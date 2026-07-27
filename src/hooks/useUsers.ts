import { queryKeys } from "@/lib/queryClient";
import {
  createUser,
  deleteUser,
  getRoles,
  getUsers,
  updateRoles,
  updateUser,
  type AccessLevel,
  type UserFilters,
  type UserInput,
} from "@/services/userApi";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

export function useUsers(filters: UserFilters = {}) {
  return useQuery({
    queryKey: queryKeys.users.list(filters),
    queryFn: () => getUsers(filters),
    placeholderData: (previous) => previous,
  });
}

export function useCreateUser() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (input: UserInput) => createUser(input),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: queryKeys.users.all });
    },
  });
}

export function useUpdateUser() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: UserInput }) =>
      updateUser(id, input),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: queryKeys.users.all });
    },
  });
}

export function useDeleteUser() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => deleteUser(id),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: queryKeys.users.all });
    },
  });
}

export function useRoles() {
  return useQuery({
    queryKey: queryKeys.roles.all,
    queryFn: getRoles,
    // Matriks hak akses nyaris tidak pernah berubah saat aplikasi dipakai.
    staleTime: 5 * 60_000,
  });
}

export function useUpdateRoles() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (
      permissions: { role: string; module: string; access: AccessLevel }[],
    ) => updateRoles(permissions),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: queryKeys.roles.all });
    },
  });
}
