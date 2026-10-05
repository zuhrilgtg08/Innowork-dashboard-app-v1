<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('detections', 'color')) {
            Schema::table('detections', function (Blueprint $table): void {
                $table->string('color')->nullable()->after('status');
            });
        }
        if (! Schema::hasColumn('detections', 'center_x')) {
            Schema::table('detections', function (Blueprint $table): void {
                $table->smallInteger('center_x')->nullable()->after('color');
            });
        }
        if (! Schema::hasColumn('detections', 'center_y')) {
            Schema::table('detections', function (Blueprint $table): void {
                $table->smallInteger('center_y')->nullable()->after('center_x');
            });
        }
        if (! Schema::hasColumn('detections', 'in_pick_zone')) {
            Schema::table('detections', function (Blueprint $table): void {
                $table->boolean('in_pick_zone')->default(false)->after('center_y');
            });
        }
        if (! Schema::hasColumn('detections', 'processed')) {
            Schema::table('detections', function (Blueprint $table): void {
                $table->boolean('processed')->default(false)->after('in_pick_zone');
            });
        }
        if (! Schema::hasColumn('detections', 'competition_event_id')) {
            Schema::table('detections', function (Blueprint $table): void {
                $table->uuid('competition_event_id')->nullable()->after('processed');
            });
        }
    }

    public function down(): void
    {
        Schema::table('detections', function (Blueprint $table): void {
            $columns = ['color', 'center_x', 'center_y', 'in_pick_zone', 'processed', 'competition_event_id'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('detections', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
