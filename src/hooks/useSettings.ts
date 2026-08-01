import { queryKeys } from "@/lib/queryClient";
import {
  getSettings,
  updateSettings,
  type SettingsInput,
} from "@/services/settingsApi";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

export function useSettings() {
  return useQuery({
    queryKey: queryKeys.settings.all,
    queryFn: getSettings,
  });
}

export function useUpdateSettings() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (input: SettingsInput) => updateSettings(input),
    onSuccess: (settings) => {
      // Backend mengembalikan baris lengkap setelah update, jadi cache bisa
      // langsung diisi tanpa perlu fetch ulang.
      client.setQueryData(queryKeys.settings.all, settings);
    },
  });
}
