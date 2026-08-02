<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Something was alive at this moment" — one row per moving part.
 *
 * Almost everything this system does happens in a queued job dispatched by the
 * scheduler: charges, dunning, invoices, notifications, backups. When either
 * stops there is no error and no failed row — the silence looks exactly like a
 * quiet night, and it keeps looking that way until a customer asks why nobody
 * charged them.
 *
 * A stamp per beat is the smallest thing that turns that silence into a fact:
 * the scheduler stamps its own, and a job it dispatches stamps another when a
 * WORKER actually runs it. One row each, overwritten in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('health_heartbeats')) {
            return;
        }

        Schema::create('health_heartbeats', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->timestamp('beat_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_heartbeats');
    }
};
