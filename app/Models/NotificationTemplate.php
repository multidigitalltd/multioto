<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An operator-editable outbound message template (email or WhatsApp). Rows are
 * seeded from TemplateEngine::DEFAULTS and edited in the admin panel; rendering
 * substitutes {{placeholders}} at send time. Disabling a row silences that
 * notification without deleting the text.
 */
class NotificationTemplate extends Model
{
    protected $fillable = ['key', 'channel', 'subject', 'body', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
