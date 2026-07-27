<?php

namespace App\Models;

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
    ];

    protected $fillable = ['site_id', 'type', 'severity', 'title', 'detail', 'detected_at'];

    protected function casts(): array
    {
        return ['detected_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
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
