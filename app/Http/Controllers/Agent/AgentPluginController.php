<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The remote-update channel for the companion plugin. A site's plugin checks in
 * here with its per-site token; we record which version it runs and, when a
 * newer one is available, hand back a short-lived signed download link. This is
 * what lets us ship a new plugin version once and have every site update itself
 * — no manual re-install per site.
 */
class AgentPluginController extends Controller
{
    /** Update manifest: current version + a signed download link when newer. */
    public function update(Request $request): JsonResponse
    {
        /** @var Site $site */
        $site = $request->attributes->get('agentSite');

        $reported = (string) $request->query('version', $request->header('X-Agent-Plugin-Version', ''));
        $current = (string) config('agent.plugin.current_version');

        // Record the check-in: which version the site runs, and that it is alive.
        $site->forceFill([
            'agent_plugin_version' => $reported !== '' ? $reported : $site->agent_plugin_version,
            'mcp_last_seen_at' => now(),
        ])->save();

        $updateAvailable = $reported === '' || version_compare($current, $reported, '>');

        return response()->json([
            'version' => $current,
            'update_available' => $updateAvailable,
            'download_url' => $updateAvailable ? $this->signedDownloadUrl($current) : null,
            'changelog_url' => config('agent.plugin.changelog_url') ?: null,
        ]);
    }

    /** Serve a plugin release zip. Authenticated purely by the signed URL. */
    public function download(Request $request, string $version): SymfonyResponse
    {
        $disk = Storage::disk((string) config('agent.plugin.disk'));
        $path = trim((string) config('agent.plugin.path'), '/')."/{$version}.zip";

        if ($disk->exists($path)) {
            return $disk->download($path, "md-agent-{$version}.zip", [
                'Content-Type' => 'application/zip',
            ]);
        }

        // Fall back to the release committed in the repo, so a fresh deploy
        // serves the new build for site self-update without a separate upload
        // to the plugin disk. Same file the admin "הורד תוסף" button uses.
        $repoCopy = base_path("wordpress-plugin/releases/multioto-agent-{$version}.zip");

        abort_unless(is_file($repoCopy), 404);

        return response()->download($repoCopy, "md-agent-{$version}.zip", [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * Admin download of the current plugin build shipped in the repo, so a
     * manager can install it on a customer's site straight from the panel. Not
     * part of the site-facing update channel — authenticated by the panel login
     * plus an admin check.
     */
    public function latest(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()?->isAdmin() ?? false, 403);

        $version = (string) config('agent.plugin.current_version');
        $path = base_path("wordpress-plugin/releases/multioto-agent-{$version}.zip");

        abort_unless(is_file($path), 404, 'קובץ התוסף אינו זמין בשרת. יש לבנות אותו עם wordpress-plugin/build.sh.');

        return response()->download($path, "multioto-agent-{$version}.zip", [
            'Content-Type' => 'application/zip',
        ]);
    }

    /** A short-lived signed link to the given version's zip. */
    private function signedDownloadUrl(string $version): string
    {
        return URL::temporarySignedRoute(
            'agent.plugin.download',
            now()->addMinutes((int) config('agent.plugin.download_ttl_minutes', 15)),
            ['version' => $version],
        );
    }
}
