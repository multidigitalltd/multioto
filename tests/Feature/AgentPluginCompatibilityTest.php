<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The WordPress plugin runs on OUR CUSTOMERS' hosts, not ours.
 *
 * A parse error inside it is fatal at compile time and takes the whole site
 * down — front end and wp-admin alike — so the owner cannot even log in to
 * deactivate it. That already happened once: a match() expression (PHP 8.0)
 * shipped in a plugin whose header promises "Requires PHP 7.4", and the first
 * customer on an older host lost their site.
 *
 * These tests guard the floor the header promises.
 */
class AgentPluginCompatibilityTest extends TestCase
{
    private const PLUGIN_DIR = __DIR__.'/../../wordpress-plugin/multioto-agent';

    /** @return list<string> */
    private function phpFiles(): array
    {
        $files = glob(self::PLUGIN_DIR.'/*.php') ?: [];
        $files = array_merge($files, glob(self::PLUGIN_DIR.'/includes/*.php') ?: []);

        $this->assertNotEmpty($files, 'No plugin PHP files found — has the plugin moved?');

        return $files;
    }

    /**
     * A file's executable code with comments and doc blocks removed, so a note
     * ABOUT a forbidden construct ("a switch rather than match()") is not
     * mistaken for the construct itself.
     */
    private function code(string $file): string
    {
        $kept = '';

        foreach (token_get_all(file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $kept;
    }

    public function test_the_plugin_declares_the_php_version_it_actually_needs(): void
    {
        $main = file_get_contents(self::PLUGIN_DIR.'/multioto-agent.php');

        $this->assertMatchesRegularExpression('/Requires PHP:\s*7\.4/', $main,
            'The floor the syntax checks below enforce must match the header customers rely on.');
    }

    public function test_no_file_uses_syntax_a_php_74_host_cannot_parse(): void
    {
        // Compile-time constructs only: anything here means the file does not
        // PARSE on 7.4, which is the failure that kills a site. Runtime-only
        // additions (str_contains and friends) fail loudly on one request
        // instead, and are checked separately below.
        $forbidden = [
            'match expression (PHP 8.0)' => '/(?<![\w$>])match\s*\(/',
            'nullsafe operator (PHP 8.0)' => '/\?->/',
            'constructor property promotion (PHP 8.0)' => '/function\s+__construct\s*\([^)]*\b(private|protected|public)\s+\$/s',
            'readonly property (PHP 8.1)' => '/(?<![\w$])readonly\s+/',
            'enum declaration (PHP 8.1)' => '/^\s*enum\s+\w/m',
            'never return type (PHP 8.1)' => '/\)\s*:\s*never\b/',
            'first-class callable syntax (PHP 8.1)' => '/\(\.\.\.\)/',
        ];

        foreach ($this->phpFiles() as $file) {
            $source = $this->code($file);
            $name = basename($file);

            foreach ($forbidden as $label => $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $source,
                    "{$name} uses {$label} — a PHP 7.4 host cannot parse the file, and the customer's whole site goes down.");
            }
        }
    }

    public function test_no_file_calls_a_function_php_74_does_not_have(): void
    {
        $forbidden = ['str_contains', 'str_starts_with', 'str_ends_with', 'array_is_list'];

        foreach ($this->phpFiles() as $file) {
            $source = $this->code($file);
            $name = basename($file);

            foreach ($forbidden as $function) {
                $this->assertDoesNotMatchRegularExpression('/(?<![\w$>])'.$function.'\s*\(/', $source,
                    "{$name} calls {$function}() — added in PHP 8.0, fatal on a 7.4 host.");
            }
        }
    }

    public function test_the_entry_file_refuses_to_load_the_rest_on_an_old_php(): void
    {
        $main = file_get_contents(self::PLUGIN_DIR.'/multioto-agent.php');

        $guardAt = strpos($main, 'version_compare(PHP_VERSION');
        $firstRequire = strpos($main, 'require_once');

        $this->assertNotFalse($guardAt, 'The entry file must check PHP_VERSION before loading anything.');
        $this->assertNotFalse($firstRequire);

        // Order matters: a guard after the require runs too late, because the
        // required file has already failed to compile.
        $this->assertLessThan($firstRequire, $guardAt,
            'The PHP version guard must come BEFORE the first require_once.');
    }

    public function test_every_plugin_file_parses(): void
    {
        foreach ($this->phpFiles() as $file) {
            exec('php -l '.escapeshellarg($file).' 2>&1', $output, $status);

            $this->assertSame(0, $status, basename($file).' does not parse: '.implode("\n", $output));
            $output = [];
        }
    }

    public function test_the_shipped_version_matches_what_the_panel_offers(): void
    {
        $main = file_get_contents(self::PLUGIN_DIR.'/multioto-agent.php');

        preg_match('/Version:\s*([\d.]+)/', $main, $header);
        preg_match("/MULTIOTO_AGENT_VERSION',\s*'([\d.]+)'/", $main, $constant);

        // Three places must agree, or sites are told to update to a build that
        // does not exist — or never hear about a fix that does.
        $this->assertSame($header[1], $constant[1], 'Plugin header and version constant disagree.');
        $this->assertSame($header[1], config('agent.plugin.current_version'),
            'config/agent.php offers a different version than the plugin ships.');
    }
}
