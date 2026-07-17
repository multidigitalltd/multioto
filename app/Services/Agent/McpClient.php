<?php

namespace App\Services\Agent;

use App\Models\Site;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Thin MCP (Model Context Protocol) client for a site's companion-plugin
 * server, over Streamable HTTP: JSON-RPC 2.0 requests POSTed to the site's
 * endpoint, authenticated with the site's own secret. All business logic —
 * risk tiers, approvals, journaling — lives in the layers above; this class
 * only speaks the protocol.
 *
 * Servers may answer a POST either as plain JSON or as an SSE stream carrying
 * the JSON-RPC response in `data:` lines; both are handled.
 */
class McpClient
{
    /** The MCP protocol revision we speak. */
    public const PROTOCOL_VERSION = '2025-06-18';

    /**
     * Perform the MCP handshake: `initialize` followed by the
     * `notifications/initialized` notification. Returns the server's
     * capabilities/info block.
     *
     * @return array<string, mixed>
     */
    public function initialize(Site $site): array
    {
        $result = $this->request($site, 'initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'multioto', 'version' => (string) config('agent.plugin.current_version')],
        ]);

        $this->notify($site, 'notifications/initialized');

        return $result;
    }

    /**
     * The tools the site's server exposes.
     *
     * @return list<array<string, mixed>> each: name, description, inputSchema
     */
    public function listTools(Site $site): array
    {
        $tools = [];
        $cursor = null;

        // Paginated per the spec; loop until the server stops returning a cursor.
        do {
            $result = $this->request($site, 'tools/list', array_filter(['cursor' => $cursor]));
            $tools = array_merge($tools, (array) ($result['tools'] ?? []));
            $cursor = $result['nextCursor'] ?? null;
        } while (is_string($cursor) && $cursor !== '');

        return $tools;
    }

    /**
     * Invoke a tool on the site. Returns the raw MCP result (content blocks +
     * optional isError/structuredContent). A tool-level failure is surfaced as
     * an McpError so callers never mistake it for success.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function callTool(Site $site, string $name, array $arguments = []): array
    {
        $result = $this->request($site, 'tools/call', [
            'name' => $name,
            'arguments' => (object) $arguments,
        ]);

        if (($result['isError'] ?? false) === true) {
            throw new McpError("הכלי {$name} החזיר שגיאה: ".Str::limit($this->textContent($result), 300));
        }

        return $result;
    }

    /** Concatenated text blocks of a tools/call result — the human-readable output. */
    public function textContent(array $result): string
    {
        return collect((array) ($result['content'] ?? []))
            ->filter(fn ($block): bool => is_array($block) && ($block['type'] ?? '') === 'text')
            ->pluck('text')
            ->implode("\n");
    }

    /**
     * One JSON-RPC request/response exchange.
     *
     * @param  array<string, mixed>|object  $params
     * @return array<string, mixed>
     */
    protected function request(Site $site, string $method, array|object $params = []): array
    {
        $response = $this->post($site, [
            'jsonrpc' => '2.0',
            'id' => (string) Str::uuid(),
            'method' => $method,
            'params' => $params === [] ? (object) [] : $params,
        ]);

        $payload = $this->decode($response);

        if (isset($payload['error'])) {
            $code = is_numeric($payload['error']['code'] ?? null) ? (int) $payload['error']['code'] : null;

            throw new McpError(
                'שגיאת MCP מהאתר: '.Str::limit((string) ($payload['error']['message'] ?? 'unknown'), 300),
                $code,
            );
        }

        $result = $payload['result'] ?? null;

        if (! is_array($result)) {
            throw new McpError('תשובת MCP לא תקינה (אין result)');
        }

        return $result;
    }

    /** Fire-and-forget JSON-RPC notification (no id, no response expected). */
    protected function notify(Site $site, string $method): void
    {
        $this->post($site, ['jsonrpc' => '2.0', 'method' => $method]);
    }

    /** POST a JSON-RPC message to the site's endpoint with its credentials. */
    protected function post(Site $site, array $message): Response
    {
        if (blank($site->mcp_endpoint)) {
            throw new McpError('לא הוגדרה כתובת MCP לאתר');
        }

        $request = Http::timeout((int) config('agent.mcp.timeout_seconds', 30))
            ->withHeaders([
                'Accept' => 'application/json, text/event-stream',
                'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
            ]);

        if (filled($site->mcp_secret)) {
            // Present the secret as a Bearer token AND in a custom header. Some
            // hosts (Apache/CGI/LiteSpeed) strip the Authorization header before
            // it reaches WordPress; the plugin reads this fallback header in that
            // case, so the same secret still authenticates.
            $request = $request
                ->withToken($site->mcp_secret)
                ->withHeaders(['X-Md-Agent-Secret' => $site->mcp_secret]);
        }

        $response = $request->post($site->mcp_endpoint, $message);

        if ($response->failed()) {
            throw new McpError($this->describeHttpFailure($response));
        }

        return $response;
    }

    /**
     * Turn an HTTP failure into an actionable Hebrew message. The common
     * real-world case is a CDN/WAF — most often Cloudflare — challenging our
     * server-to-server request with a JavaScript "Just a moment…" page (HTTP
     * 403/503). Our platform is a server and cannot solve a browser challenge,
     * so the fix is on the site's side (bypass the challenge for the agent
     * endpoint / allow-list the panel), not a wrong secret. Naming the cause
     * saves the operator from chasing the credentials instead.
     */
    protected function describeHttpFailure(Response $response): string
    {
        $status = $response->status();
        $server = Str::lower((string) $response->header('Server'));
        $body = $response->body();

        $isChallenge = filled($response->header('cf-mitigated'))
            || (in_array($status, [403, 503], true)
                && Str::contains($server, 'cloudflare')
                && Str::contains($body, ['Just a moment', 'challenge-platform', 'cf-chl', '__cf_chl']));

        if ($isChallenge) {
            return 'החיבור נחסם על ידי Cloudflare באתר (אתגר "Just a moment"). הפאנל הוא שרת ואינו יכול לפתור אתגר JavaScript. '
                .'יש להגדיר ב-Cloudflare של האתר חריגה שתעקוף את האתגר לכתובת ‎/wp-json/md-agent/*‎ (או להתיר את כתובת ה-IP של הפאנל).';
        }

        if (in_array($status, [401, 403], true)) {
            return "שרת ה-MCP של האתר דחה את החיבור (HTTP {$status}). ודאו שמפתח ה-MCP בתוסף זהה למפתח שבפאנל, ושכותרת Authorization אינה נחסמת בשרת.";
        }

        return "שרת ה-MCP של האתר החזיר HTTP {$status}";
    }

    /**
     * Decode a response body that is either plain JSON or an SSE stream whose
     * `data:` lines carry the JSON-RPC response.
     *
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        if (Str::contains((string) $response->header('Content-Type'), 'text/event-stream')) {
            foreach (explode("\n", $response->body()) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $decoded = json_decode(trim(substr($line, 5)), true);

                    // The stream may carry several events; the response to our
                    // request is the first object bearing a result or error.
                    if (is_array($decoded) && (isset($decoded['result']) || isset($decoded['error']))) {
                        return $decoded;
                    }
                }
            }

            throw new McpError('זרם ה-SSE מהאתר לא הכיל תשובת JSON-RPC');
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new McpError('תשובת ה-MCP מהאתר אינה JSON תקין');
        }

        return $decoded;
    }
}
