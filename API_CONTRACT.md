# SortVision — Kontrak API Mobile (Opsi A)

Kontrak REST API untuk mobile app SortVision, bagian dari **Opsi A tanpa
Jetson Nano** (mobile/web → backend Laravel + MQTT broker + ml-service → ESP32
via WiFi/MQTT langsung → motor). ESP32 subscribe command langsung dari broker
yang sama; **tidak ada** perantara compute (Jetson) di jalur ini. Lihat
`Opsi-a&b.md` untuk keputusan arsitekturnya.

- **Base URL:** `http://<host>:8000/api`
- **Format:** JSON (kirim `Accept: application/json`).
- **Auth:** Laravel Sanctum **personal access token** (Bearer). Ini terpisah
  dari sesi web Livewire/Breeze — mobile app pakai token, dashboard web tetap
  pakai cookie session.

Header untuk endpoint terproteksi:

```
Authorization: Bearer <token>
Accept: application/json
```

## Kode error umum

| Kode | Arti |
|---|---|
| `401` | Token tidak ada/invalid, atau kredensial login salah |
| `422` | Validasi request gagal (body `{ "message", "errors": { field: [..] } }`) |

Status implementasi: ✅ = sudah diimplementasi di PR ini.

---

## Auth

### ✅ `POST /api/auth/login`

Tukar email + password dengan personal access token. Publik (tanpa token).

**Request**
```json
{ "email": "operator@sortvision.test", "password": "password" }
```

**200**
```json
{
  "token": "12|abcdef...plaintext-sanctum-token",
  "user": { "id": 3, "name": "Operator Satu", "email": "operator@sortvision.test", "role": "operator" }
}
```

- `role` adalah salah satu dari `User::ROLES` (`admin`, `supervisor_qc`, `operator`, `viewer`).
- **401** kalau email/password salah. **422** kalau field kosong/format salah.

### ✅ `POST /api/auth/logout`

Revoke token yang dipakai request ini. Butuh Bearer token.

**200** `{ "message": "Logged out." }`

### ✅ `GET /api/auth/me`

User dari token aktif. Butuh Bearer token.

**200** `{ "user": { "id": 3, "name": "...", "email": "...", "role": "operator" } }`

---

## Data & status (butuh Bearer token)

### ✅ `GET /api/status`

Status sistem ringkas untuk dashboard mobile. Field `status` mengikuti
konektivitas MQTT broker (`online` bila broker terhubung).

**200**
```json
{
  "status": "online",
  "mqtt_connected": true,
  "app_name": "SortVision",
  "timezone": "Asia/Jakarta",
  "timestamp": "2026-07-14T08:30:00+00:00"
}
```

### ✅ `GET /api/detections`

List deteksi QC terbaru (urut `detected_at` desc), paginasi sederhana.

**Query params (opsional)**
- `status` — filter salah satu key `Detection::STATUSES`
  (`passed`, `unreadable`, `damaged`, `scratched`, `returned`, `recheck`).
  Nilai lain → **422**.
- `per_page` — 1..100, default `20`.

**200**
```json
{
  "data": [
    {
      "id": 512,
      "code": "SCN-8A21XZ",
      "status": "damaged",
      "status_label": "Damaged",
      "camera": "ICAM-300",
      "conveyor": "LINE-A",
      "confidence": "92.50",
      "qr_value": null,
      "detected_at": "2026-07-14T08:29:41+00:00"
    }
  ],
  "meta": { "current_page": 1, "per_page": 20, "total": 137, "last_page": 7 }
}
```

### ✅ `GET /api/arm`

Status terakhir robotic arm (dari model `ArmStatus`, singleton yang di-update
oleh consumer MQTT `mqtt:listen`).

**200**
```json
{
  "state": "running",
  "state_label": "Running",
  "detail": "Sorting batch A",
  "last_command": "start",
  "telemetry": { "axis": [12, 40, 0, 90, 0, 15] },
  "reported_at": "2026-07-14T08:29:55+00:00"
}
```

- `state` ∈ `ArmStatus::STATES` (`idle`, `running`, `error`).

---

## Dashboard & profil (Fase 4)

### ✅ `GET /api/stats/dashboard`

Angka agregat QC untuk dashboard mobile. Mencerminkan `App\Livewire\Dashboard`
supaya dashboard web dan mobile tidak berbeda arti. Butuh akses **read** modul
`Dashboard`.

**Query**: `range` — `today` (default), `7d`, atau `30d`. Nilai lain → `422`.

**200**
```json
{
  "range": "today",
  "stats": {
    "total": 128,
    "passed": 96,
    "pass_rate": 75.0,
    "unreadable": 12,
    "defective": 15,
    "returned": 5,
    "throughput_per_minute": 2.1,
    "active_cameras": 4
  },
  "distribution": [
    { "key": "passed", "label": "Passed", "color": "green", "count": 96, "pct": 75.0 }
  ],
  "trend": [
    { "label": "00:00", "total": 4, "passed": 3, "failed": 1 }
  ],
  "generated_at": "2026-07-26T08:29:55+00:00"
}
```

- `distribution` selalu memuat **semua** key `Detection::STATUSES`, termasuk yang
  bernilai 0, supaya grafik klien tidak berubah bentuk saat status hilang-muncul.
- `trend` selalu bersumbu waktu rata: 24 bucket per jam untuk `today`, 7/30
  bucket harian untuk `7d`/`30d`. Periode sepi dikirim sebagai nol, bukan
  dilewati.
- `throughput_per_minute` sengaja **tidak** mengikuti `range` — angka ini
  menggambarkan kondisi line saat ini (60 menit terakhir).

### ✅ `GET /api/profile` · `PUT|PATCH /api/profile` · `PUT /api/profile/password`

Akun milik user yang sedang login. **Tidak** digerbangi `module:` — setiap role
berhak menyunting profilnya sendiri, termasuk role yang tidak punya akses modul
`Users` (operator, viewer). Sasarannya selalu pemegang token, tidak pernah id
dari body request.

**`PUT /api/profile`** — body `{ "name", "email" }`. Mengganti email menghapus
status verifikasi (`email_verified_at` → `null`), sama seperti form profil web.
`422` bila email sudah dipakai user lain; email yang sama dengan milik sendiri
bukan konflik.

**`PUT /api/profile/password`** — body `{ "current_password", "password",
"password_confirmation" }`. `current_password` wajib benar (`422` bila salah):
token yang dicuri saja tidak boleh cukup untuk mengunci pemilik asli dari
akunnya. Setelah berhasil, **token lain dicabut** dan token yang dipakai request
ini dipertahankan supaya aplikasi tidak melogout dirinya sendiri.

---

## Annotation / labelling (Fase 5)

Antrian labelling data training untuk mobile. Mencerminkan
`App\Livewire\Annotation\Index` — aturan yang sama (kelas visual saja yang
bisa disetujui, sumber `ai`/`human`, auto-retrain opportunistik) berlaku di
kedua tempat. Butuh akses modul `Annotation` (`read` untuk melihat, `write`
untuk approve/relabel).

### ✅ `GET /api/annotations/queue`

Deteksi yang belum dilabeli: punya frame asli, atau berstatus kegagalan QC /
workflow (`damaged`, `scratched`, `unreadable`, `recheck`, `returned`).
Dipaginasi seperti endpoint list lainnya (`data` + `meta`).

**Query params (opsional)**
- `status` — filter salah satu key `Detection::STATUSES`. Nilai lain → `422`.
- `per_page` — 1..100, default `20`.

**200**
```json
{
  "data": [
    {
      "id": 512,
      "code": "SCN-8A21XZ",
      "status": "damaged",
      "status_label": "Damaged",
      "status_color": "red",
      "product": { "id": 7, "name": "Yogurt Strawberry", "code": "PRD-001" },
      "image_url": "http://.../storage/frames/512.jpg",
      "confidence": "92.50",
      "detected_at": "2026-07-14T08:29:41+00:00"
    }
  ],
  "meta": { "current_page": 1, "per_page": 20, "total": 8, "last_page": 1 }
}
```

### ✅ `GET /api/annotations/stats`

Ringkasan yang tampil di atas antrian.

**200** `{ "data": { "pending": 8, "labelled": 106 } }`

### ✅ `POST /api/annotations/{detection}/approve`

Konfirmasi label AI (status deteksi itu sendiri) sebagai ground truth. Hanya
status kelas visual (`Detection::TRAINABLE_STATUSES`: `passed`, `unreadable`,
`damaged`, `scratched`) yang bisa disetujui apa adanya — `422` untuk status
workflow (`returned`, `recheck`), dan `422` bila deteksi tidak punya gambar
(tidak ada `frame_path` maupun foto produk).

**200** `{ "message": "Label disetujui & masuk dataset." }`

### ✅ `POST /api/annotations/{detection}/relabel`

Koreksi label ke kelas lain. Body `{ "label" }` — harus salah satu
`Detection::TRAINABLE_STATUSES`, nilai lain → `422`. Menulis ulang anotasi yang
sama (`updateOrCreate` per `detection_id`), bukan duplikat, sehingga approve
lalu relabel pada deteksi yang sama meninggalkan satu baris anotasi.

**200** `{ "message": "Label diperbarui ke \"Scratched\"." }`

---

## Scan QR (Fase 5)

### ✅ `POST /api/products/scan`

Menerjemahkan hasil scan kamera menjadi produk + verdict QC terakhirnya. Butuh
akses **read** modul `Product`.

Body `{ "qr_value" }` — isi apa pun yang terbaca kamera. Normalnya URL publik
yang di-encode QR (`/p/{qr_token}`), tapi `Product::resolveByQrValue()` juga
menerima **token telanjang** dan payload lama `SORTVISION|{code}|{sku}`, jadi QR
yang dicetak sebelum pindah ke format URL tetap bisa discan.

**200**
```json
{
  "data": {
    "product": { "id": 7, "code": "PRD-001", "name": "Yogurt Strawberry", "...": "..." },
    "latest_detection": {
      "id": 512, "status": "damaged", "status_label": "Damaged",
      "camera": "ICAM-300", "conveyor": "LINE-A",
      "confidence": "92.50", "detected_at": "2026-07-14T08:29:41+00:00"
    }
  }
}
```

- `latest_detection` bernilai `null` untuk produk yang belum pernah discan.
- **404** `{ "message": "QR tidak dikenali. Produk tidak ditemukan." }`.

---

## Conveyor monitoring (Fase 5)

Anomali conveyor **tidak punya tabel sendiri** — `ConveyorService::raiseAlert()`
menyimpannya sebagai `SystemLog` dengan `source = 'conveyor'` dan jenis event di
`context.event`. Digerbangi modul `Live Camera` (sama seperti endpoint arm;
`RolePermission::MODULES` tidak punya entri `Conveyor`).

### ✅ `GET /api/conveyor/status`

**200**
```json
{
  "data": {
    "broker_connected": true,
    "commands": ["start", "stop", "reverse", "speed"],
    "events": ["jam", "off_flow"],
    "window_hours": 24,
    "counts": { "jam": 1, "off_flow": 2 },
    "total_alerts": 3,
    "latest_alert": { "id": 91, "level": "error", "event": "jam", "conveyor": "LINE-A", "metrics": {}, "message": "...", "logged_at": "..." }
  }
}
```

- `counts` dibatasi jendela 24 jam; `latest_alert` **tidak** — itu kondisi
  terakhir yang diketahui, seberapa pun lamanya.

### ✅ `GET /api/conveyor/alerts`

Riwayat anomali, terbaru dulu. Query `event` (`jam`/`off_flow`, nilai lain →
`422`) dan `per_page`. Dipaginasi (`data` + `meta`). Pada tiap baris, `event` dan
`conveyor` dinaikkan jadi field sendiri; sisa isi `context` ada di `metrics`.

### ✅ `POST /api/conveyor/command`

Butuh akses **write** `Live Camera`. Body `{ "command", "speed_rpm?", "line?" }`
— `command` salah satu `ConveyorService::COMMANDS`, nilai lain → `422`.
**503** bila broker MQTT mati (bukan diam-diam sukses).

---

## Aktivasi model (Fase 5)

### ✅ `POST /api/training-runs/{run}/activate`

Menjadikan model hasil sebuah run sebagai model live inference. Butuh akses
**write** modul `Training`. Mencerminkan command `sortvision:activate-model`.

Body `{ "force?": true }`.

Pra-syarat (semua `422` bila gagal): run harus berstatus `completed`, punya
`model_path`, dan lolos ambang mAP (`ML_MIN_MAP50`). `force` **hanya**
melewati ambang mAP — bukan syarat completed/model, karena memaksa run tanpa
model tidak mengaktifkan apa pun.

**200** `{ "message": "...", "data": { "active_training_run_id": 12, "ml_reloaded": true, "run": {...} } }`

Reload ML service bersifat best-effort: `ml_reloaded: false` berarti service
sedang mati, **bukan** aktivasi gagal — `Setting.active_training_run_id` tetap
tertulis. Payload run kini juga membawa `is_active_model`, jadi klien bisa
menandai run mana yang live tanpa request kedua.

---

## Push notification (Fase 5)

Backend mengirim notifikasi lewat **layanan push Expo**, bukan FCM/APNs
langsung: app mendaftarkan `ExponentPushToken[...]` dan Expo yang meneruskan ke
transport masing-masing platform, sehingga backend tidak menyimpan kredensial
per-platform. (Untuk rilis produksi, kunci FCM/APNs tetap perlu diunggah ke
*project Expo*-nya — itu urusan build, bukan konfigurasi backend.)

Notifikasi dikirim otomatis saat: **anomali conveyor** (`jam`/`off_flow` → ke
pemegang akses baca `Live Camera`) dan **training selesai/gagal** (→ pemegang
akses baca `Training`). Semua pengiriman best-effort — kegagalan push tidak
pernah menggagalkan event QC yang memicunya.

### ✅ `POST /api/device-tokens` · `DELETE /api/device-tokens`

**Tidak** digerbangi `module:` — bisa dihubungi saat line bermasalah bukan aksi
modul yang istimewa, dan token selalu diikat ke akun pemanggil.

**`POST`** — body `{ "token", "platform?" }` (`ios`/`android`/`web`). Token
divalidasi harus berformat Expo (`422` bila bukan). Kuncinya adalah **token**,
bukan pasangan (user, device): saat operator kedua login di HP yang sama, Expo
memberi token yang sama, dan alert harus mengikuti siapa yang login **sekarang**
— jadi baris yang ada dipindahkan ke user baru, bukan ditambah baris kedua yang
akan mengirim dobel. **201**.

**`DELETE`** — body `{ "token" }`, dipanggil saat logout. Dibatasi pada token
milik pemanggil sendiri: tahu string token saja tidak boleh cukup untuk
membungkam perangkat orang lain.

---

## Topik MQTT (Opsi A)

Broker (Mosquitto/EMQX) di-host terpisah — dikonfigurasi lewat env
(`MQTT_HOST`, `MQTT_PORT`, `MQTT_BASE_TOPIC`, dll; lihat `config/services.php`
section `mqtt`). **ESP32 connect ke broker yang sama lewat WiFi** dan subscribe
`arm/command` langsung — tidak lewat Jetson. Laravel berperan sebagai
**publisher** command dan **consumer** telemetry (`php artisan mqtt:listen`).
Semua topik di bawah prefix `MQTT_BASE_TOPIC` (default `arm`).

| Topik | Arah | Publisher → Subscriber | QoS | Isi |
|---|---|---|---|---|
| `arm/command` | command | Laravel → ESP32 | 1 | Sudut sendi target (hasil lookup preset) |
| `arm/status` | telemetry | ESP32 → Laravel (`mqtt:listen`) | 0 | State arm terkini |
| `arm/detection` | telemetry | ESP32 → Laravel (`mqtt:listen`) | 0 | Hasil deteksi QC realtime |

Semua payload berupa **JSON**. Command pakai **QoS 1** (at least once) supaya
tidak hilang di jalur WiFi ESP32 yang kurang stabil.

### `arm/command`
Dikirim oleh `ArmMqttService::publishCommand(string $category, array $context)`.
Karena tidak ada Jetson yang menghitung inverse kinematics, backend **sudah
melakukan lookup** `TargetZonePreset` per kategori produk dan payload berisi
**sudut sendi jadi** — ESP32 tinggal mengeksekusi, bukan menghitung IK:
```json
{
  "category": "Electronics",
  "zone": "electronics",
  "joint_angles": [10, 15, 20, 25, 30, 35],
  "issued_at": "2026-07-14T08:29:00+00:00"
}
```
Field tambahan dari `$context` (mis. `detection_id`, `source`) ikut di-merge di
level atas. Kalau kategori tidak punya preset khusus, dipakai preset `default`.

### `arm/status`
Ditulis ke `ArmStatus` (singleton). Field yang dibaca:
```json
{
  "state": "running",            // idle | running | error (lainnya → idle)
  "detail": "Sorting batch A",   // opsional
  "last_command": "start",       // opsional
  "telemetry": { "axis": [12, 40, 0, 90, 0, 15] }  // opsional, disimpan apa adanya
}
```

### `arm/detection`
Dibuat jadi row `Detection` (status dinormalisasi ke `Detection::STATUSES`,
nilai tak dikenal → `recheck`). Field:
```json
{
  "status": "passed",            // wajib
  "code": "MQT-AB12CD",          // opsional (default digenerate)
  "product_id": 42,              // opsional
  "camera": "ICAM-300",          // opsional
  "conveyor": "LINE-A",          // opsional
  "qr_value": "https://...",     // opsional
  "confidence": 92.5             // opsional
}
```
