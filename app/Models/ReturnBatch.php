<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Groups defective {@see Detection}s that were auto-rejected off a conveyor so
 * an operator can review and resolve them as a batch. {@see QcWorkflow} attaches
 * defects to the single "open" batch for their conveyor.
 */
class ReturnBatch extends Model
{
    protected $fillable = [
        'conveyor',
        'reason',
        'status',
        'notes',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @var array<string, array{label: string, color: string}>
     */
    public const STATUSES = [
        'open' => ['label' => 'Open',     'color' => 'amber'],
        'resolved' => ['label' => 'Resolved', 'color' => 'green'],
    ];

    public function detections(): HasMany
    {
        return $this->hasMany(Detection::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? ucfirst($this->status);
    }

    public function statusColor(): string
    {
        return self::STATUSES[$this->status]['color'] ?? 'gray';
    }

    /**
     * The single open batch collecting defects for a conveyor, created on demand.
     */
    public static function openForConveyor(?string $conveyor, ?string $reason = null): self
    {
        return static::firstOrCreate(
            ['conveyor' => $conveyor, 'status' => 'open'],
            ['reason' => $reason ?? 'Auto-reject: QC defect'],
        );
    }

    /**
     * Mark this batch resolved by a user.
     */
    public function resolve(?int $userId = null): void
    {
        $this->update([
            'status' => 'resolved',
            'resolved_by' => $userId,
            'resolved_at' => now(),
        ]);
    }
}
