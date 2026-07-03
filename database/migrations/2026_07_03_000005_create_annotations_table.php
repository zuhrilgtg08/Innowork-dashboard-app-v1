<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('detection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('image_path');                     // public-disk relative, e.g. annotations/xxx.jpg
            $table->string('label');                          // reuse Detection::STATUSES keys
            $table->json('bbox')->nullable();                 // [x,y,w,h] normalized (YOLO); null = full-frame
            $table->string('status')->default('pending');     // pending, approved
            $table->string('source')->default('ai');          // ai (suggested), human (relabeled)
            $table->decimal('confidence', 5, 2)->nullable();  // carried from originating detection
            $table->timestamps();

            $table->index(['status', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotations');
    }
};
