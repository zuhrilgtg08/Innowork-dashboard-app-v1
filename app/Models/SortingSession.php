<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SortingSession extends Model
{
    protected $fillable = [
        'session_key',
        'status',
        'started_at',
        'completed_at',
        'last_reset_at',
    ];

    /**
     * Get the sorting events for this session.
     */
    public function events(): HasMany
    {
        return $this->hasMany(SortingEvent::class, 'sorting_session_id');
    }

    /**
     * The singleton active session, cached until changed.
     */
    public static function current(): self
    {
        return Cache::rememberForever('sorting_session.active', function () {
            return static::where('status', 'active')->first()
                ?? static::create([
                    'session_key' => 'session-'.now()->format('Ymd-His').'-'.Str::random(6),
                    'status' => 'active',
                    'started_at' => now(),
                ]);
        });
    }

    /**
     * Get the count of completed events by color for this session.
     *
     * @return array{green: int, yellow: int, red: int}
     */
    public function getCompletionCounts(): array
    {
        $counts = $this->events()
            ->where('status', 'completed')
            ->selectRaw('color, count(*) as cnt')
            ->groupBy('color')
            ->pluck('cnt', 'color')
            ->toArray();

        return $counts + [
            'green' => 0,
            'yellow' => 0,
            'red' => 0,
        ];
    }

    /**
     * Check if this session has reached SORTING_COMPLETE (3/3 per color).
     *
     * @return string 'SORTING_COMPLETE' or 'SORTING_IN_PROGRESS'
     */
    public function getCompletionStatus(): string
    {
        $counts = $this->getCompletionCounts();

        $allComplete = true;
        foreach (['green', 'yellow', 'red'] as $color) {
            if (($counts[$color] ?? 0) < 3) {
                $allComplete = false;
                break;
            }
        }

        return $allComplete ? 'SORTING_COMPLETE' : 'SORTING_IN_PROGRESS';
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('sorting_session.active'));
        static::deleted(fn () => Cache::forget('sorting_session.active'));
    }
}
