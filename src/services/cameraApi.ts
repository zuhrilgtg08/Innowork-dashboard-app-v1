import { API_BASE_URL, apiRequest, AUTH_TOKEN_KEY } from "@/services/api";
import * as storage from "@/services/storage";
import {
  buildQuery,
  type Envelope,
  type PageParams,
  type Paginated,
} from "@/services/types";

export type Camera = {
  id: number;
  name: string;
  conveyor: string | null;
  is_active: boolean;
  position: number;
  /** True bila kamera memakai sumber RTSP nyata, bukan simulator. */
  is_live: boolean;
  source_kind: "rtsp" | "simulator";
};

export type CameraStatus = {
  connected: boolean;
  mode: "live" | "simulator" | "offline" | string;
  fps: number;
  /** False bila Laravel tidak bisa menghubungi ml-service sama sekali. */
  service_reachable: boolean;
};

export async function getCameras(
  params: PageParams & { is_active?: boolean } = {},
): Promise<Paginated<Camera>> {
  return apiRequest<Paginated<Camera>>(
    `/cameras${buildQuery({
      is_active:
        params.is_active === undefined ? undefined : params.is_active ? 1 : 0,
      page: params.page,
      per_page: params.per_page,
    })}`,
  );
}

export async function getCameraStatus(): Promise<CameraStatus> {
  const response = await apiRequest<Envelope<CameraStatus>>("/cameras/status");
  return response.data;
}

/**
 * Sumber gambar untuk satu frame kamera, siap dipakai `<Image source={...}>`.
 *
 * Kenapa polling frame tunggal, bukan MJPEG: endpoint `/camera/stream` di
 * ml-service mengirim `multipart/x-mixed-replace`, dan image loader native
 * (iOS/Android) tidak bisa merendernya — di perangkat hasilnya layar kosong.
 * Backend menyediakan `/cameras/frame` yang mengembalikan satu JPEG, dan layar
 * memanggilnya berulang untuk membentuk tampilan bergerak.
 *
 * `cacheBust` wajib berubah tiap frame: tanpa itu image loader akan memakai
 * hasil cache dan gambarnya membeku.
 */
export async function buildFrameSource(
  cacheBust: number,
): Promise<{ uri: string; headers: Record<string, string> }> {
  const token = await storage.getItemAsync(AUTH_TOKEN_KEY);

  return {
    uri: `${API_BASE_URL}/cameras/frame?t=${cacheBust}`,
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  };
}
