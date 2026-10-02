  #include <SPI.h>
  #include <Wire.h>
  #include <WiFi.h>
  #include <HTTPClient.h>
  #include <Adafruit_GFX.h>
  #include <Adafruit_ILI9341.h>
  #include <Adafruit_PN532.h>
  #include <qrcode.h>
  #include <TinyGPS++.h>

  // =====================================================
  // SETTINGS
  // =====================================================
  const char* ssid     = "PLDTHOMEFIBRB6AgP-EXT";
  const char* password = "P@ssword01";
  const char* tapUrl   = "http://192.168.1.56/TrackFare/api/tap.php?action=card";
  const char* phoneTapUrl = "http://192.168.1.56/TrackFare/api/tap.php?action=phone_nfc";
  const char* gpsUrl   = "http://192.168.1.56/TrackFare/api/gps_update.php";
  const char* gpsToken = "8f71a65d9c3e42b7a104de5f6c98a231d72b4e0f9a53c681e2f07b4a9d6c1358";

  #define BUS_ID             1
  #define GPS_PRINT_MS       2000
  #define GPS_REQUIRED       true

  #define RESULT_SCREEN_MS   5000
  #define SAME_CARD_LOCKOUT  5000
  #define CARD_HOLD_GAP_MS   1000
  #define HW_CHECK_MS        2000
  #define GPS_TIMEOUT_MS     10000
  #define GPS_UPLOAD_INTERVAL_MS 5000
  #define PN532_MISSES_BEFORE_ERROR 3

  // Trip cache: refreshed from loop(), never during a tap
  #define TRIP_REFRESH_MS    3000
  #define TRIP_STALE_MS      180000   // cache older than this with no server reply = unknown

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
  #define PN532_IRQ 26
  #define PN532_RESET 25

  Adafruit_PN532 nfc(PN532_IRQ, PN532_RESET, &Wire);

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
  unsigned long lastGpsUpload = 0;
  volatile bool wifiRetryReady = false;
  volatile uint8_t wifiDisconnectReason = 0;
  int lastButtonReading = HIGH;
  int buttonState = HIGH;
  unsigned long lastButtonDebounceAt = 0;

  bool pnOK = true;
  bool pnConfigured = false;
  uint8_t pnFailureCount = 0;
  uint8_t shownErrMask = 0;   // bit0 = PN532, bit1 = GPS

  // Trip cache: -1 = unknown/server unreachable, 0 = no active trip, >0 = trip id
  int cachedTripId = -1;
  unsigned long tripCachedAt = 0;      // last refresh attempt
  unsigned long tripLastGoodAt = 0;    // last successful server reply

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

  void drawQrCodeOnTft(esp_qrcode_handle_t qr) {
    const int moduleCount = esp_qrcode_get_size(qr);
    const int moduleScale = 4;
    const int quietZone = 4;
    const int qrSize = (moduleCount + quietZone * 2) * moduleScale;
    const int qrX = (320 - qrSize) / 2;
    const int qrY = 56;

    tft.fillRect(qrX, qrY, qrSize, qrSize, ILI9341_WHITE);
    for (int row = 0; row < moduleCount; row++) {
      for (int col = 0; col < moduleCount; col++) {
        if (esp_qrcode_get_module(qr, col, row)) {
          tft.fillRect(qrX + (col + quietZone) * moduleScale,
                      qrY + (row + quietZone) * moduleScale,
                      moduleScale, moduleScale, ILI9341_BLACK);
        }
      }
    }
  }

  // =====================================================
  // GPS READING
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

  double gpsDistanceMeters(double lat1, double lng1, double lat2, double lng2) {
    const double meanLatRadians = ((lat1 + lat2) * 0.5) * PI / 180.0;
    const double northMeters = (lat2 - lat1) * 111320.0;
    const double eastMeters = (lng2 - lng1) * 111320.0 * cos(meanLatRadians);
    return sqrt(northMeters * northMeters + eastMeters * eastMeters);
  }

  bool gpsGetLocation(double &lat, double &lng) {
    if (!gps.location.isValid() || gps.location.age() > 5000) return false;

    const double candidateLat = gps.location.lat();
    const double candidateLng = gps.location.lng();
    const unsigned long age = gps.location.age();
    const unsigned long now = millis();
    static unsigned long previousAge = 0xFFFFFFFFUL;
    static bool hasReference = false;
    static double referenceLat = 0.0;
    static double referenceLng = 0.0;
    static unsigned long referenceAt = 0;
    static bool hasPendingFix = false;
    static double pendingLat = 0.0;
    static double pendingLng = 0.0;
    const bool freshSample = age < previousAge;
    previousAge = age;

    if (!hasReference) {
      if (!freshSample) return false;
      if (hasPendingFix && gpsDistanceMeters(pendingLat, pendingLng, candidateLat, candidateLng) <= 35.0) {
        hasReference = true;
        referenceLat = candidateLat;
        referenceLng = candidateLng;
        referenceAt = now;
        hasPendingFix = false;
      } else {
        pendingLat = candidateLat;
        pendingLng = candidateLng;
        hasPendingFix = true;
        return false;
      }
    } else {
      const double distanceMeters = gpsDistanceMeters(referenceLat, referenceLng, candidateLat, candidateLng);
      const double elapsedSeconds = max(1.0, (now - referenceAt) / 1000.0);
      if (distanceMeters > (55.0 * elapsedSeconds + 50.0)) return false;
      referenceLat = candidateLat;
      referenceLng = candidateLng;
      referenceAt = now;
    }

    lat = candidateLat;
    lng = candidateLng;
    return true;
  }

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
    } else if (gps.location.isValid() && gps.location.age() <= 5000) {
      Serial.println("GPS: POSITION JUMP REJECTED");
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
    tft.setTextSize(1);

    String text = "Press BOOT for QR";
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
    drawCentered("Scan to Tap In/Out", 22, 2);
    drawCentered("Fare charged at tap out", 46, 1);
    if (WiFi.status() != WL_CONNECTED) {
      drawCentered("WiFi unavailable", 130, 2);
      return;
    }
    if (cachedTripId < 1) {
      drawCentered("No active bus trip", 130, 2);
      return;
    }

    String payload = "trackfare://tap?v=1&b=" + String(BUS_ID)
        + "&t=" + String(cachedTripId);
    esp_qrcode_config_t qrConfig = {};
    qrConfig.display_func = drawQrCodeOnTft;
    qrConfig.max_qrcode_version = 4;
    qrConfig.qrcode_ecc_level = ESP_QRCODE_ECC_LOW;
    if (esp_qrcode_generate(&qrConfig, payload.c_str()) != ESP_OK) {
      drawCentered("QR unavailable", 130, 2);
      return;
    }
    drawCentered("Scan with TrackFare app", 232, 1);
  }

  void showResultScreen(const char *headline, const char *sub) {
    currentScreen = S_RESULT;
    resultUntil = millis() + RESULT_SCREEN_MS;

    tft.fillScreen(ILI9341_GREEN);
    tft.setTextColor(ILI9341_BLACK);
    drawCentered(headline, 95, 4);
    drawCentered(sub, 150, 2);
  }

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
    bool alive = (nfc.getFirmwareVersion() != 0);

    if (alive) {
      if (!pnConfigured || !pnOK) {
        nfc.begin();
        nfc.SAMConfig();
        pnConfigured = true;
        Serial.println(pnOK ? "PN532: INITIALIZED" : "PN532: RECOVERED");
      } else if (pnFailureCount > 0) {
        Serial.println("PN532: COMMUNICATION RECOVERED");
      }
      pnFailureCount = 0;
      pnOK = true;
    } else {
      if (pnFailureCount < PN532_MISSES_BEFORE_ERROR) {
        pnFailureCount++;
      }
      if (pnFailureCount == PN532_MISSES_BEFORE_ERROR && pnOK) {
        pnOK = false;
        Serial.println("ERROR: PN532 NOT DETECTED");
        Wire.begin(SDA, SCL);
        Wire.setTimeOut(100);
        Wire.setClock(100000);
        nfc.begin();
        pnConfigured = false;
      }
    }

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

  // Prints why a connection might be failing: ESP32 side (heap, WiFi) vs server side (raw TCP test).
  void netDiag(const char *tag) {
    Serial.printf("NETDIAG[%s] heap=%u minHeap=%u wifi=%d rssi=%d\n",
                  tag, ESP.getFreeHeap(), ESP.getMinFreeHeap(), (int)WiFi.status(), WiFi.RSSI());
    WiFiClient probe;
    unsigned long t = millis();
    bool ok = probe.connect(IPAddress(192, 168, 1, 20), 80, 2000);
    Serial.printf("NETDIAG[%s] raw TCP to server:80 = %s (%lu ms)\n", tag, ok ? "OK" : "FAILED", millis() - t);
    probe.stop();
  }

  // Returns: >0 trip id, 0 = no active trip, -1 = server unreachable.
  // Only called from refreshTripId() / setup, never in the middle of an NFC exchange.
  int getActiveTripId(int maxAttempts = 1) {
    HTTPClient http;
    String url = String(gpsUrl) + "?bus_id=" + String(BUS_ID) + "&token=" + String(gpsToken);
    int tripId = 0;
    int code = -1;
    String body;
    for (int attempt = 1; attempt <= maxAttempts; attempt++) {
      if (WiFi.status() != WL_CONNECTED) {
        code = -4;
        break;
      }
      http.setTimeout(2500);
      http.begin(url);
      code = http.GET();
      body = code > 0 ? http.getString() : "";
      http.end();
      if (code >= 0) break;
      Serial.printf("ACTIVE TRIP LOOKUP attempt=%d HTTP=%d\n", attempt, code);
      netDiag("trip-lookup");
      if (attempt < maxAttempts) delay(200);
    }
    if (code == 200) {
      int key = body.indexOf("\"trip_id\":");
      if (key >= 0) {
        int start = key + 10;
        int end = body.indexOf(',', start);
        if (end < 0) end = body.indexOf('}', start);
        if (end > start) tripId = body.substring(start, end).toInt();
      }
    }
    if (code < 0) return -1;
    return tripId;
  }

  // Called from loop(). Keeps cachedTripId fresh so taps never wait on HTTP.
  void refreshTripId() {
    unsigned long now = millis();
    if (WiFi.status() != WL_CONNECTED) {
      if (cachedTripId >= 0 && now - tripLastGoodAt > TRIP_STALE_MS) cachedTripId = -1;
      return;
    }
    if (now - tripCachedAt < TRIP_REFRESH_MS) return;

    int id = getActiveTripId(1);
    tripCachedAt = millis();

    if (id >= 0) {
      if (id != cachedTripId) Serial.printf("TRIP CACHE: %d -> %d\n", cachedTripId, id);
      cachedTripId = id;
      tripLastGoodAt = tripCachedAt;
    } else if (cachedTripId >= 0 && tripCachedAt - tripLastGoodAt > TRIP_STALE_MS) {
      Serial.println("TRIP CACHE: stale, marking unknown");
      cachedTripId = -1;
    }
  }

  int sendToServer(String uid, String &body) {
    int tripId = cachedTripId;
    if (tripId < 1) {
      body = tripId == 0 ? "NO ACTIVE TRIP" : "SERVER CONNECTION FAILED";
      return tripId == 0 ? 200 : -1;
    }

    HTTPClient http;
    http.setTimeout(4000);
    String payload = "uid=" + uid + "&trip_id=" + String(tripId) + "&bus_id=" + String(BUS_ID);

    int code = -1;
    for (int attempt = 1; attempt <= 3; attempt++) {
      http.begin(tapUrl);
      http.addHeader("Content-Type", "application/x-www-form-urlencoded");
      code = http.POST(payload);
      if (code != HTTPC_ERROR_CONNECTION_REFUSED) break;   // -1: never reached the server, safe to retry
      Serial.printf("CARD POST refused, retry %d/3\n", attempt);
      http.end();
      delay(200 * attempt);
    }
    body = (code > 0) ? http.getString() : "";

    Serial.println("HTTP CODE: " + String(code));
    Serial.println(body);

    http.end();
    return code;
  }

  // Single attempt: the next upload is only GPS_UPLOAD_INTERVAL_MS away anyway,
  // and long retries would block NFC polling.
  void sendGpsPosition() {
    double lat, lng;
    if (WiFi.status() != WL_CONNECTED || !gpsAlive()) return;
    if (millis() - lastGpsUpload < GPS_UPLOAD_INTERVAL_MS) return;
    if (!gpsGetLocation(lat, lng)) return;

    String payload = "token=" + String(gpsToken)
        + "&bus_id=" + String(BUS_ID)
        + "&lat=" + String(lat, 6)
        + "&lng=" + String(lng, 6);

    HTTPClient http;
    http.setTimeout(2500);
    http.begin(gpsUrl);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");
    int code = http.POST(payload);
    String response = code > 0 ? http.getString() : "";
    http.end();

    lastGpsUpload = millis();
    if (code != 200) {
      Serial.printf("GPS UPLOAD FAILED: HTTP %d %s\n", code, response.c_str());
    }
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
    http.setTimeout(15000);
    String endpoint = String(phoneTapUrl);

    int code = -1;
    for (int attempt = 1; attempt <= 3; attempt++) {
      http.begin(endpoint);
      http.addHeader("Content-Type", "application/x-www-form-urlencoded");
      code = http.POST(payload);
      if (code != HTTPC_ERROR_CONNECTION_REFUSED) break;   // -1: never reached the server, safe to retry
      Serial.printf("PHONE NFC POST refused, retry %d/3\n", attempt);
      if (attempt == 1) netDiag("phone-post");
      http.end();
      delay(200 * attempt);
    }
    String body = code > 0 ? http.getString() : "";
    Serial.println("PHONE NFC HTTP CODE: " + String(code));
    if (code < 0) body = "CHECK TAP STATUS";
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

  // Sends an APDU with a few quick retries. Phones sometimes NAK the first
  // frame right after activation.
  bool exchange(uint8_t *cmd, uint8_t cmdLen, uint8_t *resp, uint8_t &respLen, int tries) {
    for (int i = 0; i < tries; i++) {
      respLen = 64;
      if (nfc.inDataExchange(cmd, cmdLen, resp, &respLen)) return true;
      delay(15);
    }
    return false;
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

    bool selected = exchange(selectAid, sizeof(selectAid), response, responseLength, 2)
                    && responseOk(response, responseLength);

    if (!selected) {
      // Fallback (this is what the original code always did): re-activate the
      // target, then select again. Some PN532 + phone combos need this.
      Serial.println("PHONE: SELECT failed, re-listing target");
      if (nfc.inListPassiveTarget()) {
        selected = exchange(selectAid, sizeof(selectAid), response, responseLength, 2)
                  && responseOk(response, responseLength);
      } else {
        Serial.println("PHONE: re-list failed (phone left the field?)");
      }
    }

    if (!selected) {
      Serial.printf("PHONE: SELECT AID failed, last response len=%u:", responseLength);
      for (uint8_t i = 0; i < responseLength && i < 8; i++) Serial.printf(" %02X", response[i]);
      Serial.println();
      return false;
    }

    identified = true;

    if (WiFi.status() != WL_CONNECTED) {
      message = "NO WIFI";
      return false;
    }

    // Cached trip id: no HTTP request in the middle of the NFC exchange.
    const int activeTripId = cachedTripId;
    if (activeTripId == 0) {
      message = "NO ACTIVE TRIP";
      return false;
    }
    if (activeTripId < 0) {
      message = "SERVER CONNECTION FAILED";
      return false;
    }

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

    if (!exchange(getProof, sizeof(getProof), response, responseLength, 2)
        || !responseOk(response, responseLength) || responseLength != 54) {
      Serial.printf("PHONE: GET PROOF failed (len=%u)\n", responseLength);
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
        return false;   // identified = true, message empty -> silently ignored
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
    if (!exchange(getSignatureTail, sizeof(getSignatureTail), response, responseLength, 2)
        || !responseOk(response, responseLength) || responseLength < 2
        || responseLength - 2 > sizeof(signature) - 36) {
      Serial.printf("PHONE: GET SIGNATURE failed (len=%u)\n", responseLength);
      message = "PHONE NFC SIGNATURE FAILED";
      return false;
    }
    memcpy(signature + 36, response, responseLength - 2);
    const size_t signatureLength = 36 + responseLength - 2;

    const String payload = "credential_id=" + bytesToHex(credentialId, sizeof(credentialId))
        + "&challenge=" + bytesToHex(challenge, sizeof(challenge))
        + "&signature=" + bytesToHex(signature, signatureLength)
        + "&trip_id=" + String(activeTripId)
        + "&bus_id=" + String(BUS_ID);
    message = postPhoneTap(payload);
    const bool accepted = message.indexOf("\"success\":true") >= 0;
    const bool outcomeUnknown = message == "CHECK TAP STATUS";
    RecentPhone &phone = recentPhones[phoneIndex];
    phone.lastSeen = millis();
    if (accepted || outcomeUnknown) {
      phone.hasAccept = true;
      phone.lastAccept = millis();
    } else {
      phone.hasAccept = false;
    }
    return accepted;
  }

  // =====================================================
  // TAP HANDLING (physical cards)
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
        r.lastSeen = now;
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
      r.hasAccept = false;
    }
  }

  // Pulls the fare amount out of the server response (JSON or "FARE: 12.00" text).
  // Returns "" if nothing is found.
  String extractFare(const String &msg) {
    String low = msg;
    low.toLowerCase();

    const char *keys[] = {"\"fare\"", "\"fare_amount\"", "\"total_fare\"", "\"amount\""};
    for (int i = 0; i < 4; i++) {
      int k = low.indexOf(keys[i]);
      if (k < 0) continue;
      int colon = low.indexOf(':', k + strlen(keys[i]));
      if (colon < 0) continue;
      int s = colon + 1;
      while (s < (int)msg.length() && (msg[s] == ' ' || msg[s] == '"')) s++;
      int e = s;
      while (e < (int)msg.length() && (isDigit(msg[e]) || msg[e] == '.')) e++;
      if (e > s) return msg.substring(s, e);
    }

    int f = low.indexOf("fare:");
    if (f >= 0) {
      int s = f + 5;
      int limit = min((int)msg.length(), s + 8);
      while (s < limit && !isDigit(msg[s])) s++;      // skip spaces or "PHP"
      int e = s;
      while (e < (int)msg.length() && (isDigit(msg[e]) || msg[e] == '.')) e++;
      if (e > s) return msg.substring(s, e);
    }
    return "";
  }

  void checkNFC() {
    uint8_t uid[7];
    uint8_t uidLength;

    if (nfc.readPassiveTargetID(PN532_MIFARE_ISO14443A, uid, &uidLength, 100)) {
      // Android HCE phones report a random 4-byte UID that starts with 0x08.
      // Real cards never do, so never send that UID to the card endpoint.
      const bool randomUid = (uidLength == 4 && uid[0] == 0x08);
      Serial.println("NFC target: " + uidToString(uid, uidLength));

      bool identifiedPhone = false;
      String phoneMessage;
      const bool phoneAccepted = tryPhoneTap(identifiedPhone, phoneMessage);

      if (identifiedPhone) {
        if (!phoneMessage.isEmpty()) {
          String upper = phoneMessage;
          upper.toUpperCase();
          if (phoneAccepted) {
            const bool isOut = upper.indexOf("TAP OUT SUCCESS") >= 0;
            if (isOut) {
              String fare = extractFare(phoneMessage);
              Serial.println("PHONE TAP OUT fare=" + fare);
              String sub = fare.length() ? ("FARE: PHP " + fare) : String("TAP OUT DONE");
              showResultScreen("TAP OUT", sub.c_str());
            } else {
              showResultScreen("TAP IN", "PHONE NFC ACCEPTED");
            }
          } else {
            showTapErrorScreen("PHONE TAP FAILED", phoneMessage.substring(0, 60).c_str());
          }
        }
        return;
      }

      if (randomUid) {
        Serial.println("PHONE: not ready (random UID), will retry on next poll");
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
    if (!wifiRetryReady || millis() - lastWifiTry < 5000) return;

    wifiRetryReady = false;
    lastWifiTry = millis();
    Serial.printf("WiFi retrying after disconnect, reason=%u\n", wifiDisconnectReason);
    WiFi.reconnect();
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
    Wire.setClock(100000);
    nfc.begin();
    Wire.beginTransmission(0x24);
    Serial.printf("PN532 I2C ADDRESS 0x24: %s\n", Wire.endTransmission() == 0 ? "ACK" : "NO ACK");

    if (nfc.getFirmwareVersion()) {
      nfc.SAMConfig();
      pnOK = true;
      pnConfigured = true;
      pnFailureCount = 0;
      Serial.println("PN532: OK");
    } else {
      pnOK = true;
      Serial.println("PN532: no startup response; checking again");
    }

    // ---- GPS ----
    GPS.setRxBufferSize(1024);
    GPS.begin(9600, SERIAL_8N1, GPS_RX, GPS_TX);
    lastGPSData = millis();

    // ---- WiFi ----
    drawCentered("Connecting WiFi...", 170, 2);
    WiFi.onEvent([](WiFiEvent_t event, WiFiEventInfo_t info) {
      if (event == ARDUINO_EVENT_WIFI_STA_GOT_IP) {
        wifiRetryReady = false;
        Serial.print("WiFi connected, IP: ");
        Serial.println(WiFi.localIP());
        Serial.printf("WiFi RSSI: %d dBm, gateway: %s\n", WiFi.RSSI(), WiFi.gatewayIP().toString().c_str());
      } else if (event == ARDUINO_EVENT_WIFI_STA_DISCONNECTED) {
        wifiDisconnectReason = info.wifi_sta_disconnected.reason;
        wifiRetryReady = true;
        Serial.printf("WiFi disconnected, reason=%u\n", wifiDisconnectReason);
      }
    });
    WiFi.mode(WIFI_STA);
    WiFi.setSleep(false);
    WiFi.setAutoReconnect(true);
    WiFi.setTxPower(WIFI_POWER_19_5dBm);
    WiFi.begin(ssid, password);
    unsigned long wifiStart = millis();
    while (WiFi.status() != WL_CONNECTED && millis() - wifiStart < 15000) {
      readGPS();
      delay(50);
    }
    lastWifiTry = millis();
    Serial.println(WiFi.status() == WL_CONNECTED ? "WiFi: CONNECTED" : "WiFi: NOT CONNECTED (will keep retrying)");

    // Prime the trip cache so the first tap works right away
    if (WiFi.status() == WL_CONNECTED) {
      int id = getActiveTripId(3);
      tripCachedAt = millis();
      if (id >= 0) {
        cachedTripId = id;
        tripLastGoodAt = tripCachedAt;
      }
      Serial.printf("TRIP CACHE initial: %d\n", cachedTripId);
    }

    unsigned long g = millis();
    while (millis() - g < 1500) readGPS();

    shownErrMask = 0;
    drawHomeScreen();
    checkHardware();
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

    // ---- result screen timeout -> back to initial screen ----
    if (currentScreen == S_RESULT && (long)(millis() - resultUntil) >= 0) {
      drawHomeScreen();
    }

    // ---- BOOT button toggles the QR screen on a debounced press ----
    int buttonReading = digitalRead(TEST_BUTTON);
    if (buttonReading != lastButtonReading) {
      lastButtonDebounceAt = millis();
      lastButtonReading = buttonReading;
    }
    if (millis() - lastButtonDebounceAt >= 50 && buttonReading != buttonState) {
      buttonState = buttonReading;
      if (buttonState == LOW) {
        if (currentScreen == S_HOME) drawQRScreen();
        else if (currentScreen == S_QR) drawHomeScreen();
      }
    }

    // ---- NFC first, so taps always get priority over network work ----
    checkNFC();

    // ---- network housekeeping (short, single-attempt requests) ----
    refreshTripId();
    sendGpsPosition();
  }