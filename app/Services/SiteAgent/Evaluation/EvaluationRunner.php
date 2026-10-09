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
use App\Services\Ai\GeminiContextCache;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\WhatsAppCloudClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
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

    private int $cacheManagementRequests = 0;

    private int $cacheManagementResponses = 0;

    private int $cacheReferenceRetries = 0;

    private int $caseManagementStart = 0;

    private int $caseRequestStart = 0;

    private array $providerDiagnostics = [];

    private array $cacheDiagnostics = [];

    private array $pendingCacheRetries = [];

    private array $providerUsage = ['input_tokens' => 0, 'cached_input_tokens' => 0, 'uncached_input_tokens' => 0,
        'output_tokens' => 0, 'cache_hit_requests' => 0];

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
        $usage = [];
        foreach (array_keys($this->providerUsage) as $metric) {
            $usage[$metric] = array_sum(array_map(fn (array $result): int => $result['usage'][$metric] ?? 0, $results));
        }

        return [
            'schema_version' => 1, 'mode' => $live ? 'live_model_simulated_site' : 'preflight',
            'provider' => config('billing.ai.provider'), 'model' => config('billing.ai.model'),
            'corpus_sha256' => app(EvaluationCorpus::class)->fingerprint(),
            'started_at' => (new \DateTimeImmutable('@'.(int) $this->started))->format(DATE_ATOM),
            'benchmark_time' => now()->toIso8601String(), 'duration_seconds' => round(microtime(true) - $this->started, 3),
            'provider_requests' => $this->providerRequests, 'provider_responses' => $this->providerResponses,
            'cache_management_requests' => $this->cacheManagementRequests, 'cache_management_responses' => $this->cacheManagementResponses,
            'cache_reference_retries' => $this->cacheReferenceRetries, 'provider_usage' => $usage,
            'cache_mode' => $this->cacheMode(), 'cache_status' => app(GeminiContextCache::class)->status(),
            'execution_counts' => array_replace(['model' => 0, 'deterministic' => 0, 'not_executed' => 0],
                array_count_values(array_column($results, 'execution'))),
            'summary' => ['total' => count($cases), 'passed' => $counts['passed'] ?? 0,
                'failed' => $counts['failed'] ?? 0, 'blocked' => $counts['blocked'] ?? 0],
            'limits' => ['אתר WordPress וכלי האתר מדומים; הבדיקה אינה בדיקת תוספים באתר חי.',
                'מעבר פירושו שהבדיקות המוגדרות לתרחיש עברו. אין בכך הבטחה להבנת כל ניסוח או לאיכות כל טקסט חופשי.',
                'מטמון Gemini משמש להוראות ולקטלוג כלים קבועים כאשר הוא מופעל ונתמך. שיחות ותוצאות כלים אינן נשמרות במטמון המשותף.',
                'ספירת טוקנים מהמטמון היא נתון שימוש מהספק ואינה חישוב חיסכון כספי; קריאות ואחסון מטמון מחויבים לפי תנאי הספק.'],
            'cases' => $results,
            ...(app(GeminiContextCache::class) instanceof EvaluationGeminiContextCache
                ? ['_cache_state' => app(GeminiContextCache::class)->exportState()] : []),
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
        $managementRequestsBefore = $this->cacheManagementRequests;
        $managementResponsesBefore = $this->cacheManagementResponses;
        $retriesBefore = $this->cacheReferenceRetries;
        $usageBefore = $this->providerUsage;
        $this->caseRequestStart = $requestsBefore;
        $this->caseManagementStart = $managementRequestsBefore;
        $this->caseStarted = microtime(true);
        $this->providerDiagnostics = [];
        $this->cacheDiagnostics = [];
        $this->pendingCacheRetries = [];
        DB::beginTransaction();
        try {
            $customer = Customer::create(['name' => 'לקוח בדיקה מדומה', 'business_number' => '500000001',
                'business_type' => BusinessType::LicensedDealer, 'vat_exempt' => false,
                'email' => 'evaluation@example.test', 'phone' => '+972500000001', 'status' => CustomerStatus::Active]);
            $site = Site::create(['customer_id' => $customer->id, 'domain' => 'evaluation.example',
                'status' => SiteStatus::Active, 'monitor_enabled' => false, 'mcp_enabled' => true,
                'mcp_endpoint' => 'https://evaluation.example/wp-json/multioto/v1/mcp',
                'mcp_secret' => 'synthetic-evaluation-secret',
                'mcp_capabilities' => ['server' => ['version' => '1.12.1'], 'tools' => array_map(
                    fn (string $name): array => ['name' => $name], $this->world->supportedTools())]]);
            $subscriber = SiteAgentSubscriber::create(['customer_id' => $customer->id, 'site_id' => $site->id,
                'phone' => '972500000001', 'name' => 'נועה', 'verified_at' => now()]);
            app()->instance(McpClient::class, new EvaluationMcpClient($this->world, $site->id));
            $proposer = app(EvaluationSiteActionProposer::class);
            app()->instance(SiteActionProposer::class, $proposer);
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
                $proposalOffset = count($proposer->diagnostics());
                $reply = $conversation->handle($subscriber, $turn['user'], $case['id'].'-'.$index, $media ? 'fixture-image' : null);
                $turns[] = [
                    'user' => $turn['user'], 'media' => $media, 'reply' => $reply,
                    'calls' => array_slice($this->world->calls, $callOffset),
                    'proposal_diagnostics' => array_slice($proposer->diagnostics(), $proposalOffset),
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
        $recoveredCacheRetries = $this->cacheReferenceRetries - $retriesBefore;
        $transportUsage = [];
        foreach ($this->providerUsage as $metric => $value) {
            $transportUsage[$metric] = $value - $usageBefore[$metric];
        }
        // Google includes cached prompt tokens in promptTokenCount. Never add
        // them to input a second time; preserve recorded usage for other APIs.
        $usage = config('billing.ai.provider') === 'google' ? $transportUsage
            : [...$usage, 'cached_input_tokens' => 0, 'uncached_input_tokens' => $usage['input_tokens'] ?? 0, 'cache_hit_requests' => 0];
        $modelExecuted = $responses > 0;
        $providerFailed = $requests !== $responses + $recoveredCacheRetries;
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
            'cache_management_requests' => $this->cacheManagementRequests - $managementRequestsBefore,
            'cache_management_responses' => $this->cacheManagementResponses - $managementResponsesBefore,
            'cache_reference_retries' => $recoveredCacheRetries,
            'cache_mode' => $this->cacheMode(), 'cache_status' => app(GeminiContextCache::class)->status(),
            'cache_diagnostics' => $this->cacheDiagnostics,
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
            $management = $this->isCacheManagementRequest($request);
            if ((! $management && ($request->getMethod() !== 'POST' || ! $validPath))
                || $uri->getScheme() !== 'https' || $uri->getHost() !== $host
                || $uri->getUserInfo() !== '' || ($uri->getPort() !== null && $uri->getPort() !== 443)) {
                throw new RuntimeException('Evaluation blocked external traffic.');
            }
            $overBudget = $management
                ? ++$this->cacheManagementRequests - $this->caseManagementStart > 6
                : ++$this->providerRequests - $this->caseRequestStart > 80;
            if ($overBudget || microtime(true) - $this->caseStarted > 1000) {
                throw new RuntimeException('Evaluation provider budget reached.');
            }

            return $request;
        });
        Http::globalMiddleware(function (callable $handler) use ($provider, $host): callable {
            return function (RequestInterface $request, array $options) use ($handler, $provider, $host): PromiseInterface {
                try {
                    $promise = $handler($request, $options);
                } catch (Throwable $error) {
                    $promise = Create::rejectionFor($error);
                }

                return $promise->then(function (ResponseInterface $response) use ($request, $provider, $host): ResponseInterface {
                    // Capture this request in its own promise: synthetic site
                    // health checks must never become provider diagnostics.
                    if ($request->getUri()->getHost() === $host) {
                        if ($this->isCacheManagementRequest($request)) {
                            $this->cacheManagementResponses++;
                            if (count($this->cacheDiagnostics) < 6) {
                                $this->cacheDiagnostics[] = ['operation' => $request->getMethod() === 'PATCH' ? 'renew' : 'create',
                                    'http_status' => $response->getStatusCode()];
                            }
                        } elseif ($request->getMethod() === 'POST') {
                            $this->recordProviderResponse($response, $provider, $request);
                        }
                    }

                    return $response;
                }, function (mixed $reason) use ($request, $host): PromiseInterface {
                    // A connection timeout has no HTTP response. Preserve that
                    // distinction without exporting exception text or credentials.
                    if ($request->getUri()->getHost() === $host) {
                        $this->recordTransportFailure($request, $reason);
                    }

                    return Create::rejectionFor($reason);
                });
            };
        });
    }

    private function recordTransportFailure(RequestInterface $request, mixed $reason): void
    {
        $context = $reason instanceof ConnectException || $reason instanceof RequestException
            ? $reason->getHandlerContext() : [];
        $kind = match ($context['errno'] ?? null) {
            28 => 'timeout',
            6 => 'dns',
            7 => 'connection',
            35, 51, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91 => 'tls',
            default => 'transport_error',
        };
        if ($this->isCacheManagementRequest($request)) {
            if (count($this->cacheDiagnostics) < 6) {
                $this->cacheDiagnostics[] = ['operation' => $request->getMethod() === 'PATCH' ? 'renew' : 'create',
                    'http_status' => null, 'reason' => 'transport_error', 'error_kind' => $kind];
            }

            return;
        }
        if ($request->getMethod() !== 'POST' || count($this->providerDiagnostics) >= 80) {
            return;
        }

        $payload = json_decode((string) $request->getBody(), true);
        $requestKind = data_get($payload, 'generationConfig.responseMimeType') === 'application/json'
            ? 'structured_output'
            : (isset($payload['cachedContent']) || isset($payload['tools']) ? 'tool_use' : 'text_generation');
        $this->providerDiagnostics[] = ['http_status' => null, 'reason' => 'transport_error',
            'error_kind' => $kind, 'request_kind' => $requestKind,
            'finish_reason' => null, 'block_reason' => null];
    }

    /** Export bounded status metadata only, never provider response text. */
    private function recordProviderResponse(ResponseInterface $response, string $provider, RequestInterface $request): void
    {
        $body = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300 && is_array($body)) {
            if ($provider === 'google') {
                $this->recordGoogleUsage($body);
            }
            $content = match ($provider) {
                'google' => data_get($body, 'candidates.0.content.parts'),
                'anthropic' => $body['content'] ?? null,
                'openai' => data_get($body, 'choices.0.message'),
            };
            if ($this->hasModelContent($provider, $content)) {
                $this->providerResponses++;
                if ($provider === 'google') {
                    $payload = json_decode((string) $request->getBody(), true);
                    if (is_array($payload) && ! array_key_exists('cachedContent', $payload)) {
                        $fingerprint = $this->retryFingerprint($request, $payload);
                        if (($this->pendingCacheRetries[$fingerprint] ?? 0) > 0) {
                            $this->pendingCacheRetries[$fingerprint]--;
                            $this->cacheReferenceRetries++;
                        }
                    }
                }

                return;
            }
        }

        $referenceFailure = false;
        if ($provider === 'google' && $this->cacheMode() === 'google_explicit') {
            $payload = json_decode((string) $request->getBody(), true);
            $reference = $payload['cachedContent'] ?? null;
            if (is_array($payload) && is_string($reference) && preg_match('#^cachedContents/[A-Za-z0-9_-]{1,256}$#D', $reference)
                && app(GeminiContextCache::class)->isReferenceFailure(new Response($response))) {
                $fingerprint = $this->retryFingerprint($request, $payload);
                $this->pendingCacheRetries[$fingerprint] = ($this->pendingCacheRetries[$fingerprint] ?? 0) + 1;
                $referenceFailure = true;
            }
        }

        if (count($this->providerDiagnostics) < 80) {
            $status = $response->getStatusCode();
            $finish = data_get($body, 'candidates.0.finishReason');
            $block = data_get($body, 'promptFeedback.blockReason');
            $allowed = ['STOP', 'MAX_TOKENS', 'SAFETY', 'RECITATION', 'OTHER', 'BLOCKLIST',
                'PROHIBITED_CONTENT', 'SPII', 'MALFORMED_FUNCTION_CALL', 'UNEXPECTED_TOOL_CALL'];
            $this->providerDiagnostics[] = ['http_status' => $status,
                'reason' => $referenceFailure ? 'cache_reference_rejected' : ($status >= 200 && $status < 300 ? 'missing_model_content' : 'http_error'),
                'finish_reason' => in_array($finish, $allowed, true) ? $finish : null,
                'block_reason' => in_array($block, $allowed, true) ? $block : null];
        }
    }

    private function hasModelContent(string $provider, mixed $content): bool
    {
        if (! is_array($content) || $content === []) {
            return false;
        }
        if ($provider !== 'google') {
            return true;
        }

        foreach ($content as $part) {
            if (! is_array($part)) {
                continue;
            }
            if (($part['thought'] ?? false) !== true && is_string($part['text'] ?? null) && trim($part['text']) !== '') {
                return true;
            }
            $call = $part['functionCall'] ?? null;
            if (is_array($call) && is_string($call['name'] ?? null) && trim($call['name']) !== '') {
                if (! array_key_exists('args', $call)) {
                    // The native protocol permits omitting empty arguments.
                    return true;
                }
                // JSON objects, including args:{}, decode to associative PHP arrays.
                // A nonempty JSON list is not a native function argument object.
                if (is_array($call['args']) && ($call['args'] === [] || ! array_is_list($call['args']))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function cacheMode(): string
    {
        if (! config('siteagent.assistant.cache.enabled')) {
            return 'disabled';
        }

        return config('billing.ai.provider') === 'google' ? 'google_explicit' : 'unsupported_provider';
    }

    private function isCacheManagementRequest(RequestInterface $request): bool
    {
        if ($this->cacheMode() !== 'google_explicit' || $request->getUri()->getQuery() !== '') {
            return false;
        }

        return ($request->getMethod() === 'POST' && $request->getUri()->getPath() === '/v1beta/cachedContents')
            || ($request->getMethod() === 'PATCH'
                && preg_match('#^/v1beta/cachedContents/[A-Za-z0-9_-]{1,256}$#D', $request->getUri()->getPath()) === 1);
    }

    /** Only an identical dynamic request may resolve a failed cache reference. */
    private function retryFingerprint(RequestInterface $request, array $payload): string
    {
        unset($payload['cachedContent'], $payload['systemInstruction'], $payload['tools']);

        return hash('sha256', $request->getUri()->getPath().'|'.json_encode($payload));
    }

    private function recordGoogleUsage(array $body): void
    {
        $input = $this->tokenCount(data_get($body, 'usageMetadata.promptTokenCount'));
        $cached = min($input, $this->tokenCount(data_get($body, 'usageMetadata.cachedContentTokenCount')));
        $total = $this->tokenCount(data_get($body, 'usageMetadata.totalTokenCount'));
        $this->providerUsage['input_tokens'] += $input;
        $this->providerUsage['cached_input_tokens'] += $cached;
        $this->providerUsage['uncached_input_tokens'] += $input - $cached;
        $this->providerUsage['output_tokens'] += max(0, $total - $input);
        $this->providerUsage['cache_hit_requests'] += $cached > 0 ? 1 : 0;
    }

    private function tokenCount(mixed $value): int
    {
        return is_int($value) && $value >= 0 && $value <= 100_000_000 ? $value : 0;
    }
}
