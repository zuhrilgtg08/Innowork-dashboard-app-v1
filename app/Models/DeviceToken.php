<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'platform',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    /** Platforms a token can come from. */
    public const PLATFORMS = ['ios', 'android', 'web'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * An Expo push token looks like `ExponentPushToken[xxxxxxxx]`. Checked
     * before sending so a malformed value is dropped locally instead of being
     * posted to Expo only to come back as an error.
     */
    public static function looksLikeExpoToken(string $token): bool
    {
        return (bool) preg_match('/^Expo(nent)?PushToken\[.+\]$/', $token);
    }
}
