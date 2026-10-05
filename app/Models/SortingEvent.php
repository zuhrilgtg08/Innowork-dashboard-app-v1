<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SortingEvent extends Model
{
    protected $fillable = [
        'event_uuid',
        'detection_id',
        'sorting_session_id',
        'status',
        'color',
        'destination',
        'confidence',
        'command_payload',
        'source',
        'processed',
    ];

    protected function casts(): array
    {
        return [
            'command_payload' => 'array',
            'processed' => 'boolean',
        ];
    }

    /**
     * Possible statuses for a sorting event with UI metadata.
     *
     * @var array<string, array{label: string, color: string}>
     */
    public const STATUSES = [
        'pending' => ['label' => 'Pending', 'color' => 'gray'],
        'in_progress' => ['label' => 'In Progress', 'color' => 'amber'],
        'completed' => ['label' => 'Completed', 'color' => 'green'],
        'error' => ['label' => 'Error', 'color' => 'red'],
    ];

    /**
     * Competition colors with UI metadata (canonical: green/yellow/red).
     *
     * @var array<string, array{label: string, destination: string, color: string}>
     */
    public const COLORS = [
        'green' => ['label' => 'Green', 'destination' => 'BOWL_GREEN', 'color' => 'green'],
        'yellow' => ['label' => 'Yellow', 'destination' => 'BOWL_YELLOW', 'color' => 'amber'],
        'red' => ['label' => 'Red', 'destination' => 'BOWL_RED', 'color' => 'red'],
    ];

    /**
     * Get counts of completed events by color.
     *
     * @return array{green: int, yellow: int, red: int}
     */
    public static function countsByColor(?int $sessionId = null): array
    {
        $query = self::where('status', 'completed');

        if ($sessionId !== null) {
            $query->where('sorting_session_id', $sessionId);
        }

        $result = $query
            ->selectRaw('color, count(*) as cnt')
            ->groupBy('color')
            ->pluck('cnt', 'color')
            ->toArray();

        return $result + [
            'green' => 0,
            'yellow' => 0,
            'red' => 0,
        ];
    }

    /**
     * Check if all three colors have 3 completed events (SORTING_COMPLETE).
     *
     * @return string 'SORTING_COMPLETE' or 'SORTING_IN_PROGRESS'
     */
    public static function systemState(?int $sessionId = null): string
    {
        $counts = self::countsByColor($sessionId);

        $allComplete = true;
        foreach (array_keys(self::COLORS) as $color) {
            if (($counts[$color] ?? 0) < 3) {
                $allComplete = false;
                break;
            }
        }

        return $allComplete ? 'SORTING_COMPLETE' : 'SORTING_IN_PROGRESS';
    }

    /**
     * Get the human-readable color label.
     */
    public function getColorLabel(): string
    {
        return self::COLORS[$this->color]['label'] ?? $this->color;
    }

    /**
     * Get the Tailwind color for the color.
     */
    public function getColorTint(): string
    {
        return self::COLORS[$this->color]['color'] ?? 'gray';
    }

    /**
     * Get the destination bowl name.
     */
    public function getDestination(): string
    {
        return self::COLORS[$this->color]['destination'] ?? 'BOWL_DEFAULT';
    }

    /**
     * Get the event UUID.
     */
    public function getEventUuid(): string
    {
        return $this->event_uuid;
    }

    /**
     * Get the associated detection.
     */
    public function detection(): BelongsTo
    {
        return $this->belongsTo(Detection::class, 'detection_id');
    }
}
