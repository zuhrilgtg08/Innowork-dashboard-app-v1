<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the bounding box that produced each detection.
 *
 * The ml-service already sends one `bbox` (and class `label`) per detected box
 * — see ml-service/infer.py — but CameraController dropped it: only a summary
 * copy survived inside a SystemLog `context`, which is a log, not queryable
 * data. Without this, no client can draw boxes over a frame.
 *
 * `frame_width`/`frame_height` are stored alongside because `bbox` is in raw
 * pixel coordinates; a client rendering the frame at any other size needs the
 * original dimensions to scale correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detections', function (Blueprint $table) {
            $table->json('bbox')->nullable()->after('confidence');
            $table->string('label')->nullable()->after('bbox');
            $table->unsignedSmallInteger('frame_width')->nullable()->after('label');
            $table->unsignedSmallInteger('frame_height')->nullable()->after('frame_width');
        });
    }

    public function down(): void
    {
        Schema::table('detections', function (Blueprint $table) {
            $table->dropColumn(['bbox', 'label', 'frame_width', 'frame_height']);
        });
    }
};
