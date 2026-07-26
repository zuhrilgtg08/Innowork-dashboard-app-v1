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
     * Product categories moving through the line — the single source of truth
     * reused by the factory and the arm's target-zone presets (see
     * {@see TargetZonePreset}). Kept as a plain list because the
     * column itself is free text.
     *
     * @var array<int, string>
     */
    public const CATEGORIES = [
        'Electronics',
        'Apparel',
        'Food & Beverage',
        'Automotive Parts',
        'Cosmetics',
        'Pharmaceutical',
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

    /**
     * Next sequential product code, e.g. `PRD-00007`.
     *
     * Lives on the model so the Products screen and the mobile API mint codes
     * the same way.
     */
    public static function generateCode(): string
    {
        return 'PRD-'.str_pad((string) ((static::max('id') ?? 0) + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * SKU derived from the product name's initials plus a unique 3-digit
     * suffix, e.g. "Susu Segar" → `SS-042`.
     *
     * Deliberately avoids `fake()`: faker is a dev-only dependency and this
     * runs on the request path in production.
     */
    public static function generateSku(string $name): string
    {
        $initials = collect(preg_split('/[\s-]+/', $name) ?: [])
            ->filter()
            ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('');

        $initials = $initials !== '' ? $initials : 'PRD';

        // Sequential scan keeps this terminating even once many suffixes are taken.
        for ($suffix = 0; $suffix <= 999; $suffix++) {
            $candidate = $initials.'-'.str_pad((string) $suffix, 3, '0', STR_PAD_LEFT);

            if (! static::where('sku', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $initials.'-'.Str::random(6);
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
     * Resolve the product a decoded QR value refers to. The QR encodes the
     * public scan URL (url('/p/{qr_token}')), so we extract the 40-char token
     * (last path segment) and match it. Also tolerates a bare token or the
     * legacy "SORTVISION|{code}|{sku}" payload for older codes.
     */
    public static function resolveByQrValue(?string $qrValue): ?self
    {
        $qrValue = trim((string) $qrValue);
        if ($qrValue === '') {
            return null;
        }

        // Legacy payload: SORTVISION|{code}|{sku}
        if (str_starts_with($qrValue, 'SORTVISION|')) {
            $parts = explode('|', $qrValue);
            $code = $parts[1] ?? null;

            return $code ? static::where('code', $code)->first() : null;
        }

        // Public URL (/p/{token}) or a bare token: take the last path segment.
        $token = trim(parse_url($qrValue, PHP_URL_PATH) ?: $qrValue, '/');
        if (str_contains($token, '/')) {
            $token = substr($token, strrpos($token, '/') + 1);
        }

        return $token !== '' ? static::where('qr_token', $token)->first() : null;
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