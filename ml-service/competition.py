import asyncio
import json
import os
from pathlib import Path

import uvicorn
from fastapi import FastAPI, BackgroundTask
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel

import sys
sys.path.insert(0, os.getenv("ML_SERVICE_PATH", "."))

from mock_hardware import (
    COLOR_MAP,
    COMPETITION_MODE,
    publish_command,
    publish_status,
    on_command,
    start_mqtt_client,
    run_mock_server,
    pick_destination,
    color_name_to_enum,
)

# Model path from environment
MODEL_PATH = os.getenv("ICAM_MODEL_PATH", "models/run-100/best.pt")

# MQTT configuration
MQTT_BROKER = os.getenv("MQTT_BROKER", "localhost")
MQTT_PORT = int(os.getenv("MQTT_PORT", 1883))
MQTT_TOPIC_COMMAND = "arm/command"
MQTT_TOPIC_STATUS = "arm/status"

# Competition configuration
COMPETITION_MODE = os.getenv("COMPETITION_MODE", "false").lower() == "true"
MAX_OBJECTS_PER_COLOR = int(os.getenv("MAX_OBJECTS_PER_COLOR", "3"))
SORT_MIN_CONFIDENCE = float(os.getenv("SORT_MIN_CONFIDENCE", "0.5"))
SORT_COOLDOWN_MS = int(os.getenv("SORT_COOLDOWN_MS", "1000"))

# FastAPI app
app = FastAPI(
    title="SortVision ML Service",
    description="Computer vision service for QC sorting competition mode",
    version="1.0.0",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# State
mqtt_client = None
current_color = None
current_destination = None
current_confidence = 0.0
sorting_active = False
detection_count = {"HIJAU": 0, "KUNING": 0, "MERAH": 0}

# Pydantic models
class InferRequest(BaseModel):
    image_base64: str
    timestamp: float | None = None

class SortCommand(BaseModel):
    color: str
    detection_id: str | None = None
    center_x: float | None = None
    center_y: float | None = None
    confidence: float | None = None

class StatusResponse(BaseModel):
    state: str
    color: str | None = None
    detection_id: str | None = None
    confidence: float | None = 0.0


def get_mqtt_client():
    global mqtt_client
    if mqtt_client is None:
        mqtt_client = start_mqtt_client()
    return mqtt_client


@app.on_event("startup")
async def startup():
    """Start MQTT client on service startup."""
    global mqtt_client
    if COMPETITION_MODE:
        mqtt_client = start_mqtt_client()
        await publish_status(mqtt_client, "READY")
        print(f"ML Service started in {('COMPETITION' if COMPETITION_MODE else 'NORMAL')} mode")


@app.on_event("shutdown")
async def shutdown():
    """Clean up MQTT client on shutdown."""
    global mqtt_client
    if mqtt_client:
        mqtt_client.loop_stop()
        mqtt_client.disconnect()
        mqtt_client = None


@app.get("/health")
async def health():
    """Health check endpoint."""
    return {"status": "healthy", "competition_mode": COMPETITION_MODE}


@app.post("/infer")
async def infer(request: InferRequest):
    """Run inference on a single frame."""
    if not COMPETITION_MODE:
        return {"verdict": "ml_service_offline", "color": None}

    # Simulate inference - in production would use YOLO model
    # For now, return a mock result based on the current state
    # This would normally process the image_base64 through YOLO

    # Mock: randomly select a color for demonstration
    import random
    colors = list(COLOR_MAP.keys())
    selected_color = random.choice(colors)

    # Publish sorting command to mock hardware
    destination = pick_destination(selected_color)
    mc = get_mqtt_client()
    await publish_command(
        mc, selected_color, destination, confidence=SORT_MIN_CONFIDENCE
    )

    # Update counters
    global detection_count
    detection_count[selected_color] = (
        (detection_count[selected_color] or 0) + 1
    )

    return {
        "verdict": selected_color,
        "color": selected_color,
        "destination": destination,
        "confidence": round(SORT_MIN_CONFIDENCE, 2),
        "timestamp": request.timestamp or 0.0,
    }


@app.post("/train")
async def start_training(background: BackgroundTask):
    """Start training background task."""
    if not COMPETITION_MODE:
        return {"status": "training_unavailable", "message": "Competition mode required"}

    background.add_task(_run_training)
    return {"status": "training_started", "message": "Training started in background"}


async def _run_training():
    """Run training process (simplified)."""
    print("Training started...")
    # In a full implementation, this would:
    # 1. Export approved annotations
    # 2. Split 80/20 train/test
    # 3. Train YOLO model
    # 4. Callbacks to Laravel via signed signatures
    # 5. Update Setting with new active training run
    await asyncio.sleep(2)
    print("Training completed")


@app.get("/status")
async def get_status():
    """Get current service status."""
    mc = get_mqtt_client()
    return {
        "competition_mode": COMPETITION_MODE,
        "mqtt_connected": mqtt_client is not None if 'mqtt_client' in globals() else False,
        "detection_counts": detection_count,
        "max_objects_per_color": MAX_OBJECTS_PER_COLOR,
        "sort_min_confidence": SORT_MIN_CONFIDENCE,
    }


if __name__ == "__main__":
    uvicorn.run(
        "main:app",
        host=os.getenv("SERVICE_HOST", "0.0.0.0"),
        port=int(os.getenv("SERVICE_PORT", 8001)),
        reload=os.getenv("DEBUG", "true").lower() == "true",
    )