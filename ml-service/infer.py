"""Single-frame inference. Maps YOLO output to a QC detection status."""
from functools import lru_cache
from pathlib import Path
import threading

from PIL import Image

import qr_decode
from config import settings

MODEL_LOCK = threading.RLock()


class ModelError(ValueError):
    """Configured weights are absent or cannot be loaded."""


def resolve_model_path(model_path: str | None = None) -> str:
    """Resolve explicit/active weights in storage; base weights beside this file.

    A configured model must exist. Never disguise a broken active model by
    substituting COCO weights or downloading a different model.
    """
    configured = model_path or settings.icam_model_path
    path = Path(configured or settings.base_model)
    if not path.is_absolute():
        root = Path(settings.laravel_storage_path) if configured else Path(__file__).parent
        path = root / path
    if not path.is_file():
        raise ModelError(f"model not found: {path}")
    return str(path.resolve())

# Detection::STATUSES keys shared with Laravel.
QC_STATUSES = {"passed", "unreadable", "damaged", "scratched", "returned", "recheck"}


@lru_cache(maxsize=4)
def _load(model_path: str):
    try:
        from ultralytics import YOLO
        return YOLO(model_path)
    except Exception as exc:
        raise ModelError(f"cannot load model: {model_path}") from exc


def reload_models() -> None:
    """Drop cached YOLO handles so the next infer reloads weights from disk.

    Called by the /reload-model endpoint after Laravel activates a new model.
    """
    with MODEL_LOCK:
        _load.cache_clear()


def model_info(model_path: str | None) -> dict:
    """Real metadata about the YOLO weights (no inference run).

    Loads the weights through the same shared cache as infer_frame, so this
    adds no extra memory when the model is already warm.
    """
    weights = resolve_model_path(model_path)
    with MODEL_LOCK:
        model = _load(weights)

    size = Path(weights).stat().st_size if Path(weights).exists() else None

    try:
        import torch

        cuda_available = bool(torch.cuda.is_available())
    except Exception:  # noqa: BLE001
        cuda_available = False

    return {
        "path": weights,
        "file_size_bytes": size,
        "classes": {int(k): v for k, v in dict(model.names).items()},
        "class_count": len(model.names),
        # infer_frame always predicts on CPU; report that honestly.
        "inference_device": "cpu",
        "cuda_available": cuda_available,
    }


def _box_status(label: str, conf_pct: float, is_qc_model: bool, conf: float) -> str:
    """QC status for one box.

    Trained QC model: the class name *is* the status. Base COCO model: any
    box above the confidence threshold is a clean 'passed', else 'recheck'.
    """
    if is_qc_model and label in QC_STATUSES:
        return label
    return "passed" if conf_pct >= conf * 100 else "recheck"


def infer_frame(image_path: str, model_path: str | None, conf: float) -> dict:
    """Run inference and return the per-frame QC verdict.

    Return shape:
      {
        "status": <aggregate status of the top box>,   # backward-compatible
        "confidence": <top box confidence, 0-100>,
        "qr_value": <decoded QR string or None>,
        "boxes": [{label, confidence, xyxy}, ...],      # every box (raw)
        "detections": [{status, label, confidence, bbox}, ...],  # one per box
      }

    Two modes:
      - Trained QC model (its class names are QC statuses): each box's class
        name is used directly as its status.
      - Base COCO model (no QC classes yet): heuristic — a confident object is
        'passed', otherwise 'recheck'.
    """
    weights = resolve_model_path(model_path)

    # Read the QR up front (independent of the QC model). Multiple codes on the
    # conveyor are all captured; the first is surfaced as the frame's qr_value.
    qr_values = qr_decode.decode_qr_values(image_path)
    qr_value = qr_values[0] if qr_values else None

    with Image.open(image_path) as source:
        img = source.convert("RGB")
    frame_width, frame_height = img.size
    # Ultralytics mutates predictor state. Preview and HTTP/stream inference
    # share cached models, so prediction and reload must not overlap.
    with MODEL_LOCK:
        model = _load(weights)
        results = model.predict(source=img, conf=conf, device="cpu", verbose=False)
        names = dict(model.names)
    is_qc_model = bool(set(names.values()) & QC_STATUSES)

    boxes_out = []
    detections = []
    top = None
    for r in results:
        for b in r.boxes:
            c = float(b.conf[0])
            conf_pct = round(c * 100, 1)
            cls_name = names.get(int(b.cls[0]), str(int(b.cls[0])))
            xyxy = [round(float(v), 1) for v in b.xyxy[0].tolist()]
            box = {"label": cls_name, "confidence": conf_pct, "xyxy": xyxy}
            boxes_out.append(box)
            detections.append({
                "status": _box_status(cls_name, conf_pct, is_qc_model, conf),
                "label": cls_name,
                "confidence": conf_pct,
                "bbox": xyxy,
                "frame_width": frame_width,
                "frame_height": frame_height,
            })
            if top is None or c > top["confidence"] / 100:
                top = box

    boxes_out.sort(key=lambda box: box["confidence"], reverse=True)
    detections.sort(key=lambda det: det["confidence"], reverse=True)
    dimensions = {"frame_width": frame_width, "frame_height": frame_height}
    if top is None:
        # Nothing detected above threshold => unreadable / no-read.
        return {
            **dimensions,
            "status": "unreadable",
            "confidence": 0.0,
            "qr_value": qr_value,
            "boxes": [],
            "detections": [],
        }

    status = _box_status(top["label"], top["confidence"], is_qc_model, conf)

    return {
        **dimensions,
        "status": status,
        "confidence": top["confidence"],
        "qr_value": qr_value,
        "boxes": boxes_out,
        "detections": detections,
    }
