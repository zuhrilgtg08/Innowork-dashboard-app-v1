<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesJson;
use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Category CRUD for the mobile app — REST counterpart of
 * `App\Livewire\Categories\Index`. The slug is always derived from the
 * name, matching the web screen.
 */
class CategoryController extends Controller
{
    use PaginatesJson;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => $this->perPageRules(),
        ]);

        $categories = Category::query()
            ->when($validated['search'] ?? null, fn ($q, $search) => $q->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")))
            ->when(
                array_key_exists('is_active', $validated) && $validated['is_active'] !== null,
                fn ($q) => $q->where('is_active', $validated['is_active'])
            )
            ->withCount('products')
            ->orderBy('sort_order')
            ->latest()
            ->paginate($this->perPage($validated));

        return $this->paginated($categories, fn (Category $c) => $this->payload($c));
    }

    public function show(Category $category): JsonResponse
    {
        return response()->json(['data' => $this->payload($category->loadCount('products'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(null));

        $category = new Category;
        $this->fillFrom($category, $data, $request);
        $category->save();

        return response()->json([
            'message' => 'Kategori baru berhasil ditambahkan.',
            'data' => $this->payload($category->loadCount('products')),
        ], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate($this->rules($category->id));

        $this->fillFrom($category, $data, $request);
        $category->save();

        return response()->json([
            'message' => 'Kategori berhasil diperbarui.',
            'data' => $this->payload($category->loadCount('products')),
        ]);
    }

    public function destroy(Category $category): JsonResponse
    {
        if ($category->image) {
            Storage::disk('public')->delete($category->image);
        }

        $category->delete();

        return response()->json(['message' => 'Kategori berhasil dihapus.']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fillFrom(Category $category, array $data, Request $request): void
    {
        $category->fill([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
            'description' => ($data['description'] ?? '') ?: null,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'],
        ]);

        if ($request->hasFile('image')) {
            if ($category->image) {
                Storage::disk('public')->delete($category->image);
            }
            $category->image = $request->file('image')->store('categories', 'public');
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?int $ignoreId): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('categories', 'name')->ignore($ignoreId)],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'max:2048'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'is_active' => (bool) $category->is_active,
            'sort_order' => (int) $category->sort_order,
            'image_url' => $category->imageUrl(),
            'products_count' => $category->products_count ?? null,
            'created_at' => optional($category->created_at)->toIso8601String(),
            'updated_at' => optional($category->updated_at)->toIso8601String(),
        ];
    }
}
