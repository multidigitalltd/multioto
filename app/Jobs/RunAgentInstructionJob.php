<?php

namespace App\Jobs;

use App\Enums\AgentCommandOutcome;
use App\Models\AgentCommand;
use App\Models\Task;
use App\Services\Agent\CommandInterpreter;
use App\Services\Waha\WahaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Hand one instruction from the WhatsApp management group to the console agent
 * and send its answer back to the group.
 *
 * Off the ingestion job on purpose: the agent reasons over several AI turns and
 * may take a while, and the ingestion job retries. A retry here would run the
 * agent a second time and file the same proposals twice, so this job does NOT
 * retry — a failure is reported to the group instead, where a person can simply
 * ask again.
 */
class RunAgentInstructionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Must stay UNDER the queue's retry_after (90s by default — config/queue.php
     * for both the redis and database connections). A run that outlives the
     * reclaim window can be picked up by a second worker, and with tries = 1
     * that second run would call the agent again: duplicate proposals, and a
     * failure message racing the original's success. Cut off at the deadline
     * instead — failed() releases the task and tells the group, which is honest
     * and happens exactly once. Raise this only together with retry_after.
     */
    public int $timeout = 80;

    /**
     * This run's own name on the task's holder list, minted at dispatch so the
     * fresh instance the worker builds for failed() gives back the SAME hold.
     * A run in progress is work in progress: without a hold of its own, a site
     * investigation that finishes mid-run would hand the task back while this
     * agent is still reasoning — and it may still queue another check or file a
     * proposal. Class-level default (not promoted) so a payload serialized
     * before this field unserializes safely.
     */
    public ?string $runToken = null;

    public function __construct(
        public string $chatId,
        public string $instruction,
        public ?int $taskId = null,
    ) {
        $this->runToken = (string) Str::uuid();
    }

    public function handle(CommandInterpreter $interpreter, WahaClient $waha): void
    {
        $release = false;
        $this->holdTask();

        try {
            $release = $this->work($interpreter, $waha);
        } finally {
            $release ? $this->releaseTask() : $this->dropHold();
        }
    }

    /** @return bool whether the task should go back to the humans now */
    private function work(CommandInterpreter $interpreter, WahaClient $waha): bool
    {
        $command = $interpreter->run(
            $this->instruction,
            userId: null,
            source: AgentCommand::SOURCE_WHATSAPP,
            // The instruction IS the task's own text when it was delegated, so
            // the agent is told which task it is working on — otherwise "if
            // there is no tool for it, open a task" opens a copy of it.
            taskId: $this->taskId,
        );

        // "In progress" means an agent is working on it. Two outcomes end the
        // run without that being true any more:
        //
        //   Failed  — the interpreter turns a disabled AI, a thrown agent and
        //             an empty answer into a record rather than an exception,
        //             so failed() never runs for exactly those.
        //   Unclear — the agent stopped to ask a question. The next move is a
        //             person's, and the answer arrives as a fresh instruction
        //             that carries no task id, so holding the task here would
        //             strand it: still claimed, never released, and the claim
        //             requires "open" to delegate it again.
        //
        // An answer with nothing filed for approval is the same situation: the
        // agent said its piece and there is no proposal for anyone to act on,
        // so the work is a person's again. Left "in progress" it would drop out
        // of the open list and out of the reminders — claimed forever by an
        // agent that has already finished.
        //
        // Whether anything the run started is STILL running is not decided
        // here: releaseIfIdle() refuses while a background hold or an undecided
        // proposal is on the task, and that job hands it back when it is done.
        $nothingFiled = $command->outcome === AgentCommandOutcome::Dispatched
            && $command->pending_action_id === null;

        $release = $nothingFiled
            || in_array($command->outcome, [AgentCommandOutcome::Failed, AgentCommandOutcome::Unclear], true);

        try {
            $waha->sendMessage($this->chatId, $this->message($command));
        } catch (\Throwable $e) {
            // Best-effort on purpose. The agent's work is done and recorded on
            // the console thread; a transient WAHA error must not be mistaken
            // for an agent failure, because failed() would then release a task
            // whose proposals already exist and invite the group to re-delegate
            // work that succeeded.
            Log::warning('RunAgentInstructionJob: result delivery failed', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
        }

        return $release;
    }

    /** This run is working on the task for as long as it lasts. */
    private function holdTask(): void
    {
        if ($this->taskId !== null && $this->runToken !== null) {
            Task::hold($this->taskId, $this->runToken);
        }
    }

    /** Stop holding, without deciding anything about the task's status. */
    private function dropHold(): void
    {
        if ($this->taskId !== null && $this->runToken !== null) {
            Task::dropHold($this->taskId, $this->runToken);
        }
    }

    /**
     * Give a delegated task back to the humans. Only from "in progress": if
     * someone marked it done while the agent was running, that decision is
     * newer than ours and must not be undone.
     */
    private function releaseTask(): void
    {
        if ($this->taskId === null) {
            return;
        }

        $this->dropHold();

        // Never takes back a task this run handed to background work: failed()
        // runs on a timeout too — including one that happens after a site
        // investigation was queued — and reopening it then would let the same
        // task be delegated a second time while the investigation is still
        // running. The other holders give it back when they are done.
        Task::releaseIfIdle($this->taskId);
    }

    /** The group's answer: what the agent produced, plus how to continue. */
    private function message(AgentCommand $command): string
    {
        $prefix = $this->taskId !== null ? "משימה #{$this->taskId} — " : '';
        $body = trim((string) $command->result);

        if ($body === '') {
            $body = 'הסוכן סיים בלי תשובה.';
        }

        // A question needs an answer routed back to the agent, and plain group
        // chatter is never treated as one — so say exactly how to reply.
        if ($command->outcome === AgentCommandOutcome::Unclear) {
            return $prefix.'🤖 '.$body."\n\nלהמשך השיבו: *סוכן <התשובה שלכם>*"
                .($this->taskId !== null ? "\n(המשימה חזרה למצב פתוח בינתיים.)" : '');
        }

        if ($command->outcome === AgentCommandOutcome::Proposed) {
            return $prefix.'🤖 '.$body."\n\nלאישור: *אשר <מספר>* · לדחייה: *דחה <מספר>*";
        }

        return $prefix.'🤖 '.$body;
    }

    /**
     * The agent never got to answer. Say so in the group rather than leaving the
     * instruction hanging, and release a task that was marked as being worked on.
     */
    public function failed(\Throwable $e): void
    {
        $this->releaseTask();

        Log::warning('RunAgentInstructionJob failed', ['error' => $e->getMessage()]);

        try {
            app(WahaClient::class)->sendMessage(
                $this->chatId,
                '🤖 הסוכן לא הצליח להשלים את ההוראה. נסו שוב, או בדקו את חיבור ה-AI במסך "סוכן AI".',
            );
        } catch (\Throwable) {
            // Nothing more to do — the attempt is in the log and the console.
        }
    }
}
