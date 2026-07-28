<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly compliance audit result per site: the accessibility score and its
 * findings (ת"י 5568 / WCAG 2.2 AA, machine-checkable part) plus which legal
 * documents (privacy, terms, accessibility statement, returns) are missing.
 * jsonb — never `json`, which Postgres cannot compare.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->jsonb('compliance_scan')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('compliance_scan');
        });
    }
};
