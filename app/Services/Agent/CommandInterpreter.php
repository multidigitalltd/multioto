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

    /**
     * @param  string  $source  AgentCommand::SOURCE_* — which console this came
     *                          from. Instructions from the WhatsApp management
     *                          group have no user, so the source is what keeps
     *                          the group's conversation threaded and separate
     *                          from every panel operator's.
     * @param  int|null  $taskId  the existing task this instruction came from
     *                            (delegated with "סוכן משימה 7"), so the agent
     *                            works on it rather than opening it a second time
     */
    public function run(
        string $instruction,
        ?int $userId = null,
        string $source = AgentCommand::SOURCE_PANEL,
        ?int $taskId = null,
    ): AgentCommand {
        $instruction = trim($instruction);

        // Every turn is threaded with the recent conversation, so both an
        // ordinary follow-up ("make it warmer", "and also suspend his site") AND
        // a multi-step clarification keep the full chain — the original request,
        // each answer, and the agent's own questions are all stored turns, so
        // context is reconstructed from history rather than a single prior row.
        $effective = $this->withConversationContext($instruction, $userId, $source);

        // Which question this turn is answering, if any. Two clarification
        // flows can be answered with the same word ("מחר"), and the words alone
        // would then look like the same request — so the reply is identified by
        // the question it belongs to as well. Read BEFORE this turn is
        // recorded, and unchanged by a retry of the same answer.
        $answering = $this->openQuestionId($userId, $source);

        $command = AgentCommand::create([
            'user_id' => $userId,
            'source' => $source,
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
            $result = $this->agent->run(
                $effective,
                $userId,
                $source,
                $taskId,
                // The RAW instruction, never $effective: the conversation
                // context prepended to it changes with every turn, so a retry
                // of the same words would not look like the same request.
                requestKey: $this->requestKey($instruction, $userId, $source, $answering),
            );
        } catch (\Throwable $e) {
            return $this->finish($command, AgentCommandOutcome::Failed, 'הפעולה נכשלה: '.Str::limit($e->getMessage(), 160));
        }

        $command->customer_id = $result['customer_id'] ?? null;
        $command->ticket_id = $result['ticket_id'] ?? null;
        $command->site_id = $result['site_id'] ?? null;
        $command->pending_action_id = $result['proposed'][0] ?? null;

        $summary = trim((string) ($result['summary'] ?? ''));
        $proposed = $result['proposed'] ?? [];

        // Work the agent already carried out (open_task and assign_task both act
        // immediately). It is named in every outcome below, so the operator —
        // and the next turn, which is threaded with this result — can see it is
        // done and must not be asked for again.
        $done = $this->body(
            $this->namedTasks($result['opened'] ?? [], 'נפתחו משימות'),
            $this->namedTasks($result['updated'] ?? [], 'עודכנו משימות'),
        );

        // The agent explicitly asked for something → needs clarification (continue).
        if (filled($result['clarification'] ?? null)) {
            return $this->finish($command, AgentCommandOutcome::Unclear, $this->body($done, (string) $result['clarification']));
        }

        // Actions were filed for approval.
        if ($proposed !== []) {
            $count = count($proposed);
            $body = $this->body($done, $summary, "הוגשו {$count} פעולות לאישור במסך אישורי האוטומציה.");

            return $this->finish($command, AgentCommandOutcome::Proposed, $body);
        }

        // No proposal and no question — the agent gave an answer / did a read-only
        // lookup. Terminal (not a clarification), so the next command starts fresh.
        // The summary is the model's own words and may be as vague as "בוצע",
        // so the opened tasks are named here too rather than trusted to it —
        // this line is what the operator reads and what the next turn is
        // threaded with, and without it the same task can be opened again.
        if ($summary !== '') {
            return $this->finish($command, AgentCommandOutcome::Dispatched, $this->body($done, $summary));
        }

        $reason = trim((string) ($result['error'] ?? ''));

        // The closing AI turn produced nothing — but a task was already opened
        // or assigned, and its notification already sent. Calling that a failure
        // invites the operator to repeat the instruction, and the repeat opens a
        // second identical task. What happened is reported instead of what didn't.
        if ($done !== '') {
            return $this->finish($command, AgentCommandOutcome::Dispatched, $this->body(
                $done,
                '',
                'תקציר הסוכן לא התקבל (תקלה בספק ה-AI), אבל הפעולה עצמה בוצעה — אין צורך לחזור על ההוראה.',
            ));
        }

        $message = 'לא התקבלה תשובה מהסוכן — בדקו את חיבור ה-AI ("סוכן AI ← בדיקת חיבור").';

        if ($reason !== '') {
            $message .= "\n\nסיבה מספק ה-AI: ".Str::limit($reason, 200);
        }

        return $this->finish($command, AgentCommandOutcome::Failed, $message);
    }

    /**
     * One line naming tasks this run acted on, or '' if none.
     *
     * @param  list<array{id: int, title: string}>|mixed  $tasks
     */
    private function namedTasks(mixed $tasks, string $lead): string
    {
        if (! is_array($tasks) || $tasks === []) {
            return '';
        }

        $names = collect($tasks)
            ->map(fn (array $task): string => '#'.$task['id'].' '.Str::limit((string) $task['title'], 80))
            ->implode(', ');

        return $lead.': '.$names.'.';
    }

    /** Join the non-empty parts of a result body into one message. */
    private function body(string ...$parts): string
    {
        return trim(implode("\n\n", array_filter($parts, fn (string $p): bool => trim($p) !== '')));
    }

    /**
     * A short, stable fingerprint of one request: the same words from the same
     * console mean the same request, so a manager retyping an instruction after
     * a run died is recognised as a repeat rather than as new work. Whitespace
     * is normalised because a retype is rarely character-identical.
     */
    private function requestKey(string $instruction, ?int $userId, string $source, ?int $answering): string
    {
        $normalised = trim((string) preg_replace('/\s+/u', ' ', $instruction));

        return substr(hash(
            'sha256',
            $source.'|'.((string) $userId).'|'.((string) $answering).'|'.$normalised,
        ), 0, 32);
    }

    /**
     * The last question the agent asked in this thread and has not been asked
     * again since — what an answer given now belongs to.
     *
     * Only a turn that FINISHED as a question counts. Every turn is recorded
     * with "unclear" as its provisional outcome, so a run still in flight — or
     * one whose worker was killed halfway — would otherwise look like a fresh
     * question and shift the key, which is exactly when a retry must find the
     * task the interrupted run already opened. A finished question always has
     * the question itself as its result; a provisional row has nothing.
     */
    private function openQuestionId(?int $userId, string $source): ?int
    {
        return AgentCommand::query()
            ->where('source', $source)
            ->when(
                $userId !== null,
                fn ($query) => $query->where('user_id', $userId),
                fn ($query) => $query->whereNull('user_id'),
            )
            ->where('role', 'user')
            ->where('outcome', AgentCommandOutcome::Unclear)
            ->whereNotNull('result')
            ->where('result', '<>', '')
            ->latest('id')
            ->value('id');
    }

    /**
     * Prepend the recent conversation so an ordinary follow-up keeps its thread
     * (the agent sees what was just discussed). Context only — the agent is told
     * not to re-run past actions. Bounded to the last few turns to stay cheap.
     */
    private function withConversationContext(string $instruction, ?int $userId, string $source): string
    {
        $recent = AgentCommand::query()
            ->where('source', $source)
            // One thread per operator in the panel; one shared thread for the
            // WhatsApp group, which has no user of its own.
            ->when(
                $userId !== null,
                fn ($query) => $query->where('user_id', $userId),
                fn ($query) => $query->whereNull('user_id'),
            )
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
