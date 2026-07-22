# SETUP — SortVision

Panduan teknis lengkap menjalankan **SortVision** (Laravel 11 + Livewire 3) beserta **ML service** (FastAPI + YOLOv8) di mesin lokal.

Sistem terdiri dari **3 proses yang harus jalan bersamaan**:

| Proses | Perintah | Port |
|--------|----------|------|
| PHP / Laravel | `php artisan serve` | `8000` |
| Vite (asset HMR) | `npm run dev` | `5173` |
| ML service (FastAPI) | `uvicorn main:app --port 8001` | `8001` |

Plus **1 worker** wajib saat training: `php artisan queue:work`.

---

## 1. Prasyarat

| Kebutuhan | Versi | Catatan |
|-----------|-------|---------|
| PHP | **8.2+** | wajib (`composer.json: "php": "^8.2"`) |
| Composer | 2.x | |
| Node.js + npm | 18+ | |
| PostgreSQL | 14+ | DB default = **pgsql**, bukan SQLite |
| Python | **3.9–3.12** | Ultralytics tidak mendukung 3.13/3.14. Pakai `py -3.12` |

> ⚠️ **Catatan mesin ini:** PHP belum tentu terpasang (cek `php -v`). Jika belum ada, install PHP 8.2+ dulu — tanpa itu `composer install`, `artisan`, dan migrasi tidak bisa jalan. Python sistem = 3.14 (tidak didukung) → gunakan `py -3.12` untuk venv ml-service.

---

## 2. Setup Laravel (aplikasi utama)

### 2.1 Install dependency

```bash
composer install
npm install
```

### 2.2 Konfigurasi `.env`

Salin `.env.example` → `.env` bila belum ada, lalu isi:

```env
APP_KEY=                       # diisi via key:generate
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=sort_vision
DB_USERNAME=postgres
DB_PASSWORD=admin

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

# Integrasi ML service
ML_SERVICE_URL=http://127.0.0.1:8001
ML_CALLBACK_SECRET=ubah-jadi-rahasia-panjang
```

```bash
php artisan key:generate
```

> 🔑 **`ML_CALLBACK_SECRET` harus IDENTIK** di `.env` (root) dan `ml-service/.env`. Kalau beda, callback training ditolak middleware `verify.ml`.

### 2.3 Buat database

Pastikan database `sort_vision` sudah ada di PostgreSQL:

```bash
createdb -U postgres sort_vision
```

### 2.4 Migrasi + seed data demo

```bash
php artisan migrate:fresh --seed
php artisan storage:link
```

Seeder membuat: 1 user per role, 40 produk, 600 detection, 150 log, 120 anotasi, 2 training run selesai, matrix permission, dan settings singleton.

**Login demo** (semua password `password`):
- `admin@sortvision.test`
- `supervisor_qc@sortvision.test`
- `operator@sortvision.test`
- `viewer@sortvision.test`

### 2.5 Jalankan

Dua terminal terpisah:

```bash
php artisan serve
```

```bash
npm run dev
```

Buka **http://127.0.0.1:8000**.

---

## 3. Setup ML service (`ml-service/`)

### 3.1 Buat venv + install dependency

```bash
cd ml-service
py -3.12 -m venv .venv
.venv/Scripts/activate          # Windows (PowerShell/Git Bash)
pip install torch --index-url https://download.pytorch.org/whl/cpu
pip install -r requirements.txt
```

> Install **torch (CPU) lebih dulu** dari index CPU, baru `requirements.txt`.

### 3.2 Konfigurasi `ml-service/.env`

```env
LARAVEL_STORAGE_PATH=C:/Users/RASYA/Downloads/Innowork-dashboard-app-v1/storage/app
ML_CALLBACK_SECRET=ubah-jadi-rahasia-panjang   # SAMA dengan .env Laravel
```

`LARAVEL_STORAGE_PATH` = path absolut ke `storage/app` Laravel; service membaca gambar anotasi dari sini dan menulis `models/run-*/best.pt` ke sini juga.

### 3.3 Jalankan

```bash
uvicorn main:app --host 127.0.0.1 --port 8001 --reload
```

Cek liveness:

```bash
curl http://127.0.0.1:8001/health
```

Endpoint: `GET /health`, `POST /train` (async, 202), `POST /infer`.

---

## 4. Menjalankan Training (alur realtime)

1. **Wajib** jalankan queue worker (job `StartTrainingRun` adalah `ShouldQueue`; tanpa worker, run mandek di status `queued`):

   ```bash
   php artisan queue:work
   ```

2. Pastikan **ml-service jalan** (port 8001) dan **worker jalan**.
3. Buka menu **Training** di dashboard → **Start Run**.
4. Alur: `Training` → buat `TrainingRun (queued)` → job split anotasi `approved` ~80/20 → `MlClient::startTraining()` → service latih & kirim callback progress → baris ter-update realtime (`wire:poll` aktif selama run berjalan) → saat selesai, model auto-aktif di `Setting`.

Status run: `queued → exporting → training → completed / failed`. Metrik disimpan skala **0–100** (`metrics.map50`, `metrics.per_class[]`).

> ⚠️ Training CPU lambat. Default demo: `imgsz=320`, `batch=4`, `device=cpu`. Jaga epoch rendah (≤5).

### Model artefak

- Hasil bobot tersimpan di `storage/app/models/run-{id}/best.pt`.
- Model ultra-milk yang sudah ada: `ml-service/best.pt` dan `storage/app/models/run-1/best.pt` (identik, ~6.2 MB).

---

## 5. Perintah lain yang berguna

```bash
# Tes
php artisan test
php artisan test --filter=AuthenticationTest

# Lint / format
./vendor/bin/pint            # fix
./vendor/bin/pint --test     # cek saja

# Build asset produksi
npm run build

# Regenerasi QR produk
php artisan sortvision:regenerate-qr --missing
```

---

## 6. Troubleshooting

| Gejala | Penyebab | Solusi |
|--------|----------|--------|
| `php` tidak dikenal | PHP belum terpasang | Install PHP 8.2+, tambahkan ke PATH |
| Training stuck di `queued` | Worker tidak jalan | Jalankan `php artisan queue:work` |
| Callback training ditolak / run tak update | `ML_CALLBACK_SECRET` beda antar `.env` | Samakan secret di kedua file |
| Dashboard: "ML service offline" | ml-service mati | Jalankan `uvicorn ... --port 8001` |
| `No module named 'httpx'` | Dependency Python belum terpasang | Aktifkan venv, `pip install -r requirements.txt` |
| Error Ultralytics saat install | Python 3.13/3.14 | Buat venv dengan `py -3.12` |
| Gambar/QR produk 404 | Symlink storage belum dibuat | `php artisan storage:link` |
| Tabel kosong / error kolom | Migrasi/seed belum jalan | `php artisan migrate:fresh --seed` |
