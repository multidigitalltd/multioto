<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_resolutions', function (Blueprint $table): void {
            // The agent's incident memory: what problem was treated, on which
            // site, with which fix — and whether the follow-up verification
            // confirmed the problem actually went away. Feeds future
            // investigations on the same site AND across sites.
            $table->id();
            $table->foreignId('site_id')->index();
            // Snapshot (survives site deletion/rename); 255 matches sites.domain
            // so a copy can never overflow this column.
            $table->string('domain');
            $table->text('problem');
            $table->string('fix_tool', 190)->nullable();
            $table->string('fix_summary', 500)->nullable();
            $table->boolean('verified')->default(false);
            $table->foreignId('action_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_resolutions');
    }
};
