<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The card exemption, carried onto the customer it was granted for.
 *
 * Kept on the customer and not only on the invite, because this is the fact
 * every later question asks: whether to chase them for a card, whether the
 * integrity report should call their missing card a fault, and — when a
 * collection eventually fails — who decided we would have nothing to fall back
 * on. An exemption nobody can attribute afterwards is the same as no record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('card_exempt_at')->nullable()->after('security_card_terms_at');
            $table->string('card_exempt_reason', 500)->nullable()->after('card_exempt_at');
            $table->foreignId('card_exempt_by')->nullable()->after('card_exempt_reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('card_exempt_by');
            $table->dropColumn(['card_exempt_at', 'card_exempt_reason']);
        });
    }
};
