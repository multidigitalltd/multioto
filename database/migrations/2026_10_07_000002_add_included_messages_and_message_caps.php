<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages included in the plan, and a ceiling the customer sets on their own.
 *
 * - plans.included_messages: this many billable messages per invoice cost
 *   nothing; only the ones beyond are charged.
 * - subscriptions.site_agent_message_cap: the most messages the customer
 *   wants sent per cycle. Null = no ceiling. At the ceiling the bot stops
 *   sending until the next renewal; at 80% it says so once
 *   (site_agent_cap_warned_at).
 * - charges.usage_until: the cut-off of the messages a charge counted. Kept on
 *   the charge itself, not only on its messages line, because a charge whose
 *   messages were all included has no such line — and its messages must still
 *   be stamped as counted, or the next invoice counts them again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('included_messages')->default(0);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('site_agent_message_cap')->nullable();
            $table->timestamp('site_agent_cap_warned_at')->nullable();
        });

        Schema::table('charges', function (Blueprint $table) {
            $table->timestamp('usage_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn('usage_until');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['site_agent_message_cap', 'site_agent_cap_warned_at']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('included_messages');
        });
    }
};
