<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Last-known state of the robotic arm. A single row (singleton, like settings)
 * kept in sync by the mqtt:listen consumer from "arm/status" telemetry, and
 * read back by the mobile app via GET /api/arm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arm_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('state')->default('idle'); // idle | running | error
            $table->string('detail')->nullable();      // human-readable note / error text
            $table->string('last_command')->nullable(); // last command topic/action we saw
            $table->json('telemetry')->nullable();      // raw extra fields from the arm
            $table->timestamp('reported_at')->nullable(); // when the arm last reported
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arm_statuses');
    }
};
