<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the team decided about one opportunity on one site.
 *
 * The radar re-derives its findings from scratch on every weekly scan, so a
 * judgement about them cannot live inside the findings themselves — it would be
 * overwritten every Sunday. It lives here instead, keyed by site + opportunity,
 * and outlives any number of rescans:
 *
 *   dismissed — not relevant for this site, stop showing it
 *   offered   — already quoted to the customer, waiting on them
 *
 * Deleting the row is the undo, which is why there is no third "open" status:
 * open is simply the absence of an opinion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunity_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('status');
            $table->string('reason')->nullable();
            // Who decided — so "why is this hidden?" has an answer with a name
            // on it. Kept if the user is later deleted.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            // One opinion per opportunity per site: marking an already-dismissed
            // finding as offered replaces the verdict rather than stacking on it.
            $table->unique(['site_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_notes');
    }
};
