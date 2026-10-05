"""Competition sorting pipeline: YOLO inference → signed POST → MQTT publish."""
import base64
import hashlib
import hmac
import json
import tempfile
from pathlib import Path

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
    """Publish a command to arm/command via MQTT (QoS 1)."""
    try:
        client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
        client.connect(settings.mqtt_broker, settings.mqtt_port, 60)
        client.publish(
            "arm/command",
            json.dumps(payload, separators=(",", ":")),
            qos=1,
        )
        client.disconnect()
        return True
    except Exception as exc:  # noqa: BLE001
        print(f"[sort_pipeline] MQTT publish failed: {exc}", flush=True)
        return False


def run_sort_pipeline(
    image_path: str,
    conf: float,
    model_path: str | None = None,
) -> dict:
    """
    Run the competition sort pipeline on a single frame.

    Returns a dict with the YOLO inference result plus the published command info.
    """
    # 1. Run YOLO inference
    result = infer.infer_frame(image_path, model_path, conf)

    # 2. Find the top detection whose label maps to a competition color
    target = None
    for det in result.get("detections", []):
        label = det.get("label", "")
        if label in COLOR_ALIAS:
            target = det
            break

    if target is None:
        # No competition-relevant object found
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
            "confidence": confidence,
        }

    # 3. Read frame for signed POST
    with open(image_path, "rb") as f:
        frame_b64 = base64.b64encode(f.read()).decode()

    # 4. Prepare detection payload for Laravel
    import uuid
    event_uuid = str(uuid.uuid4())
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
        "center_x": int((target["bbox"][0] + target["bbox"][2]) / 2),
        "center_y": int((target["bbox"][1] + target["bbox"][3]) / 2),
        "in_pick_zone": True,
        "competition_event_id": event_uuid,
    }

    # 5. POST to Laravel's signed ingest endpoint
    laravel_ingest = f"{settings.laravel_url.rstrip('/')}/api/camera/detection"
    try:
        ingest_resp = _post_signed(laravel_ingest, detection_payload)
        detection_ids = ingest_resp.get("detection_ids", [])
        detection_id = detection_ids[0] if detection_ids else None
    except Exception as exc:  # noqa: BLE001
        print(f"[sort_pipeline] Signed POST failed: {exc}", flush=True)
        return {
            **result,
            "sort_triggered": False,
            "reason": "ingest_failed",
            "error": str(exc),
        }

    # 6. Publish arm/command MQTT message
    command_payload = {
        "action": "sort",
        "event_uuid": event_uuid,
        "detection_id": detection_id,
        "color": color,
        "destination": destination,
        "confidence": round(confidence, 3),
        "bbox": target["bbox"],
        "source": "ml_service",
    }

    _publish_arm_command(command_payload)

    return {
        **result,
        "sort_triggered": True,
        "event_uuid": event_uuid,
        "detection_id": detection_id,
        "color": color,
        "destination": destination,
        "command": command_payload,
    }