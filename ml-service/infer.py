"""Single-frame inference. Maps YOLO output to a QC detection status."""
from functools import lru_cache
from pathlib import Path

from PIL import Image

import qr_decode

# Detection::STATUSES keys shared with Laravel.
QC_STATUSES = {"passed", "unreadable", "damaged", "scratched", "returned", "recheck"}


@lru_cache(maxsize=4)
def _load(model_path: str):
    from ultralytics import YOLO
    return YOLO(model_path)


def reload_models() -> None:
    """Drop cached YOLO handles so the next infer reloads weights from disk.

    Called by the /reload-model endpoint after Laravel activates a new model.
    """
    _load.cache_clear()


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
    weights = model_path if (model_path and Path(model_path).exists()) else "yolov8n.pt"
    model = _load(weights)

    # Read the QR up front (independent of the QC model). Multiple codes on the
    # conveyor are all captured; the first is surfaced as the frame's qr_value.
    qr_values = qr_decode.decode_qr_values(image_path)
    qr_value = qr_values[0] if qr_values else None

    img = Image.open(image_path).convert("RGB")
    results = model.predict(source=img, conf=max(0.05, min(conf, 0.95)),
                            device="cpu", verbose=False)

    names = model.names  # {idx: name}
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
            })
            if top is None or c > top["confidence"] / 100:
                top = box

    if top is None:
        # Nothing detected above threshold => unreadable / no-read.
        return {
            "status": "unreadable",
            "confidence": 0.0,
            "qr_value": qr_value,
            "boxes": [],
            "detections": [],
        }

    status = _box_status(top["label"], top["confidence"], is_qc_model, conf)

    return {
        "status": status,
        "confidence": top["confidence"],
        "qr_value": qr_value,
        "boxes": boxes_out,
        "detections": detections,
    }
