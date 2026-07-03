<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'passed'     => ['label' => 'Passed',     'color' => 'green'],
        'unreadable' => ['label' => 'QR Unreadable', 'color' => 'amber'],
        'damaged'    => ['label' => 'Damaged',    'color' => 'red'],
        'scratched'  => ['label' => 'Scratched',  'color' => 'orange'],
        'returned'   => ['label' => 'Returned',   'color' => 'rose'],
        'recheck'    => ['label' => 'Recheck',    'color' => 'blue'],
    ];

    /**
     * Statuses that count as a QC failure (defect / anomaly).
     *
     * @var array<int, string>
     */
    public const FAILED_STATUSES = ['unreadable', 'damaged', 'scratched'];

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
}
