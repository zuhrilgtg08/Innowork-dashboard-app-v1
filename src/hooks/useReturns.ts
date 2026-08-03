import { queryKeys } from "@/lib/queryClient";
import {
  getReturnBatch,
  getReturns,
  resolveReturnBatch,
  type ReturnFilters,
} from "@/services/returnApi";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

export function useReturns(filters: ReturnFilters = {}) {
  return useQuery({
    queryKey: queryKeys.returns.list(filters),
    queryFn: () => getReturns(filters),
    placeholderData: (previous) => previous,
  });
}

export function useReturnBatch(id: number | null) {
  return useQuery({
    queryKey: queryKeys.returns.detail(id ?? 0),
    queryFn: () => getReturnBatch(id as number),
    enabled: id !== null,
  });
}

export function useResolveReturnBatch() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: ({ id, notes }: { id: number; notes?: string }) =>
      resolveReturnBatch(id, notes),
    onSuccess: (batch) => {
      void client.invalidateQueries({ queryKey: queryKeys.returns.all });
      void client.invalidateQueries({
        queryKey: queryKeys.returns.detail(batch.id),
      });
    },
  });
}
