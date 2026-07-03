<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('app_name')->default('SortVision');
            $table->string('timezone')->default('Asia/Jakarta');
            $table->decimal('confidence_threshold', 4, 3)->default(0.850); // inference decision threshold
            $table->boolean('auto_retrain')->default(true);
            $table->boolean('auto_reject_on_damage')->default(true);
            $table->boolean('email_alerts')->default(true);
            // Which completed training run's model is live for inference.
            $table->foreignId('active_training_run_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
