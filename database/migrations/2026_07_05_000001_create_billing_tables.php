<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core billing domain: customers, payment tokens, sites, plans,
 * subscriptions, charges, invoices and dunning events.
 *
 * All monetary amounts are stored as integer agorot (ILS only) — never floats.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('business_number')->nullable()->comment('ח.פ / מספר עוסק');
            $table->string('business_type')->default('exempt_dealer');
            $table->boolean('vat_exempt')->default(false);
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable()->index()->comment('E.164');
            $table->string('whatsapp_jid')->nullable()->index()->comment('WAHA chat id');
            $table->string('cardcom_account_id')->nullable();
            $table->string('status')->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('cardcom_token')->comment('Token reference only — never a PAN');
            $table->string('card_last4', 4)->nullable();
            $table->string('card_brand')->nullable();
            $table->unsignedTinyInteger('expiry_month')->nullable();
            $table->unsignedSmallInteger('expiry_year')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('default_token_id')->nullable()
                ->constrained('payment_tokens')->nullOnDelete();
        });

        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('domain')->index();
            $table->string('hosting_ref')->nullable()->comment('Hosting panel account/server id');
            $table->string('monitor_url')->nullable();
            $table->boolean('monitor_enabled')->default(false)->index();
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('price_agorot');
            $table->boolean('vat_applies')->default(true);
            $table->string('billing_interval')->default('monthly');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('token_id')->nullable()->constrained('payment_tokens')->nullOnDelete();
            $table->string('status')->default('active')->index();
            $table->date('current_period_start')->nullable();
            $table->date('current_period_end')->nullable();
            $table->dateTime('next_charge_at')->nullable()->index();
            $table->unsignedBigInteger('price_agorot_override')->nullable()
                ->comment('Locked legacy price; overrides plan price when set');
            $table->unsignedTinyInteger('dunning_stage')->default(0);
            $table->dateTime('canceled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_agorot');
            $table->unsignedBigInteger('vat_agorot')->default(0);
            $table->unsignedBigInteger('total_agorot');
            $table->string('currency', 3)->default('ILS');
            $table->string('status')->default('pending')->index();
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->string('cardcom_transaction_id')->nullable()->index();
            $table->string('cardcom_response_code')->nullable();
            $table->string('failure_reason')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->dateTime('charged_at')->nullable();
            $table->timestamps();

            // One attempt number per subscription+period — hard idempotency guard.
            $table->unique(['subscription_id', 'period_start', 'attempt_number'], 'charges_attempt_unique');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charge_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('linet_document_id')->index();
            $table->string('document_type')->default('tax_invoice_receipt');
            $table->string('allocation_number')->nullable()->comment('מספר הקצאה — רלוונטי רק מעל תקרת חשבונית ישראל');
            $table->string('vat_category')->default('taxable');
            $table->unsignedBigInteger('amount_agorot');
            $table->unsignedBigInteger('vat_agorot')->default(0);
            $table->unsignedBigInteger('total_agorot');
            $table->string('pdf_url')->nullable();
            $table->dateTime('issued_at');
            $table->timestamps();
        });

        Schema::create('dunning_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('stage');
            $table->string('channel');
            $table->string('template_key');
            $table->string('status')->default('queued')->index();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dunning_events');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('charges');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('sites');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('default_token_id'));
        Schema::dropIfExists('payment_tokens');
        Schema::dropIfExists('customers');
    }
};
