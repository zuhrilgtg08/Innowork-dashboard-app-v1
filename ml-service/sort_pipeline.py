"""Competition sorting pipeline: YOLO inference → signed POST → MQTT publish.

Confidence convention (canonical, enforced across the stack):
  - Database + UI + MQTT payloads store 0–100 PERCENT (e.g. 94.0, never 0.94).
  - Only internal threshold comparisons use 0–1 (sort_min_confidence,
    Setting.confidence_threshold, icam_conf), converting at the boundary.

Model authority lives in vision_model (best.pt + exact class map); this
module reuses it and adds the legacy actuation gates.

MQTT STATUS: arm/command publishing is legacy and DISABLED by default
(SORTING_MQTT_ENABLED=false). The continuous runtime (runtime.py) never
publishes regardless of this flag. This per-request path keeps the publish
code for a future re-enablement, but paho is imported lazily so the service
runs without the broker — or without paho-mqtt installed — when disabled.

Safety properties (do not weaken while MQTT is enabled):
  - Vision Sorting ALWAYS uses the configured best.pt (ICAM_MODEL_PATH),
    resolved against Laravel storage. If it is missing/invalid, or its
    classes are not HIJAU/KUNING/MERAH, the inference is rejected and
    NOTHING is published. There is deliberately NO silent yolov8n.pt
    fallback on this path (legacy non-sorting paths in infer.py keep theirs).
  - arm/command is published ONLY when ALL gates pass: color class found,
    confidence gate, object center inside the configured pick zone, object
    not already commanded (dedupe latch + cooldown), bowl not full, arm
    not busy.
  - A failed MQTT publish is reported as sort_triggered=False with reason
    mqtt_publish_failed — never as success.
"""
import base64
import hashlib
import hmac
import json
import threading
import time
import uuid
from pathlib import Path

import httpx

import infer
import vision_model
from config import settings
from vision_model import SortingModelError  # noqa: F401 — re-exported for callers


# YOLO class name → canonical color (green/yellow/red). Both raw languages
# are accepted — the authoritative model covers the three semantic colors
# (see vision_model.RAW_TO_SEMANTIC); IDs are never assumed here.
COLOR_ALIAS = {
    "HIJAU": "green",
    "GREEN": "green",
    "KUNING": "yellow",
    "YELLOW": "yellow",
    "MERAH": "red",
    "RED": "red",
}

# Canonical color → destination bowl
DESTINATION_MAP = {
    "green": "BOWL_GREEN",
    "yellow": "BOWL_YELLOW",
    "red": "BOWL_RED",
}

# Canonical reference class map of the production best.pt (see vision_model:
# semantic coverage {GREEN, YELLOW, RED} is enforced, never fixed IDs).
EXPECTED_CLASS_MAP = vision_model.EXPECTED_CLASS_MAP

# Anti-duplicate state: signature of the last COMMANDED object plus the time
# the last command was published. A stationary object keeps producing the
# same signature, so it is commanded exactly once; the latch resets when a
# frame contains no color target (object left the view).
_state_lock = threading.Lock()
_last_signature: tuple | None = None
_last_command_at: float = 0.0


class SortingModelError(Exception):
    """The authoritative Vision Sorting model cannot be used."""


def resolve_sorting_model(explicit: str | None = None) -> str:
    """Resolve the authoritative best.pt for Vision Sorting.

    Delegates to vision_model (single source of truth). Raises
    SortingModelError when nothing usable is found.
    """
    return vision_model.resolve_sorting_model(explicit)


def assert_sorting_classes(model_path: str) -> dict:
    """Load the weights and verify the EXACT Vision Sorting class map.

    Delegates to vision_model (single source of truth).
    """
    return vision_model.assert_sorting_classes(model_path)


def in_pick_zone(nx: float, ny: float) -> bool:
    """Normalized center (0-1) inside the configured operational pick zone."""
    return (
        settings.pick_zone_x_min <= nx <= settings.pick_zone_x_max
        and settings.pick_zone_y_min <= ny <= settings.pick_zone_y_max
    )


def _post_signed(url: str, payload: dict) -> dict:
    """POST a JSON payload with HMAC-SHA256 signature over the raw body."""
    body = json.dumps(payload, separators=(",", ":")).encode()
    signature = hmac.new(
        settings.ml_callback_secret.encode(), body, hashlib.sha256
    ).hexdigest()

    resp = httpx.post(
        url,
        content=body,
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-ML-Signature": signature,
        },
        timeout=15,
    )
    resp.raise_for_status()
    return resp.json()


def _publish_arm_command(payload: dict) -> bool:
    """Publish one command to arm/command (QoS 1, blocking single-shot).

    Legacy path: only called when SORTING_MQTT_ENABLED=true. paho is
    imported lazily so the service imports and runs without paho-mqtt
    installed (and without a broker) when publishing is disabled.

    Uses paho.mqtt.publish.single so the call only returns success after the
    packet actually reached the broker — a refused/unreachable broker raises
    and we report failure instead of a phantom success. Authenticates with
    MQTT_USERNAME/MQTT_PASSWORD (+TLS) when configured.
    """
    from paho.mqtt import publish as mqtt_single  # lazy: legacy path only

    try:
        auth = None
        if (settings.mqtt_username or "").strip():
            auth = {
                "username": settings.mqtt_username,
                "password": settings.mqtt_password or "",
            }
        mqtt_single.single(
            "arm/command",
            json.dumps(payload, separators=(",", ":")),
            qos=1,
            hostname=settings.mqtt_broker,
            port=settings.mqtt_port,
            auth=auth,
            tls={} if settings.mqtt_use_tls else None,
            keepalive=60,
        )
        return True
    except Exception as exc:  # noqa: BLE001
        print(f"[sort_pipeline] MQTT publish failed: {exc}", flush=True)
        return False


def _quantize(v: float) -> float:
    tol = settings.sort_spatial_tolerance or 0.05
    return round(v / tol) * tol


def run_sort_pipeline(
    image_path: str,
    conf: float,
    model_path: str | None = None,
) -> dict:
    """
    Run the competition sort pipeline on a single frame.

    Returns a dict with the YOLO inference result plus sort_triggered and,
    when no command is issued, a machine-readable reason:
      model_error | no_color_class | low_confidence | outside_pick_zone |
      duplicate | cooldown | ingest_failed | preflight_failed |
      bowl_full | arm_busy | mqtt_disabled | mqtt_publish_failed
    """
    global _last_signature, _last_command_at

    # 0. Authoritative model gate — no silent fallback, no command on failure.
    try:
        resolved = resolve_sorting_model(model_path)
        assert_sorting_classes(resolved)
    except SortingModelError as exc:
        print(f"[sort_pipeline] {exc}", flush=True)
        return {
            "status": "error",
            "confidence": 0.0,
            "qr_value": None,
            "boxes": [],
            "detections": [],
            "sort_triggered": False,
            "reason": "model_error",
            "error": str(exc),
        }

    # 1. Run YOLO inference with the authoritative model.
    result = infer.infer_frame(image_path, resolved, conf)

    # 2. Find the top detection whose label maps to a competition color.
    target = None
    for det in result.get("detections", []):
        label = det.get("label", "")
        if label in COLOR_ALIAS:
            target = det
            break

    if target is None:
        # No color target: the previous object (if any) has left the view,
        # so release the anti-duplicate latch for the next appearance.
        with _state_lock:
            _last_signature = None
        return {
            **result,
            "sort_triggered": False,
            "reason": "no_color_class",
        }

    color = COLOR_ALIAS[target["label"]]
    destination = DESTINATION_MAP[color]
    confidence = target["confidence"] / 100.0  # YOLO returns 0-100

    if confidence < settings.sort_min_confidence:
        return {
            **result,
            "sort_triggered": False,
            "reason": "low_confidence",
            "confidence": round(confidence * 100, 1),
        }

    # 3. Operational pick zone: normalized center inside configured bounds.
    x1, y1, x2, y2 = (float(v) for v in target["bbox"])
    try:
        from PIL import Image as _Image

        with _Image.open(image_path) as _img:
            frame_w, frame_h = _img.size
    except Exception:  # noqa: BLE001
        frame_w, frame_h = (0, 0)
    cx, cy = (x1 + x2) / 2.0, (y1 + y2) / 2.0
    nx, ny = (cx / frame_w) if frame_w else 0.0, (cy / frame_h) if frame_h else 0.0
    zone_ok = in_pick_zone(nx, ny)

    event_uuid = str(uuid.uuid4())

    # 4. Read frame for signed POST (monitoring visibility in all cases).
    with open(image_path, "rb") as f:
        frame_b64 = base64.b64encode(f.read()).decode()

    detection_payload = {
        "status": "passed",
        "confidence": target["confidence"],
        "qr_value": result.get("qr_value"),
        "boxes": result.get("boxes", []),
        "detections": result.get("detections", []),
        "camera": settings.icam_camera,
        "conveyor": settings.icam_conveyor,
        "frame_jpeg_b64": frame_b64,
        # Competition fields
        "color": color,
        "center_x": int(cx),
        "center_y": int(cy),
        "in_pick_zone": zone_ok,
        "competition_event_id": event_uuid,
    }

    def _ingest() -> str | None:
        laravel_ingest = f"{settings.laravel_url.rstrip('/')}/api/camera/detection"
        try:
            ingest_resp = _post_signed(laravel_ingest, detection_payload)
        except Exception as exc:  # noqa: BLE001
            print(f"[sort_pipeline] Signed POST failed: {exc}", flush=True)
            return None
        detection_ids = ingest_resp.get("detection_ids", [])
        return detection_ids[0] if detection_ids else None

    if not zone_ok:
        # Visible in monitoring, but NEVER commands the arm from outside.
        detection_id = _ingest()
        if detection_id is None:
            return {**result, "sort_triggered": False, "reason": "ingest_failed"}
        return {
            **result,
            "sort_triggered": False,
            "reason": "outside_pick_zone",
            "in_pick_zone": False,
            "event_uuid": event_uuid,
            "detection_id": detection_id,
            "color": color,
        }

    # 5. Anti-duplicate: same object (color + quantized center) already
    # commanded → suppress; any command too recent → cooldown.
    signature = (color, _quantize(nx), _quantize(ny))
    now = time.monotonic()
    with _state_lock:
        latched = _last_signature is not None and _last_signature == signature
        cooling = (now - _last_command_at) * 1000.0 < settings.sort_cooldown_ms
    if latched:
        return {
            **result,
            "sort_triggered": False,
            "reason": "duplicate",
            "in_pick_zone": True,
            "color": color,
        }
    if cooling:
        return {
            **result,
            "sort_triggered": False,
            "reason": "cooldown",
            "in_pick_zone": True,
            "color": color,
        }

    # 6. Persist the detection first (monitoring + detection_id for command).
    detection_id = _ingest()
    if detection_id is None:
        return {**result, "sort_triggered": False, "reason": "ingest_failed"}

    # 7. Preflight: bowl capacity + arm availability (fail closed).
    laravel_preflight = f"{settings.laravel_url.rstrip('/')}/api/sorting/preflight"
    try:
        pre = _post_signed(laravel_preflight, {"color": color})
    except Exception as exc:  # noqa: BLE001
        print(f"[sort_pipeline] Preflight failed: {exc}", flush=True)
        return {
            **result,
            "sort_triggered": False,
            "reason": "preflight_failed",
            "error": str(exc),
            "event_uuid": event_uuid,
            "detection_id": detection_id,
            "color": color,
        }
    if pre.get("bowl_full"):
        return {
            **result,
            "sort_triggered": False,
            "reason": "bowl_full",
            "event_uuid": event_uuid,
            "detection_id": detection_id,
            "color": color,
            "count": pre.get("count"),
            "max": pre.get("max"),
        }
    if pre.get("arm_busy"):
        return {
            **result,
            "sort_triggered": False,
            "reason": "arm_busy",
            "event_uuid": event_uuid,
            "detection_id": detection_id,
            "color": color,
            "arm_state": pre.get("arm_state"),
        }

    # 8. Publish arm/command MQTT message (blocking; failure ≠ success).
    # Legacy path, DISABLED unless SORTING_MQTT_ENABLED=true: production no
    # longer uses MQTT (monitoring is pull-based). The per-request gates above
    # still run, but without an enabled broker nothing is commanded.
    # Confidence convention: database/UI store 0-100 percent everywhere, so
    # the command carries 0-100 too (MqttListen persists it verbatim into
    # sorting_events.confidence). Only threshold comparisons use 0-1.
    if not settings.sorting_mqtt_enabled:
        return {
            **result,
            "sort_triggered": False,
            "reason": "mqtt_disabled",
            "event_uuid": event_uuid,
            "detection_id": detection_id,
            "color": color,
        }

    command_payload = {
        "action": "sort",
        "event_uuid": event_uuid,
        "detection_id": detection_id,
        "color": color,
        "destination": destination,
        "confidence": round(confidence * 100, 1),
        "bbox": target["bbox"],
        "source": "ml_service",
    }

    if not _publish_arm_command(command_payload):
        return {
            **result,
            "sort_triggered": False,
            "reason": "mqtt_publish_failed",
            "event_uuid": event_uuid,
            "detection_id": detection_id,
            "color": color,
        }

    # 9. Latch the commanded object only after a confirmed publish.
    with _state_lock:
        _last_signature = signature
        _last_command_at = time.monotonic()

    return {
        **result,
        "sort_triggered": True,
        "event_uuid": event_uuid,
        "detection_id": detection_id,
        "color": color,
        "destination": destination,
        "command": command_payload,
    }
