<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RobotCommand extends Model
{
    protected $fillable = [
        'uuid',
        'source',
        'x',
        'y',
        'g',
        'r',
        'y_flag',
        'color',
        'status',
        'error_message',
        'claimed_by',
        'acknowledged_at',
        'executing_at',
        'completed_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'g' => 'boolean',
            'r' => 'boolean',
            'y_flag' => 'boolean',
            'acknowledged_at' => 'datetime',
            'executing_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * Lifecycle states. Uppercase only; persisted verbatim.
     *
     * @var array<int, string>
     */
    public const STATUSES = ['PENDING', 'ACKNOWLEDGED', 'EXECUTING', 'COMPLETED', 'FAILED'];

    /**
     * Semantic colors. Uppercase English only (no Indonesian class names).
     *
     * @var array<int, string>
     */
    public const COLORS = ['GREEN', 'RED', 'YELLOW'];

    /**
     * Allowed state transitions, including idempotent repeats of the current
     * state. Anything else (e.g. COMPLETED -> EXECUTING) must be rejected so
     * a repeated poll can never resurrect or duplicate a command.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        'PENDING' => ['ACKNOWLEDGED'],
        'ACKNOWLEDGED' => ['ACKNOWLEDGED', 'EXECUTING'],
        'EXECUTING' => ['EXECUTING', 'COMPLETED', 'FAILED'],
        'COMPLETED' => ['COMPLETED'],
        'FAILED' => ['FAILED'],
    ];

    /**
     * Resolve the semantic color for one-hot G/R/Y flags.
     *
     * @return string|null GREEN, RED, YELLOW, or null when not exactly one flag is 1.
     */
    public static function colorForFlags(int $g, int $r, int $y): ?string
    {
        if ($g + $r + $y !== 1) {
            return null;
        }

        return match (true) {
            $g === 1 => 'GREEN',
            $r === 1 => 'RED',
            $y === 1 => 'YELLOW',
            default => null,
        };
    }

    /**
     * Whether a transition from the current status to $target is allowed.
     */
    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * External device payload. G/R/Y keep their exact uppercase JSON keys;
     * the database stores lowercase columns internally.
     *
     * @return array{id: int, uuid: string, x: float, y: float, G: int, R: int, Y: int, color: string, status: string}
     */
    public function toDevicePayload(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'x' => (float) $this->x,
            'y' => (float) $this->y,
            'G' => $this->g ? 1 : 0,
            'R' => $this->r ? 1 : 0,
            'Y' => $this->y_flag ? 1 : 0,
            'color' => $this->color,
            'status' => $this->status,
        ];
    }
}
