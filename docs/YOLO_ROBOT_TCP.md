# Hasil YOLO menjadi target XY robot

Alur ini mengikuti kode `ICAMXYClient` yang digunakan robot:

Untuk laptop Windows, ikuti [instalasi lokal](../LOCAL-WINDOWS.md) terlebih
dahulu. Setelah instalasi, jalankan dari folder proyek:

```powershell
powershell -ExecutionPolicy Bypass -File tools/start-services.ps1
powershell -ExecutionPolicy Bypass -File tools/show-xy.ps1
```

Terminal menampilkan hasil nyata YOLO berupa kelas, confidence, X, dan Y
dalam piksel, ditambah payload `x,y,G,R,Y` dalam mm dan respons terakhir ESP32
saat bridge aktif. Monitor hanya membaca status; menutup terminal tidak
menghentikan layanan bridge. Web tersedia di `http://127.0.0.1:8080/live-camera`; login lokal
`admin@sortvision.test` / `password`. Layanan ML yang dijalankan script memakai
port **8002**. Contoh perintah manual pada dokumen ini memakai port **8001**;
ganti menjadi 8002 saat memakai script Windows.

X/Y baru tersedia ketika model siap, kamera tersambung, dan objek terdeteksi.
Pastikan `ICAM_RTSP_URL=rtsp://192.168.0.100:8550/video` sesuai kamera.
Monitor terminal dapat berjalan tanpa mengaktifkan bridge robot.
Preview visual menampilkan bounding box, titik pusat, serta label X/Y piksel
langsung di gambar; panel Current Detection menampilkan nilai yang sama.

```text
Kamera -> YOLO -> titik tengah X/Y piksel -> konversi mm
       -> TCP JSON per baris -> ESP32 port 5000 -> balasan OK
```

`GET /detections/latest` menyediakan `x` dan `y` untuk setiap objek.
Nilainya sama dengan `center.x` dan `center.y`: titik tengah bounding box
dalam piksel, titik nol di kiri atas gambar, X ke kanan dan Y ke bawah.
Contoh bagian hasil deteksi:

```json
{"class_name":"GREEN","confidence":95.0,"x":320,"y":240,"center":{"x":320,"y":240}}
```

## Protokol yang diterima robot

Satu objek dikirim sebagai lima field berikut, diakhiri newline (`\n`):

```json
{"x":100.0,"y":50.0,"G":1,"R":0,"Y":0}
```

`x` dan `y` pada koneksi robot adalah **mm**, mengikuti `x_mm`/`y_mm` dalam
kode contoh. `G`, `R`, `Y` adalah flag warna, tepat satu bernilai 1.
GREEN/HIJAU menghasilkan `1,0,0`, RED/MERAH `0,1,0`, dan YELLOW/KUNING `0,0,1`.
Huruf kecil `y` adalah koordinat; huruf besar `Y` adalah flag kuning.

Saat tidak ada objek yang memenuhi syarat, kirim:

```json
{"found":false}
```

Worker menggunakan koneksi TCP persisten dan menunggu respons `OK` untuk
setiap pesan. Target dikirim berulang dengan jadwal 10 Hz, mengikuti contoh
yang menyebut kebutuhan lebih dari enam frame stabil untuk memicu robot.
Kecepatan aktual mengikuti waktu respons ESP32. Respons `OK` hanya menandakan
pesan diterima; status gerakan selesai tidak tersedia dalam protokol ini.

## Konfigurasi

Isi `ml-service/.env` berdasarkan `.env.example`:

```dotenv
ROBOT_BRIDGE_ENABLED=true
ROBOT_ESP_HOST=192.168.67.174
ROBOT_ESP_PORT=5000
ROBOT_STREAM_HZ=10
ROBOT_MIN_CONFIDENCE=0.80
```

Isi juga `ROBOT_PIXEL_TO_MM` dengan enam koefisien **hasil pengukuran**:

```text
ROBOT_PIXEL_TO_MM=[a,b,c,d,e,f]
X_robot_mm = a * X_pixel + b * Y_pixel + c
Y_robot_mm = d * X_pixel + e * Y_pixel + f
```

Ini transformasi affine: mendukung skala, pembalikan/pertukaran sumbu,
rotasi, dan offset. Untuk sumbu kamera dan robot yang sejajar, `b=d=0`;
ukur skala mm/piksel serta offset titik nol untuk kedua sumbu. Untuk kasus
umum, gunakan sedikitnya tiga pasangan titik kamera dan robot yang tidak
segaris. Kamera, resolusi, crop, dan bidang objek harus sesuai saat kalibrasi.
Koreksi perspektif kamera miring belum tersedia pada transformasi ini.

Contoh hitungan saja: `[0.5,0,-160,0,-0.5,120]` memetakan titik kamera
`(320,240)` ke robot `(0,0)` mm. Nilai contoh ini bukan kalibrasi perangkatmu.
Tanpa koefisien, bridge melaporkan `pixel_to_mm_calibration_required` dan
tidak membuka koneksi robot. Hasil X/Y piksel tetap tersedia di API deteksi.

Jalankan layanan ML seperti biasa dengan **satu proses worker**:

```powershell
cd ml-service
python -m uvicorn main:app --host 127.0.0.1 --port 8001
```

Mesin yang menjalankan ML harus dapat menjangkau IP ESP32, biasanya melalui
Wi-Fi/LAN yang sama. VPS memerlukan rute VPN/tunnel ke jaringan tersebut.
TCP ini terpisah dari antrean HTTPS dalam `ROBOT_VPS_BRIDGE.md` dan tidak
menggunakan MQTT. Ikuti langkah `START` pada Serial Monitor sesuai kode
contoh/firmware robot sebelum menjalankan pengambilan objek.

## Pemilihan objek dan pemeriksaan status

Worker memilih objek dengan confidence tertinggi yang berada dalam pick zone.
Batas awal zona adalah X/Y ternormalisasi `0.2` sampai `0.8`; ubah melalui
`PICK_ZONE_X_MIN`, `PICK_ZONE_X_MAX`, `PICK_ZONE_Y_MIN`, `PICK_ZONE_Y_MAX`.
Sajikan objek satu per satu agar pemilihan target stabil seperti contoh scene.

Tidak ada objek, confidence rendah, kamera offline/simulator, model belum
siap, data tidak valid, atau hasil lebih tua dari `ROBOT_MAX_FRAME_AGE_S`
(default 1 detik) menghasilkan `{"found":false}`. Worker TCP memakai snapshot
runtime yang sama dengan dashboard; tidak menjalankan inferensi tambahan.
Umur frame dihitung sejak frame diterima dari kamera, termasuk waktu inferensi.
Setelah koneksi putus, worker mencoba kembali setiap dua detik dan membaca
deteksi terbaru. Frame lama tidak diantrikan untuk dikirim ulang.

Cek dari mesin layanan ML:

```powershell
Invoke-RestMethod http://127.0.0.1:8001/detections/latest
Invoke-RestMethod http://127.0.0.1:8001/robot/status
```

Status melaporkan `streaming`, `no_object`, `camera_not_live`, `stale_frame`,
`configuration_error`, atau `connection_error`, beserta `last_payload`,
`last_response`, dan `acknowledged_frames`. Membuka endpoint status/preview
tidak memicu koneksi atau pengiriman baru.
