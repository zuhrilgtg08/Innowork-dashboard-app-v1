"""Continuous YOLO runtime: shared state + single inference worker.

Production architecture (no MQTT anywhere in this module):

    Camera Capture Worker (stream.CameraSource, ONE connection)
            |
    latest raw frame (+ sequence number)
            |
    Inference Worker (this module, ONE thread, ONE shared YOLO handle)
            |
    latest annotated frame / latest detection result / statistics

All dashboard viewers reuse the same cached frames/results — inference NEVER
runs inside an HTTP request handler. Shared mutable state is guarded by a
single lock; readers always receive copies.

Memory is bounded: only the latest raw/annotated frames are kept in RAM,
plus fixed-length metadata buffers (deque maxlen).
"""
import threading
import time
from collections import deque
from datetime import datetime, timezone

import cv2
import numpy as np

import vision_model
from config import settings

# --- bounded buffers (§20) -------------------------------------------------
CONFIDENCE_HISTORY_MAX = 300   # recent per-detection confidence samples
DETECTION_EVENTS_MAX = 500     # recent per-detection events (timeline source)
LATENCY_SAMPLES_MAX = 200      # recent inference latencies (ms)

# Counts only objects that newly appear (class + quantized center not seen on
# the previous processed frame), so a stationary object is counted once.
COUNT_SPATIAL_TOLERANCE = 0.05

# How often the shared raw-JPEG cache may be refreshed for the raw MJPEG
# preview (~15 fps shared across all viewers).
RAW_JPEG_MAX_AGE_S = 0.066


def _utcnow_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def _quantize(value: float, tol: float = COUNT_SPATIAL_TOLERANCE) -> float:
    return round(float(value) / tol) * tol


class RuntimeState:
    """Thread-safe holder of the latest runtime snapshot."""

    def __init__(self):
        self._lock = threading.Lock()
        self._started_at = time.monotonic()
        # Camera (mirrored from the single capture connection).
        self._camera = {
            "connected": False, "mode": "offline", "fps": 0.0,
            "frame_width": 0, "frame_height": 0, "source": "",
        }
        # Model.
        self._model = {"loaded": False, "classes": {}, "path": None, "error": None}
        # Latest inference result.
        self._result = {
            "timestamp": None, "frame": {"width": 0, "height": 0},
            "detections": [], "primary": None, "inference_ms": None,
        }
        # Cached JPEGs shared by every MJPEG viewer.
        self._raw_jpeg: bytes | None = None
        self._raw_jpeg_at: float = 0.0
        self._annotated_jpeg: bytes | None = None
        self._annotated_at: float | None = None
        # Performance.
        self._inference_fps = 0.0
        self._last_inference_at: float | None = None
        self._last_inference_ms: float | None = None
        self._latency = deque(maxlen=LATENCY_SAMPLES_MAX)
        self._processed_frames = 0
        # Statistics (cumulative, edge-triggered — see module docstring).
        self._counts = {"GREEN": 0, "YELLOW": 0, "RED": 0}
        self._total = 0
        self._last_signatures: set = set()
        self._confidence_history: deque = deque(maxlen=CONFIDENCE_HISTORY_MAX)
        self._detection_events: deque = deque(maxlen=DETECTION_EVENTS_MAX)

    # -- writers (inference worker only) ------------------------------------
    def update_camera(self, connected: bool, mode: str, fps: float,
                      width: int, height: int, source: str) -> None:
        with self._lock:
            self._camera.update({
                "connected": bool(connected), "mode": str(mode),
                "fps": round(float(fps), 1),
                "frame_width": int(width), "frame_height": int(height),
                "source": str(source or ""),
            })

    def update_model(self, loaded: bool, classes: dict, path: str | None,
                     error: str | None = None) -> None:
        with self._lock:
            self._model = {
                "loaded": bool(loaded),
                "classes": {str(k): v for k, v in dict(classes or {}).items()},
                "path": path,
                "error": error,
            }

    def publish(self, frame_w: int, frame_h: int, raw_jpeg: bytes,
                annotated_jpeg: bytes, detections: list,
                latency_ms: float) -> None:
        """Publish one processed frame: result + JPEG caches + statistics."""
        now_iso = _utcnow_iso()
        now_mono = time.monotonic()
        with self._lock:
            self._result = {
                "timestamp": now_iso,
                "frame": {"width": int(frame_w), "height": int(frame_h)},
                "detections": [dict(d) for d in detections],
                "primary": dict(vision_model.primary_detection(detections))
                if detections else None,
                "inference_ms": round(float(latency_ms), 1),
            }
            self._raw_jpeg = raw_jpeg
            self._raw_jpeg_at = now_mono
            self._annotated_jpeg = annotated_jpeg
            self._annotated_at = now_mono

            # Performance tracking.
            if self._last_inference_at is not None:
                dt = now_mono - self._last_inference_at
                if dt > 0:
                    inst = 1.0 / dt
                    self._inference_fps = (
                        0.8 * self._inference_fps + 0.2 * inst
                        if self._inference_fps else inst
                    )
            self._last_inference_at = now_mono
            self._last_inference_ms = round(float(latency_ms), 1)
            self._latency.append(round(float(latency_ms), 1))
            self._processed_frames += 1

            # Statistics: a detection event is recorded only when its object
            # signature (class + quantized center) is newly detected — a
            # stationary object is counted once, keeping Total Detections,
            # the timeline, and the confidence history mutually consistent.
            signatures = set()
            for det in detections:
                n = det.get("normalized", {})
                sig = (
                    det.get("class_id"),
                    _quantize(n.get("center_x", 0.0)),
                    _quantize(n.get("center_y", 0.0)),
                )
                signatures.add(sig)
                if sig in self._last_signatures:
                    continue
                name = det.get("class_name")
                if name in self._counts:
                    self._counts[name] += 1
                    self._total += 1
                self._confidence_history.append({
                    "at": now_iso,
                    "class": det.get("class_name"),
                    "confidence": det.get("confidence"),
                })
                self._detection_events.append({
                    "at": now_mono,
                    "class": det.get("class_name"),
                    "confidence": det.get("confidence"),
                })
            self._last_signatures = signatures

    def refresh_raw_jpeg(self, jpeg: bytes) -> None:
        """Refresh the shared raw-JPEG cache without touching statistics."""
        with self._lock:
            self._raw_jpeg = jpeg
            self._raw_jpeg_at = time.monotonic()

    # -- readers (request handlers; always copies) --------------------------
    def get_raw_jpeg(self, max_age_s: float = RAW_JPEG_MAX_AGE_S) -> bytes | None:
        """Shared raw JPEG, re-encoded (once per TTL) when stale.

        Every MJPEG viewer reads through this cache, so N browsers cost one
        encode per 66 ms — never one YOLO run and never one capture each.
        """
        with self._lock:
            if self._raw_jpeg is not None and \
                    (time.monotonic() - self._raw_jpeg_at) <= max_age_s:
                return self._raw_jpeg
        # Re-encode outside the lock (slow path, shared by TTL).
        from stream import camera_source  # lazy: stream never imports runtime

        frame = camera_source.latest_frame()
        if frame is None:
            with self._lock:
                return self._raw_jpeg
        ok, buf = cv2.imencode(".jpg", frame, [cv2.IMWRITE_JPEG_QUALITY, 75])
        if not ok:
            with self._lock:
                return self._raw_jpeg
        jpeg = buf.tobytes()
        self.refresh_raw_jpeg(jpeg)
        return jpeg

    def get_annotated_jpeg(self) -> bytes | None:
        with self._lock:
            return self._annotated_jpeg

    def snapshot_health(self) -> dict:
        with self._lock:
            lat = list(self._latency)
            return {
                "status": "ok",
                "vision_sorting": settings.competition_mode,
                "model_loaded": self._model["loaded"],
                "model_error": self._model["error"],
                "camera_connected": self._camera["connected"],
                "camera_mode": self._camera["mode"],
                "camera_fps": self._camera["fps"],
                "inference_fps": round(self._inference_fps, 1),
                "last_inference_ms": self._last_inference_ms,
                "average_inference_ms": round(sum(lat) / len(lat), 1) if lat else None,
                "uptime_seconds": round(time.monotonic() - self._started_at, 1),
            }

    def snapshot_model(self) -> dict:
        with self._lock:
            return dict(self._model)

    def snapshot_camera(self) -> dict:
        with self._lock:
            return dict(self._camera)

    def snapshot_latest(self) -> dict:
        with self._lock:
            result = {
                "timestamp": self._result["timestamp"],
                "frame": dict(self._result["frame"]),
                "detections": [dict(d) for d in self._result["detections"]],
            }
            return result

    def snapshot_legacy_preview(self) -> dict:
        """Backward-compatible GET /preview/latest shape for existing clients."""
        with self._lock:
            primary = self._result["primary"]
            frame = self._result["frame"]
            dets = []
            for det in self._result["detections"]:
                bbox = det["bbox"]
                dets.append({
                    "label": det["class_name_raw"],
                    "normalized_color": {
                        "GREEN": "green", "YELLOW": "yellow", "RED": "red",
                    }.get(det["class_name"]),
                    "confidence": det["confidence"],
                    "bbox": [bbox["x1"], bbox["y1"], bbox["x2"], bbox["y2"]],
                    "center_x": det["center"]["x"],
                    "center_y": det["center"]["y"],
                    "in_pick_zone": self._in_pick_zone(det),
                })
            top = dets[0] if dets else None
            at = self._result["timestamp"]
            return {
                "at": datetime.fromisoformat(at).strftime("%H:%M:%S") if at else None,
                "detected_class": top["label"] if top else None,
                "normalized_color": top["normalized_color"] if top else None,
                "confidence": top["confidence"] if top else 0.0,
                "bbox": top["bbox"] if top else None,
                "center_x": top["center_x"] if top else None,
                "center_y": top["center_y"] if top else None,
                "in_pick_zone": top["in_pick_zone"] if top else None,
                "latency_ms": self._result["inference_ms"],
                "frame_w": frame["width"],
                "frame_h": frame["height"],
                "detections": dets,
            }

    def _in_pick_zone(self, det: dict) -> bool:
        n = det.get("normalized", {})
        return (
            settings.pick_zone_x_min <= float(n.get("center_x", -1)) <= settings.pick_zone_x_max
            and settings.pick_zone_y_min <= float(n.get("center_y", -1)) <= settings.pick_zone_y_max
        )

    def snapshot_summary(self) -> dict:
        with self._lock:
            lat = list(self._latency)
            return {
                "green": self._counts["GREEN"],
                "yellow": self._counts["YELLOW"],
                "red": self._counts["RED"],
                "total": self._total,
                "camera_fps": self._camera["fps"],
                "inference_fps": round(self._inference_fps, 1),
                "last_latency_ms": self._last_inference_ms,
                "average_latency_ms": round(sum(lat) / len(lat), 1) if lat else None,
                "model_loaded": self._model["loaded"],
                "camera_connected": self._camera["connected"],
                "camera_mode": self._camera["mode"],
                "uptime_seconds": round(time.monotonic() - self._started_at, 1),
            }

    def snapshot_confidence(self, limit: int = 200) -> dict:
        with self._lock:
            samples = list(self._confidence_history)[-max(1, min(limit, CONFIDENCE_HISTORY_MAX)):]
            return {"samples": [dict(s) for s in samples], "count": len(samples)}

    def snapshot_timeline(self, minutes: int = 60, bucket_seconds: int = 60) -> dict:
        """Detections per time bucket over the trailing window (real events)."""
        minutes = max(5, min(int(minutes), 180))
        bucket_seconds = max(30, min(int(bucket_seconds), 600))
        now = time.monotonic()
        start = now - minutes * 60
        n_buckets = max(1, int(minutes * 60 / bucket_seconds))
        series = {
            "GREEN": [0] * n_buckets,
            "YELLOW": [0] * n_buckets,
            "RED": [0] * n_buckets,
        }
        labels = []
        for i in range(n_buckets):
            labels.append(
                datetime.fromtimestamp(
                    datetime.now().timestamp() - (n_buckets - 1 - i) * bucket_seconds
                ).strftime("%H:%M")
            )
        with self._lock:
            events = list(self._detection_events)
        for ev in events:
            at = ev.get("at", 0)
            if at < start:
                continue
            idx = min(n_buckets - 1, int((at - start) // bucket_seconds))
            cls = ev.get("class")
            if cls in series:
                series[cls][idx] += 1
        return {
            "bucket_seconds": bucket_seconds,
            "labels": labels,
            "series": series,
        }


# Module-level singleton: one shared state for every thread + handler.
state = RuntimeState()


def draw_annotated(frame_bgr: np.ndarray, detections: list) -> np.ndarray:
    """Draw English overlay: box + GREEN/YELLOW/RED + confidence %."""
    annotated = frame_bgr.copy()
    for det in detections:
        english = det.get("class_name", "?")
        bgr = vision_model.DRAW_COLORS_BGR.get(english, vision_model.DRAW_DEFAULT_BGR)
        box = det.get("bbox", {})
        x1, y1, x2, y2 = (box.get("x1", 0), box.get("y1", 0),
                           box.get("x2", 0), box.get("y2", 0))
        cv2.rectangle(annotated, (x1, y1), (x2, y2), bgr, 2)
        text = f"{english} {det.get('confidence', 0)}%"
        (tw, th), _ = cv2.getTextSize(text, cv2.FONT_HERSHEY_SIMPLEX, 0.6, 2)
        y0 = max(th + 8, y1)
        cv2.rectangle(annotated, (x1, y0 - th - 8), (x1 + tw + 6, y0), bgr, -1)
        cv2.putText(annotated, text, (x1 + 3, y0 - 6),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.6, (255, 255, 255), 2)
    return annotated


class InferenceWorker(threading.Thread):
    """ONE continuous inference thread on the shared best.pt handle.

    Reads each new frame exactly once (sequence guard), runs YOLO, and
    publishes the annotated frame + detection result + statistics. Never
    POSTs to Laravel and never touches MQTT — monitoring is pull-based.
    """

    def __init__(self, runtime_state: RuntimeState | None = None):
        super().__init__(daemon=True, name="yolo-inference")
        self._state = runtime_state or state
        self._running = False
        self._model = None
        self._conf = max(0.05, min(float(settings.icam_conf), 0.95))

    def _ensure_model(self) -> bool:
        """Load + validate best.pt once; MODEL_ERROR state until it works."""
        if self._model is not None:
            return True
        try:
            path = vision_model.resolve_sorting_model()
            classes = vision_model.assert_sorting_classes(path)
        except vision_model.SortingModelError as exc:
            self._state.update_model(False, {}, settings.icam_model_path or None, str(exc))
            return False
        import infer  # lazy: torch only when the worker actually runs

        self._model = infer._load(path)  # noqa: SLF001 — same service package
        self._state.update_model(True, classes, path, None)
        print(f"[runtime] model ready: {path} {classes}", flush=True)
        return True

    def stop(self) -> None:
        self._running = False

    def _mirror_camera(self) -> None:
        """Mirror capture telemetry into shared state.

        Runs on EVERY loop pass — including model-error idle and frames
        skipped as duplicates — so camera health (connected/mode/fps) never
        depends on YOLO succeeding. Camera Connected = true with Model
        Loaded = false is a normal, reportable combination.
        """
        from stream import camera_source  # lazy: stream never imports runtime

        status = camera_source.status()
        w, h = camera_source.frame_dims()
        if w <= 0 or h <= 0:
            prev = self._state.snapshot_camera()
            w, h = prev["frame_width"], prev["frame_height"]
        self._state.update_camera(
            status.get("connected", False), status.get("mode", "offline"),
            status.get("fps", 0.0), w, h, status.get("source", ""),
        )

    def run(self) -> None:
        from stream import camera_source  # lazy: stream never imports runtime

        self._running = True
        last_seq = -1
        retry_at = 0.0
        while self._running:
            # Camera telemetry first: independent of model/inference health.
            self._mirror_camera()
            # A /reload-model request drops the shared handle so the next
            # _ensure_model() reloads + revalidates current disk weights.
            if _reload_requested.is_set():
                _reload_requested.clear()
                self._model = None
                last_seq = -1
                print("[runtime] model handle invalidated, reloading", flush=True)
            now = time.monotonic()
            if self._model is None:
                # MODEL_ERROR until best.pt loads + validates; retry slowly.
                if now >= retry_at:
                    if self._ensure_model():
                        last_seq = -1
                    else:
                        retry_at = now + 30.0
                        print("[runtime] MODEL_ERROR: inference idle (retry in 30s)", flush=True)
                time.sleep(1.0)
                continue

            seq = camera_source.frame_seq()
            if seq == last_seq or seq <= 0:
                time.sleep(0.05)
                continue
            frame = camera_source.latest_frame()
            if frame is None:
                time.sleep(0.05)
                continue
            last_seq = seq

            try:
                detections, latency_ms = self._infer(frame)
            except Exception as exc:  # noqa: BLE001
                print(f"[runtime] inference failed: {exc}", flush=True)
                continue

            try:
                h, w = frame.shape[:2]
                annotated = draw_annotated(frame, detections)
                ok_a, buf_a = cv2.imencode(".jpg", annotated, [cv2.IMWRITE_JPEG_QUALITY, 80])
                ok_r, buf_r = cv2.imencode(".jpg", frame, [cv2.IMWRITE_JPEG_QUALITY, 75])
                if not (ok_a and ok_r):
                    continue
                # Camera telemetry was already mirrored at the top of this
                # loop pass; publish result, JPEGs, and statistics together.
                self._state.publish(w, h, buf_r.tobytes(), buf_a.tobytes(),
                                    detections, latency_ms)
            except Exception as exc:  # noqa: BLE001
                print(f"[runtime] publish failed: {exc}", flush=True)

    def _infer(self, frame_bgr: np.ndarray) -> tuple[list, float]:
        """Run the shared YOLO handle on one BGR frame (ultralytics expects
        BGR numpy and flips to RGB internally). Returns (detections, ms)."""
        t0 = time.perf_counter()
        # Guard against a mid-run /reload-model swap racing this predict.
        model = self._model
        results = model.predict(source=frame_bgr, conf=self._conf,
                                device="cpu", verbose=False)
        latency_ms = (time.perf_counter() - t0) * 1000.0
        h, w = frame_bgr.shape[:2]
        names = model.names
        detections = []
        for r in results:
            for b in r.boxes:
                cls_id = int(b.cls[0])
                raw = str(names.get(cls_id, str(cls_id)))
                # Authoritative model covers exactly the three semantic
                # colors (either raw language); anything else is skipped.
                if vision_model.semantic_name(raw) is None:
                    continue
                conf_pct = round(float(b.conf[0]) * 100, 1)
                x1, y1, x2, y2 = (float(v) for v in b.xyxy[0].tolist())
                detections.append(vision_model.build_detection(
                    cls_id, raw, conf_pct, x1, y1, x2, y2, w, h,
                ))
        detections.sort(key=lambda d: d["confidence"], reverse=True)
        return detections, round(latency_ms, 1)


_worker: InferenceWorker | None = None

# Set by POST /reload-model: the inference worker drops its YOLO handle on
# the next loop pass and reloads + revalidates from disk, so clearing only
# infer.py's cache can never leave the worker on stale weights.
_reload_requested = threading.Event()


def request_reload() -> None:
    """Ask the inference worker to drop and reload its model handle."""
    _reload_requested.set()


def start() -> InferenceWorker:
    """Start the single inference worker (idempotent)."""
    global _worker
    if _worker is None or not _worker.is_alive():
        _worker = InferenceWorker()
        _worker.start()
    return _worker


def stop() -> None:
    """Stop the inference worker (lifespan shutdown)."""
    global _worker
    if _worker is not None:
        _worker.stop()
        _worker.join(timeout=5)
        _worker = None
