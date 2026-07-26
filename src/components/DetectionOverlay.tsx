import { type DetectionItem } from "@/services/statusApi";
import Svg, { Rect, Text as SvgText } from "react-native-svg";

/** Warna kotak mengikuti verdict QC, selaras dengan Detection::STATUSES. */
const STATUS_COLORS: Record<string, string> = {
  passed: "#16a34a",
  damaged: "#dc2626",
  scratched: "#ea580c",
  unreadable: "#ca8a04",
  returned: "#e11d48",
  recheck: "#2563eb",
};

const FALLBACK_COLOR = "#6b7280";

type Props = {
  detections: DetectionItem[];
  /** Ukuran area tempat frame dirender di layar. */
  width: number;
  height: number;
};

/**
 * Menggambar bounding box hasil deteksi di atas frame kamera.
 *
 * `bbox` dari backend memakai koordinat piksel frame ASLI, sementara di layar
 * frame dirender pada ukuran lain — jadi setiap kotak diskalakan memakai
 * `frame_width`/`frame_height` yang ikut dikirim bersama deteksi. Tanpa itu
 * kotak akan meleset jauh pada perangkat dengan lebar berbeda.
 *
 * Deteksi tanpa `bbox` (mis. dari jalur webcam manual) dilewati, bukan digambar
 * di posisi tebakan.
 */
export default function DetectionOverlay({ detections, width, height }: Props) {
  const boxes = detections.filter(
    (d) =>
      Array.isArray(d.bbox) &&
      d.bbox.length === 4 &&
      (d.frame_width ?? 0) > 0 &&
      (d.frame_height ?? 0) > 0,
  );

  if (boxes.length === 0 || width <= 0 || height <= 0) {
    return null;
  }

  return (
    <Svg
      width={width}
      height={height}
      style={{ position: "absolute", left: 0, top: 0 }}
      pointerEvents="none"
    >
      {boxes.map((detection, index) => {
        const [x1, y1, x2, y2] = detection.bbox as [
          number,
          number,
          number,
          number,
        ];
        const scaleX = width / (detection.frame_width as number);
        const scaleY = height / (detection.frame_height as number);

        const left = x1 * scaleX;
        const top = y1 * scaleY;
        const boxWidth = Math.max(1, (x2 - x1) * scaleX);
        const boxHeight = Math.max(1, (y2 - y1) * scaleY);

        const color = STATUS_COLORS[detection.status ?? ""] ?? FALLBACK_COLOR;

        // Label digambar di pass kedua di bawah — <Rect> tidak bisa memuat teks.
        return (
          <Rect
            key={detection.id ?? `${index}-${x1}-${y1}`}
            x={left}
            y={top}
            width={boxWidth}
            height={boxHeight}
            stroke={color}
            strokeWidth={2}
            fill={color}
            fillOpacity={0.12}
          />
        );
      })}

      {boxes.map((detection, index) => {
        const [x1, y1] = detection.bbox as [number, number, number, number];
        const scaleX = width / (detection.frame_width as number);
        const scaleY = height / (detection.frame_height as number);
        const color = STATUS_COLORS[detection.status ?? ""] ?? FALLBACK_COLOR;
        const confidence =
          detection.confidence !== null && detection.confidence !== undefined
            ? ` ${Math.round(Number(detection.confidence))}%`
            : "";

        // Jaga label tetap terlihat saat kotak menempel di tepi atas.
        const labelY = Math.max(12, y1 * scaleY - 5);

        return (
          <SvgText
            key={`label-${detection.id ?? index}`}
            x={Math.max(2, x1 * scaleX)}
            y={labelY}
            fill={color}
            fontSize={11}
            fontWeight="bold"
          >
            {`${detection.label ?? detection.status ?? "?"}${confidence}`}
          </SvgText>
        );
      })}
    </Svg>
  );
}
