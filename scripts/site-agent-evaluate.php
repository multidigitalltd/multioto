<?php

/**
 * Separate process only. Isolate BEFORE Laravel boots or reads saved settings.
 * Platform AI configuration arrives through stdin, never command arguments.
 */
declare(strict_types=1);
use App\Services\SiteAgent\Evaluation\EvaluationCorpus;
use App\Services\SiteAgent\Evaluation\EvaluationRunner;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';

$options = getopt('', ['live', 'platform', 'case:', 'suite:', 'output:', 'preflight']);
$root = dirname(__DIR__);
$runtime = sys_get_temp_dir().'/multioto-evaluation-'.bin2hex(random_bytes(12));
foreach (['', '/storage/framework/cache/data', '/storage/framework/views', '/storage/framework/sessions', '/storage/logs', '/storage/app/private', '/bootstrap'] as $part) {
    if (! mkdir($runtime.$part, 0700, true) && ! is_dir($runtime.$part)) {
        fwrite(STDERR, "Cannot create isolated evaluation storage.\n");
        exit(2);
    }
}

register_shutdown_function(static function () use ($runtime): void {
    if (! is_dir($runtime)) {
        return;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($runtime);
});

try {
    if (isset($options['platform'])) {
        $input = stream_get_contents(STDIN, 100001);
        if (strlen($input) > 100000) {
            throw new RuntimeException('Configuration too large.');
        }
        $settings = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($settings)) {
            throw new RuntimeException('Invalid platform settings.');
        }
        $ai = array_intersect_key((array) ($settings['ai'] ?? []), array_flip(['enabled', 'provider', 'model', 'base_url', 'api_key', 'effort']));
        $assistant = array_intersect_key((array) ($settings['assistant'] ?? []), array_flip([
            'persona', 'style', 'work_rules', 'instructions', 'history_messages', 'history_hours', 'history_chars',
            'max_turns', 'budget_seconds', 'tool_result_chars', 'disabled_permissions',
        ]));
        unset($input, $settings);
    } else {
        $environment = is_file($root.'/.env') ? Dotenv\Dotenv::parse(file_get_contents($root.'/.env')) : [];
        $value = static fn (string $name, mixed $default = null): mixed => getenv($name) !== false ? getenv($name) : ($environment[$name] ?? $default);
        $ai = ['enabled' => filter_var($value('AI_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'provider' => $value('AI_PROVIDER', 'google'), 'model' => $value('AI_MODEL', 'gemini-3.1-flash-lite'),
            'base_url' => $value('AI_BASE_URL', 'https://generativelanguage.googleapis.com'),
            'api_key' => $value('AI_API_KEY', ''), 'effort' => $value('AI_EFFORT', 'low')];
        $assistant = [];
        unset($environment, $value);
    }
    foreach (['provider', 'model', 'base_url', 'api_key', 'effort'] as $name) {
        if (isset($ai[$name]) && (! is_string($ai[$name]) || strlen($ai[$name]) > 16000)) {
            throw new RuntimeException('Invalid AI configuration.');
        }
    }

    $isolated = [
        'APP_ENV' => 'evaluation', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_DEBUG' => 'false',
        'APP_URL' => 'https://evaluation.example', 'APP_TIMEZONE' => 'Asia/Jerusalem',
        'APP_CONFIG_CACHE' => $runtime.'/bootstrap/config.php', 'APP_SERVICES_CACHE' => $runtime.'/bootstrap/services.php',
        'APP_PACKAGES_CACHE' => $runtime.'/bootstrap/packages.php', 'APP_ROUTES_CACHE' => $runtime.'/bootstrap/routes.php',
        'APP_EVENTS_CACHE' => $runtime.'/bootstrap/events.php', 'LARAVEL_STORAGE_PATH' => $runtime.'/storage',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'DATABASE_URL' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'null', 'MAIL_MAILER' => 'array',
        'LOG_CHANNEL' => 'null', 'VIEW_COMPILED_PATH' => $runtime.'/storage/framework/views',
        'FILESYSTEM_DISK' => 'local', 'AI_ENABLED' => 'false', 'AI_API_KEY' => '',
    ];
    foreach ($isolated as $name => $value) {
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
    define('SITE_AGENT_EVALUATION_ISOLATED', true);
    $app = require $root.'/bootstrap/app.php';
    $app->useEnvironmentPath($runtime);
    $app->useStoragePath($runtime.'/storage');
    $app->make(Kernel::class)->bootstrap();
    config([
        'database.default' => 'sqlite',
        'database.connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]],
        'cache.default' => 'array', 'queue.default' => 'null', 'queue.connections' => ['null' => ['driver' => 'null']],
        'mail.default' => 'array', 'mail.mailers' => ['array' => ['transport' => 'array']],
        'filesystems.default' => 'local', 'filesystems.disks' => ['local' => ['driver' => 'local', 'root' => $runtime.'/storage/app/private']],
        'siteagent.enabled' => true, 'siteagent.assistant.enabled' => true,
        'siteagent.assistant.cache.enabled' => false, 'siteagent.alerts.failure_email' => '',
        'billing.ai' => [...config('billing.ai'), ...$ai],
    ]);
    DB::purge('sqlite');
    foreach ($assistant as $name => $value) {
        config(['siteagent.assistant.'.$name => $value]);
    }
    unset($ai, $assistant);
    // Scheduling cases use a documented reference clock; request budgets use
    // monotonic wall time in the runner and are not extended by this clock.
    Carbon\Carbon::setTestNow(new CarbonImmutable('2026-10-09 12:00:00', 'Asia/Jerusalem'));
    CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-09 12:00:00', 'Asia/Jerusalem'));

    $cases = app(EvaluationCorpus::class)->cases($options['case'] ?? null, $options['suite'] ?? 'all');
    $report = app(EvaluationRunner::class)->run(
        $cases, isset($options['live']),
        static fn (array $progress) => fwrite(STDOUT, json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n"),
    );
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    if (isset($options['output'])) {
        $path = $options['output'];
        if (! is_string($path) || $path === '' || is_link($path) || ! is_dir(dirname($path))) {
            throw new RuntimeException('Invalid report output path.');
        }
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException('Cannot save evaluation report.');
        }
        chmod($temporary, 0600);
        rename($temporary, $path);
    }
    fwrite(STDOUT, json_encode(['event' => 'complete', 'summary' => $report['summary']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    exit($report['summary']['blocked'] > 0 ? 3 : ($report['summary']['failed'] > 0 ? 1 : 0));
} catch (Throwable $error) {
    // Neither input configuration nor raw provider/transport errors leave here.
    fwrite(STDERR, 'Evaluation could not complete ('.get_class($error).").\n");
    exit(2);
}
