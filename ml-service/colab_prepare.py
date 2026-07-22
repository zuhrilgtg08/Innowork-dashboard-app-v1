"""Prepare the external YOLOv8 dataset for Google Colab training.

This script:
1. Copies ``ultra-milk-kecil.yolov8`` into ``ml-service/colab-artifacts/``.
2. Renames the Roboflow class names to SortVision QC Detection statuses
   (``um_normal`` -> ``passed``, ``um_rusak`` -> ``damaged``).
3. Zips the prepared folder so it can be uploaded to Colab.
4. Writes ``colab_script.py`` — a ready-to-run Colab training script.
"""

import shutil
import zipfile
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = BASE_DIR.parent
SOURCE_DATASET = PROJECT_ROOT / "ultra-milk-kecil.yolov8"
ARTIFACTS_DIR = BASE_DIR / "colab-artifacts"
PREPARED_DIR = ARTIFACTS_DIR / "ultra-milk-yolo-ready"
ZIP_PATH = ARTIFACTS_DIR / "ultra-milk-yolo-ready.zip"
COLAB_SCRIPT = ARTIFACTS_DIR / "colab_script.py"

CLASS_NAME_MAP = {
    "um_normal": "passed",
    "um_rusak": "damaged",
}


def prepare() -> None:
    if not SOURCE_DATASET.exists():
        raise FileNotFoundError(f"Dataset not found: {SOURCE_DATASET}")

    if PREPARED_DIR.exists():
        shutil.rmtree(PREPARED_DIR)

    shutil.copytree(SOURCE_DATASET, PREPARED_DIR)

    data_yaml = PREPARED_DIR / "data.yaml"
    text = data_yaml.read_text(encoding="utf-8")

    old_names = "names: ['um_normal', 'um_rusak']"
    new_names = "names: ['passed', 'damaged']"
    if old_names not in text:
        raise RuntimeError("Expected names row not found in data.yaml")
    text = text.replace(old_names, new_names)

    text = text.replace("train: ../train/images", "path: ultra-milk-yolo-ready\ntrain: train/images")
    text = text.replace("val: ../valid/images", "val: valid/images")
    text = text.replace("test: ../test/images", "test: test/images")
    data_yaml.write_text(text, encoding="utf-8")

    if ZIP_PATH.exists():
        ZIP_PATH.unlink()
    with zipfile.ZipFile(ZIP_PATH, "w", zipfile.ZIP_DEFLATED) as zf:
        for path in PREPARED_DIR.rglob("*"):
            if path.is_file():
                zf.write(path, path.relative_to(PREPARED_DIR.parent))

    COLAB_SCRIPT.write_text(COLAB_SCRIPT_TEMPLATE, encoding="utf-8")
    print(f"Prepared dataset : {PREPARED_DIR}")
    print(f"ZIP archive      : {ZIP_PATH}")
    print(f"Colab script     : {COLAB_SCRIPT}")


COLAB_SCRIPT_TEMPLATE = '''"""Google Colab training script for ultra-milk YOLOv8 dataset.

Upload `ultra-milk-yolo-ready.zip` first, then run this cell.
"""

# 1. Unzip dataset (uploaded in Colab sidebar)
!unzip -q ultra-milk-yolo-ready.zip

# 2. Install Ultralytics if the runtime does not already have it.
!pip install -q ultralytics

# 3. Quick sanity check
from ultralytics import YOLO
import os

dataset_dir = "ultra-milk-yolo-ready"
data_yaml = os.path.join(dataset_dir, "data.yaml")
print("Dataset yaml:", data_yaml)

# 4. Train
model = YOLO("yolov8n.pt")
model.train(
    data=data_yaml,
    epochs=10,
    imgsz=640,
    batch=16,
    device="cuda" if os.path.exists("/usr/local/cuda") else "cpu",
    workers=2,
    cache=False,
    project="runs/detect",
    name="train",
    exist_ok=True,
)

# 5. Locate best weights
best = "runs/detect/train/weights/best.pt"
print("Best weights:", best)

# 6. Print a Colab download hint
print("Run the cell below to download:")
print("from google.colab import files")
print("files.download('" + best + "')")
'''


if __name__ == "__main__":
    prepare()
