# Google Colab Training — Ultra Milk Dataset

Gunakan panduan ini untuk melatih model YOLOv8 dari dataset **ultra-milk-kecil.yolov8**
di Google Colab, kemudian mendaftarkannya ke SortVision sebagai model aktif.

## Prasyarat

- Akun Google + akses ke https://colab.research.google.com
- Dataset `ultra-milk-kecil.yolov8/` sudah ada di dalam repo ini (root folder project)
- Python 3.12 tersedia untuk persiapan lokal

## Ringkasan Alur — Opsi Dataset

Ada dua cara menyiapkan dataset di Colab:

**Opsi A — Upload zip via sidebar Colab** (cepat, dataset kecil < 1GB):
1. Persiapan lokal: zip dataset → `ml-service/colab-artifacts/`
2. Upload zip (+ notebook `.ipynb`) ke Colab
3. Unzip otomatis, train, download `best.pt`

**Opsi B — Simpan di Google Drive** (disarankan, dataset besar):
1. Persiapan lokal: zip dataset → `ml-service/colab-artifacts/`
2. Upload zip atau folder ke Google Drive: `/content/drive/MyDrive/ultra-milk-yolo-ready/`
3. Mount Drive di Colab, train langsung dari Drive, download `best.pt`

Kedua opsi menghasilkan model yang sama. Notebook `ultra-milk-training.ipynb` sudah
mendukung kedua mode.

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

Notebook `ultra-milk-training.ipynb` sudah menyediakan dua mode. Pilih salah satu:

### Opsi A — Upload zip via sidebar Colab

1. Buka https://colab.research.google.com → buat Notebook baru (Python 3, GPU disarankan)
2. Upload `ml-service/colab-artifacts/ultra-milk-yolo-ready.zip` via sidebar → **Files** → **Upload**
3. Upload `ml-service/colab-artifacts/ultra-milk-training.ipynb` sebagai notebook
4. Di notebook, jalankan cell **Opsi A: Unzip dataset dari upload Colab**
5. Lanjut ke Langkah 3

### Opsi B — Dataset dari Google Drive

1. Upload `ultra-milk-yolo-ready.zip` atau folder hasil prepare ke Google Drive
2. Pastikan path di Drive: `/content/drive/MyDrive/ultra-milk-yolo-ready/`
   - Jika masih dalam bentuk zip, ekstrak dulu di Drive atau gunakan cell Python:
     ```python
     import zipfile
     with zipfile.ZipFile('/content/drive/MyDrive/ultra-milk-yolo-ready.zip', 'r') as z:
         z.extractall('/content/drive/MyDrive/')
     ```
3. Buka notebook `ultra-milk-training.ipynb` di Colab
4. Jalankan cell **Opsi B: Mount Google Drive**
5. Di cell **Tentukan base path dataset**, pilih baris:
   ```python
   base = Path('/content/drive/MyDrive/ultra-milk-yolo-ready')
   ```
   (comment baris `base = Path('/content/ultra-milk-yolo-ready')`)
6. Lanjut ke Langkah 3

## Langkah 3 — Training di Colab

Notebook `ultra-milk-training.ipynb` memiliki cell berurutan:

1. **Unzip (jika pakai Opsi A)**
2. **Mount Drive (jika pakai Opsi B)**
3. **Tentukan base path dataset** — pilih baris sesuai sumber dataset
4. **Install + Train**
5. **Download `best.pt`**

Cell training menggunakan `DATA_YAML` dan `DATA_BASE` yang otomatis diset dari cell base path, jadi kamu hanya perlu menjalankan cell berurutan.

Rekomendasi konfigurasi Colab (GPU):

- Epochs: `10` (untuk demonstrasi; naikkan jika perlu)
- Batch: `16`
- `imgsz`: `640`
- `device`: `cuda`

Jika runtime kehabisan memori, turunkan batch ke `8` atau `4`.

---

Jika kamu membuat notebook manual, cell training harus pakai path sesuai sumber:

**Dari upload Colab:**
```python
base = Path("/content/ultra-milk-yolo-ready")
data_yaml = str(base / "data.yaml")
```

**Dari Google Drive:**
```python
from google.colab import drive
drive.mount('/content/drive')
base = Path("/content/drive/MyDrive/ultra-milk-yolo-ready")
data_yaml = str(base / "data.yaml")
```

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
