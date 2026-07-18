<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Days the team works in a reduced capacity or handles urgent matters only.
 * Marked on the calendar and read by the agent so a new ticket's acknowledgement
 * sets the right expectation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('mode'); // ServiceMode: reduced | urgent_only
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_exceptions');
    }
};
