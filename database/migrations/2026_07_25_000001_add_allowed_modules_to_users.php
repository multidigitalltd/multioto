<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // null = full access (default for existing users); a JSON list of
            // module keys (see App\Support\TeamModules) limits an agent to
            // those navigation groups. Admins always have everything.
            $table->json('allowed_modules')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('allowed_modules');
        });
    }
};
