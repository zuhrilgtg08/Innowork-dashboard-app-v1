"""Environment-driven configuration for the ML service."""
from pathlib import Path

from pydantic import Field, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    # Absolute .env location: the service must load ml-service/.env no
    # matter which directory uvicorn is started from (repo root vs
    # ml-service/). Without this, ICAM_MODEL_PATH silently reads empty and
    # the sorting pipeline rejects every frame with model_error.
    model_config = SettingsConfigDict(
        env_file=str(Path(__file__).parent / ".env"), extra="ignore"
    )

    # Absolute path to Laravel's storage/app directory (shared filesystem).
    # Falls back to a relative guess if not provided.
    laravel_storage_path: str = "../storage/app"

    # Base URL of the Laravel app (for reference / logging).
    laravel_url: str = "http://127.0.0.1:8000"

    # Shared secret used to sign callbacks (must match Laravel's ML_CALLBACK_SECRET).
    ml_callback_secret: str = ""

    # Base YOLO weights used when starting a fresh training run.
    base_model: str = "yolov8n.pt"

    # --- ICAM-300 camera integration ---------------------------------------
    # RTSP URL of the ICAM-300 when "playing" (rtsp://<ip>:8550/video). Empty
    # string enables simulator mode (see icam_sim_source below).
    icam_rtsp_url: str = ""

    # Multi-camera: comma-separated RTSP URLs, one per camera on the line. When
    # set, the service can run one capture/inference thread per feed (mirrors the
    # Camera registry in Laravel). Empty falls back to the single icam_rtsp_url.
    icam_rtsp_urls: str = ""

    @property
    def rtsp_url_list(self) -> list[str]:
        """Parsed, de-duplicated list of camera RTSP URLs (multi-camera)."""
        raw = self.icam_rtsp_urls or self.icam_rtsp_url
        return [u.strip() for u in raw.split(",") if u.strip()]

    # Fallback video source used when icam_rtsp_url is empty or unreachable.
    # A file path (looped) or a digit string like "0" for a local webcam.
    icam_sim_source: str = "samples/conveyor.mp4"

    # Seconds between automatic inference passes on the live stream.
    icam_infer_interval: float = Field(default=3.0, gt=0)

    # When true, the service runs the periodic infer→POST loop on startup.
    icam_auto_infer: bool = False

    # Context stamped onto detections created from the stream.
    icam_camera: str = "ICAM-300"
    icam_conveyor: str = "LINE-A"

    # Relative model path (models/run-x/best.pt) for stream inference; empty
    # falls back to the base model.
    icam_model_path: str = ""

    # Confidence threshold for stream inference.
    icam_conf: float = Field(default=0.85, ge=0, le=1)

    # --- Conveyor off-flow analysis (flow.py) ------------------------------
    # When true, the stream infer loop also runs jam/off_flow detection and
    # POSTs anomalies to Laravel's /api/conveyor/event.
    flow_analysis: bool = False

    # Rolling-window size (frames) the analyser smooths its signals over.
    flow_window: int = Field(default=15, ge=1)

    # Avg edge-density occupancy above this, with motion below flow_jam_motion,
    # counts as a jam (material piled up, not advancing).
    flow_jam_occupancy: float = 0.04
    flow_jam_motion: float = 0.01

    # Avg edge-density occupancy at/below this counts as off_flow (empty belt).
    flow_offflow_occupancy: float = 0.008

    # --- Competition sorting integration ------------------------------------
    # When true, the /infer endpoint runs the competition pipeline:
    # YOLO inference → signed POST /api/camera/detection → MQTT arm/command publish.
    competition_mode: bool = False

    # MQTT broker for arm/command (mock_hardware consumes this).
    mqtt_broker: str = "localhost"
    mqtt_port: int = Field(default=1883, ge=1, le=65535)
    mqtt_timeout: float = Field(default=5.0, gt=0)

    # MQTT authentication for the production broker. Empty username keeps
    # local anonymous compatibility; when set (VPS), both the sort pipeline
    # publisher and mock_hardware authenticate with these credentials.
    # Laravel reads the same values from MQTT_USERNAME/MQTT_PASSWORD.
    mqtt_username: str = ""
    mqtt_password: str = ""
    mqtt_use_tls: bool = False

    # Minimum confidence (0-1) for a detection to trigger a sort command.
    sort_min_confidence: float = Field(default=0.5, ge=0, le=1)

    # Maximum objects per color before SORTING_COMPLETE (default 3).
    max_objects_per_color: int = 3

    # Cooldown between sort commands in milliseconds.
    sort_cooldown_ms: int = Field(default=1000, ge=0)

    # Operational pick zone, normalized 0-1 (fraction of frame width/height).
    # A sort command is issued ONLY when the detected object's center falls
    # inside this rectangle. Defaults to the central 60% of the frame.
    # The Model Evaluation preview reuses the same bounds for its overlay.
    pick_zone_x_min: float = Field(default=0.2, ge=0, le=1)
    pick_zone_x_max: float = Field(default=0.8, ge=0, le=1)
    pick_zone_y_min: float = Field(default=0.2, ge=0, le=1)
    pick_zone_y_max: float = Field(default=0.8, ge=0, le=1)

    # Spatial quantization (normalized units) for the anti-duplicate latch:
    # centers falling in the same cell count as the same stationary object.
    sort_spatial_tolerance: float = Field(default=0.05, gt=0, le=1)

    # Mock hardware step delay (ms).
    mock_delay_ms: int = Field(default=300, ge=0)

    @model_validator(mode="after")
    def validate_paths_and_zone(self):
        if self.pick_zone_x_min >= self.pick_zone_x_max or self.pick_zone_y_min >= self.pick_zone_y_max:
            raise ValueError("pick-zone minimum must be smaller than maximum")
        storage = Path(self.laravel_storage_path)
        if not storage.is_absolute():
            self.laravel_storage_path = str((Path(__file__).parent / storage).resolve())
        return self


settings = Settings()
