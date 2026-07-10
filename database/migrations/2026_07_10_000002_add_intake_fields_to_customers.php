<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('contact_name', 120)->nullable()->after('name');
            $table->string('address')->nullable()->after('phone');
            $table->string('payment_method', 30)->nullable()->after('address'); // credit_card | standing_order | bank_transfer
            $table->timestamp('terms_accepted_at')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['contact_name', 'address', 'payment_method', 'terms_accepted_at']);
        });
    }
};
