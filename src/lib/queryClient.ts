import { ApiError } from "@/services/api";
import { QueryClient } from "@tanstack/react-query";

/**
 * Status yang tidak akan membaik kalau diulang: kredensial habis (401),
 * role tidak berhak (403), resource tidak ada (404), validasi gagal (422),
 * konflik state (409). Mengulanginya hanya menunda pesan error ke user.
 */
const NON_RETRYABLE = new Set([401, 403, 404, 409, 422]);

function shouldRetry(failureCount: number, error: unknown): boolean {
  if (error instanceof ApiError && NON_RETRYABLE.has(error.status)) {
    return false;
  }

  // Sisanya (5xx, jaringan putus) dicoba ulang secukupnya.
  return failureCount < 2;
}

export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        retry: shouldRetry,
        // Data QC berubah cepat, tapi 30 detik cukup untuk mencegah refetch
        // beruntun saat user bolak-balik antar layar.
        staleTime: 30_000,
        // React Native tidak punya konsep "window focus" seperti browser;
        // refetch saat layar kembali fokus ditangani per-layar bila perlu.
        refetchOnWindowFocus: false,
      },
      mutations: {
        // Mutation tidak pernah diulang otomatis: create/delete yang diulang
        // diam-diam bisa menghasilkan data ganda.
        retry: false,
      },
    },
  });
}

/**
 * Kunci cache terpusat supaya invalidasi setelah mutation tidak salah sasaran.
 * Pola: `[resource]` untuk semua, `[resource, 'list', filters]` untuk daftar.
 */
export const queryKeys = {
  products: {
    all: ["products"] as const,
    list: (filters: unknown) => ["products", "list", filters] as const,
    detail: (id: number) => ["products", "detail", id] as const,
  },
  categories: {
    all: ["categories"] as const,
    list: (filters: unknown) => ["categories", "list", filters] as const,
    detail: (id: number) => ["categories", "detail", id] as const,
  },
  users: {
    all: ["users"] as const,
    list: (filters: unknown) => ["users", "list", filters] as const,
    detail: (id: number) => ["users", "detail", id] as const,
  },
  roles: {
    all: ["roles"] as const,
  },
  trainingRuns: {
    all: ["training-runs"] as const,
    list: (params: unknown) => ["training-runs", "list", params] as const,
    detail: (id: number) => ["training-runs", "detail", id] as const,
    dataset: ["training-runs", "dataset"] as const,
  },
  logs: {
    all: ["logs"] as const,
    list: (filters: unknown) => ["logs", "list", filters] as const,
    filters: ["logs", "filters"] as const,
  },
  settings: {
    all: ["settings"] as const,
  },
  cameras: {
    all: ["cameras"] as const,
    list: ["cameras", "list"] as const,
    status: ["cameras", "status"] as const,
  },
  detections: {
    all: ["detections"] as const,
    list: (filters: unknown) => ["detections", "list", filters] as const,
  },
  returns: {
    all: ["returns"] as const,
    list: (filters: unknown) => ["returns", "list", filters] as const,
    detail: (id: number) => ["returns", "detail", id] as const,
  },
} as const;
