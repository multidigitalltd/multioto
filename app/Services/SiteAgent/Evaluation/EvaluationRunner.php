<?php

namespace App\Services\SiteAgent\Evaluation;

use App\Enums\BusinessType;
use App\Enums\CustomerStatus;
use App\Enums\SiteStatus;
use App\Models\AiUsage;
use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\Agent\McpClient;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\WhatsAppCloudClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/** Runs the real dialogue/application against a synthetic site and a live AI. */
final class EvaluationRunner
{
    private int $providerRequests = 0;

    private int $providerResponses = 0;

    private int $caseRequestStart = 0;

    private array $providerDiagnostics = [];

    private float $caseStarted;

    private float $started;

    public function __construct(private EvaluationWorld $world, private EvaluationOracle $oracle) {}

    public function run(array $cases, bool $live, ?callable $progress = null): array
    {
        $this->assertIsolation();
        $this->started = microtime(true);
        $key = (string) config('billing.ai.api_key');
        $configured = config('billing.ai.enabled') && $key !== '';
        $results = [];
        $networkError = null;
        if ($live && $configured) {
            try {
                $this->restrictNetwork();
            } catch (Throwable) {
                $networkError = 'כתובת ספק ה-AI אינה נתמכת בסביבת הבדיקה המבודדת.';
            }
        }

        if ($live && $configured && $networkError === null) {
            Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
            app()->instance(WhatsAppCloudClient::class, new EvaluationWhatsAppClient);
        }

        foreach ($cases as $case) {
            $this->world->reset();
            $missingTools = array_values(array_diff($case['expect']['tools_all'] ?? [], $this->world->supportedTools()));
            if (! $live || ! $configured || $networkError !== null || $missingTools !== []) {
                $result = [
                    'id' => $case['id'], 'domain' => $case['domain'], 'title' => $case['title'],
                    'status' => 'blocked', 'model_executed' => false, 'execution' => 'not_executed',
                    'provider_requests' => 0, 'provider_responses' => 0,
                    'reason' => $missingTools !== [] ? 'fixture_missing_tools' : (! $live ? 'preflight_only' : ($networkError !== null ? 'provider_not_allowed' : 'missing_ai_configuration')),
                    'failures' => $missingTools, 'turns' => [], 'expect' => $case['expect'],
                ];
            } else {
                $result = $this->scenario($case, $progress);
            }
            $results[] = $result;
            $progress?->__invoke(['event' => 'case_complete', 'id' => $case['id'], 'status' => $result['status']]);
        }

        $counts = array_count_values(array_column($results, 'status'));

        return [
            'schema_version' => 1, 'mode' => $live ? 'live_model_simulated_site' : 'preflight',
            'provider' => config('billing.ai.provider'), 'model' => config('billing.ai.model'),
            'corpus_sha256' => app(EvaluationCorpus::class)->fingerprint(),
            'started_at' => (new \DateTimeImmutable('@'.(int) $this->started))->format(DATE_ATOM),
            'benchmark_time' => now()->toIso8601String(), 'duration_seconds' => round(microtime(true) - $this->started, 3),
            'provider_requests' => $this->providerRequests, 'provider_responses' => $this->providerResponses,
            'execution_counts' => array_replace(['model' => 0, 'deterministic' => 0, 'not_executed' => 0],
                array_count_values(array_column($results, 'execution'))),
            'summary' => ['total' => count($cases), 'passed' => $counts['passed'] ?? 0,
                'failed' => $counts['failed'] ?? 0, 'blocked' => $counts['blocked'] ?? 0],
            'limits' => ['אתר WordPress וכלי האתר מדומים; הבדיקה אינה בדיקת תוספים באתר חי.',
                'מעבר פירושו שהבדיקות המוגדרות לתרחיש עברו. אין בכך הבטחה להבנת כל ניסוח או לאיכות כל טקסט חופשי.',
                'מטמון ספק ה-AI כבוי בהרצת הבדיקה; השימוש מחויב אצל הספק לפי הקריאות בפועל.'],
            'cases' => $results,
        ];
    }

    private function scenario(array $case, ?callable $progress): array
    {
        // IDs are reused after transaction rollback; page caches must not leak
        // a prior synthetic scenario into the next one.
        Cache::flush();
        $initial = $this->world->state;
        $turns = [];
        $exception = null;
        $requestsBefore = $this->providerRequests;
        $responsesBefore = $this->providerResponses;
        $this->caseRequestStart = $requestsBefore;
        $this->caseStarted = microtime(true);
        $this->providerDiagnostics = [];
        DB::beginTransaction();
        try {
            $customer = Customer::create(['name' => 'לקוח בדיקה מדומה', 'business_number' => '500000001',
                'business_type' => BusinessType::LicensedDealer, 'vat_exempt' => false,
                'email' => 'evaluation@example.test', 'phone' => '+972500000001', 'status' => CustomerStatus::Active]);
            $site = Site::create(['customer_id' => $customer->id, 'domain' => 'evaluation.example',
                'status' => SiteStatus::Active, 'monitor_enabled' => false, 'mcp_enabled' => true,
                'mcp_endpoint' => 'https://evaluation.example/wp-json/multioto/v1/mcp',
                'mcp_secret' => 'synthetic-evaluation-secret',
                'mcp_capabilities' => ['server' => ['version' => '1.12.0'], 'tools' => array_map(
                    fn (string $name): array => ['name' => $name], $this->world->supportedTools())]]);
            $subscriber = SiteAgentSubscriber::create(['customer_id' => $customer->id, 'site_id' => $site->id,
                'phone' => '972500000001', 'name' => 'נועה', 'verified_at' => now()]);
            app()->instance(McpClient::class, new EvaluationMcpClient($this->world, $site->id));
            $conversation = app(SiteAgentConversation::class);
            foreach ($case['turns'] as $index => $turn) {
                $before = $this->requests();
                $pending = collect($before)->last(fn (array $row): bool => $row['state'] === SiteAgentRequest::AWAITING);
                $word = mb_strtolower(trim($turn['user'], " \t\n\r\0\x0B.!?,־-"));
                $media = ($turn['media'] ?? false) === true;
                $approved = ! $media && filled($pending['preview'] ?? null)
                    && in_array($word, ['כן', 'אשר', 'אישור', 'מאשר', 'מאשרת', 'בצע', 'תבצע', 'אוקיי', 'אוקי', 'ok', 'yes', 'כן.', '👍'], true);
                $undo = ! $media && $pending === null
                    && in_array($word, ['בטל', 'תבטל', 'תחזיר', 'החזר', 'שחזר', 'undo'], true);
                $callOffset = count($this->world->calls);
                $reply = $conversation->handle($subscriber, $turn['user'], $case['id'].'-'.$index, $media ? 'fixture-image' : null);
                $turns[] = [
                    'user' => $turn['user'], 'media' => $media, 'reply' => $reply,
                    'calls' => array_slice($this->world->calls, $callOffset),
                    'before_request' => $pending, 'requests' => $this->requests(),
                    'approved' => $approved, 'undo' => $undo,
                ];
                $progress?->__invoke(['event' => 'turn_complete', 'id' => $case['id'], 'turn' => $index + 1]);
            }
            $usage = ['input_tokens' => (int) AiUsage::sum('input_tokens'), 'output_tokens' => (int) AiUsage::sum('output_tokens')];
            $failures = $this->oracle->evaluate($case, $turns, $initial, $this->world->state);
        } catch (Throwable $error) {
            // Do not export raw exceptions: HTTP errors can contain credentials.
            $exception = class_basename($error);
            $failures = ['runner_exception:'.$exception];
            $usage = [];
        } finally {
            DB::rollBack();
        }
        $requests = $this->providerRequests - $requestsBefore;
        $responses = $this->providerResponses - $responsesBefore;
        $modelExecuted = $responses > 0;
        $providerFailed = $requests !== $responses;
        // Only a curated corpus assertion permits a local guard to pass
        // without AI. A failed provider attempt can never use this exception.
        $deterministic = ($case['expect']['model_required'] ?? true) === false && $requests === 0 && $responses === 0;
        if (! $modelExecuted && ! $deterministic) {
            $failures[] = 'no_successful_model_response';
        }
        if ($providerFailed) {
            $failures[] = 'provider_transport_or_response_failure';
        }

        return ['id' => $case['id'], 'domain' => $case['domain'], 'title' => $case['title'],
            'status' => (! $modelExecuted && ! $deterministic) || $providerFailed ? 'blocked' : ($failures === [] ? 'passed' : 'failed'),
            'model_executed' => $modelExecuted, 'execution' => $modelExecuted ? 'model' : ($deterministic ? 'deterministic' : 'not_executed'),
            'failures' => $failures, 'semantic_review' => 'not_performed',
            'turns' => $turns, 'expect' => $case['expect'], 'final' => $this->world->state,
            'provider_requests' => $requests, 'provider_responses' => $responses,
            'provider_diagnostics' => $this->providerDiagnostics, 'usage' => $usage];
    }

    private function requests(): array
    {
        return SiteAgentRequest::query()->orderBy('id')->get()->map(fn (SiteAgentRequest $request): array => [
            'id' => $request->id, 'state' => $request->state, 'operation' => $request->operation,
            'preview' => $request->preview, 'plan' => $request->plan,
        ])->all();
    }

    private function assertIsolation(): void
    {
        if (! defined('SITE_AGENT_EVALUATION_ISOLATED') || SITE_AGENT_EVALUATION_ISOLATED !== true
            || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:'
            || config('cache.default') !== 'array' || config('mail.default') !== 'array'
            || config('queue.default') !== 'null' || DB::connection()->getDriverName() !== 'sqlite'
            || count(config('database.connections')) !== 1) {
            throw new RuntimeException('Evaluation requires the isolated launcher.');
        }
    }

    private function restrictNetwork(): void
    {
        $provider = config('billing.ai.provider');
        $base = rtrim((string) config('billing.ai.base_url'), '/');
        if ($provider === 'google') {
            $base = 'https://generativelanguage.googleapis.com';
            config(['billing.ai.base_url' => $base]);
        }
        $allowed = ['google' => 'generativelanguage.googleapis.com', 'anthropic' => 'api.anthropic.com', 'openai' => 'api.openai.com'];
        $host = parse_url($base, PHP_URL_HOST);
        if (! isset($allowed[$provider]) || $host !== $allowed[$provider]
            || parse_url($base, PHP_URL_SCHEME) !== 'https' || parse_url($base, PHP_URL_USER)
            || parse_url($base, PHP_URL_PASS) || parse_url($base, PHP_URL_PORT)) {
            throw new RuntimeException('Unsupported evaluation provider endpoint.');
        }
        Http::globalOptions(['allow_redirects' => false, 'connect_timeout' => 15]);
        // Plugin/theme apply checks perform a public health GET outside MCP.
        // This exact synthetic origin is always answered locally.
        Http::fake(['https://evaluation.example*' => Http::response('<html><body>Evaluation fixture</body></html>', 200)]);
        Http::globalRequestMiddleware(function (RequestInterface $request) use ($provider, $host): RequestInterface {
            $uri = $request->getUri();
            $path = $uri->getPath();
            if ($request->getMethod() === 'GET' && $uri->getScheme() === 'https'
                && $uri->getHost() === 'evaluation.example' && in_array($path, ['', '/'], true)
                && $uri->getQuery() === '' && $uri->getUserInfo() === '' && $uri->getPort() === null) {
                return $request;
            }
            $validPath = match ($provider) {
                'google' => preg_match('#^/v1beta/models/[A-Za-z0-9._-]+:generateContent$#D', $path) === 1,
                'anthropic' => $path === '/v1/messages',
                'openai' => $path === '/v1/chat/completions',
            };
            if ($request->getMethod() !== 'POST' || $uri->getScheme() !== 'https' || $uri->getHost() !== $host
                || $uri->getUserInfo() !== '' || ($uri->getPort() !== null && $uri->getPort() !== 443) || ! $validPath) {
                throw new RuntimeException('Evaluation blocked external traffic.');
            }
            if (++$this->providerRequests - $this->caseRequestStart > 80 || microtime(true) - $this->caseStarted > 1000) {
                throw new RuntimeException('Evaluation provider budget reached.');
            }

            return $request;
        });
        Http::globalMiddleware(function (callable $handler) use ($provider, $host): callable {
            return function (RequestInterface $request, array $options) use ($handler, $provider, $host): PromiseInterface {
                return $handler($request, $options)->then(function (ResponseInterface $response) use ($request, $provider, $host): ResponseInterface {
                    // Capture this request in its own promise: synthetic site
                    // health checks must never become provider diagnostics.
                    if ($request->getMethod() === 'POST' && $request->getUri()->getHost() === $host) {
                        $this->recordProviderResponse($response, $provider);
                    }

                    return $response;
                });
            };
        });
    }

    /** Export bounded status metadata only, never provider response text. */
    private function recordProviderResponse(ResponseInterface $response, string $provider): void
    {
        $body = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300 && is_array($body)) {
            $content = match ($provider) {
                'google' => data_get($body, 'candidates.0.content.parts'),
                'anthropic' => $body['content'] ?? null,
                'openai' => data_get($body, 'choices.0.message'),
            };
            if (is_array($content) && $content !== []) {
                $this->providerResponses++;

                return;
            }
        }

        if (count($this->providerDiagnostics) < 80) {
            $status = $response->getStatusCode();
            $finish = data_get($body, 'candidates.0.finishReason');
            $block = data_get($body, 'promptFeedback.blockReason');
            $allowed = ['STOP', 'MAX_TOKENS', 'SAFETY', 'RECITATION', 'OTHER', 'BLOCKLIST',
                'PROHIBITED_CONTENT', 'SPII', 'MALFORMED_FUNCTION_CALL', 'UNEXPECTED_TOOL_CALL'];
            $this->providerDiagnostics[] = ['http_status' => $status,
                'reason' => $status >= 200 && $status < 300 ? 'missing_model_content' : 'http_error',
                'finish_reason' => in_array($finish, $allowed, true) ? $finish : null,
                'block_reason' => in_array($block, $allowed, true) ? $block : null];
        }
    }
}
