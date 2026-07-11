import sys
# GANTI teks di bawah ini dengan alamat folder tempat Anda menyimpan SDK CamNavi2
sys.path.append('/home/rasya/Downloads/Masukkan_Nama_Folder_SDK_Anda_Di_Sini')

# Kode Anda yang sebelumnya:
from CamNavi2 import CamNavi2

sdk = CamNavi2()
print("Sedang mencari kamera ICAM-300 di jaringan...")
cam_list = sdk.enum_camera_list()
print("Hasil pencarian:", cam_list)