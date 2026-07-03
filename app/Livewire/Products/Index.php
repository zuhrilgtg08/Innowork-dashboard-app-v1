<?php

namespace App\Livewire\Products;

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

    /** Modal state. */
    public bool $showModal = false;

    public ?int $editingId = null;

    /** Form fields. */
    public string $code = '';

    public string $name = '';

    public string $category = '';

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
        if (in_array($name, ['search', 'status'])) {
            $this->resetPage();
        }
    }

    protected function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255', Rule::unique('products', 'code')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
            'productStatus' => ['required', Rule::in(array_keys(Product::STATUSES))],
            'stock' => ['required', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'photo' => ['nullable', 'image', 'max:2048'], // max 2MB foto produk susu
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        $this->code = 'PRD-'.str_pad((string) (Product::max('id') + 1), 5, '0', STR_PAD_LEFT);
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $product = Product::findOrFail($id);

        $this->editingId = $product->id;
        $this->code = $product->code;
        $this->name = $product->name;
        $this->category = (string) $product->category;
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

        $product = $this->editingId ? Product::findOrFail($this->editingId) : new Product();

        $product->fill([
            'code' => $data['code'],
            'name' => $data['name'],
            'category' => $data['category'] ?: null,
            'sku' => $data['sku'] ?: null,
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
            'editingId', 'code', 'name', 'category', 'sku',
            'stock', 'description', 'photo', 'existingImage',
        ]);
        $this->productStatus = 'active';
        $this->stock = 0;
        $this->resetValidation();
    }

    public function render()
    {
        $products = Product::query()
            ->when($this->search, fn ($q) => $q->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('code', 'like', "%{$this->search}%")
                ->orWhere('sku', 'like', "%{$this->search}%")))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->withCount('detections')
            ->latest()
            ->paginate(12);

        return view('livewire.products.index', [
            'products' => $products,
            'total' => Product::count(),
            'statuses' => Product::STATUSES,
        ]);
    }
}
