<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One outside-in inspection of a website, kept as its own record.
 *
 * Deliberately not hung off `sites`: the address typed in is usually somebody
 * who is NOT a customer yet, and that is the point — the tool exists to turn a
 * bare URL into something worth talking about. A site that IS in the system is
 * linked when the address matches, so the same page serves both.
 *
 * The findings are stored as they were found, with the date they were found on.
 * A report handed to a customer is a statement about a moment, and re-running
 * the checks to render an old document would quietly change what was said.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('url');
            $table->string('host')->index();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('running');
            $table->string('error', 2000)->nullable();
            $table->json('findings')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_audits');
    }
};
