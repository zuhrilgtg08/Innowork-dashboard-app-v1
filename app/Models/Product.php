<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'category_id',
        'sku',
        'status',
        'stock',
        'image',
        'qr_path',
        'qr_token',
        'description',
    ];

    /**
     * Product statuses with UI metadata.
     *
     * @var array<string, array{label: string, color: string}>
     */
    public const STATUSES = [
        'active' => ['label' => 'Active',   'color' => 'green'],
        'inactive' => ['label' => 'Inactive', 'color' => 'amber'],
        'archived' => ['label' => 'Archived', 'color' => 'gray'],
    ];

    /**
     * Assign an unguessable public token to new products.
     */
    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (empty($product->qr_token)) {
                $product->qr_token = Str::random(40);
            }
        });
    }

    public function detections(): HasMany
    {
        return $this->hasMany(Detection::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The most recent detection for this product (drives the public QR page).
     */
    public function latestDetection(): HasOne
    {
        return $this->hasOne(Detection::class)->latestOfMany('detected_at');
    }

    /**
     * Get the full URL for the product image.
     * Handles both storage disk paths and public asset paths.
     */
    public function imageUrl(): ?string
    {
        if (empty($this->image)) {
            return null;
        }

        if (str_starts_with($this->image, 'assets/')) {
            return asset($this->image);
        }

        return Storage::url($this->image);
    }

    /**
     * Content encoded into the product's QR code — a scannable public URL.
     */
    public function qrPayload(): string
    {
        return url('/p/'.$this->qr_token);
    }

    /**
     * (Re)generate and persist this product's QR code as an SVG on the public disk.
     * Shared by the Products screen and the sortvision:regenerate-qr command.
     */
    public function regenerateQr(): void
    {
        $svg = QrCode::format('svg')->size(240)->margin(1)->generate($this->qrPayload());

        $path = 'qrcodes/'.$this->code.'.svg';
        Storage::disk('public')->put($path, $svg);

        $this->forceFill(['qr_path' => $path])->save();
    }
}