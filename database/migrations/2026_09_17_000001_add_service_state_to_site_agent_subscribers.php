<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What this number was last TOLD about the service, as opposed to what is true.
 *
 * Entitlement is decided live on every message, so this column decides nothing.
 * It exists so the customer hears about a change once: the day the payment fails
 * they get one message saying the agent has stopped and the site has not, and
 * the day it is settled they get one saying it is back. Without somewhere to
 * remember what was already said, an hourly reconciliation is an hourly
 * repetition of the same bad news.
 *
 * Null means "never told" — a subscriber whose state has not been recorded yet
 * is recorded silently rather than greeted with news about a service they have
 * not started using.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_agent_subscribers', function (Blueprint $table) {
            $table->string('notified_service_state', 10)->nullable()->after('last_seen_at');
            $table->timestamp('notified_service_state_at')->nullable()->after('notified_service_state');
        });
    }

    public function down(): void
    {
        Schema::table('site_agent_subscribers', function (Blueprint $table) {
            $table->dropColumn(['notified_service_state', 'notified_service_state_at']);
        });
    }
};
