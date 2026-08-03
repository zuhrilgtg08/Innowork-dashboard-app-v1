import { apiRequest } from "@/services/api";
import {
  buildQuery,
  type Envelope,
  type PageParams,
  type Paginated,
} from "@/services/types";

export type LogLevel = "info" | "warning" | "error" | "critical";

export type SystemLog = {
  id: number;
  level: LogLevel;
  /** Nama warna Tailwind dari backend (blue/amber/red/rose). */
  level_color: string;
  source: string;
  message: string;
  context: Record<string, unknown> | null;
  logged_at: string | null;
};

export type LogFilters = PageParams & {
  level?: LogLevel | "";
  source?: string;
  search?: string;
};

export type LogFilterOptions = {
  levels: { key: LogLevel; color: string }[];
  sources: string[];
};

export async function getLogs(
  filters: LogFilters = {},
): Promise<Paginated<SystemLog>> {
  return apiRequest<Paginated<SystemLog>>(
    `/logs${buildQuery({
      level: filters.level,
      source: filters.source,
      search: filters.search,
      page: filters.page,
      per_page: filters.per_page,
    })}`,
  );
}

/** Opsi filter datang dari backend supaya tidak di-hard-code di mobile. */
export async function getLogFilters(): Promise<LogFilterOptions> {
  const response = await apiRequest<Envelope<LogFilterOptions>>(
    "/logs/filters",
  );
  return response.data;
}
