"""Environment-driven configuration for the ML service."""
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    # Absolute path to Laravel's storage/app directory (shared filesystem).
    # Falls back to a relative guess if not provided.
    laravel_storage_path: str = "../storage/app"

    # Base URL of the Laravel app (for reference / logging).
    laravel_url: str = "http://127.0.0.1:8000"

    # Shared secret used to sign callbacks (must match Laravel's ML_CALLBACK_SECRET).
    ml_callback_secret: str = ""

    # Base YOLO weights used when starting a fresh training run.
    base_model: str = "yolov8n.pt"


settings = Settings()
