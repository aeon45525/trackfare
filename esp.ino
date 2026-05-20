#include <WiFi.h>
#include <HTTPClient.h>
#include <Wire.h>
#include <Adafruit_PN532.h>

#define SDA 21
#define SCL 22

Adafruit_PN532 nfc(SDA, SCL);

const char* ssid = "WILMASTORE";
const char* password = "edertgt01614";

const char* tapInURL = "http://192.168.100.103/TrackFare/api/tapin.php";
const char* tapOutURL = "http://192.168.100.103/TrackFare/api/tapout.php";

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

void sendToServer(String url, String uid) {
  HTTPClient http;
  http.begin(url);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");

  String payload = "uid=" + uid + "&trip_id=1";

  int httpCode = http.POST(payload);
  String response = http.getString();

  Serial.println("HTTP CODE: " + String(httpCode));
  Serial.println(response);

  http.end();
}

void setup() {
  Serial.begin(115200);

  WiFi.begin(ssid, password);
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
  }

  nfc.begin();
  nfc.SAMConfig();
}

void loop() {
  uint8_t uid[7];
  uint8_t uidLength;

  if (nfc.readPassiveTargetID(PN532_MIFARE_ISO14443A, uid, &uidLength)) {

    String uidStr = uidToString(uid, uidLength);

    Serial.println("CARD: " + uidStr);

    static bool toggle = false;

    if (!toggle) {
      sendToServer(tapInURL, uidStr);
      toggle = true;
    } else {
      sendToServer(tapOutURL, uidStr);
      toggle = false;
    }

    delay(2500);
  }
}