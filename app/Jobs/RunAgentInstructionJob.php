<?php

namespace App\Jobs;

use App\Enums\AgentCommandOutcome;
use App\Enums\TaskStatus;
use App\Models\AgentCommand;
use App\Models\Task;
use App\Services\Agent\CommandInterpreter;
use App\Services\Waha\WahaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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

    public int $timeout = 300;

    public function __construct(
        public string $chatId,
        public string $instruction,
        public ?int $taskId = null,
    ) {}

    public function handle(CommandInterpreter $interpreter, WahaClient $waha): void
    {
        $command = $interpreter->run(
            $this->instruction,
            userId: null,
            source: AgentCommand::SOURCE_WHATSAPP,
        );

        // The interpreter turns a disabled AI, a thrown agent and an empty
        // answer into a Failed record rather than an exception, so failed()
        // never runs for those — a delegated task would sit "in progress"
        // forever with nobody working on it.
        if ($command->outcome === AgentCommandOutcome::Failed) {
            $this->releaseTask();
        }

        $waha->sendMessage($this->chatId, $this->message($command));
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

        Task::whereKey($this->taskId)
            ->where('status', TaskStatus::InProgress)
            // reminded_at cleared here because a conditional update bypasses
            // TaskObserver, and a released task must be remindable again.
            ->update(['status' => TaskStatus::Open, 'reminded_at' => null]);
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
            return $prefix.'🤖 '.$body."\n\nלהמשך השיבו: *סוכן <התשובה שלכם>*";
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
