<?php

namespace App\Jobs;

use App\Services\SiteAgent\Evaluation\EvaluationRuns;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Exactly one synthetic scenario. Credentials are never serialized into a job. */
class RunSiteAgentEvaluationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public bool $failOnTimeout = true;

    public function __construct(public string $runId, public int $index) {}

    public function handle(EvaluationRuns $runs): void
    {
        $runs->process($this->runId, $this->index);
    }

    public function failed(?Throwable $error): void
    {
        // No raw process exception, input, or output is persisted or logged.
        app(EvaluationRuns::class)->failed($this->runId, $this->index);
    }
}
