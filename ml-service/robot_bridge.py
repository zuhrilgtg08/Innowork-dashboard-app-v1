"""YOLO -> ESP32 using the supplied ICAMXYClient TCP streaming protocol.

Each frame is one JSON line: {x,y,G,R,Y}, or {found:false}. The ESP32
responds OK and handles stability/pick triggering (>6 stable frames).
Camera coordinates are pixels; transmitted coordinates are calibrated mm.
"""
import json
import math
import socket
import threading
import time


COLOR_FLAGS = {"GREEN": (1, 0, 0), "RED": (0, 1, 0), "YELLOW": (0, 0, 1)}


def pixel_center(det: dict, frame: dict) -> tuple[float, float, float, float]:
    """Validate raw pixels before normalization; never clamp invalid input."""
    x, y = float(det["center"]["x"]), float(det["center"]["y"])
    w, h = float(frame["width"]), float(frame["height"])
    if not all(math.isfinite(v) for v in (x, y, w, h)) or w <= 0 or h <= 0:
        raise ValueError("invalid_geometry")
    if not (0 <= x < w and 0 <= y < h):
        raise ValueError("center_outside_frame")
    return x, y, x / w, y / h


def build_robot_target(det: dict, frame: dict, transform: tuple) -> dict:
    """Exactly the robot client's five fields; x/y in mm, uppercase colors."""
    x, y, _, _ = pixel_center(det, frame)
    a, b, c, d, e, f = transform
    rx, ry = a * x + b * y + c, d * x + e * y + f
    if not (math.isfinite(rx) and math.isfinite(ry)):
        raise ValueError("invalid_target")
    g, r, yellow = COLOR_FLAGS[det["class_name"]]
    return {"x": round(rx, 2), "y": round(ry, 2), "G": g, "R": r, "Y": yellow}


class RobotBridge(threading.Thread):
    def __init__(self, config, runtime_state, connector=socket.create_connection,
                 clock=time.monotonic):
        super().__init__(daemon=True, name="yolo-robot-bridge")
        self.config, self.state = config, runtime_state
        self.connector, self.clock = connector, clock
        self._stop_event = threading.Event()
        self._lock = threading.Lock()
        self._status = {"enabled": config.robot_bridge_enabled, "state": "disabled",
                        "transport": "tcp", "coordinate_unit": "mm", "connected": False,
                        "last_payload": None, "last_response": None,
                        "acknowledged_frames": 0, "error": None}
        self._sock = None
        self._received = b""
        self._retry_at = 0.0
        self._configured = False
        if config.robot_bridge_enabled:
            error = None
            transform = config.robot_pixel_to_mm
            if not config.robot_esp_host.strip():
                error = "robot_esp_host_required"
            elif transform is None:
                error = "pixel_to_mm_calibration_required"
            elif not all(math.isfinite(v) for v in transform):
                error = "invalid_calibration"
            elif abs(transform[0] * transform[4] - transform[1] * transform[3]) < 1e-12:
                error = "invalid_calibration"
            elif not (0 <= config.pick_zone_x_min < config.pick_zone_x_max <= 1
                      and 0 <= config.pick_zone_y_min < config.pick_zone_y_max <= 1):
                error = "invalid_pick_zone"
            if error:
                self._update("configuration_error", error=error)
            else:
                self._configured = True
                self._update("waiting_for_connection")

    def _update(self, state: str, **values) -> None:
        with self._lock:
            self._status.update(state=state, **values)

    def snapshot(self) -> dict:
        with self._lock:
            return {**self._status, "last_payload": dict(self._status["last_payload"])
                    if self._status["last_payload"] is not None else None}

    def _packet(self, snap: dict) -> tuple[dict, str]:
        """Empty/stale/offline observations explicitly clear firmware presence."""
        empty = {"found": False}
        if not snap["camera_live"]:
            return empty, "camera_not_live"
        if not snap["model_loaded"]:
            return empty, "model_not_ready"
        if (snap["age_s"] is None or not math.isfinite(snap["age_s"])
                or not 0 <= snap["age_s"] <= self.config.robot_max_frame_age_s):
            return empty, "stale_frame"
        candidates = []
        try:
            for det in snap["detections"]:
                _, _, u, v = pixel_center(det, snap["frame"])
                conf = float(det["confidence"])
                if (det.get("class_name") in COLOR_FLAGS and math.isfinite(conf)
                        and self.config.robot_min_confidence * 100 <= conf <= 100
                        and self.config.pick_zone_x_min <= u <= self.config.pick_zone_x_max
                        and self.config.pick_zone_y_min <= v <= self.config.pick_zone_y_max):
                    candidates.append(det)
            if not candidates:
                return empty, "no_object"
            det = max(candidates, key=lambda d: float(d["confidence"]))
            return build_robot_target(det, snap["frame"], self.config.robot_pixel_to_mm), "streaming"
        except (KeyError, TypeError, ValueError, OverflowError):
            return empty, "invalid_detection"

    def _read_response(self) -> str:
        """Handle a split OK and both bare-OK and newline-terminated replies."""
        deadline = self.clock() + self.config.robot_socket_timeout_s
        while True:
            if b"\n" in self._received:
                line, self._received = self._received.split(b"\n", 1)
                if line.strip():
                    return line.decode("utf-8", errors="replace").strip()
            elif self._received.strip() == b"OK":
                self._received = b""
                return "OK"
            else:
                remaining = deadline - self.clock()
                if remaining <= 0 or len(self._received) >= 128:
                    raise OSError("invalid_or_timed_out_response")
                self._sock.settimeout(remaining)
                chunk = self._sock.recv(128 - len(self._received))
                if not chunk:
                    raise OSError("robot_disconnected")
                self._received += chunk

    def _disconnect(self) -> None:
        sock, self._sock = self._sock, None
        self._received = b""
        if sock is not None:
            try:
                sock.close()
            except OSError:
                pass

    def step(self) -> None:
        if not self._configured or self._stop_event.is_set():
            return
        if self._sock is None:
            if self.clock() < self._retry_at:
                return
            try:
                self._sock = self.connector(
                    (self.config.robot_esp_host, self.config.robot_esp_port),
                    timeout=self.config.robot_socket_timeout_s,
                )
            except OSError:
                self._retry_at = self.clock() + self.config.robot_reconnect_interval_s
                self._update("connection_error", connected=False, error="tcp_connect_failed")
                return

        # Read AFTER connecting: never replay a target captured before a slow
        # connection/reconnect. Repetition of fresh snapshots is intentional:
        # the supplied firmware requires >6 stable frames before picking.
        packet, state = self._packet(self.state.snapshot_robot_input())
        if self._stop_event.is_set():
            return
        try:
            self._sock.settimeout(self.config.robot_socket_timeout_s)
            message = json.dumps(packet, separators=(",", ":"), allow_nan=False) + "\n"
            self._sock.sendall(message.encode("utf-8"))
            response = self._read_response()
            if response != "OK":
                self._update("robot_rejected", connected=True, last_payload=packet,
                             last_response=response, error="expected_OK")
                return
            with self._lock:
                self._status["acknowledged_frames"] += 1
            self._update(state, connected=True, last_payload=packet,
                         last_response=response, error=None)
        except OSError:
            self._disconnect()
            self._retry_at = self.clock() + self.config.robot_reconnect_interval_s
            self._update("connection_error", connected=False, last_response=None,
                         error="tcp_send_or_receive_failed")

    def run(self) -> None:
        if not self._configured:
            return
        try:
            while not self._stop_event.is_set():
                started = self.clock()
                try:
                    self.step()
                except Exception:
                    self._update("bridge_error", connected=False, error="unexpected_bridge_error")
                    break
                self._stop_event.wait(max(0, 1 / self.config.robot_stream_hz - (self.clock() - started)))
        finally:
            self._disconnect()
            with self._lock:
                self._status["connected"] = False

    def stop(self) -> None:
        self._stop_event.set()
        if self.is_alive():
            self.join(timeout=self.config.robot_socket_timeout_s * 2 + 1)
        else:
            self._disconnect()
