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
  mode is `LIVE`, `SIMULATOR`, or `OFFLINE`.
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

- `ICAM_MODEL_PATH` — authoritative `models/run-100/best.pt` with the exact
  class map `{0: HIJAU, 1: KUNING, 2: MERAH}`. Any mismatch ⇒ `MODEL_ERROR`,
  never a silent fallback.
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

- `ICAM_RTSP_URL` — real camera URL. **Leave empty for simulator mode.**
  (Known raw/default preview context `192.168.0.100:5001` is not assumed to
  be the YOLO frame source — keep the actual source configurable here.)
- `ICAM_SIM_SOURCE` — fallback when RTSP is empty/unreachable: a looped video
  file path (`samples/conveyor.mp4`) or a webcam index (`"0"`). If neither is
  available, synthetic conveyor frames are generated.
- `ICAM_CAMERA`, `ICAM_CONVEYOR` — labels stamped on detections.
- `ICAM_MODEL_PATH` — authoritative `models/run-100/best.pt` (exact class
  map `{0: HIJAU, 1: KUNING, 2: MERAH}`; anything else ⇒ `MODEL_ERROR`).
- `ICAM_CONF` — YOLO confidence threshold (0–1).

Test without hardware: leave `ICAM_RTSP_URL` empty, start the service, open
`http://127.0.0.1:8001/camera/preview` (annotated) or
`http://127.0.0.1:8001/camera/raw`. Check `http://127.0.0.1:8001/health`,
`/model/info`, `/detections/latest`, and `/stats/summary` for the runtime
telemetry the dashboard graphs consume.

## Notes

- Training defaults are demo-scale: `imgsz=320`, `batch=4`, `device=cpu`.
  Keep epochs low (≤5) — CPU training is slow.
- `runs/` holds generated datasets and Ultralytics working output (gitignored).
