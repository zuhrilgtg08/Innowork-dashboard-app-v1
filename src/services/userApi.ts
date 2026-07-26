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

/** Kunci role — samakan dengan `User::ROLES` di backend. */
export const USER_ROLES = [
  "admin",
  "supervisor_qc",
  "operator",
  "viewer",
] as const;

export type UserRole = (typeof USER_ROLES)[number];

export type ManagedUser = {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  role_label: string;
  title: string | null;
  is_active: boolean;
  initials: string;
  avatar_url: string | null;
  created_at: string | null;
  updated_at: string | null;
};

export type UserFilters = PageParams & {
  search?: string;
  role?: UserRole | "";
};

export type UserInput = {
  name: string;
  email: string;
  role: UserRole;
  /** Wajib saat create; kosongkan saat edit untuk membiarkan password lama. */
  password?: string;
  title?: string | null;
  is_active?: boolean;
  avatar?: UploadFile | null;
};

export async function getUsers(
  filters: UserFilters = {},
): Promise<Paginated<ManagedUser>> {
  return apiRequest<Paginated<ManagedUser>>(
    `/users${buildQuery({
      search: filters.search,
      role: filters.role,
      page: filters.page,
      per_page: filters.per_page,
    })}`,
  );
}

export async function getUser(id: number): Promise<ManagedUser> {
  const response = await apiRequest<Envelope<ManagedUser>>(`/users/${id}`);
  return response.data;
}

function toFormData(input: UserInput): FormData {
  const form = new FormData();
  appendField(form, "name", input.name);
  appendField(form, "email", input.email);
  appendField(form, "role", input.role);
  appendField(form, "title", input.title);
  appendField(form, "is_active", input.is_active ?? true);
  // Password kosong tidak dikirim sama sekali — backend memperlakukannya
  // sebagai "biarkan password lama".
  if (input.password) {
    appendField(form, "password", input.password);
  }
  appendFile(form, "avatar", input.avatar);
  return form;
}

export async function createUser(input: UserInput): Promise<ManagedUser> {
  const response = await apiRequest<Envelope<ManagedUser>>("/users", {
    method: "POST",
    body: toFormData(input),
  });
  return response.data;
}

export async function updateUser(
  id: number,
  input: UserInput,
): Promise<ManagedUser> {
  const form = toFormData(input);
  form.append("_method", "PUT");

  const response = await apiRequest<Envelope<ManagedUser>>(`/users/${id}`, {
    method: "POST",
    body: form,
  });
  return response.data;
}

export async function deleteUser(id: number): Promise<void> {
  await apiRequest(`/users/${id}`, { method: "DELETE" });
}

/* ------------------------------------------------------------------ roles */

export type AccessLevel = "f" | "w" | "r" | "-";

export type RoleMatrix = Record<string, Record<string, AccessLevel>>;

export type RolesResponse = {
  roles: { key: string; label: string }[];
  modules: string[];
  access_levels: { key: AccessLevel; label: string; color: string }[];
  matrix: RoleMatrix;
};

export async function getRoles(): Promise<RolesResponse> {
  const response = await apiRequest<Envelope<RolesResponse>>("/roles");
  return response.data;
}

export async function updateRoles(
  permissions: { role: string; module: string; access: AccessLevel }[],
): Promise<RoleMatrix> {
  const response = await apiRequest<Envelope<{ matrix: RoleMatrix }>>(
    "/roles",
    { method: "PUT", body: { permissions } },
  );
  return response.data.matrix;
}
