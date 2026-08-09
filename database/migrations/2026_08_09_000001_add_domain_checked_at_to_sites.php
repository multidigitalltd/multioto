<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * מתי תוקף הדומיין נבדק בפעם האחרונה מול הרישום.
 *
 * בלי התאריך הזה, "פג" על המסך הוא אמירה שאי אפשר לבדוק: הבדיקה היומית משאירה
 * את הערך הישן כשהרישום לא עונה (וזו ההתנהגות הנכונה — עדיף ערך ישן מאשר
 * למחוק מידע בגלל תקלת רשת), אבל אז לקוח שחידש רואה במערכת "פג" בלי שום רמז
 * שהמספר הזה מלפני שבועיים. מה שנשמר כאן הוא מתי באמת התקבלה תשובה.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('domain_checked_at')->nullable()->after('domain_expiry_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('domain_checked_at');
        });
    }
};
