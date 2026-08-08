<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A durable, customer-showable monitoring finding for one site ("on 27/07 we
 * detected a new administrator"). Team alerts are transient; this is the record
 * the site page renders so the customer can be shown what we found and when.
 */
class SiteEvent extends Model
{
    /** Finding types, with the Hebrew label + icon used on the site page. */
    public const TYPES = [
        'admin_added' => ['👤', 'משתמש מנהל חדש'],
        'admin_removed' => ['👤', 'משתמש מנהל הוסר'],
        'plugin_added' => ['🧩', 'תוסף חדש הותקן'],
        'plugin_removed' => ['🧩', 'תוסף הוסר'],
        'theme_added' => ['🎨', 'תבנית חדשה הותקנה'],
        'theme_removed' => ['🎨', 'תבנית הוסרה'],
        'reputation' => ['🛡️', 'ממצא מוניטין'],
        'defacement' => ['🚨', 'חשד להשחתת אתר'],
        'vulnerability' => ['⚠️', 'פגיעות אבטחה'],
        'dns' => ['🌐', 'שינוי DNS'],
        'store_silent' => ['🛒', 'החנות הפסיקה לקבל הזמנות'],
        'store_payments' => ['💳', 'הזמנות שלא שולמו — חשד לתקלת סליקה'],
        'layout_broken' => ['🧱', 'מבנה העמוד נשבר'],
        'accessibility' => ['♿', 'ממצאי נגישות'],
        'legal_docs' => ['📄', 'מסמכים משפטיים חסרים'],
        'content_change' => ['✏️', 'עדכון תוכן לבקשת הלקוח'],
    ];

    /** Severities that are a finding to act on, as opposed to a logged fact. */
    public const ACTIONABLE_SEVERITIES = ['critical', 'warning'];

    protected $fillable = ['site_id', 'type', 'severity', 'title', 'detail', 'detected_at'];

    protected function casts(): array
    {
        return ['detected_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** מי סימן שהממצא טופל (ריק כל עוד לא טופל, או אם המשתמש נמחק מאז). */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * ממצאים שממתינים לצוות: אזהרה או קריטי שאיש עוד לא סימן כטופל.
     *
     * `info` נשאר בחוץ במכוון — עדכון תוכן שהלקוח ביקש הוא תיעוד, לא מטלה,
     * וחיווי שסופר גם אותו מפסיק להיות אמין תוך שבוע.
     */
    public function scopePendingReview(Builder $query): Builder
    {
        return $query
            ->whereNull('acknowledged_at')
            ->whereIn('severity', self::ACTIONABLE_SEVERITIES);
    }

    /** סימון "טופל" — נרשם עם השעה ועם מי שסימן, כדי שיהיה למי לפנות. */
    public function acknowledge(?User $user = null): void
    {
        $this->forceFill([
            'acknowledged_at' => now(),
            'acknowledged_by' => $user?->id,
        ])->save();
    }

    /** צבע Filament לפי חומרת הממצא — אותו מיפוי בכל מסך שמציג ממצאים. */
    public function severityColor(): string
    {
        return match ($this->severity) {
            'critical' => 'danger',
            'warning' => 'warning',
            default => 'gray',
        };
    }

    /**
     * Record a finding. Best-effort by design: a monitoring job must never fail
     * because the finding log could not be written.
     */
    public static function record(int $siteId, string $type, string $severity, string $title, ?string $detail = null): void
    {
        try {
            if (! Schema::hasTable('site_events')) {
                return;
            }

            static::create([
                'site_id' => $siteId,
                'type' => $type,
                'severity' => $severity,
                'title' => Str::limit($title, 240),
                'detail' => $detail === null ? null : Str::limit($detail, 2000),
                'detected_at' => now(),
            ]);
        } catch (\Throwable) {
            // Never let the finding log break a monitoring run.
        }
    }

    /** Emoji + Hebrew label for this finding type. */
    public function label(): string
    {
        [$icon, $label] = self::TYPES[$this->type] ?? ['•', $this->type];

        return "{$icon} {$label}";
    }
}
