import { queryKeys } from "@/lib/queryClient";
import {
  createProduct,
  deleteProduct,
  getProduct,
  getProducts,
  updateProduct,
  type ProductFilters,
  type ProductInput,
} from "@/services/productApi";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

export function useProducts(filters: ProductFilters = {}) {
  return useQuery({
    queryKey: queryKeys.products.list(filters),
    queryFn: () => getProducts(filters),
    // Daftar lama tetap ditampilkan saat filter berubah, jadi layar tidak
    // berkedip kosong tiap kali user mengetik di kolom cari.
    placeholderData: (previous) => previous,
  });
}

export function useProduct(id: number | null) {
  return useQuery({
    queryKey: queryKeys.products.detail(id ?? 0),
    queryFn: () => getProduct(id as number),
    enabled: id !== null,
  });
}

export function useCreateProduct() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (input: ProductInput) => createProduct(input),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: queryKeys.products.all });
    },
  });
}

export function useUpdateProduct() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: ProductInput }) =>
      updateProduct(id, input),
    onSuccess: (product) => {
      void client.invalidateQueries({ queryKey: queryKeys.products.all });
      client.setQueryData(queryKeys.products.detail(product.id), product);
    },
  });
}

export function useDeleteProduct() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => deleteProduct(id),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: queryKeys.products.all });
      // Menghapus produk mengubah jumlah produk per kategori.
      void client.invalidateQueries({ queryKey: queryKeys.categories.all });
    },
  });
}
