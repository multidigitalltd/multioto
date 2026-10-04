<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "אמת שאתה אנושי" — שתי עמודות, אחת להיסטוריה ואחת כדי לא להציק.
 *
 * `monitor_checks.challenge` — מי הציג את דף האימות באותה בדיקה (cloudflare,
 * sucuri, captcha…). נשמר על כל בדיקה, כך שאפשר לראות מתי זה התחיל ומתי נגמר
 * ולא רק את המצב כרגע.
 *
 * `sites.challenge_alerted_at` — מתי התרענו על המצב הנוכחי. אותה תבנית בדיוק של
 * `slow_alerted_at`: התראה אחת בכניסה למצב, התראה אחת ביציאה ממנו, ושקט בין
 * לבין. אתר שמוגן בקביעות היה מייצר התראה כל חמש דקות בלעדיה.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_checks', function (Blueprint $table) {
            $table->string('challenge', 40)->nullable()->after('error');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('challenge_alerted_at')->nullable()->after('slow_alerted_at');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_checks', function (Blueprint $table) {
            $table->dropColumn('challenge');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('challenge_alerted_at');
        });
    }
};
