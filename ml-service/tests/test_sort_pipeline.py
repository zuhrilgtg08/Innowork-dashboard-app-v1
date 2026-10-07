"""Sorting gates must fail closed; tests never publish to a real broker."""
import copy
import sys
import tempfile
import threading
import unittest
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from PIL import Image

import infer
import preview
import sort_pipeline as pipeline
from config import settings


class SortingTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.frame = str(Path(self.directory.name) / "frame.jpg")
        Image.new("RGB", (100, 100)).save(self.frame)
        self.result = {"status": "passed", "confidence": 95.0, "boxes": [], "qr_value": None,
                       "detections": [{"status": "passed", "label": "HIJAU", "confidence": 95.0, "bbox": [30, 30, 70, 70]}]}
        self.preflight = {"ok": True, "arm_busy": False, "bowl_full": False}
        self.ingest = {"ok": True, "detection_ids": [123]}
        self.posts = []

        def post(url, payload):
            self.posts.append((url, payload))
            return self.preflight if url.endswith("/preflight") else self.ingest

        self.start_patch(patch.object(pipeline, "resolve_sorting_model", return_value="test.pt"))
        self.classes = self.start_patch(patch.object(pipeline, "assert_sorting_classes", return_value=pipeline.EXPECTED_CLASS_MAP))
        self.prediction = self.start_patch(patch.object(infer, "infer_frame", side_effect=lambda *args: copy.deepcopy(self.result)))
        self.post = self.start_patch(patch.object(pipeline, "_post_signed", side_effect=post))
        self.publish = self.start_patch(patch.object(pipeline, "_publish_arm_command", return_value=True))
        self.start_patch(patch.object(settings, "sort_cooldown_ms", 0))
        self.start_patch(patch.object(settings, "sort_min_confidence", 0.5))
        for name, value in (("pick_zone_x_min", 0.2), ("pick_zone_x_max", 0.8),
                            ("pick_zone_y_min", 0.2), ("pick_zone_y_max", 0.8)):
            self.start_patch(patch.object(settings, name, value))
        pipeline._last_signature = None
        pipeline._last_command_at = 0.0

    def start_patch(self, patcher):
        value = patcher.start()
        self.addCleanup(patcher.stop)
        return value

    def run_frame(self, **kwargs):
        return pipeline.run_sort_pipeline(self.frame, 0.5, **kwargs)

    def test_success_matches_command_and_persisted_target(self):
        self.result["detections"].insert(0, {"status": "passed", "label": "MERAH", "confidence": 75, "bbox": [1, 1, 9, 9]})
        result = self.run_frame(camera="CAM-2", conveyor="LINE-B")
        self.assertTrue(result["sort_triggered"])
        command = self.publish.call_args.args[0]
        self.assertEqual(command["detection_id"], 123)
        self.assertEqual(command["confidence"], 95.0)
        self.assertEqual(command["destination"], "BOWL_GREEN")
        detection = self.posts[0][1]
        self.assertEqual(detection["camera"], "CAM-2")
        self.assertEqual(detection["conveyor"], "LINE-B")
        self.assertEqual(detection["competition_event_id"], command["event_uuid"])
        self.assertEqual([det["label"] for det in detection["detections"]], ["HIJAU"])

    def test_missing_or_wrong_model_never_ingests_or_commands(self):
        self.classes.side_effect = pipeline.SortingModelError("wrong class map")
        self.assertEqual(self.run_frame()["reason"], "model_error")
        self.prediction.assert_not_called()
        self.post.assert_not_called()
        self.publish.assert_not_called()

    def test_no_target_and_low_confidence_do_not_command(self):
        self.result["detections"][0]["confidence"] = 30
        self.assertEqual(self.run_frame()["reason"], "low_confidence")
        self.result["detections"] = []
        self.assertEqual(self.run_frame()["reason"], "no_color_class")
        self.publish.assert_not_called()

    def test_outside_zone_is_visible_without_command(self):
        self.result["detections"][0]["bbox"] = [0, 0, 10, 10]
        self.assertEqual(self.run_frame()["reason"], "outside_pick_zone")
        self.assertEqual(len(self.posts), 1)
        self.publish.assert_not_called()

    def test_stationary_object_is_commanded_once_until_it_leaves(self):
        self.assertTrue(self.run_frame()["sort_triggered"])
        self.assertEqual(self.run_frame()["reason"], "duplicate")
        detections = self.result["detections"]
        self.result["detections"] = []
        self.run_frame()
        self.result["detections"] = detections
        self.assertTrue(self.run_frame()["sort_triggered"])
        self.assertEqual(self.publish.call_count, 2)

    def test_simultaneous_requests_only_publish_once(self):
        entered = threading.Event()
        release = threading.Event()

        def slow_publish(payload):
            entered.set()
            self.assertTrue(release.wait(2))
            return True

        self.publish.side_effect = slow_publish
        with ThreadPoolExecutor(max_workers=2) as pool:
            first = pool.submit(self.run_frame)
            self.assertTrue(entered.wait(2))
            second = pool.submit(self.run_frame)
            release.set()
            results = [first.result(timeout=3), second.result(timeout=3)]
        self.assertEqual(sum(result["sort_triggered"] for result in results), 1)
        self.publish.assert_called_once()

    def test_malformed_and_denied_preflight_never_publish(self):
        for preflight, reason in [({}, "preflight_failed"),
                                  ({"ok": False, "bowl_full": False, "arm_busy": False}, "preflight_failed"),
                                  ({"ok": True, "bowl_full": "false", "arm_busy": False}, "preflight_failed"),
                                  ({"ok": True, "bowl_full": True, "arm_busy": False}, "bowl_full"),
                                  ({"ok": True, "bowl_full": False, "arm_busy": True}, "arm_busy")]:
            with self.subTest(preflight=preflight):
                self.preflight = preflight
                self.assertEqual(self.run_frame()["reason"], reason)
        self.publish.assert_not_called()

    def test_failed_ingest_and_mqtt_never_report_success(self):
        self.ingest = {"ok": True, "deduped": True, "count": 0}
        self.assertEqual(self.run_frame()["reason"], "ingest_failed")
        self.publish.assert_not_called()
        self.ingest = {"ok": True, "detection_ids": [123]}
        self.publish.return_value = False
        self.assertEqual(self.run_frame()["reason"], "mqtt_publish_failed")
        self.assertIsNone(pipeline._last_signature)
        self.publish.return_value = True
        self.assertTrue(self.run_frame()["sort_triggered"])


class ModelAndPreviewTests(unittest.TestCase):
    def test_class_order_must_match_exactly(self):
        for names in ({0: "MERAH", 1: "KUNING", 2: "HIJAU"}, {0: "passed"}):
            with patch.object(infer, "_load", return_value=SimpleNamespace(names=names)):
                with self.assertRaises(pipeline.SortingModelError):
                    pipeline.assert_sorting_classes("test.pt")

    def test_preview_uses_sorting_gate_and_never_calls_pipeline(self):
        import numpy as np

        with patch.object(settings, "competition_mode", True), \
                patch.object(pipeline, "resolve_sorting_model", return_value="test.pt"), \
                patch.object(pipeline, "assert_sorting_classes") as classes, \
                patch.object(pipeline, "run_sort_pipeline") as run, \
                patch.object(infer, "infer_frame", return_value={"detections": []}):
            model = preview.resolve_model()
            jpeg, snapshot = preview.annotate_frame(np.zeros((100, 100, 3), dtype=np.uint8), 0.8, model)
        self.assertTrue(jpeg.startswith(b"\xff\xd8"))
        self.assertEqual(snapshot["frame_w"], 100)
        classes.assert_called_once_with("test.pt")
        run.assert_not_called()

    def test_mqtt_failure_and_missing_ack_are_not_success(self):
        with patch.object(pipeline.mqtt, "Client") as factory:
            client = factory.return_value
            client.connect.side_effect = OSError("broker unavailable")
            self.assertFalse(pipeline._publish_arm_command({"action": "sort"}))
            client.publish.assert_not_called()
            client.disconnect.assert_called_once()
        with patch.object(pipeline.mqtt, "Client") as factory:
            client = factory.return_value
            client.loop_start.side_effect = lambda: client.on_connect(client, None, None, SimpleNamespace(is_failure=False), None)
            client.publish.return_value.rc = 0
            client.publish.return_value.is_published.return_value = False
            self.assertFalse(pipeline._publish_arm_command({"action": "sort"}))
            client.publish.return_value.wait_for_publish.assert_called_once_with(timeout=settings.mqtt_timeout)


if __name__ == "__main__":
    unittest.main()
