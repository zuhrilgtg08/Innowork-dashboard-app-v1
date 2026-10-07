"""Service contract tests; no camera, broker, Laravel or weight download needed."""
import hashlib
import hmac
import io
import sys
import tempfile
import threading
import unittest
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import httpx
import numpy as np
from fastapi.testclient import TestClient
from PIL import Image
from pydantic import ValidationError

import callbacks
import infer
import main
import preview
import train
from config import Settings, settings
from stream import CameraSource


def jpeg_bytes():
    stream = io.BytesIO()
    Image.new("RGB", (320, 240), "white").save(stream, format="JPEG")
    return stream.getvalue()


class ServiceTests(unittest.TestCase):
    def setUp(self):
        self.client = TestClient(main.app)  # No lifespan: no camera/automatic callbacks.
        self.addCleanup(self.client.close)
        mode = patch.object(settings, "competition_mode", False)
        mode.start()
        self.addCleanup(mode.stop)

    def test_invalid_image_is_rejected_before_inference(self):
        with patch.object(infer, "infer_frame") as prediction:
            response = self.client.post("/infer", files={"frame": ("bad.jpg", b"bad")})
        self.assertEqual(response.status_code, 422)
        prediction.assert_not_called()

    def test_confidence_and_upload_limits(self):
        response = self.client.post("/infer", files={"frame": ("frame.jpg", jpeg_bytes())}, data={"conf": 1.1})
        self.assertEqual(response.status_code, 422)
        with patch.object(main, "MAX_FRAME_BYTES", 5):
            response = self.client.post("/infer", files={"frame": ("frame.jpg", b"123456")})
        self.assertEqual(response.status_code, 413)

    def test_inference_context_and_temporary_file_cleanup(self):
        captured = []

        def prediction(path, model, conf):
            captured.append(path)
            self.assertTrue(Path(path).is_file())
            self.assertEqual(conf, 0.7)
            return {"status": "passed", "confidence": 97, "frame_width": 320, "frame_height": 240}

        with patch.object(infer, "infer_frame", side_effect=prediction):
            response = self.client.post("/infer", files={"frame": ("frame.jpg", jpeg_bytes())},
                                        data={"conf": 0.7, "camera": "CAM-2", "conveyor": "B", "product_id": 3})
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json()["camera"], "CAM-2")
        self.assertEqual(response.json()["product_id"], 3)
        self.assertEqual(response.json()["frame_width"], 320)
        self.assertFalse(Path(captured[0]).exists())

    def test_health_and_model_info_report_missing_weights(self):
        with patch.object(settings, "icam_model_path", "missing/weights.pt"):
            response = self.client.get("/health")
            self.assertEqual(response.status_code, 200)
            self.assertFalse(response.json()["model_loaded"])
            self.assertEqual(self.client.get("/model/info").status_code, 503)
            response = self.client.post("/infer", files={"frame": ("frame.jpg", jpeg_bytes())})
            self.assertEqual(response.status_code, 503)

    def test_reload_activates_valid_weights_and_keeps_previous_on_failure(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(settings, "icam_model_path", ""), \
                patch.object(settings, "laravel_storage_path", directory), \
                patch.object(infer, "_load", return_value=SimpleNamespace(names={0: "passed"})):
            weights = Path(directory) / "new.pt"
            weights.touch()
            response = self.client.post("/reload-model", json={"model_path": "new.pt"})
            self.assertEqual(response.status_code, 200)
            self.assertEqual(Path(settings.icam_model_path), weights)
            self.assertEqual(preview.resolve_model(), str(weights.resolve()))
            self.assertEqual(self.client.post("/reload-model", json={"model_path": "absent.pt"}).status_code, 503)
            self.assertEqual(Path(settings.icam_model_path), weights)

    def test_training_validation_and_busy_guard(self):
        request = {"run_id": 1, "epochs": 1, "storage_path": "storage", "callback_url": "http://laravel.test/api/ml/training/1",
                   "annotations": [{"image_path": "a.jpg", "label": "passed"}]}
        with patch.object(train, "run_training") as training:
            self.assertEqual(self.client.post("/train", json=request).status_code, 202)
            training.assert_called_once()
        with main._training_lock:
            self.assertEqual(self.client.post("/train", json=request).status_code, 409)
        for override in ({"epochs": 0}, {"run_id": -1}, {"annotations": []},
                         {"annotations": [{"image_path": "a.jpg", "label": "passed", "bbox": [0.9, 0, 0.3, 1]}]}):
            self.assertEqual(self.client.post("/train", json={**request, **override}).status_code, 422)

    def test_training_guard_is_released_on_failure(self):
        request = {"run_id": 1, "storage_path": "storage", "callback_url": "http://laravel.test/callback",
                   "annotations": [{"image_path": "a.jpg", "label": "passed"}]}
        with patch.object(train, "run_training", side_effect=RuntimeError("failed")):
            with self.assertRaises(RuntimeError):
                self.client.post("/train", json=request)
        self.assertFalse(main._training_lock.locked())

    def test_camera_frame_is_jpeg_and_uncached(self):
        with patch.object(main.camera_source, "latest_jpeg", return_value=jpeg_bytes()):
            response = self.client.get("/camera/frame")
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.headers["content-type"], "image/jpeg")
        self.assertIn("no-store", response.headers["cache-control"])

    def test_lifespan_stops_background_workers(self):
        stopped = []

        def worker(stop):
            stop.wait(2)
            stopped.append(stop.is_set())

        with patch.object(settings, "icam_auto_infer", True), patch.object(settings, "flow_analysis", True), \
                patch.object(main, "_infer_loop", side_effect=worker), patch.object(main, "_flow_loop", side_effect=worker), \
                patch.object(main.camera_source, "start"), patch.object(main.camera_source, "stop") as stop:
            with TestClient(main.app):
                pass
        self.assertEqual(stopped, [True, True])
        stop.assert_called_once()

    def test_cpu_inference_does_not_block_health_requests(self):
        entered = threading.Event()
        release = threading.Event()

        def prediction(*args):
            entered.set()
            release.wait(3)
            return {"status": "passed"}

        with patch.object(settings, "icam_auto_infer", False), patch.object(settings, "flow_analysis", False), \
                patch.object(main.camera_source, "start"), patch.object(main.camera_source, "stop"), \
                patch.object(infer, "infer_frame", side_effect=prediction), \
                patch.object(main, "_resolve_stream_model", return_value="test.pt"), \
                patch.object(infer, "model_info", return_value={"path": "test.pt", "classes": {0: "passed"}}):
            with TestClient(main.app) as client, ThreadPoolExecutor(max_workers=2) as pool:
                request = pool.submit(client.post, "/infer", files={"frame": ("frame.jpg", jpeg_bytes())})
                try:
                    self.assertTrue(entered.wait(2))
                    health = pool.submit(client.get, "/health")
                    self.assertEqual(health.result(timeout=1).status_code, 200)
                finally:
                    release.set()
                self.assertEqual(request.result(timeout=2).status_code, 200)

    def test_stream_re_resolves_active_weights_each_iteration(self):
        stop = Mock()
        stop.wait.side_effect = [False, False, True]
        image = np.zeros((20, 20, 3), dtype=np.uint8)
        with patch.object(main.camera_source, "latest_frame", return_value=image), \
                patch.object(main, "_resolve_stream_model", side_effect=["first.pt", "second.pt"]), \
                patch.object(infer, "infer_frame", return_value={}) as prediction, \
                patch.object(callbacks, "post_detection"):
            main._infer_loop(stop)
        self.assertEqual([call.args[1] for call in prediction.call_args_list], ["first.pt", "second.pt"])


class InferenceTests(unittest.TestCase):
    def test_per_box_coordinates_confidence_and_frame_dimensions(self):
        def box(cls, conf, bounds):
            return SimpleNamespace(cls=[cls], conf=[conf], xyxy=np.array([bounds]))

        model = Mock(names={0: "passed", 1: "damaged"})
        model.predict.return_value = [SimpleNamespace(boxes=[box(0, 0.85, [1, 2, 100, 200]), box(1, 0.97, [20, 30, 80, 90])])]
        with tempfile.TemporaryDirectory() as directory:
            frame = Path(directory) / "frame.jpg"
            frame.write_bytes(jpeg_bytes())
            with patch.object(infer, "resolve_model_path", return_value="test.pt"), \
                    patch.object(infer, "_load", return_value=model), patch.object(infer.qr_decode, "decode_qr_values", return_value=["QR-1"]):
                result = infer.infer_frame(str(frame), None, 0.8)
        self.assertEqual(result["status"], "damaged")
        self.assertEqual(result["confidence"], 97.0)
        self.assertEqual(result["qr_value"], "QR-1")
        self.assertEqual(result["detections"][0]["bbox"], [20, 30, 80, 90])
        self.assertEqual(result["detections"][0]["frame_width"], 320)
        self.assertEqual(result["frame_height"], 240)

    def test_empty_frame_preserves_dimensions(self):
        model = Mock(names={0: "passed"})
        model.predict.return_value = [SimpleNamespace(boxes=[])]
        with tempfile.TemporaryDirectory() as directory:
            frame = Path(directory) / "frame.jpg"
            frame.write_bytes(jpeg_bytes())
            with patch.object(infer, "resolve_model_path", return_value="test.pt"), patch.object(infer, "_load", return_value=model):
                result = infer.infer_frame(str(frame), None, 0.8)
        self.assertEqual(result["status"], "unreadable")
        self.assertEqual(result["detections"], [])
        self.assertEqual(result["frame_width"], 320)

    def test_paths_are_independent_of_working_directory(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(settings, "laravel_storage_path", directory):
            weights = Path(directory) / "model.pt"
            weights.touch()
            self.assertEqual(infer.resolve_model_path("model.pt"), str(weights.resolve()))
            with self.assertRaises(infer.ModelError):
                infer.resolve_model_path("missing.pt")


class DatasetTests(unittest.TestCase):
    def test_multiple_objects_share_one_image_and_stay_in_one_split(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "public").mkdir()
            (root / "public" / "a.jpg").write_bytes(jpeg_bytes())
            (root / "public" / "b.jpg").write_bytes(jpeg_bytes())
            annotations = [
                {"image_path": "a.jpg", "label": "damaged", "bbox": [0, 0, 0.5, 0.5], "split": "train"},
                {"image_path": "a.jpg", "label": "passed", "bbox": [0.5, 0.5, 0.5, 0.5], "split": "val"},
                {"image_path": "b.jpg", "label": "passed", "split": "val"},
            ]
            with patch.object(train, "RUNS_DIR", root / "runs"):
                dataset, classes, ntrain, nval = train.build_dataset(1, str(root), annotations)
            self.assertEqual((ntrain, nval), (1, 1))
            self.assertEqual(classes, ["damaged", "passed"])
            labels = list((dataset / "labels" / "train").glob("*.txt"))
            self.assertEqual(len(labels), 1)
            self.assertEqual(len(labels[0].read_text().splitlines()), 2)
            self.assertIn("0.250000 0.250000 0.500000 0.500000", labels[0].read_text())

    def test_annotation_path_cannot_escape_public_storage(self):
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaises(ValueError):
                train._public_path(directory, "../secret.jpg")


class CallbackAndConfigTests(unittest.TestCase):
    def test_signature_covers_exact_body_and_http_errors_are_reported(self):
        response = httpx.Response(403, request=httpx.Request("POST", "http://laravel.test/callback"))
        with patch.object(settings, "ml_callback_secret", "test-secret"), patch.object(callbacks.httpx, "post", return_value=response) as post:
            self.assertFalse(callbacks._post("http://laravel.test/callback", {"status": "passed"}))
        kwargs = post.call_args.kwargs
        expected = hmac.new(b"test-secret", kwargs["content"], hashlib.sha256).hexdigest()
        self.assertEqual(kwargs["headers"]["X-ML-Signature"], expected)

    def test_invalid_pick_zone_is_rejected(self):
        with self.assertRaises(ValidationError):
            Settings(_env_file=None, pick_zone_x_min=0.9, pick_zone_x_max=0.1)
        with self.assertRaises(ValidationError):
            Settings(_env_file=None, sort_spatial_tolerance=0)

    def test_synthetic_camera_can_stop_and_restart(self):
        source = CameraSource()
        with patch.object(source, "_open_primary", return_value=(None, None)), \
                patch.object(source, "_open_simulator", return_value=None):
            for _ in range(2):
                ready = threading.Event()
                original = source._push

                def push(frame):
                    original(frame)
                    ready.set()

                with patch.object(source, "_push", side_effect=push):
                    source.start()
                    try:
                        self.assertTrue(ready.wait(2))
                        self.assertIsNotNone(source.latest_jpeg())
                        self.assertEqual(source.status()["mode"], "simulator")
                    finally:
                        source.stop()
                self.assertFalse(source._thread.is_alive())
                self.assertIsNone(source.latest_frame())
                self.assertEqual(source.status()["mode"], "offline")


if __name__ == "__main__":
    unittest.main()
