# SortVision ML Service

FastAPI + YOLO untuk inferensi QC, training, preview kamera, dan Vision Sorting.
Laravel mengakses service pada port 8001. Confidence pada respons, callback,
dan MQTT memakai **0–100 persen**; konfigurasi threshold memakai **0–1**.

## Setup

Gunakan Python 3.11 atau 3.12 dengan virtualenv terpisah. Jalankan dari `ml-service/`:

```powershell
py -3.11 -m venv .venv
.venv\Scripts\python.exe -m pip install --upgrade pip
.venv\Scripts\python.exe -m pip install torch torchvision --index-url https://download.pytorch.org/whl/cpu
.venv\Scripts\python.exe -m pip install -r requirements.txt
Copy-Item .env.example .env  # hanya saat .env belum ada
```

Linux: `python3 -m venv .venv`, lalu gunakan `.venv/bin/python` untuk semua
perintah Python. Jangan membawa virtualenv dari komputer atau versi Python lain.

Isi `.env`:

- `LARAVEL_STORAGE_PATH=../storage/app`. Path relatif selalu dihitung dari
  folder `ml-service`, termasuk saat service dijalankan dari root proyek.
- `LARAVEL_URL=http://127.0.0.1:8000` dan `ML_CALLBACK_SECRET` harus sesuai
  konfigurasi Laravel. Secret kosong menyebabkan callback ditolak Laravel.
- `ICAM_MODEL_PATH=models/run-2/best.pt` untuk model QC yang tersedia di
  `storage/app/models/run-2/best.pt`. Kosong berarti memakai `BASE_MODEL` di
  folder service. File model harus tersedia; model yang hilang tidak diganti
  diam-diam dengan model lain.

Untuk mengunduh base model saat setup jika belum tersedia:

```powershell
.venv\Scripts\python.exe -c "from ultralytics import YOLO; YOLO('yolov8n.pt')"
```

## Menjalankan

```powershell
.venv\Scripts\python.exe -m uvicorn main:app --host 127.0.0.1 --port 8001 --workers 1
```

Dari root proyek, gunakan:

```powershell
ml-service\.venv\Scripts\python.exe -m uvicorn main:app --app-dir ml-service --host 127.0.0.1 --port 8001 --workers 1
```

Gunakan satu worker: buffer kamera, antrean training, dan latch sorting hidup
di satu proses. Laravel dan queue worker (`php artisan queue:work`) perlu
berjalan untuk menerima deteksi dan callback training.

## Kamera dan mode sorting

`ICAM_RTSP_URL` berisi RTSP kamera. Jika kosong/tidak tersedia, service memakai
`ICAM_SIM_SOURCE` (video berulang atau indeks webcam seperti `0`), lalu frame
sintetis jika sumber tersebut juga tidak tersedia. Kamera RTSP dicoba kembali
secara berkala. `connected=true` berarti frame RTSP benar-benar diterima.

`ICAM_AUTO_INFER=true` mengaktifkan inferensi dan callback otomatis setiap
`ICAM_INFER_INTERVAL` detik. Default template adalah `false` sehingga setup
awal dapat diperiksa lewat `/health`, `/camera/frame`, dan `/camera/preview`.

Untuk Vision Sorting:

```dotenv
COMPETITION_MODE=true
ICAM_MODEL_PATH=models/run-100/best.pt
ICAM_AUTO_INFER=true
MQTT_BROKER=127.0.0.1
MQTT_PORT=1883
```

Ganti path dengan model warna yang tersedia. Class map wajib tepat
`{0: HIJAU, 1: KUNING, 2: MERAH}`. Model QC produk susu tidak dapat digunakan
sebagai model warna. Laravel `mqtt:listen` dan perangkat arm harus terhubung.
Mock lokal dapat dijalankan dengan `.venv\Scripts\python.exe mock_hardware.py`.

Sebelum mengirim `arm/command`, pipeline memeriksa model, confidence, pick zone,
duplikasi/cooldown, keberhasilan ingest, kapasitas bowl, dan status arm.
Kegagalan/malformed preflight menahan perintah. Publikasi MQTT menunggu ACK
QoS 1 dengan batas `MQTT_TIMEOUT`; respons sukses berarti broker menerima
perintah, sedangkan penyelesaian gerakan dilaporkan melalui `arm/status`.
Preview hanya menjalankan inferensi dan tidak mengirim perintah atau deteksi.

## Endpoint

| Endpoint | Perilaku |
| --- | --- |
| `GET /health` | Liveness (HTTP 200), `model_loaded` hasil pemuatan model nyata, diagnostik jika gagal. |
| `GET /model/info` | Model aktif, class map, ukuran file, dan device; 503 jika model tidak tersedia. |
| `POST /reload-model` | `{model_path?}` memvalidasi lalu mengaktifkan model untuk HTTP, stream, dan preview. Path aktif dipertahankan jika validasi gagal. Aktivasi berlaku sampai restart; simpan `ICAM_MODEL_PATH` untuk startup berikutnya. |
| `POST /infer` | Multipart `frame`, `conf`, `model_path?`, `camera?`, `conveyor?`, `product_id?`. Mengembalikan verdict, QR, boxes, detections, `frame_width`/`frame_height`. Bbox adalah piksel `[x1,y1,x2,y2]`. Dalam sorting mode juga menjalankan pipeline arm. |
| `GET /camera/status` | `connected`, `mode`, `source`, dan `fps`. |
| `GET /camera/frame` | Satu JPEG tanpa cache untuk polling mobile. |
| `GET /camera/stream` | Stream MJPEG kamera. |
| `GET /camera/preview` | Stream MJPEG beranotasi tanpa efek sorting. |
| `GET /preview/latest` | Snapshot inferensi preview terakhir beserta error jika ada. |
| `POST /train` | JSON `{run_id, epochs, imgsz, storage_path, callback_url, annotations[]}`; 202 jika diterima, 409 jika training lain masih aktif. |

Frame tidak valid mengembalikan 422, upload di atas 10 MiB mengembalikan 413,
dan model tidak tersedia mengembalikan 503. Inferensi berjalan di thread pool
sehingga endpoint lain tetap dapat dilayani.

Annotation training berisi `image_path` relatif terhadap `storage/app/public`,
`label`, `bbox` opsional dalam format normalized `[x,y,width,height]`, dan
`split` (`train`/`val`). Beberapa annotation pada satu gambar diekspor menjadi
satu gambar dengan beberapa baris label. Jika val kosong, train dicerminkan
ke val untuk demo; metrik tersebut bukan pengukuran generalisasi independen.
Training CPU memakai batch 4; mulai dengan 1–5 epoch. Hasil masuk ke
`storage/app/models/run-{id}/best.pt` dan progres dikirim lewat callback HMAC.

## Verifikasi

```powershell
.venv\Scripts\python.exe -m unittest discover -s tests -v
.venv\Scripts\python.exe -m pip check
.venv\Scripts\python.exe tests/smoke_ml.py --train
Invoke-RestMethod http://127.0.0.1:8001/health
```

Tes regresi memakai model/HTTP/MQTT palsu, gambar kecil nyata, dan kamera
sintetis. Tes mencakup kontrak API, pergantian model, metadata overlay,
training dataset, callback, lifecycle, serta gate sorting dan request bersamaan.
`smoke_ml.py` memakai weights lokal untuk inferensi dan preview nyata; `--train`
menambahkan satu epoch training pada dataset sintetis sementara. Smoke test
tidak mengirim callback Laravel atau MQTT dan tidak mengubah model aktif.
Pengujian arm fisik tetap memerlukan kamera, model warna, Laravel, dan broker
yang dikonfigurasi untuk perangkat tersebut.
