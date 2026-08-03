<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesJson;
use App\Http\Controllers\Controller;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Read-only system log feed — REST counterpart of
 * `App\Livewire\Logs\Index`. Newest entries first so the mobile screen
 * can poll and prepend.
 */
class LogController extends Controller
{
    use PaginatesJson;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'level' => ['nullable', 'string', Rule::in(array_keys(SystemLog::LEVELS))],
            'source' => ['nullable', 'string', Rule::in(SystemLog::SOURCES)],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => $this->perPageRules(),
        ]);

        $logs = SystemLog::query()
            ->when($validated['level'] ?? null, fn ($q, $level) => $q->where('level', $level))
            ->when($validated['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->when($validated['search'] ?? null, fn ($q, $search) => $q->where('message', 'like', "%{$search}%"))
            ->latest('logged_at')
            ->paginate($this->perPage($validated));

        return $this->paginated($logs, fn (SystemLog $log) => [
            'id' => $log->id,
            'level' => $log->level,
            'level_color' => $log->levelColor(),
            'source' => $log->source,
            'message' => $log->message,
            'context' => $log->context,
            'logged_at' => optional($log->logged_at)->toIso8601String(),
        ]);
    }

    /**
     * Filter options so the mobile screen does not hard-code them.
     */
    public function filters(): JsonResponse
    {
        return response()->json([
            'data' => [
                'levels' => collect(SystemLog::LEVELS)
                    ->map(fn (string $color, string $key) => ['key' => $key, 'color' => $color])
                    ->values()
                    ->all(),
                'sources' => SystemLog::SOURCES,
            ],
        ]);
    }
}
