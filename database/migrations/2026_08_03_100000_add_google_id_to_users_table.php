<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * מזהה חשבון גוגל של המשתמש, אם הוא התחבר פעם אחת דרך גוגל.
 *
 * לא כתובת המייל: כתובת אפשר להעביר מחשבון לחשבון, ומזהה החשבון אצל גוגל הוא
 * קבוע. הוא נשמר בכניסה הראשונה ומאומת בכל אחת אחריה, כך שכתובת שהוסבה לחשבון
 * אחר אינה נכנסת בשקט לחשבון של מישהו אחר.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('google_id', 64)->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('google_id');
        });
    }
};
