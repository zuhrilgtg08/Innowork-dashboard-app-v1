# SortVision ML Service

FastAPI + Ultralytics YOLO service that the Laravel app calls over HTTP for
labeling → training → live inference. Runs on CPU (demo-scale).

## Setup (Windows, dedicated Python 3.12 venv recommended)

Ultralytics officially supports Python 3.9–3.12. If the system Python is 3.13,
install 3.12 alongside it and point the venv at it.

```bash
cd ml-service
py -3.12 -m venv .venv          # or: python -m venv .venv
.venv/Scripts/activate
pip install torch --index-url https://download.pytorch.org/whl/cpu
pip install -r requirements.txt
```

## Run

```bash
uvicorn main:app --host 127.0.0.1 --port 8001 --reload
```

Check: `curl http://127.0.0.1:8001/health`

## Config (`.env`)

- `LARAVEL_STORAGE_PATH` — absolute path to the Laravel app's `storage/app`
  (the service reads annotation images and writes `models/run-*/best.pt` there).
- `ML_CALLBACK_SECRET` — must match Laravel's `ML_CALLBACK_SECRET`; used to sign
  training progress/complete/fail callbacks.

## Endpoints

- `GET  /health` — liveness + model/camera/inference telemetry
  (`model_loaded`, `camera_connected`, `camera_mode`, `camera_fps`,
  `inference_fps`, `last_inference_ms`). `MODEL_ERROR` is reported honestly
  when best.pt cannot be used.
- `GET  /model/info` — authoritative best.pt path, file size, exact class
  map, device, confidence threshold (503 `MODEL_ERROR` when unusable).
- `POST /train`  — `{run_id, epochs, imgsz, storage_path, callback_url, annotations[]}`;
  returns 202 and trains in the background, POSTing progress back to Laravel.
- `POST /infer`  — multipart `frame` (JPEG) + `conf`, `model_path`, context;
  returns `{status, confidence, boxes}`.
- `GET  /camera/status` — `{connected, mode, source, fps, frame_width, frame_height}`;
  mode is `LIVE`, `SIMULATOR`, or `OFFLINE`; `source` is credential-redacted
  (`rtsp://user:pass@host` ⇒ `rtsp://***:***@host`).
- `GET  /camera/stream` — raw MJPEG from the shared frame cache.
- `GET  /camera/raw` — continuous raw MJPEG preview (no YOLO overlay).
- `GET  /camera/frame` — latest raw JPEG (pollable still).
- `GET  /camera/preview` — continuous annotated MJPEG preview (English
  GREEN/YELLOW/RED overlay), served from the shared inference cache —
  never runs YOLO per request.
- `GET  /camera/preview/frame` — latest annotated JPEG (pollable still).
- `GET  /preview/latest` — latest snapshot (legacy shape, existing clients).
- `GET  /detections/latest` — `{ok, timestamp, frame, detections[]}` with
  class ids, raw + English names, 0–100 confidence, pixel bbox/center and
  clamped 0–1 normalized geometry.
- `GET  /stats/summary` — per-class counts, totals, fps, latency, uptime.
- `GET  /stats/confidence` — recent confidence samples for graphing.
- `GET  /stats/timeline` — detections per time bucket.

## Continuous runtime (no MQTT)

One camera capture connection (`stream.CameraSource`) feeds one inference
worker (`runtime.InferenceWorker`) running the shared best.pt handle. Every
MJPEG/JSON endpoint serves the cached state — no per-request inference, no
per-browser stream, no broker. Monitoring is pull-based; nothing POSTs per
frame and nothing publishes `arm/command`.

- `ICAM_MODEL_PATH` — authoritative `models/run-100/best.pt` covering
  exactly the semantic classes `GREEN`/`YELLOW`/`RED` (currently YOLO11
  segmentation, `{0: GREEN, 1: YELLOW, 2: RED}`, `imgsz=512`; Indonesian raw
  labels and any class-ID order are also accepted). Any mismatch ⇒
  `MODEL_ERROR`, never a silent COCO fallback. Segmentation masks are
  ignored — the UI/API contract stays bounding-box only
  (`class`, `confidence`, `bbox`, `center x/y`).
- `ICAM_AUTO_INFER` / `ICAM_INFER_INTERVAL` — legacy keys, no longer used
  (the worker infers continuously; remove them from your `.env` if present).
- `SORTING_MQTT_ENABLED` — legacy opt-in for the per-request sort-pipeline
  publish path only (requires paho-mqtt installed separately).

## ICAM-300 camera integration

The service can pull the Advantech **ICAM-300** RTSP stream
(`rtsp://<ip>:8550/video`, available when the camera is "playing" at ≥5fps),
re-serve it as browser-friendly MJPEG, and — with auto-infer on — run YOLO on
it every few seconds and POST each verdict to Laravel (`/api/camera/detection`,
HMAC-signed). **No code runs on the camera**; it just streams.

`.env` keys:

- `ICAM_RTSP_URL` — project camera `rtsp://192.168.0.100:8550/video` for
  local integration testing (runtime stays environment-driven; the address
  is never hard-coded in Python source). Leave empty only for local
  development with `ICAM_ALLOW_SIMULATOR=true`. An unreachable source
  reports `camera_connected=false` and `camera_mode=OFFLINE` — never `LIVE`
  and never a silent simulator substitution in production/demo mode.
  (Known raw/default preview context `192.168.0.100:5001` is not assumed to
  be the YOLO frame source — keep the actual source configurable here.)
- `ICAM_SIM_SOURCE` — simulator source used only when RTSP is
  empty/unreachable **and** `ICAM_ALLOW_SIMULATOR=true`: a looped video file
  path (`samples/conveyor.mp4`) or a webcam index (`"0"`). If neither is
  available, synthetic conveyor frames are generated (local development only).
- `ICAM_CAMERA`, `ICAM_CONVEYOR` — labels stamped on detections.
- `ICAM_MODEL_PATH` — authoritative `models/run-100/best.pt` (semantic
  coverage `GREEN`/`YELLOW`/`RED`; anything else ⇒ `MODEL_ERROR`).
- `ICAM_CONF` — YOLO confidence threshold (0–1). Deployment default for the
  current model is `0.60` (see training/runtime report); still
  environment-driven, never hard-coded in inference code.
- `ICAM_ALLOW_SIMULATOR` — `false` by default (production/demo intent): an
  unreachable real camera reports `OFFLINE` with no synthetic feed. Set
  `true` only for local development; simulator output is always labeled
  `SIMULATOR`, never `LIVE`.

Test without hardware (local development only): set `ICAM_RTSP_URL` empty
**and** `ICAM_ALLOW_SIMULATOR=true` — empty URL alone does not enable the
simulator (production/demo keeps `ICAM_ALLOW_SIMULATOR=false` and reports
`OFFLINE`). Then start the service and open
`http://127.0.0.1:8001/camera/preview` (annotated) or
`http://127.0.0.1:8001/camera/raw`. Check `http://127.0.0.1:8001/health`,
`/model/info`, `/detections/latest`, and `/stats/summary` for the runtime
telemetry the dashboard graphs consume.

## Manual bring-up (operator only)

MANUAL COMMAND — DO NOT RUN DURING AGENT TASK. The agent must never start
services; the commands below are for a human operator validating the demo.

```bash
# 1. Activate the ML venv (from the repo root or ml-service/)
# 2. Validate best.pt (semantic coverage GREEN/YELLOW/RED required)
python -c "from ultralytics import YOLO; print(YOLO('storage/app/models/run-100/best.pt').names)"
#    expected: {0: 'GREEN', 1: 'YELLOW', 2: 'RED'} (IDs may differ by build)
# 3. Start FastAPI (foreground, for the demo check)
uvicorn main:app --host 127.0.0.1 --port 8001
# 4-10. In another terminal:
curl http://127.0.0.1:8001/health            # model_loaded, camera + inference telemetry
curl http://127.0.0.1:8001/model/info        # path, size, class map, device, threshold
curl http://127.0.0.1:8001/camera/status     # LIVE | SIMULATOR | OFFLINE
#    open http://127.0.0.1:8001/camera/raw        # raw MJPEG (browser)
#    open http://127.0.0.1:8001/camera/preview    # annotated MJPEG (browser)
curl http://127.0.0.1:8001/detections/latest # YOLO result + coordinates
curl http://127.0.0.1:8001/stats/summary     # counts, fps, latency, uptime
# 11-17. Start Laravel separately, open Live Camera, confirm the YOLO
# preview, RED/GREEN/YELLOW labels, X/Y coordinates, updating graphs, and
# that no browser camera permission prompt appears.
```

Production installs the `deploy/sortvision-ml.service.example` template
manually (see the header comment inside that file).

## VPS deployment (model update)

Model location (copied separately — `*.pt` binaries are gitignored, never
committed):

```
/var/www/sortvision/storage/app/models/run-100/best.pt
```

Environment (see `.env.example`):

```
LARAVEL_STORAGE_PATH=/var/www/sortvision/storage/app
ICAM_MODEL_PATH=models/run-100/best.pt
ICAM_CONF=0.60
```

Expected verification after deploy/restart (no credentials in output —
`/camera/status` redacts RTSP userinfo):

```bash
curl http://127.0.0.1:8001/model/info     # real path, class map, file size, device
curl http://127.0.0.1:8001/health         # model_loaded=true only if best.pt loads
curl http://127.0.0.1:8001/camera/status  # LIVE / OFFLINE / SIMULATOR, honestly
curl http://127.0.0.1:8001/camera/preview/frame  # latest annotated JPEG
```

The service binds privately (`127.0.0.1:8001`); browsers use the Laravel
same-origin proxy routes, never port 8001 directly. If `best.pt` is
missing or invalid, endpoints report `MODEL_ERROR` — no silent fallback.

## Notes

- Training defaults are demo-scale: `imgsz=320`, `batch=4`, `device=cpu`.
  Keep epochs low (≤5) — CPU training is slow.
- `runs/` holds generated datasets and Ultralytics working output (gitignored).
