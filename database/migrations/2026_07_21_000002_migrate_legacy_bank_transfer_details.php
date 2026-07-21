<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Payment demands used to read their bank-transfer details from a dedicated
 * setting (billing.bank_transfer_details). They now share the signup-form field
 * (signup.instructions.bank_transfer) as the single source of truth. Carry any
 * panel-stored legacy value across so an existing install keeps its configured
 * account without re-entering it, then retire the old key. (The .env variant,
 * BANK_TRANSFER_DETAILS, is preserved via a config fallback in config/billing.php.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $legacy = Setting::query()->whereKey('billing.bank_transfer_details')->first();

        if (! $legacy) {
            return;
        }

        // Only adopt the legacy value if the signup field hasn't been set yet —
        // never clobber a value the operator already entered there.
        if (filled($legacy->value)) {
            $target = Setting::query()->whereKey('signup.instructions.bank_transfer')->first();

            if (! $target || blank($target->value)) {
                Setting::put('signup.instructions.bank_transfer', $legacy->value);
            }
        }

        // The key is no longer read anywhere — remove the stale override.
        Setting::forget('billing.bank_transfer_details');
    }

    public function down(): void
    {
        // One-way data migration — the legacy key is intentionally not restored.
    }
};
