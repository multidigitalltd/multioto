<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "מחק מיד" על כלל מעקב.
 *
 * עד כה כלל שנוסף בפאנל נמצא ודווח, ואדם היה צריך להיכנס לאתר ולמחוק. העמודה
 * הזאת מסמנת את הכללים שהאתר מוחק לבד, ברגע שהם מופיעים.
 *
 * ברירת המחדל היא false, וזה לא סתם ברירת מחדל שמרנית: היא מה שמשאיר את ההחלטה
 * מפורשת. כלל נוסף כדי לחפש; מחיקה אוטומטית היא החלטה נפרדת שמסמנים בנפרד, על
 * אתר של לקוח.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_rules', function (Blueprint $table) {
            $table->boolean('auto_remove')->default(false)->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('security_rules', function (Blueprint $table) {
            $table->dropColumn('auto_remove');
        });
    }
};
