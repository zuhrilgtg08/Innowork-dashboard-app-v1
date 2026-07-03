<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrainingRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'status',
        'progress',
        'current_epoch',
        'epochs',
        'metrics',
        'dataset_train',
        'dataset_val',
        'model_path',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Run statuses with UI metadata.
     *
     * @var array<string, array{label: string, color: string}>
     */
    public const STATUSES = [
        'queued' => ['label' => 'Queued',    'color' => 'gray'],
        'exporting' => ['label' => 'Exporting', 'color' => 'blue'],
        'training' => ['label' => 'Training',  'color' => 'blue'],
        'completed' => ['label' => 'Completed', 'color' => 'green'],
        'failed' => ['label' => 'Failed',    'color' => 'red'],
    ];

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? ucfirst($this->status);
    }

    public function statusColor(): string
    {
        return self::STATUSES[$this->status]['color'] ?? 'gray';
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'exporting', 'training'], true);
    }
}
