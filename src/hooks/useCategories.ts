import { queryKeys } from "@/lib/queryClient";
import {
  createCategory,
  deleteCategory,
  getCategories,
  updateCategory,
  type CategoryFilters,
  type CategoryInput,
} from "@/services/categoryApi";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

export function useCategories(filters: CategoryFilters = {}) {
  return useQuery({
    queryKey: queryKeys.categories.list(filters),
    queryFn: () => getCategories(filters),
    placeholderData: (previous) => previous,
  });
}

export function useCreateCategory() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (input: CategoryInput) => createCategory(input),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: queryKeys.categories.all });
    },
  });
}

export function useUpdateCategory() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: CategoryInput }) =>
      updateCategory(id, input),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: queryKeys.categories.all });
      // Nama kategori ikut tampil di kartu produk.
      void client.invalidateQueries({ queryKey: queryKeys.products.all });
    },
  });
}

export function useDeleteCategory() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => deleteCategory(id),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: queryKeys.categories.all });
      void client.invalidateQueries({ queryKey: queryKeys.products.all });
    },
  });
}
