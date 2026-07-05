# SortVision — Panduan Setup Lokal (Windows + Laragon)

Panduan ini mengikuti langkah yang sudah terbukti berhasil di environment Windows + Laragon (PHP di luar PATH, PostgreSQL tanpa driver `pdo_pgsql`, Python system 3.14). Sesuaikan jika environment kamu berbeda.

## Prasyarat

- **Laragon** (PHP 8.2+, Composer, Node.js) — https://laragon.org/download/
- **Python 3.12** terpasang (Ultralytics YOLO butuh 3.9–3.12; Python 3.13/3.14 tidak didukung)
- Git

---

## 1. Buka terminal Laragon

Semua perintah di bawah dijalankan dari **Laragon Terminal** (Laragon → menu **Tools** → **Terminal**), karena PATH untuk `php`, `composer`, `npm` sudah otomatis dikonfigurasi di sana.

Kalau memakai terminal lain (PowerShell/VS Code) dan `php`/`composer` tidak dikenali, jalankan lewat path lengkap Laragon, contoh:

```bash
"C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan migrate
```

(Sesuaikan versi PHP dengan folder yang ada di `C:\laragon\bin\php\`.)

Cek dulu semua tool terbaca:

```bash
php --version
composer --version
node --version
npm --version
```

## 2. Masuk ke folder project

```bash
cd C:\Users\RASYA\Downloads\Innowork-dashboard-app-v1
```

## 3. Install dependency PHP & Node

```bash
composer install
npm install
```

## 4. Siapkan file `.env`

Kalau `.env` belum ada, salin dari contoh:

```bash
copy .env.example .env
```

Generate `APP_KEY`:

```bash
php artisan key:generate
```

### Database

Project ini didokumentasikan untuk **PostgreSQL**, tapi kalau `pdo_pgsql` tidak tersedia di instalasi PHP kamu (`php -m` tidak menampilkan `pgsql`), pakai **SQLite** sebagai gantinya — ini yang dipakai di setup referensi ini.

Buka `.env`, set:

```env
DB_CONNECTION=sqlite
# DB_HOST=127.0.0.1
# DB_PORT=5432
# DB_DATABASE=sort_vision
# DB_USERNAME=postgres
# DB_PASSWORD=admin
```

Buat file database kosong (wajib untuk SQLite — Laravel tidak membuatnya otomatis):

```bash
type nul > database\database.sqlite
```

> Kalau Laragon kamu punya ekstensi `pdo_pgsql` aktif dan mau pakai PostgreSQL sungguhan, buat database `sort_vision` dulu (`psql -U postgres -c "CREATE DATABASE sort_vision;"`), lalu set `DB_CONNECTION=pgsql` beserta host/port/user/password yang sesuai.

### ML Callback Secret

Tambahkan di `.env` (harus sama persis dengan `ml-service/.env`):

```env
ML_SERVICE_URL=http://127.0.0.1:8001
ML_CALLBACK_SECRET=<string acak panjang, generate sekali dan pakai di kedua .env>
```

## 5. Migrate & seed database

```bash
php artisan migrate:fresh --seed
php artisan storage:link
```

Ini akan membuat akun demo untuk setiap role di `*@sortvision.test` dengan password `password` (contoh: `admin@sortvision.test`).

---

## 6. Jalankan aplikasi (4 terminal)

Buka **4 terminal Laragon terpisah**, masing-masing `cd` ke folder project dulu, lalu jalankan salah satu perintah berikut per terminal:

### Terminal 1 — Laravel server
```bash
php artisan serve
```
→ http://127.0.0.1:8000

### Terminal 2 — Vite (assets & HMR)
```bash
npm run dev
```

### Terminal 3 — Queue worker (wajib untuk fitur Training)
```bash
php artisan queue:work
```
> Tanpa ini, training run akan tersangkut selamanya di status `queued` karena job training dikirim lewat queue database.

### Terminal 4 — ML Service (Python/FastAPI, opsional untuk fitur AI)
Lihat bagian [ML Service](#ml-service-python) di bawah untuk setup awal, lalu:
```bash
cd ml-service
.venv\Scripts\activate
uvicorn main:app --host 127.0.0.1 --port 8001 --reload
```
→ http://127.0.0.1:8001/health

---

## 7. Login

Buka http://127.0.0.1:8000

| Role | Email | Password |
|---|---|---|
| Admin | admin@sortvision.test | password |
| Supervisor QC | supervisor_qc@sortvision.test | password |
| Operator | operator@sortvision.test | password |
| Viewer | viewer@sortvision.test | password |

---

## ML Service (Python)

Service ini opsional untuk fitur **Live Camera** (inference) dan **Training** (melatih model YOLO). Tanpa service ini, aplikasi tetap jalan tapi kedua fitur tersebut akan menampilkan status "ML service offline".

### Setup awal (sekali saja)

```bash
cd ml-service
py -3.12 -m venv .venv
.venv\Scripts\activate
pip install torch --index-url https://download.pytorch.org/whl/cpu
pip install -r requirements.txt
```

> Kalau `py -3.12` tidak dikenali, cek versi Python yang terpasang dengan `py --list`. Ultralytics (YOLO) **tidak mendukung Python 3.13/3.14** — wajib pakai 3.9–3.12.

### Konfigurasi `ml-service/.env`

```env
LARAVEL_STORAGE_PATH=C:/Users/RASYA/Downloads/Innowork-dashboard-app-v1/storage/app
LARAVEL_URL=http://127.0.0.1:8000
ML_CALLBACK_SECRET=<harus sama persis dengan ML_CALLBACK_SECRET di .env Laravel>
BASE_MODEL=yolov8n.pt
```

### Verifikasi instalasi

```bash
.venv\Scripts\python.exe -c "import httpx, fastapi, ultralytics; print('OK')"
```

Kalau muncul error `ModuleNotFoundError: No module named 'httpx'` (atau modul lain), berarti `pip install -r requirements.txt` belum selesai atau dijalankan di Python/venv yang salah — ulangi langkah instalasi di atas dengan Python 3.12.

---

## Kamera ICAM-300 (Advantech)

Live Camera bisa memakai **webcam browser** (default, demo) atau **ICAM-300** (smart camera industri via RTSP). Inference tetap di ml-service (YOLO) — kamera hanya sumber video, jadi **tidak perlu kode di dalam kamera**.

### Uji tanpa hardware (simulator)
1. Pastikan ml-service jalan (Terminal 4). Biarkan `ICAM_RTSP_URL` kosong di `ml-service/.env` → mode simulator (frame sintetis / video sample).
2. Buka `http://127.0.0.1:8001/camera/stream` di browser → harus muncul video MJPEG.
3. Di dashboard: **Settings → Camera → Sumber Kamera = ICAM-300 (RTSP)** lalu Save. Buka **Live Camera** → video tampil dengan badge LIVE.
4. (Opsional) Set `ICAM_AUTO_INFER=true` di `ml-service/.env` + restart ml-service + jalankan queue worker → deteksi otomatis mengalir ke feed & tabel `detections` tiap `ICAM_INFER_INTERVAL` detik.

### Saat unit ICAM-300 tersedia
1. Hubungkan kamera ke jaringan (GbE), set IP via web service bawaan, dan set kamera ke status **playing** (≥5fps) agar RTSP di port 8550 aktif.
2. Di `ml-service/.env`: `ICAM_RTSP_URL=rtsp://<ip-kamera>:8550/video`, restart ml-service.
3. Di dashboard Settings, isi juga **RTSP URL ICAM-300** (untuk referensi UI). Selesai — alur lainnya tak berubah.

> Detail env & endpoint: lihat `ml-service/README.md`.

---

## Troubleshooting

| Gejala | Penyebab | Solusi |
|---|---|---|
| `php`/`composer` tidak dikenali | PATH belum di-set, atau di terminal selain Laragon | Pakai Laragon Terminal, atau panggil lewat path lengkap `C:\laragon\bin\php\...\php.exe` |
| `could not find driver` (pgsql) | Ekstensi `pdo_pgsql` tidak aktif di PHP | Pakai SQLite (lihat langkah 4), atau aktifkan `pdo_pgsql` di `php.ini` |
| `Database file ... does not exist` | File `database/database.sqlite` belum dibuat | `type nul > database\database.sqlite` |
| `'vite' is not recognized` | `npm install` belum dijalankan | `npm install` lalu `npm run dev` lagi |
| Training tersangkut di status `queued` | Queue worker tidak jalan | Jalankan `php artisan queue:work` di terminal terpisah |
| Live Camera / Training bilang "ML service offline" | ml-service belum dijalankan, atau `.venv` belum lengkap | Jalankan Terminal 4, cek `http://127.0.0.1:8001/health` |
| `No module named 'httpx'` di ml-service | Dependency Python belum terinstall di venv yang benar | Ulangi setup ML Service di atas, pastikan pakai Python 3.12 |
| Training callback gagal / run tidak pernah `completed` | `ML_CALLBACK_SECRET` beda antara `.env` Laravel dan `ml-service/.env` | Samakan persis nilainya di kedua file |
| Video ICAM-300 tidak muncul di Live Camera | ml-service mati, atau Settings masih `webcam`, atau kamera belum "playing" | Cek `http://127.0.0.1:8001/camera/stream`; Settings → Camera → ICAM-300; pastikan kamera playing (≥5fps) |
| Deteksi ICAM tidak masuk ke feed | `ICAM_AUTO_INFER` masih `false` atau queue worker mati | Set `ICAM_AUTO_INFER=true`, restart ml-service, jalankan `php artisan queue:work` |
