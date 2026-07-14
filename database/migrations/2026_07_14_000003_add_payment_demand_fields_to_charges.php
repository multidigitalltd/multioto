<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment-demand fields on charges. A "payment demand" is a pending one-off
 * charge whose Cardcom link we route through our own domain so it can be voided,
 * and which may carry a Linet proforma (חשבונית עסקה) issued up front.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            // The absolute Cardcom hosted-page URL for this demand. We hand the
            // customer a signed link on our own domain that redirects here, so a
            // canceled demand can show "לא פעיל" instead of forwarding to pay.
            $table->string('cardcom_pay_url')->nullable()->after('cardcom_low_profile_id');
            // The Linet proforma (חשבונית עסקה) issued when the demand is created,
            // distinct from the fiscal tax invoice/receipt issued after payment.
            $table->string('proforma_document_id')->nullable()->after('cardcom_pay_url');
            $table->string('proforma_pdf_url')->nullable()->after('proforma_document_id');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn(['cardcom_pay_url', 'proforma_document_id', 'proforma_pdf_url']);
        });
    }
};
