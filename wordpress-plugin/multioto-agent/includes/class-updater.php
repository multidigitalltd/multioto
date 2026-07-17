<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Self-update from the Multi Digital platform. The plugin checks in with its
 * per-site update token; when the platform advertises a newer version it hands
 * back a short-lived signed package URL, which WordPress's normal update
 * machinery downloads and installs. This is what lets us ship one new version
 * and have every site update itself — no manual re-install.
 */
class Multioto_Agent_Updater
{
    private const CACHE_KEY = 'multioto_agent_update_check';

    public function boot(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'injectUpdate']);
        add_filter('plugins_api', [$this, 'details'], 20, 3);
        add_action('upgrader_process_complete', [$this, 'clearCache'], 10, 0);

        // Opt THIS managed plugin into WordPress's automatic background updates.
        // Advertising a new version only makes it *offered* — WordPress still
        // waits for a manual "Update now" click unless auto-updates are enabled
        // for the plugin. That is the whole promise of the managed channel: ship
        // a version once and every site installs it by itself. Only our own
        // plugin is affected; every other plugin's decision is left untouched.
        add_filter('auto_update_plugin', [$this, 'enableAutoUpdate'], 10, 2);
    }

    /**
     * Force auto-updates ON for our plugin only. WordPress runs its auto-update
     * cron twice daily; with this returning true, a newer version we advertise
     * installs itself without anyone clicking.
     *
     * @param  mixed  $update  The current auto-update decision for the item.
     * @param  mixed  $item    The update offer (has ->plugin = the basename).
     * @return mixed
     */
    public function enableAutoUpdate($update, $item)
    {
        $plugin = is_object($item) ? (string) ($item->plugin ?? '') : '';

        return $plugin === plugin_basename(MULTIOTO_AGENT_FILE) ? true : $update;
    }

    /** @param mixed $transient */
    public function injectUpdate($transient)
    {
        if (! is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $info = $this->fetchManifest();

        if ($info === null || empty($info['update_available']) || empty($info['download_url'])) {
            return $transient;
        }

        $basename = plugin_basename(MULTIOTO_AGENT_FILE);

        $transient->response[$basename] = (object) [
            // WordPress's auto-update path reads several of these fields on the
            // offer object; give it a stable id + slug so it treats ours like a
            // normal, auto-updatable plugin.
            'id' => MULTIOTO_AGENT_SLUG,
            'slug' => MULTIOTO_AGENT_SLUG,
            'plugin' => $basename,
            'new_version' => (string) $info['version'],
            'package' => (string) $info['download_url'],
            'url' => (string) ($info['changelog_url'] ?? ''),
            'tested' => get_bloginfo('version'),
        ];

        return $transient;
    }

    /**
     * Provide the "view details" payload WordPress shows for our plugin.
     *
     * @param  mixed  $result
     * @param  string  $action
     * @param  object  $args
     * @return mixed
     */
    public function details($result, $action, $args)
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== MULTIOTO_AGENT_SLUG) {
            return $result;
        }

        $info = $this->fetchManifest();

        return (object) [
            'name' => 'Multi Digital Agent',
            'slug' => MULTIOTO_AGENT_SLUG,
            'version' => (string) ($info['version'] ?? MULTIOTO_AGENT_VERSION),
            'author' => 'Multi Digital',
            'download_link' => (string) ($info['download_url'] ?? ''),
            'sections' => ['description' => 'חיבור האתר לפאנל התפעול של Multi Digital.'],
        ];
    }

    public function clearCache(): void
    {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * Fetch the update manifest from the platform, cached briefly to avoid a
     * network call on every admin page load.
     *
     * @return array<string,mixed>|null
     */
    private function fetchManifest(): ?array
    {
        $cached = get_transient(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $settings = Multioto_Agent_Settings::get();

        if ($settings['platform_url'] === '' || $settings['update_token'] === '') {
            return null;
        }

        $url = $settings['platform_url'].'/agent/plugin/update?version='.rawurlencode(MULTIOTO_AGENT_VERSION);

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer '.$settings['update_token'],
                'X-Agent-Plugin-Version' => MULTIOTO_AGENT_VERSION,
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if (! is_array($data)) {
            return null;
        }

        set_transient(self::CACHE_KEY, $data, 6 * HOUR_IN_SECONDS);

        return $data;
    }
}
