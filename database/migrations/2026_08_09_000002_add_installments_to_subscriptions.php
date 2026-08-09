<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * פריסת תשלומים: מנוי שיודע כמה תשלומים יש לו, ונסגר בסוף.
 *
 * חוב של 7,000 ₪ שנפרס ל-14 תשלומים חודשיים נראה בדיוק כמו מנוי — אותו סכום,
 * אותו תאריך, אותו מנגנון גבייה ודאנינג. ההבדל היחיד הוא שיש לו סוף, ובלי
 * שהמערכת יודעת עליו הסוף תלוי בכך שמישהו יזכור לבטל בחודש הארבעה-עשר. מי
 * שישכח יגבה מהלקוח כסף שאינו חייב, וזו טעות שמתגלה אצל הלקוח ולא אצלנו.
 *
 * נשמר כאן רק המספר הכולל. כמה כבר שולמו נספר מהחיובים עצמם ולא מעמודה
 * נפרדת: שני מקורות לאותה עובדה נפרדים זה מזה בדיוק ביום שבו זה יקר.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedSmallInteger('installments_total')->nullable()->after('billing_interval');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('installments_total');
        });
    }
};
