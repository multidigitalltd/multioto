<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which build a licence was sold with.
 *
 * Without this the system cannot answer "let me download it again" for anybody
 * who bought the plugin outright. Handing them the newest build would give away
 * the updates they chose not to buy; handing them nothing leaves a paying
 * customer unable to reinstall software they own. Both are wrong, and the only
 * way out is to remember what was actually delivered.
 *
 * Null on every licence issued before today, and on any product that had no
 * published build at the time. That is said on screen rather than guessed at —
 * "we don't have a record of which version you received" is a sentence somebody
 * can act on; silently serving the wrong zip is not.
 *
 * The key RESTRICTS rather than nulls, and that is the whole guarantee. A
 * release stops being current the moment a newer one is published, and old
 * builds are deletable from the panel — so nulling on delete would quietly
 * erase the record for exactly the licences that depend on it, the perpetual
 * ones, and leave a paying customer with no download at all. The database
 * refuses; the panel explains before anybody gets that far.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table): void {
            $table->foreignId('delivered_release_id')->nullable()->after('plugin_plan_id')
                ->constrained('plugin_releases')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delivered_release_id');
        });
    }
};
