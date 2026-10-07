# Perbaikan deteksi benda jauh — 7 Oktober 2026

Gunakan model yang sudah dikirim; **belum perlu training ulang untuk mencoba perbaikan ini**.
Notebook `Train_arm_yolo1.ipynb` sekarang membuat runtime yang memfokuskan gambar ke matras
sebelum resize ke input ONNX. Model .pt/.onnx tetap sama.

## Hasil pemeriksaan model dan foto

Model diperiksa dari `deploy_arm_colors_20261007T002446Z_18e37b.zip`.
Checksum isi arsip cocok. Manifest menyatakan kedua dataset sudah digabung dengan remapping
GREEN/YELLOW/RED; model YOLO11-seg memiliki input ONNX FP32 statis 512 × 512.
SHA256 `best.onnx`: `30e1449707b40b8ed909745cb2bc70a0fb05f5c60b83f194666aba9d609e09fa`.

`photos4_icam.tar.zip` berisi 252 foto tanpa label YOLO. Pemeriksaan ini menggunakan
**10 foto terbaru tanggal 7 Oktober, 1280 × 960**, masing-masing satu benda yang ditinjau
secara visual. Lebar benda sekitar 70–80 piksel pada foto asli menjadi sekitar 28–32 piksel
pada input model jika seluruh lebar frame diperkecil menjadi 512. Ini konsisten dengan
objek jauh yang lebih sulit terdeteksi, meskipun faktor fokus/pencahayaan juga dapat berpengaruh.

Semua perbandingan memakai bobot yang sama, decoder yang sama, dan threshold 0,60.
ROI untuk eksperimen ditaksir secara visual dari foto; **bukan kalibrasi robot** dan tidak
diisikan otomatis ke konfigurasi pengguna.

| Jalur inferensi | Foto dengan target ditemukan | Prediksi tambahan yang terlihat |
|---|---:|---:|
| Seluruh frame, tanpa ROI (jalur bundle lama) | 8/10 | 2 di luar matras |
| Seluruh frame, luar matras ditutup | 6/10 | 0 |
| Luar matras ditutup + crop matras (default baru) | 10/10 | 0 |
| Crop matras + tiles 640, overlap 25% (opsional) | 10/10 | 0 |

Menghilangkan latar luar saja dapat mengubah confidence dan belum memperbesar objek.
Crop dan tiles diuji terpisah agar efek tersebut terlihat.

| Nama foto JPG | Warna | Full tanpa ROI | Crop matras | Tiled |
|---|---|---:|---:|---:|
| 20261007073251 | RED | 0,612 | 0,813 | 0,932 |
| 20261007073255 | RED | 0,622 | 0,814 | 0,933 |
| 20261007073302 | GREEN | 0,698 | 0,843 | 0,945 |
| 20261007073304 | GREEN | 0,689 | 0,840 | 0,946 |
| 20261007073307 | GREEN | 0,693 | 0,839 | 0,943 |
| 20261007073315 | YELLOW | 0,720 | 0,875 | 0,933 |
| 20261007073319 | YELLOW | 0,691 | 0,876 | 0,933 |
| 20261007073931 | GREEN | 0,736 | 0,806 | 0,948 |
| 20261007073939 | RED | < 0,25 / tidak ditemukan | 0,659 | 0,899 |
| 20261007073945 | YELLOW | 0,583 (tersaring) | 0,857 | 0,941 |

Angka adalah confidence target, **bukan persentase akurasi**. Sampel kecil ini mengandung
frame berurutan yang mirip dan tidak memiliki anotasi polygon untuk menghitung mAP atau
error pusat objek secara formal. Belum mengukur meja kosong, objek bertumpuk, beberapa
warna sekaligus, latency ICAM, atau error XY fisik. Confidence tinggi tidak menjamin
koordinat grasp tepat. Nilai threshold model lama belum dikalibrasi ulang untuk crop/tiled.

## Mencoba runtime tanpa training ulang

1. Ekstrak `icam_runtime_far_patch.zip` ke **folder baru** di ICAM/PC inferensi.
2. Salin `best.onnx` dan `runtime_config.json` dari bundle model yang sama ke folder itu.
   Tidak perlu menyalin .pt untuk runtime ONNX. Biarkan bundle asli sebagai pembanding.
   Dependensi tetap NumPy, OpenCV, ONNX Runtime; live ICAM memakai CAMNavi2 bawaan BSP.
3. Isi `mat_roi.example.json`, simpan sebagai `mat_roi.json`. `frame_size_wh` adalah
   `[lebar, tinggi]` gambar ICAM asli. `points_px` berisi empat sudut matras berurutan
   mengelilingi tepi, misalnya kiri bawah → kanan bawah → kanan atas → kiri atas.
   Ambil angka dari frame kamera aktual dengan dudukan final, bukan foto setup dari ponsel.
   XY robot dan Z belum diperlukan untuk membatasi deteksi di matras.
4. Jalankan pada foto terlebih dahulu:

```bash
python3 icam_color_runtime.py --source meja.jpg --roi mat_roi.json --output terminal
```

Default `roi-crop` menutup luar poligon dengan abu-abu, memotong bounding rectangle matras,
dan menjalankan satu inferensi. Bounding box, contour, dan pusat dikembalikan ke piksel
frame asli sebelum overlay dan mapping. Mask yang menyentuh/melintasi batas matras ditolak
dengan margin 2 piksel; benda hijau di dalam matras tetap diproses. `prediction.jpg`
menampilkan hasil. Belum kalibrasi berarti keluaran U/V piksel, bukan X/Y mm.

Jika benda masih kecil, bandingkan:

```bash
python3 icam_color_runtime.py --source meja.jpg --roi mat_roi.json --inference-mode tiled --tile-size 640 --tile-overlap 0.25 --output terminal
```

Tiled menambahkan potongan tumpang tindih, membuang instance terpotong di tepi internal tile,
lalu menggabungkan deteksi sekelas dengan NMS. Ia membutuhkan beberapa inferensi per frame.
Pakai output default tanpa `--output terminal` untuk melihat `elapsed_ms`; ukur langsung
di ICAM. Mode `--inference-mode full` dengan ROI tersedia untuk perbandingan masking tanpa crop.
`--full-frame` tanpa ROI hanya untuk diagnosis deteksi lingkungan.

Untuk live, gunakan nama device hasil enumerasi CAMNavi2 dan resolusi yang didukung BSP.
Contoh **hanya jika** ROI diukur pada 1280 × 960 dan kamera mendukung mode tersebut:

```bash
python3 icam_color_runtime.py --source camnavi --device-name iCam500 --width 1280 --height 960 --roi mat_roi.json --output terminal
```

Setelah `robot_plane.json` hasil pengukuran lengkap, ganti `--roi mat_roi.json` dengan
`--calibration robot_plane.json`. `--output xy-json` menghasilkan XY mm dan flag G/R/Y
di stdout. Kalibrasi wajib memakai resolusi/orientasi frame asli yang sama. Runtime ini
belum membuka serial/TCP atau menggerakkan robot. Pengiriman gerak tetap perlu gate
satu stage: ambil → mangkok → HOME → DONE, lalu frame baru; jangan antrekan observasi per-frame.

## Training berikutnya

Jangan memasukkan arsip foto mentah ini langsung ke `ZIP_SOURCES`: belum ada label objek.
Anotasi frame dari dudukan final, termasuk objek kecil di berbagai posisi matras, beberapa
warna sekaligus, meja kosong, bayangan dan gripper. Pertahankan detail resolusi asli.
Pisahkan train/valid/test berdasarkan sesi pengambilan, bukan mengacak foto beruntun yang mirip.
Evaluasi false positive, objek terlewat, dan error posisi sebelum memutuskan fine-tuning.

## Ekspor ulang runtime setelah mengedit notebook

```bash
python tools/export_icam_runtime.py --output artifacts/icam_runtime_far_patch_baru
```

Exporter hanya menyalin kode cell; tidak mengeksekusi notebook atau memuat bobot.
Patch menyertakan checksum script sendiri dan tidak menimpa kalibrasi maupun model.
Notebook juga memasukkan perubahan ini ke bundle Colab pada ekspor berikutnya.

Referensi konsep: [Ultralytics tiled inference](https://docs.ultralytics.com/guides/sahi-tiled-inference/).
Runtime ini memakai decoder ONNX sendiri tanpa tambahan paket SAHI.
