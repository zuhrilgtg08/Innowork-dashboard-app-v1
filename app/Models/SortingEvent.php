<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SortingEvent extends Model
{
    protected $fillable = [
        'event_uuid',
        'detection_id',
        'status',
        'color',
        'destination',
        'confidence',
        'command_payload',
        'source',
        'processed',
    ];

    /**
     * Possible statuses for a sorting event.
     *
     * @var array<string, string>
     */
    public const STATUSES = [
        'pending',
        'in_progress',
        'completed',
        'error',
    ];

    /**
     * Competition colors mapping.
     *
     * @var array<string, string>
     */
    public const COLORS = [
        'HIJAU' => 'GREEN',
        'KUNING' => 'YELLOW',
        'MERAH' => 'RED',
    ];

    /**
     * Possible destinations for sorted items.
     *
     * @var array<string, string>
     */
    public const DESTINATIONS = [
        'BOWL_GREEN',
        'BOWL_YELLOW',
        'BOWL_RED',
    ];

    /**
     * Get counts of completed events by color.
     *
     * @return array{HIJAU: int, KUNING: int, MERAH: int}
     */
    public static function countsByColor(): array
    {
        return self::where('status', 'completed')
            ->selectRaw('color, count(*) as cnt')
            ->groupBy('color')
            ->pluck('cnt', 'color')
            ->toArray() + [
            'HIJAU' => 0,
            'KUNING' => 0,
            'MERAH' => 0,
        ];
    }

    /**
     * Check if all three colors have 3 completed events (SORTING_COMPLETE).
     *
     * @return bool
     */
    public static function systemState(): string
    {
        $counts = self::countsByColor();

        $allComplete = true;
        foreach (self::COLORS as $color => $) {
            if ($counts[$color] ?? 0 < 3) {
                $allComplete = false;
                break;
            }
        }

        return $allComplete ? 'SORTING_COMPLETE' : 'SORTING_IN_PROGRESS';
    }

    /**
     * Get the human-readable color label.
     *
     * @param string $color
     * @return string
     */
    public function getColorLabel(): string
    {
        return data_get(self::COMPETITION_COLORS, $color, $color);
    }

    /**
     * Get the Tailwind color for the color.
     *
     * @param string $color
     * @return string
     */
    public function getColorTint(): string
    {
        return data_get(self::COMPETITION_COLORS, $color . '.color', 'gray');
    }

    /**
     * Get the destination bowl name.
     *
     * @param string $color
     * @return string
     */
    public function getDestination(): string
    {
        return data_get(self::DESTINATIONS, $this->color . '.', 'BOWL_DEFAULT');
    }

    /**
     * Get the event UUID.
     *
     * @return string
     */
    public function getEventUuid(): string
    {
        return $this->event_uuid;
    }

    /**
     * Get the associated detection.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function detection(): BelongsTo
    {
        return $this->belongsTo(Detection::class, 'detection_id');
    }
}