"""QR code decoding using OpenCV's built-in detector.

Uses cv2.QRCodeDetector so there is no native dependency (zbar) to build on
Windows — opencv-python is already a requirement. detectAndDecodeMulti finds
several QR codes in one frame (multiple boxes on the conveyor); we fall back to
the single-code detector for older OpenCV builds or awkward angles.
"""
from __future__ import annotations

from pathlib import Path

import cv2
import numpy as np

_detector = cv2.QRCodeDetector()


def _as_bgr(image: "str | Path | np.ndarray") -> np.ndarray | None:
    """Accept a file path or an already-decoded BGR frame."""
    if isinstance(image, (str, Path)):
        return cv2.imread(str(image))
    return image


def decode_qr_values(image: "str | Path | np.ndarray") -> list[str]:
    """Return every distinct non-empty QR string found in the image.

    Order is preserved; duplicates (the same code detected twice) are dropped.
    """
    img = _as_bgr(image)
    if img is None:
        return []

    values: list[str] = []

    # Multi-code first (OpenCV >= 4.5.3). Some builds raise cv2.error on frames
    # with no QR — treat that as "nothing found".
    try:
        ok, decoded, _points, _straight = _detector.detectAndDecodeMulti(img)
        if ok and decoded is not None:
            values.extend(d for d in decoded if d)
    except cv2.error:
        pass

    # Fallback: single-code detector.
    if not values:
        try:
            decoded, _points, _straight = _detector.detectAndDecode(img)
            if decoded:
                values.append(decoded)
        except cv2.error:
            pass

    seen: set[str] = set()
    out: list[str] = []
    for v in values:
        if v not in seen:
            seen.add(v)
            out.append(v)
    return out


def decode_qr(image: "str | Path | np.ndarray") -> str | None:
    """Return the first QR string found, or None."""
    values = decode_qr_values(image)
    return values[0] if values else None
