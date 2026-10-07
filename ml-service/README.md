# Perbaikan deteksi benda jauh — 7 Oktober 2026

Gunakan model yang sudah dikirim; **belum perlu training ulang untuk mencoba perbaikan ini**.
Notebook `Train_arm_yolo1.ipynb` sekarang membuat runtime yang memfokuskan gambar ke matras
sebelum resize ke input ONNX. Model .pt/.onnx tetap sama.

## Hasil pemeriksaan model dan foto

Model diperiksa dari `deploy_arm_colors_20261007T002446Z_18e37b.zip`.
Checksum isi arsip cocok. Manifest menyatakan kedua dataset sudah digabung dengan remapping
GREEN/YELLOW/RED; model YOLO11-seg memiliki input ONNX FP32 statis 512 × 512.
SHA256 `best.onnx`: `30e1449707b40b8ed909745cb2bc70a0fb05f5c60b83f194666aba9d609e09fa`.

`photos4_icam.tar.zip` berisi 252 foto tanpa label YOLO. Pemeriksaan ini menggunakan
**10 foto terbaru tanggal 7 Oktober, 1280 × 960**, masing-masing satu benda yang ditinjau
secara visual. Lebar benda sekitar 70–80 piksel pada foto asli menjadi sekitar 28–32 piksel
pada input model jika seluruh lebar frame diperkecil menjadi 512. Ini konsisten dengan
objek jauh yang lebih sulit terdeteksi, meskipun faktor fokus/pencahayaan juga dapat berpengaruh.

Semua perbandingan memakai bobot yang sama, decoder yang sama, dan threshold 0,60.
ROI untuk eksperimen ditaksir secara visual dari foto; **bukan kalibrasi robot** dan tidak
diisikan otomatis ke konfigurasi pengguna.

| Jalur inferensi | Foto dengan target ditemukan | Prediksi tambahan yang terlihat |
|---|---:|---:|
| Seluruh frame, tanpa ROI (jalur bundle lama) | 8/10 | 2 di luar matras |
| Seluruh frame, luar matras ditutup | 6/10 | 0 |
| Luar matras ditutup + crop matras (default baru) | 10/10 | 0 |
| Crop matras + tiles 640, overlap 25% (opsional) | 10/10 | 0 |

Menghilangkan latar luar saja dapat mengubah confidence dan belum memperbesar objek.
Crop dan tiles diuji terpisah agar efek tersebut terlihat.

| Nama foto JPG | Warna | Full tanpa ROI | Crop matras | Tiled |
|---|---|---:|---:|---:|
| 20261007073251 | RED | 0,612 | 0,813 | 0,932 |
| 20261007073255 | RED | 0,622 | 0,814 | 0,933 |
| 20261007073302 | GREEN | 0,698 | 0,843 | 0,945 |
| 20261007073304 | GREEN | 0,689 | 0,840 | 0,946 |
| 20261007073307 | GREEN | 0,693 | 0,839 | 0,943 |
| 20261007073315 | YELLOW | 0,720 | 0,875 | 0,933 |
| 20261007073319 | YELLOW | 0,691 | 0,876 | 0,933 |
| 20261007073931 | GREEN | 0,736 | 0,806 | 0,948 |
| 20261007073939 | RED | < 0,25 / tidak ditemukan | 0,659 | 0,899 |
| 20261007073945 | YELLOW | 0,583 (tersaring) | 0,857 | 0,941 |

Angka adalah confidence target, **bukan persentase akurasi**. Sampel kecil ini mengandung
frame berurutan yang mirip dan tidak memiliki anotasi polygon untuk menghitung mAP atau
error pusat objek secara formal. Belum mengukur meja kosong, objek bertumpuk, beberapa
warna sekaligus, latency ICAM, atau error XY fisik. Confidence tinggi tidak menjamin
koordinat grasp tepat. Nilai threshold model lama belum dikalibrasi ulang untuk crop/tiled.

## Mencoba runtime tanpa training ulang

1. Ekstrak `icam_runtime_far_patch.zip` ke **folder baru** di ICAM/PC inferensi.
2. Salin `best.onnx` dan `runtime_config.json` dari bundle model yang sama ke folder itu.
   Tidak perlu menyalin .pt untuk runtime ONNX. Biarkan bundle asli sebagai pembanding.
   Dependensi tetap NumPy, OpenCV, ONNX Runtime; live ICAM memakai CAMNavi2 bawaan BSP.
3. Isi `mat_roi.example.json`, simpan sebagai `mat_roi.json`. `frame_size_wh` adalah
   `[lebar, tinggi]` gambar ICAM asli. `points_px` berisi empat sudut matras berurutan
   mengelilingi tepi, misalnya kiri bawah → kanan bawah → kanan atas → kiri atas.
   Ambil angka dari frame kamera aktual dengan dudukan final, bukan foto setup dari ponsel.
   XY robot dan Z belum diperlukan untuk membatasi deteksi di matras.
4. Jalankan pada foto terlebih dahulu:

```bash
python3 icam_color_runtime.py --source meja.jpg --roi mat_roi.json --output terminal
```

Default `roi-crop` menutup luar poligon dengan abu-abu, memotong bounding rectangle matras,
dan menjalankan satu inferensi. Bounding box, contour, dan pusat dikembalikan ke piksel
frame asli sebelum overlay dan mapping. Mask yang menyentuh/melintasi batas matras ditolak
dengan margin 2 piksel; benda hijau di dalam matras tetap diproses. `prediction.jpg`
menampilkan hasil. Belum kalibrasi berarti keluaran U/V piksel, bukan X/Y mm.

Jika benda masih kecil, bandingkan:

```bash
uvicorn main:app --host 127.0.0.1 --port 8001 --reload
```

Check: `curl http://127.0.0.1:8001/health`

## Config (`.env`)

- `LARAVEL_STORAGE_PATH` — absolute path to the Laravel app's `storage/app`
  (the service reads annotation images and writes `models/run-*/best.pt` there).
- `ML_CALLBACK_SECRET` — must match Laravel's `ML_CALLBACK_SECRET`; used to sign
  training progress/complete/fail callbacks.

## Endpoints

- `GET  /health` — liveness + model/camera/inference telemetry
  (`model_loaded`, `camera_connected`, `camera_mode`, `camera_fps`,
  `inference_fps`, `last_inference_ms`). `MODEL_ERROR` is reported honestly
  when best.pt cannot be used.
- `GET  /model/info` — authoritative best.pt path, file size, exact class
  map, device, confidence threshold (503 `MODEL_ERROR` when unusable).
- `POST /train`  — `{run_id, epochs, imgsz, storage_path, callback_url, annotations[]}`;
  returns 202 and trains in the background, POSTing progress back to Laravel.
- `POST /infer`  — multipart `frame` (JPEG) + `conf`, `model_path`, context;
  returns `{status, confidence, boxes}`.
- `GET  /camera/status` — `{connected, mode, source, fps, frame_width, frame_height}`;
  mode is `LIVE`, `SIMULATOR`, or `OFFLINE`; `source` is credential-redacted
  (`rtsp://user:pass@host` ⇒ `rtsp://***:***@host`).
- `GET  /camera/stream` — raw MJPEG from the shared frame cache.
- `GET  /camera/raw` — continuous raw MJPEG preview (no YOLO overlay).
- `GET  /camera/frame` — latest raw JPEG (pollable still).
- `GET  /camera/preview` — continuous annotated MJPEG preview (English
  GREEN/YELLOW/RED overlay), served from the shared inference cache —
  never runs YOLO per request.
- `GET  /camera/preview/frame` — latest annotated JPEG (pollable still).
- `GET  /preview/latest` — latest snapshot (legacy shape, existing clients).
- `GET  /detections/latest` — `{ok, timestamp, frame, detections[]}` with
  class ids, raw + English names, 0–100 confidence, pixel bbox/center and
  clamped 0–1 normalized geometry.
- `GET  /stats/summary` — per-class counts, totals, fps, latency, uptime.
- `GET  /stats/confidence` — recent confidence samples for graphing.
- `GET  /stats/timeline` — detections per time bucket.

## Continuous runtime (no MQTT)

One camera capture connection (`stream.CameraSource`) feeds one inference
worker (`runtime.InferenceWorker`) running the shared best.pt handle. Every
MJPEG/JSON endpoint serves the cached state — no per-request inference, no
per-browser stream, no broker. Monitoring is pull-based; nothing POSTs per
frame and nothing publishes `arm/command`.

- `ICAM_MODEL_PATH` — authoritative `models/run-100/best.pt` covering
  exactly the semantic classes `GREEN`/`YELLOW`/`RED` (currently YOLO11
  segmentation, `{0: GREEN, 1: YELLOW, 2: RED}`, `imgsz=512`; Indonesian raw
  labels and any class-ID order are also accepted). Any mismatch ⇒
  `MODEL_ERROR`, never a silent COCO fallback. Segmentation masks are
  ignored — the UI/API contract stays bounding-box only
  (`class`, `confidence`, `bbox`, `center x/y`).
- `ICAM_AUTO_INFER` / `ICAM_INFER_INTERVAL` — legacy keys, no longer used
  (the worker infers continuously; remove them from your `.env` if present).
- `SORTING_MQTT_ENABLED` — legacy opt-in for the per-request sort-pipeline
  publish path only (requires paho-mqtt installed separately).

## ICAM-300 camera integration

The service can pull the Advantech **ICAM-300** RTSP stream
(`rtsp://<ip>:8550/video`, available when the camera is "playing" at ≥5fps),
re-serve it as browser-friendly MJPEG, and — with auto-infer on — run YOLO on
it every few seconds and POST each verdict to Laravel (`/api/camera/detection`,
HMAC-signed). **No code runs on the camera**; it just streams.

`.env` keys:

- `ICAM_RTSP_URL` — project camera `rtsp://192.168.0.100:8550/video` for
  local integration testing (runtime stays environment-driven; the address
  is never hard-coded in Python source). Leave empty only for local
  development with `ICAM_ALLOW_SIMULATOR=true`. An unreachable source
  reports `camera_connected=false` and `camera_mode=OFFLINE` — never `LIVE`
  and never a silent simulator substitution in production/demo mode.
  (Known raw/default preview context `192.168.0.100:5001` is not assumed to
  be the YOLO frame source — keep the actual source configurable here.)
- `ICAM_SIM_SOURCE` — simulator source used only when RTSP is
  empty/unreachable **and** `ICAM_ALLOW_SIMULATOR=true`: a looped video file
  path (`samples/conveyor.mp4`) or a webcam index (`"0"`). If neither is
  available, synthetic conveyor frames are generated (local development only).
- `ICAM_CAMERA`, `ICAM_CONVEYOR` — labels stamped on detections.
- `ICAM_MODEL_PATH` — authoritative `models/run-100/best.pt` (semantic
  coverage `GREEN`/`YELLOW`/`RED`; anything else ⇒ `MODEL_ERROR`).
- `ICAM_CONF` — YOLO confidence threshold (0–1). Deployment default for the
  current model is `0.60` (see training/runtime report); still
  environment-driven, never hard-coded in inference code.
- `ICAM_ALLOW_SIMULATOR` — `false` by default (production/demo intent): an
  unreachable real camera reports `OFFLINE` with no synthetic feed. Set
  `true` only for local development; simulator output is always labeled
  `SIMULATOR`, never `LIVE`.

Test without hardware (local development only): set `ICAM_RTSP_URL` empty
**and** `ICAM_ALLOW_SIMULATOR=true` — empty URL alone does not enable the
simulator (production/demo keeps `ICAM_ALLOW_SIMULATOR=false` and reports
`OFFLINE`). Then start the service and open
`http://127.0.0.1:8001/camera/preview` (annotated) or
`http://127.0.0.1:8001/camera/raw`. Check `http://127.0.0.1:8001/health`,
`/model/info`, `/detections/latest`, and `/stats/summary` for the runtime
telemetry the dashboard graphs consume.

## Manual bring-up (operator only)

MANUAL COMMAND — DO NOT RUN DURING AGENT TASK. The agent must never start
services; the commands below are for a human operator validating the demo.

```bash
python3 icam_color_runtime.py --source camnavi --device-name iCam500 --width 1280 --height 960 --roi mat_roi.json --output terminal
```

Setelah `robot_plane.json` hasil pengukuran lengkap, ganti `--roi mat_roi.json` dengan
`--calibration robot_plane.json`. `--output xy-json` menghasilkan XY mm dan flag G/R/Y
di stdout. Kalibrasi wajib memakai resolusi/orientasi frame asli yang sama. Runtime ini
belum membuka serial/TCP atau menggerakkan robot. Pengiriman gerak tetap perlu gate
satu stage: ambil → mangkok → HOME → DONE, lalu frame baru; jangan antrekan observasi per-frame.

## Training berikutnya

Jangan memasukkan arsip foto mentah ini langsung ke `ZIP_SOURCES`: belum ada label objek.
Anotasi frame dari dudukan final, termasuk objek kecil di berbagai posisi matras, beberapa
warna sekaligus, meja kosong, bayangan dan gripper. Pertahankan detail resolusi asli.
Pisahkan train/valid/test berdasarkan sesi pengambilan, bukan mengacak foto beruntun yang mirip.
Evaluasi false positive, objek terlewat, dan error posisi sebelum memutuskan fine-tuning.

## Ekspor ulang runtime setelah mengedit notebook

```bash
python tools/export_icam_runtime.py --output artifacts/icam_runtime_far_patch_baru
```

Exporter hanya menyalin kode cell; tidak mengeksekusi notebook atau memuat bobot.
Patch menyertakan checksum script sendiri dan tidak menimpa kalibrasi maupun model.
Notebook juga memasukkan perubahan ini ke bundle Colab pada ekspor berikutnya.

Referensi konsep: [Ultralytics tiled inference](https://docs.ultralytics.com/guides/sahi-tiled-inference/).
Runtime ini memakai decoder ONNX sendiri tanpa tambahan paket SAHI.
