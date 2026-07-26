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
