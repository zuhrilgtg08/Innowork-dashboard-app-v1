/**
 * Helper validasi form yang dipakai bersama oleh layar auth & profil.
 *
 * Konvensi: setiap validator mengembalikan `string` berisi pesan error siap
 * tampil (Bahasa Indonesia), atau `null` kalau nilainya valid. Dengan begitu
 * pemanggil bisa langsung menaruh hasilnya di state `FieldErrors`.
 */

export type FieldErrors<TField extends string = string> = Partial<
  Record<TField, string>
>;

/** Panjang password minimum — samakan dengan aturan `min:8` milik Laravel. */
export const PASSWORD_MIN_LENGTH = 8;

/**
 * Cukup ketat untuk menangkap salah ketik umum, cukup longgar untuk tidak
 * menolak alamat yang sah. Validasi sesungguhnya tetap milik backend.
 */
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

export function validateRequired(
  value: string,
  label: string,
): string | null {
  return value.trim().length === 0 ? `${label} wajib diisi.` : null;
}

export function validateEmail(value: string): string | null {
  const trimmed = value.trim();
  if (trimmed.length === 0) {
    return "Email wajib diisi.";
  }

  return EMAIL_PATTERN.test(trimmed) ? null : "Format email tidak valid.";
}

export function validatePassword(
  value: string,
  minLength: number = PASSWORD_MIN_LENGTH,
): string | null {
  if (value.length === 0) {
    return "Password wajib diisi.";
  }

  return value.length < minLength
    ? `Password minimal ${minLength} karakter.`
    : null;
}

export function validatePasswordConfirmation(
  password: string,
  confirmation: string,
): string | null {
  if (confirmation.length === 0) {
    return "Konfirmasi password wajib diisi.";
  }

  return password === confirmation ? null : "Konfirmasi password tidak sama.";
}

/** Buang entri `null`/`undefined` supaya `hasErrors` tidak ikut menghitungnya. */
export function collectErrors<TField extends string>(
  candidates: Record<TField, string | null>,
): FieldErrors<TField> {
  const errors: FieldErrors<TField> = {};

  for (const [field, message] of Object.entries(candidates) as [
    TField,
    string | null,
  ][]) {
    if (message) {
      errors[field] = message;
    }
  }

  return errors;
}

export function hasErrors(errors: FieldErrors): boolean {
  return Object.keys(errors).length > 0;
}
