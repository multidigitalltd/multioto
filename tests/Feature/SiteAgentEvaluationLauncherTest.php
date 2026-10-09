<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class SiteAgentEvaluationLauncherTest extends TestCase
{
    public function test_child_bootstraps_without_touching_inherited_database_or_exposing_credentials(): void
    {
        $directory = sys_get_temp_dir().'/evaluation-launcher-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory.'/production-sentinel.sqlite';
        $output = $directory.'/report.json';
        file_put_contents($database, 'Do not open or change this production database sentinel.');
        $before = hash_file('sha256', $database);
        try {
            $process = new Process([PHP_BINARY, base_path('scripts/site-agent-evaluate.php'), '--platform', '--preflight', '--output='.$output], base_path(), [
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database,
                'DB_URL' => 'sqlite:'.$database, 'CACHE_STORE' => 'redis', 'MAIL_MAILER' => 'smtp',
                'QUEUE_CONNECTION' => 'sync', 'AI_API_KEY' => 'inherited-secret-must-not-appear',
            ], json_encode(['ai' => ['enabled' => true, 'provider' => 'google', 'model' => 'gemini-3.1-flash-lite',
                'api_key' => 'stdin-secret-must-not-appear'], 'assistant' => []]), 60);
            $process->run();
            $this->assertSame(3, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame($before, hash_file('sha256', $database));
            $this->assertFileExists($output);
            $serialized = file_get_contents($output);
            $report = json_decode($serialized, true, 128, JSON_THROW_ON_ERROR);
            $this->assertSame(['total' => 752, 'passed' => 0, 'failed' => 0, 'blocked' => 752], $report['summary']);
            $this->assertSame(0, $report['provider_requests']);
            $this->assertSame('2026-10-09T12:00:00+03:00', $report['benchmark_time']);
            foreach (['inherited-secret-must-not-appear', 'stdin-secret-must-not-appear'] as $secret) {
                $this->assertStringNotContainsString($secret, $serialized.$process->getOutput().$process->getErrorOutput());
            }
            $this->assertStringNotContainsString($database, $serialized);
        } finally {
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
