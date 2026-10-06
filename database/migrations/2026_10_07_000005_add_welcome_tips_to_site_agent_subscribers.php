<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tip a week in a number's first month: how many went out, and when the last.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_agent_subscribers', function (Blueprint $table) {
            $table->unsignedTinyInteger('tips_sent')->default(0);
            $table->timestamp('last_tip_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_agent_subscribers', function (Blueprint $table) {
            $table->dropColumn(['tips_sent', 'last_tip_at']);
        });
    }
};
