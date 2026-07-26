import { queryKeys } from "@/lib/queryClient";
import {
  getTrainingDataset,
  getTrainingRuns,
  startTrainingRun,
  type TrainingRun,
} from "@/services/trainingApi";
import { type Paginated } from "@/services/types";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

/** Sesering apa progres di-poll selama ada run berjalan. */
const ACTIVE_POLL_MS = 3_000;

export function useTrainingRuns(params: { per_page?: number } = {}) {
  return useQuery({
    queryKey: queryKeys.trainingRuns.list(params),
    queryFn: () => getTrainingRuns(params),
    placeholderData: (previous) => previous,
    // Poll HANYA selama ada run aktif — mengikuti pola `wire:poll` di dashboard
    // web, supaya aplikasi tidak terus menembak server saat idle.
    refetchInterval: (query) => {
      const data = query.state.data as Paginated<TrainingRun> | undefined;
      const active = data?.data.some((run) => run.is_active) ?? false;
      return active ? ACTIVE_POLL_MS : false;
    },
  });
}

export function useTrainingDataset() {
  return useQuery({
    queryKey: queryKeys.trainingRuns.dataset,
    queryFn: getTrainingDataset,
  });
}

export function useStartTrainingRun() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (epochs: number) => startTrainingRun(epochs),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: queryKeys.trainingRuns.all });
      // Run baru mencatat entri di system log.
      void client.invalidateQueries({ queryKey: queryKeys.logs.all });
    },
  });
}
