import { isForbiddenError } from "@/services/api";
import { Ionicons } from "@expo/vector-icons";
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from "react-native";

/** Spinner untuk pemuatan pertama sebuah layar. */
export function LoadingState({ label = "Memuat..." }: { label?: string }) {
  return (
    <View style={styles.centered}>
      <ActivityIndicator size="large" color="#2563eb" />
      <Text style={styles.mutedText}>{label}</Text>
    </View>
  );
}

/**
 * Tampilan error yang membedakan "tidak punya akses" dari kegagalan biasa.
 *
 * `403` datang dari middleware `EnsureModuleAccess` di backend — role user
 * memang tidak berhak atas modul ini (atau akunnya dinonaktifkan). Menawarkan
 * tombol "coba lagi" untuk kasus itu menyesatkan: berapa kali pun diulang
 * hasilnya sama. Jadi tombolnya disembunyikan.
 */
export function ErrorState({
  error,
  onRetry,
}: {
  error: unknown;
  onRetry?: () => void;
}) {
  const forbidden = isForbiddenError(error);
  const message =
    error instanceof Error ? error.message : "Terjadi kesalahan tak terduga.";

  return (
    <View style={styles.centered}>
      <View style={[styles.iconWrap, forbidden && styles.iconWrapWarn]}>
        <Ionicons
          name={forbidden ? "lock-closed-outline" : "cloud-offline-outline"}
          size={28}
          color={forbidden ? "#b45309" : "#dc2626"}
        />
      </View>

      <Text style={styles.title}>
        {forbidden ? "Tidak punya akses" : "Gagal memuat data"}
      </Text>
      <Text style={styles.mutedText}>{message}</Text>

      {!forbidden && onRetry ? (
        <Pressable style={styles.retryBtn} onPress={onRetry}>
          <Ionicons name="refresh-outline" size={16} color="#fff" />
          <Text style={styles.retryText}>Coba Lagi</Text>
        </Pressable>
      ) : null}
    </View>
  );
}

export function EmptyState({
  icon = "file-tray-outline",
  title,
  hint,
}: {
  icon?: keyof typeof Ionicons.glyphMap;
  title: string;
  hint?: string;
}) {
  return (
    <View style={styles.centered}>
      <View style={styles.iconWrapMuted}>
        <Ionicons name={icon} size={28} color="#9ca3af" />
      </View>
      <Text style={styles.title}>{title}</Text>
      {hint ? <Text style={styles.mutedText}>{hint}</Text> : null}
    </View>
  );
}

/** Kontrol paging sederhana untuk semua layar daftar. */
export function Paginator({
  page,
  lastPage,
  total,
  onChange,
}: {
  page: number;
  lastPage: number;
  total: number;
  onChange: (page: number) => void;
}) {
  if (lastPage <= 1) {
    return null;
  }

  return (
    <View style={styles.paginator}>
      <Pressable
        style={[styles.pageBtn, page <= 1 && styles.pageBtnDisabled]}
        disabled={page <= 1}
        onPress={() => onChange(page - 1)}
      >
        <Ionicons
          name="chevron-back"
          size={18}
          color={page <= 1 ? "#d1d5db" : "#2563eb"}
        />
      </Pressable>

      <Text style={styles.pageLabel}>
        Hal. {page} / {lastPage} · {total} data
      </Text>

      <Pressable
        style={[styles.pageBtn, page >= lastPage && styles.pageBtnDisabled]}
        disabled={page >= lastPage}
        onPress={() => onChange(page + 1)}
      >
        <Ionicons
          name="chevron-forward"
          size={18}
          color={page >= lastPage ? "#d1d5db" : "#2563eb"}
        />
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  centered: {
    alignItems: "center",
    justifyContent: "center",
    paddingVertical: 48,
    paddingHorizontal: 24,
    gap: 10,
  },
  iconWrap: {
    width: 64,
    height: 64,
    borderRadius: 20,
    backgroundColor: "#fce7e7",
    justifyContent: "center",
    alignItems: "center",
    marginBottom: 4,
  },
  iconWrapWarn: { backgroundColor: "#fef3c7" },
  iconWrapMuted: {
    width: 64,
    height: 64,
    borderRadius: 20,
    backgroundColor: "#f3f4f6",
    justifyContent: "center",
    alignItems: "center",
    marginBottom: 4,
  },
  title: { fontSize: 15, fontFamily: "Poppins_600SemiBold", color: "#111827" },
  mutedText: {
    fontSize: 13,
    fontFamily: "Poppins_400Regular",
    color: "#6b7280",
    textAlign: "center",
    lineHeight: 19,
  },
  retryBtn: {
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    backgroundColor: "#2563eb",
    borderRadius: 12,
    paddingHorizontal: 18,
    paddingVertical: 11,
    marginTop: 8,
  },
  retryText: { color: "#fff", fontSize: 14, fontFamily: "Poppins_600SemiBold" },
  paginator: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 14,
    paddingVertical: 18,
  },
  pageBtn: {
    width: 40,
    height: 40,
    borderRadius: 12,
    backgroundColor: "#fff",
    borderWidth: 1,
    borderColor: "#e5e7eb",
    justifyContent: "center",
    alignItems: "center",
  },
  pageBtnDisabled: { backgroundColor: "#f9fafb" },
  pageLabel: {
    fontSize: 13,
    fontFamily: "Poppins_500Medium",
    color: "#6b7280",
  },
});
