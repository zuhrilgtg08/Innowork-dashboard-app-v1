<?php

namespace App\Livewire\Products;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Product'])]
class Index extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $categoryFilter = '';

    /** Modal state. */
    public bool $showModal = false;

    public ?int $editingId = null;

    /** Form fields. */
    public string $code = '';

    public string $name = '';

    public ?int $category_id = null;

    public string $sku = '';

    public string $productStatus = 'active';

    public int $stock = 0;

    public string $description = '';

    /** New upload + existing stored path. */
    public $photo = null;

    public ?string $existingImage = null;

    /** Flash + delete confirmation. */
    public string $flash = '';

    public ?int $confirmingDeleteId = null;

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'categoryFilter'])) {
            $this->resetPage();
        }
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'productStatus' => ['required', Rule::in(array_keys(Product::STATUSES))],
            'stock' => ['required', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'photo' => ['nullable', 'image', 'max:2048'],
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    protected function generateCode(): string
    {
        return 'PRD-'.str_pad((string) ((Product::max('id') ?? 0) + 1), 5, '0', STR_PAD_LEFT);
    }

    protected function generateSku(string $name): string
    {
        $words = preg_split('/[\s-]+/', $name);
        $abbr = '';
        foreach ($words as $w) {
            if (!empty($w)) $abbr .= strtoupper($w[0]);
        }
        return $abbr.'-'.fake()->unique()->numerify('###');
    }

    public function edit(int $id): void
    {
        $product = Product::findOrFail($id);

        $this->editingId = $product->id;
        $this->code = $product->code;
        $this->name = $product->name;
        $this->category_id = $product->category_id;
        $this->sku = (string) $product->sku;
        $this->productStatus = $product->status;
        $this->stock = (int) $product->stock;
        $this->description = (string) $product->description;
        $this->existingImage = $product->image;
        $this->photo = null;
        $this->resetValidation();
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate();

        $product = $this->editingId ? Product::findOrFail($this->editingId) : new Product;

        $product->fill([
            'code' => $this->editingId ? $product->code : $this->generateCode(),
            'name' => $data['name'],
            'category_id' => $data['category_id'] ?? null,
            'sku' => $this->editingId ? $product->sku : $this->generateSku($data['name']),
            'status' => $data['productStatus'],
            'stock' => $data['stock'],
            'description' => $data['description'] ?: null,
        ]);

        // Store the milk product photo on the public disk.
        if ($this->photo) {
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            $product->image = $this->photo->store('products', 'public');
        }

        $product->save();

        // (Re)generate the product QR code as an SVG whenever the code/sku changes.
        $product->regenerateQr();

        $this->flash = $this->editingId ? 'Produk berhasil diperbarui.' : 'Produk baru berhasil ditambahkan.';
        $this->closeModal();
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmingDeleteId = $id;
    }

    public function delete(int $id): void
    {
        $product = Product::findOrFail($id);

        if ($product->image) {
            Storage::disk('public')->delete($product->image);
        }
        if ($product->qr_path) {
            Storage::disk('public')->delete($product->qr_path);
        }

        $product->delete();
        $this->flash = 'Produk berhasil dihapus.';
        $this->confirmingDeleteId = null;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'category_id',
            'stock', 'description', 'photo', 'existingImage',
        ]);
        $this->productStatus = 'active';
        $this->stock = 0;
        $this->resetValidation();
    }

    public function render()
    {
        $categories = Category::where('is_active', true)->orderBy('name')->get();

        $products = Product::query()
            ->when($this->search, fn ($q) => $q->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('code', 'like', "%{$this->search}%")
                ->orWhere('sku', 'like', "%{$this->search}%")))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->categoryFilter, fn ($q) => $q->where('category_id', $this->categoryFilter))
            ->withCount('detections')
            ->with('category')
            ->latest()
            ->paginate(12);

        return view('livewire.products.index', [
            'products' => $products,
            'total' => Product::count(),
            'statuses' => Product::STATUSES,
            'categories' => $categories,
        ]);
    }
}