#include <SPI.h>
#include <Wire.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <Adafruit_GFX.h>
#include <Adafruit_ILI9341.h>
#include <Adafruit_PN532.h>
#include <TinyGPS++.h>

// =====================================================
// SETTINGS
// =====================================================
const char* ssid     = "BOOTCAMP";
const char* password = "Paloadkanalang!01#";
const char* tapUrl   = "http://192.168.1.20/TrackFare/api/tapin.php";

#define TRIP_ID            "1"
#define GPS_PRINT_MS       2000    // GPS status printed to Serial Monitor this often
#define GPS_REQUIRED       true    // true = red screen when GPS wiring/serial is lost

#define RESULT_SCREEN_MS   5000    // green (tap in/out) screen time
#define SAME_CARD_LOCKOUT  5000    // same card can't tap again within this time (prevents in->out instantly)
#define CARD_HOLD_GAP_MS   1000    // card seen again within this gap = still being held on the reader
#define HW_CHECK_MS        2000    // how often PN532 is health-checked
#define GPS_TIMEOUT_MS     3000    // no NMEA data for this long = GPS disconnected

// =====================================================
// TFT
// =====================================================
#define TFT_CS    5
#define TFT_RST   4
#define TFT_DC    27
#define TFT_MOSI  23
#define TFT_MISO  19
#define TFT_SCK   18

Adafruit_ILI9341 tft(TFT_CS, TFT_DC, TFT_RST);

// =====================================================
// PN532
// =====================================================
#define SDA 21
#define SCL 22

Adafruit_PN532 nfc(SDA, SCL);

// =====================================================
// GPS  (NEO-8M TX -> GPIO16, RX -> GPIO17)
// =====================================================
#define GPS_RX 16
#define GPS_TX 17

HardwareSerial GPS(2);
TinyGPSPlus gps;
String gpsLineBuffer = "";
unsigned long lastGPSData = 0;

// =====================================================
// TEST BUTTON (BOOT) + QR BUTTON
// =====================================================
#define TEST_BUTTON 0

#define BUTTON_X 85
#define BUTTON_Y 195
#define BUTTON_W 150
#define BUTTON_H 35


// =====================================================
// STATE
// =====================================================
enum Screen { S_HOME, S_QR, S_RESULT, S_HW_ERROR };
Screen currentScreen = S_HOME;

unsigned long resultUntil = 0;
unsigned long lastHwCheck = 0;
unsigned long lastWifiTry = 0;

bool pnOK = false;
uint8_t shownErrMask = 0;   // bit0 = PN532, bit1 = GPS

// recent cards (per-UID debounce so A,B,A within lockout still can't double-tap A)
#define RECENT_MAX 6
struct RecentCard {
  bool used;
  uint8_t uid[7];
  uint8_t len;
  bool hasAccept;
  unsigned long lastAccept;
  unsigned long lastSeen;
};
RecentCard recent[RECENT_MAX];
struct RecentPhone {
  bool used;
  uint8_t credentialId[16];
  bool hasAccept;
  unsigned long lastAccept;
  unsigned long lastSeen;
};
RecentPhone recentPhones[RECENT_MAX];

// =====================================================
// HELPERS
// =====================================================
String uidToString(uint8_t *uid, uint8_t uidLength) {
  String result = "";
  for (int i = 0; i < uidLength; i++) {
    if (uid[i] < 0x10) result += "0";
    result += String(uid[i], HEX);
    if (i < uidLength - 1) result += " ";
  }
  result.toUpperCase();
  return result;
}

void drawCentered(const String &text, int cy, uint8_t size) {
  int16_t x1, y1;
  uint16_t w, h;
  tft.setTextSize(size);
  tft.getTextBounds(text, 0, 0, &x1, &y1, &w, &h);
  tft.setCursor((320 - w) / 2, cy - (h / 2));
  tft.print(text);
}

// =====================================================
// GPS READING (normalizes $GN.. talker IDs so TinyGPS++ parses them)
// =====================================================
void processNMEALine(String line) {
  line.trim();
  if (line.length() < 6 || line.charAt(0) != '$') return;

  lastGPSData = millis();

  String talker = line.substring(1, 3);
  String type   = line.substring(3, 6);

  if ((type == "RMC" || type == "GGA") && talker != "GP") {
    line.setCharAt(1, 'G');
    line.setCharAt(2, 'P');

    int starIdx = line.indexOf('*');
    if (starIdx > 0 && starIdx + 2 < (int)line.length()) {
      uint8_t cs = 0;
      for (int i = 1; i < starIdx; i++) cs ^= (uint8_t)line.charAt(i);
      char buf[3];
      sprintf(buf, "%02X", cs);
      line.setCharAt(starIdx + 1, buf[0]);
      line.setCharAt(starIdx + 2, buf[1]);
    }
  }

  for (unsigned int i = 0; i < line.length(); i++) gps.encode(line.charAt(i));
  gps.encode('\n');
}

void readGPS() {
  while (GPS.available()) {
    char c = GPS.read();
    if (c == '\n') {
      processNMEALine(gpsLineBuffer);
      gpsLineBuffer = "";
    } else if (c != '\r') {
      gpsLineBuffer += c;
      if (gpsLineBuffer.length() > 120) gpsLineBuffer = "";
    }
  }
}

bool gpsAlive() {
  return (millis() - lastGPSData) < GPS_TIMEOUT_MS;
}

// Ready for porting later: returns true and fills lat/lng when a fix exists
bool gpsGetLocation(double &lat, double &lng) {
  if (!gps.location.isValid()) return false;
  lat = gps.location.lat();
  lng = gps.location.lng();
  return true;
}

// Serial Monitor only (not used anywhere else yet)
void gpsSerialStatus() {
  static unsigned long lastPrint = 0;
  if (millis() - lastPrint < GPS_PRINT_MS) return;
  lastPrint = millis();

  if (!gpsAlive()) {
    Serial.println("GPS: NO DATA (check wiring)");
    return;
  }

  double lat, lng;
  if (gpsGetLocation(lat, lng)) {
    Serial.printf("GPS: FIX | SATS: %d | LAT: %.6f | LNG: %.6f\n",
                  (int)gps.satellites.value(), lat, lng);
  } else {
    Serial.printf("GPS: SCANNING... | SATS: %d\n", (int)gps.satellites.value());
  }
}

// =====================================================
// SCREENS
// =====================================================
void drawQRButton() {
  tft.fillRoundRect(BUTTON_X, BUTTON_Y, BUTTON_W, BUTTON_H, 6, ILI9341_WHITE);
  tft.setTextColor(ILI9341_BLACK);
  tft.setTextSize(2);

  String text = "Pay via QR";
  int16_t x1, y1;
  uint16_t w, h;
  tft.getTextBounds(text, 0, 0, &x1, &y1, &w, &h);
  tft.setCursor(BUTTON_X + (BUTTON_W - w) / 2, BUTTON_Y + (BUTTON_H - h) / 2);
  tft.print(text);
}

void drawHomeScreen() {
  currentScreen = S_HOME;
  tft.fillScreen(ILI9341_BLACK);
  tft.setTextColor(ILI9341_WHITE);
  drawCentered("TrackFare", 55, 4);
  drawCentered("Tap to Pay", 100, 2);
  drawQRButton();
}

void drawQRScreen() {
  currentScreen = S_QR;
  tft.fillScreen(ILI9341_WHITE);
  tft.setTextColor(ILI9341_BLACK);
  drawCentered("TrackFare", 35, 4);
  drawCentered("Scan to Pay", 70, 2);

  tft.drawRect(85, 85, 150, 110, ILI9341_BLACK);
  drawCentered("ADD QR HERE", 140, 2);
}

// Green confirmation screen (tap in / tap out)
void showResultScreen(const char *headline, const char *sub) {
  currentScreen = S_RESULT;
  resultUntil = millis() + RESULT_SCREEN_MS;

  tft.fillScreen(ILI9341_GREEN);
  tft.setTextColor(ILI9341_BLACK);
  drawCentered(headline, 95, 4);
  drawCentered(sub, 150, 2);
}

// Red screen for a problem with a tap (server/WiFi) - auto returns like the green one
void showTapErrorScreen(const char *headline, const char *sub) {
  currentScreen = S_RESULT;
  resultUntil = millis() + 3000;

  tft.fillScreen(ILI9341_RED);
  tft.setTextColor(ILI9341_WHITE);
  drawCentered(headline, 70, 3);
  tft.setTextSize(2);
  tft.setCursor(10, 120);
  tft.print(sub);
}

// Persistent red hardware error screen
void showHardwareError(uint8_t mask) {
  currentScreen = S_HW_ERROR;

  tft.fillScreen(ILI9341_RED);
  tft.setTextColor(ILI9341_WHITE);

  drawCentered("HARDWARE ERROR", 35, 3);

  int y = 90;
  if (mask & 1) {
    drawCentered("PN532: NOT FOUND", y, 2);
    y += 30;
  }
  if (mask & 2) {
    drawCentered("GPS: NO DATA", y, 2);
    y += 30;
  }
  drawCentered("CHECK WIRING", 190, 2);
}

// =====================================================
// HARDWARE HEALTH
// =====================================================
void checkHardware() {
  // --- PN532 ---
  bool alive = (nfc.getFirmwareVersion() != 0);

  if (!alive) {
    // try to un-stick the I2C bus for next attempt
    Wire.begin(SDA, SCL);
    Wire.setTimeOut(100);
  }
  else if (!pnOK) {
    // just recovered -> re-arm the reader
    nfc.begin();
    nfc.SAMConfig();
    Serial.println("PN532: RECOVERED");
  }
  if (alive != pnOK) {
    Serial.println(alive ? "PN532: OK" : "ERROR: PN532 NOT DETECTED");
  }
  pnOK = alive;

  // --- GPS ---
  bool gpsOK = gpsAlive() || !GPS_REQUIRED;

  uint8_t mask = 0;
  if (!pnOK) mask |= 1;
  if (!gpsOK) mask |= 2;

  if (mask != shownErrMask) {
    shownErrMask = mask;
    if (mask) {
      Serial.printf("HARDWARE ERROR mask=%d\n", mask);
      showHardwareError(mask);
    } else {
      Serial.println("Hardware OK - back to home");
      drawHomeScreen();
    }
  }
}

// =====================================================
// SERVER
// =====================================================
int sendToServer(String uid, String &body) {
  HTTPClient http;
  http.setTimeout(4000);
  http.begin(tapUrl);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");

  String payload = "uid=" + uid + "&trip_id=" + String(TRIP_ID);

  int code = http.POST(payload);
  body = (code > 0) ? http.getString() : "";

  Serial.println("HTTP CODE: " + String(code));
  Serial.println(body);

  http.end();
  return code;
}

String bytesToHex(const uint8_t *bytes, size_t length) {
  String result;
  for (size_t i = 0; i < length; i++) {
    if (bytes[i] < 0x10) result += "0";
    result += String(bytes[i], HEX);
  }
  result.toLowerCase();
  return result;
}

String postPhoneTap(const String &payload) {
  HTTPClient http;
  http.setTimeout(4000);
  String endpoint = String(tapUrl);
  const int lastSlash = endpoint.lastIndexOf('/');
  endpoint = endpoint.substring(0, lastSlash + 1) + "phone_nfc_tap.php";
  http.begin(endpoint);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  const int code = http.POST(payload);
  const String body = code > 0 ? http.getString() : "";
  Serial.println("PHONE NFC HTTP CODE: " + String(code));
  Serial.println(body);
  http.end();
  return body;
}

bool responseOk(const uint8_t *response, uint8_t length) {
  return length >= 2 && response[length - 2] == 0x90 && response[length - 1] == 0x00;
}

int findRecentPhone(const uint8_t *credentialId) {
  for (int i = 0; i < RECENT_MAX; i++) {
    if (recentPhones[i].used && memcmp(recentPhones[i].credentialId, credentialId, 16) == 0) return i;
  }
  return -1;
}

int allocRecentPhone() {
  int oldest = 0;
  for (int i = 0; i < RECENT_MAX; i++) {
    if (!recentPhones[i].used) return i;
    if (recentPhones[i].lastSeen < recentPhones[oldest].lastSeen) oldest = i;
  }
  return oldest;
}

bool tryPhoneTap(bool &identified, String &message) {
  identified = false;
  message = "";

  const uint8_t aid[] = {0xF0, 0x54, 0x52, 0x41, 0x43, 0x4B, 0x46, 0x41, 0x52, 0x45};
  uint8_t response[64];
  uint8_t responseLength = sizeof(response);
  uint8_t selectAid[5 + sizeof(aid) + 1] = {
    0x00, 0xA4, 0x04, 0x00, sizeof(aid)
  };
  memcpy(selectAid + 5, aid, sizeof(aid));
  selectAid[sizeof(selectAid) - 1] = 0x00;

  if (!nfc.inListPassiveTarget()
      || !nfc.inDataExchange(selectAid, sizeof(selectAid), response, &responseLength)
      || !responseOk(response, responseLength)) {
    return false;
  }

  identified = true;
  const unsigned long now = millis();
  const bool stillHeld = phoneSeen && (now - lastPhoneSeen) < CARD_HOLD_GAP_MS;
  const bool inLockout = phoneTapAccepted && (now - lastPhoneAccepted) < SAME_CARD_LOCKOUT;
  phoneSeen = true;
  lastPhoneSeen = now;
  if (stillHeld || inLockout) return false;

  if (WiFi.status() != WL_CONNECTED) {
    message = "NO WIFI";
    return false;
  }

  const int activeTripId = String(TRIP_ID).toInt();
  uint8_t challenge[16];
  for (size_t i = 0; i < sizeof(challenge); i += 4) {
    const uint32_t randomValue = esp_random();
    memcpy(challenge + i, &randomValue, sizeof(randomValue));
  }

  uint8_t getProof[26] = {0};
  getProof[0] = 0x80;
  getProof[1] = 0xCA;
  getProof[4] = 0x14;
  getProof[5] = static_cast<uint8_t>((activeTripId >> 24) & 0xFF);
  getProof[6] = static_cast<uint8_t>((activeTripId >> 16) & 0xFF);
  getProof[7] = static_cast<uint8_t>((activeTripId >> 8) & 0xFF);
  getProof[8] = static_cast<uint8_t>(activeTripId & 0xFF);
  memcpy(getProof + 9, challenge, sizeof(challenge));
  responseLength = sizeof(response);
  if (!nfc.inDataExchange(getProof, sizeof(getProof), response, &responseLength)
      || !responseOk(response, responseLength) || responseLength != 54) {
    message = "PHONE NFC AUTHENTICATION FAILED";
    return false;
  }

  uint8_t credentialId[16];
  uint8_t signature[80];
  memcpy(credentialId, response, sizeof(credentialId));
  memcpy(signature, response + sizeof(credentialId), 36);

  int phoneIndex = findRecentPhone(credentialId);
  const unsigned long now = millis();
  if (phoneIndex >= 0) {
    RecentPhone &phone = recentPhones[phoneIndex];
    const bool stillHeld = (now - phone.lastSeen) < CARD_HOLD_GAP_MS;
    const bool inLockout = phone.hasAccept && (now - phone.lastAccept) < SAME_CARD_LOCKOUT;
    if (stillHeld || inLockout) {
      phone.lastSeen = now;
      return false;
    }
  } else {
    phoneIndex = allocRecentPhone();
    recentPhones[phoneIndex].used = true;
    memcpy(recentPhones[phoneIndex].credentialId, credentialId, sizeof(credentialId));
    recentPhones[phoneIndex].hasAccept = false;
    recentPhones[phoneIndex].lastAccept = 0;
  }
  recentPhones[phoneIndex].lastSeen = now;

  uint8_t getSignatureTail[] = {0x80, 0xCA, 0x01, 0x00, 0x00};
  responseLength = sizeof(response);
  if (!nfc.inDataExchange(getSignatureTail, sizeof(getSignatureTail), response, &responseLength)
      || !responseOk(response, responseLength) || responseLength < 2
      || responseLength - 2 > sizeof(signature) - 36) {
    message = "PHONE NFC SIGNATURE FAILED";
    return false;
  }
  memcpy(signature + 36, response, responseLength - 2);
  const size_t signatureLength = 36 + responseLength - 2;

  const String payload = "credential_id=" + bytesToHex(credentialId, sizeof(credentialId))
      + "&challenge=" + bytesToHex(challenge, sizeof(challenge))
      + "&signature=" + bytesToHex(signature, signatureLength)
      + "&trip_id=" + String(activeTripId);
  message = postPhoneTap(payload);
  const bool accepted = message.indexOf("\"success\":true") >= 0;
  RecentPhone &phone = recentPhones[phoneIndex];
  phone.lastSeen = millis();
  if (accepted) {
    phone.hasAccept = true;
    phone.lastAccept = millis();
  } else {
    phone.hasAccept = false;
  }
  return accepted;
}

// =====================================================
// TAP HANDLING
// =====================================================
int findRecent(uint8_t *uid, uint8_t len) {
  for (int i = 0; i < RECENT_MAX; i++) {
    if (recent[i].used && recent[i].len == len && memcmp(recent[i].uid, uid, len) == 0) return i;
  }
  return -1;
}

int allocRecent() {
  int oldest = 0;
  for (int i = 0; i < RECENT_MAX; i++) {
    if (!recent[i].used) return i;
    if (recent[i].lastSeen < recent[oldest].lastSeen) oldest = i;
  }
  return oldest;
}

void handleTap(uint8_t *uid, uint8_t uidLength) {
  unsigned long now = millis();

  int idx = findRecent(uid, uidLength);

  if (idx >= 0) {
    RecentCard &r = recent[idx];
    bool stillHeld  = (now - r.lastSeen) < CARD_HOLD_GAP_MS;
    bool inLockout  = r.hasAccept && (now - r.lastAccept) < SAME_CARD_LOCKOUT;

    if (stillHeld || inLockout) {
      r.lastSeen = now;     // keep "held" tracking alive, but do NOT process again
      return;
    }
  } else {
    idx = allocRecent();
    recent[idx].used = true;
    recent[idx].len = uidLength;
    memcpy(recent[idx].uid, uid, uidLength);
    recent[idx].hasAccept = false;
    recent[idx].lastAccept = 0;
  }

  String uidStr = uidToString(uid, uidLength);
  Serial.println("CARD: " + uidStr);

  bool accepted = false;

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("ERROR: WIFI DOWN");
    showTapErrorScreen("NO WIFI", "TRY AGAIN");
  }
  else {
        String body;
    int code = sendToServer(uidStr, body);
    body.trim();
    String up = body;
    up.toUpperCase();

    if (code == 200 && up.indexOf("TAP IN SUCCESS") >= 0) {
      accepted = true;
      showResultScreen("TAP IN", "NFC ACCEPTED");
    }
    else if (code == 200 && up.indexOf("TAP OUT SUCCESS") >= 0) {
      accepted = true;

      String fare = "CARD ACCEPTED";
      int f = up.indexOf("FARE:");
      if (f >= 0) {
        fare = "FARE: " + body.substring(f + 5);
        fare.trim();
      }
      showResultScreen("TAP OUT", fare.c_str());
    }
    else if (code == 200) {
      // server replied with a reason (INVALID CARD, NO ACTIVE TRIP, insufficient balance, ...)
      showTapErrorScreen("TAP FAILED", body.substring(0, 60).c_str());
    }
    else {
      showTapErrorScreen("SERVER ERROR", "TRY AGAIN");
    }
  }

  RecentCard &r = recent[idx];
  r.lastSeen = millis();
  if (accepted) {
    r.hasAccept = true;
    r.lastAccept = millis();
  } else {
    r.hasAccept = false;   // failed tap can be retried after removing the card
  }
}

void checkNFC() {
  uint8_t uid[7];
  uint8_t uidLength;

  if (nfc.readPassiveTargetID(PN532_MIFARE_ISO14443A, uid, &uidLength, 100)) {
    bool identifiedPhone = false;
    String phoneMessage;
    const bool phoneAccepted = tryPhoneTap(identifiedPhone, phoneMessage);
    if (identifiedPhone) {
      if (!phoneMessage.isEmpty()) {
        String upper = phoneMessage;
        upper.toUpperCase();
        if (phoneAccepted) {
          showResultScreen(upper.indexOf("TAP OUT SUCCESS") >= 0 ? "TAP OUT" : "TAP IN", "PHONE NFC ACCEPTED");
        } else {
          showTapErrorScreen("PHONE TAP FAILED", phoneMessage.substring(0, 60).c_str());
        }
      }
      return;
    }
    handleTap(uid, uidLength);
  }
}

// =====================================================
// WIFI
// =====================================================
void wifiMaintain() {
  if (WiFi.status() == WL_CONNECTED) return;
  if (millis() - lastWifiTry < 10000) return;

  lastWifiTry = millis();
  Serial.println("WiFi lost - reconnecting...");
  WiFi.disconnect();
  WiFi.begin(ssid, password);
}

// =====================================================
// SETUP
// =====================================================
void setup() {
  Serial.begin(115200);
  delay(300);

  pinMode(TEST_BUTTON, INPUT_PULLUP);

  // ---- TFT ----
  SPI.begin(TFT_SCK, TFT_MISO, TFT_MOSI, TFT_CS);
  tft.begin();
  tft.setRotation(3);

  tft.fillScreen(ILI9341_WHITE);
  tft.setTextColor(ILI9341_BLACK);
  drawCentered("TrackFare", 80, 4);
  drawCentered("Starting...", 130, 2);

  // ---- PN532 ----
  Wire.begin(SDA, SCL);
  Wire.setTimeOut(100);
  nfc.begin();

  if (nfc.getFirmwareVersion()) {
    nfc.SAMConfig();
    pnOK = true;
    Serial.println("PN532: OK");
  } else {
    pnOK = false;
    Serial.println("ERROR: PN532 NOT DETECTED");
  }

  // ---- GPS ----
  GPS.setRxBufferSize(1024);
  GPS.begin(9600, SERIAL_8N1, GPS_RX, GPS_TX);
  lastGPSData = millis();   // grace period before "no data" counts as an error

  // ---- WiFi ----
  drawCentered("Connecting WiFi...", 170, 2);
  WiFi.begin(ssid, password);
  unsigned long wifiStart = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - wifiStart < 15000) {
    readGPS();
    delay(50);
  }
  lastWifiTry = millis();
  Serial.println(WiFi.status() == WL_CONNECTED ? "WiFi: CONNECTED" : "WiFi: NOT CONNECTED (will keep retrying)");

  // give GPS a moment of data before the first health check
  unsigned long g = millis();
  while (millis() - g < 1500) readGPS();

  shownErrMask = 0;
  drawHomeScreen();
  checkHardware();          // shows red screen immediately if something is wrong
  lastHwCheck = millis();
}

// =====================================================
// LOOP
// =====================================================
void loop() {

  readGPS();
  gpsSerialStatus();
  wifiMaintain();

  // ---- periodic hardware health check ----
  if (millis() - lastHwCheck >= HW_CHECK_MS) {
    lastHwCheck = millis();
    checkHardware();
  }

  // Red hardware error screen: nothing else runs until fixed
  if (currentScreen == S_HW_ERROR) {
    return;
  }

  // ---- result screen timeout (5s) -> back to initial screen ----
  if (currentScreen == S_RESULT && (long)(millis() - resultUntil) >= 0) {
    drawHomeScreen();
  }

  // ---- temporary BOOT button: show QR while held ----
  if (currentScreen == S_HOME && digitalRead(TEST_BUTTON) == LOW) {
    drawQRScreen();
  }
  else if (currentScreen == S_QR && digitalRead(TEST_BUTTON) == HIGH) {
    drawHomeScreen();
  }

  // ---- NFC is always polled, so a new tap works even while the previous
  //      confirmation is still on screen ----
  checkNFC();
}