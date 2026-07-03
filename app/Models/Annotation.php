<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Annotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'detection_id',
        'image_path',
        'label',
        'bbox',
        'status',
        'source',
        'confidence',
    ];

    protected function casts(): array
    {
        return [
            'bbox' => 'array',
            'confidence' => 'decimal:2',
        ];
    }

    /**
     * Annotation review states with UI metadata.
     *
     * @var array<string, array{label: string, color: string}>
     */
    public const STATUSES = [
        'pending' => ['label' => 'Pending',  'color' => 'amber'],
        'approved' => ['label' => 'Approved', 'color' => 'green'],
    ];

    /**
     * Where the label came from.
     *
     * @var array<int, string>
     */
    public const SOURCES = ['ai', 'human'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function detection(): BelongsTo
    {
        return $this->belongsTo(Detection::class);
    }
}
