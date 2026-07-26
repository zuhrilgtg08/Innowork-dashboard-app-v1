# Google Colab Training — Ultra Milk Dataset

Gunakan panduan ini untuk melatih model YOLOv8 dari dataset **ultra-milk-kecil.yolov8**
di Google Colab, kemudian mendaftarkannya ke SortVision sebagai model aktif.

## Prasyarat

- Akun Google + akses ke https://colab.research.google.com
- Dataset `ultra-milk-kecil.yolov8/` sudah ada di dalam repo ini (root folder project)
- Python 3.12 tersedia untuk persiapan lokal

## Ringkasan Alur

1. Buka notebook `ultra-milk-training.ipynb` di Colab
2. Notebook otomatis download dataset dari Roboflow
3. Jalankan cell berurutan: Mount Drive → Download Roboflow → Install → Train → Download `best.pt`
4. Copy `best.pt` ke `storage/app/models/run-{n}/best.pt`
5. Buat `TrainingRun` + arahkan `active_training_run_id`

Link Roboflow: `https://app.roboflow.com/ds/qFlbnnIoI8?key=jjFHrlZqjO`

## Langkah 1 — Persiapan Lokal

Pastikan Python 3.12 aktif (venv repo tidak wajib, karena ini hanya utilitas):

```bash
# dari root project
py -3.12 ml-service\colab_prepare.py
```

Output yang diharapkan:

- `ml-service/colab-artifacts/ultra-milk-yolo-ready/` — folder dataset dengan label
  kelas sudah di-rename menjadi QC SortVision:
  - `um_normal` → `passed`
  - `um_rusak` → `damaged`
- `ml-service/colab-artifacts/ultra-milk-yolo-ready.zip`
- `ml-service/colab-artifacts/ultra-milk-training.ipynb` — notebook Colab siap pakai

> Catatan: folder asli `ultra-milk-kecil.yolov8/` **tidak diubah**. Persiapan hanya
> membuat salinan di `ml-service/colab-artifacts/`.

## Langkah 2 — Persiapan Dataset di Colab

Upload dataset ke Google Drive, lalu buka notebook.

### Upload ke Google Drive

1. Buka https://drive.google.com
2. Upload `ml-service/colab-artifacts/ultra-milk-yolo-ready.zip`
3. Pastikan path di Drive: `/content/drive/MyDrive/ultra-milk-yolo-ready.zip`
   - Catatan: notebook akan otomatis ekstrak zip ini saat training
   - Atau kamu bisa ekstrak manual di Drive menjadi folder `ultra-milk-yolo-ready/`

### Buka Notebook di Colab

1. Buka https://colab.research.google.com
2. Upload `ml-service/colab-artifacts/ultra-milk-training.ipynb` sebagai notebook
3. Atau buka notebook dari GitHub jika sudah di-push
4. Pilih Runtime → **Change runtime type** → **GPU** (disarankan)
5. Jalankan cell berurutan

## Langkah 3 — Training di Colab

Notebook `ultra-milk-training.ipynb` memiliki 4 cell utama:

1. **Setup — Mount Drive & deteksi dataset** (otomatis)
2. **Install Ultralytics + Cek GPU**
3. **Train Model YOLOv8**
4. **Download best.pt**

Cell pertama otomatis:
- Mount Google Drive
- Mencari dataset di `/content/drive/MyDrive/ultra-milk-yolo-ready/`
- Jika ada zip, ekstrak otomatis
- Jika tidak ada, minta upload zip

Cell training otomatis menggunakan `DATA_YAML` dari cell pertama.

Rekomendasi konfigurasi Colab (GPU):
- Epochs: `10` (untuk demonstrasi; naikkan jika perlu)
- Batch: `16`
- `imgsz`: `640`
- `device`: `cuda`

Jika runtime kehabisan memori, turunkan batch ke `8` atau `4`.

## Langkah 4 — Download Model

Setelah training selesai, jalankan cell download:

```python
from google.colab import files
files.download('/content/runs/detect/train/weights/best.pt')
```

Simpan file sebagai `ultra-milk-best.pt` di komputer.

## Langkah 5 — Daftarkan Model ke SortVision

### 5a. Copy file model ke storage Laravel

```bash
copy ultramilk-best.pt C:\Users\RASYA\Downloads\Innowork-dashboard-app-v1\storage\app\models\run-1\best.pt
```

Pastikan folder `storage/app/models/run-1/` sudah ada, atau buat manual:

```bash
mkdir storage\app\models\run-1
```

### 5b. Buat TrainingRun + Setting melalui Laravel

Opsi termudah adalah via Tinker:

```bash
php artisan tinker
```

```php
>>> App\Models\TrainingRun::create([
...     'name' => 'ultra-milk-colab-1',
...     'status' => 'completed',
...     'epochs' => 10,
...     'progress' => 100,
...     'model_path' => 'models/run-1/best.pt',
...     'started_at' => now(),
...     'finished_at' => now(),
... ]);
>>> App\Models\Setting::current()->update(['active_training_run_id' => 1]);
>>> Cache::forget('settings.singleton');
```

> Jika ada error seed atau data duplicate, ubah `run-1` menjadi `run-2` dst.
> Pastikan `active_training_run_id` mengacu ke id `TrainingRun` yang baru dibuat.

## Langkah 6 — Verifikasi Inference

Jalankan ml-service + Laravel seperti biasa, lalu buka halaman **Live Camera**:

- `ML service` harus **online**
- Upload frame / gunakan ICAM-300 / simulator
- Verdict harus keluar sebagai `passed`, `damaged`, atau `recheck`
- Lihat **Logs** — harus muncul baris `Live inference: Passed ...` atau `Damaged ...`

## Catatan Penting

- Dataset hanya memiliki 2 kelas: `passed` dan `damaged`. Jika frame berisi
  QR unreadable atau scratch yang tidak dilatih, model kemungkinan besar
  mengembalikan `recheck` (threshold confidence tidak terpenuhi).
- Tambah kelas baru (mis. `scratched`) perlu:
  1. Tambahkan anotasi manual di SortVision untuk kelas tersebut
  2. Gabungkan dengan dataset ultra-milk saat export Ulang atau
  3. Tambahkan gambar ultra-milk dengan label baru dan retrain di Colab
- `infer.py` otomatis mengenali model QC jika setidaknya satu nama kelas ada di
  `Detection::STATUSES` (`passed`, `damaged`, dst). Karena kita mengganti label
  menjadi `passed` dan `damaged`, model langsung kompatibel tanpa perubahan kode.

## Troubleshooting

| Gejala | Solusi |
|---|---|
| Label keluar sebagai `um_normal` bukan `passed` | Pastikan `data.yaml` di Colab sudah diubah nama kelas, atau pakai `colab_prepare.py` untuk generate ulang |
| `ModuleNotFoundError: No module named 'ultralytics'` | Jalankan `!pip install ultralytics` di cell pertama Colab |
| Runtime GPU kehabisan memori | Turunkan `batch` ke 8 atau 4, atau gunakan runtime CPU (lebih lambat) |
| Model tidak muncul di Live Camera | Cek `storage/app/models/run-{n}/best.pt` ada, `TrainingRun` status `completed`, `Setting.active_training_run_id` mengacu ke run itu |

## Hasil Registrasi — 24 Juli 2026 (run-2)

Model hasil Colab (`ultra-milk-best.pt`, 6.2 MB, class names `{0: passed, 1: damaged}`,
50 epochs) sudah didaftarkan sebagai model live:

- **File model:** `storage/app/models/run-2/best.pt` (identik dgn `run-1/best.pt`, MD5 `7b24b823…`)
- **TrainingRun:** `ultra-milk-colab-final` (id `10`), status `completed`, `dataset_train=1524`, `dataset_val=327`
- **Model aktif:** `Setting.active_training_run_id = 10`, `ICAM_MODEL_PATH=models/run-2/best.pt`
- **Metrics (hasil `model.val()` nyata atas 327 gambar valid, skala 0–100):**

  | Metric | Overall | passed | damaged |
  |---|---|---|---|
  | mAP@50 | 99.5 | 99.5 | 99.5 |
  | Precision | 100.0 | 100.0 | 99.9 |
  | Recall | 100.0 | 100.0 | 100.0 |

> Metrics di atas dihitung ulang secara lokal dari `ml-service/colab-artifacts/ultra-milk-yolo-ready/valid`,
> **bukan** placeholder. Angka sangat tinggi karena valid split berasal dari distribusi yang
> sama dengan train — untuk evaluasi lebih ketat gunakan gambar conveyor nyata.

Setelah registrasi via DB, cache singleton settings di-bust (`Cache::forget('settings.singleton')`
atau hapus baris `settings.singleton` di tabel `cache`) agar app membaca run aktif yang baru.
