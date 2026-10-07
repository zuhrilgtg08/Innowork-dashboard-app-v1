<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent queue for the manual/debug VPS <-> ESP32 robot command bridge.
 *
 * The ESP32 polls outbound over HTTPS (never the reverse), so commands must
 * survive across requests: one row per command with an explicit lifecycle
 * PENDING -> ACKNOWLEDGED -> EXECUTING -> COMPLETED / FAILED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robot_commands', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source')->nullable()->default('manual');
            $table->decimal('x', 10, 3);
            $table->decimal('y', 10, 3);
            $table->boolean('g')->default(false);
            $table->boolean('r')->default(false);
            $table->boolean('y_flag')->default(false);
            $table->string('color', 16);
            $table->string('status', 16)->default('PENDING');
            $table->text('error_message')->nullable();
            $table->string('claimed_by')->nullable();
            $table->timestamps();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('executing_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robot_commands');
    }
};
