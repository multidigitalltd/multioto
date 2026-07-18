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
