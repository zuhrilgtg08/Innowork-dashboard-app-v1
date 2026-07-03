"""FastAPI entrypoint for the SortVision ML service."""
import tempfile
from pathlib import Path

from fastapi import BackgroundTasks, FastAPI, Form, UploadFile
from pydantic import BaseModel

import infer
import train
from config import settings

app = FastAPI(title="SortVision ML Service")


class AnnotationItem(BaseModel):
    image_path: str
    label: str
    bbox: list[float] | None = None
    split: str = "train"


class TrainRequest(BaseModel):
    run_id: int
    epochs: int = 5
    imgsz: int = 320
    storage_path: str
    callback_url: str
    annotations: list[AnnotationItem] = []


@app.get("/health")
def health():
    return {"status": "ok", "model_loaded": True, "base_model": settings.base_model}


@app.post("/train", status_code=202)
def start_train(req: TrainRequest, background: BackgroundTasks):
    """Accept a training job and run it in the background (returns immediately)."""
    background.add_task(
        train.run_training,
        req.run_id,
        req.epochs,
        req.imgsz,
        req.storage_path,
        req.callback_url,
        [a.model_dump() for a in req.annotations],
    )
    return {"accepted": True, "run_id": req.run_id}


@app.post("/infer")
async def run_infer(
    frame: UploadFile,
    conf: float = Form(0.85),
    model_path: str | None = Form(None),
    camera: str | None = Form(None),
    conveyor: str | None = Form(None),
    product_id: int | None = Form(None),
):
    """Run inference on one uploaded frame and return the QC verdict inline."""
    # Resolve a relative model path (models/run-x/best.pt) against Laravel storage.
    resolved_model = None
    if model_path:
        candidate = Path(settings.laravel_storage_path) / model_path
        resolved_model = str(candidate) if candidate.exists() else model_path

    data = await frame.read()
    with tempfile.NamedTemporaryFile(suffix=".jpg", delete=False) as tmp:
        tmp.write(data)
        tmp_path = tmp.name

    try:
        result = infer.infer_frame(tmp_path, resolved_model, conf)
    finally:
        Path(tmp_path).unlink(missing_ok=True)

    result["camera"] = camera
    result["conveyor"] = conveyor
    result["product_id"] = product_id
    return result
