import { queryKeys } from "@/lib/queryClient";
import { getLogFilters, getLogs, type LogFilters } from "@/services/logApi";
import { useQuery } from "@tanstack/react-query";

/** Log baru cukup sering muncul; segarkan berkala selama layar terbuka. */
const LOG_POLL_MS = 15_000;

export function useLogs(filters: LogFilters = {}) {
  return useQuery({
    queryKey: queryKeys.logs.list(filters),
    queryFn: () => getLogs(filters),
    placeholderData: (previous) => previous,
    refetchInterval: LOG_POLL_MS,
  });
}

export function useLogFilterOptions() {
  return useQuery({
    queryKey: queryKeys.logs.filters,
    queryFn: getLogFilters,
    // Daftar level & source berasal dari konstanta model — praktis statis.
    staleTime: 10 * 60_000,
  });
}
