<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry of cameras on the line. Replaces the single ICAM_RTSP_URL with a
 * multi-camera config: each row is one physical camera feeding one conveyor.
 * The ml-service reads active rows to spawn a capture/inference thread per feed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cameras', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();                 // CAM-01
            $table->string('conveyor')->nullable();           // LINE-A
            $table->string('rtsp_url')->nullable();           // rtsp://ip:8550/video
            $table->string('sim_source')->nullable();         // fallback video/webcam index
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0); // grid ordering
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cameras');
    }
};
