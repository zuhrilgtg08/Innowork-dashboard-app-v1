import { queryKeys } from "@/lib/queryClient";
import { getDetections, type DetectionQuery } from "@/services/statusApi";
import { useQuery } from "@tanstack/react-query";

/**
 * Deteksi terbaru, opsional disaring per kamera.
 *
 * `refetchMs` dibuat parameter supaya layar live-camera bisa menyegarkan
 * secepat feed-nya, sementara layar lain cukup sesekali.
 */
export function useDetections(
  query: DetectionQuery = {},
  refetchMs: number | false = false,
) {
  return useQuery({
    queryKey: queryKeys.detections.list(query),
    queryFn: () => getDetections(query),
    refetchInterval: refetchMs,
    placeholderData: (previous) => previous,
  });
}
