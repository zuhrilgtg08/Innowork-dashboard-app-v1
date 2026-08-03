<?php

namespace App\Http\Middleware;

use App\Models\RolePermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a mobile API route behind the role × module matrix
 * (`RolePermission::matrix()`).
 *
 * Usage: `->middleware('module:Product,read')` / `'module:Product,write'`.
 *
 * NOTE — this is a deliberate divergence from the web dashboard. There the
 * matrix is editable but NOT enforced: Livewire routes are gated only by the
 * `auth` middleware. That is tolerable for a browser UI where the sidebar hides
 * what a role cannot use, but the mobile API exposes raw CRUD (including
 * DELETE) to anyone holding a token, so it needs a real check.
 *
 * We read `RolePermission::matrix()` directly rather than calling
 * `User::canAccess()`, because that helper hard-codes a whitelist of
 * admin/supervisor_qc/operator and therefore denies the `viewer` role every
 * module — contradicting the matrix, which grants viewers read on Dashboard,
 * Product, Live Camera, Returns and Logs. The matrix is the documented source
 * of truth, so the API follows it.
 */
class EnsureModuleAccess
{
    /** Access levels that satisfy a read requirement. */
    private const READ_LEVELS = ['r', 'w', 'f'];

    /** Access levels that satisfy a write requirement. */
    private const WRITE_LEVELS = ['w', 'f'];

    public function handle(Request $request, Closure $next, string $module, string $level = 'read'): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // A deactivated account keeps its token but loses access. `null` means
        // "never set" (legacy rows) and is treated as active, so only an
        // explicit false locks the user out.
        if (($user->is_active ?? true) === false) {
            return response()->json([
                'message' => 'Akun Anda dinonaktifkan.',
            ], 403);
        }

        $granted = RolePermission::matrix()[$user->role][$module] ?? '-';
        $allowed = $level === 'write' ? self::WRITE_LEVELS : self::READ_LEVELS;

        if (! in_array($granted, $allowed, true)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk tindakan ini.',
                'module' => $module,
                'required' => $level,
            ], 403);
        }

        return $next($request);
    }
}
