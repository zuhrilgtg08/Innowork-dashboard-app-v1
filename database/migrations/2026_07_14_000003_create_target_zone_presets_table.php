<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Target-zone presets: a joint-angle recipe per product category. In Opsi A
 * (no Jetson Nano) there is no on-device inverse-kinematics solver, so the
 * backend ships these presets and the ESP32 simply replays the angles it
 * receives on "arm/command". Values are placeholders until the team tunes them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('target_zone_presets', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();       // lookup key (Str::slug of category)
            $table->string('category')->nullable(); // product category label this maps to
            $table->string('label');                // human label for the target zone
            $table->json('joint_angles');           // 6-axis arm angles [j1..j6]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('target_zone_presets');
    }
};
