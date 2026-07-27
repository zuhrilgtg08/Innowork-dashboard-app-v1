# Rencana Kerja Harian — SortVision

**Tanggal:** 24 Juli 2026  
**Branch:** `rasya/dev3`  
**Fokus:** Implementasi Live Camera (end-to-end) + Sesi Training Model YOLOv8

---

## Ringkasan Status Proyek (Sebelum Hari Ini)

| Komponen | Status | Catatan |
|---|---|---|
| Laravel 11 + Livewire 3 | ✅ Siap | Auth Breeze/Volt, routes Livewire-first |
| ML Service (FastAPI) | ✅ Siap | Endpoint `/health`, `/infer`, `/train`, `/camera/stream` |
| Live Camera UI | ✅ UI siap | Webcam + ICAM-300 (RTSP MJPEG) |
| Training UI | ✅ UI siap | Progress real-time, metrics chart |
| Model `best.pt` | ✅ Ada | `storage/app/models/run-1/best.pt` (6.2 MB) |
| Dataset Colab | ✅ Siap | `ultra-milk-yolo-ready.zip` + notebook |
| Simulator video | ❌ **HILANG** | `samples/conveyor.mp4` tidak ada |
| URL consistency | ⚠️ Risk | `APP_URL=localhost:8000` vs `LARAVEL_URL=127.0.0.1:8000` |
| Auto-infer stream | ❌ Off | `ICAM_AUTO_INFER=false` |

---

## Target Capaian Hari Ini (OKR)

| # | Target | Kriteria Penerimaan |
|---|---|---|
| T1 | **Live Camera aktif & streaming** | Kamera (webcam/ICAM-300/simulator) menampilkan video di dashboard, status "AI Service Online" |
| T2 | **Inference berjalan** | Setiap frame menghasilkan verdict (`passed`/`damaged`/`recheck`) dengan confidence, tercatat di Detection Feed |
| T3 | **Simulator fallback bekerja** | Tanpa hardware, stream MJPEG dari ml-service menampilkan frame sintetis, auto-infer menghasilkan deteksi |
| T4 | **Training run selesai** | Minimal 1 training run berhasil `completed` dengan metrics (mAP@50, precision, recall) tercatat |
| T5 | **Model aktif terdaftar** | `Setting.active_training_run_id` mengacu ke run yang selesai, Live Camera memakai model baru |

---

## Jadwal Kerja Hari Ini

### Sesi 1: 09:00 – 10:30 — Foundation & Environment Fix
**Fokus:** Perbaiki konfigurasi yang mencegah pipeline berjalan.

#### Langkah Teknis 1.1 — Samakan URL Laravel & ML Service
Callback dari ml-service ke Laravel butuh URL yang konsisten.

```bash
# Cek .env Laravel
cat .env | grep APP_URL
# Saat ini: APP_URL=http://localhost:8000

# Edit .env agar konsisten dengan ml-service/.env (LARAVEL_URL=http://127.0.0.1:8000)
# Pilih SATU, jangan mixed:
# Opsi A (disarankan untuk lokal):
APP_URL=http://127.0.0.1:8000

# Opsi B:
APP_URL=http://localhost:8000
# lalu ubah ml-service/.env LARAVEL_URL=http://localhost:8000
```

Verifikasi callback akan berjalan:
```bash
# Setelah edit, clear config cache
php artisan config:clear
php artisan cache:forget settings.singleton
```

#### Langkah Teknis 1.2 — Buat Simulator Video (`samples/conveyor.mp4`)
Tanpa file ini, `ICAM_SIM_SOURCE=samples/conveyor.mp4` akan fallback ke synthetic frame (tetap jalan, tapi kurang realistis untuk demo).

```bash
# Opsi A: Gunakan ffmpeg untuk generate video sintetis 30 detik
# Cek apakah ffmpeg tersedia
ffmpeg -version

# Generate conveyor video sintetis (640x480, 30fps, 30 detik)
# Membuat gradasi abu-abu + overlay teks sebagai simulasi conveyor
ffmpeg -f lavfi -i testsrc=duration=30:size=640x480:rate=30 `
  -vf "drawtext=text='SORTVISION SIMULATOR':x=(w-text_w)/2:y=10:fontcolor=white:fontsize=20" `
  -c:v libx264 -pix_fmt yuv420p samples/conveyor.mp4

# Verifikasi
dir samples\conveyor.mp4
```

Alternatif jika ffmpeg tidak ada: ubah `ICAM_SIM_SOURCE` di `ml-service/.env` ke `"0"` (webcam lokal) atau biarkan kosong untuk full synthetic.

#### Langkah Teknis 1.3 — Validasi Environment ML Service
```bash
cd ml-service

# Pastikan venv aktif
.venv\Scripts\activate

# Cek dependency
python -c "import fastapi, ultralytics, cv2, httpx, pydantic_settings; print('All OK')"

# Jika error, reinstall
pip install -r requirements.txt
```

#### Langkah Teknis 1.4 — Verifikasi Model File
```bash
# Cek best.pt valid
dir storage\app\models\run-1\best.pt

# Quick test load via Python
python -c "from ultralytics import YOLO; m=YOLO('storage/app/models/run-1/best.pt'); print(m.names)"
```
Expected output: `{0: 'passed', 1: 'damaged'}` (class names sudah di-rename oleh `colab_prepare.py`)

---

### Sesi 2: 10:30 – 12:00 — Live Camera End-to-End Testing
**Fokus:** Pastikan stream + inference berjalan dari kamera → ml-service → Laravel → UI.

#### Langkah Teknis 2.1 — Start ML Service
```bash
# Terminal 1: ML Service
cd ml-service
.venv\Scripts\activate
uvicorn main:app --host 127.0.0.1 --port 8001 --reload

# Verifikasi health
curl http://127.0.0.1:8001/health
# Expected: {"status":"ok","model_loaded":true,"base_model":"yolov8n.pt"}
```

#### Langkah Teknis 2.2 — Start Laravel App
```bash
# Terminal 2: Laravel
php artisan serve --host=127.0.0.1 --port=8000

# Terminal 3: Vite
npm run dev

# Terminal 4: Queue Worker (WAJIB untuk training callback)
php artisan queue:work
```

#### Langkah Teknis 2.3 — Test MJPEG Stream (Simulator)
Buka browser:
```
http://127.0.0.1:8001/camera/stream
```
Expected: Video stream tampil (synthetic conveyor atau `samples/conveyor.mp4`).

#### Langkah Teknis 2.4 — Test Camera Status API
```bash
curl http://127.0.0.1:8001/camera/status
# Expected: {"connected":false,"mode":"simulator","source":"samples/conveyor.mp4","fps":0.0}
```

#### Langkah Teknis 2.5 — Test Single-Frame Inference
```bash
# Ambil satu frame dari stream untuk test
# Atau gunakan screenshot dari simulator

# Via curl (multipart)
curl -X POST http://127.0.0.1:8001/infer `
  -F "frame=@samples/conveyor.mp4" `
  -F "conf=0.85" `
  -F "model_path=models/run-1/best.pt" `
  -F "camera=ICAM-300" `
  -F "conveyor=LINE-A"

# Expected JSON: {"status":"passed|damaged|recheck","confidence":xx,"qr_value":null,"boxes":[...],"detections":[...]}
```

#### Langkah Teknis 2.6 — Enable Auto-Infer di Live Camera
Edit `ml-service/.env`:
```env
ICAM_AUTO_INFER=true
```
Restart ml-service:
```bash
# Ctrl+C, lalu restart
uvicorn main:app --host 127.0.0.1 --port 8001 --reload
```

Buka dashboard → **Live Camera** → pilih sumber **ICAM-300 (RTSP)** di Settings → Save.
- Jika `ICAM_RTSP_URL` kosong, ml-service otomatis pakai simulator.
- Auto-infer loop akan POST deteksi ke `http://127.0.0.1:8000/api/camera/detection` setiap 3 detik.

**Verifikasi:**
1. Dashboard Live Camera menampilkan stream MJPEG dengan badge **LIVE**
2. Detection Feed menerima item baru setiap ~3 detik
3. Logs muncul: "Live inference: Passed ..." atau "Damaged ..."
4. Statistik hari ini bertambah

#### Langkah Teknis 2.7 — Troubleshooting Live Camera
| Gejala | Diagnosis | Solusi |
|---|---|---|
| Stream blank / "Stream ICAM-300 tidak tersedia" | ml-service mati atau URL salah | `curl http://127.0.0.1:8001/health` |
| "AI Service Offline" | `APP_URL`/`ML_SERVICE_URL` mismatch | Samakan `.env` Laravel & `ml-service/.env` |
| Inference stuck, tidak ada Detection Feed | `ICAM_AUTO_INFER=false` atau queue worker mati | Set `ICAM_AUTO_INFER=true`, jalankan `php artisan queue:work` |
| Callback 401/403 | `ML_CALLBACK_SECRET` beda | Samakan persis di kedua `.env` |
| Confidence 0%, status "unreadable" | Model `best.pt` bukan QC model atau class names salah | Cek `model.names`, pastikan `passed`/`damaged` |

---

### Sesi 3: 12:00 – 13:00 — Istirahat & Dokumentasi
- Catat hasil testing Live Camera
- Screenshot stream aktif dan Detection Feed
- Update `COLAB_TRAINING.md` jika ada temuan baru

---

### Sesi 4: 13:00 – 15:00 — Training Session: Ultra Milk Model
**Fokus:** Latih model YOLOv8 dari dataset ultra-milk, daftarkan ke SortVision, verifikasi inference.

#### Langkah Teknis 4.1 — Persiapan Dataset
Dataset sudah siap di `ml-service/colab-artifacts/ultra-milk-yolo-ready/` dan `.zip`.

Verifikasi:
```bash
dir ml-service\colab-artifacts\ultra-milk-yolo-ready
# Harus ada: train/, valid/, data.yaml

# Cek isi data.yaml
cat ml-service\colab-artifacts\ultra-milk-yolo-ready\data.yaml
# Expected: names: [0: passed, 1: damaged]
```

#### Langkah Teknis 4.2 — Upload ke Google Drive
1. Buka https://drive.google.com
2. Upload `ml-service/colab-artifacts/ultra-milk-yolo-ready.zip`
3. Pastikan path: `MyDrive/ultra-milk-yolo-ready.zip`

#### Langkah Teknis 4.3 — Buka Colab & Setup Runtime
1. Buka https://colab.research.google.com
2. Upload `ml-service/colab-artifacts/ultra-milk-training.ipynb`
3. **Runtime → Change runtime type → GPU (T4/A100)**
4. Mount Google Drive (cell pertama)

#### Langkah Teknis 4.4 — Eksekusi Training di Colab
Jalankan cell berurutan:
1. **Mount Drive + Dataset** — otomatis mendeteksi zip di Drive dan ekstrak
2. **Install Ultralytics + Cek GPU** — `pip install ultralytics`, verifikasi CUDA
3. **Train Model** — konfigurasi:
   ```python
   model = YOLO('yolov8n.pt')
   model.train(
       data=DATA_YAML,
       epochs=10,        # Naikkan ke 20-30 jika ingin akurasi lebih baik
       imgsz=640,
       batch=16,         # Turun ke 8 jika OOM
       device='cuda',
       workers=2,
       cache=False,
       project='/content/runs/detect',
       name='train',
       exist_ok=True,
   )
   ```
4. **Download `best.pt`** — cell terakhir download otomatis

**Estimasi durasi:** ~15-30 menit (tergantung dataset size & GPU Colab).

#### Langkah Teknis 4.5 — Daftarkan Model ke SortVision
```bash
# 1. Copy best.pt ke storage Laravel
# Ganti nama agar unik (misal ultra-milk-colab-1)
mkdir storage\app\models\run-2
copy ultra-milk-best.pt storage\app\models\run-2\best.pt

# 2. Buat TrainingRun via Tinker
php artisan tinker
```
```php
>>> App\Models\TrainingRun::create([
...     'name' => 'ultra-milk-colab-1',
...     'status' => 'completed',
...     'epochs' => 10,
...     'progress' => 100,
...     'current_epoch' => 10,
...     'model_path' => 'models/run-2/best.pt',
...     'dataset_train' => 80,  // sesuaikan dengan split dataset
...     'dataset_val' => 20,
...     'metrics' => [
...         'map50' => 85.5,      // isi dari hasil Colab
...         'precision' => 88.0,
...         'recall' => 82.0,
...         'per_class' => [
...             ['label' => 'passed', 'precision' => 90.0, 'recall' => 85.0, 'map50' => 88.0],
...             ['label' => 'damaged', 'precision' => 86.0, 'recall' => 79.0, 'map50' => 83.0],
...         ],
...     ],
...     'started_at' => now()->subHours(1),
...     'finished_at' => now(),
... ]);
>>> App\Models\Setting::current()->update(['active_training_run_id' => 1]); // atau ID run yang baru
>>> Cache::forget('settings.singleton');
>>> exit;
```

> **Penting:** Ganti `run-2` dengan angka yang sesuai. Jika `run-1` sudah ada, gunakan `run-2` atau cek ID `TrainingRun` terbaru.

#### Langkah Teknis 4.6 — Verifikasi Inference dengan Model Baru
```bash
# Reload model cache di ml-service
curl -X POST http://127.0.0.1:8001/reload-model `
  -H "Content-Type: application/json" `
  -d '{"model_path":"models/run-2/best.pt"}'
```

Buka **Live Camera**:
- Upload frame manual (browser webcam capture) atau tunggu auto-infer
- Verdict harus sesuai kelas ultra-milk (`passed` / `damaged`)
- Cek Logs → harus muncul "Live inference: ..."

---

### Sesi 5: 15:00 – 16:30 — Integration Testing & Documentation
**Fokus:** Validasi full pipeline, perbaiki bug, dokumentasikan.

#### Langkah Teknis 5.1 — End-to-End Smoke Test
```bash
# Checklist validasi
1. [ ] ML Service: GET /health → 200 OK
2. [ ] ML Service: GET /camera/stream → MJPEG tampil di browser
3. [ ] ML Service: GET /camera/status → mode "simulator" atau "live"
4. [ ] Laravel: Live Camera → badge "AI Service Online" (hijau)
5. [ ] Laravel: Live Camera → stream tampil (MJPEG atau webcam)
6. [ ] Laravel: Auto-infer → Detection Feed menerima item baru
7. [ ] Laravel: Training → New Training button enabled (min 4 approved annotations)
8. [ ] Laravel: Training → progress bar berjalan jika run aktif
9. [ ] Callback: Training run berubah status queued → exporting → training → completed
10. [ ] Model: Setting.active_training_run_id mengacu ke run terbaru
```

#### Langkah Teknis 5.2 — Jika Ada Bug, Perbaiki Prioritas
| Prioritas | Bug type | Contoh |
|---|---|---|
| P0 (blokir) | Callback gagal | `X-ML-Signature` mismatch → samakan `ML_CALLBACK_SECRET` |
| P0 (blokir) | Inference 500 | Model path salah → cek `storage/app/models/run-*/best.pt` |
| P1 | Stream blank | MJPEG endpoint error → cek `camera_source.latest_jpeg()` |
| P1 | Queue worker mati | Training stuck di `queued` → jalankan `php artisan queue:work` |
| P2 | UI minor | Tailwind class, spacing, label |

#### Langkah Teknis 5.3 — Dokumentasi Update
Perbarui file dokumentasi yang relevan:
- `COLAB_TRAINING.md`: tambahkan catatan tentang `run-2` dan metrics yang didapat
- `SETUP.md`: tambahkan troubleshooting untuk simulator video
- `README.md`: tambahkan section tentang Live Camera & Training quickstart

---

### Sesi 6: 16:30 – 17:30 — Review & Planning for Tomorrow
**Fokus:** Evaluasi hari ini, rencana besok.

#### Checklist Akhir Hari
```
[ ] Live Camera stream aktif (webcam atau simulator)
[ ] Inference menghasilkan verdict QC (passed/damaged/recheck)
[ ] Auto-inferPOST ke Laravel berhasil
[ ] Detection Feed menampilkan hasil
[ ] Training run (Colab atau lokal) berhasil completed
[ ] Model baru terdaftar di Setting.active_training_run_id
[ ] Semua terminal masih berjalan稳定 (Laravel, Vite, Queue, ML Service)
```

#### Rencana Besok (Preview)
1. **Multi-camera fleet:** Tambah kamera kedua di `Camera` model & fleet UI
2. **Real ICAM-300:** Test dengan hardware sebenarnya (RTSP URL)
3. **Annotation improvement:** Tambah tool annotate manual di halaman Annotation
4. **Model quality:** Tambah epochs ke 20-30, fine-tune hyperparameter
5. **Auto-retrain:** Implementasi `AutoRetrain` service yang otomatis retrain saat threshold accuracy turun

---

## Konfigurasi Kamera — Detail Teknis

### 1. Browser Webcam (Default)
- **Sumber:** `navigator.mediaDevices.getUserMedia({ video: true })`
- **Processing:** Client-side capture → Upload ke `/infer` (multipart)
- **Keunggulan:** Zero-config, langsung jalan
- **Keterbatasan:** Tidak bisa auto-infer tanpa user interaction di beberapa browser

### 2. ICAM-300 via RTSP
- **Sumber:** `rtsp://<ip>:8550/video`
- **Relay:** ml-service → MJPEG (`/camera/stream`) + inference loop
- **Konfigurasi:**
  ```env
  # ml-service/.env
  ICAM_RTSP_URL=rtsp://192.168.1.100:8550/video
  ICAM_AUTO_INFER=true
  ICAM_INFER_INTERVAL=3.0
  ICAM_MODEL_PATH=models/run-2/best.pt
  ```
- **Syarat:** Kamera dalam status "playing" (≥5fps), network reachable dari mesin

### 3. Simulator (Tanpa Hardware)
- **Sumber:** `samples/conveyor.mp4` (looped) atau synthetic frames
- **Konfigurasi:**
  ```env
  ICAM_RTSP_URL=
  ICAM_SIM_SOURCE=samples/conveyor.mp4  # atau "0" untuk webcam, atau kosong untuk synthetic
  ICAM_AUTO_INFER=true
  ```

---

## Jadwal Pelatihan Pengguna (Training Session)

### Audience
- **Operator QC:** Pengguna sehari-hari yang memindai produk di conveyor
- **Supervisor QC:** Yang meninjau hasil deteksi dan mengatur threshold
- **Admin:** Yang menjalankan training dan mengelola model

### Materi Pelatihan Hari Ini

| Waktu | Materi | Pengguna | Durasi |
|---|---|---|---|
| 13:00 – 13:45 | **Live Camera Basics** — cara mengaktifkan kamera, membaca verdict, memahami badge LIVE/OFF | Operator + Supervisor | 45 menit |
| 13:45 – 14:30 | **Training Workflow** — dari annotation → export → train → activate model | Admin + Supervisor | 45 menit |
| 14:30 – 15:00 | **Hands-on: Inference Testing** — semua peserta mencoba capture frame dan melihat hasil | Semua | 30 menit |

### Contoh Skenario Pelatihan
1. **Operator:** "Saya ingin memindai produk Ultra Milk di line A"
   - Buka Live Camera → klik Aktifkan Kamera → lihat stream
   - Tunggu auto-infer → lihat verdict di Detection Feed
   - Jika `damaged` → cek Logs untuk detail

2. **Supervisor:** "Saya ingin meninjau akurasi model sebelum shift berikutnya"
   - Buka Training → lihat per-class metrics (precision/recall/F1)
   - Lihat dataset distribution — apakah kelas `damaged` cukup sample?
   - Jika perlu, tambah annotation lalu jalankan retrain

3. **Admin:** "Saya ingin mengganti model dengan yang baru dari Colab"
   - Upload `best.pt` Colab ke `storage/app/models/run-3/best.pt`
   - Buat TrainingRun completed via Tinker
   - Update `Setting.active_training_run_id`
   - Reload model: `POST /reload-model`

---

## Dependensi & Prasyarat Hari Ini

| Item | Diperlukan | Status |
|---|---|---|
| Python 3.12 | ✅ | Terpasang |
| venv ml-service | ✅ | `.venv` ada |
| Ultralytics + FastAPI deps | ✅ | Terinstall |
| Laravel + Vite | ✅ | `npm install` + `composer install` |
| PostgreSQL/SQLite | ✅ | SQLite aktif |
| Queue worker | ⚠️ Harus dijalankan | `php artisan queue:work` |
| Simulator video | ❌ **Harus dibuat** | `samples/conveyor.mp4` |
| Colab notebook | ✅ | `ultra-milk-training.ipynb` siap |
| Model best.pt | ✅ | `storage/app/models/run-1/best.pt` |

---

## Risk Register & Mitigasi

| Risiko | Probabilitas | Dampak | Mitigasi |
|---|---|---|
| Colab GPU unavailable/timeout | Medium | Training tertunda | Gunakan CPU runtime (lambat) atau schedule ulang |
| `ML_CALLBACK_SECRET` mismatch | Low | Callback 403, training stuck | Samakan persis di `.env` Laravel & `ml-service/.env` |
| Queue worker tidak dijalankan | Medium | Training stuck di `queued` | Selalu jalankan `php artisan queue:work` di terminal terpisah |
| `samples/conveyor.mp4` tidak bisa dibuat | Low | Simulator pakai synthetic (tetap jalan) | Biarkan `ICAM_SIM_SOURCE` kosong |
| Browser blokir webcam permission | Low | Webcam tidak aktif | Gunakan ICAM-300 mode atau simulator |
| Model `best.pt` class names salah | Low | Inference return label asli | Cek dengan `python -c "from ultralytics import YOLO; print(YOLO('...').names)"` |

---

## Deliverables Hari Ini

1. ✅ **Live Camera** — stream aktif (webcam/simulator/ICAM-300), inference berjalan, Detection Feed terisi
2. ✅ **Simulator fallback** — `samples/conveyor.mp4` dibuat atau synthetic frame aktif
3. ✅ **Training run completed** — minimal 1 run berhasil, metrics tercatat di database
4. ✅ **Model aktif** — `Setting.active_training_run_id` mengacu ke run terbaru
5. ✅ **Dokumentasi** — `COLAB_TRAINING.md` dan `SETUP.md` diperbarui dengan temuan hari ini
6. ✅ **Staging stable** — semua service (Laravel, Vite, Queue, ML) berjalan tanpa error di terminal

---

**Catatan:** Rencana ini menilai bahwa fitur-fitur inti (Live Camera page, Training page, ML service endpoints) sudah diimplementasikan di kodebase. Hari ini difokuskan pada **integrasi end-to-end**, **konfigurasi environment**, **testing pipeline**, dan **eksekusi training pertama** yang sukses.
