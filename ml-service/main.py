"""FastAPI entrypoint for the SortVision ML service."""
import base64
import tempfile
import threading
import time
from contextlib import asynccontextmanager
from pathlib import Path

import cv2
from fastapi import BackgroundTasks, FastAPI, Form, UploadFile
from fastapi.responses import StreamingResponse
from pydantic import BaseModel

import callbacks
import infer
import train
from config import settings
from flow import FlowAnalyzer
from stream import camera_source


def _resolve_stream_model() -> str | None:
    """Resolve the configured stream model against Laravel storage."""
    rel = settings.icam_model_path
    if not rel:
        return None
    candidate = Path(settings.laravel_storage_path) / rel
    return str(candidate) if candidate.exists() else rel


def _flow_loop() -> None:
    """Continuously watch the stream for conveyor jam/off_flow anomalies.

    Runs faster than the infer loop (every frame it can grab) so the rolling
    window reflects real motion; anomalies are POSTed to Laravel, which logs
    them and broadcasts a conveyor/alert.
    """
    analyzer = FlowAnalyzer(
        window=settings.flow_window,
        jam_occupancy=settings.flow_jam_occupancy,
        jam_motion=settings.flow_jam_motion,
        offflow_occupancy=settings.flow_offflow_occupancy,
    )
    while True:
        time.sleep(0.1)
        frame = camera_source.latest_frame()
        if frame is None:
            continue
        try:
            result = analyzer.analyze(frame)
        except Exception as exc:  # noqa: BLE001
            print(f"[flow] failed: {exc}", flush=True)
            continue
        if result["event"] is None:
            continue
        callbacks.post_conveyor_event(settings.laravel_url, {
            "event": result["event"],
            "conveyor": settings.icam_conveyor,
            "camera": settings.icam_camera,
            "metrics": {"occupancy": result["occupancy"], "motion": result["motion"]},
        })


def _infer_loop() -> None:
    """Periodically infer on the latest frame and push a Detection to Laravel."""
    model = _resolve_stream_model()
    while True:
        time.sleep(max(0.5, settings.icam_infer_interval))
        frame = camera_source.latest_frame()
        if frame is None:
            continue
        ok, buf = cv2.imencode(".jpg", frame)
        if not ok:
            continue
        jpeg = buf.tobytes()
        with tempfile.NamedTemporaryFile(suffix=".jpg", delete=False) as tmp:
            tmp.write(jpeg)
            tmp_path = tmp.name
        try:
            result = infer.infer_frame(tmp_path, model, settings.icam_conf)
        except Exception as exc:  # noqa: BLE001
            print(f"[stream-infer] failed: {exc}", flush=True)
            continue
        finally:
            Path(tmp_path).unlink(missing_ok=True)

        callbacks.post_detection(settings.laravel_url, {
            "status": result.get("status", "recheck"),
            "confidence": result.get("confidence", 0.0),
            "qr_value": result.get("qr_value"),
            "boxes": result.get("boxes", []),
            "detections": result.get("detections", []),
            "camera": settings.icam_camera,
            "conveyor": settings.icam_conveyor,
            "frame_jpeg_b64": base64.b64encode(jpeg).decode(),
        })


@asynccontextmanager
async def lifespan(app: FastAPI):
    # Start the camera buffer, and optionally the auto-inference loop.
    camera_source.start()
    if settings.icam_auto_infer:
        threading.Thread(target=_infer_loop, daemon=True).start()
    if settings.flow_analysis:
        threading.Thread(target=_flow_loop, daemon=True).start()
    yield
    camera_source.stop()


app = FastAPI(title="SortVision ML Service", lifespan=lifespan)


class AnnotationItem(BaseModel):
    image_path: str
    label: str
    bbox: list[float] | None = None
    split: str = "train"


class TrainRequest(BaseModel):
    run_id: int
    epochs: int = 5
    imgsz: int = 320
    storage_path: str
    callback_url: str
    annotations: list[AnnotationItem] = []


@app.get("/health")
def health():
    return {"status": "ok", "model_loaded": True, "base_model": settings.base_model}


class ReloadRequest(BaseModel):
    model_path: str | None = None


@app.post("/reload-model")
def reload_model(req: ReloadRequest | None = None):
    """Drop cached YOLO weights so a newly activated model takes effect without
    a service restart. The optional model_path is informational (logging)."""
    infer.reload_models()
    print(f"[reload-model] cache cleared (hint: {req.model_path if req else None})", flush=True)
    return {"ok": True}


@app.get("/camera/status")
def camera_status():
    """Liveness/mode of the ICAM-300 (or simulator) source, for the UI."""
    return camera_source.status()


@app.get("/camera/stream")
def camera_stream():
    """MJPEG stream of the live source — displayable directly in an <img>."""
    def frames():
        boundary = b"--frame\r\n"
        while True:
            jpeg = camera_source.latest_jpeg()
            if jpeg is not None:
                yield boundary + b"Content-Type: image/jpeg\r\n\r\n" + jpeg + b"\r\n"
            time.sleep(0.066)  # ~15 fps

    return StreamingResponse(
        frames(),
        media_type="multipart/x-mixed-replace; boundary=frame",
        headers={"Cache-Control": "no-cache, no-store, must-revalidate"},
    )


@app.post("/train", status_code=202)
def start_train(req: TrainRequest, background: BackgroundTasks):
    """Accept a training job and run it in the background (returns immediately)."""
    background.add_task(
        train.run_training,
        req.run_id,
        req.epochs,
        req.imgsz,
        req.storage_path,
        req.callback_url,
        [a.model_dump() for a in req.annotations],
    )
    return {"accepted": True, "run_id": req.run_id}


@app.post("/infer")
async def run_infer(
    frame: UploadFile,
    conf: float = Form(0.85),
    model_path: str | None = Form(None),
    camera: str | None = Form(None),
    conveyor: str | None = Form(None),
    product_id: int | None = Form(None),
):
    """Run inference on one uploaded frame and return the QC verdict inline."""
    # Resolve a relative model path (models/run-x/best.pt) against Laravel storage.
    resolved_model = None
    if model_path:
        candidate = Path(settings.laravel_storage_path) / model_path
        resolved_model = str(candidate) if candidate.exists() else model_path

    data = await frame.read()
    with tempfile.NamedTemporaryFile(suffix=".jpg", delete=False) as tmp:
        tmp.write(data)
        tmp_path = tmp.name

    try:
        result = infer.infer_frame(tmp_path, resolved_model, conf)
    finally:
        Path(tmp_path).unlink(missing_ok=True)

    result["camera"] = camera
    result["conveyor"] = conveyor
    result["product_id"] = product_id
    return result
