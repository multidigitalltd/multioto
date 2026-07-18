<?php

namespace App\Filament\Concerns;

use App\Services\Agent\CommandInterpreter;
use Filament\Notifications\Notification;

/**
 * Shared behaviour for the agent command input — used by the full console page
 * and by the compact command bar embedded on the dashboard and the tickets
 * screen. Keeps the "interpret → notify" step in one place so every entry point
 * behaves identically.
 */
trait RunsAgentCommands
{
    /** Interpret one instruction and surface the outcome as a notification. */
    protected function dispatchAgentCommand(string $instruction): void
    {
        $instruction = trim($instruction);

        if ($instruction === '') {
            return;
        }

        // The interpreter threads the recent conversation itself, so a follow-up
        // or a clarification answer continues the chat with no special handling
        // here.
        $command = app(CommandInterpreter::class)->run($instruction, auth()->id());

        Notification::make()
            ->title($command->outcome->getLabel())
            ->body($command->result)
            ->{$command->outcome->value === 'failed' ? 'danger' : ($command->outcome->value === 'unclear' ? 'warning' : 'success')}()
            ->persistent()
            ->send();
    }
}
