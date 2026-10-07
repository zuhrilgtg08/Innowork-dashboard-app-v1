"""Detect YOLO -> log x,y di terminal -> kirim ke ESP32 robot via TCP.

Alur per tick (default tiap 0.5 detik):
1. Ambil 1 frame dari kamera (webcam / RTSP / video / gambar).
2. YOLO best.pt (vision_model) -> pilih box confidence tertinggi.
3. Print ke terminal: x, y + payload JSON.
4. Kirim payload ke ESP32 (JSON 1 baris + '\\n', ESP32 jawab "OK").

Contoh:
    python detect_esp32.py                              # webcam 0, ESP32 default
    python detect_esp32.py --esp32-ip 192.168.67.174    # IP ESP32 robot
    python detect_esp32.py --source 0 --no-show         # headless / SSH
    python detect_esp32.py --source video.mp4 --no-send  # tes tanpa ESP32
"""

import argparse
import json
import os
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

import cv2

from vision_model import (
    SortingModelError,
    assert_sorting_classes,
    build_detection,
    build_icam_payload,
    english_name,
    primary_detection,
    resolve_sorting_model,
    send_to_esp32,
)

DEFAULT_ESP32_IP = (
    os.getenv("ESP32_IP") or os.getenv("ROBOT_ESP_HOST") or "192.168.100.77"
)
DEFAULT_ESP32_PORT = int(os.getenv("ESP32_PORT") or os.getenv("ROBOT_ESP_PORT") or 5000)


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--source", default="0",
                   help="0=webcam, RTSP URL, path video/gambar (default: 0)")
    p.add_argument("--esp32-ip", default=DEFAULT_ESP32_IP,
                   help=f"IP ESP32 robot (default: {DEFAULT_ESP32_IP})")
    p.add_argument("--esp32-port", type=int, default=DEFAULT_ESP32_PORT,
                   help=f"Port TCP ESP32 (default: {DEFAULT_ESP32_PORT})")
    p.add_argument("--conf", type=float, default=0.60,
                   help="Threshold YOLO 0-1 (default: 0.60)")
    p.add_argument("--interval", type=float, default=0.5,
                   help="Jeda antar kirim/detik (default: 0.5)")
    p.add_argument("--no-send", action="store_true",
                   help="Hanya log terminal, jangan kirim ke ESP32")
    p.add_argument("--no-show", action="store_true",
                   help="Jangan buka window preview (headless)")
    p.add_argument("--frames", type=int, default=0,
                   help="Berhenti setelah N tick (0 = sampai 'q', default: 0)")
    return p.parse_args()


def open_source(source: str):
    still = None
    if Path(source).suffix.lower() in {".jpg", ".jpeg", ".png"} and Path(source).is_file():
        still = cv2.imread(source)
        if still is None:
            raise RuntimeError(f"tidak bisa baca gambar: {source}")
        return still
    cap = cv2.VideoCapture(int(source) if source.isdigit() else source)
    if not cap.isOpened():
        raise RuntimeError(
            f"kamera tidak kebuka: {source} "
            "(pakai --source 0 untuk webcam / URL RTSP / path video)"
        )
    return cap


def detect_frame(model, frame, conf: float) -> list:
    """YOLO 1 frame -> list canonical detection (vision_model.build_detection)."""
    h, w = frame.shape[:2]
    names = {int(k): str(v) for k, v in dict(model.names).items()}
    out = []
    for r in model.predict(source=frame, conf=conf, verbose=False):
        for b in r.boxes:
            cls_id = int(b.cls[0])
            raw = names.get(cls_id, str(cls_id))
            x1, y1, x2, y2 = [float(v) for v in b.xyxy[0].tolist()]
            out.append(build_detection(
                class_id=cls_id, class_name_raw=raw,
                confidence=float(b.conf[0]) * 100.0,
                x1=x1, y1=y1, x2=x2, y2=y2,
                frame_width=w, frame_height=h,
            ))
    return out


def main() -> None:
    args = parse_args()

    try:
        model_path = resolve_sorting_model()
        normalized = assert_sorting_classes(model_path)
        print(f"[model] {model_path} -> {normalized}")
    except SortingModelError as exc:
        print(f"[ERROR] model: {exc}")
        sys.exit(1)

    import infer  # lazy: butuh ultralytics hanya saat inferensi beneran
    model = infer._load(model_path)

    print(f"[esp32] target {args.esp32_ip}:{args.esp32_port}"
          + (" (SEND MATI --no-send)" if args.no_send else ""))
    print("[info] tick: detect -> terminal log x,y -> kirim ESP32 "
          "(tekan 'q' di window / Ctrl+C untuk stop)")

    src = open_source(args.source)
    still = src if not hasattr(src, "read") else None
    cap = None if still is not None else src

    n = 0
    try:
        while True:
            if still is not None:
                frame = still
            else:
                ok, frame = cap.read()
                if not ok:
                    print("[WARN] frame kosong, retry...")
                    time.sleep(0.2)
                    continue

            n += 1
            dets = detect_frame(model, frame, args.conf)
            top = primary_detection(dets)
            payload = build_icam_payload(top)

            # ---- terminal log: x, y + payload ----
            if top is None:
                print(f"[{n}] TIDAK ADA OBYEK | payload={json.dumps(payload)}")
            else:
                print(f"[{n}] {top['class_name']} conf={top['confidence']}% "
                      f"| x={payload.get('x')} y={payload.get('y')} "
                      f"| G={payload.get('G')} R={payload.get('R')} Y={payload.get('Y')} "
                      f"| payload={json.dumps(payload)}")

            # ---- setelah detect langsung kirim ke ESP32 ----
            if not args.no_send:
                ok_send = send_to_esp32(payload, args.esp32_ip, args.esp32_port)
                print(f"     -> ESP32 {args.esp32_ip}:{args.esp32_port}: "
                      f"{'OK' if ok_send else 'GAGAL (cek IP/kabel/WiFi)'}")

            if not args.no_show:
                view = frame.copy()
                for d in dets:
                    b = d["bbox"]
                    cv2.rectangle(view, (b["x1"], b["y1"]), (b["x2"], b["y2"]), (0, 255, 0), 2)
                    cv2.putText(view, f"{english_name(d['class_name_raw'])} {d['confidence']}%",
                                (b["x1"], max(0, b["y1"] - 6)),
                                cv2.FONT_HERSHEY_SIMPLEX, 0.6, (0, 255, 0), 2)
                cv2.putText(view, f"x={payload.get('x', 0)} y={payload.get('y', 0)}",
                            (10, 30), cv2.FONT_HERSHEY_SIMPLEX, 0.8, (255, 255, 255), 2)
                cv2.imshow("detect -> ESP32 (q = keluar)", view)
                if cv2.waitKey(1) & 0xFF == ord("q"):
                    break

            if still is not None:
                break
            if args.frames and n >= args.frames:
                break
            time.sleep(max(0.0, args.interval))
    except KeyboardInterrupt:
        print("\ndistop (Ctrl+C)")
    finally:
        if cap is not None:
            cap.release()
        if not args.no_show:
            cv2.destroyAllWindows()
        print("selesai.")


if __name__ == "__main__":
    main()
