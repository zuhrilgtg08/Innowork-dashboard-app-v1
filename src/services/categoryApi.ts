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

export type Category = {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  is_active: boolean;
  sort_order: number;
  image_url: string | null;
  products_count: number | null;
  created_at: string | null;
  updated_at: string | null;
};

export type CategoryFilters = PageParams & {
  search?: string;
  /** `undefined` = semua, `true`/`false` = filter aktif/nonaktif. */
  is_active?: boolean;
};

export type CategoryInput = {
  name: string;
  sort_order: number;
  description?: string | null;
  is_active?: boolean;
  image?: UploadFile | null;
};

export async function getCategories(
  filters: CategoryFilters = {},
): Promise<Paginated<Category>> {
  return apiRequest<Paginated<Category>>(
    `/categories${buildQuery({
      search: filters.search,
      is_active:
        filters.is_active === undefined ? undefined : filters.is_active ? 1 : 0,
      page: filters.page,
      per_page: filters.per_page,
    })}`,
  );
}

export async function getCategory(id: number): Promise<Category> {
  const response = await apiRequest<Envelope<Category>>(`/categories/${id}`);
  return response.data;
}

function toFormData(input: CategoryInput): FormData {
  const form = new FormData();
  appendField(form, "name", input.name);
  appendField(form, "sort_order", input.sort_order);
  appendField(form, "description", input.description);
  appendField(form, "is_active", input.is_active ?? true);
  appendFile(form, "image", input.image);
  return form;
}

export async function createCategory(
  input: CategoryInput,
): Promise<Category> {
  const response = await apiRequest<Envelope<Category>>("/categories", {
    method: "POST",
    body: toFormData(input),
  });
  return response.data;
}

export async function updateCategory(
  id: number,
  input: CategoryInput,
): Promise<Category> {
  const form = toFormData(input);
  form.append("_method", "PUT");

  const response = await apiRequest<Envelope<Category>>(`/categories/${id}`, {
    method: "POST",
    body: form,
  });
  return response.data;
}

export async function deleteCategory(id: number): Promise<void> {
  await apiRequest(`/categories/${id}`, { method: "DELETE" });
}
