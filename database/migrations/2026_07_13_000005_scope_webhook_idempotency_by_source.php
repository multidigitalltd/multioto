<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make webhook idempotency source-scoped. external_id was globally unique, but
 * the three providers (Cardcom, WAHA, email) feed different id spaces into that
 * one column — a cross-source id collision would silently drop the second
 * delivery (e.g. a real Cardcom charge). Uniqueness is now (source, external_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->unique(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropUnique(['source', 'external_id']);
            $table->unique(['external_id']);
        });
    }
};
