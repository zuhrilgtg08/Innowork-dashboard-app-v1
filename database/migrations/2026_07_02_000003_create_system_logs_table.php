<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level')->default('info');   // info, warning, error, critical
            $table->string('source')->default('system'); // camera, conveyor, system, auth, ai
            $table->string('message');
            $table->json('context')->nullable();
            $table->timestamp('logged_at')->index();
            $table->timestamps();

            $table->index(['level', 'logged_at']);
            $table->index(['source', 'logged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_logs');
    }
};
