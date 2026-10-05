<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the site agent is sold with: messages paid for by the count, and a
 * free week before the first charge.
 *
 * site_agent_usage is the ledger behind the message line on an invoice — one row
 * per reply the bot actually delivered, never a running counter. A counter can
 * be incremented twice by a retried job and nobody can tell; a row carries
 * Meta's own message id, which is unique, and the charge that billed it, so
 * "which 1,240 messages were these?" always has an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_agent_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_agent_subscriber_id')->nullable()->constrained()->nullOnDelete();

            // Meta's id for the delivered message. The same reply can never be
            // counted twice, whatever retries upstream.
            $table->string('provider_message_id')->nullable()->unique();

            // Decided when sent: a message during a free trial, or on a plan
            // that does not price messages, is never billed later.
            $table->boolean('billable')->default(true);

            // The charge that billed it. Null until a renewal collects it.
            $table->foreignId('charge_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('sent_at');
            $table->timestamps();

            // "Unbilled messages of this subscription up to now", at every renewal.
            $table->index(['subscription_id', 'charge_id', 'sent_at']);
            $table->index(['customer_id', 'sent_at']);
        });

        Schema::table('plans', function (Blueprint $table) {
            // Per message the bot sends, before VAT. Null = messages are not billed.
            $table->unsignedInteger('message_price_agorot')->nullable()->after('extra_number_price_agorot');

            // Free days before the first charge. 0 = no trial.
            $table->unsignedSmallInteger('trial_days')->default(0)->after('message_price_agorot');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable()->after('status');
            // The "your trial ends in two days" email, sent once.
            $table->timestamp('trial_reminded_at')->nullable()->after('trial_ends_at');
        });

        Schema::table('site_agent_orders', function (Blueprint $table) {
            // A trial order captures a card and charges nothing: it is matched
            // back on the hosted card page's id rather than on a charge.
            $table->unsignedSmallInteger('trial_days')->default(0)->after('total_agorot');
            $table->string('cardcom_low_profile_id', 64)->nullable()->index()->after('charge_id');
        });
    }

    public function down(): void
    {
        Schema::table('site_agent_orders', fn (Blueprint $table) => $table->dropColumn(['trial_days', 'cardcom_low_profile_id']));
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn(['trial_ends_at', 'trial_reminded_at']));
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn(['message_price_agorot', 'trial_days']));
        Schema::dropIfExists('site_agent_usage');
    }
};
