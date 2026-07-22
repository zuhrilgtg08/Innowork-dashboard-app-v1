# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**SortVision** — a Laravel 11 + Livewire 3 dashboard for a QC (quality-control) vision system that inspects products moving on a conveyor line: cameras scan QR codes and classify each item (passed / damaged / scratched / unreadable / etc.). The UI language is Indonesian; flash messages and comments are often in Indonesian.

## Commands

```bash
# Install
composer install
npm install

# Run the app (needs BOTH: PHP server + Vite dev server)
php artisan serve            # http://127.0.0.1:8000
npm run dev                  # Vite HMR for assets

# Database (PostgreSQL — see stack note below)
php artisan migrate
php artisan migrate:fresh --seed   # rebuild + seed demo data
php artisan db:seed
php artisan storage:link           # required: product images/QRs served from public disk

# Tests (PHPUnit)
php artisan test                                  # all
php artisan test --filter=AuthenticationTest      # single class
php artisan test tests/Feature/ProfileTest.php    # single file

# Lint / format (Laravel Pint)
./vendor/bin/pint            # fix
./vendor/bin/pint --test     # check only

# Assets
npm run build                # production build
```

## Stack notes

- **Database is PostgreSQL** (`DB_CONNECTION=pgsql`, db `sort_vision`), not the Laravel-default SQLite. The `.env.example` may differ — the running app uses Postgres.
- Sessions, cache, and queue all use the **database** driver.
- Tests run with `APP_ENV=testing` (see `phpunit.xml`). The sqlite/`:memory:` lines there are commented out, so tests run against the configured DB unless you enable them.
- Auth scaffolding is **Laravel Breeze (Livewire/Volt stack)**.

## Architecture

This is a **Livewire-first** app: there are almost no traditional controllers. Each page is a full-page Livewire component mapped directly in `routes/web.php`.

- **Routing** (`routes/web.php`): all app pages are behind the `auth` middleware group and route straight to a Livewire class, e.g. `Route::get('products', ProductsIndex::class)`. `routes/auth.php` holds Breeze auth routes; `/` redirects to `dashboard` or `login`.
- **Two flavors of Livewire components:**
  - **Domain pages** — class-based components in `app/Livewire/*/Index.php` (Products, Users, Roles, Dashboard, LiveCamera, Training, Annotation, Logs, Settings), each rendering a Blade view in `resources/views/livewire/`. These use `#[Layout('layouts.app', ['title' => '...'])]`.
  - **Auth/profile pages** — **Volt** single-file components (`resources/views/livewire/pages/auth/*.blade.php`, `profile/*`). Tests target these via `Volt::test(...)` / `assertSeeVolt(...)`, not class instantiation.
- **Layouts:** `resources/views/layouts/app.blade.php` (authenticated shell + `livewire/layout/navigation`) and `guest.blade.php`.

### Domain model (`app/Models`)

The core data flow: **Product** (catalogue) → has many **Detection** (individual QC scan events) → aggregated on the **Dashboard**. **SystemLog** captures device/system events. **User** + **RolePermission** drive access.

Models encode their domain enums as `const` arrays with UI metadata (label + Tailwind color), and these are the single source of truth referenced by validation, seeders, and views:

- `Product::STATUSES`, `Product::qrPayload()` (QR content format `SORTVISION|{code}|{sku}`)
- `Detection::STATUSES`, `Detection::FAILED_STATUSES` (which statuses count as defects)
- `SystemLog::LEVELS`, `SystemLog::SOURCES`
- `User::ROLES` (`admin`, `supervisor_qc`, `operator`, `viewer`)

When adding a status/role/level, update the model `const` — do not hardcode the values elsewhere.

### Roles & permissions

`RolePermission` defines a **role × module access matrix** (`f`=Full, `w`=Write, `r`=Read, `-`=None across `MODULES`). Key methods:
- `RolePermission::defaults()` — the baseline matrix shipped/seeded.
- `RolePermission::matrix()` — merges DB overrides on top of defaults for every role/module (DB rows are sparse; missing pairs fall back to defaults).
- The Roles page (`app/Livewire/Roles/Index.php`) edits and persists this matrix via `updateOrCreate`.

**Important:** this matrix is currently informational/editable but is **not enforced** — routes are gated only by the `auth` middleware, and components do no per-module permission checks. Don't assume a role restriction exists just because the matrix defines one.

### Common Livewire component conventions (follow when editing/adding pages)

Look at `app/Livewire/Products/Index.php` as the reference implementation. Recurring patterns:
- `#[Url]` public props for `search`/`status`/filters so state lives in the query string; `WithPagination` with `resetPage()` in `updating()` when a filter changes.
- CRUD via a modal: `create()` / `edit($id)` / `save()` / `confirmDelete($id)` / `delete($id)` / `closeModal()` + `resetForm()`, with a `$flash` string for the success message.
- `rules()` method for validation; `Rule::unique(...)->ignore($this->editingId)` for edit-safe uniqueness.
- File uploads via `WithFileUploads`, stored on the `public` disk (`->store('products', 'public')`), old files deleted on replace/delete.
- QR codes generated with `simplesoftwareio/simple-qrcode` as SVG into `storage/app/public/qrcodes/` and tracked in `products.qr_path`.

### ML / Vision integration (FastAPI + YOLO)

The heavy computer-vision work lives in a **separate Python service** under `ml-service/` (FastAPI + Ultralytics YOLOv8), not in PHP. Laravel talks to it over HTTP and the service talks back via **signed callbacks**.

- **`ml-service/`** — `main.py` exposes `/health`, `/train` (async, returns 202), `/infer` (one frame → QC verdict). Training runs in a FastAPI `BackgroundTask`; `callbacks.py` POSTs progress/complete/fail back to Laravel with an `X-ML-Signature` HMAC. Run it on port 8001. See `ml-service/README.md`.
- **`App\Services\MlClient`** — the only thing that should call the ML service. All calls are best-effort (transport errors caught → screens degrade to "ML service offline" instead of 500s). `healthy()`, `startTraining()`, `infer()`.
- **Config** in `config/services.php` under `ml` (`ML_SERVICE_URL`, `ML_CALLBACK_SECRET`).
- **Callbacks land in `App\Http\Controllers\Api\MlCallbackController`** (`routes/api.php`, prefix `ml`), guarded by the **`verify.ml`** middleware (`VerifyMlSignature`, HMAC-SHA256 over the raw body; aliased in `bootstrap/app.php`). This is the *only* traditional controller — the caller is a machine, not a browser.

**Training flow (realtime):** Training screen `startRun()` → creates a `TrainingRun` (status `queued`) → dispatches `StartTrainingRun` job → job splits approved `Annotation`s ~80/20 and calls `MlClient::startTraining()` → service trains and streams progress callbacks that advance the row → the Blade view `wire:poll`s **only while a run isActive**. On complete, the run's model is auto-activated in `Setting`. `TrainingRun::STATUSES` (`queued/exporting/training/completed/failed`); metrics are stored on the **0–100 scale** (`metrics.map50`, `metrics.per_class[]`).

**Annotation dataset:** `Annotation` (status `pending`/`approved`, source `ai`/`human`) is the training corpus; only `approved` rows are exported. Reuses `Detection::STATUSES` keys as class labels.

### Settings & public QR page

- **`Setting`** is a cached singleton (`Setting::current()`, `Cache::rememberForever` busted on save). It has `$attributes` defaults so `firstOrCreate([])` always yields a well-formed row (Postgres column defaults do **not** apply to Eloquent inserts that omit the column). Holds `confidence_threshold`, automation toggles, and `active_training_run_id` (the live model). Settings page persists via `Setting::current()->update(...)`.
- **Public QR page:** a product's QR now encodes a public URL `/p/{qr_token}` (unguessable 40-char token, not the old `SORTVISION|code|sku` string). Route `public.product` → `App\Livewire\PublicProduct` (guest layout, no auth) shows the product + its `latestDetection` QC verdict. `Product::regenerateQr()` (re)writes the SVG; `php artisan sortvision:regenerate-qr [--missing]` backfills.

### Seeding

`DatabaseSeeder` builds a full demo dataset: one user per role (`*@sortvision.test`, password `password`), plus factory-generated users, 40 products, 600 detections, 150 system logs, 120 annotations (~106 approved), 2 completed training runs, the baseline permission matrix, and the settings singleton (pointing at the latest run). Use `migrate:fresh --seed` to reset to a known state.

4. QR Code Publik via Ngrok
- QR code menggunakan url('/p/'.$qr_token) yang otomatis menggunakan APP_URL dari .env
- Untuk akses publik: ngrok http 8000 → copy URL ngrok → set APP_URL di .env → php artisan sortvision:regenerate-qr untuk regenerate QR
- Halaman publik: /p/{token} — menampilkan nama produk, foto, dan status QC terakhir (route public.product)
