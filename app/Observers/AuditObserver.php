<?php

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Records create/update/delete of audited models as team-action audit entries —
 * but only when a panel user is signed in (system/queue writes are not team
 * actions, so they are not attributed to anyone and are skipped). Registered per
 * model in AppServiceProvider.
 */
class AuditObserver
{
    /**
     * Changed-attribute keys that are noise, never worth an audit line on their
     * own: timestamps, plus the sign-in flow's bookkeeping on the user row (the
     * 2FA one-time code and remember token change on EVERY login, and used to
     * spam the log with a "עודכן משתמש" line before each התחברות entry).
     */
    private const IGNORED_KEYS = [
        'updated_at', 'created_at',
        'two_factor_code', 'two_factor_expires_at', 'two_factor_last_sent_at',
        'two_factor_attempts', 'remember_token',
    ];

    public function created(Model $model): void
    {
        AuditLog::record('created', 'נוצר '.$this->label($model), $model);
    }

    public function updated(Model $model): void
    {
        $changes = collect($model->getChanges())
            ->except(self::IGNORED_KEYS)
            ->all();

        if ($changes === []) {
            return; // only timestamps changed — nothing meaningful to audit
        }

        AuditLog::record('updated', 'עודכן '.$this->label($model), $model, $changes);
    }

    public function deleted(Model $model): void
    {
        AuditLog::record('deleted', 'נמחק '.$this->label($model), $model);
    }

    /** A short human label for the subject: "לקוח «name»" / "אתר «domain»" / … */
    private function label(Model $model): string
    {
        $noun = match (class_basename($model)) {
            'Customer' => 'לקוח',
            'Site' => 'אתר',
            'Subscription' => 'מנוי',
            'Charge' => 'חיוב',
            'Plan' => 'תוכנית',
            'Task' => 'משימה',
            'Ticket' => 'פנייה',
            'User' => 'משתמש',
            'NotificationTemplate' => 'תבנית הודעה',
            'PaymentToken' => 'אמצעי תשלום',
            default => class_basename($model),
        };

        $title = $model->name
            ?? $model->domain
            ?? $model->subject
            ?? $model->title
            ?? ('#'.$model->getKey());

        return "{$noun} «{$title}»";
    }
}
