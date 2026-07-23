<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\TrainingRun;
use App\Services\MlClient;
use Illuminate\Console\Command;

/**
 * Activates a manually-provided trained model (e.g. copied from Colab into
 * storage/app/models/run-{n}/best.pt) without re-running the training flow.
 *
 * Aktivasi model normalnya di-set oleh callback "training selesai"
 * (MlCallbackController) atau seeder. Command ini menggantikannya untuk kasus
 * model yang di-copy manual: menunjuk sebuah TrainingRun ke best.pt yang ada di
 * disk lalu menjadikannya model aktif (Setting.active_training_run_id) yang
 * dipakai Live Camera untuk inference.
 */
class ActivateModelCommand extends Command
{
    protected $signature = 'sortvision:activate-model
        {run=1 : Nomor run (folder storage/app/models/run-{run})}
        {--min-map= : mAP50 minimum (0–100) yang harus dilampaui; default dari ML_MIN_MAP50}
        {--force : Aktifkan walau mAP di bawah minimum / tidak diketahui}';

    protected $description = 'Aktifkan model best.pt hasil training (models/run-{n}/best.pt) sebagai model live inference';

    public function handle(MlClient $ml): int
    {
        $run = (int) $this->argument('run');
        $modelRel = "models/run-{$run}/best.pt";
        $absolute = storage_path('app/'.$modelRel);

        if (! is_file($absolute)) {
            $this->error("Model tidak ditemukan: {$absolute}");
            $this->line('Pastikan best.pt sudah di-copy ke path tersebut (lihat COLAB_TRAINING.md).');

            return self::FAILURE;
        }

        $trainingRun = TrainingRun::updateOrCreate(
            ['model_path' => $modelRel],
            [
                'name' => "ultra-milk-run-{$run}",
                'status' => 'completed',
                'progress' => 100,
                'epochs' => 50,
                'metrics' => ['map50' => null],
                'finished_at' => now(),
            ],
        );

        // Quality gate — refuse to promote a model below the mAP bar unless forced.
        $minMap = $this->option('min-map') !== null
            ? (float) $this->option('min-map')
            : (float) config('services.ml.min_map', 0);

        if (! $this->option('force') && ! $trainingRun->meetsQualityBar($minMap)) {
            $this->error("mAP50 ".($trainingRun->map50() ?? 'n/a')." di bawah minimum {$minMap}. Batal (pakai --force untuk memaksa).");

            return self::FAILURE;
        }

        Setting::current()->update(['active_training_run_id' => $trainingRun->id]);

        // Best-effort: hot-reload the ML service so the new weights take effect.
        $reloaded = $ml->reloadModel($modelRel);

        $this->info('Model aktif diperbarui.'.($reloaded ? ' ML service reloaded.' : ' (ML service offline — reload dilewati.)'));
        $this->table(
            ['Run ID', 'Name', 'Status', 'mAP50', 'Model path'],
            [[$trainingRun->id, $trainingRun->name, $trainingRun->status, $trainingRun->map50() ?? 'n/a', $trainingRun->model_path]],
        );

        return self::SUCCESS;
    }
}
