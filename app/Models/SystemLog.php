<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'level',
        'source',
        'message',
        'context',
        'logged_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'logged_at' => 'datetime',
        ];
    }

    /**
     * @var array<string, string>
     */
    public const LEVELS = [
        'info' => 'blue',
        'warning' => 'amber',
        'error' => 'red',
        'critical' => 'rose',
    ];

    public const SOURCES = ['system', 'camera', 'conveyor', 'arm', 'auth', 'ai'];

    public function levelColor(): string
    {
        return self::LEVELS[$this->level] ?? 'gray';
    }
}
