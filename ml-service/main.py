"""FastAPI entrypoint for the SortVision ML service."""
import base64
import tempfile
import threading
import time
from typing import Literal
from contextlib import asynccontextmanager
from pathlib import Path

import cv2
from fastapi import BackgroundTasks, FastAPI, Form, HTTPException, UploadFile
from fastapi.responses import JSONResponse, Response, StreamingResponse
from pydantic import BaseModel, Field, field_validator
from PIL import Image, UnidentifiedImageError

import callbacks
import infer
import preview
import sort_pipeline
import train
from config import settings
from flow import FlowAnalyzer
from stream import camera_source

_training_lock = threading.Lock()
MAX_FRAME_BYTES = 10 * 1024 * 1024


def _resolve_stream_model() -> str | None:
    """Resolve the configured stream model against Laravel storage."""
    return infer.resolve_model_path()


def _flow_loop(stop: threading.Event) -> None:
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
    while not stop.wait(0.1):
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


def _infer_loop(stop: threading.Event) -> None:
    """Periodically infer on the latest frame and push a Detection to Laravel.

    In competition (Vision Sorting) mode each frame runs the full sort
    pipeline (authoritative best.pt → ingest → pick-zone/dedupe/preflight
    gates → arm/command); otherwise the legacy plain-inference path posts a
    monitoring detection.
    """
    while not stop.wait(max(0.5, settings.icam_infer_interval)):
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
            if settings.competition_mode:
                result = sort_pipeline.run_sort_pipeline(tmp_path, settings.icam_conf)
                if not result.get("sort_triggered"):
                    print(f"[stream-sort] no command ({result.get('reason')})", flush=True)
            else:
                result = infer.infer_frame(tmp_path, _resolve_stream_model(), settings.icam_conf)
        except Exception as exc:  # noqa: BLE001
            print(f"[stream-infer] failed: {exc}", flush=True)
            continue
        finally:
            Path(tmp_path).unlink(missing_ok=True)

        if settings.competition_mode:
            # The sort pipeline already ingested the frame with competition
            # fields; nothing left to post from the loop.
            continue

        callbacks.post_detection(settings.laravel_url, {
            "status": result.get("status", "recheck"),
            "confidence": result.get("confidence", 0.0),
            "qr_value": result.get("qr_value"),
            "boxes": result.get("boxes", []),
            "detections": result.get("detections", []),
            "frame_width": result.get("frame_width"),
            "frame_height": result.get("frame_height"),
            "camera": settings.icam_camera,
            "conveyor": settings.icam_conveyor,
            "frame_jpeg_b64": base64.b64encode(jpeg).decode(),
        })


@asynccontextmanager
async def lifespan(app: FastAPI):
    # Start the camera buffer, and optionally the auto-inference loop.
    camera_source.start()
    stop = threading.Event()
    workers = []
    if settings.icam_auto_infer:
        workers.append(threading.Thread(target=_infer_loop, args=(stop,), daemon=True))
    if settings.flow_analysis:
        workers.append(threading.Thread(target=_flow_loop, args=(stop,), daemon=True))
    for worker in workers:
        worker.start()
    try:
        yield
    finally:
        stop.set()
        camera_source.stop()
        for worker in workers:
            worker.join(timeout=20)


app = FastAPI(title="SortVision ML Service", lifespan=lifespan)


class AnnotationItem(BaseModel):
    image_path: str = Field(min_length=1)
    label: str = Field(min_length=1, max_length=100)
    bbox: list[float] | None = Field(default=None, min_length=4, max_length=4)
    split: Literal["train", "val"] = "train"

    @field_validator("bbox")
    @classmethod
    def valid_bbox(cls, bbox):
        if bbox is not None:
            x, y, w, h = bbox
            if not (0 <= x <= 1 and 0 <= y <= 1 and 0 < w <= 1 and 0 < h <= 1
                    and x + w <= 1.000001 and y + h <= 1.000001):
                raise ValueError("bbox must be normalized [x, y, width, height] inside the frame")
        return bbox


class TrainRequest(BaseModel):
    run_id: int = Field(gt=0)
    epochs: int = Field(default=5, ge=1, le=1000)
    imgsz: int = Field(default=320, ge=32, le=2048)
    storage_path: str = Field(min_length=1)
    callback_url: str = Field(pattern=r"^https?://")
    annotations: list[AnnotationItem] = Field(min_length=1)


@app.get("/health")
def health():
    """Liveness + real Vision Sorting model health.

    In competition (Vision Sorting) mode the configured best.pt is actually
    resolved and loaded: model_loaded reflects reality, never a hardcoded
    true. A load failure returns model_loaded=false with a safe diagnostic
    (no secrets — the message is a YOLO/filesystem error string only).
    """
    base = {
        "status": "ok",
        "vision_sorting": settings.competition_mode,
        "camera_connected": bool(camera_source.status().get("connected", False)),
    }
    if not settings.competition_mode:
        try:
            info = infer.model_info(_resolve_stream_model())
            return {**base, "model_loaded": True, "base_model": settings.base_model,
                    "model_path": info["path"], "classes": info["classes"]}
        except infer.ModelError as exc:
            return {**base, "model_loaded": False, "diagnostic": str(exc)[:200]}
    try:
        resolved = sort_pipeline.resolve_sorting_model()
        classes = sort_pipeline.assert_sorting_classes(resolved)
        return {
            **base,
            "model_loaded": True,
            "model_path": settings.icam_model_path or None,
            "classes": {str(k): v for k, v in classes.items()},
        }
    except sort_pipeline.SortingModelError as exc:
        return {
            **base,
            "model_loaded": False,
            "model_path": settings.icam_model_path or None,
            "classes": {},
            "diagnostic": str(exc)[:200],
        }


class ReloadRequest(BaseModel):
    model_path: str | None = None


@app.post("/reload-model")
def reload_model(req: ReloadRequest | None = None):
    """Validate and activate weights for HTTP, stream and preview inference."""
    with infer.MODEL_LOCK:
        requested = req.model_path if req and req.model_path else settings.icam_model_path
        try:
            resolved = (sort_pipeline.resolve_sorting_model(requested)
                        if settings.competition_mode else infer.resolve_model_path(requested))
            infer.reload_models()
            if settings.competition_mode:
                sort_pipeline.assert_sorting_classes(resolved)
            else:
                infer.model_info(resolved)
        except (infer.ModelError, sort_pipeline.SortingModelError) as exc:
            raise HTTPException(503, detail={"error": "MODEL_ERROR", "message": str(exc)[:200]}) from exc
        settings.icam_model_path = resolved
    return {"ok": True, "model_path": resolved}


@app.get("/camera/status")
def camera_status():
    """Liveness/mode of the ICAM-300 (or simulator) source, for the UI."""
    return camera_source.status()


@app.get("/camera/frame")
def camera_frame():
    """Single latest JPEG frame.

    Native mobile image loaders cannot render a `multipart/x-mixed-replace`
    MJPEG stream, so clients that need a live view poll this instead. Laravel
    proxies it so the phone never talks to this service directly.
    """
    jpeg = camera_source.latest_jpeg()
    if jpeg is None:
        return Response(status_code=503)

    return Response(
        content=jpeg,
        media_type="image/jpeg",
        headers={"Cache-Control": "no-cache, no-store, must-revalidate"},
    )


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


@app.get("/model/info")
def model_info():
    """Real metadata about the active YOLO weights: path, size, classes,
    inference device. No inference is run; no side effects.

    In competition (Vision Sorting) mode the configured best.pt is
    authoritative: if it is missing, a MODEL_ERROR is returned instead of
    silently displaying yolov8n.pt as if it were the sorting model.
    """
    if settings.competition_mode:
        try:
            resolved = sort_pipeline.resolve_sorting_model()
            sort_pipeline.assert_sorting_classes(resolved)
        except sort_pipeline.SortingModelError as exc:
            return JSONResponse(status_code=503, content={
                "error": "MODEL_ERROR",
                "message": str(exc)[:200],
                "configured_path": settings.icam_model_path or None,
            })
        info = infer.model_info(resolved)
        return {
            **info,
            "configured_path": settings.icam_model_path or None,
            "base_model": settings.base_model,
            "conf_threshold": settings.icam_conf,
        }
    try:
        resolved = _resolve_stream_model()
        info = infer.model_info(resolved)
    except infer.ModelError as exc:
        return JSONResponse(status_code=503, content={"error": "MODEL_ERROR", "message": str(exc)[:200]})
    return {
        **info,
        "configured_path": settings.icam_model_path or None,
        "base_model": settings.base_model,
        "conf_threshold": settings.icam_conf,
    }


@app.get("/camera/preview")
def camera_preview():
    """Annotated MJPEG stream for Model Evaluation (bounding box + class +
    confidence overlays). READ-ONLY: preview never publishes arm/command and
    never writes detections — see preview.py safety contract."""
    return StreamingResponse(
        preview.preview_frames(),
        media_type="multipart/x-mixed-replace; boundary=frame",
        headers={"Cache-Control": "no-cache, no-store, must-revalidate"},
    )


@app.get("/preview/latest")
def preview_latest():
    """Latest preview inference snapshot for the info panel and logs."""
    snap = preview.latest_snapshot()
    return {"ok": snap["at"] is not None, **snap}


@app.post("/train", status_code=202)
def start_train(req: TrainRequest, background: BackgroundTasks):
    """Accept a training job and run it in the background (returns immediately)."""
    if not _training_lock.acquire(blocking=False):
        raise HTTPException(409, detail="A training run is already active")

    def run_job():
        try:
            train.run_training(
                req.run_id,
                req.epochs,
                req.imgsz,
                req.storage_path,
                req.callback_url,
                [a.model_dump() for a in req.annotations],
            )
        finally:
            _training_lock.release()

    background.add_task(run_job)
    return {"accepted": True, "run_id": req.run_id}


@app.post("/infer")
def run_infer(
    frame: UploadFile,
    conf: float = Form(0.85, ge=0, le=1),
    model_path: str | None = Form(None),
    camera: str | None = Form(None),
    conveyor: str | None = Form(None),
    product_id: int | None = Form(None),
):
    """Run inference on one uploaded frame and return the QC verdict inline.

    When competition_mode is enabled and a color class is detected, this also
    runs the sort pipeline: signed POST to /api/camera/detection + MQTT arm/command.
    """
    # A synchronous endpoint runs in FastAPI's thread pool; CPU inference and
    # network callbacks must not block health checks or the camera feed.
    data = frame.file.read(MAX_FRAME_BYTES + 1)
    if len(data) > MAX_FRAME_BYTES:
        raise HTTPException(413, detail="Frame exceeds 10 MiB")
    with tempfile.NamedTemporaryFile(suffix=".jpg", delete=False) as tmp:
        tmp.write(data)
        tmp_path = tmp.name

    try:
        try:
            with Image.open(tmp_path) as uploaded:
                uploaded.verify()
        except (UnidentifiedImageError, OSError, ValueError, Image.DecompressionBombError) as exc:
            raise HTTPException(422, detail="Frame must be a valid image") from exc
        if settings.competition_mode:
            result = sort_pipeline.run_sort_pipeline(tmp_path, conf, model_path,
                                                     camera=camera, conveyor=conveyor)
            if result.get("reason") == "model_error":
                return JSONResponse(status_code=503, content=result)
        else:
            result = infer.infer_frame(tmp_path, model_path, conf)
    except infer.ModelError as exc:
        raise HTTPException(503, detail={"error": "MODEL_ERROR", "message": str(exc)[:200]}) from exc
    finally:
        Path(tmp_path).unlink(missing_ok=True)

    result["camera"] = camera
    result["conveyor"] = conveyor
    result["product_id"] = product_id
    return result
