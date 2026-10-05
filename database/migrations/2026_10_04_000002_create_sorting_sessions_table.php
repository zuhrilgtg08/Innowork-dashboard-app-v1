<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sorting_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('session_key', 50)->unique();
            $table->enum('status', ['active', 'completed'])->default('active');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_reset_at')->nullable();
            $table->timestamps();

            $table->index('session_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sorting_sessions');
    }
};
