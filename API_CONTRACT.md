# SortVision — Kontrak API Mobile (Opsi A)

Kontrak REST API untuk mobile app SortVision, bagian dari **Opsi A**
(mobile/web → backend Laravel + MQTT broker → Jetson Nano → ESP32 → motor).
Lihat `Opsi-a&b.md` untuk keputusan arsitekturnya.

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

## Topik MQTT (Opsi A)

Broker (Mosquitto/EMQX) di-host terpisah — dikonfigurasi lewat env
(`MQTT_HOST`, `MQTT_PORT`, `MQTT_BASE_TOPIC`, dll; lihat `config/services.php`
section `mqtt`). Laravel berperan sebagai **publisher** command dan **consumer**
telemetry (`php artisan mqtt:listen`). Semua topik di bawah prefix
`MQTT_BASE_TOPIC` (default `arm`).

| Topik | Arah | Publisher → Subscriber | Isi |
|---|---|---|---|
| `arm/command` | command | Laravel/dashboard → Jetson/ESP32 | Perintah kontrol arm |
| `arm/status` | telemetry | Jetson/ESP32 → Laravel (`mqtt:listen`) | State arm terkini |
| `arm/detection` | telemetry | Jetson → Laravel (`mqtt:listen`) | Hasil deteksi QC realtime |

Semua payload berupa **JSON**.

### `arm/command`
Dikirim oleh `ArmMqttService::publishCommand()`. Format bebas per command,
contoh:
```json
{ "action": "start", "line": "LINE-A", "ts": "2026-07-14T08:29:00Z" }
```

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
