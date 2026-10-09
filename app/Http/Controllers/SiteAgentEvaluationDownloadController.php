<?php

namespace App\Http\Controllers;

use App\Services\SiteAgent\Evaluation\EvaluationRuns;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A private, administrator-only JSON export that never enters Livewire's download buffer. */
final class SiteAgentEvaluationDownloadController extends Controller
{
    public function __invoke(string $run, EvaluationRuns $runs): StreamedResponse
    {
        return response()->streamDownload(
            $runs->streamReport($run),
            'site-agent-evaluation-'.$run.'.json',
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }
}
