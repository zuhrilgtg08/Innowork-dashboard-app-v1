import { apiRequest } from "@/services/api";
import {
  buildQuery,
  type Envelope,
  type PageParams,
  type Paginated,
} from "@/services/types";

export type ReturnStatus = "open" | "resolved";

export type ReturnBatch = {
  id: number;
  conveyor: string | null;
  reason: string | null;
  status: ReturnStatus;
  status_label: string;
  status_color: string;
  notes: string | null;
  detections_count: number | null;
  resolved_by: { id: number; name: string } | null;
  resolved_at: string | null;
  created_at: string | null;
};

/** Deteksi cacat yang dikumpulkan sebuah batch. */
export type ReturnDetection = {
  id: number;
  code: string | null;
  status: string | null;
  status_label: string;
  camera: string | null;
  conveyor: string | null;
  confidence: number | null;
  detected_at: string | null;
  product: { id: number; code: string; name: string } | null;
};

export type ReturnBatchDetail = ReturnBatch & {
  detections: ReturnDetection[];
};

export type ReturnFilters = PageParams & {
  status?: ReturnStatus | "";
  conveyor?: string;
};

export async function getReturns(
  filters: ReturnFilters = {},
): Promise<Paginated<ReturnBatch>> {
  return apiRequest<Paginated<ReturnBatch>>(
    `/returns${buildQuery({
      status: filters.status,
      conveyor: filters.conveyor,
      page: filters.page,
      per_page: filters.per_page,
    })}`,
  );
}

export async function getReturnBatch(id: number): Promise<ReturnBatchDetail> {
  const response = await apiRequest<Envelope<ReturnBatchDetail>>(
    `/returns/${id}`,
  );
  return response.data;
}

/**
 * Tandai batch selesai. Backend membalas `409` bila batch sudah resolved
 * (misal operator lain menyelesaikannya lebih dulu).
 */
export async function resolveReturnBatch(
  id: number,
  notes?: string,
): Promise<ReturnBatch> {
  const response = await apiRequest<Envelope<ReturnBatch>>(
    `/returns/${id}/resolve`,
    { method: "POST", body: notes ? { notes } : {} },
  );
  return response.data;
}
