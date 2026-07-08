<?php

namespace App\Livewire\Users;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Users'])]
class Index extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $role = '';

    /** Modal state. */
    public bool $showModal = false;

    /** Id of the user being edited (null = creating). */
    public ?int $editingId = null;

    /** Form fields. */
    public string $name = '';

    public string $email = '';

    public string $userRole = 'viewer';

    public string $title = '';

    public string $password = '';

    public bool $is_active = true;

    /** Avatar upload. */
    public $avatar = null;

    public ?string $existingAvatar = null;

    /** Flash + delete-confirmation state. */
    public string $flash = '';

    public ?int $confirmingDeleteId = null;

    public function updating($name): void
    {
        if (in_array($name, ['search', 'role'])) {
            $this->resetPage();
        }
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'userRole' => ['required', Rule::in(array_keys(User::ROLES))],
            'title' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'password' => [$this->editingId ? 'nullable' : 'required', 'nullable', 'string', 'min:8'],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ];
    }

    protected array $messages = [
        'userRole.required' => 'Role wajib dipilih.',
        'userRole.in' => 'Role tidak valid.',
    ];

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $user = User::findOrFail($id);

        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->userRole = $user->role;
        $this->title = (string) $user->title;
        $this->is_active = (bool) $user->is_active;
        $this->password = '';
        $this->existingAvatar = $user->avatar;
        $this->avatar = null;
        $this->resetValidation();
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate();

        $user = $this->editingId ? User::findOrFail($this->editingId) : new User();

        // Guard: the sole Administrator cannot be demoted or deactivated —
        // the admin role is tunggal (single top-level account).
        if ($this->editingId && $this->isProtectedAdmin($user)) {
            if ($this->userRole !== 'admin') {
                $this->addError('userRole', 'Administrator tunggal tidak dapat diturunkan role-nya.');

                return;
            }
            if (! $this->is_active) {
                $this->addError('is_active', 'Administrator tunggal tidak dapat dinonaktifkan.');

                return;
            }
        }

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['userRole'],
            'title' => $data['title'] ?: null,
            'is_active' => $data['is_active'],
        ]);

        if (! empty($data['password'])) {
            $user->password = $data['password']; // "hashed" cast bcrypts it
        }

        // Store avatar on the public disk.
        if ($this->avatar) {
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }
            $user->avatar = $this->avatar->store('avatars', 'public');
        }

        $user->save();

        $this->flash = $this->editingId ? 'User berhasil diperbarui.' : 'User baru berhasil ditambahkan.';
        $this->closeModal();
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmingDeleteId = $id;
    }

    public function delete(int $id): void
    {
        $user = User::findOrFail($id);

        if ($this->isProtectedAdmin($user)) {
            $this->flash = 'Administrator tunggal tidak dapat dihapus.';
            $this->confirmingDeleteId = null;

            return;
        }

        if ($user->id === auth()->id()) {
            $this->flash = 'Anda tidak dapat menghapus akun sendiri.';
            $this->confirmingDeleteId = null;

            return;
        }

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $user->delete();
        $this->flash = 'User berhasil dihapus.';
        $this->confirmingDeleteId = null;
    }

    /**
     * True when the user is an admin and the only admin left (tunggal).
     */
    protected function isProtectedAdmin(User $user): bool
    {
        return $user->role === 'admin' && User::where('role', 'admin')->count() <= 1;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'email', 'title', 'password', 'avatar', 'existingAvatar']);
        $this->userRole = 'viewer';
        $this->is_active = true;
        $this->resetValidation();
    }

    public function render()
    {
        $users = User::query()
            ->when($this->search, fn ($q) => $q->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")))
            ->when($this->role, fn ($q) => $q->where('role', $this->role))
            ->latest()
            ->paginate(10);

        return view('livewire.users.index', [
            'users' => $users,
            'roles' => User::ROLES,
            'total' => User::count(),
            'adminCount' => User::where('role', 'admin')->count(),
        ]);
    }
}