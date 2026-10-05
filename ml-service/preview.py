"""Read-only model preview: annotated MJPEG stream + latest-result snapshot.

SAFETY CONTRACT (do not break):
  - This module runs YOLO inference ONLY (infer.infer_frame).
  - It NEVER publishes MQTT messages and NEVER POSTs to Laravel.
  - The ONLY code path allowed to publish arm/command is
    sort_pipeline.run_sort_pipeline (used by POST /infer in sorting mode).

The annotated stream is a visualization/evaluation aid: bounding box +
class + confidence overlays on the live ICAM-300 feed. Preview detections
never touch bowl counters, sorting_events, or the physical arm.
"""
import tempfile
import threading
import time
from pathlib import Path

import cv2

import infer
from config import settings
from stream import camera_source

# YOLO class name -> canonical color (green/yellow/red), mirrors sort_pipeline.
COLOR_ALIAS = {
    "HIJAU": "green",
    "KUNING": "yellow",
    "MERAH": "red",
}

# BGR draw colors per canonical color; anything else gets neutral blue.
DRAW_COLORS = {
    "green": (34, 197, 94),
    "yellow": (234, 179, 8),
    "red": (239, 68, 68),
}
DRAW_DEFAULT = (96, 165, 250)

# Pick-zone heuristic for the preview panel ONLY: bbox center inside the
# central 60% of the frame counts as VALID. Presentation aid, not backend logic.
PICK_MARGIN = 0.2

_latest_lock = threading.Lock()
_latest = {
    "at": None,
    "detected_class": None,
    "normalized_color": None,
    "confidence": 0.0,
    "bbox": None,
    "center_x": None,
    "center_y": None,
    "in_pick_zone": None,
    "latency_ms": None,
    "frame_w": 0,
    "frame_h": 0,
    "detections": [],
}


def resolve_model() -> str | None:
    """Same resolution as the stream infer loop: configured weights against
    Laravel storage, falling back to the base model inside infer_frame."""
    rel = settings.icam_model_path
    if not rel:
        return None
    candidate = Path(settings.laravel_storage_path) / rel
    return str(candidate) if candidate.exists() else rel


def in_pick_zone(cx: float, cy: float, w: int, h: int) -> bool:
    """Preview-only pick-zone check (central 60% of the frame)."""
    mx, my = PICK_MARGIN * w, PICK_MARGIN * h
    return (mx <= cx <= w - mx) and (my <= cy <= h - my)


def annotate_frame(frame, conf: float, model: str | None):
    """Run YOLO on one frame and draw label + confidence overlays.

    Returns (jpeg_bytes, snapshot_dict). Snapshot is also stored for
    GET /preview/latest. Pure function otherwise: no MQTT, no HTTP.
    """
    t0 = time.perf_counter()
    with tempfile.NamedTemporaryFile(suffix=".jpg", delete=False) as tmp:
        ok, buf = cv2.imencode(".jpg", frame)
        if not ok:
            raise ValueError("frame encode failed")
        tmp.write(buf.tobytes())
        tmp_path = tmp.name
    try:
        result = infer.infer_frame(tmp_path, model, conf)
    finally:
        Path(tmp_path).unlink(missing_ok=True)
    latency_ms = round((time.perf_counter() - t0) * 1000, 1)

    h, w = frame.shape[:2]
    annotated = frame.copy()
    dets = []
    for det in result.get("detections", []):
        label = det.get("label", "?")
        color = COLOR_ALIAS.get(label)
        bgr = DRAW_COLORS.get(color, DRAW_DEFAULT)
        x1, y1, x2, y2 = [int(v) for v in det.get("bbox", [0, 0, 0, 0])]
        cv2.rectangle(annotated, (x1, y1), (x2, y2), bgr, 2)
        text = f"{label} {det.get('confidence', 0)}%"
        (tw, th), _ = cv2.getTextSize(text, cv2.FONT_HERSHEY_SIMPLEX, 0.6, 2)
        cv2.rectangle(annotated, (x1, y1 - th - 8), (x1 + tw + 6, y1), bgr, -1)
        cv2.putText(annotated, text, (x1 + 3, y1 - 6),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.6, (255, 255, 255), 2)
        cx, cy = int((x1 + x2) / 2), int((y1 + y2) / 2)
        dets.append({
            "label": label,
            "normalized_color": color,
            "confidence": det.get("confidence", 0),
            "bbox": [x1, y1, x2, y2],
            "center_x": cx,
            "center_y": cy,
            "in_pick_zone": in_pick_zone(cx, cy, w, h),
        })

    top = dets[0] if dets else None
    snapshot = {
        "at": time.strftime("%H:%M:%S"),
        "detected_class": top["label"] if top else None,
        "normalized_color": top["normalized_color"] if top else None,
        "confidence": top["confidence"] if top else 0.0,
        "bbox": top["bbox"] if top else None,
        "center_x": top["center_x"] if top else None,
        "center_y": top["center_y"] if top else None,
        "in_pick_zone": top["in_pick_zone"] if top else None,
        "latency_ms": latency_ms,
        "frame_w": w,
        "frame_h": h,
        "detections": dets,
    }
    with _latest_lock:
        _latest.update(snapshot)

    ok, out = cv2.imencode(".jpg", annotated)
    if not ok:
        raise ValueError("annotated encode failed")
    return out.tobytes(), snapshot


def latest_snapshot() -> dict:
    """Last preview inference result (empty until the preview runs)."""
    with _latest_lock:
        return dict(_latest)


def preview_frames(min_interval: float = 0.4):
    """MJPEG generator: annotated frames at ~2.5 fps (CPU-friendly)."""
    boundary = b"--frame\r\n"
    model = resolve_model()
    conf = settings.icam_conf
    last_jpeg = None
    while True:
        frame = camera_source.latest_frame()
        if frame is None:
            if last_jpeg is not None:
                yield boundary + b"Content-Type: image/jpeg\r\n\r\n" + last_jpeg + b"\r\n"
            time.sleep(min_interval)
            continue
        try:
            jpeg, _ = annotate_frame(frame.copy(), conf, model)
            last_jpeg = jpeg
        except Exception as exc:  # noqa: BLE001
            print(f"[preview] annotate failed: {exc}", flush=True)
            time.sleep(min_interval)
            continue
        yield boundary + b"Content-Type: image/jpeg\r\n\r\n" + jpeg + b"\r\n"
        time.sleep(min_interval)
