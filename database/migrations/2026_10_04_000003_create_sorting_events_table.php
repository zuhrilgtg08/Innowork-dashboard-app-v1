<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sorting_events', function (Blueprint $table): void {
            $table->id();
            $table->char('event_uuid', 36)->unique();
            $table->foreignId('detection_id')->constrained()->onDelete('cascade');
            $table->foreignId('sorting_session_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['pending', 'in_progress', 'completed', 'error'])->default('pending');
            $table->enum('color', ['green', 'yellow', 'red']);
            $table->enum('destination', ['BOWL_GREEN', 'BOWL_YELLOW', 'BOWL_RED']);
            $table->decimal('confidence', 5, 2);
            $table->json('command_payload')->nullable();
            $table->string('source', 50)->default('ml_service');
            $table->boolean('processed')->default(false);
            $table->timestamps();

            $table->index('detection_id');
            $table->index('status');
            $table->index('color');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sorting_events');
    }
};
