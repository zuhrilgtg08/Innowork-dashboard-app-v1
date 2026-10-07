"""Competition sorting pipeline: YOLO inference → signed POST → MQTT publish.

Confidence convention (canonical, enforced across the stack):
  - Database + UI + MQTT payloads store 0–100 PERCENT (e.g. 94.0, never 0.94).
  - Only internal threshold comparisons use 0–1 (sort_min_confidence,
    Setting.confidence_threshold, icam_conf), converting at the boundary.

Safety properties (H-1, do not weaken):
  - Vision Sorting ALWAYS uses the configured best.pt (ICAM_MODEL_PATH),
    resolved against Laravel storage. If it is missing/invalid, or its
    classes are not HIJAU/KUNING/MERAH, the inference is rejected and
    NOTHING is published. There is deliberately NO silent yolov8n.pt
    fallback on this path.
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

import httpx
import paho.mqtt.client as mqtt

import infer
from config import settings


# YOLO class name → canonical color (green/yellow/red)
COLOR_ALIAS = {
    "HIJAU": "green",
    "KUNING": "yellow",
    "MERAH": "red",
}

# Canonical color → destination bowl
DESTINATION_MAP = {
    "green": "BOWL_GREEN",
    "yellow": "BOWL_YELLOW",
    "red": "BOWL_RED",
}

# Exact class map the authoritative Vision Sorting model must expose.
# ID AND order are enforced (a model with the right names on the wrong IDs
# would sort colors into the wrong bowls).
EXPECTED_CLASS_MAP = {0: "HIJAU", 1: "KUNING", 2: "MERAH"}

# Anti-duplicate state: signature of the last COMMANDED object plus the time
# the last command was published. A stationary object keeps producing the
# same signature, so it is commanded exactly once; the latch resets when a
# frame contains no color target (object left the view).
_state_lock = threading.Lock()
_pipeline_lock = threading.Lock()
_last_signature: tuple | None = None
_last_command_at: float = 0.0


class SortingModelError(Exception):
    """The authoritative Vision Sorting model cannot be used."""


def resolve_sorting_model(explicit: str | None = None) -> str:
    """Resolve the authoritative best.pt for Vision Sorting.

    An explicit request path wins (must exist); otherwise ICAM_MODEL_PATH
    resolved against Laravel storage is used. Raises SortingModelError when
    nothing usable is found — callers must reject the inference, never fall
    back to another model on this path.
    """
    rel = explicit or settings.icam_model_path
    if not rel:
        raise SortingModelError("no sorting model configured (ICAM_MODEL_PATH is empty)")
    try:
        return infer.resolve_model_path(rel)
    except infer.ModelError as exc:
        raise SortingModelError(str(exc)) from exc


def assert_sorting_classes(model_path: str) -> dict:
    """Load the weights and verify the EXACT Vision Sorting class map.

    model.names must equal {0: 'HIJAU', 1: 'KUNING', 2: 'MERAH'} — IDs and
    order, not just name presence. Anything else raises SortingModelError
    (wrong IDs would silently sort colors into the wrong bowls).
    Returns the {index: name} mapping.
    """
    try:
        with infer.MODEL_LOCK:
            model = infer._load(model_path)
            names = {int(k): v for k, v in dict(model.names).items()}
    except Exception as exc:  # noqa: BLE001
        raise SortingModelError(f"cannot load sorting model {model_path}: {exc}") from exc
    if names != EXPECTED_CLASS_MAP:
        raise SortingModelError(
            f"sorting model class map {names} != required {EXPECTED_CLASS_MAP}"
        )
    return names


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
    """Publish at QoS 1 with bounded connection and acknowledgement waits."""
    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
    connected = threading.Event()
    accepted = False

    def on_connect(client, userdata, flags, reason_code, properties):
        nonlocal accepted
        accepted = not reason_code.is_failure
        connected.set()

    client.on_connect = on_connect
    client.connect_timeout = settings.mqtt_timeout
    try:
        if (settings.mqtt_username or "").strip():
            client.username_pw_set(settings.mqtt_username, settings.mqtt_password or "")
        if settings.mqtt_use_tls:
            client.tls_set()
        client.connect(settings.mqtt_broker, settings.mqtt_port, keepalive=30)
        client.loop_start()
        if not connected.wait(settings.mqtt_timeout) or not accepted:
            return False
        message = client.publish("arm/command", json.dumps(payload, separators=(",", ":")), qos=1)
        message.wait_for_publish(timeout=settings.mqtt_timeout)
        return message.rc == mqtt.MQTT_ERR_SUCCESS and message.is_published()
    except Exception as exc:  # noqa: BLE001
        print(f"[sort_pipeline] MQTT publish failed: {exc}", flush=True)
        return False
    finally:
        client.disconnect()
        client.loop_stop()


def _quantize(v: float) -> float:
    tol = settings.sort_spatial_tolerance or 0.05
    return round(v / tol) * tol


def run_sort_pipeline(
    image_path: str,
    conf: float,
    model_path: str | None = None,
    *,
    camera: str | None = None,
    conveyor: str | None = None,
) -> dict:
    # Serialize the complete gate/ingest/publish/latch transaction. Locking
    # just the latch read/write allows two simultaneous frames to command it.
    with _pipeline_lock:
        return _run_sort_pipeline(image_path, conf, model_path, camera, conveyor)


def _run_sort_pipeline(image_path, conf, model_path, camera, conveyor) -> dict:
    """
    Run the competition sort pipeline on a single frame.

    Returns a dict with the YOLO inference result plus sort_triggered and,
    when no command is issued, a machine-readable reason:
      model_error | no_color_class | low_confidence | outside_pick_zone |
      duplicate | cooldown | ingest_failed | preflight_failed |
      bowl_full | arm_busy | mqtt_publish_failed
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
    target = max((det for det in result.get("detections", []) if det.get("label") in COLOR_ALIAS),
                 key=lambda det: det["confidence"], default=None)

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
        # The frame-level competition metadata belongs to this target only.
        # Other boxes must not inherit its color, center or event UUID.
        "detections": [target],
        "camera": camera or settings.icam_camera,
        "conveyor": conveyor or settings.icam_conveyor,
        "frame_width": frame_w,
        "frame_height": frame_h,
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
        if not isinstance(ingest_resp, dict) or ingest_resp.get("ok") is not True:
            return None
        detection_ids = ingest_resp.get("detection_ids", [])
        if not isinstance(detection_ids, list) or not detection_ids:
            return None
        return detection_ids[0] or None

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
    signature = (camera or settings.icam_camera, color, _quantize(nx), _quantize(ny))
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
        if (not isinstance(pre, dict) or pre.get("ok") is not True
                or type(pre.get("bowl_full")) is not bool
                or type(pre.get("arm_busy")) is not bool):
            raise ValueError("invalid preflight response")
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
    # Confidence convention: database/UI store 0-100 percent everywhere, so
    # the command carries 0-100 too (MqttListen persists it verbatim into
    # sorting_events.confidence). Only threshold comparisons use 0-1.
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
