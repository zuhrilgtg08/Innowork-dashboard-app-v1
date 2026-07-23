# SortVision: Industrial Conveyor & Multi-Detection ML Plan
**Implementasi sistem sorting logistik real-time, multi-kamera, dan quality control berbasis YOLOv8**

---

## 1. Ringkasan Arsitektur Saat Ini

Proyek ini sudah memiliki fondasi solid:

| Layer | Teknologi | Status |
|-------|-----------|--------|
| Backend | Laravel 11 + Livewire 3 + Volt | Aktif |
| Frontend | Blade + Tailwind + Alpine.js | Aktif |
| Auth | Laravel Breeze (Livewire stack) + Sanctum API | Aktif |
| Database | SQLite (dev) / Postgres (prod) | Aktif |
| ML Engine | Python FastAPI + Ultralytics YOLOv8 | Aktif |
| Kamera | ICAM-300 RTSP / webcam browser / simulator | Aktif |
| Hardware | MQTT (php-mqtt/client) untuk robotic arm | Aktif |
| Training | Anotasi manusia → YOLO train → best.pt | Aktif |

**Alur data saat ini:**
`Kamera → RTSP → FastAPI (YOLO) → HMAC callback → Laravel ingesti → Detection + SystemLog`

---

## 2. Gap Analysis: Konsep Proyek vs Implementasi Saat Ini

| Kebutuhan Proyek | Status Saat Ini | Kesenjangan |
|------------------|-----------------|-------------|
| Integrasi conveyor untuk pemindahan otomatis barang | Belum ada driver/contoh implementasi conveyor | Perlu: sensor atau modul conveyor service + simulasi. |
| Deteksi QR code secara realtime | QR **generation** ada, QR **decoding** belum ada di pipa | Tambah decoder (pyzbar/zxingcpp) di FastAPI sebelum inferensi QC. |
| Deteksi kotak susu ultra milk (best.pt) | best.pt ada, model class saat ini hanya QC status | Latih ulang model khusus Ultra Milk + QR code reading. |
| Deteksi kondisi abnormal (rusak, kelecetan, tidak terbaca) | QC status ada (damaged, unreadable, scratched) | Belum ada deteksi *layout/positional anomaly* untuk off-flow/kelel-lecetan. |
| Return / pengecekan ulang otomatis | Status `returned`/`recheck` ada di DB | Belum ada trigger otomatis ke robotic arm atau sistem return. |
| Multiple detection real-time | Single frame, single inference per trigger | Perlu: multi-object tracking + parallel kamera. |
| Monitoring + quality control | Monitoring dasar ada | QC logic dan automasi belum lengkap. |

---

## 3. Rencana Instruksi (Prompt Plan untuk Claude Code)

Gunakan urutan ini sebagai checklist eksekusi. Setiap blok dapat dijalankan secara berurutan atau paralel jika independen.

---

### TASK 1: QR Code Decoding Pipeline (Backend + ML Service)
**Tujuan:** Melengkapi pipa deteksi QR code dari kamera ke database.

1. **Instal library resolusi QR di `ml-service`:**
   - Tambah `pyzbar` atau `zxingcpp` ke `ml-service/requirements.txt`.
   - Buat modul `ml-service/qr_decode.py` yang menerima frame (numpy/BGR) atau path gambar dan mengembalikan string QR decoded.
   - Integrasi decoy: di `infer.py`, sebelum atau sesudah inferensi YOLO, jalankan QR decode. Jika QR berhasil dibaca, isi field `qr_value` di hasil inferensi.
   - Jika QR tidak terdeteksi di frame tertentu, sistem harus mencatat status `unreadable` dan confidence rendah.

2. **Ekspos hasil QR decoding:**
   - Modifikasi `ml-service/callbacks.py:post_detection` agar payload menyertakan `qr_value`.
   - Pastikan `CameraController::ingest` menyimpan `qr_value` ke kolom `qr_value` di tabel `detections`.

3. **Update migrasi jika perlu:**
   - Kolom `qr_value` sudah ada. Cukup pastikan fillable dan casting tidak ada yang terlewat.

**Output:** Kamera dapat membaca QR code Ultra Milk secara otomatis tanpa bergantung pada hardware ICAM-300 bawaan.

---

### TASK 2: Multi-Object Detection & Tracking per Frame
**Tujuan:** Meningkatkan throughput dengan deteksi banyak barang secara bersamaan dalam satu frame.

1. **Di `infer.py`, aktifkan mode multi-deteksi:**
   - Ubah logika dari "top-1 box" menjadi "semua box di atas threshold".
   - Setiap box individu yang melebihi confidence threshold di Represent sebagai deteksi terpisah.
   - Return format: `{"status": ..., "confidence": ..., "boxes": [...], "detections": [{status, confidence, bbox, label}]}` atau agregasi per frame.

2. **Di Laravel, modifikasi `CameraController::ingest`:**
   - Iterasi `boxes` di payload.
   - Buat **banyak `Detection` record** dari satu frame jika diperlukan (setiap box = satu deteksi).
   - Kaitkan dengan `product_id` jika `qr_value` cocok dengan token produk di tabel `products`.

3. **Batch ingest API:**
   - Pertimbangkan endpoints baru untuk batch: `POST /api/camera/detections` yang menerima array deteksi dari satu frame untuk mengurangi overhead HTTP.
   - Atau keep single endpoint tapi uji dengan increment payload.

**Output:** Satu snapshot kamera dapat menghasilkan banyak record deteksi sekaligus.

---

### TASK 3: Conveyor Integration & Off-Flow Detection
**Tujuan:** Sistem tidak hanya membaca barang, tapi memahami kondisi alur conveyor.

1. **Simulasi atau sensor conveyor:**
   - Buat `app/Services/ConveyorService.php` (mirip `ArmMqttService`).
   - Modul ini bisa berkomunikasi via MQTT topsis: `conveyor/status`, `conveyor/command` (start, stop, reverse).
   - Buat simulator conveyor di `ml-service` atau Python standalone: simulasi line tracking, sensor photoreflector virtual.

2. **Deteksi off-flow / kelecetan secara visual:**
   - Di `ml-service`, tambah modul `flow.py` menggunakan **background subtraction** (MOG2/KNN) atau optical flow (Lucas-Kanade).
   - Analisis densitas objek di conveyor: kerapatan di bawah threshold = kemacetan (*jam*); strip kosong di tengah line = barang turun/kelecetan.
   - Tambah status baru di Laravel: `jam` dan `off_flow` (opsional, atau pakai `recheck` dulu).
   - Jika `jam`/`off_flow` terdeteksi, publish event MQTT ke `conveyor/alert` dan insert `SystemLog` level `warning/error`.

3. **Database schema untuk conveyor:**
   - Tambah tabel `conveyor_lines` (id, name, camera_id, status, speed_rpm, last_event_at).
   - Tambah tabel `conveyor_events` (id, line_id, event_type, detection_id(FK), ctx(JSON), occurred_at).
   - Atau simple: tambah kolom `frame_path` sudah ada, cukup pakai `SystemLog source=conveyor`.

**Output:** Sistem dapat mendeteksi kondisi alur abnormal dan memberi alert, bukan hanya membaca barang.

---

### TASK 4: Multi-Kamera & Paralel Ingest
**Tujuan:** Skalabilitas lini produksi ganda.

1. **Update `Setting` model:**
   - Tambah kolom `active_cameras` (JSON array konfigurasi kamera: `id, rtsp_url, conveyor, line`) atau buat tabel baru `cameras`.
   - Tabel baru lebih fleksibel: `cameras` (id, name, rtsp_url, simulator_source, conveyor_line_id, is_active, position_x, position_y).

2. **Update `ml-service/config.py`:**
   - Ubah single `icam_rtsp_url` menjadi daftar `icam_rtsp_urls` (array).
   - Buat daemon thread per kamera (bukan satu global loop). Atau gunakan `asyncio` + `cv2.VideoCapture` async wrapper.

3. **Update FastAPI:**
   - `/camera/stream` perlu bisa melayani stream berdasarkan parameter `camera_id`.
   - Inferensi loop: spawn satu thread per kamera, masing-masing memanggil `infer.infer_frame` dan POST ke Laravel.

4. **Frontend:**
   - Livewire `LiveCamera\Index` ubah jadi grid layout.
   - `wire:poll` untuk setiap kamera interval berbeda-beda.
   - Tampilkan statistik agregat keseluruhan lini.

**Output:** Dukungan banyak kamera sekaligus untuk lini conveyor berbeda.

---

### TASK 5: QC Workflow Automation (Return & Recheck Trigger)
**Tujuan:** Dari deteksi menjadi aksi otomatis (robotic arm / sistem return).

1. **Event-driven architecture:**
   - Setelah `Detection::create` di `CameraController`, cek `Setting::auto_reject_on_damage`.
   - Jika `true` dan status = `damaged`/`scratched`/`unreadable`, publish command MQTT ke `arm/command` dengan target zone untuk "return line".
   - Gunakan `ArmMqttService` yang sudah ada, tambah method `routeToReturn(Detection $detection)`.

2. **Return batch management:**
   - Tabel baru `return_batches` (id, conveyor, reason, created_at, resolved_at).
   - Saat barang masuk return, attach detection ke batch.
   - Livewire page baru `Returns\Index` untuk operator meninjau dan menyelesaikan batch.

3. **Recline visual:**
   - Operator supervisor QC melihat item `recheck` di dashboard, klik "Manual Review", tampilkan frame + bbox.
   - Operator ubah status menjadi `passed` atau `returned`.

**Output:** Deteksi abnormal memicu aksi fisik (arm) atau alur administratif (return batch).

---

### TASK 6: Model Retraining & Active Model Management
**Tujuan:** best.pt khusus Ultra Milk berkala diperbaiki.

1. **Dataset labeling khusus Ultra Milk:**
   - Pastikan dataset Roboflow (`ultra-milk-kecil.yolov8`) diekspor dan dipakai di `train.py`.
   - Tambah class khusus: `um_normal` (passed), `um_rusak` (damaged), `qr_unreadable` (unreadable).

2. **Auto-retrain pipeline:**
   - `Setting::auto_retrain` harus memicu `StartTrainingRun` job ketika jumlah approved `Annotation` mencapai threshold (misal: 50 item baru).
   - Update `StartTrainingRun` agar filter berdasarkan product category = Ultra Milk.

3. **Model activation flow:**
   - Command artisan `sortvision:activate-model` sudah ada. Tambah validasi untuk menjamin model hanya diaktifkan jika mAP50 > threshold minimum (misal 70%).
   - Buat rollback otomatis ke model sebelumnya jika model baru gagal.

**Output:** Model YOLO selalu up-to-date untuk produk Ultra Milk dengan cara yang aman.

---

### TASK 7: Performance & Real-Time Hardening
**Tujuan:** Stabilitas di lingkungan industri 24/7.

1. **Queue-based inference (opsional tapi direkomendasikan):**
   - Daripada callback langsung dari FastAPI (synchronous), ubah menjadi Laravel queue job: `ProcessFrameJob` dengan backoff + timeout.
   - Keuntungan: retry otomatis, rate limiting, isolasi failure.

2. **Frame deduplication:**
   - Tambah cache key `frame:hash:<sha256>` di Redis/database untuk mencegah double-count deteksi pada frame yang sama (conveyor lambat, kamera静pict frame yang sama berulang kali).

3. **Hot-reload model:**
   - Di `ml-service`, tambah endpoint `POST /reload-model` agar Laravel bisa memaksa FastAPI me-reload `best.pt` tanpa restart service.
   - Implementasi: cache `_load` LRU kirim sinyal clear.

4. **Health & alerting:**
   - Jadwalkan command artisan `sortvision:health-check` (via scheduler) yang mengecek MQTT, ML service, dan kamera. Failure → `SystemLog` level `error` + opsional email.

**Output:** Sistem lebih tahan banting untuk operasi non-stop.

---

## 4. Urutan Eksekusi yang Disarankan

| Fase | Task | Estimasi Relatif |
|------|------|------------------|
| **P1** | QR Decoding Pipeline | 2-3 hari |
| **P1** | Multi-Object per Frame | 1-2 hari |
| **P2** | QC Workflow Automation (return/recheck) | 2-4 hari |
| **P2** | Conveyor Integration & Off-Flow | 3-5 hari |
| **P3** | Multi-Kamera & Paralel Ingest | 2-3 hari |
| **P3** | Performance Hardening | 1-2 hari |
| **P3** | Model Retraining Improvements | 1-2 hari |

**P1** adalah fondasi agar sistem benar-benar membaca QR + banyak barang. Tanpa itu, multi-kamera pun tidak berarti.

---

## 5. Data Flow Target (Setelah Implementasi)

```
Kamera A (QR + Box) → RTSP/Stream → FastAPI
    ├─> QR Decode → [qr_value]
    ├─> YOLO multi-box → [labels, bboxes, confidence]
    ├─> Flow Analysis → [jam, off_flow]
    └─> Aggregate per frame → POST /api/camera/detections (batch)

Laravel
    ├─> Verifikasi HMAC → Insert Detection(s)
    ├─> Join dengan Product (via qr_token)
    ├─> Auto QC Rule Engine:
    │       ├─ damaged/unreadable → publish MQTT → Robotic Arm → Return Line
    │       ├─ jam/off_flow → SystemLog + alert
    │       └─ passed → statistik daily QC
    ├─> Dashboard Livewire update (wire:poll)
    └─> Anotasi → Training Run → best.pt (jika auto_retrain)

Conveyor Service (separate MQTT broker topics)
    ├─> speed sensor / photoreflector
    └─> status → SystemLog
```

---

## 6. Catatan & Asumsi Penting

1. **LibQR**: KLementasi pyzbar dibutuhkan OpenCV build dengan zlib; jika build sulit di Windows, fallback ke `zxingcpp` atau remote decode service.
2. **Hardware**: ICAM-300 SDK tidak ada integrasi penuh di repo (hanya stub). Asumsi: decode QR diserahkan ke pipeline Python di depan kamera.
3. **best.pt**: Pastikan model dilatih pada dataset Ultra Milk yang relevan. Jika belum, latih ulang dengan dataset `ultra-milk-kecil.yolov8` sebelum deploy produksi.
4. **YOLO device**: Saat ini `device="cpu"` di infer.py. Untuk multi-kamera + multiple detection, gunakan `device=0` (CUDA) jika GPU tersedia.
5. **Conveyor off-flow detection**: Pendekatan visual (optical flow) adalah estimasi. Jika ada sensor fisik (photoelectric, infrared), lebih akurat integrasi sensor via MQTT.

---

## 7. File Kunci yang Akan Dimodifikasi

| File | Perubahan |
|------|-----------|
| `ml-service/requirements.txt` | Tambah pyzbar/zxingcpp, opencv-contrib |
| `ml-service/infer.py` | Multi-box, QR decode hook |
| `ml-service/main.py` | Per-camera thread, batch ingest |
| `ml-service/callbacks.py` | Batch payload support |
| `ml-service/config.py` | Multi-URL, per-camera settings |
| `app/Http/Controllers/Api/CameraController.php` | Multi-detection loop, QR save |
| `app/Models/Setting.php` | Active cameras config |
| `app/Models/Detection.php` | Sudah bagus, mungkin tambah constant CONVEYOR_EVENT_STATUSES |
| `app/Services/ArmMqttService.php` | Tambah `routeToReturn()` |
| `app/Services/ConveyorService.php` | [BARU] MQTT + sensor logic |
| `app/Models/Camera.php` | [BARU] Tabel kamera |
| `app/Models/ConveyorLine.php` | [BARU] Tabel conveyor |
| `app/Models/ReturnBatch.php` | [BARU] Manajemen return |
| `app/Jobs/ProcessFrameJob.php` | [BARU] Queue-based inference |
| `app/Livewire/LiveCamera/Index.php` | Multi-camera grid |
| `app/Livewire/Returns/Index.php` | [BARU] QC return review |
| `database/migrations/*_create_cameras_table.php` | [BARU] |
| `database/migrations/*_create_conveyor_lines_table.php` | [BARU] |
| `database/migrations/*_create_return_batches_table.php` | [BARU] |

---

## 8. Validasi & Testing Plan

- **Unit**: `infer.py` unit test dengan sample gambar Ultra Milk (passed/damaged/unreadable).
- **Integration**: POST camera detection dengan 3 box dalam satu frame → pastikan 3 `Detection` terbuat.
- **Pipeline**: Simulate QR decode + YOLO infer → pastikan `qr_value` terisi.
- **Conveyor**: Simulate optical flow video → pastikan event `jam`/`off_flow` tertrigger.
- **E2E**: Full scan dari kamera live → Dashboard → Return batch → Arm publish.

---

*Plan ini dirancang untuk dieksekusi langsung oleh Claude Code. Setiap task di atas sudah cukup spesifik untuk di-split menjadi sub-tasks implementasi.*
