<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_runs', function (Blueprint $table) {
            $table->id();
            $table->string('name');                                 // e.g. yolov8n-qc-12
            // queued, exporting, training, completed, failed
            $table->string('status')->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);    // 0-100
            $table->unsignedInteger('current_epoch')->default(0);
            $table->unsignedInteger('epochs')->default(5);
            $table->json('metrics')->nullable();                    // {map50, precision, recall, per_class:[...]}
            $table->unsignedInteger('dataset_train')->default(0);
            $table->unsignedInteger('dataset_val')->default(0);
            $table->string('model_path')->nullable();               // relative to storage/app, e.g. models/run-12/best.pt
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_runs');
    }
};
