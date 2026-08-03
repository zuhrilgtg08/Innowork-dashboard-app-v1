<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Shared `{ data, meta }` envelope for paginated mobile API responses.
 *
 * The shape mirrors what `App\Http\Controllers\Api\DetectionController`
 * already returns, so the mobile client can normalise every list the same way.
 */
trait PaginatesJson
{
    /** Default page size when the client does not ask for one. */
    private const DEFAULT_PER_PAGE = 20;

    /** Upper bound so a client cannot ask for the whole table at once. */
    private const MAX_PER_PAGE = 100;

    /**
     * Wrap a paginator, mapping each model through $callback.
     *
     * @param  callable(mixed): array<string, mixed>  $callback
     */
    protected function paginated(LengthAwarePaginator $paginator, callable $callback): JsonResponse
    {
        return response()->json([
            'data' => $paginator->getCollection()->map($callback)->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Validation rules for the shared `per_page` query parameter.
     *
     * @return array<int, mixed>
     */
    protected function perPageRules(): array
    {
        return ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function perPage(array $validated): int
    {
        return (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE);
    }
}
