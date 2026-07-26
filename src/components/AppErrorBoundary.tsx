import { Ionicons } from "@expo/vector-icons";
import type { ErrorBoundaryProps } from "expo-router";
import { Pressable, ScrollView, StyleSheet, Text, View } from "react-native";

/**
 * Layar fallback saat ada render error yang tidak tertangani.
 *
 * Diekspor ulang sebagai `ErrorBoundary` dari `src/app/_layout.tsx` — Expo
 * Router otomatis memakai export bernama `ErrorBoundary` pada sebuah route
 * untuk membungkus segmen tersebut. Karena dipasang di root layout, satu error
 * di layar mana pun tidak lagi meng-unmount seluruh aplikasi: user melihat
 * pesan ini dan bisa mencoba lagi lewat `retry()`.
 */
export default function AppErrorBoundary({ error, retry }: ErrorBoundaryProps) {
  return (
    <View style={styles.flex}>
      <ScrollView contentContainerStyle={styles.container}>
        <View style={styles.icon}>
          <Ionicons name="warning-outline" size={36} color="#dc2626" />
        </View>

        <Text style={styles.title}>Terjadi kesalahan</Text>
        <Text style={styles.subtitle}>
          Aplikasi mengalami error yang tidak terduga. Coba muat ulang layar
          ini. Jika terus berulang, laporkan pesan di bawah ke tim teknis.
        </Text>

        <View style={styles.detailBox}>
          <Text style={styles.detailText} selectable>
            {error?.message || "Unknown error"}
          </Text>
        </View>

        <Pressable style={styles.btn} onPress={() => void retry()}>
          <Ionicons name="refresh-outline" size={18} color="#fff" />
          <Text style={styles.btnText}>Coba Lagi</Text>
        </Pressable>
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1, backgroundColor: "#f3f4f6" },
  container: {
    flexGrow: 1,
    justifyContent: "center",
    padding: 24,
    alignItems: "center",
  },
  icon: {
    width: 72,
    height: 72,
    borderRadius: 22,
    backgroundColor: "#fce7e7",
    justifyContent: "center",
    alignItems: "center",
    marginBottom: 20,
  },
  title: { fontSize: 20, fontWeight: "700", color: "#111827" },
  subtitle: {
    fontSize: 14,
    color: "#6b7280",
    textAlign: "center",
    marginTop: 8,
    lineHeight: 20,
  },
  detailBox: {
    alignSelf: "stretch",
    backgroundColor: "#fff",
    borderRadius: 14,
    borderWidth: 1,
    borderColor: "#e5e7eb",
    padding: 16,
    marginTop: 20,
  },
  detailText: {
    fontSize: 12,
    color: "#374151",
    lineHeight: 18,
  },
  btn: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 8,
    alignSelf: "stretch",
    backgroundColor: "#2563eb",
    borderRadius: 14,
    paddingVertical: 16,
    marginTop: 24,
  },
  btnText: { color: "#fff", fontSize: 16, fontWeight: "600" },
});
