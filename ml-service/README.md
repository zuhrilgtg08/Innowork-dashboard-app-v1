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

- `GET  /health` — liveness.
- `POST /train`  — `{run_id, epochs, imgsz, storage_path, callback_url, annotations[]}`;
  returns 202 and trains in the background, POSTing progress back to Laravel.
- `POST /infer`  — multipart `frame` (JPEG) + `conf`, `model_path`, context;
  returns `{status, confidence, boxes}`.
- `GET  /camera/stream` — MJPEG (`multipart/x-mixed-replace`) of the live source,
  displayable directly in a browser `<img>`.
- `GET  /camera/status` — `{connected, mode, source, fps}`.

## ICAM-300 camera integration

The service can pull the Advantech **ICAM-300** RTSP stream
(`rtsp://<ip>:8550/video`, available when the camera is "playing" at ≥5fps),
re-serve it as browser-friendly MJPEG, and — with auto-infer on — run YOLO on
it every few seconds and POST each verdict to Laravel (`/api/camera/detection`,
HMAC-signed). **No code runs on the camera**; it just streams.

`.env` keys:

- `ICAM_RTSP_URL` — real camera URL. **Leave empty for simulator mode.**
- `ICAM_SIM_SOURCE` — fallback when RTSP is empty/unreachable: a looped video
  file path (`samples/conveyor.mp4`) or a webcam index (`"0"`). If neither is
  available, synthetic conveyor frames are generated.
- `ICAM_AUTO_INFER` — `true` to run the periodic infer→POST loop.
- `ICAM_INFER_INTERVAL` — seconds between inferences (default 3).
- `ICAM_CAMERA`, `ICAM_CONVEYOR` — labels stamped on detections.
- `ICAM_MODEL_PATH` — optional `models/run-x/best.pt` (else base model).

Test without hardware: leave `ICAM_RTSP_URL` empty, start the service, open
`http://127.0.0.1:8001/camera/stream`. Set `ICAM_AUTO_INFER=true` (with Laravel
running + matching `ML_CALLBACK_SECRET`) to see detections flow into the
dashboard's Live Camera feed.

## Notes

- Training defaults are demo-scale: `imgsz=320`, `batch=4`, `device=cpu`.
  Keep epochs low (≤5) — CPU training is slow.
- `runs/` holds generated datasets and Ultralytics working output (gitignored).
