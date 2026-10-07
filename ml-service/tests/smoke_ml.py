r"""Opt-in smoke test with local YOLO weights. No Laravel/MQTT side effects.

Run from ml-service: .venv\Scripts\python.exe tests/smoke_ml.py [--train]
The optional one-epoch training uses a disposable synthetic dataset; it only
checks execution and artifacts, not model accuracy.
"""
import argparse
import json
import sys
import tempfile
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import cv2
from fastapi.testclient import TestClient
from PIL import Image, ImageDraw

import main
import preview
import train
from config import settings
from stream import CameraSource


def smoke(train_model=False):
    # Keep the check independent of the connected camera and physical arm.
    with patch.object(settings, "competition_mode", False), \
            patch.object(settings, "icam_auto_infer", False), patch.object(settings, "flow_analysis", False), \
            patch.object(settings, "icam_rtsp_url", ""), patch.object(settings, "icam_sim_source", ""):
        with TestClient(main.app) as client:
            health = client.get("/health")
            assert health.status_code == 200 and health.json()["model_loaded"], health.text
            model = client.get("/model/info")
            assert model.status_code == 200, model.text
            frame = CameraSource._synthetic()
            ok, jpeg = cv2.imencode(".jpg", frame)
            assert ok
            result = client.post("/infer", files={"frame": ("frame.jpg", jpeg.tobytes(), "image/jpeg")})
            assert result.status_code == 200, result.text
            assert (result.json()["frame_width"], result.json()["frame_height"]) == (640, 480)
            camera = client.get("/camera/frame")
            assert camera.status_code == 200 and camera.content.startswith(b"\xff\xd8")
            annotated, snapshot = preview.annotate_frame(frame, settings.icam_conf, preview.resolve_model())
            assert annotated.startswith(b"\xff\xd8") and snapshot["frame_w"] == 640
            print(json.dumps({"health": "ok", "classes": model.json()["classes"],
                              "infer": result.json()["status"], "frame": "640x480", "preview": "ok"}))

    if train_model:
        with tempfile.TemporaryDirectory(prefix="sortvision-smoke-") as directory:
            root = Path(directory)
            public = root / "public"
            public.mkdir()
            annotations = []
            for i in range(5):
                image = Image.new("RGB", (64, 64), "white")
                ImageDraw.Draw(image).rectangle((16, 16, 48, 48), fill="green")
                image.save(public / f"frame-{i}.jpg")
                annotations.append({"image_path": f"frame-{i}.jpg", "label": "passed",
                                    "bbox": [0.25, 0.25, 0.5, 0.5], "split": "val" if i == 4 else "train"})
            with patch.object(train, "RUNS_DIR", root / "runs"), \
                    patch.object(train.callbacks, "progress"), patch.object(train.callbacks, "complete") as completed, \
                    patch.object(train.callbacks, "fail") as failed:
                train.run_training(1, 1, 64, str(root), "http://unused.test/callback", annotations)
                assert not failed.called, failed.call_args
                assert completed.call_count == 1
                assert (root / "models" / "run-1" / "best.pt").is_file()
                assert completed.call_args.args[-2:] == (4, 1)
                print(json.dumps({"training": "ok", "epochs": 1, "train_images": 4, "val_images": 1,
                                  "weights_saved": True, "completion_callback": True}))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--train", action="store_true")
    smoke(parser.parse_args().train)
