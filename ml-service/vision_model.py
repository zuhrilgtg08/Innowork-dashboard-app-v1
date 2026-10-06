"""Authoritative Vision Sorting model helpers (MQTT-free).

best.pt is the single authoritative Vision Sorting model. This module owns:

- the exact required class map {0: HIJAU, 1: KUNING, 2: MERAH},
- the user-facing English mapping HIJAU->GREEN, KUNING->YELLOW, MERAH->RED,
- model resolution + class-map validation (MODEL_ERROR on any mismatch),
- the canonical per-detection data structure (pixel + normalized geometry).

Deliberately free of paho.mqtt, httpx, and Laravel callbacks so the
continuous runtime and unit tests never depend on the broker — or on torch —
to import this module. Ultralytics is imported lazily, only when weights are
actually loaded.
"""

from pathlib import Path

# Exact class map the authoritative Vision Sorting model must expose.
# ID AND order are enforced (a model with the right names on the wrong IDs
# would sort colors into the wrong bowls).
EXPECTED_CLASS_MAP = {0: "HIJAU", 1: "KUNING", 2: "MERAH"}

# Raw YOLO class name -> user-facing English name (overlay text, API, UI).
ENGLISH_NAMES = {
    "HIJAU": "GREEN",
    "KUNING": "YELLOW",
    "MERAH": "RED",
}

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


def validate_class_map(names: dict) -> tuple[bool, str]:
    """Pure check of a {index: name} mapping against the required class map.

    Returns (ok, error_message). Anything but an exact match is rejected —
    wrong IDs would silently sort colors into the wrong bowls.
    """
    try:
        normalized = {int(k): str(v) for k, v in dict(names).items()}
    except (TypeError, ValueError):
        return False, f"sorting model class map {dict(names)!r} is not readable"
    if normalized != EXPECTED_CLASS_MAP:
        return False, (
            f"sorting model class map {normalized} != required {EXPECTED_CLASS_MAP}"
        )
    return True, ""


def assert_sorting_classes(model_path: str, loader=None) -> dict:
    """Load the weights and verify the EXACT Vision Sorting class map.

    Returns the {index: name} mapping. Raises SortingModelError on load
    failure or any mismatch. `loader` is injectable for unit tests.
    """
    if loader is None:
        import infer  # lazy: keeps torch/ultralytics out of the import chain

        loader = infer._load  # noqa: SLF001 — same service package
    try:
        model = loader(model_path)
    except Exception as exc:  # noqa: BLE001
        raise SortingModelError(f"cannot load sorting model {model_path}: {exc}") from exc
    names = {int(k): v for k, v in dict(model.names).items()}
    ok, error = validate_class_map(names)
    if not ok:
        raise SortingModelError(error)
    return names


def english_name(class_name_raw: str) -> str:
    """User-facing English class name; unknown raws pass through unchanged."""
    return ENGLISH_NAMES.get(class_name_raw, class_name_raw)


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
