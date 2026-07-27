import { EmptyState, ErrorState, LoadingState, Paginator } from "@/components/QueryStates";
import {
  useResolveReturnBatch,
  useReturnBatch,
  useReturns,
} from "@/hooks/useReturns";
import { type ReturnStatus } from "@/services/returnApi";
import { Ionicons } from "@expo/vector-icons";
import { useState } from "react";
import {
  ActivityIndicator,
  Alert,
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from "react-native";

const STATUS_COLORS: Record<ReturnStatus, string> = {
  open: "#ca8a04",
  resolved: "#16a34a",
};

function formatDateTime(iso: string | null): string {
  if (!iso) return "-";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;
  return date.toLocaleString("id-ID", {
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  });
}

/**
 * Layar triase QC return: batch produk cacat yang dibuang otomatis dari line
 * oleh `QcWorkflow` di backend, untuk ditinjau lalu ditandai selesai.
 */
export default function ReturnsScreen() {
  const [status, setStatus] = useState<ReturnStatus | "">("open");
  const [page, setPage] = useState(1);
  const [viewingId, setViewingId] = useState<number | null>(null);
  const [notes, setNotes] = useState("");

  const query = useReturns({ status, page });
  const detail = useReturnBatch(viewingId);
  const resolveBatch = useResolveReturnBatch();

  const batches = query.data?.data ?? [];
  const meta = query.data?.meta;
  const batch = detail.data;

  const closeModal = () => {
    setViewingId(null);
    setNotes("");
  };

  const handleResolve = async () => {
    if (viewingId === null) return;

    try {
      await resolveBatch.mutateAsync({
        id: viewingId,
        notes: notes.trim() || undefined,
      });
      closeModal();
    } catch (error) {
      // 409 = batch sudah diselesaikan operator lain sementara modal terbuka.
      Alert.alert(
        "Gagal menyelesaikan",
        error instanceof Error ? error.message : "Coba lagi.",
      );
    }
  };

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <View style={styles.filters}>
        {(["open", "resolved", ""] as const).map((value) => (
          <Pressable
            key={value || "all"}
            style={[styles.filterChip, status === value && styles.filterChipActive]}
            onPress={() => {
              setStatus(value);
              setPage(1);
            }}
          >
            <Text
              style={[styles.filterText, status === value && styles.filterTextActive]}
            >
              {value === "open" ? "Terbuka" : value === "resolved" ? "Selesai" : "Semua"}
            </Text>
          </Pressable>
        ))}
        {query.isFetching && !query.isLoading ? (
          <ActivityIndicator size="small" color="#9ca3af" />
        ) : null}
      </View>

      {query.isLoading ? (
        <LoadingState label="Memuat return batch..." />
      ) : query.isError ? (
        <ErrorState error={query.error} onRetry={() => void query.refetch()} />
      ) : batches.length === 0 ? (
        <EmptyState
          icon="checkmark-done-outline"
          title="Tidak ada return batch"
          hint={
            status === "open"
              ? "Semua batch sudah ditangani. Bagus!"
              : undefined
          }
        />
      ) : (
        <>
          {batches.map((item) => (
            <Pressable
              key={item.id}
              style={styles.card}
              onPress={() => setViewingId(item.id)}
            >
              <View
                style={[
                  styles.iconWrap,
                  { backgroundColor: STATUS_COLORS[item.status] + "20" },
                ]}
              >
                <Ionicons
                  name="return-down-back-outline"
                  size={22}
                  color={STATUS_COLORS[item.status]}
                />
              </View>

              <View style={{ flex: 1 }}>
                <Text style={styles.cardTitle}>
                  Batch #{item.id} · {item.conveyor ?? "Tanpa conveyor"}
                </Text>
                <Text style={styles.cardMeta}>
                  {item.detections_count ?? 0} deteksi ·{" "}
                  {formatDateTime(item.created_at)}
                </Text>
                {item.reason ? (
                  <Text style={styles.cardReason} numberOfLines={1}>
                    {item.reason}
                  </Text>
                ) : null}
              </View>

              <View
                style={[
                  styles.badge,
                  { backgroundColor: STATUS_COLORS[item.status] + "20" },
                ]}
              >
                <Text style={[styles.badgeText, { color: STATUS_COLORS[item.status] }]}>
                  {item.status_label}
                </Text>
              </View>
            </Pressable>
          ))}

          {meta ? (
            <Paginator
              page={meta.current_page}
              lastPage={meta.last_page}
              total={meta.total}
              onChange={setPage}
            />
          ) : null}
        </>
      )}

      <Modal visible={viewingId !== null} transparent animationType="slide">
        <View style={styles.modalOverlay}>
          <View style={styles.modalContent}>
            {detail.isLoading ? (
              <LoadingState label="Memuat detail..." />
            ) : detail.isError ? (
              <ErrorState error={detail.error} onRetry={() => void detail.refetch()} />
            ) : batch ? (
              <>
                <View style={styles.modalHeader}>
                  <Text style={styles.modalTitle}>Batch #{batch.id}</Text>
                  <Pressable onPress={closeModal} hitSlop={12}>
                    <Ionicons name="close" size={24} color="#6b7280" />
                  </Pressable>
                </View>

                <Text style={styles.modalMeta}>
                  {batch.conveyor ?? "Tanpa conveyor"} · {batch.status_label}
                  {batch.resolved_by ? ` · oleh ${batch.resolved_by.name}` : ""}
                </Text>

                <ScrollView style={styles.detectionList}>
                  {batch.detections.length === 0 ? (
                    <Text style={styles.emptyDetections}>
                      Batch ini belum punya deteksi.
                    </Text>
                  ) : (
                    batch.detections.map((detection) => (
                      <View key={detection.id} style={styles.detectionRow}>
                        <View style={{ flex: 1 }}>
                          <Text style={styles.detectionTitle}>
                            {detection.product?.name ?? detection.code ?? "Tanpa nama"}
                          </Text>
                          <Text style={styles.detectionMeta}>
                            {detection.status_label} ·{" "}
                            {detection.camera ?? "kamera ?"} ·{" "}
                            {formatDateTime(detection.detected_at)}
                          </Text>
                        </View>
                        {detection.confidence !== null ? (
                          <Text style={styles.confidence}>
                            {Math.round(Number(detection.confidence) * 100)}%
                          </Text>
                        ) : null}
                      </View>
                    ))
                  )}
                </ScrollView>

                {batch.status === "open" ? (
                  <>
                    <TextInput
                      style={styles.notesInput}
                      placeholder="Catatan penyelesaian (opsional)"
                      placeholderTextColor="#9ca3af"
                      value={notes}
                      onChangeText={setNotes}
                      multiline
                    />
                    <Pressable
                      style={[
                        styles.resolveBtn,
                        resolveBatch.isPending && styles.resolveBtnDisabled,
                      ]}
                      onPress={handleResolve}
                      disabled={resolveBatch.isPending}
                    >
                      {resolveBatch.isPending ? (
                        <ActivityIndicator color="#fff" />
                      ) : (
                        <>
                          <Ionicons name="checkmark" size={18} color="#fff" />
                          <Text style={styles.resolveText}>Tandai Selesai</Text>
                        </>
                      )}
                    </Pressable>
                  </>
                ) : (
                  <View style={styles.resolvedBox}>
                    <Ionicons name="checkmark-circle" size={18} color="#16a34a" />
                    <Text style={styles.resolvedText}>
                      Selesai {formatDateTime(batch.resolved_at)}
                      {batch.notes ? ` — ${batch.notes}` : ""}
                    </Text>
                  </View>
                )}
              </>
            ) : null}
          </View>
        </View>
      </Modal>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  content: { padding: 16, paddingBottom: 32 },
  filters: {
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    marginBottom: 16,
  },
  filterChip: {
    paddingHorizontal: 16,
    paddingVertical: 8,
    borderRadius: 999,
    backgroundColor: "#fff",
    borderWidth: 1,
    borderColor: "#e5e7eb",
  },
  filterChipActive: { backgroundColor: "#2563eb", borderColor: "#2563eb" },
  filterText: { fontSize: 13, fontFamily: "Poppins_500Medium", color: "#6b7280" },
  filterTextActive: { color: "#fff" },
  card: {
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
    backgroundColor: "#fff",
    borderRadius: 16,
    padding: 14,
    marginBottom: 10,
    shadowColor: "#000",
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.05,
    shadowRadius: 4,
    elevation: 1,
  },
  iconWrap: {
    width: 42,
    height: 42,
    borderRadius: 13,
    justifyContent: "center",
    alignItems: "center",
  },
  cardTitle: { fontSize: 14, fontFamily: "Poppins_600SemiBold", color: "#111827" },
  cardMeta: { fontSize: 12, fontFamily: "Poppins_400Regular", color: "#6b7280" },
  cardReason: { fontSize: 12, fontFamily: "Poppins_400Regular", color: "#9ca3af" },
  badge: { paddingHorizontal: 10, paddingVertical: 5, borderRadius: 999 },
  badgeText: { fontSize: 11, fontFamily: "Poppins_600SemiBold" },
  modalOverlay: { flex: 1, justifyContent: "flex-end", backgroundColor: "rgba(0,0,0,0.4)" },
  modalContent: {
    backgroundColor: "#fff",
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    padding: 24,
    gap: 12,
    maxHeight: "85%",
  },
  modalHeader: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
  },
  modalTitle: { fontSize: 18, fontFamily: "Poppins_700Bold", color: "#111827" },
  modalMeta: { fontSize: 13, fontFamily: "Poppins_400Regular", color: "#6b7280" },
  detectionList: { maxHeight: 280 },
  emptyDetections: {
    fontSize: 13,
    fontFamily: "Poppins_400Regular",
    color: "#9ca3af",
    paddingVertical: 16,
    textAlign: "center",
  },
  detectionRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
    paddingVertical: 10,
    borderBottomWidth: 1,
    borderBottomColor: "#f3f4f6",
  },
  detectionTitle: { fontSize: 13, fontFamily: "Poppins_500Medium", color: "#111827" },
  detectionMeta: { fontSize: 11, fontFamily: "Poppins_400Regular", color: "#6b7280" },
  confidence: { fontSize: 13, fontFamily: "Poppins_600SemiBold", color: "#2563eb" },
  notesInput: {
    backgroundColor: "#f9fafb",
    borderWidth: 1,
    borderColor: "#e5e7eb",
    borderRadius: 12,
    paddingHorizontal: 14,
    paddingVertical: 12,
    minHeight: 60,
    textAlignVertical: "top",
    fontSize: 14,
    fontFamily: "Poppins_400Regular",
    color: "#111827",
  },
  resolveBtn: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 8,
    backgroundColor: "#16a34a",
    borderRadius: 14,
    paddingVertical: 15,
  },
  resolveBtnDisabled: { opacity: 0.7 },
  resolveText: { fontSize: 15, fontFamily: "Poppins_600SemiBold", color: "#fff" },
  resolvedBox: {
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    backgroundColor: "#dcfce7",
    borderRadius: 12,
    paddingHorizontal: 14,
    paddingVertical: 12,
  },
  resolvedText: {
    flex: 1,
    fontSize: 13,
    fontFamily: "Poppins_500Medium",
    color: "#16a34a",
  },
});
