"""Prepare local configuration and install the supplied model (stdlib only)."""

import argparse
import hashlib
import json
from pathlib import Path
import re
import secrets
import sqlite3
import zipfile


def env_value(text, key):
    match = re.search(rf"^{re.escape(key)}=(.*)$", text, re.MULTILINE)
    return match.group(1).strip().strip('"') if match else ""


def set_env(text, key, value):
    line = key + "=" + json.dumps(str(value))
    pattern = rf"^{re.escape(key)}=.*$"
    if re.search(pattern, text, re.MULTILINE):
        return re.sub(pattern, lambda _: line, text, flags=re.MULTILINE)
    return text.rstrip() + "\n" + line + "\n"


def prepare(root, model_zip=None, camera_url="rtsp://192.168.0.100:8550/video"):
    model_dir = root / "storage/app/models/run-100"
    if model_zip:
        # Copy only the named data files; never execute bundle scripts.
        names = ("best.pt", "manifest.json", "runtime_config.json", "labels.txt")
        with zipfile.ZipFile(model_zip) as archive:
            checksums = json.loads(archive.read("SHA256SUMS.json"))
            files = {name: archive.read(name) for name in names}
            for name, content in files.items():
                if hashlib.sha256(content).hexdigest() != checksums.get(name):
                    raise ValueError(f"Checksum tidak cocok: {name}")
            model_dir.mkdir(parents=True, exist_ok=True)
            for name, content in files.items():
                (model_dir / name).write_bytes(content)
            (model_dir / "SHA256SUMS.json").write_bytes(archive.read("SHA256SUMS.json"))
    elif not (model_dir / "best.pt").is_file():
        raise FileNotFoundError("Model belum ada. Berikan --model-zip path-ke-bundle.zip")

    app_path, ml_path = root / ".env", root / "ml-service/.env"
    app_text = (app_path if app_path.exists() else root / ".env.example").read_text(encoding="utf-8-sig")
    ml_text = (ml_path if ml_path.exists() else root / "ml-service/.env.example").read_text(encoding="utf-8-sig")
    if env_value(app_text, "DB_CONNECTION") != "sqlite":
        raise ValueError("Setup lokal ini memerlukan DB_CONNECTION=sqlite. Gunakan checkout lokal terpisah.")
    database_path = env_value(app_text, "DB_DATABASE")
    if database_path and Path(database_path).resolve() != (root / "database/database.sqlite").resolve():
        raise ValueError("DB_DATABASE menunjuk database lain. Gunakan checkout lokal terpisah.")
    secret = env_value(app_text, "ML_CALLBACK_SECRET") or secrets.token_hex(32)
    for key, value in {
        "APP_NAME": "SortVision", "APP_URL": "http://127.0.0.1:8080",
        "DB_DATABASE": (root / "database/database.sqlite").as_posix(),
        "ML_SERVICE_URL": "http://127.0.0.1:8002",
        "ML_STREAM_URL": "http://127.0.0.1:8002/camera/stream",
        "ML_STATUS_URL": "http://127.0.0.1:8002/camera/status",
        "ML_PREVIEW_URL": "http://127.0.0.1:8002/camera/preview",
        "ML_CALLBACK_SECRET": secret,
    }.items():
        app_text = set_env(app_text, key, value)
    for key, value in {
        "LARAVEL_STORAGE_PATH": (root / "storage/app").as_posix(),
        "LARAVEL_URL": "http://127.0.0.1:8080", "ML_CALLBACK_SECRET": secret,
        "ICAM_MODEL_PATH": "models/run-100/best.pt", "ICAM_RTSP_URL": camera_url,
        "ICAM_ALLOW_SIMULATOR": "false", "ICAM_CONF": "0.60",
    }.items():
        ml_text = set_env(ml_text, key, value)
    app_path.write_text(app_text, encoding="utf-8")
    ml_path.write_text(ml_text, encoding="utf-8")
    print("Konfigurasi lokal dan model siap. Rahasia disimpan hanya dalam .env.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--model-zip", type=Path)
    parser.add_argument("--camera-url", default="rtsp://192.168.0.100:8550/video")
    parser.add_argument("--user-count", action="store_true")
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    if args.user_count:
        with sqlite3.connect(root / "database/database.sqlite") as connection:
            print(connection.execute("select count(*) from users").fetchone()[0])
    else:
        prepare(root, args.model_zip, args.camera_url)
