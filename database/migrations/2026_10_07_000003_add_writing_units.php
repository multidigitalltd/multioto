<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Writing units: every approved change that puts more than 300 words of text
 * on the site — a post, a page section, a product description — is one unit,
 * billed like messages: an allowance included in the plan, a price for each
 * one beyond it.
 *
 * The same ledger as messages, told apart by `kind`, so a renewal settles
 * both with one cut-off and an invoice is never built from two sources that
 * could disagree. A unit's row is keyed `writing:{request id}`, so one approved
 * change is one unit however many times anything retries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_agent_usage', function (Blueprint $table) {
            $table->string('kind', 16)->default('message');
            $table->unsignedInteger('words')->nullable();
            $table->index(['subscription_id', 'kind', 'charge_id']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('writing_price_agorot')->nullable();
            $table->unsignedInteger('included_writings')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['writing_price_agorot', 'included_writings']);
        });

        Schema::table('site_agent_usage', function (Blueprint $table) {
            $table->dropIndex(['subscription_id', 'kind', 'charge_id']);
            $table->dropColumn(['kind', 'words']);
        });
    }
};
