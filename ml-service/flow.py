"""Conveyor off-flow / jam detection from the video stream.

Pure computer vision, independent of the YOLO QC model. Two signals per frame:

  - occupancy: edge density (Canny) — a proxy for "material present". An empty
                belt is smooth (few edges); boxes/labels are textured (many
                edges). Unlike background subtraction this does NOT decay when
                material sits still, so a stalled pile stays "occupied".
  - motion:    fraction of pixels that changed vs. the previous frame — how much
                the belt content is actually moving.

A short rolling window smooths both, then thresholds flag anomalies:

  - "jam"      : occupancy high but motion near zero — material piled up, not
                 advancing.
  - "off_flow" : occupancy near zero — nothing on the belt (item fell off / feed
                 stopped) while the line is meant to be running.

Thresholds are camera-dependent estimates; tune them to the real belt, or defer
to a physical photoelectric/IR sensor over MQTT when available.
"""
from __future__ import annotations

from collections import deque

import cv2
import numpy as np


class FlowAnalyzer:
    def __init__(
        self,
        window: int = 15,
        jam_occupancy: float = 0.04,
        jam_motion: float = 0.01,
        offflow_occupancy: float = 0.008,
        cooldown_frames: int = 45,
    ) -> None:
        self._occ = deque(maxlen=window)
        self._mot = deque(maxlen=window)
        self._prev_gray: np.ndarray | None = None
        self.jam_occupancy = jam_occupancy
        self.jam_motion = jam_motion
        self.offflow_occupancy = offflow_occupancy
        self._cooldown_frames = cooldown_frames
        self._cooldown = 0

    def analyze(self, frame: np.ndarray) -> dict:
        """Feed one BGR frame; return {occupancy, motion, event}.

        `event` is None, "jam", or "off_flow". A cooldown prevents the same
        anomaly firing every frame once raised.
        """
        gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
        gray = cv2.GaussianBlur(gray, (5, 5), 0)
        area = float(gray.shape[0] * gray.shape[1]) or 1.0

        # Occupancy: edge density (material present, motion-independent).
        edges = cv2.Canny(gray, 50, 150)
        occupancy = float(np.count_nonzero(edges)) / area

        # Motion: changed-pixel ratio vs. the previous frame.
        if self._prev_gray is not None and self._prev_gray.shape == gray.shape:
            diff = cv2.absdiff(gray, self._prev_gray)
            _, diff = cv2.threshold(diff, 25, 255, cv2.THRESH_BINARY)
            motion = float(np.count_nonzero(diff)) / area
        else:
            motion = 0.0
        self._prev_gray = gray

        self._occ.append(occupancy)
        self._mot.append(motion)

        avg_occ = sum(self._occ) / len(self._occ)
        avg_mot = sum(self._mot) / len(self._mot)

        event = None
        if self._cooldown > 0:
            self._cooldown -= 1
        elif len(self._occ) >= self._occ.maxlen:
            # Only judge once the window is full (avoids startup false positives).
            if avg_occ <= self.offflow_occupancy:
                event = "off_flow"
            elif avg_occ >= self.jam_occupancy and avg_mot <= self.jam_motion:
                event = "jam"
            if event is not None:
                self._cooldown = self._cooldown_frames

        return {
            "occupancy": round(avg_occ, 4),
            "motion": round(avg_mot, 4),
            "event": event,
        }
