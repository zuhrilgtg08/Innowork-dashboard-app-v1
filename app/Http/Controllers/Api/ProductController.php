<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesJson;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Product CRUD for the mobile app — the REST counterpart of
 * `App\Livewire\Products\Index`.
 *
 * Business rules are kept identical to the web screen: `code` and `sku` are
 * generated on create and never rewritten on update, and the QR SVG is
 * regenerated on every save.
 */
class ProductController extends Controller
{
    use PaginatesJson;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(array_keys(Product::STATUSES))],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'per_page' => $this->perPageRules(),
        ]);

        $products = Product::query()
            ->when($validated['search'] ?? null, fn ($q, $search) => $q->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->withCount('detections')
            ->with('category')
            ->latest()
            ->paginate($this->perPage($validated));

        return $this->paginated($products, fn (Product $p) => $this->payload($p));
    }

    public function show(Product $product): JsonResponse
    {
        $product->loadCount('detections')->load('category');

        return response()->json(['data' => $this->payload($product)]);
    }

    /**
     * Resolve a scanned QR code to its product (Fase 5, mobile QR scanner).
     *
     * The decoded value is whatever the camera read — normally the public scan
     * URL the QR encodes (`/p/{qr_token}`), but {@see Product::resolveByQrValue()}
     * also accepts a bare token and the legacy `SORTVISION|{code}|{sku}` payload,
     * so codes printed before the URL switch still scan. Parsing lives on the
     * model because the Livewire screens resolve scans the same way.
     *
     * Returns the product together with its latest QC verdict — that pairing is
     * the whole point of scanning an item on the line.
     */
    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_value' => ['required', 'string', 'max:2048'],
        ]);

        $product = Product::resolveByQrValue($validated['qr_value']);

        if (! $product) {
            return response()->json([
                'message' => 'QR tidak dikenali. Produk tidak ditemukan.',
            ], 404);
        }

        $product->loadCount('detections')->load(['category', 'latestDetection']);

        $detection = $product->latestDetection;

        return response()->json([
            'data' => [
                'product' => $this->payload($product),
                'latest_detection' => $detection ? [
                    'id' => $detection->id,
                    'code' => $detection->code,
                    'status' => $detection->status,
                    'status_label' => $detection->statusLabel(),
                    'status_color' => $detection->statusColor(),
                    'camera' => $detection->camera,
                    'conveyor' => $detection->conveyor,
                    'confidence' => $detection->confidence,
                    'detected_at' => optional($detection->detected_at)->toIso8601String(),
                ] : null,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $product = new Product;
        $product->fill([
            'code' => Product::generateCode(),
            'sku' => Product::generateSku($data['name']),
            'name' => $data['name'],
            'category_id' => $data['category_id'] ?? null,
            'status' => $data['status'],
            'stock' => $data['stock'],
            'description' => ($data['description'] ?? '') ?: null,
        ]);

        if ($request->hasFile('image')) {
            $product->image = $request->file('image')->store('products', 'public');
        }

        $product->save();
        $product->regenerateQr();

        return response()->json([
            'message' => 'Produk baru berhasil ditambahkan.',
            'data' => $this->payload($product->loadCount('detections')->load('category')),
        ], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate($this->rules());

        // `code` and `sku` are identifiers minted at creation — never reissued,
        // otherwise printed QR labels already on the line would stop resolving.
        $product->fill([
            'name' => $data['name'],
            'category_id' => $data['category_id'] ?? null,
            'status' => $data['status'],
            'stock' => $data['stock'],
            'description' => ($data['description'] ?? '') ?: null,
        ]);

        if ($request->hasFile('image')) {
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            $product->image = $request->file('image')->store('products', 'public');
        }

        $product->save();
        $product->regenerateQr();

        return response()->json([
            'message' => 'Produk berhasil diperbarui.',
            'data' => $this->payload($product->loadCount('detections')->load('category')),
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        if ($product->image) {
            Storage::disk('public')->delete($product->image);
        }
        if ($product->qr_path) {
            Storage::disk('public')->delete($product->qr_path);
        }

        $product->delete();

        return response()->json(['message' => 'Produk berhasil dihapus.']);
    }

    /**
     * Same rules as the Products Livewire screen. `image` is a multipart file
     * upload here rather than a Livewire temporary upload.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'status' => ['required', Rule::in(array_keys(Product::STATUSES))],
            'stock' => ['required', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'max:2048'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Product $product): array
    {
        return [
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->name,
            'sku' => $product->sku,
            'status' => $product->status,
            'status_label' => Product::STATUSES[$product->status]['label'] ?? $product->status,
            'stock' => (int) $product->stock,
            'description' => $product->description,
            'category_id' => $product->category_id,
            'category' => $product->relationLoaded('category') && $product->category
                ? ['id' => $product->category->id, 'name' => $product->category->name]
                : null,
            'image_url' => $product->imageUrl(),
            'qr_url' => $product->qr_path ? Storage::url($product->qr_path) : null,
            'public_url' => $product->qrPayload(),
            'detections_count' => $product->detections_count ?? null,
            'created_at' => optional($product->created_at)->toIso8601String(),
            'updated_at' => optional($product->updated_at)->toIso8601String(),
        ];
    }
}
