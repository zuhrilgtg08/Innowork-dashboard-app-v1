<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expo push tokens per device, so the backend can notify operators about
 * conveyor anomalies and finished training runs.
 *
 * The token — not (user, device) — is the unique key: a physical device keeps
 * the same Expo token when a different operator signs in on it, and the
 * notification must follow the account that owns the token *now*. Registering
 * an existing token therefore re-points it at the current user instead of
 * creating a second row that would double-send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('platform', 20)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
