<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = [
        'app_name',
        'timezone',
        'confidence_threshold',
        'auto_retrain',
        'auto_reject_on_damage',
        'email_alerts',
        'active_training_run_id',
    ];

    /**
     * Baseline values so the singleton is always well-formed, even when created
     * via firstOrCreate([]) (Postgres defaults don't apply to Eloquent inserts
     * that omit the column).
     */
    protected $attributes = [
        'app_name' => 'SortVision',
        'timezone' => 'Asia/Jakarta',
        'confidence_threshold' => 0.850,
        'auto_retrain' => true,
        'auto_reject_on_damage' => true,
        'email_alerts' => true,
    ];

    protected function casts(): array
    {
        return [
            'confidence_threshold' => 'decimal:3',
            'auto_retrain' => 'boolean',
            'auto_reject_on_damage' => 'boolean',
            'email_alerts' => 'boolean',
        ];
    }

    /**
     * The singleton settings row, cached until changed.
     */
    public static function current(): self
    {
        return Cache::rememberForever('settings.singleton', fn () => static::firstOrCreate([]));
    }

    /**
     * Forget the cached singleton after a write.
     */
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('settings.singleton'));
        static::deleted(fn () => Cache::forget('settings.singleton'));
    }

    /**
     * The training run whose model is currently live for inference.
     */
    public function activeRun(): ?TrainingRun
    {
        return $this->active_training_run_id
            ? TrainingRun::find($this->active_training_run_id)
            : null;
    }
}
