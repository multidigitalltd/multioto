<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which plan IS the agent subscription.
 *
 * A flag on the plan rather than a name match. "האם המנוי הזה כולל את הסוכן"
 * is asked on every single message the customer sends, and answering it by
 * looking for a word in a plan's name means the day somebody renames a plan,
 * or opens a second one for a different price, the agent quietly stops
 * answering a paying customer — or starts answering one who never bought it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('includes_site_agent')->default(false)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('includes_site_agent');
        });
    }
};
