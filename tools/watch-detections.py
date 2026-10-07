"""Show real detections from the local YOLO service. No robot commands."""

import argparse
from datetime import datetime
import json
import math
import os
from pathlib import Path
import time
from urllib.error import HTTPError, URLError
from urllib.request import urlopen


def default_service_url():
    env = Path(__file__).resolve().parent.parent / ".env"
    if env.is_file():
        for line in env.read_text(encoding="utf-8-sig").splitlines():
            if line.strip().startswith("ML_SERVICE_URL="):
                value = line.split("=", 1)[1].strip().strip(chr(34)).strip(chr(39))
                if value:
                    return value
    return "http://127.0.0.1:8001"


def read_json(url):
    with urlopen(url, timeout=4) as response:
        return json.load(response)


def detection_line(detection):
    center = detection.get("center", {})
    return (
        f"{detection.get('class_name', '?'):<10} "
        f"confidence={float(detection.get('confidence', 0)):5.1f}%  "
        f"X={center.get('x', '?')}  Y={center.get('y', '?')}  px"
    )


def robot_lines(status):
    """Display the bridge's actual last payload/ACK; never send a command."""
    state = status.get("state", "unknown")
    lines = [f"ROBOT: {state}" + (f" ({status['error']})" if status.get("error") else "")]
    if state in ("streaming", "no_object", "camera_not_live", "stale_frame", "model_not_ready", "invalid_detection", "robot_rejected"):
        payload = status.get("last_payload")
        if payload is not None:
            unit = " | x/y dalam mm" if "x" in payload else ""
            lines.append("[ICAM -> ESP32] " + json.dumps(payload, separators=(",", ":")) + unit)
        if status.get("last_response"):
            lines.append(f"[ESP32 -> ICAM] {status['last_response']} | ACK={status.get('acknowledged_frames', 0)} (penerimaan pesan)")
    return lines


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", default=default_service_url())
    parser.add_argument("--interval", type=float, default=1.0)
    parser.add_argument("--once", action="store_true")
    parser.add_argument("--robot", action="store_true", help="Tampilkan payload mm dan balasan dari /robot/status")
    args = parser.parse_args()
    if not math.isfinite(args.interval) or args.interval <= 0:
        parser.error("--interval harus lebih besar dari 0")
    if os.name == "nt":
        import ctypes
        ctypes.windll.kernel32.SetConsoleTitleW("YOLOv11 - Deteksi dan koordinat X/Y")

    base = args.url.rstrip("/")
    print("YOLOv11 | HASIL DETEKSI DAN KOORDINAT X/Y", flush=True)
    print(f"Sumber: {base}/detections/latest", flush=True)
    print("X/Y = pusat objek dalam piksel. Ctrl+C untuk berhenti.\n", flush=True)
    last_frame = None
    last_status = None
    last_print = 0.0
    last_robot = None

    while True:
        lines = []
        try:
            health = read_json(base + "/health")
            if not health.get("model_loaded"):
                status = "MODEL BELUM SIAP: " + (health.get("diagnostic") or "menunggu model YOLO dimuat")
            elif not health.get("camera_connected"):
                status = "KAMERA OFFLINE: periksa iCAM, jaringan, dan RTSP. X/Y belum tersedia."
            else:
                result = read_json(base + "/detections/latest")
                frame = result.get("timestamp")
                if not frame:
                    status = "Menunggu frame pertama dari YOLO."
                elif frame == last_frame:
                    status = "Menunggu hasil frame baru dari YOLO."
                else:
                    last_frame = frame
                    detections = result.get("detections", [])
                    lines = [detection_line(item) for item in detections]
                    status = "Belum ada objek terdeteksi. X/Y belum tersedia."
        except HTTPError as error:
            status = f"Layanan YOLO mengembalikan HTTP {error.code}."
        except (URLError, OSError, ValueError) as error:
            status = f"LAYANAN YOLO BELUM TERHUBUNG: {error}"

        now = time.monotonic()
        if lines:
            for line in lines:
                print(f"[{datetime.now():%H:%M:%S}] {line}", flush=True)
            last_print = now
            last_status = None
        elif status != last_status or now - last_print >= 10:
            print(f"[{datetime.now():%H:%M:%S}] {status}", flush=True)
            last_status, last_print = status, now
        if args.robot:
            try:
                robot = read_json(base + "/robot/status")
                signature = json.dumps(robot, sort_keys=True)
                if signature != last_robot:
                    for line in robot_lines(robot):
                        print(f"[{datetime.now():%H:%M:%S}] {line}", flush=True)
                    last_robot = signature
            except (URLError, OSError, ValueError):
                if last_robot != "offline":
                    print("Status robot belum tersedia.", flush=True)
                    last_robot = "offline"
        if args.once:
            return
        time.sleep(args.interval)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nMonitor deteksi dihentikan.")
