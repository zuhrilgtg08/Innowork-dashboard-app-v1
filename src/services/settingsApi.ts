import { apiRequest } from "@/services/api";
import { type Envelope } from "@/services/types";

export type AppSettings = {
  app_name: string;
  timezone: string;
  /** Ambang keyakinan inferensi, 0.5–1. */
  confidence_threshold: number;
  auto_retrain: boolean;
  email_alerts: boolean;
  auto_reject_on_damage: boolean;
  camera_source: "webcam" | "icam";
  icam_rtsp_url: string | null;
  active_training_run_id: number | null;
  updated_at: string | null;
};

/** Update bersifat parsial: kirim hanya field yang berubah. */
export type SettingsInput = Partial<
  Pick<
    AppSettings,
    | "app_name"
    | "timezone"
    | "confidence_threshold"
    | "auto_retrain"
    | "email_alerts"
    | "auto_reject_on_damage"
    | "camera_source"
    | "icam_rtsp_url"
  >
>;

export async function getSettings(): Promise<AppSettings> {
  const response = await apiRequest<Envelope<AppSettings>>("/settings");
  return response.data;
}

export async function updateSettings(
  input: SettingsInput,
): Promise<AppSettings> {
  const response = await apiRequest<Envelope<AppSettings>>("/settings", {
    method: "PUT",
    body: input,
  });
  return response.data;
}
