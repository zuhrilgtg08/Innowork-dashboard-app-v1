"""Unit tests for the authoritative Vision Sorting model helpers.

Pure logic only: no torch, no camera, no broker required.
Run from ml-service/:  .venv/Scripts/python -m pytest tests/ -q
"""
import sys

import pytest

import vision_model
from vision_model import (
    SortingModelError,
    assert_sorting_classes,
    build_detection,
    english_name,
    primary_detection,
    resolve_sorting_model,
    validate_class_map,
)


def test_exact_class_map_validates():
    ok, error = validate_class_map({0: "HIJAU", 1: "KUNING", 2: "MERAH"})
    assert ok
    assert error == ""


def test_class_map_rejects_wrong_order():
    ok, error = validate_class_map({0: "MERAH", 1: "KUNING", 2: "HIJAU"})
    assert not ok
    assert "HIJAU" in error or "MERAH" in error


def test_class_map_rejects_extra_class():
    ok, _ = validate_class_map({0: "HIJAU", 1: "KUNING", 2: "MERAH", 3: "BIRU"})
    assert not ok


def test_class_map_rejects_missing_class():
    ok, _ = validate_class_map({0: "HIJAU", 1: "KUNING"})
    assert not ok


def test_class_map_rejects_coco_names():
    ok, _ = validate_class_map({0: "person", 1: "bottle"})
    assert not ok


def test_english_mapping():
    assert english_name("HIJAU") == "GREEN"
    assert english_name("KUNING") == "YELLOW"
    assert english_name("MERAH") == "RED"


def test_build_detection_geometry():
    det = build_detection(2, "MERAH", 96.4, 500, 200, 700, 430, 1280, 720)
    assert det["class_id"] == 2
    assert det["class_name_raw"] == "MERAH"
    assert det["class_name"] == "RED"
    assert det["confidence"] == 96.4
    assert det["bbox"] == {"x1": 500, "y1": 200, "x2": 700, "y2": 430}
    assert det["center"] == {"x": 600, "y": 315}
    norm = det["normalized"]
    assert norm["center_x"] == pytest.approx(600 / 1280, abs=1e-5)
    assert norm["center_y"] == pytest.approx(315 / 720, abs=1e-5)
    assert norm["width"] == pytest.approx(200 / 1280, abs=1e-5)
    assert norm["height"] == pytest.approx(230 / 720, abs=1e-5)
    for value in norm.values():
        assert 0.0 <= value <= 1.0


def test_build_detection_clamps_out_of_frame_boxes():
    det = build_detection(0, "HIJAU", 90.0, -50, -20, 1400, 900, 1280, 720)
    for value in det["normalized"].values():
        assert 0.0 <= value <= 1.0
    assert det["normalized"]["center_x"] == pytest.approx(675 / 1280, abs=1e-5)


def test_build_detection_confidence_clamped_to_percent():
    assert build_detection(1, "KUNING", 150.0, 0, 0, 10, 10, 100, 100)["confidence"] == 100.0
    assert build_detection(1, "KUNING", -5.0, 0, 0, 10, 10, 100, 100)["confidence"] == 0.0


def test_primary_detection_picks_highest_confidence():
    dets = [
        build_detection(0, "HIJAU", 80.0, 0, 0, 10, 10, 100, 100),
        build_detection(2, "MERAH", 96.4, 0, 0, 10, 10, 100, 100),
        build_detection(1, "KUNING", 91.8, 0, 0, 10, 10, 100, 100),
    ]
    top = primary_detection(dets)
    assert top["class_name"] == "RED"
    assert top["confidence"] == 96.4


def test_primary_detection_none_when_empty():
    assert primary_detection([]) is None


class _FakeModel:
    def __init__(self, names):
        self.names = names


def test_assert_sorting_classes_accepts_exact_map():
    names = assert_sorting_classes(
        "models/run-100/best.pt",
        loader=lambda path: _FakeModel({0: "HIJAU", 1: "KUNING", 2: "MERAH"}),
    )
    assert names == {0: "HIJAU", 1: "KUNING", 2: "MERAH"}


def test_assert_sorting_classes_rejects_mismatch():
    with pytest.raises(SortingModelError):
        assert_sorting_classes(
            "models/run-100/best.pt",
            loader=lambda path: _FakeModel({0: "person"}),
        )


def test_assert_sorting_classes_reports_load_failure():
    def boom(path):
        raise RuntimeError("disk gone")

    with pytest.raises(SortingModelError, match="cannot load"):
        assert_sorting_classes("models/run-100/best.pt", loader=boom)


def test_resolve_sorting_model_rejects_missing_file(tmp_path):
    with pytest.raises(SortingModelError, match="not found"):
        resolve_sorting_model(explicit=str(tmp_path / "nope.pt"))


def test_mqtt_publish_disabled_by_default():
    from config import Settings

    assert Settings.model_fields["sorting_mqtt_enabled"].default is False


def test_active_flow_imports_no_mqtt():
    for module in ("runtime", "vision_model", "sort_pipeline"):
        __import__(module)
    assert not any(name == "paho" or name.startswith("paho.") for name in sys.modules), (
        "paho must stay out of the active import chain (lazy import only)"
    )
