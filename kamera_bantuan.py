import time
import random

class KameraSimulasi:
    def __init__(self):
        print("[SIMULASI] Menghubungkan ke ICAM-300...")
        time.sleep(1)

    def enum_camera_list(self):
        # Berpura-pura menemukan kamera virtual
        return {"icam300_virtual_dev": "192.168.1.15"}

    def get_device_by_name(self, name):
        print(f"[SIMULASI] Mengunci kamera: {name}")
        return True

    def advcam_snap_image(self):
        print("[SIMULASI] Mengambil gambar dari sensor...")
        # Nanti kalau kamera asli datang, ini diganti return gambar asli
        return True 

# Tes jalankan simulasi
cam = KameraSimulasi()
print(cam.enum_camera_list())