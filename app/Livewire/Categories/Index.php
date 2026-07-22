<?php

namespace App\Livewire\Categories;

use App\Models\Category;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Categories'])]
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
    public string $name = '';

    public string $description = '';

    public string $image = '';

    public $photo = null;

    public ?string $existingImage = null;

    public bool $is_active = true;

    public int $sort_order = 0;

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
            'name' => ['required', 'string', 'max:255', Rule::unique('categories', 'name')->ignore($this->editingId)],
            'description' => ['nullable', 'string'],
            'photo' => ['nullable', 'image', 'max:2048'],
            'is_active' => ['boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $category = Category::findOrFail($id);

        $this->editingId = $category->id;
        $this->name = $category->name;
        $this->description = (string) $category->description;
        $this->existingImage = $category->image;
        $this->photo = null;
        $this->is_active = $category->is_active;
        $this->sort_order = $category->sort_order;
        $this->resetValidation();
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate();

        $category = $this->editingId ? Category::findOrFail($this->editingId) : new Category();

        $category->fill([
            'name' => $data['name'],
            'slug' => \Illuminate\Support\Str::slug($data['name']),
            'description' => $data['description'] ?: null,
            'is_active' => $data['is_active'],
            'sort_order' => $data['sort_order'],
        ]);

        // Store the category image on the public disk.
        if ($this->photo) {
            if ($category->image) {
                Storage::disk('public')->delete($category->image);
            }
            $category->image = $this->photo->store('categories', 'public');
        }

        $category->save();

        $this->flash = $this->editingId ? 'Kategori berhasil diperbarui.' : 'Kategori baru berhasil ditambahkan.';
        $this->closeModal();
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmingDeleteId = $id;
    }

    public function delete(int $id): void
    {
        $category = Category::findOrFail($id);

        if ($category->image) {
            Storage::disk('public')->delete($category->image);
        }

        $category->delete();
        $this->flash = 'Kategori berhasil dihapus.';
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
            'editingId', 'name', 'description', 'image', 'photo', 'existingImage',
        ]);
        $this->is_active = true;
        $this->sort_order = 0;
        $this->resetValidation();
    }

    public function render()
    {
        $categories = Category::query()
            ->when($this->search, fn ($q) => $q->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('description', 'like', "%{$this->search}%")))
            ->when($this->status !== '', fn ($q) => $q->where('is_active', $this->status === 'active'))
            ->orderBy('sort_order')
            ->latest()
            ->paginate(12);

        return view('livewire.categories.index', [
            'categories' => $categories,
            'total' => Category::count(),
        ]);
    }
}