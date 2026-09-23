<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Watch rules the team can add from the panel, instead of a deploy.
 *
 * The two names that ship with the system (an admin nobody created, a browser
 * file manager) were found the hard way and hard coded. Everything the team
 * learns afterwards — the plugin that turned up on two customers last month,
 * the login an attacker likes — had nowhere to go but a config file and a
 * release, which in practice means it never got written down at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_rules', function (Blueprint $table) {
            $table->id();

            // 'user' (an exact wp login) or 'plugin' (an exact folder slug).
            $table->string('type', 16);

            // Stored lower-cased and trimmed, because that is how both the
            // inventory readers and the matcher normalise what they compare.
            $table->string('value', 190);

            // Why this is here. A rule nobody can explain is a rule nobody
            // dares remove, and the list then only ever grows.
            $table->string('note', 500)->nullable();

            $table->boolean('enabled')->default(true);

            // Who added it. This list decides what gets deactivated on a
            // customer's site, so "who said so" is part of the record.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One rule per name. Re-adding an existing one is an edit, never a
            // second row that a later removal would leave half-deleted.
            $table->unique(['type', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_rules');
    }
};
