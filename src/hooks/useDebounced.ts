import { useEffect, useState } from "react";

/**
 * Menunda perubahan nilai sampai user berhenti mengetik selama `delayMs`.
 *
 * Dipakai untuk kolom pencarian: tanpa ini setiap ketikan memicu satu request
 * ke server dan hasil lama bisa datang belakangan menimpa hasil baru.
 */
export function useDebounced<T>(value: T, delayMs = 350): T {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delayMs);
    return () => clearTimeout(timer);
  }, [value, delayMs]);

  return debounced;
}
