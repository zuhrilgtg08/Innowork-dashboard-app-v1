# Opsi Arsitektur Komunikasi Mobile/Web App ke Robotic Arm

> Dokumen ini mencatat opsi arsitektur komunikasi antara mobile app / web dashboard SortVision dengan robotic arm 6-axis (ESP32, dengan atau tanpa Jetson Nano/mini PC). Opsi A adalah pilihan utama, dengan dua varian: menggunakan Jetson Nano sebagai lapisan compute, atau tanpa Jetson Nano (beban compute dipindah ke backend server). Jika Opsi A tidak berjalan sesuai harapan (mis. ESP32 kewalahan menangani beban komunikasi + kontrol motor bersamaan), tim dapat beralih ke Opsi B tanpa perlu merancang ulang dari nol.

## Opsi A — MQTT langsung ke ESP32 (rekomendasi utama)

**Alur:**
```
Mobile app / Web dashboard  --(REST + MQTT)-->  Backend server + MQTT broker  --(MQTT)-->  Jetson Nano  --(UART/serial)-->  ESP32  -->  Stepper + servo motor
```

**Pembagian peran:**
- **Mobile app / web dashboard** — REST API untuk data non-real-time (histori, user, kategori produk), MQTT client untuk command real-time (start/stop sorting, jog manual) dan menerima telemetry.
- **Backend server + MQTT broker** — titik tengah tunggal (mis. Mosquitto/EMQX self-host atau broker cloud). App tidak perlu tahu IP lokal ESP32/Jetson.
- **Jetson Nano** — subscribe command dari broker, jalankan deteksi objek (YOLO via iCam-300), hitung target zone & inverse kinematics tiap sendi, kirim command low-level ke ESP32.
- **ESP32** — terima command sederhana lewat UART/serial dari Jetson, generate step pulses dan sinyal servo. Fokus hanya eksekusi motor real-time, sesuai wiring diagram pada proposal AIoT InnoWorks.

**Kelebihan:**
- ESP32 tetap ringan, real-time, dan fokus pada timing motor presisi.
- Jetson menangani beban komputasi berat (AI + planning), sesuai kapasitasnya.
- MQTT menyediakan pub/sub real-time untuk command & status; REST tetap dipakai untuk data terstruktur di dashboard yang sudah ada.
- App tidak perlu koneksi langsung ke perangkat — cukup lewat broker, sehingga tetap berfungsi meski diakses dari luar jaringan lokal.

**Risiko / hal yang perlu dipantau:**
- Broker MQTT jadi single point of failure — perlu strategi reconnect & QoS yang tepat.
- Latensi tambahan karena melewati broker + Jetson sebelum sampai ke ESP32.
- Perlu desain topik MQTT yang jelas (mis. `arm/command`, `arm/status`, `arm/detection`) dan format payload JSON yang konsisten antara app, Jetson, dan ESP32.

## Opsi A — varian tanpa Jetson Nano

Masih Opsi A (MQTT lewat broker, bukan ESP32 jadi perantara jaringan seperti Opsi B). Bedanya: peran "otak" yang tadinya dipegang Jetson Nano dipindah, bukan dihilangkan — dan lapisan Jetson + jalur UART/serial Jetson→ESP32 ditiadakan.

**Alur:**
```
Mobile app / Web dashboard  --(REST + MQTT)-->  Backend server + MQTT broker + ml-service (YOLO)  --(MQTT, WiFi)-->  ESP32  -->  Stepper + servo motor
```

**Pembagian peran:**
- **Mobile app / web dashboard** — sama seperti Opsi A: REST untuk data non-real-time, MQTT client untuk command & telemetry real-time.
- **Backend server (Laravel) + MQTT broker** — selain jadi titik tengah komunikasi, sekarang juga menjalankan/menampung `ml-service` (YOLO, host-nya cukup PC/server biasa, tidak harus Jetson) dan menghitung target zone/preset koordinat, lalu publish command jadi langsung ke topic yang di-subscribe ESP32.
- **iCam-300** — tetap kirim frame ke `ml-service`, hasil deteksi masuk ke backend lewat callback HMAC yang sudah ada di repo — bagian ini sebenarnya sudah tidak bergantung pada Jetson dari awal.
- **ESP32** — jadi MQTT client langsung lewat WiFi (mis. `PubSubClient`), subscribe command dari broker (bukan dari Jetson lewat serial), lalu eksekusi ke motor.

**Syarat supaya ini realistis:**
- Target zone/gerakan arm bersifat **diskrit/preset** (mis. beberapa bucket sesuai kategori produk), bukan trajectory bebas — sehingga kinematika cukup berupa tabel lookup "kategori → sudut sendi/preset koordinat" yang dihitung di backend, bukan IK real-time berat yang butuh compute sekelas Jetson.
- Kalau ke depan butuh gerakan arm yang lebih dinamis, itu jadi sinyal untuk kembali mempertimbangkan Jetson/minipc.

**Kelebihan dibanding Opsi A dengan Jetson:**
- Hardware lebih sederhana — satu perangkat lebih sedikit untuk dikelola/di-debug.
- ESP32 tetap ringan karena cuma eksekusi angka jadi dari backend, bukan menghitung IK sendiri.

**Risiko tambahan dibanding Opsi A dengan Jetson:**
- Latensi WiFi ESP32 kadang kurang stabil dibanding koneksi lokal ke Jetson — perlu QoS MQTT yang tepat (QoS 1 untuk command supaya tidak hilang).
- Semua beban kinematika/planning menumpuk di backend server — perlu dipastikan backend cukup kuat kalau kategori/preset makin banyak.

## Opsi B — Mobile/web ke ESP32, ESP32 ke Jetson Nano

**Alur:**
```
Mobile app / Web dashboard  -->  ESP32  -->  Jetson Nano  -->  Stepper + servo motor (tiap driver axis)
```

**Pembagian peran:**
- **Mobile app / web dashboard** — komunikasi langsung ke ESP32 (mis. lewat WiFi/HTTP atau MQTT langsung ke ESP32 sebagai MQTT client).
- **ESP32** — menerima command dari app, meneruskan ke Jetson Nano.
- **Jetson Nano** — mengeksekusi tiap driver motor arm 6-axis berdasarkan command yang diteruskan ESP32.

**Kapan dipertimbangkan:**
- Jika Opsi A bermasalah, misalnya Jetson Nano sulit diakses langsung dari broker/jaringan app (isolasi jaringan, keterbatasan koneksi), sehingga ESP32 perlu jadi perantara jaringan antara app dan Jetson.
- Jika ingin ESP32 tetap menjadi satu-satunya titik komunikasi yang dikenal oleh app (arsitektur jaringan lebih sederhana dari sisi app), meski beban ESP32 jadi lebih berat.

**Kekurangan dibanding Opsi A:**
- ESP32 harus menangani dua jalur komunikasi sekaligus (dari app, ke Jetson), padahal ESP32 adalah mikrokontroler dengan RAM dan daya proses terbatas — berisiko bottleneck saat mengontrol banyak stepper motor secara bersamaan.
- Urutan ini kurang efisien karena Jetson (yang jauh lebih kuat) justru menunggu perintah dari ESP32 (yang lebih lemah), padahal idealnya Jetson yang mengatur logika utama.
- Menambah kompleksitas debugging karena ada dua lapis relay sebelum command sampai ke driver motor.

## Kriteria beralih dari Opsi A ke Opsi B

Pindah ke Opsi B jika salah satu kondisi berikut terjadi pada Opsi A (baik varian dengan Jetson maupun tanpa Jetson):
- Jetson Nano/backend tidak bisa dijangkau langsung oleh broker MQTT / jaringan app (masalah jaringan/firewall yang tidak bisa diselesaikan di sisi Jetson/backend).
- Kebutuhan arsitektur berubah sehingga ESP32 harus menjadi satu-satunya endpoint yang diakses app secara langsung.

## Kriteria memilih varian Opsi A (dengan Jetson vs tanpa Jetson)

- Gunakan **varian tanpa Jetson** kalau gerakan arm cukup berupa preset/bucket tetap dan ingin hardware lebih sederhana.
- Gunakan **varian dengan Jetson** kalau butuh trajectory/gerakan arm yang lebih dinamis, atau beban komputasi vision + kinematika terlalu berat untuk ditumpuk di satu backend server.

## Catatan

- Opsi A tetap menjadi arsitektur utama karena lebih sesuai dengan kapasitas masing-masing perangkat (ESP32 = eksekusi real-time, Jetson/backend = komputasi AI/planning).
- Dokumen ini akan diperbarui jika ada perubahan skema komunikasi atau jika tim benar-benar berpindah ke Opsi B.