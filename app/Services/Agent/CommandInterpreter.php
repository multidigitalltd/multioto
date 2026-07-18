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

    public function run(string $instruction, ?int $userId = null): AgentCommand
    {
        $instruction = trim($instruction);

        // Every turn is threaded with the recent conversation, so both an
        // ordinary follow-up ("make it warmer", "and also suspend his site") AND
        // a multi-step clarification keep the full chain — the original request,
        // each answer, and the agent's own questions are all stored turns, so
        // context is reconstructed from history rather than a single prior row.
        $effective = $this->withConversationContext($instruction, $userId);

        $command = AgentCommand::create([
            'user_id' => $userId,
            'role' => 'user',
            'instruction' => $instruction, // the raw turn; context is passed to the agent only
            'outcome' => AgentCommandOutcome::Unclear,
        ]);

        if ($instruction === '') {
            return $this->finish($command, AgentCommandOutcome::Unclear, 'לא הוזנה הוראה.');
        }

        if (! $this->ai->isEnabled()) {
            return $this->finish($command, AgentCommandOutcome::Failed, 'סוכן ה-AI כבוי או ללא מפתח — הפעילו אותו בהגדרות "סוכן AI".');
        }

        try {
            // Pass the operator's user id so any async work the agent kicks off
            // (e.g. a background site investigation) can post its result back
            // into THIS chat thread when it finishes, not only to the event log.
            $result = $this->agent->run($effective, $userId);
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

        $reason = trim((string) ($result['error'] ?? ''));
        $message = 'לא התקבלה תשובה מהסוכן — בדקו את חיבור ה-AI ("סוכן AI ← בדיקת חיבור").';

        if ($reason !== '') {
            $message .= "\n\nסיבה מספק ה-AI: ".Str::limit($reason, 200);
        }

        return $this->finish($command, AgentCommandOutcome::Failed, $message);
    }

    /**
     * Prepend the recent conversation so an ordinary follow-up keeps its thread
     * (the agent sees what was just discussed). Context only — the agent is told
     * not to re-run past actions. Bounded to the last few turns to stay cheap.
     */
    private function withConversationContext(string $instruction, ?int $userId): string
    {
        if ($userId === null) {
            return $instruction;
        }

        $recent = AgentCommand::query()
            ->where('user_id', $userId)
            ->latest('id')
            ->limit(6)
            ->get()
            ->reverse();

        if ($recent->isEmpty()) {
            return $instruction;
        }

        $lines = $recent->map(function (AgentCommand $c): string {
            if ($c->role === 'system') {
                return 'מערכת: '.Str::limit((string) $c->result, 300);
            }

            return 'מנהל: '.Str::limit($c->instruction, 400)
                .(filled($c->result) ? "\nסוכן: ".Str::limit((string) $c->result, 400) : '');
        })->implode("\n");

        return "שיחה קודמת עם המנהל (להקשר: אל תחזור על פעולות שכבר בוצעו, אבל כן השלם בקשות שנשארו פתוחות — למשל אם שאלת שאלה וקיבלת עכשיו תשובה):\n{$lines}"
            ."\n\nההודעה הנוכחית מהמנהל:\n{$instruction}";
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
