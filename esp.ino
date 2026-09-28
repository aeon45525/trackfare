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
const char* ssid     = "WILMASTORE";
const char* password = "edertgt01614";
const char* tapUrl   = "http://192.168.100.103/TrackFare/api/tapin.php";

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
// SEND GPS TO SERVER (live location for dashboards)
// =====================================================
const char* gpsUrl = "http://192.168.100.103/TrackFare/api/gps_update.php";
#define DEVICE_KEY   "trackfare-demo-key"   // must match $key in gps_update.php
#define GPS_SEND_MS  2000

void sendGPS() {
  static unsigned long last = 0;
  if (millis() - last < GPS_SEND_MS) return;
  last = millis();

  if (WiFi.status() != WL_CONNECTED) { Serial.println("GPS SEND: no WiFi"); return; }
  if (!gpsAlive())                   { Serial.println("GPS SEND: GPS module silent"); return; }

  double lat = 0, lng = 0;
  bool fix = gpsGetLocation(lat, lng) && gps.location.age() < 5000;

  HTTPClient http;
  http.setConnectTimeout(800);
  http.setTimeout(1000);
  http.begin(gpsUrl);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");

  String p = "key=" + String(DEVICE_KEY)
           + "&fix=" + String(fix ? 1 : 0)
           + "&sats=" + String((int)gps.satellites.value());
  if (fix) {
    p += "&lat=" + String(lat, 6) + "&lng=" + String(lng, 6)
       + "&speed=" + String(gps.speed.kmph(), 1);
  }

  int code = http.POST(p);
  Serial.printf("GPS SEND: fix=%d -> HTTP %d", fix ? 1 : 0, code);
  if (code != 200 && code > 0) Serial.print(" " + http.getString());
  Serial.println();
  http.end();
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
  sendGPS(); 
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