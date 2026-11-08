<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.scanner


declare(strict_types=1);

namespace WpPluginCheck;

use RuntimeException;
use Throwable;

/**
 * Scans one plugin ZIP with the OFFICIAL Plugin Check (`wp plugin check`, static and runtime checks)
 * inside a throwaway WordPress, and returns a normalised report.
 *
 * No Plugin Check rule is copied or re-implemented here. The only things this class adds are
 * the proof that the scanned bytes are the ZIP's bytes, the proof that runtime checks ran,
 * and a stable shape for the results.
 */
final class Scanner
{
    public const REPORT_SCHEMA = 'wp-plugin-check-report/1';
    public const FIELDS        = 'file,line,column,type,code,message,docs,severity';

    public const EXIT_CLEAN   = 0;
    public const EXIT_FINDING = 1;
    public const EXIT_FAILED  = 2;
    public const EXIT_PARTIAL = 3;

    private string $toolDir;
    private string $workDir;
    private ?string $php;
    private ?string $dbBinDir;

    public function __construct(string $toolDir, string $workDir, ?string $php = null, ?string $dbBinDir = null)
    {
        $this->toolDir  = rtrim(str_replace('\\', '/', $toolDir), '/');
        $this->workDir  = rtrim(str_replace('\\', '/', $workDir), '/');
        $this->php      = $php;
        $this->dbBinDir = $dbBinDir;
    }

    /**
     * @param array{out_dir:string,commit?:?string,checks?:?string,keep_sandbox?:bool,timeout?:int} $options
     * @return array<string,mixed> the report (see docs in README.md)
     */
    public function scan(string $zipPath, array $options): array
    {
        $startedAt = gmdate('c');
        $started   = microtime(true);
        $outDir    = rtrim(str_replace('\\', '/', $options['out_dir']), '/');
        $rawDir    = $outDir . '/raw';
        foreach ([$outDir, $rawDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }

        $pins    = Pins::load($this->toolDir . '/pins.json');
        $partial = isset($options['checks']) && $options['checks'] !== null && $options['checks'] !== '';
        $report  = [
            'schema' => self::REPORT_SCHEMA,
            'scan'   => [
                'id'            => gmdate('Ymd\THis\Z') . '-' . substr(bin2hex(random_bytes(4)), 0, 6),
                'started_at'    => $startedAt,
                'finished_at'   => null,
                'seconds'       => null,
                'status'        => 'failed',
                'status_reason' => 'not finished',
                'partial'       => $partial,
                'tool'          => ['name' => 'wp-plugin-check', 'pins_sha256' => hash_file('sha256', $this->toolDir . '/pins.json')],
            ],
            'subject'     => null,
            'environment' => ['pinned' => $pins->versions()],
            'command'     => null,
            'result'      => null,
            'findings'    => [],
            'suppressions' => null,
            'hygiene'     => [],
            'external_hosts' => [],
            'raw'         => null,
        ];

        $sandbox = null;
        try {
            $zipHashBefore = (string) hash_file('sha256', $zipPath);
            $zip           = new ZipFile($zipPath);
            $content       = new ZipContent($zip);
            $slug          = $content->slug();
            $header        = $content->mainHeader();
            if ($header === null) {
                throw new RuntimeException("No main plugin file with a 'Plugin Name:' header at the root of {$slug}/ in the ZIP");
            }

            $report['subject'] = [
                'file'           => basename($zipPath),
                'zip_sha256'     => $zipHashBefore,
                'zip_bytes'      => filesize($zipPath),
                'entries'        => count($zip->names()),
                'slug'           => $slug,
                'plugin_name'    => $header['name'],
                'plugin_version' => $header['version'],
                'commit'         => $options['commit'] ?? null,
            ];
            $report['suppressions']   = Suppressions::inventory($content->phpFiles());
            $report['hygiene']        = $content->hygiene();
            $report['external_hosts'] = $content->externalHosts();

            $phpBinary = $this->php ?? PHP_BINARY;
            $dbBinDir  = $this->dbBinDir ?? Sandbox::findDbBinDir(null);
            if ($dbBinDir === null) {
                throw new RuntimeException('No MariaDB found. Install MariaDB or pass --mariadb-bin=<dir> / set WPC_MARIADB_BIN.');
            }

            $runId   = $report['scan']['id'];
            $sandbox = new Sandbox($this->workDir . '/run-' . $runId, $pins, new Fetcher($this->workDir . '/cache'), $phpBinary, $dbBinDir, (bool) ($options['keep_sandbox'] ?? false));
            $problems = $sandbox->preflight();
            if ($problems) {
                throw new RuntimeException('Preflight failed: ' . implode(' ', $problems));
            }
            $this->requireNetwork();

            $sandbox->provision();
            $report['environment'] += $sandbox->environment();

            // 1. Prove runtime checks execute in this sandbox, with the same command line.
            $canary                   = $this->runCanary($sandbox, $pins, $rawDir);
            $report['command']        = ['runtime_checks' => $canary];
            if (!$canary['verified']) {
                throw new RuntimeException('Runtime checks are not running in this sandbox: ' . $canary['detail']);
            }

            // 2. Install the exact ZIP and prove the installed files are the ZIP's files.
            $input = $sandbox->inputDir() . '/' . basename($zipPath);
            copy($zipPath, $input);
            if (hash_file('sha256', $input) !== $zipHashBefore) {
                throw new RuntimeException('The copy of the ZIP differs from the original');
            }
            $install = $sandbox->wp(['plugin', 'install', $input, '--activate']);
            if ($install['code'] !== 0) {
                throw new RuntimeException('The ZIP does not install and activate cleanly (exit ' . $install['code'] . '): ' . Sandbox::tail($install['stderr'] . "\n" . $install['stdout']));
            }
            $report['install_verification'] = $this->verifyInstalled($zip, $sandbox->pluginsDir() . '/' . $slug);
            if ($report['install_verification']['mismatches'] || $report['install_verification']['extra']) {
                throw new RuntimeException('The installed files differ from the ZIP, so the scan would not describe the release file');
            }

            // 3. The official command.
            $args = ['plugin', 'check', $slug, '--format=strict-json', '--fields=' . self::FIELDS];
            if ($partial) {
                $args[] = '--checks=' . $options['checks'];
            }
            $requires = [$sandbox->pluginsDir() . '/plugin-check/cli.php'];
            $run      = $sandbox->wp($args, $requires, (int) ($options['timeout'] ?? 1800));

            file_put_contents($rawDir . '/plugin-check.stdout.txt', $run['stdout']);
            file_put_contents($rawDir . '/plugin-check.stderr.txt', $run['stderr']);
            $report['command'] += [
                'display'      => 'wp ' . implode(' ', $args) . ' ' . implode(' ', array_map(static fn (string $r): string => '--require=<sandbox>/wp-content/plugins/plugin-check/cli.php', $requires)),
                'exit_code'    => $run['code'],
                'seconds'      => $run['seconds'],
                'global_flags' => 'none of --checks (unless partial), --exclude-checks, --ignore-codes, --exclude-directories, --exclude-files, --severity is used',
            ];
            $report['raw'] = [
                'stdout_file'   => 'raw/plugin-check.stdout.txt',
                'stdout_sha256' => hash('sha256', $run['stdout']),
                'stderr_file'   => 'raw/plugin-check.stderr.txt',
                'stderr_sha256' => hash('sha256', $run['stderr']),
            ];

            if ($run['timed_out']) {
                throw new RuntimeException('Plugin Check timed out');
            }
            if ($run['code'] !== 0) {
                throw new RuntimeException('wp plugin check exited with ' . $run['code'] . ': ' . Sandbox::tail($run['stderr'] . "\n" . $run['stdout']));
            }
            if (preg_match('/(PHP )?(Fatal error|Parse error)/', $run['stderr'] . $run['stdout'])) {
                throw new RuntimeException('PHP fatal error during the scan: ' . Sandbox::tail($run['stderr'] . "\n" . $run['stdout']));
            }

            $findings = self::parseFindings($run['stdout'], $sandbox->pluginsDir(), $slug);

            // 4. The ZIP must be the same file after the scan as before.
            if (hash_file('sha256', $zipPath) !== $zipHashBefore) {
                throw new RuntimeException('The ZIP changed while it was being scanned');
            }

            $findings           = Fingerprint::apply($slug, $findings, $content->files());
            $report['findings'] = $findings;
            $report['result']   = self::summarise($findings, $report['suppressions'], $report['hygiene']);
            $report['scan']['status']        = $partial ? 'partial' : 'complete';
            $report['scan']['status_reason'] = $partial ? 'Only the checks named in --checks were run; not valid as release evidence.' : 'All checks ran: Plugin Check completed, runtime checks verified by the canary, installed files identical to the ZIP.';
        } catch (Throwable $e) {
            $report['scan']['status']        = 'failed';
            $report['scan']['status_reason'] = $e->getMessage();
            $report['findings']              = [];
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
        file_put_contents($outDir . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        return $report;
    }

    public static function exitCode(array $report): int
    {
        switch ($report['scan']['status'] ?? 'failed') {
            case 'complete':
                return ($report['result']['clean'] ?? false) ? self::EXIT_CLEAN : self::EXIT_FINDING;
            case 'partial':
                return self::EXIT_PARTIAL;
            default:
                return self::EXIT_FAILED;
        }
    }

    /**
     * @return list<array<string,mixed>>
     * @throws RuntimeException when the output is neither a result list nor the "no errors" message
     */
    public static function parseFindings(string $stdout, string $pluginsDir, string $slug): array
    {
        $trimmed = trim($stdout);
        if ($trimmed === '' || ($trimmed[0] !== '[' && $trimmed[0] !== '{')) {
            if (stripos($trimmed, 'Checks complete. No errors found.') !== false) {
                return [];
            }
            throw new RuntimeException('Plugin Check printed neither JSON nor its "no errors found" message: ' . substr($trimmed, 0, 300));
        }
        $rows = json_decode($trimmed, true);
        if (!is_array($rows)) {
            throw new RuntimeException('Plugin Check output is not valid JSON: ' . json_last_error_msg());
        }

        $findings = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['code'], $row['type'], $row['file'])) {
                throw new RuntimeException('Unexpected Plugin Check result row: ' . substr((string) json_encode($row), 0, 200));
            }
            $findings[] = [
                'check'    => (string) $row['code'],
                'type'     => strtoupper((string) $row['type']),
                'severity' => isset($row['severity']) ? (int) $row['severity'] : null,
                'file'     => self::relativeFile((string) $row['file'], $slug, $pluginsDir),
                'line'     => (int) ($row['line'] ?? 0),
                'column'   => (int) ($row['column'] ?? 0),
                'message'  => html_entity_decode((string) ($row['message'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'docs'     => (string) ($row['docs'] ?? ''),
            ];
        }
        usort($findings, static fn (array $a, array $b): int => [$a['file'], $a['line'], $a['column'], $a['check']] <=> [$b['file'], $b['line'], $b['column'], $b['check']]);
        return $findings;
    }

    /**
     * Plugin-relative path from whatever Plugin Check printed: an absolute path inside the sandbox
     * for most checks, an already relative path for some (readme, sitemap-compat, runtime checks).
     */
    public static function relativeFile(string $file, string $slug, string $pluginsDir = ''): string
    {
        $file = str_replace('\\', '/', $file);
        if ($pluginsDir !== '') {
            $prefix = rtrim(str_replace('\\', '/', $pluginsDir), '/') . '/' . $slug . '/';
            if (stripos($file, $prefix) === 0) {
                return substr($file, strlen($prefix));
            }
        }
        $marker = '/wp-content/plugins/' . $slug . '/';
        $pos    = stripos($file, $marker);
        if ($pos !== false) {
            return substr($file, $pos + strlen($marker));
        }
        if (!preg_match('#^([A-Za-z]:)?/#', $file)) {
            return (string) preg_replace('#^\./#', '', $file);
        }
        return basename($file);
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @param array<string,mixed>       $suppressions
     * @param list<array<string,mixed>> $hygiene
     * @return array<string,mixed>
     */
    public static function summarise(array $findings, array $suppressions, array $hygiene): array
    {
        $errors = $warnings = 0;
        $byCheck = $byFile = [];
        foreach ($findings as $f) {
            $f['type'] === 'ERROR' ? $errors++ : $warnings++;
            $byCheck[$f['check']] = ($byCheck[$f['check']] ?? 0) + 1;
            $byFile[$f['file']]   = ($byFile[$f['file']] ?? 0) + 1;
        }
        arsort($byCheck);
        arsort($byFile);
        $problems = count($suppressions['problems'] ?? []);
        return [
            'errors'                => $errors,
            'warnings'              => $warnings,
            'findings_total'        => count($findings),
            'suppression_problems'  => $problems,
            'hygiene_problems'      => count($hygiene),
            'clean'                 => $errors === 0 && $warnings === 0 && $problems === 0 && count($hygiene) === 0,
            'by_check'              => $byCheck,
            'by_file'               => $byFile,
        ];
    }

    /**
     * Compare every file in the ZIP with the file WordPress installed.
     *
     * @return array{files:int,mismatches:list<string>,extra:list<string>}
     */
    private function verifyInstalled(ZipFile $zip, string $installedDir): array
    {
        $root       = $zip->topLevelDirectory();
        $mismatches = [];
        $expected   = [];
        foreach ($zip->hashes() as $name => $hash) {
            $relative            = substr($name, strlen($root) + 1);
            $expected[$relative] = true;
            $path                = $installedDir . '/' . $relative;
            if (!is_file($path) || hash_file('sha256', $path) !== $hash) {
                $mismatches[] = $relative;
            }
        }
        $extra = [];
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($installedDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $installedDir))), '/');
                if (!isset($expected[$relative])) {
                    $extra[] = $relative;
                }
            }
        }
        sort($extra);
        return ['files' => count($expected), 'mismatches' => $mismatches, 'extra' => $extra];
    }

    /** @return array<string,mixed> */
    private function runCanary(Sandbox $sandbox, Pins $pins, string $rawDir): array
    {
        $expected = (string) $pins->section('runtime_canary')['expected_code'];
        Canary::install($sandbox->pluginsDir());
        $sandbox->mustWp(['plugin', 'activate', Canary::SLUG], 'activate the runtime canary');

        $run = $sandbox->wp(
            ['plugin', 'check', Canary::SLUG, '--format=strict-json', '--fields=' . self::FIELDS],
            [$sandbox->pluginsDir() . '/plugin-check/cli.php']
        );
        file_put_contents($rawDir . '/canary.stdout.txt', $run['stdout']);

        $found = false;
        $detail = '';
        try {
            foreach (self::parseFindings($run['stdout'], $sandbox->pluginsDir(), Canary::SLUG) as $f) {
                if ($f['check'] === $expected) {
                    $found = true;
                }
            }
            $detail = $found ? 'The canary triggered the expected runtime finding.' : "The canary did not trigger {$expected}; Plugin Check skipped its runtime checks.";
        } catch (Throwable $e) {
            $detail = $e->getMessage();
        }

        // The canary must not be present while the real plugin is scanned.
        $sandbox->wp(['plugin', 'deactivate', Canary::SLUG]);
        $sandbox->wp(['plugin', 'delete', Canary::SLUG]);

        return [
            'requested'     => true,
            'verified'      => $found && $run['code'] === 0,
            'canary_plugin' => Canary::SLUG,
            'expected_code' => $expected,
            'detail'        => $detail,
        ];
    }

    private function requireNetwork(): void
    {
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'method' => 'GET']]);
        if (@file_get_contents('https://api.wordpress.org/core/version-check/1.7/', false, $ctx) === false) {
            throw new RuntimeException('api.wordpress.org is not reachable. Plugin Check calls it for the readme and header checks, so a scan without it would be incomplete.');
        }
    }
}
