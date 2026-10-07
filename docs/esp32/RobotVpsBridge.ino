/*
 * RobotVpsBridge.ino — reference ESP32 implementation for the VPS robot
 * command bridge (H-1, manual/debug).
 *
 * Flow (ESP32 always initiates outbound HTTPS; the VPS never dials in):
 *   GET  /api/robot/next-command
 *   POST /api/robot/commands/{id}/ack        (PENDING -> ACKNOWLEDGED)
 *   POST /api/robot/commands/{id}/status     {"status":"EXECUTING"}
 *   ... simulate execution with a short delay (NO real motor/arm control) ...
 *   POST /api/robot/commands/{id}/status     {"status":"COMPLETED"}
 *
 * Required libraries (Arduino IDE Library Manager):
 *   - ArduinoJson (for parsing the command payload)
 *
 * Fill in the placeholders below. Do NOT commit real Wi-Fi credentials or
 * tokens — keep them in this sketch locally only.
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <ArduinoJson.h>

// ---- Local values (replace the quoted placeholders locally) ----
// Each tester must provide these five values; do NOT commit real secrets:
//   WIFI_SSID          - your Wi-Fi network name
//   WIFI_PASSWORD      - your Wi-Fi password
//   ROBOT_API_BASE_URL - "https://armatrix1.tech/api"
//   ROBOT_DEVICE_TOKEN - the VPS ROBOT_DEVICE_TOKEN value (ask the backend owner)
//   DEVICE_ID          - unique per device, e.g. "esp32-01"
#define WIFI_SSID "WIFI_SSID"
#define WIFI_PASSWORD "WIFI_PASSWORD"
#define ROBOT_API_BASE_URL "https://armatrix1.tech/api"
#define ROBOT_DEVICE_TOKEN "ROBOT_DEVICE_TOKEN"
#define DEVICE_ID "esp32-01"

// Poll cadence: 500 ms .. 1000 ms.
#define POLL_INTERVAL_MS 750
// Simulated execution time (no real arm control in this reference).
#define FAKE_EXEC_MS 1500

// ---- TLS ----
// Default is secure: the VPS certificate is validated. For production and
// the final demo, install the VPS CA certificate and validate it here:
//   client.setCACert(LETSENCRYPT_ROOT_CA);
// TLS_INSECURE_DEBUG=1 is allowed ONLY for initial connectivity testing on a
// test network (it skips certificate verification). It MUST be 0 for the
// production / final demo. Do NOT hardcode credentials anywhere in this file.
#define TLS_INSECURE_DEBUG 0

unsigned long lastPollMs = 0;

String authHeaders(HTTPClient &http) {
  http.addHeader("Accept", "application/json");
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Authorization", String("Bearer ") + ROBOT_DEVICE_TOKEN);
  return String("Bearer ") + ROBOT_DEVICE_TOKEN;
}

String apiGet(const String &path, int &outStatus) {
  WiFiClientSecure client;
#if TLS_INSECURE_DEBUG
  client.setInsecure(); // DEBUG ONLY — see TLS note above.
#endif
  HTTPClient http;
  http.begin(client, ROBOT_API_BASE_URL + path);
  authHeaders(http);
  outStatus = http.GET();
  String body = (outStatus > 0) ? http.getString() : String("");
  http.end();
  return body;
}

String apiPost(const String &path, const String &json, int &outStatus) {
  WiFiClientSecure client;
#if TLS_INSECURE_DEBUG
  client.setInsecure(); // DEBUG ONLY — see TLS note above.
#endif
  HTTPClient http;
  http.begin(client, ROBOT_API_BASE_URL + path);
  authHeaders(http);
  outStatus = http.POST(json);
  String body = (outStatus > 0) ? http.getString() : String("");
  http.end();
  return body;
}

void handleCommand(long id, float x, float y, int g, int r, int yf, const String &color) {
  Serial.print("command id: "); Serial.println(id);
  Serial.print("X: "); Serial.println(x);
  Serial.print("Y: "); Serial.println(y);
  Serial.print("G: "); Serial.println(g);
  Serial.print("R: "); Serial.println(r);
  Serial.print("Y: "); Serial.println(yf);
  Serial.print("color: "); Serial.println(color);

  String base = "/robot/commands/" + String(id);
  int status;
  String device = String("{\"device_id\":\"") + DEVICE_ID + "\"}";

  String ackBody = apiPost(base + "/ack", device, status);
  Serial.print("ACK -> HTTP "); Serial.println(status);
  if (status != 200) { Serial.println(ackBody); return; }

  String execJson = String("{\"status\":\"EXECUTING\",\"device_id\":\"") + DEVICE_ID + "\"}";
  String execBody = apiPost(base + "/status", execJson, status);
  Serial.print("EXECUTING -> HTTP "); Serial.println(status);
  if (status != 200) { Serial.println(execBody); return; }

  delay(FAKE_EXEC_MS); // Simulated execution only. No motor/arm control here.

  String doneJson = String("{\"status\":\"COMPLETED\",\"device_id\":\"") + DEVICE_ID + "\"}";
  String doneBody = apiPost(base + "/status", doneJson, status);
  Serial.print("COMPLETED -> HTTP "); Serial.println(status);
  Serial.println(doneBody);
}

void pollOnce() {
  int status;
  String body = apiGet(String("/robot/next-command?device_id=") + DEVICE_ID, status);
  if (status != 200) {
    Serial.print("poll HTTP "); Serial.println(status);
    return;
  }
  StaticJsonDocument<512> doc;
  if (deserializeJson(doc, body)) { Serial.println("poll: invalid JSON"); return; }
  if (!doc["ok"] || doc["command"].isNull()) return; // nothing pending

  JsonObject cmd = doc["command"];
  handleCommand(
    cmd["id"].as<long>(),
    cmd["x"].as<float>(), cmd["y"].as<float>(),
    cmd["G"].as<int>(), cmd["R"].as<int>(), cmd["Y"].as<int>(),
    cmd["color"].as<String>()
  );
}

void setup() {
  Serial.begin(115200);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  Serial.print("WiFi connecting");
  while (WiFi.status() != WL_CONNECTED) { delay(300); Serial.print("."); }
  Serial.println("\nWiFi connected");
}

void loop() {
  unsigned long now = millis();
  if (now - lastPollMs >= POLL_INTERVAL_MS) {
    lastPollMs = now;
    if (WiFi.status() == WL_CONNECTED) pollOnce();
  }
}
