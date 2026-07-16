<?php

namespace App\Services\Agent;

use App\Enums\AgentCommandOutcome;
use App\Models\AgentCommand;
use App\Services\Ai\ClaudeClient;
use Illuminate\Support\Str;

/**
 * The command-console front door: it records every instruction, drives the
 * free-reasoning ConsoleAgent (which investigates on its own and proposes
 * actions for approval), and maps the outcome back for the UI.
 *
 * Clarify-and-continue: when the agent asks for something it can't discover
 * (an amount, which of two customers), the command ends "needs clarification"
 * rather than a dead end — the operator's next message is MERGED with the
 * original and continues it (see $continues), so a half-given instruction is
 * refined, not restarted.
 */
class CommandInterpreter
{
    public function __construct(
        private ClaudeClient $ai,
        private ConsoleAgent $agent,
    ) {}

    public function run(string $instruction, ?int $userId = null, ?AgentCommand $continues = null): AgentCommand
    {
        $instruction = trim($instruction);

        // Carry the earlier attempt's full text AND what the agent replied, so a
        // clarification completes it with memory of the question it asked (and
        // further clarifications keep accumulating context).
        $effective = $continues && $instruction !== ''
            ? trim($continues->instruction
                .(filled($continues->result) ? "\n\n[מה שהסוכן השיב/שאל קודם: ".$continues->result.']' : '')
                ."\n\nהבהרה מהמפעיל: ".$instruction)
            : $instruction;

        $command = AgentCommand::create([
            'user_id' => $userId,
            'instruction' => $effective,
            'outcome' => AgentCommandOutcome::Unclear,
        ]);

        if ($instruction === '') {
            return $this->finish($command, AgentCommandOutcome::Unclear, 'לא הוזנה הוראה.');
        }

        if (! $this->ai->isEnabled()) {
            return $this->finish($command, AgentCommandOutcome::Failed, 'סוכן ה-AI כבוי או ללא מפתח — הפעילו אותו בהגדרות "סוכן AI".');
        }

        try {
            $result = $this->agent->run($effective);
        } catch (\Throwable $e) {
            return $this->finish($command, AgentCommandOutcome::Failed, 'הפעולה נכשלה: '.Str::limit($e->getMessage(), 160));
        }

        $command->customer_id = $result['customer_id'] ?? null;
        $command->ticket_id = $result['ticket_id'] ?? null;
        $command->site_id = $result['site_id'] ?? null;
        $command->pending_action_id = $result['proposed'][0] ?? null;

        $summary = trim((string) ($result['summary'] ?? ''));
        $proposed = $result['proposed'] ?? [];

        // The agent explicitly asked for something → needs clarification (continue).
        if (filled($result['clarification'] ?? null)) {
            return $this->finish($command, AgentCommandOutcome::Unclear, (string) $result['clarification']);
        }

        // Actions were filed for approval.
        if ($proposed !== []) {
            $count = count($proposed);
            $body = trim(($summary !== '' ? $summary."\n\n" : '')."הוגשו {$count} פעולות לאישור במסך אישורי האוטומציה.");

            return $this->finish($command, AgentCommandOutcome::Proposed, $body);
        }

        // No proposal and no question — the agent gave an answer / did a read-only
        // lookup. Terminal (not a clarification), so the next command starts fresh.
        if ($summary !== '') {
            return $this->finish($command, AgentCommandOutcome::Dispatched, $summary);
        }

        return $this->finish($command, AgentCommandOutcome::Failed, 'לא התקבלה תשובה מהסוכן — בדקו את חיבור ה-AI ("סוכן AI ← בדיקת חיבור").');
    }

    /** Persist the outcome + human result and return the record. */
    private function finish(AgentCommand $command, AgentCommandOutcome $outcome, string $result): AgentCommand
    {
        $command->outcome = $outcome;
        $command->result = $result;
        $command->save();

        return $command;
    }
}
