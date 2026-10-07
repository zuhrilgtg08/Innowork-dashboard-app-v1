"""FastAPI entrypoint for the SortVision ML service.

Runtime architecture (no MQTT):

    Camera Capture Worker (stream.CameraSource, ONE connection)
            |
    Inference Worker (runtime.InferenceWorker, ONE thread, ONE best.pt)
            |
    shared RuntimeState: latest frames + detections + statistics

Every MJPEG/JSON endpoint serves the shared cached state — inference NEVER
runs inside a request handler, and the camera stream is never reopened per
browser. See runtime.py.
"""
import tempfile
import threading
import time
from contextlib import asynccontextmanager
from pathlib import Path
from urllib.parse import urlsplit, urlunsplit

from fastapi import BackgroundTasks, FastAPI, Form, UploadFile
from fastapi.responses import JSONResponse, Response, StreamingResponse
from pydantic import BaseModel

import callbacks
import infer
import robot_bridge
import runtime
import sort_pipeline
import train
import vision_model
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


@asynccontextmanager
async def lifespan(app: FastAPI):
    # Start the single camera capture connection plus the single continuous
    # YOLO inference worker. Monitoring is pull-based (shared RuntimeState);
    # the optional robot bridge consumes snapshots in its own TCP worker.
    camera_source.start()
    runtime.start()
    bridge = robot_bridge.RobotBridge(settings, runtime.state)
    app.state.robot_bridge = bridge
    bridge.start()
    if settings.flow_analysis:
        threading.Thread(target=_flow_loop, daemon=True).start()
    try:
        yield
    finally:
        bridge.stop()
        runtime.stop()
        camera_source.stop()


app = FastAPI(title="SortVision ML Service", lifespan=lifespan)


@app.get("/robot/status")
def robot_status():
    """Read-only TCP status; ACK means received, not motion complete."""
    bridge = getattr(app.state, "robot_bridge", None)
    if bridge is None:
        return {"enabled": settings.robot_bridge_enabled, "state": "not_started",
                "transport": "tcp", "coordinate_unit": "mm", "connected": False,
                "last_payload": None, "last_response": None,
                "acknowledged_frames": 0, "error": None}
    return bridge.snapshot()


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
    """Liveness + real Vision Sorting model health.

    model_loaded reflects reality, never a hardcoded true: in competition
    (Vision Sorting) mode the configured best.pt is actually resolved and
    class-validated. A load failure returns model_loaded=false with a safe
    diagnostic (no secrets — the message is a YOLO/filesystem error string
    only), i.e. MODEL_ERROR. Nothing here requires MQTT.
    """
    snap = runtime.state.snapshot_health()
    base = {
        "status": "ok",
        "vision_sorting": settings.competition_mode,
        "model_loaded": snap["model_loaded"],
        "camera_connected": snap["camera_connected"],
        "camera_mode": snap["camera_mode"],
        "camera_fps": snap["camera_fps"],
        "inference_fps": snap["inference_fps"],
        "last_inference_ms": snap["last_inference_ms"],
    }
    if not settings.competition_mode:
        return {**base, "base_model": settings.base_model}
    if not snap["model_loaded"]:
        return {**base, "model_path": settings.icam_model_path or None,
                "classes": {}, "diagnostic": (snap["model_error"] or "")[:200]}
    try:
        resolved = sort_pipeline.resolve_sorting_model()
        classes = sort_pipeline.assert_sorting_classes(resolved)
        return {
            **base,
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
    """Drop cached YOLO weights so a newly activated model takes effect without
    a service restart. The optional model_path is informational (logging)."""
    infer.reload_models()
    print(f"[reload-model] cache cleared (hint: {req.model_path if req else None})", flush=True)
    return {"ok": True}


def redact_url_credentials(url: str | None) -> str:
    """Strip username/password from a camera URL for /camera/status.

    `rtsp://user:pass@host:8550/video` becomes
    `rtsp://***:***@host:8550/video`. Non-URL values (file paths, webcam
    indexes, empty strings) pass through unchanged. Credentials must never
    reach the dashboard, logs, or API clients through this endpoint.
    """
    text = str(url or "")
    if "://" not in text or "@" not in text:
        return text
    try:
        parts = urlsplit(text)
        if not parts.username and not parts.password:
            return text
        netloc = "***:***@" + (parts.hostname or "")
        try:
            port = parts.port
        except ValueError:
            port = None
        if port:
            netloc += f":{port}"
        return urlunsplit((parts.scheme, netloc, parts.path, parts.query, parts.fragment))
    except ValueError:
        return text


@app.get("/camera/status")
def camera_status():
    """Liveness/mode of the ICAM-300 (or simulator) source, for the UI.

    mode is one of LIVE | SIMULATOR | OFFLINE (uppercase; the dashboard must
    never describe simulator mode as a live iCAM connection). The source
    URL is credential-redacted (see redact_url_credentials).
    """
    snap = runtime.state.snapshot_camera()
    frame = camera_source.latest_frame()
    h, w = (frame.shape[:2] if frame is not None
            else (snap["frame_height"], snap["frame_width"]))
    return {
        "connected": snap["connected"],
        "mode": snap["mode"].upper(),
        "source": redact_url_credentials(
            snap["source"] or settings.icam_rtsp_url or settings.icam_sim_source
        ),
        "fps": snap["fps"],
        "frame_width": w,
        "frame_height": h,
    }


@app.get("/camera/frame")
def camera_frame():
    """Single latest raw JPEG frame.

    Native mobile image loaders cannot render a `multipart/x-mixed-replace`
    MJPEG stream, so clients that need a live view poll this instead. Laravel
    proxies it so the phone never talks to this service directly.
    """
    jpeg = runtime.state.get_raw_jpeg(max_age_s=3600) or camera_source.latest_jpeg()
    if jpeg is None:
        return Response(status_code=503)

    return Response(
        content=jpeg,
        media_type="image/jpeg",
        headers={"Cache-Control": "no-cache, no-store, must-revalidate"},
    )


def _mjpeg(get_jpeg, interval: float):
    """Shared MJPEG generator: yield the cached JPEG, never run inference."""
    boundary = b"--frame\r\n"
    while True:
        jpeg = get_jpeg()
        if jpeg is not None:
            yield boundary + b"Content-Type: image/jpeg\r\n\r\n" + jpeg + b"\r\n"
        time.sleep(interval)


@app.get("/camera/stream")
def camera_stream():
    """MJPEG stream of the live raw source — displayable in an <img>.

    Serves the shared cached frame (~15 fps); the capture connection is
    opened once, never per browser.
    """
    return StreamingResponse(
        _mjpeg(runtime.state.get_raw_jpeg, 0.066),
        media_type="multipart/x-mixed-replace; boundary=frame",
        headers={"Cache-Control": "no-cache, no-store, must-revalidate"},
    )


@app.get("/camera/raw")
def camera_raw():
    """Continuous raw MJPEG preview. NO YOLO overlay.

    Same shared cache as /camera/stream (raw frames only).
    """
    return StreamingResponse(
        _mjpeg(runtime.state.get_raw_jpeg, 0.066),
        media_type="multipart/x-mixed-replace; boundary=frame",
        headers={"Cache-Control": "no-cache, no-store, must-revalidate"},
    )


@app.get("/model/info")
def model_info():
    """Real metadata about the active YOLO weights: path, size, classes,
    inference device. No inference is run; no side effects.

    The configured best.pt is always authoritative: if it is missing or
    its classes are invalid, a MODEL_ERROR is returned instead of silently
    displaying yolov8n.pt or COCO as if it were the sorting model. This
    check never depends on COMPETITION_MODE.
    """
    try:
        resolved = vision_model.resolve_sorting_model()
        vision_model.assert_sorting_classes(resolved)
    except vision_model.SortingModelError as exc:
        return JSONResponse(status_code=503, content={
            "error": "MODEL_ERROR",
            "message": str(exc)[:200],
            "configured_path": settings.icam_model_path or None,
        })
    info = infer.model_info(resolved)
    return {
        **info,
        "configured_path": settings.icam_model_path or None,
        "conf_threshold": settings.icam_conf,
    }


@app.get("/camera/preview")
def camera_preview():
    """Continuous annotated MJPEG preview (bounding box + English class +
    confidence overlays).

    Serves the latest annotated frame produced by the single inference
    worker — NEVER runs YOLO inside the request. READ-ONLY: preview never
    publishes arm/command and never writes detections.
    """
    return StreamingResponse(
        _mjpeg(runtime.state.get_annotated_jpeg, 0.2),
        media_type="multipart/x-mixed-replace; boundary=frame",
        headers={"Cache-Control": "no-cache, no-store, must-revalidate"},
    )


@app.get("/camera/preview/frame")
def camera_preview_frame():
    """Latest annotated frame as a single JPEG (pollable)."""
    jpeg = runtime.state.get_annotated_jpeg()
    if jpeg is None:
        return Response(status_code=503)
    return Response(
        content=jpeg,
        media_type="image/jpeg",
        headers={"Cache-Control": "no-cache, no-store, must-revalidate"},
    )


@app.get("/preview/latest")
def preview_latest():
    """Latest preview inference snapshot (legacy shape, existing clients)."""
    snap = runtime.state.snapshot_legacy_preview()
    return {"ok": snap["at"] is not None, **snap}


@app.get("/detections/latest")
def detections_latest():
    """Latest continuous-inference result: timestamp, frame size, detections.

    Each detection carries class_id, raw + English class names, 0-100
    confidence, pixel bbox/center, and clamped 0-1 normalized geometry.
    MODEL_ERROR is reported honestly when best.pt cannot be used.
    """
    model = runtime.state.snapshot_model()
    if not model["loaded"]:
        return JSONResponse(status_code=503, content={
            "ok": False,
            "error": "MODEL_ERROR",
            "message": (model["error"] or "sorting model unavailable")[:200],
            "detections": [],
        })
    snap = runtime.state.snapshot_latest()
    return {"ok": True, "camera": settings.icam_camera, **snap}


@app.get("/stats/summary")
def stats_summary():
    """Runtime counters: per-class counts, totals, fps, latency, uptime."""
    return runtime.state.snapshot_summary()


@app.get("/stats/confidence")
def stats_confidence(limit: int = 200):
    """Recent per-detection confidence samples for dashboard graphing."""
    return runtime.state.snapshot_confidence(limit=limit)


@app.get("/stats/timeline")
def stats_timeline(minutes: int = 60, bucket_seconds: int = 60):
    """Detections per time bucket over the trailing window."""
    return runtime.state.snapshot_timeline(minutes=minutes, bucket_seconds=bucket_seconds)


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
    """Run inference on one uploaded frame and return the QC verdict inline.

    When competition_mode is enabled this also runs the sort pipeline gates
    (signed POST to /api/camera/detection). arm/command publishing is legacy
    and disabled unless SORTING_MQTT_ENABLED=true — without it the pipeline
    reports mqtt_disabled instead of commanding.
    """
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
        if settings.competition_mode:
            result = sort_pipeline.run_sort_pipeline(tmp_path, conf, resolved_model)
        else:
            result = infer.infer_frame(tmp_path, resolved_model, conf)
    finally:
        Path(tmp_path).unlink(missing_ok=True)

    result["camera"] = camera
    result["conveyor"] = conveyor
    result["product_id"] = product_id
    return result
