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

## Notes

- Training defaults are demo-scale: `imgsz=320`, `batch=4`, `device=cpu`.
  Keep epochs low (≤5) — CPU training is slow.
- `runs/` holds generated datasets and Ultralytics working output (gitignored).
