import { queryKeys } from "@/lib/queryClient";
import {
  buildFrameSource,
  getCameras,
  getCameraStatus,
} from "@/services/cameraApi";
import { useQuery } from "@tanstack/react-query";
import { useEffect, useRef, useState } from "react";

/** Laju penyegaran frame yang boleh dipilih user. */
export const FRAME_RATES = [1, 2, 5, 10] as const;

export type FrameRate = (typeof FRAME_RATES)[number];

export const DEFAULT_FRAME_RATE: FrameRate = 5;

export function useCameras() {
  return useQuery({
    queryKey: queryKeys.cameras.list,
    queryFn: () => getCameras({ per_page: 50 }),
    staleTime: 5 * 60_000,
  });
}

export function useCameraStatus(enabled = true) {
  return useQuery({
    queryKey: queryKeys.cameras.status,
    queryFn: getCameraStatus,
    // Status murah dan menentukan apa yang ditampilkan overlay; segarkan
    // beberapa detik sekali selama layar aktif.
    refetchInterval: enabled ? 5_000 : false,
    enabled,
  });
}

type FrameSource = { uri: string; headers: Record<string, string> };

/**
 * Menghasilkan sumber gambar yang berganti pada laju tertentu, sehingga
 * rentetan JPEG dari backend tampil sebagai video.
 *
 * Bukan MJPEG: lihat catatan di `buildFrameSource`. Timer hanya hidup saat
 * `playing` true supaya layar yang di-pause benar-benar berhenti menembak
 * server, bukan sekadar menyembunyikan gambar.
 */
export function useCameraStream(playing: boolean, fps: number) {
  const [source, setSource] = useState<FrameSource | null>(null);
  const [error, setError] = useState<string | null>(null);

  // Dipakai supaya frame yang datang terlambat tidak menimpa frame terbaru.
  const generation = useRef(0);

  useEffect(() => {
    if (!playing) {
      return;
    }

    let cancelled = false;
    generation.current += 1;
    const myGeneration = generation.current;

    const interval = Math.max(50, Math.round(1000 / Math.max(1, fps)));

    const tick = async () => {
      try {
        const next = await buildFrameSource(Date.now());
        if (!cancelled && generation.current === myGeneration) {
          setSource(next);
          setError(null);
        }
      } catch (e) {
        if (!cancelled) {
          setError(e instanceof Error ? e.message : "Gagal memuat frame.");
        }
      }
    };

    void tick();
    const timer = setInterval(tick, interval);

    return () => {
      cancelled = true;
      clearInterval(timer);
    };
  }, [playing, fps]);

  return { source, error };
}
