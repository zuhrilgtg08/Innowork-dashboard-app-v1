"""Authoritative Vision Sorting model helpers (MQTT-free).

best.pt is the single authoritative Vision Sorting model. This module owns:

- the required semantic class coverage {GREEN, YELLOW, RED} (Indonesian or
  English raw labels, any class-ID order),
- the user-facing English mapping HIJAU/GREEN->GREEN, KUNING/YELLOW->YELLOW,
  MERAH/RED->RED,
- model resolution + class validation (MODEL_ERROR on any mismatch),
- the canonical per-detection data structure (pixel + normalized geometry).

Deliberately free of paho.mqtt, httpx, and Laravel callbacks so the
continuous runtime and unit tests never depend on the broker — or on torch —
to import this module. Ultralytics is imported lazily, only when weights are
actually loaded.
"""

from pathlib import Path

import json
import socket

# Canonical reference class map of the production best.pt (Indonesian raws).
# Class IDs are NOT assumed: any ID order is accepted, and English raw
# labels (GREEN/YELLOW/RED) are accepted too — see RAW_TO_SEMANTIC.
EXPECTED_CLASS_MAP = {0: "HIJAU", 1: "KUNING", 2: "MERAH"}

# Canonical semantic colors every valid model must cover after normalization.
EXPECTED_SEMANTICS = {"GREEN", "YELLOW", "RED"}

# Raw YOLO label (either language) -> canonical semantic color. Class IDs are
# NOT assumed: any ID order is accepted as long as the normalized set is
# exactly {GREEN, YELLOW, RED} with no missing/duplicated/extra classes.
RAW_TO_SEMANTIC = {
    "HIJAU": "GREEN",
    "GREEN": "GREEN",
    "KUNING": "YELLOW",
    "YELLOW": "YELLOW",
    "MERAH": "RED",
    "RED": "RED",
}

# User-facing English class name (overlay text, API, UI). Raw labels in
# either language normalize to these; unknown raws pass through unchanged.
ENGLISH_NAMES = dict(RAW_TO_SEMANTIC)

# Canonical English color -> destination bowl (sorting actuation only).
DESTINATION_MAP = {
    "GREEN": "BOWL_GREEN",
    "YELLOW": "BOWL_YELLOW",
    "RED": "BOWL_RED",
}

# BGR draw colors per English class for the annotated overlay.
# Anything unmapped gets neutral blue (should never happen on this path).
DRAW_COLORS_BGR = {
    "GREEN": (34, 197, 94),
    "YELLOW": (8, 179, 234),
    "RED": (68, 68, 239),
}
DRAW_DEFAULT_BGR = (250, 165, 96)


class SortingModelError(Exception):
    """The authoritative Vision Sorting model cannot be used."""


def resolve_sorting_model(explicit: str | None = None) -> str:
    """Resolve the authoritative best.pt for Vision Sorting.

    An explicit request path wins (must exist); otherwise ICAM_MODEL_PATH
    resolved against Laravel storage is used. Raises SortingModelError when
    nothing usable is found — callers must reject the inference, never fall
    back to another model on this path.
    """
    # Local import: keeps this module importable without the app settings
    # (unit tests) while matching the rest of the service at runtime.
    from config import settings

    rel = explicit or settings.icam_model_path
    if not rel:
        raise SortingModelError("no sorting model configured (ICAM_MODEL_PATH is empty)")
    candidate = Path(rel)
    if not candidate.is_absolute():
        if candidate.exists():
            return str(candidate)
        candidate = Path(settings.laravel_storage_path) / rel
    if not candidate.exists():
        raise SortingModelError(f"sorting model not found: {rel}")
    return str(candidate)


def normalize_class_map(names: dict) -> dict | None:
    """Normalize a {index: raw_label} mapping to {index: semantic color}.

    Returns None when the map is invalid: unreadable entries, unknown raw
    labels, missing semantic colors, duplicated semantic colors, or any
    extra classes. Exactly {GREEN, YELLOW, RED} is required.
    """
    try:
        items = [(int(k), str(v).strip().upper()) for k, v in dict(names).items()]
    except (TypeError, ValueError):
        return None
    if len(items) != 3:
        return None
    normalized = {}
    seen = set()
    for idx, raw in items:
        semantic = RAW_TO_SEMANTIC.get(raw)
        if semantic is None or semantic in seen:
            return None
        seen.add(semantic)
        normalized[idx] = semantic
    if seen != EXPECTED_SEMANTICS:
        return None
    return normalized


def validate_class_map(names: dict) -> tuple[bool, str]:
    """Pure check of a {index: name} mapping against the semantic requirement.

    Accepts Indonesian or English raw labels in any ID order; rejects
    missing/duplicated/extra/unsupported classes. Returns (ok, error).
    """
    normalized = normalize_class_map(names)
    if normalized is None:
        try:
            shown = {int(k): str(v) for k, v in dict(names).items()}
        except (TypeError, ValueError):
            shown = dict(names)
        return False, (
            f"sorting model class map {shown} does not cover exactly "
            f"GREEN/YELLOW/RED (Indonesian or English raw labels, any ID order)"
        )
    return True, ""


def assert_sorting_classes(model_path: str, loader=None) -> dict:
    """Load the weights and verify the Vision Sorting class coverage.

    The normalized map must be exactly {GREEN, YELLOW, RED} — Indonesian or
    English raw labels, any ID order; missing/duplicated/extra classes are
    rejected. Returns the normalized {index: semantic} mapping. Raises
    SortingModelError on load failure or any mismatch.
    """
    if loader is None:
        import infer  # lazy: keeps torch/ultralytics out of the import chain

        loader = infer._load  # noqa: SLF001 — same service package
    try:
        model = loader(model_path)
    except Exception as exc:  # noqa: BLE001
        raise SortingModelError(f"cannot load sorting model {model_path}: {exc}") from exc
    names = {int(k): v for k, v in dict(model.names).items()}
    normalized = normalize_class_map(names)
    if normalized is None:
        _, error = validate_class_map(names)
        raise SortingModelError(error)
    return normalized


def semantic_name(class_name_raw: str) -> str | None:
    """Canonical semantic color for a raw YOLO label, or None if unsupported.

    Accepts Indonesian or English labels, case-insensitive.
    """
    return RAW_TO_SEMANTIC.get(str(class_name_raw).strip().upper())


def english_name(class_name_raw: str) -> str:
    """User-facing English class name; unknown raws pass through unchanged."""
    return semantic_name(class_name_raw) or str(class_name_raw)


def clamp01(value: float) -> float:
    """Clamp a normalized coordinate into [0.0, 1.0]."""
    return max(0.0, min(1.0, float(value)))


def build_detection(
    class_id: int,
    class_name_raw: str,
    confidence: float,
    x1: float,
    y1: float,
    x2: float,
    y2: float,
    frame_width: int,
    frame_height: int,
) -> dict:
    """Build one canonical detection record.

    confidence: 0-100 percentage (UI/API/DB convention).
    bbox/center: integers in pixels. normalized: 0.0-1.0 floats, clamped:

        center_x = (x1 + x2) / 2
        center_y = (y1 + y2) / 2
        center_x_norm = center_x / frame_width
        center_y_norm = center_y / frame_height
        width_norm  = (x2 - x1) / frame_width
        height_norm = (y2 - y1) / frame_height
    """
    x1i, y1i, x2i, y2i = int(x1), int(y1), int(x2), int(y2)
    cx, cy = (x1i + x2i) / 2.0, (y1i + y2i) / 2.0
    fw = max(1, int(frame_width))
    fh = max(1, int(frame_height))
    return {
        "class_id": int(class_id),
        "class_name_raw": str(class_name_raw),
        "class_name": english_name(class_name_raw),
        "confidence": round(max(0.0, min(100.0, float(confidence))), 1),
        "bbox": {"x1": x1i, "y1": y1i, "x2": x2i, "y2": y2i},
        # Direct X/Y for robot targets, in camera pixels (origin top-left).
        "x": int(cx),
        "y": int(cy),
        "center": {"x": int(cx), "y": int(cy)},
        "normalized": {
            "center_x": round(clamp01(cx / fw), 5),
            "center_y": round(clamp01(cy / fh), 5),
            "width": round(clamp01((x2i - x1i) / fw), 5),
            "height": round(clamp01((y2i - y1i) / fh), 5),
        },
    }


def primary_detection(detections: list) -> dict | None:
    """Highest-confidence detection, or None when there are no detections."""
    if not detections:
        return None
    return max(detections, key=lambda d: float(d.get("confidence", 0.0)))


def build_icam_payload(detection: dict | None) -> dict:
    """Ubah 1 deteksi jadi payload JSON untuk ESP32.

    Format persis protokol robot (docs/YOLO_ROBOT_TCP.md, TCP 1 baris + '\\n'):
        {"x": 313, "y": 56, "G": 1, "R": 0, "Y": 0}  # 5 field, tanpa "found"
        {"found": false}                              # tidak ada objek
    """
    if detection is None:
        return {"found": False}
    color = detection.get("class_name", "")
    return {
        "x": int(detection["x"]),
        "y": int(detection["y"]),
        "G": 1 if color == "GREEN" else 0,
        "R": 1 if color == "RED" else 0,
        "Y": 1 if color == "YELLOW" else 0,
    }


def send_to_esp32(payload: dict, esp32_ip: str, port: int = 5000, timeout: float = 1.0) -> bool:
    """Kirim payload JSON ke ESP32 via TCP. Return True kalau ESP32 jawab OK."""
    try:
        line = json.dumps(payload) + "\n"  # '\n' wajib: ESP32 baca per baris
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
            s.settimeout(timeout)
            s.connect((esp32_ip, port))
            s.sendall(line.encode("utf-8"))
            return s.recv(1024).decode("utf-8").strip() == "OK"
    except Exception as exc:  # noqa: BLE001 — best-effort, cukup log
        print(f"[TCP] gagal ke ESP32 ({esp32_ip}:{port}): {exc}")
        return False
