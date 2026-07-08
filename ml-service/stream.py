"""Live camera source for the ICAM-300 integration.

Pulls frames from the camera's RTSP stream (rtsp://<ip>:8550/video). When no
RTSP URL is configured, or the stream is unreachable, it degrades to a
simulator source (a looped sample video, a local webcam, or synthesized
frames) so the whole pipeline is testable without the hardware.

A single background thread keeps a "latest frame" buffer that both the MJPEG
endpoint and the periodic inference loop read from.
"""
import threading
import time
from pathlib import Path

import cv2
import numpy as np

from config import settings

BASE_DIR = Path(__file__).parent


class CameraSource:
    """Thread-safe holder of the most recent frame from the active source."""

    def __init__(self):
        self._lock = threading.Lock()
        self._frame = None          # latest BGR frame (numpy array)
        self._running = False
        self._thread = None
        self._connected = False     # True when a real capture is delivering
        self._mode = "offline"      # 'live' | 'simulator' | 'offline'
        self._fps = 0.0

    # -- lifecycle ---------------------------------------------------------
    def start(self):
        if self._running:
            return
        self._running = True
        self._thread = threading.Thread(target=self._loop, daemon=True)
        self._thread.start()

    def stop(self):
        self._running = False
        if self._thread:
            self._thread.join(timeout=2)

    # -- accessors ---------------------------------------------------------
    def latest_frame(self):
        with self._lock:
            return None if self._frame is None else self._frame.copy()

    def latest_jpeg(self, quality: int = 80):
        frame = self.latest_frame()
        if frame is None:
            frame = self._placeholder("Menunggu sumber kamera…")
        ok, buf = cv2.imencode(".jpg", frame, [cv2.IMWRITE_JPEG_QUALITY, quality])
        return buf.tobytes() if ok else None

    def status(self) -> dict:
        return {
            "connected": self._connected,
            "mode": self._mode,
            "source": settings.icam_rtsp_url or settings.icam_sim_source,
            "fps": round(self._fps, 1),
        }

    # -- internals ---------------------------------------------------------
    def _open_primary(self):
        """Try the real RTSP stream first; return (cap, mode) or (None, ...)."""
        if settings.icam_rtsp_url:
            cap = cv2.VideoCapture(settings.icam_rtsp_url, cv2.CAP_FFMPEG)
            if cap.isOpened():
                return cap, "live"
            cap.release()
        return None, None

    def _open_simulator(self):
        """Open the fallback source (webcam index or looped video file)."""
        src = settings.icam_sim_source
        if src.isdigit():
            cap = cv2.VideoCapture(int(src))
            if cap.isOpened():
                return cap
            cap.release()
            return None
        path = src if Path(src).is_absolute() else str(BASE_DIR / src)
        if Path(path).exists():
            cap = cv2.VideoCapture(path)
            if cap.isOpened():
                return cap
            cap.release()
        return None

    def _loop(self):
        """Continuously fill the frame buffer, reconnecting as needed."""
        last = time.time()
        while self._running:
            cap, mode = self._open_primary()
            if cap is None:
                cap = self._open_simulator()
                mode = "simulator" if cap is not None else "offline"

            if cap is None:
                # No source at all — emit a synthetic conveyor frame so the
                # UI still shows something and inference has an input.
                self._mode = "simulator"
                self._connected = False
                self._push(self._synthetic())
                time.sleep(0.1)
                continue

            self._mode = mode
            self._connected = (mode == "live")
            while self._running and cap.isOpened():
                ok, frame = cap.read()
                if not ok:
                    # End of a looped video file → rewind; RTSP drop → break to reconnect.
                    if mode == "simulator" and not settings.icam_sim_source.isdigit():
                        cap.set(cv2.CAP_PROP_POS_FRAMES, 0)
                        continue
                    break
                self._push(frame)
                now = time.time()
                dt = now - last
                if dt > 0:
                    self._fps = 0.8 * self._fps + 0.2 * (1.0 / dt)
                last = now
                # Cap the buffer refresh ~20fps; MJPEG/infer read independently.
                time.sleep(0.05)
            cap.release()
            self._connected = False
            time.sleep(1)  # brief backoff before reconnecting

    def _push(self, frame):
        with self._lock:
            self._frame = frame

    @staticmethod
    def _synthetic():
        """A moving box on a conveyor-like backdrop (no hardware/sample)."""
        h, w = 480, 640
        img = np.full((h, w, 3), 40, dtype=np.uint8)
        cv2.putText(img, "ICAM-300 SIMULATOR", (120, 40),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.8, (0, 200, 255), 2)
        # Conveyor band
        cv2.rectangle(img, (0, 200), (w, 320), (70, 70, 70), -1)
        # A "product" sliding left→right based on wall clock
        x = int((time.time() * 120) % (w + 120)) - 60
        cv2.rectangle(img, (x, 225), (x + 90, 295), (200, 200, 210), -1)
        cv2.rectangle(img, (x, 225), (x + 90, 295), (120, 120, 130), 2)
        return img

    @staticmethod
    def _placeholder(text: str):
        img = np.full((480, 640, 3), 30, dtype=np.uint8)
        cv2.putText(img, text, (60, 240), cv2.FONT_HERSHEY_SIMPLEX,
                    0.7, (180, 180, 180), 2)
        return img


# Module-level singleton shared by the MJPEG endpoint and the infer loop.
camera_source = CameraSource()
