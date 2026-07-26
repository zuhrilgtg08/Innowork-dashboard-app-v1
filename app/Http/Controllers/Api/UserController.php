<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesJson;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * User CRUD for the mobile app — REST counterpart of
 * `App\Livewire\Users\Index`.
 *
 * The web screen's safety rails are enforced here too, because they protect the
 * data rather than the UI: the last remaining administrator cannot be demoted,
 * deactivated or deleted, and nobody can delete their own account.
 */
class UserController extends Controller
{
    use PaginatesJson;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', Rule::in(array_keys(User::ROLES))],
            'per_page' => $this->perPageRules(),
        ]);

        $users = User::query()
            ->when($validated['search'] ?? null, fn ($q, $search) => $q->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($validated['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->latest()
            ->paginate($this->perPage($validated));

        return $this->paginated($users, fn (User $u) => $this->payload($u));
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => $this->payload($user)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(null));

        $user = new User;
        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'title' => ($data['title'] ?? '') ?: null,
            'is_active' => $data['is_active'] ?? true,
        ]);
        $user->password = $data['password']; // "hashed" cast bcrypts it

        if ($request->hasFile('avatar')) {
            $user->avatar = $request->file('avatar')->store('avatars', 'public');
        }

        $user->save();

        return response()->json([
            'message' => 'User baru berhasil ditambahkan.',
            'data' => $this->payload($user),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate($this->rules($user->id));

        if ($this->isLastAdmin($user)) {
            if ($data['role'] !== 'admin') {
                return response()->json([
                    'message' => 'Administrator tunggal tidak dapat diturunkan role-nya.',
                    'errors' => ['role' => ['Administrator tunggal tidak dapat diturunkan role-nya.']],
                ], 422);
            }

            if (array_key_exists('is_active', $data) && $data['is_active'] === false) {
                return response()->json([
                    'message' => 'Administrator tunggal tidak dapat dinonaktifkan.',
                    'errors' => ['is_active' => ['Administrator tunggal tidak dapat dinonaktifkan.']],
                ], 422);
            }
        }

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'title' => ($data['title'] ?? '') ?: null,
            'is_active' => $data['is_active'] ?? $user->is_active,
        ]);

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }
            $user->avatar = $request->file('avatar')->store('avatars', 'public');
        }

        $user->save();

        return response()->json([
            'message' => 'User berhasil diperbarui.',
            'data' => $this->payload($user),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($this->isLastAdmin($user)) {
            return response()->json([
                'message' => 'Administrator tunggal tidak dapat dihapus.',
            ], 422);
        }

        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'Anda tidak dapat menghapus akun sendiri.',
            ], 422);
        }

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $user->delete();

        return response()->json(['message' => 'User berhasil dihapus.']);
    }

    /** True when this user is an admin and the only one left. */
    private function isLastAdmin(User $user): bool
    {
        return $user->role === 'admin' && User::where('role', 'admin')->count() <= 1;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?int $ignoreId): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignoreId)],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'title' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            // Required when creating, optional (leave unchanged) when editing.
            'password' => [$ignoreId ? 'nullable' : 'required', 'string', 'min:8'],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'role_label' => $user->roleLabel(),
            'title' => $user->title,
            'is_active' => (bool) $user->is_active,
            'initials' => $user->initials(),
            'avatar_url' => $user->avatarUrl(),
            'created_at' => optional($user->created_at)->toIso8601String(),
            'updated_at' => optional($user->updated_at)->toIso8601String(),
        ];
    }
}
