# Robot VPS Bridge (H-1, manual/debug)

Reliable manual/debug command link between a VPS-hosted Laravel API and an
ESP32 robot controller. No MQTT. No YOLO coupling. No workspace scaling yet.

## Architecture

```
Manual/iCAM future source
        |
        v (HTTPS POST)
VPS Laravel
        |
        v
robot_commands (persistent queue)
        |
        v (ESP32 outbound HTTPS polling)
ESP32 robot arm
```

The ESP32 always initiates outbound HTTPS requests to the VPS. Laravel never
connects to a private ESP32 IP.

## Lifecycle

```
POST /api/robot/commands
        |  status=PENDING
GET /api/robot/next-command      (oldest PENDING, atomically claimed)
        |
POST /api/robot/commands/{id}/ack        (PENDING -> ACKNOWLEDGED)
        |
POST /api/robot/commands/{id}/status    {"status":"EXECUTING"}
        |
POST /api/robot/commands/{id}/status    {"status":"COMPLETED"}
                                         or {"status":"FAILED","error":"..."}
```

ACK and terminal repeats are idempotent. `COMPLETED -> EXECUTING` and any
other unlisted transition is rejected. `FAILED` is terminal (no auto-retry).

## Endpoints (all require `Authorization: Bearer <ROBOT_DEVICE_TOKEN>`)

### POST /api/robot/commands — create a manual/debug command

```json
{ "x": 100.0, "y": 50.0, "G": 1, "R": 0, "Y": 0, "source": "manual" }
```

Response `201`:

```json
{
  "ok": true,
  "command": {
    "id": 27, "uuid": "...", "x": 100.0, "y": 50.0,
    "G": 1, "R": 0, "Y": 0, "color": "GREEN", "status": "PENDING"
  }
}
```

### GET /api/robot/next-command — oldest pending command (claimed atomically)

No command available:

```json
{ "ok": true, "command": null }
```

### POST /api/robot/commands/{id}/ack — acknowledge receipt

`PENDING` (or an already-`ACKNOWLEDGED` repeat) -> `ACKNOWLEDGED`.

### POST /api/robot/commands/{id}/status — advance execution

```json
{ "status": "EXECUTING" }
{ "status": "COMPLETED" }
{ "status": "FAILED", "error": "..." }
```

### GET /api/robot/status — queue summary

```json
{
  "ok": true, "pending": 2, "acknowledged": 1, "executing": 0,
  "completed": 7, "failed": 0, "latest_command": { "..." }
}
```

Counts are real database counts. No device-online status is reported —
there is no heartbeat in this PR.

## Payload contract

- `x` / `y` are already robot-space/debug coordinates in this PR.
  Automatic camera scaling, homography, pick-zone calibration, YOLO center
  mapping and inverse kinematics are explicitly NOT implemented yet — a
  future workspace-calibration step will generate `x`/`y` before creating
  the command.
- `G` / `R` / `Y` are one-hot: exactly one flag must be `1`.
  `G=1` => `GREEN`, `R=1` => `RED`, `Y=1` => `YELLOW`.
  `0,0,0`, `1,1,0`, `1,0,1`, `0,1,1`, `1,1,1` (and any other combination)
  are rejected with HTTP 422.
- External JSON keeps the exact uppercase `G`/`R`/`Y` keys; the database
  stores lowercase columns (`g`, `r`, `y_flag`) internally.
- Only uppercase English semantic colors (`GREEN`, `RED`, `YELLOW`).

## Authentication

- Device endpoints use a dedicated Bearer token from `ROBOT_DEVICE_TOKEN`
  (`config/services.php` → `services.robot.device_token`), compared with
  `hash_equals`. Missing/invalid token returns HTTP 401 JSON.
- The creation endpoint uses the same robot token for this H-1 debug
  implementation (no separate producer auth system).
- The token is never exposed in responses or logs.

## Manual client and ESP32 reference

- `tools/robot_vps_client.py` — stdlib-only HTTPS debug client
  (`ROBOT_API_BASE_URL`, `ROBOT_DEVICE_TOKEN`; menu `1`/`2`/`q`).
- `docs/esp32/RobotVpsBridge.ino` — reference sketch: Wi-Fi, ~750 ms poll,
  ACK → EXECUTING → simulated delay → COMPLETED. Placeholders only, no
  secrets; TLS validation point is marked in the code.
- Local values each tester must provide in the sketch (never commit them):
  `WIFI_SSID`, `WIFI_PASSWORD`,
  `ROBOT_API_BASE_URL=https://armatrix1.tech/api`, `ROBOT_DEVICE_TOKEN`
  (the VPS value), and a unique `DEVICE_ID` (e.g. `esp32-01`).
- TLS: the sketch defaults to secure certificate validation
  (`TLS_INSECURE_DEBUG=0`). Setting it to `1` is allowed ONLY for initial
  connectivity testing; the production / final demo must use proper CA
  verification (`client.setCACert(...)`).
