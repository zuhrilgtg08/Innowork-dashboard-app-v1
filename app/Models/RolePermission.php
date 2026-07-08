<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    protected $fillable = [
        'role',
        'module',
        'access',
    ];

    /**
     * Modules that permissions are defined against.
     *
     * @var array<int, string>
     */
    public const MODULES = ['Dashboard', 'Users', 'Product', 'Categories', 'Live Camera', 'Training', 'Annotation', 'Logs', 'Settings'];

    /**
     * Access levels with UI metadata.
     *
     * @var array<string, array{label: string, color: string}>
     */
    public const ACCESS = [
        'f' => ['label' => 'Full', 'color' => 'green'],
        'w' => ['label' => 'Write', 'color' => 'blue'],
        'r' => ['label' => 'Read', 'color' => 'amber'],
        '-' => ['label' => 'None', 'color' => 'gray'],
    ];

    /**
     * Seed defaults — the baseline matrix shipped with the app.
     *
     * @return array<string, array<string, string>>
     */
    public static function defaults(): array
    {
        return [
            'admin' => array_fill_keys(self::MODULES, 'f'),
            'supervisor_qc' => [
                'Dashboard' => 'f', 'Users' => 'r', 'Product' => 'w', 'Categories' => 'w',
                'Live Camera' => 'f', 'Training' => 'w', 'Annotation' => 'w', 'Logs' => 'r', 'Settings' => 'r',
            ],
            'operator' => [
                'Dashboard' => 'r', 'Users' => '-', 'Product' => 'r', 'Categories' => '-',
                'Live Camera' => 'w', 'Training' => 'r', 'Annotation' => 'w', 'Logs' => 'r', 'Settings' => '-',
            ],
            'viewer' => [
                'Dashboard' => 'r', 'Users' => '-', 'Product' => 'r', 'Categories' => '-',
                'Live Camera' => 'r', 'Training' => '-', 'Annotation' => '-', 'Logs' => 'r', 'Settings' => '-',
            ],
        ];
    }

    /**
     * Full role → module → access matrix, sourced from the DB and backfilled
     * with defaults for any missing role/module pair.
     *
     * @return array<string, array<string, string>>
     */
    public static function matrix(): array
    {
        $defaults = self::defaults();
        $stored = self::all()->groupBy('role');

        $matrix = [];
        foreach (array_keys(User::ROLES) as $role) {
            foreach (self::MODULES as $module) {
                $matrix[$role][$module] = $stored[$role]?->firstWhere('module', $module)?->access
                    ?? $defaults[$role][$module]
                    ?? '-';
            }
        }

        return $matrix;
    }
}
