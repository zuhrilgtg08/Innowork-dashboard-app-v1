<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Detection extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'product_id',
        'camera',
        'conveyor',
        'status',
        'qr_value',
        'frame_path',
        'confidence',
        'detected_at',
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'confidence' => 'decimal:2',
        ];
    }

    /**
     * All possible detection statuses with UI metadata.
     *
     * @var array<string, array{label: string, color: string}>
     */
    public const STATUSES = [
        'passed' => ['label' => 'Passed',     'color' => 'green'],
        'unreadable' => ['label' => 'QR Unreadable', 'color' => 'amber'],
        'damaged' => ['label' => 'Damaged',    'color' => 'red'],
        'scratched' => ['label' => 'Scratched',  'color' => 'orange'],
        'returned' => ['label' => 'Returned',   'color' => 'rose'],
        'recheck' => ['label' => 'Recheck',    'color' => 'blue'],
    ];

    /**
     * Statuses that count as a QC failure (defect / anomaly).
     *
     * @var array<int, string>
     */
    public const FAILED_STATUSES = ['unreadable', 'damaged', 'scratched'];

    /**
     * Statuses that are valid visual QC classes for training. Workflow-only
     * states ('returned', 'recheck') are excluded — they are not something the
     * vision model can learn to recognise from a frame.
     *
     * @var array<int, string>
     */
    public const TRAINABLE_STATUSES = ['passed', 'unreadable', 'damaged', 'scratched'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
     * Get the URL for the detection image (frame or product fallback).
     */
    public function imageUrl(): string
    {
        $path = $this->frame_path ?: $this->product?->image;

        if (empty($path)) {
            return '';
        }

        if (str_starts_with($path, 'assets/')) {
            return asset($path);
        }

        return Storage::url($path);
    }
}
