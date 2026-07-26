# SortVision

Laravel 11 + Livewire 3 dashboard untuk sistem QC vision di lini conveyor: kamera memindai
QR dan mengklasifikasikan tiap produk (`passed` / `damaged` / `recheck`, dst). Computer-vision
berjalan di service Python terpisah (`ml-service/`, FastAPI + YOLOv8). Detail arsitektur ada di
[`CLAUDE.md`](CLAUDE.md); setup lengkap di [`SETUP.md`](SETUP.md).

## Quickstart — Live Camera & Training

Butuh **4 terminal** (Laravel, Vite, Queue worker, ML service):

```bash
# 1) Laravel  — pakai 127.0.0.1 agar konsisten dgn callback ml-service
php artisan serve --host=127.0.0.1 --port=8000
# 2) Vite
npm run dev
# 3) Queue worker (WAJIB untuk callback training)
php artisan queue:work
# 4) ML service (FastAPI + YOLOv8)
cd ml-service && .venv\Scripts\activate && uvicorn main:app --host 127.0.0.1 --port 8001 --reload
```

**Live Camera** (`/live-camera`) — webcam (client-side), ICAM-300 (RTSP), atau simulator
(`samples/conveyor.mp4` → MJPEG `/camera/stream`). Auto-infer aktif saat `ICAM_AUTO_INFER=true`
di `ml-service/.env`; deteksi dipush ke feed tiap `ICAM_INFER_INTERVAL` detik.

**Detection** (`/detection`) — deteksi objek real-time **100% di browser** (TensorFlow.js +
COCO-SSD), tanpa ml-service. Lihat [`DETECTION_PLAN.md`](DETECTION_PLAN.md).

**Training** (`/training`) — annotation → export ~80/20 → train di ml-service → callback progres →
auto-activate model. Model aktif dilacak di `Setting.active_training_run_id`. Untuk melatih di
Google Colab dan mendaftarkan hasilnya, ikuti [`COLAB_TRAINING.md`](COLAB_TRAINING.md).

Konfigurasi penting:

- `APP_URL` (Laravel) **=** `LARAVEL_URL` (ml-service) `= http://127.0.0.1:8000`
- `ML_CALLBACK_SECRET` harus identik di kedua `.env`
- Model live saat ini: `storage/app/models/run-2/best.pt` (ultra-milk, `passed`/`damaged`)

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
