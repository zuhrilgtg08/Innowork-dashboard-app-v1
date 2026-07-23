<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Which source the Live Camera page uses: browser webcam or ICAM-300.
            $table->string('camera_source')->default('webcam');
            // RTSP URL of the ICAM-300 (reference/UI; ml-service uses its own env).
            $table->string('icam_rtsp_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['camera_source', 'icam_rtsp_url']);
        });
    }
};
