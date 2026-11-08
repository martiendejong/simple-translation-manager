<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.lifecycle

declare(strict_types=1);

namespace WpPluginCheck;

use RuntimeException;
use Throwable;

/**
 * Install, update, run and uninstall a plugin ZIP in the same kind of throwaway WordPress the
 * scanner uses, and let a plugin-specific test script judge the behaviour in between.
 *
 * The runner is generic: it only knows how to put a ZIP into the site, how to take it out again,
 * how to serve the site over HTTP and how to run the script. What "translations survive an update"
 * means is decided by the script (for STM: tests/lifecycle/stm-lifecycle.php).
 *
 * Script contract: `wp eval-file <script> <phase> <label> <siteUrl> <adminPassword> <slug>`; the script
 * prints one line `@@LIFECYCLE@@ {"checks":[{"name":"...","ok":true,"detail":"..."}]}`.
 * A PHP notice, warning or fatal error that the plugin writes to debug.log during a phase fails that phase.
 */
final class Lifecycle
{
    public const REPORT_SCHEMA = 'wp-plugin-lifecycle-report/1';
    public const MARKER        = '@@LIFECYCLE@@';

    private string $toolDir;
    private string $workDir;
    private ?string $php;
    private ?string $dbBinDir;

    /** @var list<array<string,mixed>> */
    private array $steps = [];
    private int $debugLogOffset = 0;

    public function __construct(string $toolDir, string $workDir, ?string $php = null, ?string $dbBinDir = null)
    {
        $this->toolDir  = rtrim(str_replace('\\', '/', $toolDir), '/');
        $this->workDir  = rtrim(str_replace('\\', '/', $workDir), '/');
        $this->php      = $php;
        $this->dbBinDir = $dbBinDir;
    }

    /**
     * @param array{out_dir:string,script:string,from?:list<string>,commit?:?string,keep_sandbox?:bool,reuse?:?string} $options
     * @return array<string,mixed>
     */
    public function run(string $newZip, array $options): array
    {
        $started = microtime(true);
        $outDir  = rtrim(str_replace('\\', '/', $options['out_dir']), '/');
        if (!is_dir($outDir)) {
            mkdir($outDir, 0777, true);
        }
        $pins   = Pins::load($this->toolDir . '/pins.json');
        $script = str_replace('\\', '/', is_file($options['script']) ? (string) realpath($options['script']) : $options['script']);
        $report = [
            'schema'  => self::REPORT_SCHEMA,
            'scan'    => ['id' => gmdate('Ymd\THis\Z') . '-' . substr(bin2hex(random_bytes(4)), 0, 6), 'started_at' => gmdate('c'), 'status' => 'failed', 'status_reason' => 'not finished'],
            'subject' => null,
            'environment' => ['pinned' => $pins->versions()],
            'script'  => ['file' => $script, 'sha256' => is_file($script) ? hash_file('sha256', $script) : null],
            'steps'   => [],
            'result'  => null,
        ];

        $sandbox = null;
        try {
            if (!is_file($script)) {
                throw new RuntimeException("Test script not found: {$script}");
            }
            $zip     = new ZipFile($newZip);
            $content = new ZipContent($zip);
            $slug    = $content->slug();
            $header  = $content->mainHeader();
            $report['subject'] = [
                'file'           => basename($newZip),
                'zip_sha256'     => hash_file('sha256', $newZip),
                'slug'           => $slug,
                'plugin_version' => $header['version'] ?? '',
                'commit'         => $options['commit'] ?? null,
                'update_from'    => array_map(static fn (string $z): array => ['file' => basename($z), 'zip_sha256' => hash_file('sha256', $z), 'plugin_version' => self::versionOf($z)], $options['from'] ?? []),
            ];

            $dbBinDir = $this->dbBinDir ?? Sandbox::findDbBinDir(null);
            if ($dbBinDir === null) {
                throw new RuntimeException('No MariaDB found. Install MariaDB or pass --mariadb-bin=<dir> / set WPC_MARIADB_BIN.');
            }
            $reuse   = isset($options['reuse']) && $options['reuse'] !== null && $options['reuse'] !== '';
            $root    = $reuse ? str_replace('\\', '/', (string) $options['reuse']) : $this->workDir . '/lifecycle-' . $report['scan']['id'];
            $sandbox = new Sandbox($root, $pins, new Fetcher($this->workDir . '/cache'), $this->php ?? PHP_BINARY, $dbBinDir, (bool) ($options['keep_sandbox'] ?? false) || $reuse);
            $problems = $sandbox->preflight();
            if ($problems) {
                throw new RuntimeException('Preflight failed: ' . implode(' ', $problems));
            }

            $port = $sandbox->freePort();
            $sandbox->useSiteUrl('http://127.0.0.1:' . $port);
            $sandbox->withoutPluginCheck();
            $sandbox->reuseExisting($reuse);
            $sandbox->provision();
            $sandbox->startWebServer($port);
            $report['environment'] += $sandbox->environment();
            foreach ([['WP_DEBUG', 'true'], ['WP_DEBUG_LOG', 'true'], ['WP_DEBUG_DISPLAY', 'false']] as [$name, $value]) {
                $sandbox->mustWp(['config', 'set', $name, $value, '--raw'], "wp config set {$name}");
            }
            $sandbox->mustWp(['rewrite', 'structure', '/%postname%/'], 'wp rewrite structure');
            $debugLog = $sandbox->sitePath() . '/wp-content/debug.log';
            $this->debugLogOffset = is_file($debugLog) ? (int) filesize($debugLog) : 0;

            $ctx = ['sandbox' => $sandbox, 'script' => $script, 'slug' => $slug, 'debug_log' => $debugLog, 'dir' => $sandbox->inputDir()];

            // Scenario 1..n: an older release is installed, filled with synthetic data, then replaced by the new ZIP.
            foreach (($options['from'] ?? []) as $i => $oldZip) {
                $label = 'update-from-' . self::versionOf($oldZip);
                $this->install($ctx, $oldZip, 'install old ' . self::versionOf($oldZip));
                $this->phase($ctx, 'seed', $label);
                $this->install($ctx, $newZip, 'update to ' . ($header['version'] ?? 'new'), false);
                $this->phase($ctx, 'after-update', $label);
                $this->phase($ctx, 'prepare-remove', $label);
                $this->remove($ctx, 'uninstall after update scenario', true);
                $this->phase($ctx, 'verify-removed', $label);
            }

            // Scenario: clean install of the new ZIP on an empty site.
            $this->install($ctx, $newZip, 'clean install ' . ($header['version'] ?? 'new'));
            $this->phase($ctx, 'fresh-install', 'clean-install');
            $this->phase($ctx, 'functional', 'clean-install');

            // Uninstall with "keep data" on: data stays, a new install picks it up.
            $this->phase($ctx, 'prepare-keep', 'keep-data');
            $this->remove($ctx, 'uninstall, keep data on', true);
            $this->phase($ctx, 'verify-kept', 'keep-data');
            $this->install($ctx, $newZip, 'reinstall over kept data');
            $this->phase($ctx, 'verify-reinstall', 'keep-data');

            // Uninstall with "keep data" off, from WP-CLI without a logged-in user, as automation does it.
            $this->phase($ctx, 'prepare-remove', 'remove-data');
            $this->remove($ctx, 'uninstall, keep data off, no user (WP-CLI)', false);
            $this->phase($ctx, 'verify-removed', 'remove-data');

            $failed = 0;
            $total  = 0;
            foreach ($this->steps as $step) {
                foreach ($step['checks'] as $c) {
                    $total++;
                    if (!$c['ok']) {
                        $failed++;
                    }
                }
            }
            $report['steps']  = $this->steps;
            $report['result'] = ['checks' => $total, 'failed' => $failed, 'passed' => $total - $failed, 'ok' => $failed === 0 && $total > 0];
            $report['scan']['status']        = 'complete';
            $report['scan']['status_reason'] = 'Every scenario ran to the end.';
        } catch (Throwable $e) {
            $report['steps']                 = $this->steps;
            $report['scan']['status']        = 'failed';
            $report['scan']['status_reason'] = $e->getMessage();
            $report['result']                = null;
        } finally {
            if ($sandbox !== null) {
                try {
                    $sandbox->teardown();
                } catch (Throwable $e) {
                    $report['scan']['teardown_warning'] = $e->getMessage();
                }
            }
        }
        $report['scan']['finished_at'] = gmdate('c');
        $report['scan']['seconds']     = round(microtime(true) - $started, 1);
        file_put_contents($outDir . '/lifecycle.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        file_put_contents($outDir . '/lifecycle.md', self::markdown($report));
        return $report;
    }

    public static function exitCode(array $report): int
    {
        if (($report['scan']['status'] ?? '') !== 'complete') {
            return 2;
        }
        return ($report['result']['ok'] ?? false) ? 0 : 1;
    }

    /**
     * Install a ZIP over whatever is there ("update" when the plugin exists, "install" otherwise).
     *
     * @param array<string,mixed> $ctx
     */
    private function install(array $ctx, string $zip, string $name, bool $activate = true): void
    {
        /** @var Sandbox $sandbox */
        $sandbox = $ctx['sandbox'];
        $copy    = $ctx['dir'] . '/' . basename($zip) . '.' . substr((string) hash_file('sha256', $zip), 0, 8) . '.zip';
        copy($zip, $copy);
        $args = ['plugin', 'install', $copy, '--force'];
        if ($activate) {
            $args[] = '--activate';
        }
        $r = $sandbox->wp($args, [], 300, ['--user=scan-admin']);
        $this->record($name, $r['code'] === 0, [
            ['name' => $name . ': wp plugin install exits 0', 'ok' => $r['code'] === 0, 'detail' => $r['code'] === 0 ? '' : Sandbox::tail($r['stderr'] . "\n" . $r['stdout'])],
            ['name' => $name . ': no PHP error while installing', 'ok' => !self::hasPhpError($r['stdout'] . $r['stderr']), 'detail' => ''],
        ], $ctx);
        if ($r['code'] !== 0) {
            throw new RuntimeException("Cannot continue: {$name} failed");
        }
    }

    /**
     * Deactivate and uninstall the plugin (runs uninstall.php, then deletes the files).
     *
     * @param array<string,mixed> $ctx
     */
    private function remove(array $ctx, string $name, bool $asAdmin): void
    {
        /** @var Sandbox $sandbox */
        $sandbox = $ctx['sandbox'];
        $flags   = $asAdmin ? ['--user=scan-admin'] : [];
        $deact   = $sandbox->wp(['plugin', 'deactivate', $ctx['slug']], [], 120, $flags);
        $un      = $sandbox->wp(['plugin', 'uninstall', $ctx['slug']], [], 120, $flags);
        $this->record($name, $deact['code'] === 0 && $un['code'] === 0, [
            ['name' => $name . ': deactivate and uninstall exit 0', 'ok' => $deact['code'] === 0 && $un['code'] === 0, 'detail' => Sandbox::tail($deact['stderr'] . "\n" . $un['stderr'] . "\n" . $un['stdout'])],
            ['name' => $name . ': plugin folder is gone', 'ok' => !is_dir($sandbox->pluginsDir() . '/' . $ctx['slug']), 'detail' => ''],
        ], $ctx);
    }

    /** @param array<string,mixed> $ctx */
    private function phase(array $ctx, string $phase, string $label): void
    {
        /** @var Sandbox $sandbox */
        $sandbox = $ctx['sandbox'];
        $r = $sandbox->wp(
            ['eval-file', $ctx['script'], $phase, $label, $sandbox->siteUrl(), $sandbox->adminPassword(), $ctx['slug']],
            [],
            900,
            ['--user=scan-admin']
        );
        $checks = [];
        $parsed = self::parseResult($r['stdout']);
        if ($parsed === null) {
            $checks[] = ['name' => "{$phase} ({$label}): the test script reported a result", 'ok' => false, 'detail' => Sandbox::tail($r['stderr'] . "\n" . $r['stdout'], 20)];
        } else {
            foreach ($parsed as $c) {
                $c['name'] = "{$phase} ({$label}): " . $c['name'];
                $checks[]  = $c;
            }
        }
        $this->record("{$phase} ({$label})", array_reduce($checks, static fn (bool $ok, array $c): bool => $ok && $c['ok'], true), $checks, $ctx, $phase, $label);
    }

    /**
     * @param list<array{name:string,ok:bool,detail:string}> $checks
     * @param array<string,mixed>                           $ctx
     */
    private function record(string $name, bool $ok, array $checks, array $ctx, string $phase = '', string $label = ''): void
    {
        $log = (string) $ctx['debug_log'];
        if (is_file($log)) {
            $size  = (int) filesize($log);
            $fresh = $size > $this->debugLogOffset ? (string) file_get_contents($log, false, null, $this->debugLogOffset) : '';
            $this->debugLogOffset = $size;
            $bad = self::pluginErrors($fresh, (string) $ctx['slug']);
            $checks[] = [
                'name'   => "{$name}: debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin",
                'ok'     => $bad === [],
                'detail' => implode(' | ', array_slice($bad, 0, 5)),
            ];
        }
        $this->steps[] = ['name' => $name, 'phase' => $phase, 'label' => $label, 'ok' => !in_array(false, array_column($checks, 'ok'), true), 'checks' => $checks];
    }

    /** @return list<array{name:string,ok:bool,detail:string}>|null */
    public static function parseResult(string $stdout): ?array
    {
        $found = null;
        foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
            $pos = strpos($line, self::MARKER);
            if ($pos === false) {
                continue;
            }
            $data = json_decode(substr($line, $pos + strlen(self::MARKER)), true);
            if (is_array($data) && isset($data['checks']) && is_array($data['checks'])) {
                $found = [];
                foreach ($data['checks'] as $c) {
                    if (is_array($c) && isset($c['name'])) {
                        $found[] = ['name' => (string) $c['name'], 'ok' => (bool) ($c['ok'] ?? false), 'detail' => (string) ($c['detail'] ?? '')];
                    }
                }
            }
        }
        return $found === [] ? null : $found;
    }

    /**
     * Lines of a debug.log that blame the plugin: PHP problems that name its files, or its own
     * error codes. Core and other plugins are not judged here.
     *
     * @return list<string>
     */
    public static function pluginErrors(string $log, string $slug): array
    {
        $bad = [];
        foreach (preg_split('/\R/', $log) ?: [] as $line) {
            $isPhp  = preg_match('/PHP (Notice|Warning|Fatal error|Parse error|Deprecated|Stack trace)/i', $line) === 1;
            $inPlug = stripos($line, '/' . $slug . '/') !== false || stripos($line, '\\' . $slug . '\\') !== false;
            $isDb   = stripos($line, 'WordPress database error') !== false && preg_match('/\bwp_stm_|\w+stm_(languages|strings|translations|post_|term_|field_)/i', $line) === 1;
            if (($isPhp && $inPlug) || $isDb || preg_match('/\[STM-E-[A-Z0-9-]+\]/', $line) === 1) {
                $bad[] = trim($line);
            }
        }
        return $bad;
    }

    private static function hasPhpError(string $text): bool
    {
        return preg_match('/(PHP )?(Fatal error|Parse error|Warning|Notice):/', $text) === 1;
    }

    private static function versionOf(string $zipPath): string
    {
        try {
            $zip     = new ZipFile($zipPath);
            $content = new ZipContent($zip);
            $header  = $content->mainHeader();
            return $header['version'] ?? 'unknown';
        } catch (Throwable $e) {
            return 'unknown';
        }
    }

    /** @param array<string,mixed> $report */
    public static function markdown(array $report): string
    {
        $s   = $report['subject'] ?? [];
        $out = ['# Install, update and runtime test', ''];
        $out[] = '- Scan `' . ($report['scan']['id'] ?? '') . '` status `' . ($report['scan']['status'] ?? '') . '`' . (($report['scan']['status_reason'] ?? '') !== '' ? ': ' . $report['scan']['status_reason'] : '');
        $out[] = '- ZIP `' . ($s['file'] ?? '') . '` sha256 `' . ($s['zip_sha256'] ?? '') . '`, plugin version ' . ($s['plugin_version'] ?? '?');
        foreach ($s['update_from'] ?? [] as $f) {
            $out[] = '- Updated from `' . $f['file'] . '` (version ' . $f['plugin_version'] . ', sha256 `' . $f['zip_sha256'] . '`)';
        }
        $env = $report['environment'] ?? [];
        $out[] = '- Environment: WordPress ' . ($env['wordpress'] ?? '?') . ', PHP ' . ($env['php'] ?? '?') . ', ' . ($env['mariadb'] ?? '?');
        if ($report['result'] !== null) {
            $r = $report['result'];
            $out[] = '- Result: **' . ($r['ok'] ? 'all ' . $r['passed'] . ' checks passed' : $r['failed'] . ' of ' . $r['checks'] . ' checks failed') . '**';
        }
        foreach ($report['steps'] ?? [] as $step) {
            $out[] = '';
            $out[] = '## ' . ($step['ok'] ? 'PASS' : 'FAIL') . ' - ' . $step['name'];
            foreach ($step['checks'] as $c) {
                $out[] = '- ' . ($c['ok'] ? 'ok' : '**FAILED**') . ': ' . $c['name'] . (!$c['ok'] && $c['detail'] !== '' ? ' - ' . $c['detail'] : '');
            }
        }
        return implode("\n", $out) . "\n";
    }
}
