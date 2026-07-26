<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A return batch groups defective detections routed off the line (auto-reject)
 * so an operator can review and resolve them together. One "open" batch per
 * conveyor collects incoming defects until resolved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_batches', function (Blueprint $table) {
            $table->id();
            $table->string('conveyor')->nullable();
            $table->string('reason')->nullable();
            $table->string('status')->default('open');       // open, resolved
            $table->text('notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'conveyor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_batches');
    }
};
