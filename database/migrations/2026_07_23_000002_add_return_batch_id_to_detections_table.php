<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detections', function (Blueprint $table) {
            $table->foreignId('return_batch_id')->nullable()->after('product_id')
                ->constrained('return_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('detections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_batch_id');
        });
    }
};
