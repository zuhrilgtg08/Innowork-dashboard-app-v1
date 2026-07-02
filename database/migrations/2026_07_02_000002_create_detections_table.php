<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detections', function (Blueprint $table) {
            $table->id();
            $table->string('code');                              // scan/batch code, e.g. SCN-8A21
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('camera');                            // CAM-01, CAM-02, ...
            $table->string('conveyor')->nullable();              // LINE-A, LINE-B
            // passed, unreadable, damaged, scratched, returned, recheck
            $table->string('status');
            $table->string('qr_value')->nullable();              // decoded QR content (null if unreadable)
            $table->decimal('confidence', 5, 2)->default(0);     // detection confidence %
            $table->timestamp('detected_at')->index();
            $table->timestamps();

            $table->index(['status', 'detected_at']);
            $table->index(['camera', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detections');
    }
};
