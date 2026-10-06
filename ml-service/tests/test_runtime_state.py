"""Unit tests for the shared runtime state (bounded buffers, edge counting).

No camera, no model weights, no broker required.
Run from ml-service/:  .venv/Scripts/python -m pytest tests/ -q
"""
import numpy as np

from runtime import (
    CONFIDENCE_HISTORY_MAX,
    DETECTION_EVENTS_MAX,
    LATENCY_SAMPLES_MAX,
    RuntimeState,
)
from vision_model import build_detection


def _det(class_id=2, raw="MERAH", conf=96.4, box=(500, 200, 700, 430)):
    x1, y1, x2, y2 = box
    return build_detection(class_id, raw, conf, x1, y1, x2, y2, 1280, 720)


def _publish(state, dets, latency=42.0):
    frame = np.zeros((720, 1280, 3), dtype=np.uint8)
    import cv2

    ok, buf = cv2.imencode(".jpg", frame)
    assert ok
    jpeg = buf.tobytes()
    state.publish(1280, 720, jpeg, jpeg, dets, latency)


def test_empty_state_shapes():
    state = RuntimeState()
    latest = state.snapshot_latest()
    assert latest["detections"] == []
    assert latest["frame"] == {"width": 0, "height": 0}
    summary = state.snapshot_summary()
    assert (summary["green"], summary["yellow"], summary["red"], summary["total"]) == (0, 0, 0, 0)
    assert summary["model_loaded"] is False
    assert state.get_annotated_jpeg() is None


def test_publish_counts_new_objects_once():
    state = RuntimeState()
    det = _det()
    _publish(state, [det])  # first appearance: counted
    _publish(state, [det])  # same object, next frame: not counted again
    _publish(state, [det])
    summary = state.snapshot_summary()
    assert summary["red"] == 1
    assert summary["total"] == 1


def test_publish_counts_each_new_appearance():
    state = RuntimeState()
    _publish(state, [_det(box=(100, 100, 200, 200))])  # object A
    _publish(state, [])  # left the view: latch released
    _publish(state, [_det(box=(100, 100, 200, 200))])  # object A again: counted
    _publish(state, [_det(box=(900, 100, 1000, 200))])  # object B: counted
    summary = state.snapshot_summary()
    assert summary["red"] == 3
    assert summary["total"] == 3


def test_history_buffers_are_bounded():
    state = RuntimeState()
    for i in range(DETECTION_EVENTS_MAX + 120):
        _publish(state, [_det(conf=50.0 + (i % 40))])
    assert len(state.snapshot_confidence(limit=10_000)["samples"]) <= CONFIDENCE_HISTORY_MAX
    timeline = state.snapshot_timeline(minutes=180, bucket_seconds=30)
    total = sum(sum(bucket) for bucket in timeline["series"].values())
    assert total <= DETECTION_EVENTS_MAX


def test_latency_statistics():
    state = RuntimeState()
    for ms in (10.0, 20.0, 30.0):
        _publish(state, [], latency=ms)
    summary = state.snapshot_summary()
    assert summary["last_latency_ms"] == 30.0
    assert summary["average_latency_ms"] == 20.0
    assert len(state._latency) <= LATENCY_SAMPLES_MAX


def test_timeline_buckets_recent_events():
    state = RuntimeState()
    _publish(state, [_det(raw="HIJAU", class_id=0)])
    _publish(state, [_det(raw="KUNING", class_id=1)])
    timeline = state.snapshot_timeline(minutes=5, bucket_seconds=300)
    assert timeline["bucket_seconds"] == 300
    assert len(timeline["labels"]) == 1
    assert timeline["series"]["GREEN"] == [1]
    assert timeline["series"]["YELLOW"] == [1]
    assert timeline["series"]["RED"] == [0]


def test_legacy_preview_shape_for_existing_clients():
    state = RuntimeState()
    snap = state.snapshot_legacy_preview()
    assert snap["at"] is None
    assert snap["detections"] == []
    _publish(state, [_det()])
    snap = state.snapshot_legacy_preview()
    assert snap["detected_class"] == "MERAH"
    assert snap["normalized_color"] == "red"
    assert snap["confidence"] == 96.4
    assert snap["bbox"] == [500, 200, 700, 430]
    assert (snap["center_x"], snap["center_y"]) == (600, 315)
    assert snap["frame_w"] == 1280 and snap["frame_h"] == 720
