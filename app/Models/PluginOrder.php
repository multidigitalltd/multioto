<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One self-service purchase, from the moment somebody pressed "buy" to the
 * moment the key is in their inbox.
 *
 * It exists because the buyer leaves. They go to the payment page, and what
 * comes back is a webhook from Cardcom — by then the form, the session and the
 * browser tab are all gone. This row is what remembers what was bought, and it
 * is also the answer to "did I pay and get nothing?", which is the only support
 * question a checkout ever really generates.
 *
 * `license_id` being set IS the record that the order was fulfilled. A webhook
 * delivered twice therefore cannot issue a second licence.
 */
class PluginOrder extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    protected $fillable = [
        'plugin_product_id', 'plugin_plan_id', 'customer_id', 'charge_id', 'license_id',
        'buyer_name', 'buyer_email', 'buyer_phone',
        'sites_limit', 'billing_interval', 'total_agorot',
        'status', 'reference', 'fulfilled_at',
    ];

    protected function casts(): array
    {
        return [
            'sites_limit' => 'integer',
            'total_agorot' => 'integer',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(PluginProduct::class, 'plugin_product_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PluginPlan::class, 'plugin_plan_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /**
     * The handle the buyer's "thank you" page is addressed by.
     *
     * Random rather than the row id: the page shows what somebody bought, and a
     * sequential number in the address is an invitation to read the neighbours'.
     */
    public static function newReference(): string
    {
        return (string) Str::uuid();
    }

    public function isFulfilled(): bool
    {
        return $this->license_id !== null;
    }
}
