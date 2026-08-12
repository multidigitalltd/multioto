<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * עריכת הדוח שנשלח ללקוח — בלי לגעת בממצאים עצמם.
 *
 * הבדיקה היא צילום מצב, וזה בדיוק מה שהופך אותה לשווה משהו: מה שנמצא ביום
 * שהיא רצה נשאר רשום כפי שהוא. אבל המסמך שנשלח ללקוח אינו הבדיקה — הוא בחירה
 * מה להציג מתוכה, ולפעמים ממצא נכון פשוט אינו שייך לשיחה הזו.
 *
 * לכן שתי העמודות האלה שומרות **החלטות תצוגה** ולא ממצאים: מה לא להדפיס, ומה
 * להוסיף בטקסט חופשי. הממצא המוסתר נשאר בבדיקה, נראה בפאנל וניתן להחזרה — כך
 * שאין מצב שבו מידע נעלם בלי שאיש יידע שהיה שם.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_audits', function (Blueprint $table): void {
            // מיקומי הממצאים שלא יודפסו (אינדקסים במערך findings, שאינו משתנה).
            $table->json('hidden_findings')->nullable()->after('summary');
            // מקטעים חופשיים שנוספו לדוח: [{title, body}]
            $table->json('extra_sections')->nullable()->after('hidden_findings');
        });
    }

    public function down(): void
    {
        Schema::table('site_audits', function (Blueprint $table): void {
            $table->dropColumn(['hidden_findings', 'extra_sections']);
        });
    }
};
