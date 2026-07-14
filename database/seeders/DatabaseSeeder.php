<?php

namespace Database\Seeders;

use App\Models\Annotation;
use App\Models\Detection;
use App\Models\Product;
use App\Models\RolePermission;
use App\Models\Setting;
use App\Models\SystemLog;
use App\Models\TargetZonePreset;
use App\Models\TrainingRun;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // One account per role — password: "password"
        $accounts = [
            ['name' => 'Ahmad Fauzi',    'email' => 'admin@sortvision.test',      'role' => 'admin',         'title' => 'System Administrator'],
            ['name' => 'Rina Marlina',   'email' => 'supervisor@sortvision.test', 'role' => 'supervisor_qc', 'title' => 'QC Supervisor'],
            ['name' => 'Budi Santoso',   'email' => 'operator@sortvision.test',   'role' => 'operator',      'title' => 'Line Operator'],
            ['name' => 'Sari Dewi',      'email' => 'viewer@sortvision.test',     'role' => 'viewer',        'title' => 'Monitoring Staff'],
        ];

        foreach ($accounts as $account) {
            User::factory()->create($account + ['is_active' => true]);
        }

        // A handful of extra members for the Users table.
        User::factory(12)->create();

        // Catalogue of products moving through the line.
        Product::factory(40)->create();

        // Realtime detection history (QC scans).
        Detection::factory(600)->create();

        // System / device logs.
        SystemLog::factory(150)->create();

        // Labelled dataset for the Annotation + Training screens.
        Annotation::factory(90)->approved()->create();
        Annotation::factory(30)->create(); // pending / mixed review states

        // Training history: two finished runs so the Training page shows metrics.
        TrainingRun::factory(2)->completed()->create();

        // Baseline role → module permission matrix.
        foreach (RolePermission::defaults() as $role => $modules) {
            foreach ($modules as $module => $access) {
                RolePermission::updateOrCreate(
                    ['role' => $role, 'module' => $module],
                    ['access' => $access],
                );
            }
        }

        // Arm target-zone presets (joint-angle recipes per product category).
        // No Jetson computes IK in Opsi A, so the backend ships these and the
        // ESP32 replays them — placeholder angles until the team tunes them.
        foreach (TargetZonePreset::defaults() as $preset) {
            TargetZonePreset::updateOrCreate(['slug' => $preset['slug']], $preset);
        }

        // Singleton system settings row, pointing at the latest trained model.
        Setting::firstOrCreate([])->update([
            'active_training_run_id' => TrainingRun::where('status', 'completed')->max('id'),
        ]);
    }
}
