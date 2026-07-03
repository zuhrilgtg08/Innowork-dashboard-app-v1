"""Single-frame inference. Maps YOLO output to a QC detection status."""
from functools import lru_cache
from pathlib import Path

from PIL import Image

# Detection::STATUSES keys shared with Laravel.
QC_STATUSES = {"passed", "unreadable", "damaged", "scratched", "returned", "recheck"}


@lru_cache(maxsize=4)
def _load(model_path: str):
    from ultralytics import YOLO
    return YOLO(model_path)


def infer_frame(image_path: str, model_path: str | None, conf: float) -> dict:
    """Run inference and return {status, confidence, boxes}.

    Two modes:
      - Trained QC model (its class names are QC statuses): use the top box's
        class name directly as the status.
      - Base COCO model (no QC classes yet): prove the pipeline with a heuristic —
        a confident object present => 'passed', otherwise 'unreadable'.
    """
    weights = model_path if (model_path and Path(model_path).exists()) else "yolov8n.pt"
    model = _load(weights)

    img = Image.open(image_path).convert("RGB")
    results = model.predict(source=img, conf=max(0.05, min(conf, 0.95)),
                            device="cpu", verbose=False)

    names = model.names  # {idx: name}
    is_qc_model = bool(set(names.values()) & QC_STATUSES)

    boxes_out = []
    top = None
    for r in results:
        for b in r.boxes:
            c = float(b.conf[0])
            cls_name = names.get(int(b.cls[0]), str(int(b.cls[0])))
            box = {
                "label": cls_name,
                "confidence": round(c * 100, 1),
                "xyxy": [round(float(v), 1) for v in b.xyxy[0].tolist()],
            }
            boxes_out.append(box)
            if top is None or c > top["confidence"] / 100:
                top = box

    if top is None:
        # Nothing detected above threshold => unreadable / no-read.
        return {"status": "unreadable", "confidence": 0.0, "boxes": []}

    if is_qc_model and top["label"] in QC_STATUSES:
        status = top["label"]
    else:
        # Base model: any confident object counts as a clean pass.
        status = "passed" if top["confidence"] >= conf * 100 else "recheck"

    return {"status": status, "confidence": top["confidence"], "boxes": boxes_out}
