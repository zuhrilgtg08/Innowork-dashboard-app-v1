"""Focused integration tests for the GREEN/YELLOW/RED best.pt (PR #22).

Covers the existing shared runtime from PR #19 against the new YOLO11
segmentation model contract. No servers, threads, cameras, or brokers are
started: YOLO results are faked, the model loader is stubbed, and every
test terminates on its own.
"""

import inspect
import re
import sys
from pathlib import Path

import numpy as np
import pytest

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import runtime
import stream
import vision_model
from config import settings
from vision_model import SortingModelError


# --- fakes ---------------------------------------------------------------

class _FakeXYXY:
    """Mimics a (1, 4) ultralytics box tensor: [row].tolist() -> [[x1..y2]]."""

    def __init__(self, box):
        self._box = box

    def __getitem__(self, index):
        return self._Row(self._box) if index == 0 else []

    class _Row:
        def __init__(self, box):
            self._box = box

        def tolist(self):
            return list(self._box)


class _FakeBox:
    """Mimics one ultralytics Boxes row (detect AND segment models)."""

    def __init__(self, cls_id, conf, box):
        self.cls = [cls_id]
        self.conf = [conf]
        self.xyxy = _FakeXYXY(box)


class _FakeMasks:
    """Segmentation masks exist on the result but must be ignored."""

    def __init__(self):
        self.data = [[0, 1], [1, 0]]


class _FakeSegResult:
    """Mimics a YOLO11 segmentation result: .boxes behaves like detect."""

    def __init__(self, boxes):
        self.boxes = boxes
        self.masks = _FakeMasks()


class _FakeEmptySegResult:
    """Segmentation result with no detections (boxes is None)."""

    def __init__(self):
        self.boxes = None
        self.masks = None


class _FakeModel:
    def __init__(self, names):
        self.names = names
        self.seen_kwargs = {}

    def predict(self, source, conf, device, verbose):
        self.seen_kwargs = {"conf": conf, "device": device, "verbose": verbose}
        return [
            _FakeSegResult([
                _FakeBox(1, 0.92, [10.0, 20.0, 50.0, 80.0]),
                _FakeBox(0, 0.81, [60.0, 30.0, 120.0, 90.0]),
            ])
        ]


class _FakeLoaderModel:
    def __init__(self, names):
        self.names = names


# --- 1-3. class maps -----------------------------------------------------

def test_english_class_map_accepted():
    ok, _ = vision_model.validate_class_map({0: "GREEN", 1: "YELLOW", 2: "RED"})
    assert ok is True


def test_indonesian_equivalents_remain_accepted():
    for names in (
        {0: "HIJAU", 1: "KUNING", 2: "MERAH"},
        {0: "GREEN", 1: "KUNING", 2: "MERAH"},
        {0: "RED", 1: "YELLOW", 2: "GREEN"},
    ):
        ok, _ = vision_model.validate_class_map(names)
        assert ok is True, names


def test_assert_sorting_classes_returns_normalized_map():
    normalized = vision_model.assert_sorting_classes(
        "models/run-100/best.pt",
        loader=lambda path: _FakeLoaderModel({0: "GREEN", 1: "YELLOW", 2: "RED"}),
    )
    assert normalized == {0: "GREEN", 1: "YELLOW", 2: "RED"}


# --- 4. invalid maps rejected --------------------------------------------

@pytest.mark.parametrize("names", [
    {0: "GREEN", 1: "YELLOW"},                                  # missing class
    {0: "GREEN", 1: "YELLOW", 2: "RED", 3: "GREEN"},            # extra class
    {0: "GREEN", 1: "HIJAU", 2: "RED"},                        # duplicate semantic
    {0: "GREEN", 1: "YELLOW", 2: "BLUE"},                      # unsupported name
    {0: "person", 1: "car", 2: "dog"},                         # COCO-style map
])
def test_invalid_class_maps_rejected(names):
    ok, error = vision_model.validate_class_map(names)
    assert ok is False
    assert error


# --- 5. segmentation compatibility ----------------------------------------

def test_seg_result_with_boxes_produces_normal_detections():
    worker = runtime.InferenceWorker()
    worker._model = _FakeModel({0: "GREEN", 1: "YELLOW", 2: "RED"})

    frame = np.zeros((480, 640, 3), dtype=np.uint8)
    detections, latency_ms = worker._infer(frame)

    assert len(detections) == 2
    # Sorted by confidence, highest first.
    assert [d["class_name"] for d in detections] == ["YELLOW", "GREEN"]
    for det in detections:
        for key in ("class_id", "class_name_raw", "class_name",
                    "confidence", "bbox", "center", "normalized"):
            assert key in det, key
        assert set(det["bbox"]) == {"x1", "y1", "x2", "y2"}
        assert set(det["center"]) == {"x", "y"}
    assert worker._model.seen_kwargs["device"] == "cpu"
    assert 0.05 <= worker._model.seen_kwargs["conf"] <= 0.95


def test_empty_seg_result_yields_no_detections():
    worker = runtime.InferenceWorker()

    class _EmptyModel(_FakeModel):
        def predict(self, source, conf, device, verbose):
            return [_FakeEmptySegResult()]

    worker._model = _EmptyModel({0: "GREEN", 1: "YELLOW", 2: "RED"})
    frame = np.zeros((480, 640, 3), dtype=np.uint8)
    detections, _ = worker._infer(frame)
    assert detections == []


# --- 6. invalid model is MODEL_ERROR --------------------------------------

def test_invalid_model_raises_sorting_model_error():
    with pytest.raises(SortingModelError):
        vision_model.assert_sorting_classes(
            "models/run-100/best.pt",
            loader=lambda path: _FakeLoaderModel({0: "person", 1: "car", 2: "dog"}),
        )


def test_unloadable_model_raises_sorting_model_error():
    def _boom(path):
        raise RuntimeError("weights file missing")

    with pytest.raises(SortingModelError):
        vision_model.assert_sorting_classes("models/run-100/best.pt", loader=_boom)


# --- 7. camera offline honesty --------------------------------------------

def test_fresh_camera_source_is_offline_not_live():
    source = stream.CameraSource()
    try:
        status = source.status()
        assert status["connected"] is False
        assert status["mode"] == "offline"
        assert status["mode"] != "live"
    finally:
        source.stop()


def test_simulator_gate_defaults_closed():
    assert settings.icam_allow_simulator is False


def test_fallback_reports_offline_when_gate_closed(monkeypatch):
    monkeypatch.setattr(settings, "icam_allow_simulator", False)
    source = stream.CameraSource()
    try:
        cap, mode = source._fallback()
        assert cap is None
        assert mode == "offline"
    finally:
        source.stop()


def test_primary_reports_nothing_when_unconfigured(monkeypatch):
    monkeypatch.setattr(settings, "icam_rtsp_url", "")
    monkeypatch.setattr(settings, "icam_rtsp_urls", "")
    monkeypatch.setattr(settings, "icam_stream_url", "")
    source = stream.CameraSource()
    try:
        cap, mode = source._open_primary()
        assert cap is None
        assert mode is None
    finally:
        source.stop()


# --- 8. credential redaction ----------------------------------------------

def test_camera_status_redacts_rtsp_credentials():
    from main import redact_url_credentials

    redacted = redact_url_credentials("rtsp://user:password@192.168.0.10:8550/video")
    assert redacted == "rtsp://***:***@192.168.0.10:8550/video"
    assert "user" not in redacted
    assert "password" not in redacted


def test_redaction_leaves_clean_values_untouched():
    from main import redact_url_credentials

    assert redact_url_credentials("rtsp://192.168.0.10:8550/video") == \
        "rtsp://192.168.0.10:8550/video"
    assert redact_url_credentials("samples/conveyor.mp4") == "samples/conveyor.mp4"
    assert redact_url_credentials("") == ""
    assert redact_url_credentials(None) == ""


# --- 9. preview stays read-only --------------------------------------------

def test_import_starts_no_inference_worker():
    assert runtime._worker is None
    assert runtime.state.get_annotated_jpeg() is None


def test_preview_endpoint_takes_no_side_effect_params():
    import main

    assert list(inspect.signature(main.camera_preview).parameters) == []


# --- iCAM endpoint: env-driven, never hard-coded ---------------------------

def test_realtime_endpoint_not_hardcoded_in_python_source():
    needle = "192.168.0.100" + ":8550"
    root = Path(__file__).resolve().parent.parent
    hits = []
    for path in sorted(root.rglob("*.py")):
        if "tests" in path.parts:
            continue
        for lineno, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
            if needle in line:
                hits.append(f"{path.name}:{lineno}")
    assert hits == []


def test_env_example_documents_expected_icam_endpoint():
    from urllib.parse import urlsplit

    env_example = Path(__file__).resolve().parent.parent.joinpath(".env.example").read_text()
    match = re.search(r"^ICAM_RTSP_URL=(.*)$", env_example, re.MULTILINE)
    assert match is not None
    parts = urlsplit(match.group(1).strip())
    assert parts.scheme == "rtsp"
    assert parts.hostname == "192.168.0.100"
    assert parts.port == 8550
    assert parts.path == "/video"


def test_camera_source_stays_env_driven_by_default(monkeypatch):
    from config import Settings

    monkeypatch.delenv("ICAM_RTSP_URL", raising=False)
    assert Settings(_env_file=None).icam_rtsp_url == ""


# --- 10. no MQTT imports introduced ----------------------------------------

@pytest.mark.parametrize("module", ["vision_model", "runtime", "stream", "infer", "config", "main"])
def test_core_runtime_modules_have_no_mqtt_imports(module):
    src = Path(__file__).resolve().parent.parent.joinpath(f"{module}.py").read_text()
    hits = re.findall(r"^\s*(?:import|from)\s+[\w.]*\b(?:paho|mqtt)\b", src,
                      re.MULTILINE | re.IGNORECASE)
    assert hits == [], hits
