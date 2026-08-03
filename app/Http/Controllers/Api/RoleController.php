<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The role × module permission matrix — REST counterpart of
 * `App\Livewire\Roles\Index`.
 *
 * Unlike the web dashboard, this matrix is actually enforced on the mobile API
 * by `App\Http\Middleware\EnsureModuleAccess`, so editing it here changes
 * what mobile clients can do.
 */
class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'roles' => collect(User::ROLES)
                    ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                    ->values()
                    ->all(),
                'modules' => RolePermission::MODULES,
                'access_levels' => collect(RolePermission::ACCESS)
                    ->map(fn (array $meta, string $key) => [
                        'key' => $key,
                        'label' => $meta['label'],
                        'color' => $meta['color'],
                    ])
                    ->values()
                    ->all(),
                'matrix' => RolePermission::matrix(),
            ],
        ]);
    }

    /**
     * Persist one or more role/module access changes.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*.role' => ['required', 'string', Rule::in(array_keys(User::ROLES))],
            'permissions.*.module' => ['required', 'string', Rule::in(RolePermission::MODULES)],
            'permissions.*.access' => ['required', 'string', Rule::in(array_keys(RolePermission::ACCESS))],
        ]);

        foreach ($data['permissions'] as $permission) {
            RolePermission::updateOrCreate(
                ['role' => $permission['role'], 'module' => $permission['module']],
                ['access' => $permission['access']],
            );
        }

        return response()->json([
            'message' => 'Hak akses berhasil diperbarui.',
            'data' => ['matrix' => RolePermission::matrix()],
        ]);
    }
}
