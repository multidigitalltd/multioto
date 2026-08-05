<?php

namespace App\Services\System;

use Illuminate\Support\Carbon;

/**
 * Bridges the admin panel and a privileged host-side deploy agent WITHOUT the
 * web process ever executing a shell command (which the security standard
 * forbids). The panel only reads/writes small files in a host-mounted `ops`
 * directory; a cron watcher on the host (docker/deploy-watcher.sh) runs the
 * actual update when it sees a request flag.
 *
 * File contract (all inside the ops dir):
 *   version.json  — {sha, short, date} stamped by update.sh after each deploy
 *   deploy.request — presence means "please update" (written here)
 *   deploy.lock    — presence means the host agent is mid-update
 *   deploy.status  — {state, message, at} written by the host agent
 *   available.json — {behind, short, at, releases} when a newer build waits
 *   update-check.json — {at, ok, error} stamped on EVERY check, including the
 *                       ones that found nothing or failed. Without it, "no
 *                       update banner" and "nobody has looked in three weeks"
 *                       are the same screen.
 */
class DeployManager
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? base_path('ops'), '/');
    }

    /** Current deployed version, or null if it was never stamped. */
    public function currentVersion(): ?array
    {
        return $this->readJson('version.json');
    }

    /** Outcome of the last host-side deploy run, or null. */
    public function lastStatus(): ?array
    {
        return $this->readJson('deploy.status');
    }

    /**
     * A newer version waiting upstream, as detected by the host watcher
     * (git fetch → commits ahead), or null when up to date. Shape:
     * {behind:int, short:string, at:string}.
     */
    public function availableUpdate(): ?array
    {
        $info = $this->readJson('available.json');

        return ($info !== null && (int) ($info['behind'] ?? 0) > 0) ? $info : null;
    }

    /**
     * The last time the host agent looked for a newer version, and how it went.
     * Shape: {at, ok, behind, branch, error}. Null means it has never run at all.
     */
    public function lastCheck(): ?array
    {
        return $this->readJson('update-check.json');
    }

    /**
     * Is the "no update available" answer on screen actually trustworthy?
     *
     * The agent looks once a minute, so an answer from hours ago means it
     * stopped looking — the cron was never installed, the fetch has no
     * credentials, the host is down. Silence reads as "you are up to date",
     * which is the one wrong conclusion a team can act on for months.
     */
    public function checkIsStale(int $minutes = 120): bool
    {
        $check = $this->lastCheck();

        if ($check === null || blank($check['at'] ?? null)) {
            return true;
        }

        try {
            return Carbon::parse((string) $check['at'])->lt(Carbon::now()->subMinutes($minutes));
        } catch (\Throwable) {
            return true;
        }
    }

    /** The error from the last failed check, or null when the last one worked. */
    public function lastCheckError(): ?string
    {
        $check = $this->lastCheck();

        if ($check === null || ($check['ok'] ?? false) === true) {
            return null;
        }

        $error = trim((string) ($check['error'] ?? ''));

        return $error !== '' ? $error : 'הבדיקה נכשלה ללא פירוט.';
    }

    /** True while an update is requested or actively running. */
    public function isPending(): bool
    {
        return $this->exists('deploy.request') || $this->exists('deploy.lock');
    }

    /**
     * Whether the ops directory is wired up (host mount present and writable).
     * When false the panel shows setup guidance instead of an update button.
     */
    public function isConfigured(): bool
    {
        return is_dir($this->dir) && is_writable($this->dir);
    }

    /**
     * Ask the host agent to deploy the latest version. Idempotent — a second
     * request while one is pending is a no-op. Returns false if not configured.
     */
    public function requestUpdate(?string $requestedBy = null): bool
    {
        if (! $this->isConfigured() || $this->isPending()) {
            return false;
        }

        return $this->writeJson('deploy.request', [
            'requested_by' => $requestedBy,
            'requested_at' => Carbon::now()->toIso8601String(),
        ]);
    }

    private function path(string $file): string
    {
        return $this->dir.'/'.$file;
    }

    private function exists(string $file): bool
    {
        return is_file($this->path($file));
    }

    private function readJson(string $file): ?array
    {
        if (! $this->exists($file)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($this->path($file)), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function writeJson(string $file, array $data): bool
    {
        return @file_put_contents(
            $this->path($file),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        ) !== false;
    }
}
