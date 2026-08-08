<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * מסמן ממצא אתר כ"טופל".
 *
 * עד כאן הממצאים נשלחו במייל ובקבוצת הניהול ונרשמו בעמוד האתר — אבל שום מסך לא
 * ידע לומר מה מתוכם עוד לא נבדק. בלי הסימון הזה אין חיווי: רשימה שכולה היסטוריה
 * אינה רשימת מטלות. משנרשם מי סימן ומתי, מה שנשאר ללא סימון הוא בדיוק מה שממתין.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_events', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable()->after('detected_at');
            $table->foreignId('acknowledged_by')->nullable()->after('acknowledged_at')
                ->constrained('users')->nullOnDelete();

            // החיווי שואל תמיד "מה לא טופל" — עמודה מסוננת בכל טעינת פאנל.
            $table->index(['acknowledged_at', 'severity']);
        });

        // ממצאים ישנים מסומנים כנצפו: כולם כבר נשלחו בזמנו במייל ובוואטסאפ, וחיווי
        // שנפתח ביום הראשון עם היסטוריה שלמה אינו חיווי אלא רעש. מה שזוהה
        // בשבועיים האחרונים כן עולה לבדיקה — ושום דבר לא נמחק: יומן הממצאים
        // המלא נשאר בעמוד האתר כפי שהיה.
        DB::table('site_events')
            ->whereNull('acknowledged_at')
            ->where('detected_at', '<', now()->subDays(14))
            ->update(['acknowledged_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('site_events', function (Blueprint $table) {
            $table->dropIndex(['acknowledged_at', 'severity']);
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropColumn('acknowledged_at');
        });
    }
};
