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

    /** Role currently being edited (empty = editor closed). */
    public string $editingRole = '';

    /**
     * Working copy of the edited role's permissions: module => access.
     *
     * @var array<string, string>
     */
    public array $draft = [];

    public string $saved = '';

    /**
     * Open the permission editor for a role.
     */
    public function edit(string $role): void
    {
        if (! array_key_exists($role, User::ROLES)) {
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
     * Persist the edited permissions for the current role.
     */
    public function save(): void
    {
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
