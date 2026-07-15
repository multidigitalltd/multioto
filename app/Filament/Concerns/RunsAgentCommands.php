<?php

namespace App\Filament\Concerns;

use App\Enums\AgentCommandOutcome;
use App\Models\AgentCommand;
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

        // If my last command needed clarification, treat this one as the answer
        // and continue it instead of starting over.
        $last = AgentCommand::query()
            ->where('user_id', auth()->id())
            ->latest('id')
            ->first();
        $continues = $last?->outcome === AgentCommandOutcome::Unclear ? $last : null;

        $command = app(CommandInterpreter::class)->run($instruction, auth()->id(), $continues);

        Notification::make()
            ->title($command->outcome->getLabel())
            ->body($command->result)
            ->{$command->outcome->value === 'failed' ? 'danger' : ($command->outcome->value === 'unclear' ? 'warning' : 'success')}()
            ->persistent()
            ->send();
    }
}
