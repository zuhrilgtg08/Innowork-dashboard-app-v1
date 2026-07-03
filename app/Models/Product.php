<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'category',
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

    /**
     * The most recent detection for this product (drives the public QR page).
     */
    public function latestDetection(): HasOne
    {
        return $this->hasOne(Detection::class)->latestOfMany('detected_at');
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
