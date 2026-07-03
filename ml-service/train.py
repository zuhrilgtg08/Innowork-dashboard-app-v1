"""Dataset export + YOLO training loop (CPU, demo-scale)."""
import os
import shutil
from pathlib import Path

import yaml

import callbacks
from config import settings

BASE_DIR = Path(__file__).parent
RUNS_DIR = BASE_DIR / "runs"


def _public_path(storage_path: str, image_path: str) -> Path:
    """Resolve an annotation's public-disk-relative path to an absolute file."""
    return Path(storage_path) / "public" / image_path


def build_dataset(run_id: int, storage_path: str, annotations: list[dict]) -> tuple[Path, list[str], int, int]:
    """Materialize a YOLO detection dataset from the annotation list.

    Returns (dataset_dir, class_names, train_count, val_count).
    """
    dataset_dir = RUNS_DIR / f"dataset-{run_id}"
    if dataset_dir.exists():
        shutil.rmtree(dataset_dir)

    for split in ("train", "val"):
        (dataset_dir / "images" / split).mkdir(parents=True, exist_ok=True)
        (dataset_dir / "labels" / split).mkdir(parents=True, exist_ok=True)

    # Stable, sorted class list so indices are deterministic.
    class_names = sorted({a["label"] for a in annotations})
    class_index = {name: i for i, name in enumerate(class_names)}

    counts = {"train": 0, "val": 0}
    for i, ann in enumerate(annotations):
        src = _public_path(storage_path, ann["image_path"])
        if not src.exists():
            print(f"[train] missing image, skipping: {src}", flush=True)
            continue

        split = ann.get("split", "train")
        if split not in ("train", "val"):
            split = "train"

        stem = f"{i:06d}_{src.stem}"
        dst_img = dataset_dir / "images" / split / f"{stem}{src.suffix}"
        shutil.copyfile(src, dst_img)

        # bbox is [x, y, w, h] normalized (top-left origin); null = full frame.
        bbox = ann.get("bbox")
        if bbox and len(bbox) == 4:
            x, y, w, h = bbox
            xc, yc = x + w / 2, y + h / 2
        else:
            xc, yc, w, h = 0.5, 0.5, 1.0, 1.0

        cls = class_index[ann["label"]]
        label_file = dataset_dir / "labels" / split / f"{stem}.txt"
        label_file.write_text(f"{cls} {xc:.6f} {yc:.6f} {w:.6f} {h:.6f}\n")
        counts[split] += 1

    # YOLO needs a non-empty validation set; mirror train if none was assigned.
    if counts["val"] == 0 and counts["train"] > 0:
        for kind in ("images", "labels"):
            for f in (dataset_dir / kind / "train").iterdir():
                shutil.copyfile(f, dataset_dir / kind / "val" / f.name)
        counts["val"] = counts["train"]

    data_yaml = dataset_dir / "data.yaml"
    data_yaml.write_text(yaml.safe_dump({
        "path": str(dataset_dir.resolve()),
        "train": "images/train",
        "val": "images/val",
        "names": {i: n for i, n in enumerate(class_names)},
    }))

    return dataset_dir, class_names, counts["train"], counts["val"]


def run_training(run_id: int, epochs: int, imgsz: int, storage_path: str,
                 callback_url: str, annotations: list[dict]) -> None:
    """Full training job: export dataset, train YOLO, emit callbacks."""
    from ultralytics import YOLO

    try:
        if not annotations:
            callbacks.fail(callback_url, "No approved annotations to train on.")
            return

        callbacks.progress(callback_url, 2, 0, status="exporting")
        dataset_dir, class_names, n_train, n_val = build_dataset(
            run_id, storage_path, annotations
        )

        if n_train == 0:
            callbacks.fail(callback_url, "No usable annotation images were found on disk.")
            return

        model = YOLO(settings.base_model)

        def on_epoch_end(trainer):
            epoch = int(getattr(trainer, "epoch", 0)) + 1
            total = int(getattr(trainer, "epochs", epochs)) or epochs
            percent = max(2, min(99, round(epoch / total * 100)))
            callbacks.progress(callback_url, percent, epoch, status="training")

        model.add_callback("on_train_epoch_end", on_epoch_end)

        project = str((RUNS_DIR / "train").resolve())
        name = f"run-{run_id}"
        callbacks.progress(callback_url, 5, 0, status="training")

        model.train(
            data=str(dataset_dir / "data.yaml"),
            epochs=epochs,
            imgsz=imgsz,
            batch=4,
            device="cpu",
            workers=0,
            cache=False,
            project=project,
            name=name,
            exist_ok=True,
            verbose=False,
            plots=False,
        )

        # Persist the produced weights into Laravel's storage/app/models/.
        best = Path(project) / name / "weights" / "best.pt"
        if not best.exists():
            best = Path(project) / name / "weights" / "last.pt"

        dest_dir = Path(storage_path) / "models" / f"run-{run_id}"
        dest_dir.mkdir(parents=True, exist_ok=True)
        dest = dest_dir / "best.pt"
        shutil.copyfile(best, dest)
        model_rel = f"models/run-{run_id}/best.pt"

        # Pull final metrics from the validation results.
        metrics = extract_metrics(model, class_names)

        callbacks.complete(callback_url, model_rel, metrics, n_train, n_val)
    except Exception as exc:  # noqa: BLE001
        import traceback
        traceback.print_exc()
        callbacks.fail(callback_url, str(exc))


def extract_metrics(model, class_names: list[str]) -> dict:
    """Read mAP/precision/recall (overall + per class) from the trainer."""
    try:
        rd = getattr(model.trainer, "metrics", {}) or {}
        map50 = round(float(rd.get("metrics/mAP50(B)", 0)) * 100, 1)
        precision = round(float(rd.get("metrics/precision(B)", 0)) * 100, 1)
        recall = round(float(rd.get("metrics/recall(B)", 0)) * 100, 1)

        per_class = []
        try:
            v = model.trainer.validator.metrics
            for i, name in enumerate(class_names):
                p, r, ap50, _ = v.class_result(i)
                per_class.append({
                    "label": name,
                    "precision": round(float(p) * 100, 1),
                    "recall": round(float(r) * 100, 1),
                    "map50": round(float(ap50) * 100, 1),
                })
        except Exception:  # noqa: BLE001
            per_class = [{"label": n} for n in class_names]

        return {
            "map50": map50,
            "precision": precision,
            "recall": recall,
            "per_class": per_class,
        }
    except Exception:  # noqa: BLE001
        return {"map50": 0, "precision": 0, "recall": 0, "per_class": []}
