"""XY and the supplied ESP32 TCP protocol; no real device is contacted."""
import copy
import json
import sys
from collections import deque
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import robot_bridge
import runtime
import vision_model
from config import Settings


def detection(color="GREEN", confidence=95, x=320, y=240):
    return vision_model.build_detection(0, color, confidence,
                                        x - 20, y - 20, x + 20, y + 20, 640, 480)


class FakeState:
    def __init__(self):
        self.data = {"timestamp": 1, "frame": {"width": 640, "height": 480},
                     "detections": [detection()], "camera_live": True,
                     "model_loaded": True, "age_s": 0.1}

    def snapshot_robot_input(self):
        return copy.deepcopy(self.data)


class FakeSocket:
    def __init__(self, replies=()):
        self.sent = []
        self.replies = deque(replies)
        self.closed = False

    def settimeout(self, seconds):
        assert seconds > 0

    def sendall(self, message):
        assert not self.closed
        self.sent.append(message)

    def recv(self, size):
        reply = self.replies.popleft() if self.replies else b"OK\n"
        if isinstance(reply, Exception):
            raise reply
        return reply

    def close(self):
        self.closed = True


@pytest.fixture
def rig():
    config = Settings(_env_file=None, robot_bridge_enabled=True,
                      robot_esp_host="esp32.test",
                      robot_pixel_to_mm=(0.5, 0, -10, 0, -0.5, 200))
    state, sock, calls, clock = FakeState(), FakeSocket(), [], [0.0]

    def connect(address, timeout):
        calls.append((address, timeout))
        return sock

    bridge = robot_bridge.RobotBridge(config, state, connect, lambda: clock[0])
    yield bridge, state, sock, calls, clock
    bridge.stop()


@pytest.mark.parametrize("color,flags", [
    ("HIJAU", (1, 0, 0)), ("MERAH", (0, 1, 0)), ("KUNING", (0, 0, 1)),
])
def test_xy_from_yolo_reaches_robot_in_exact_wire_format(rig, color, flags):
    bridge, state, sock, calls, _ = rig
    det = detection(color, x=201, y=123)
    assert (det["x"], det["y"]) == (201, 123)
    assert det["center"] == {"x": 201, "y": 123}
    state.data["detections"] = [det]
    bridge.step()
    assert json.loads(sock.sent[0]) == {
        "x": 90.5, "y": 138.5, "G": flags[0], "R": flags[1], "Y": flags[2],
    }
    assert sock.sent[0].endswith(b"\n") and sock.sent[0].count(b"\n") == 1
    assert calls == [(("esp32.test", 5000), 1.0)]
    assert bridge.snapshot()["state"] == "streaming"
    assert bridge.snapshot()["acknowledged_frames"] == 1


def test_fresh_target_repeats_enough_times_for_firmware_stability_gate(rig):
    bridge, _, sock, calls, clock = rig
    for i in range(10):
        clock[0] = i / 10
        bridge.step()
    assert len(sock.sent) == 10
    assert len(set(sock.sent)) == 1
    assert len(calls) == 1  # one persistent TCP connection
    assert bridge.snapshot()["acknowledged_frames"] == 10


def test_highest_confidence_inside_pick_zone_is_selected(rig):
    bridge, state, sock, _, _ = rig
    state.data["detections"] = [
        detection("RED", 99, 10, 10),
        detection("GREEN", 85),
        detection("YELLOW", 97, 200, 200),
    ]
    bridge.step()
    assert json.loads(sock.sent[0]) == {"x": 90, "y": 100, "G": 0, "R": 0, "Y": 1}


@pytest.mark.parametrize("changes,reason", [
    ({"camera_live": False}, "camera_not_live"),
    ({"model_loaded": False}, "model_not_ready"),
    ({"age_s": 2}, "stale_frame"),
    ({"age_s": None}, "stale_frame"),
    ({"age_s": float("nan")}, "stale_frame"),
    ({"detections": []}, "no_object"),
    ({"detections": [detection(confidence=40)]}, "no_object"),
    ({"detections": [detection(x=10, y=10)]}, "no_object"),
    ({"detections": [detection("OTHER")]}, "no_object"),
    ({"frame": {"width": 0, "height": 480}}, "invalid_detection"),
])
def test_empty_or_invalid_observation_sends_found_false(rig, changes, reason):
    bridge, state, sock, _, _ = rig
    state.data.update(changes)
    bridge.step()
    assert sock.sent == [b'{"found":false}\n']
    assert bridge.snapshot()["state"] == reason


@pytest.mark.parametrize("replies", [
    [b"O", b"K\n"], [b"OK"], [b"\r\n", b"O", b"K\r\n"], [b"OK", b"\n", b"OK\n"],
])
def test_ack_handles_tcp_fragmentation_and_optional_newline(rig, replies):
    bridge, _, sock, _, _ = rig
    sock.replies.extend(replies)
    bridge.step()
    bridge.step()
    assert bridge.snapshot()["acknowledged_frames"] == 2


def test_rejected_packet_does_not_count_as_acknowledged(rig):
    bridge, _, sock, _, _ = rig
    sock.replies.append(b"ERROR\n")
    bridge.step()
    assert bridge.snapshot()["state"] == "robot_rejected"
    assert bridge.snapshot()["acknowledged_frames"] == 0


@pytest.mark.parametrize("reply", [b"", TimeoutError("timeout"), b"x" * 128])
def test_socket_failure_closes_connection_and_schedules_retry(rig, reply):
    bridge, _, sock, calls, clock = rig
    sock.replies.append(reply)
    bridge.step()
    assert sock.closed
    assert bridge.snapshot()["state"] == "connection_error"
    assert not bridge.snapshot()["connected"]
    clock[0] = 1.9
    bridge.step()
    assert len(calls) == 1


def test_reconnect_reads_newest_observation_instead_of_replaying_target(rig):
    bridge, state, sock, _, clock = rig
    sock.replies.append(TimeoutError())
    bridge.step()
    replacement = FakeSocket()

    def reconnect(address, timeout):
        state.data["detections"] = []  # disappears while connect is pending
        return replacement

    bridge.connector = reconnect
    clock[0] = 2
    bridge.step()
    assert replacement.sent == [b'{"found":false}\n']
    assert bridge.snapshot()["state"] == "no_object"


def test_connection_failure_backs_off_without_blocking(rig):
    bridge, _, _, calls, clock = rig

    def failure(address, timeout):
        calls.append(address)
        raise OSError("unreachable")

    bridge.connector = failure
    bridge.step()
    clock[0] = 1
    bridge.step()
    assert len(calls) == 1
    clock[0] = 2
    bridge.step()
    assert len(calls) == 2


@pytest.mark.parametrize("changes,reason", [
    ({"robot_bridge_enabled": False}, "disabled"),
    ({"robot_esp_host": ""}, "configuration_error"),
    ({"robot_pixel_to_mm": None}, "configuration_error"),
    ({"robot_pixel_to_mm": (0, 0, 0, 0, 0, 0)}, "configuration_error"),
    ({"robot_pixel_to_mm": (float("nan"), 0, 0, 0, 1, 0)}, "configuration_error"),
    ({"pick_zone_x_min": 0.9, "pick_zone_x_max": 0.1}, "configuration_error"),
])
def test_unconfigured_bridge_does_not_connect(rig, changes, reason):
    existing, state, _, calls, _ = rig
    config = existing.config.model_copy(update=changes)
    bridge = robot_bridge.RobotBridge(config, state, existing.connector)
    bridge.step()
    assert calls == []
    assert bridge.snapshot()["state"] == reason


def test_affine_calibration_supports_axis_swap_rotation_and_offsets():
    payload = robot_bridge.build_robot_target(
        detection(x=200, y=100), {"width": 640, "height": 480},
        (0, -0.5, 150, 0.5, 0, -20),
    )
    assert (payload["x"], payload["y"]) == (100, 80)


def test_worker_runs_stream_and_closes_socket(rig):
    bridge, _, sock, _, _ = rig
    original_recv = sock.recv

    def receive(size):
        if len(sock.sent) >= 7:
            bridge._stop_event.set()
        return original_recv(size)

    sock.recv = receive
    try:
        bridge.start()
        bridge.join(timeout=3)
        assert not bridge.is_alive()
        assert len(sock.sent) == 7
        assert sock.closed
    finally:
        bridge.stop()


def test_runtime_snapshot_requires_live_capture_and_is_isolated():
    state = runtime.RuntimeState()
    state.update_model(True, {0: "GREEN", 1: "RED", 2: "YELLOW"}, "fake.pt")
    state.update_camera(False, "simulator", 15, 640, 480, "fake")
    state.publish(640, 480, b"raw", b"annotated", [detection()], 10)
    state.update_camera(True, "live", 15, 640, 480, "fake")
    assert not state.snapshot_robot_input()["camera_live"]  # cached simulator result
    state.publish(640, 480, b"raw", b"annotated", [detection()], 10)
    snap = state.snapshot_robot_input()
    assert snap["camera_live"] and snap["model_loaded"] and snap["age_s"] >= 0
    snap["detections"][0]["center"]["x"] = -1
    assert state.snapshot_robot_input()["detections"][0]["center"]["x"] == 320


def test_detection_endpoint_exposes_xy_and_status_does_not_connect(monkeypatch):
    import main

    state = runtime.RuntimeState()
    state.update_model(True, {0: "GREEN"}, "fake.pt")
    state.publish(640, 480, b"raw", b"annotated", [detection()], 10)
    monkeypatch.setattr(runtime, "state", state)
    result = main.detections_latest()
    assert result["detections"][0]["x"] == 320
    assert result["detections"][0]["y"] == 240
    assert main.robot_status()["state"] == "not_started"


def test_robot_frame_age_includes_inference_time(monkeypatch, rig):
    bridge, _, _, _, _ = rig
    monkeypatch.setattr(runtime.time, "monotonic", lambda: 10.0)
    state = runtime.RuntimeState()
    state.update_model(True, {0: "GREEN"}, "fake.pt")
    state.update_camera(True, "live", 20, 640, 480, "fake")
    state.publish(640, 480, b"raw", b"annotated", [detection()], 1500,
                  captured_at=8.5, camera_mode="live")
    snap = state.snapshot_robot_input()
    assert snap["age_s"] == 1.5
    assert bridge._packet(snap) == ({"found": False}, "stale_frame")


def test_simulator_frame_cannot_become_live_during_inference(rig):
    bridge, _, _, _, _ = rig
    state = runtime.RuntimeState()
    state.update_model(True, {0: "GREEN"}, "fake.pt")
    state.update_camera(True, "live", 20, 640, 480, "fake")
    state.publish(640, 480, b"raw", b"annotated", [detection()], 20,
                  camera_mode="simulator")
    assert bridge._packet(state.snapshot_robot_input()) == ({"found": False}, "camera_not_live")


def test_capture_snapshot_keeps_frame_sequence_time_and_mode_together(monkeypatch):
    import stream
    import numpy as np
    source = stream.CameraSource()
    monkeypatch.setattr(stream.time, "monotonic", lambda: 12.0)
    source._mode = "live"
    source._push(np.zeros((10, 10, 3), dtype=np.uint8))
    seq, frame, captured_at, mode = source.latest_sample()
    assert (seq, captured_at, mode) == (1, 12.0, "live")
    frame[0, 0, 0] = 255
    assert source.latest_frame()[0, 0, 0] == 0
