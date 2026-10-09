<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Track provider-reported cache reads separately, without changing total input. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['ai_usage_daily', 'ai_usage_customers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('cached_input_tokens')->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (['ai_usage_daily', 'ai_usage_customers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('cached_input_tokens');
            });
        }
    }
};
