<?php

namespace App\Livewire\Roles;

use App\Models\RolePermission;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Roles & Permission'])]
class Index extends Component
{
    #[Url]
    public string $role = '';

    public string $editingRole = '';

    /**
     * @var array<string, string>
     */
    public array $draft = [];

    public string $saved = '';

    public function mount(): void
    {
        if (! in_array(auth()->user()->role, ['admin', 'supervisor_qc', 'operator'])) {
            $this->redirect(route('dashboard'), navigate: true);
        }
    }

    /**
     * Open the permission editor for a role. Only admin can edit.
     */
    public function edit(string $role): void
    {
        if (! array_key_exists($role, User::ROLES)) {
            return;
        }

        if (auth()->user()->role !== 'admin') {
            return;
        }

        $this->editingRole = $role;
        $this->draft = RolePermission::matrix()[$role];
        $this->saved = '';
    }

    public function cancel(): void
    {
        $this->reset('editingRole', 'draft');
    }

    /**
     * Persist the edited permissions for the current role. Only admin can save.
     */
    public function save(): void
    {
        if (auth()->user()->role !== 'admin') {
            return;
        }

        if (! array_key_exists($this->editingRole, User::ROLES)) {
            return;
        }

        $this->validate([
            'draft.*' => ['required', 'in:f,w,r,-'],
        ]);

        foreach ($this->draft as $module => $access) {
            if (! in_array($module, RolePermission::MODULES, true)) {
                continue;
            }

            RolePermission::updateOrCreate(
                ['role' => $this->editingRole, 'module' => $module],
                ['access' => $access],
            );
        }

        $this->saved = $this->editingRole;
        $this->reset('editingRole', 'draft');
    }

    public function render()
    {
        $counts = User::query()
            ->selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        return view('livewire.roles.index', [
            'roles' => User::ROLES,
            'counts' => $counts,
            'modules' => RolePermission::MODULES,
            'matrix' => RolePermission::matrix(),
            'access' => RolePermission::ACCESS,
        ]);
    }
}
