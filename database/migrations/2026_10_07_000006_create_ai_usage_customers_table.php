<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI tokens per customer per day — the cost side of what a site-agent
 * customer pays. The daily table (ai_usage_daily) says what the AI costs in
 * total; this says who it was spent on, so a customer whose messages cost
 * more to answer than they bring in can be seen and priced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_customers', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('model', 120);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedInteger('requests')->default(0);
            $table->timestamps();

            $table->unique(['date', 'customer_id', 'provider', 'model']);
            $table->index(['customer_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_customers');
    }
};
