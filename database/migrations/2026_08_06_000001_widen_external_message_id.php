<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give the provider's message id room to be itself.
 *
 * WAHA returned an id longer than 255 characters, and the write that records
 * "this reply was sent" threw. The reply itself had ALREADY gone out — so the
 * job's own guard against re-sending was never set, the job retried, and the
 * customer received the same message again on every attempt before it finally
 * landed in failed_jobs.
 *
 * The column is the dedupe key for inbound messages and the sent-marker for
 * outbound ones. Both jobs depend on it being writable, so the width is not a
 * formatting detail — it is what stops a customer being messaged three times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->string('external_message_id', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->string('external_message_id', 255)->nullable()->change();
        });
    }
};
