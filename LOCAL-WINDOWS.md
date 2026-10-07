# Menjalankan instalasi lokal

## Instalasi di laptop teman (sekali saja)

Prasyarat: Windows, Laragon dengan Apache dan PHP 8.2+, Composer bawaan Laragon,
Node.js/npm, serta Python 3.11 atau 3.12. Setup di komputer ini diuji dengan
PHP 8.3, Python 3.11, dan Node.js 24. Internet diperlukan untuk mengunduh dependensi.

Setelah clone/pull proyek, kirim juga ZIP model
`deploy_arm_colors_20261007T002446Z_18e37b (1).zip` ke teman Anda. Model `.pt`,
ZIP, `.env`, database lokal, dan folder dependensi tidak ikut Git.
Script memverifikasi checksum dan mengambil model dari ZIP; script di dalam
ZIP tidak dijalankan.

Dari folder proyek, jalankan (sesuaikan lokasi ZIP):

```powershell
powershell -ExecutionPolicy Bypass -File tools/install-local.ps1 -ModelZip "C:\lokasi\deploy_arm_colors_20261007T002446Z_18e37b (1).zip"
```

Script membuat konfigurasi lokal dengan path laptop tersebut, menginstal
dependensi PHP/Node/Python, membangun tampilan, lalu membuat database SQLite
dan akun demo. Instalasi pertama dapat memerlukan beberapa menit. Database
yang sudah ada tidak dihapus. Jalankan script saat layanan proyek berhenti.
Instalasi ulang boleh tanpa `-ModelZip` jika model sudah terpasang.

Parameter tambahan jika diperlukan: `-LaragonRoot "D:\laragon"`,
`-PythonExe "C:\lokasi\python.exe"`, atau
`-CameraUrl "rtsp://192.168.0.100:8550/video"`.
Jika Laragon berada di lokasi khusus, berikan `-LaragonRoot` yang sama saat
menjalankan `start-services.ps1` dan `stop-services.ps1`.

## Menyalakan web dan terminal X/Y

Dari PowerShell di folder proyek:

```powershell
powershell -ExecutionPolicy Bypass -File tools/start-services.ps1
```

Kemudian buka terminal hasil deteksi:

```powershell
powershell -ExecutionPolicy Bypass -File tools/show-xy.ps1
```

Monitor otomatis membaca `ML_SERVICE_URL` dari `.env`, sehingga mengikuti
port layanan proyek (8001 untuk setup lama, 8002 untuk script instalasi baru).
Alamat juga bisa diberikan melalui `tools/show-xy.ps1 -Url http://127.0.0.1:8001`.

Terminal menampilkan kelas, confidence, dan X/Y dalam piksel setiap ada hasil
baru. Saat bridge aktif, terminal juga menampilkan payload TCP terakhir
`x,y,G,R,Y` dalam mm dan respons ESP32 dari `/robot/status`.
Contoh format (bukan hasil pengukuran):

```text
[10:30:00] GREEN      confidence= 92.5%  X=320  Y=240  px
[10:30:00] [ICAM -> ESP32] {"x":100.0,"y":50.0,"G":1,"R":0,"Y":0} | x/y dalam mm
[10:30:00] [ESP32 -> ICAM] OK | ACK=7 (penerimaan pesan)
```

Buka http://127.0.0.1:8080 dan login dengan akun demo lokal:

- Email: `admin@sortvision.test`
- Password: `password`

Script memakai PHP dan Apache yang sudah ada di `C:\laragon`, virtual environment
Python proyek, serta aset hasil `npm run build`. Apache mendukung permintaan
video MJPEG dan dashboard secara bersamaan. Log proses tersimpan di `.local/`.
Server hanya mendengarkan pada komputer ini (`127.0.0.1`).

Untuk menghentikan proses yang dimulai oleh script:

```powershell
powershell -ExecutionPolicy Bypass -File tools/stop-services.ps1
```

## Model dan koordinat

Model dari ZIP `deploy_arm_colors_20261007T002446Z_18e37b (1).zip` dipasang di
`storage/app/models/run-100/best.pt`. Checksum model telah diverifikasi terhadap
`SHA256SUMS.json` dalam ZIP. Model memiliki kelas GREEN, YELLOW, dan RED.

Konfigurasi layanan ada di `ml-service/.env`. `ICAM_MODEL_PATH` menunjuk model
tersebut. Kamera iCAM yang dipilih memakai `ICAM_RTSP_URL`:
`rtsp://192.168.0.100:8550/video`. Jika alamat kamera berubah, ubah nilai tersebut, kemudian
hentikan dan jalankan ulang layanan. Kamera yang tidak terhubung ditampilkan
sebagai OFFLINE.

Komputer harus bisa menjangkau kamera melalui jaringan lokal. Saat pengecekan
instalasi, koneksi ke `192.168.0.100:8550` timeout. Pastikan kamera menyala,
komputer terhubung ke jaringan kamera, dan RTSP kamera aktif sebelum
mengharapkan hasil X/Y langsung.

Lihat **Live Camera** atau **Model Evaluation** untuk hasil deteksi dan X/Y.
Preview gambar menampilkan bounding box, tanda silang di pusat objek, dan
label `X=... Y=... px`; panel Current Detection juga menampilkan Center X / Y.
Endpoint lokal `http://127.0.0.1:8002/detections/latest` menyediakan
`detections[].center.x` dan `detections[].center.y` dalam piksel sumber kamera.
Titik tersebut adalah pusat bounding box; titik nol berada di kiri atas,
X bertambah ke kanan dan Y bertambah ke bawah. Field `normalized` juga
menyediakan koordinat dalam rentang 0 sampai 1.

Jika ingin melihat X/Y dari terminal (setelah layanan menyala):

```powershell
python tools/watch-detections.py --robot
```

Monitor ini menampilkan kelas, confidence, X, dan Y secara terus-menerus.
Jika kamera offline, model belum siap, atau tidak ada objek terdeteksi,
monitor menampilkan status tersebut. Tekan Ctrl+C untuk berhenti.
Untuk mengambil hasil satu kali melalui PowerShell:

```powershell
(Invoke-RestMethod http://127.0.0.1:8002/detections/latest).detections |
    Select-Object class_name, @{Name='X'; Expression={$_.center.x}}, @{Name='Y'; Expression={$_.center.y}}
```

Hasil kosong berarti belum ada objek yang terdeteksi. Periksa status kamera,
pastikan objek sesuai kelas model terlihat jelas, dan lihat confidence
(ambang yang dipasang adalah 0.60 / 60%). Tidak perlu menghitung X/Y secara manual.

Pengiriman ke robot melalui TCP tersedia tetapi **nonaktif secara default**.
Kalibrasi piksel-ke-mm dalam paket model belum diisi. Isi hasil pengukuran
`ROBOT_PIXEL_TO_MM`, alamat ESP32, dan `ROBOT_BRIDGE_ENABLED=true` untuk
mengaktifkannya, lalu restart layanan. Lihat
[protokol dan konfigurasi robot](docs/YOLO_ROBOT_TCP.md).

Status pengiriman: `http://127.0.0.1:8002/robot/status`.
Payload ke ESP32 port 5000 berisi `x,y` dalam mm dan flag warna `G,R,Y`.
Balasan `OK` berarti pesan diterima. Antrean robot HTTPS yang juga ada di
proyek merupakan jalur terpisah.
