"""Signed HTTP callbacks back to Laravel's /api/ml/training/{run}/* routes."""
import hashlib
import hmac
import json

import httpx

from config import settings


def _post(url: str, payload: dict) -> None:
    """POST a JSON payload with an HMAC-SHA256 signature over the raw body.

    Best-effort: failures are swallowed so a callback hiccup never crashes the
    training loop (Laravel can still be inspected directly).
    """
    body = json.dumps(payload, separators=(",", ":")).encode()
    signature = hmac.new(
        settings.ml_callback_secret.encode(), body, hashlib.sha256
    ).hexdigest()

    try:
        httpx.post(
            url,
            content=body,
            headers={
                "Content-Type": "application/json",
                "Accept": "application/json",
                "X-ML-Signature": signature,
            },
            timeout=15,
        )
    except Exception as exc:  # noqa: BLE001
        print(f"[callback] failed POST {url}: {exc}", flush=True)


def progress(callback_url: str, percent: int, epoch: int, status: str = "training") -> None:
    _post(f"{callback_url}/progress", {
        "progress": int(percent),
        "current_epoch": int(epoch),
        "status": status,
    })


def complete(callback_url: str, model_path: str, metrics: dict,
             dataset_train: int, dataset_val: int) -> None:
    _post(f"{callback_url}/complete", {
        "model_path": model_path,
        "metrics": metrics,
        "dataset_train": int(dataset_train),
        "dataset_val": int(dataset_val),
    })


def fail(callback_url: str, error: str) -> None:
    _post(f"{callback_url}/fail", {"error": str(error)[:1000]})


def post_detection(laravel_url: str, payload: dict) -> None:
    """Send a stream-inference verdict to Laravel's signed ingest endpoint."""
    _post(f"{laravel_url.rstrip('/')}/api/camera/detection", payload)
