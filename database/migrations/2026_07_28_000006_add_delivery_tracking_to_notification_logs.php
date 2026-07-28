<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened to an email AFTER we handed it to the provider.
 *
 * Until now the log recorded "queued" and stopped there — honest, but it left
 * the team unable to answer the first question anyone asks about a broadcast:
 * did it arrive, and did anyone read it. These columns are filled by the
 * provider's webhook, never guessed.
 *
 * broadcast_id ties a row back to the broadcast that produced it, so per-send
 * totals don't have to be inferred from "type = broadcast" plus a timestamp
 * window — which mixes two broadcasts sent the same afternoon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->foreignId('broadcast_id')->nullable()->after('customer_id')
                ->constrained()->nullOnDelete();

            // The provider's own id for the message — the only reliable key to
            // match an incoming event to the row it belongs to.
            $table->string('provider_message_id')->nullable()->index();

            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('complained_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);

            $table->index(['broadcast_id', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('broadcast_id');
            $table->dropColumn([
                'provider_message_id', 'delivered_at', 'opened_at',
                'bounced_at', 'complained_at', 'open_count',
            ]);
        });
    }
};
