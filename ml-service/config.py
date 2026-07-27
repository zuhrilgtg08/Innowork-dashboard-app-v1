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
    icam_infer_interval: float = 3.0

    # When true, the service runs the periodic infer→POST loop on startup.
    icam_auto_infer: bool = False

    # Context stamped onto detections created from the stream.
    icam_camera: str = "ICAM-300"
    icam_conveyor: str = "LINE-A"

    # Relative model path (models/run-x/best.pt) for stream inference; empty
    # falls back to the base model.
    icam_model_path: str = ""

    # Confidence threshold for stream inference.
    icam_conf: float = 0.85

    # --- Conveyor off-flow analysis (flow.py) ------------------------------
    # When true, the stream infer loop also runs jam/off_flow detection and
    # POSTs anomalies to Laravel's /api/conveyor/event.
    flow_analysis: bool = False

    # Rolling-window size (frames) the analyser smooths its signals over.
    flow_window: int = 15

    # Avg edge-density occupancy above this, with motion below flow_jam_motion,
    # counts as a jam (material piled up, not advancing).
    flow_jam_occupancy: float = 0.04
    flow_jam_motion: float = 0.01

    # Avg edge-density occupancy at/below this counts as off_flow (empty belt).
    flow_offflow_occupancy: float = 0.008


settings = Settings()
