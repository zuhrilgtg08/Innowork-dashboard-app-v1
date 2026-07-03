<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('qr_token', 40)->nullable()->unique()->after('qr_path'); // unguessable public token
        });

        // Backfill existing rows so their QR can encode a public URL.
        foreach (DB::table('products')->whereNull('qr_token')->pluck('id') as $id) {
            DB::table('products')->where('id', $id)->update(['qr_token' => Str::random(40)]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('qr_token');
        });
    }
};
