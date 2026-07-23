<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\TrainingRun;
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
    protected $signature = 'sortvision:activate-model {run=1 : Nomor run (folder storage/app/models/run-{run})}';

    protected $description = 'Aktifkan model best.pt hasil training (models/run-{n}/best.pt) sebagai model live inference';

    public function handle(): int
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

        Setting::current()->update(['active_training_run_id' => $trainingRun->id]);

        $this->info('Model aktif diperbarui.');
        $this->table(
            ['Run ID', 'Name', 'Status', 'Model path'],
            [[$trainingRun->id, $trainingRun->name, $trainingRun->status, $trainingRun->model_path]],
        );

        return self::SUCCESS;
    }
}
