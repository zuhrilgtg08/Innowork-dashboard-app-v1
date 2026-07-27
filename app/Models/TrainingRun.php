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

    /**
     * mAP@50 for this run (metrics are stored on the 0–100 scale), or null if
     * the run has no recorded metric (e.g. a model copied in manually).
     */
    public function map50(): ?float
    {
        $value = $this->metrics['map50'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Does this run clear the minimum quality bar to be promoted to live?
     * A run with no recorded mAP is treated as "unknown" and does NOT clear a
     * positive bar (caller can still force it).
     */
    public function meetsQualityBar(float $minMap50): bool
    {
        if ($minMap50 <= 0) {
            return true;
        }

        $map = $this->map50();

        return $map !== null && $map >= $minMap50;
    }
}
