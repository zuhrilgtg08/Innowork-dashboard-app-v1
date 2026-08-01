import { apiRequest } from "@/services/api";
import {
  appendField,
  appendFile,
  buildQuery,
  type Envelope,
  type PageParams,
  type Paginated,
  type UploadFile,
} from "@/services/types";

/** Status produk — samakan dengan `Product::STATUSES` di backend. */
export const PRODUCT_STATUSES = ["active", "inactive", "archived"] as const;

export type ProductStatus = (typeof PRODUCT_STATUSES)[number];

export type Product = {
  id: number;
  code: string;
  name: string;
  sku: string | null;
  status: ProductStatus;
  status_label: string;
  stock: number;
  description: string | null;
  category_id: number | null;
  category: { id: number; name: string } | null;
  image_url: string | null;
  qr_url: string | null;
  public_url: string;
  detections_count: number | null;
  created_at: string | null;
  updated_at: string | null;
};

export type ProductFilters = PageParams & {
  search?: string;
  status?: ProductStatus | "";
  category_id?: number | null;
};

/**
 * Field yang bisa dikirim saat create/update. `code` dan `sku` sengaja tidak
 * ada: keduanya digenerate backend saat create dan tidak pernah diterbitkan
 * ulang, supaya QR yang sudah tercetak tetap bisa di-resolve.
 */
export type ProductInput = {
  name: string;
  status: ProductStatus;
  stock: number;
  category_id?: number | null;
  description?: string | null;
  image?: UploadFile | null;
};

export async function getProducts(
  filters: ProductFilters = {},
): Promise<Paginated<Product>> {
  return apiRequest<Paginated<Product>>(
    `/products${buildQuery({
      search: filters.search,
      status: filters.status,
      category_id: filters.category_id,
      page: filters.page,
      per_page: filters.per_page,
    })}`,
  );
}

export async function getProduct(id: number): Promise<Product> {
  const response = await apiRequest<Envelope<Product>>(`/products/${id}`);
  return response.data;
}

function toFormData(input: ProductInput): FormData {
  const form = new FormData();
  appendField(form, "name", input.name);
  appendField(form, "status", input.status);
  appendField(form, "stock", input.stock);
  appendField(form, "category_id", input.category_id);
  appendField(form, "description", input.description);
  appendFile(form, "image", input.image);
  return form;
}

export async function createProduct(input: ProductInput): Promise<Product> {
  const response = await apiRequest<Envelope<Product>>("/products", {
    method: "POST",
    body: toFormData(input),
  });
  return response.data;
}

export async function updateProduct(
  id: number,
  input: ProductInput,
): Promise<Product> {
  const form = toFormData(input);
  // PHP tidak mem-parse multipart pada PUT; kirim sebagai POST + _method
  // (method spoofing bawaan Laravel) supaya file ikut terbaca.
  form.append("_method", "PUT");

  const response = await apiRequest<Envelope<Product>>(`/products/${id}`, {
    method: "POST",
    body: form,
  });
  return response.data;
}

export async function deleteProduct(id: number): Promise<void> {
  await apiRequest(`/products/${id}`, { method: "DELETE" });
}
