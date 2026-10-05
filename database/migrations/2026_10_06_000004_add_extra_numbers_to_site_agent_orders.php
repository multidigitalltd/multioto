<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra manager numbers chosen at checkout, not only added later.
 *
 * A business with a partner or an office manager decides who will be driving the
 * site while it is deciding whether to buy at all. Until now the only answer was
 * "add them afterwards from the personal area", which meant the price they were
 * shown was not the price of the thing they wanted.
 *
 * The numbers are kept on the ORDER rather than bound at once, for the same
 * reason nothing else here is granted at checkout: the buyer leaves for Cardcom
 * and may never come back. They become bindings in SiteAgentCheckout::grant,
 * each with its own verification code, once the money (or the card, on a trial)
 * has actually arrived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_agent_orders', function (Blueprint $table) {
            // Normalised phone numbers, as WhatsAppCloudClient writes them. Null
            // and [] mean the same thing and both are expected: a column added
            // to rows that predate it cannot be anything else.
            $table->json('extra_phones')->nullable()->after('manager_name');
        });
    }

    public function down(): void
    {
        Schema::table('site_agent_orders', function (Blueprint $table) {
            $table->dropColumn('extra_phones');
        });
    }
};
