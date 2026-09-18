<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may drive a site from WhatsApp, and which site.
 *
 * The whole product rests on this row. A phone number that reaches the agent's
 * number is, on its own, nothing but a string an attacker can spoof-adjacent
 * to — so nothing happens until somebody proved they hold that number AND the
 * team bound it to a site. No inference, ever: an unrecognised number is told
 * it is unrecognised, never matched to "the customer whose phone looks like
 * this".
 *
 * One row per number per site, so a business with an owner and a manager gives
 * each of them their own, and revoking one does not revoke the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_agent_subscribers', function (Blueprint $table) {
            $table->id();

            // Digits only, country code included — the form the provider uses,
            // so the lookup on an inbound message is an exact match and never a
            // fuzzy one.
            $table->string('phone', 20)->index();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // What this person is called in the chat, and by the team.
            $table->string('name')->nullable();

            // Proof they hold the number. Stored HASHED: a plaintext code in a
            // column is a credential anybody with read access to the database —
            // or to a backup — can use to bind a number to somebody's site.
            $table->string('verification_code')->nullable();
            $table->timestamp('verification_sent_at')->nullable();
            // Guessing is bounded. Six digits is a million tries for a machine
            // and the code outlives a single message, so an unbounded counter
            // is the difference between "proves possession" and "eventually
            // falls over".
            $table->unsignedSmallInteger('verification_attempts')->default(0);
            $table->timestamp('verified_at')->nullable();

            // Switched off by a person, without deleting the history of what
            // this number did.
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // The same number may manage two different sites (an owner of two
            // businesses), but never the same site twice.
            $table->unique(['phone', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_agent_subscribers');
    }
};
