<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-site change journal ("sandbox"): every action the agent applies to a
 * site is recorded here with a before-snapshot that makes it reversible, so we
 * always know exactly what changed on a site and can roll it back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // The approval that authorised this change, when there was one.
            $table->foreignId('pending_action_id')->nullable()->constrained()->nullOnDelete();
            $table->string('summary');
            $table->string('tool')->nullable();
            $table->json('arguments')->nullable();
            // Enough of the prior state to undo the change (a value, a file, a
            // diff, an exported row set…). Kept as text so any shape fits.
            $table->longText('before_state')->nullable();
            $table->longText('after_state')->nullable();
            $table->string('status')->default('applied')->index();
            $table->string('initiated_by')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_changes');
    }
};
