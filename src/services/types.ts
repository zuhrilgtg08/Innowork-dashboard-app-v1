/**
 * Bentuk amplop yang dipakai semua endpoint list di backend (lihat
 * `API_CONTRACT.md` bagian "CRUD Resources").
 */

export type PaginationMeta = {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
};

export type Paginated<T> = {
  data: T[];
  meta: PaginationMeta;
};

/** Amplop untuk detail tunggal / hasil create & update. */
export type Envelope<T> = {
  message?: string;
  data: T;
};

/** Parameter paging yang berlaku di semua endpoint list. */
export type PageParams = {
  page?: number;
  per_page?: number;
};

/**
 * Susun query string, membuang nilai kosong supaya tidak mengirim
 * `?search=&status=` yang bikin backend menolak filter kosong.
 */
export function buildQuery(
  params: Record<string, string | number | boolean | null | undefined>,
): string {
  const parts: string[] = [];

  for (const [key, value] of Object.entries(params)) {
    if (value === null || value === undefined || value === "") {
      continue;
    }
    parts.push(
      `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`,
    );
  }

  return parts.length > 0 ? `?${parts.join("&")}` : "";
}

/**
 * Lampirkan field ke FormData. Nilai boolean dikirim sebagai "1"/"0" karena
 * aturan `boolean` Laravel tidak menerima string "true"/"false" dari multipart.
 */
export function appendField(
  form: FormData,
  key: string,
  value: string | number | boolean | null | undefined,
): void {
  if (value === null || value === undefined) {
    return;
  }

  if (typeof value === "boolean") {
    form.append(key, value ? "1" : "0");
    return;
  }

  form.append(key, String(value));
}

/**
 * Berkas gambar yang dipilih user. Bentuk `{ uri, name, type }` inilah yang
 * diterima FormData di React Native (bukan `File`/`Blob` seperti di web).
 */
export type UploadFile = {
  uri: string;
  name: string;
  type: string;
};

export function appendFile(
  form: FormData,
  key: string,
  file: UploadFile | null | undefined,
): void {
  if (!file) {
    return;
  }

  // React Native menerima objek ini sebagai bagian multipart; cast diperlukan
  // karena tipe DOM FormData hanya mengenal Blob/string.
  form.append(key, file as unknown as Blob);
}
