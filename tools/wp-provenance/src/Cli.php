<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.tools.provenance.cli

declare(strict_types=1);

namespace WpProvenance;

/**
 * Command line front end. Exit codes: 0 ok, 1 findings with errors (or a failed expectation), 2 usage or tool failure.
 */
final class Cli
{
    private const USAGE = <<<'TXT'
wp-provenance - provenance checks for WordPress plugins

  check          --config=FILE [--repo=DIR] [--manifest=FILE] [--zip=FILE | --build-zip[=REF]]
                 [--format=text|json|github] [--out=FILE] [--strict] [--no-git]
  apply-headers  --config=FILE [--repo=DIR]
  manifest       --config=FILE [--repo=DIR] [--out=FILE] [--no-git] [--no-intro]
  release        --config=FILE [--repo=DIR] [--out-dir=DIR] [--commit=REF] [--zip=FILE]
                 [--published=YYYY-MM-DD] [--publication-url=URL]
  verify-release RECORD --config=FILE [--repo=DIR]
  compare        --config=FILE [--repo=DIR] --candidate=DIR [--format=json|md] [--out=FILE]
  diff           OLD-REPORT.json NEW-REPORT.json [--fail-on-new]
  demo           [--fixtures=DIR] [--format=text|json]

Exit codes: 0 ok, 1 errors found, 2 usage or tool failure.
Manifests, release records and ZIPs are written only outside the plugin repository.
TXT;

    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        $command = $argv[1] ?? '';
        $opts    = [];
        $pos     = [];
        foreach (array_slice($argv, 2) as $arg) {
            if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/s', $arg, $m)) {
                $opts[$m[1]] = $m[2] ?? true;
            } else {
                $pos[] = $arg;
            }
        }

        try {
            switch ($command) {
                case 'check':
                    return self::check($opts);
                case 'apply-headers':
                    return self::applyHeaders($opts);
                case 'manifest':
                    return self::manifest($opts);
                case 'release':
                    return self::release($opts);
                case 'verify-release':
                    return self::verifyRelease($opts, $pos);
                case 'compare':
                    return self::compare($opts);
                case 'diff':
                    return self::diff($opts, $pos);
                case 'demo':
                    return self::demo($opts);
            }
        } catch (ConfigException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 2;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
            return 2;
        }

        fwrite(STDERR, self::USAGE . "\n");
        return $command === '' || $command === 'help' || $command === '--help' ? 0 : 2;
    }

    /** @param array<string,mixed> $opts */
    private static function config(array $opts): Config
    {
        $file = is_string($opts['config'] ?? null) ? $opts['config'] : 'provenance.json';
        return Config::load($file, is_string($opts['repo'] ?? null) ? $opts['repo'] : null);
    }

    /** @param array<string,mixed> $opts */
    private static function git(Config $c, array $opts): ?Git
    {
        if (isset($opts['no-git'])) {
            return null;
        }
        $git = new Git($c->repoRoot);
        return $git->isRepository() ? $git : null;
    }

    /** @param array<string,mixed> $opts */
    private static function check(array $opts): int
    {
        $c   = self::config($opts);
        $git = self::git($c, $opts);

        $manifest = null;
        if (is_string($opts['manifest'] ?? null)) {
            $manifest = $opts['manifest'];
        } elseif ($c->private_manifest !== null && is_file($c->private_manifest)) {
            $manifest = $c->private_manifest;
        }

        $zip      = is_string($opts['zip'] ?? null) ? $opts['zip'] : null;
        $zipTemp  = null;
        $label    = null;
        $zipError = null;
        if (isset($opts['build-zip'])) {
            if ($git === null) {
                throw new \RuntimeException('--build-zip needs a git repository.');
            }
            $ref     = is_string($opts['build-zip']) ? $opts['build-zip'] : 'HEAD';
            $commit  = $git->commitOf($ref);
            if ($commit === null) {
                throw new \RuntimeException("Unknown commit '{$ref}'");
            }
            $zipTemp = sys_get_temp_dir() . '/wp-provenance-check-' . bin2hex(random_bytes(4)) . '.zip';
            try {
                Zip::build($c, $git, $commit, $zipTemp, false);
                $zip   = $zipTemp;
                $label = $c->slug . '.zip (built from ' . substr($commit, 0, 8) . ')';
            } catch (\RuntimeException $e) {
                $zipError = $e->getMessage(); // reported as a finding below, the rest of the check still runs
                $zipTemp  = null;
            }
        }

        try {
            $report = Checker::run($c, $git, ['manifest' => $manifest, 'zip' => $zip, 'zip_label' => $label, 'zip_error' => $zipError]);
        } finally {
            if ($zipTemp !== null) {
                @unlink($zipTemp);
            }
        }

        $format = is_string($opts['format'] ?? null) ? $opts['format'] : 'text';
        if ($format === 'json') {
            $text = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        } elseif ($format === 'github') {
            $text = Report::github($report);
        } elseif ($format === 'text') {
            $text = Report::text($report);
        } else {
            throw new \RuntimeException("Unknown --format '{$format}' (text, json or github)");
        }

        if (is_string($opts['out'] ?? null)) {
            file_put_contents($opts['out'], $text);
            echo Report::text($report);
        } else {
            echo $text;
        }
        return Report::exitCode($report, isset($opts['strict']));
    }

    /** @param array<string,mixed> $opts */
    private static function applyHeaders(array $opts): int
    {
        $c = self::config($opts);
        if ($c->holder_status !== 'verified') {
            fwrite(STDERR, "Refusing to add headers: the rights holder \"{$c->holder}\" is not confirmed (rights.holder_status is \"{$c->holder_status}\").\n"
                . "Verify who holds the rights first and record the evidence in the config. Never add our copyright unverified.\n");
            return 1;
        }
        $tree    = new Tree($c);
        $taken   = [];
        foreach ($tree->ownSourceFiles() as $rel) {
            $id = Headers::parse($tree->read($rel))['source_id'];
            if ($id !== null) {
                $taken[] = $id;
            }
        }
        $changed = 0;
        foreach ($tree->ownSourceFiles() as $rel) {
            $before = $tree->read($rel);
            $after  = Headers::apply($c, $before, $rel, $taken);
            if ($after !== $before) {
                file_put_contents($c->pluginRoot() . '/' . $rel, $after);
                $taken[] = (string) Headers::parse($after)['source_id'];
                $changed++;
            }
        }
        echo "Headers added to {$changed} file(s).\n";
        return 0;
    }

    /** Where a private output goes: the explicit option, else config private.dir. */
    private static function outputDir(Config $c, array $opts, string $option): ?string
    {
        if (is_string($opts[$option] ?? null)) {
            return rtrim(str_replace('\\', '/', $opts[$option]), '/');
        }
        return $c->private_dir !== null ? rtrim($c->private_dir, '/') : null;
    }

    private static function remind(string $dir): void
    {
        $repo = PrivateOutput::enclosingRepository($dir);
        if ($repo !== null) {
            echo "Note: {$dir} is inside the git repository {$repo}. Outside the plugin repo is not the same as private: make sure that repository is private.\n";
        }
    }

    /** @param array<string,mixed> $opts */
    private static function manifest(array $opts): int
    {
        $c   = self::config($opts);
        $git = self::git($c, $opts);

        $out = is_string($opts['out'] ?? null) ? $opts['out'] : null;
        if ($out === null) {
            $dir = self::outputDir($c, $opts, 'out-dir');
            if ($dir === null) {
                throw new \RuntimeException('No output location: pass --out=FILE or set private.dir in the config (or its environment variable).');
            }
            $out = $dir . '/manifest-' . $c->slug . '.json';
        }

        $manifest = Manifest::build(new Tree($c), $git, !isset($opts['no-intro']));
        $json     = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        PrivateOutput::write($out, $json, $c->repoRoot, 'the manifest');
        echo "Manifest written: {$out} (" . count($manifest['markers']) . ' markers, ' . count($manifest['modules']) . " modules)\n";
        self::remind(dirname($out));
        return 0;
    }

    /** @param array<string,mixed> $opts */
    private static function release(array $opts): int
    {
        $c   = self::config($opts);
        $git = self::git($c, $opts);
        if ($git === null) {
            throw new \RuntimeException('A release record needs a git repository.');
        }
        $dir = self::outputDir($c, $opts, 'out-dir');
        if ($dir === null) {
            throw new \RuntimeException('No output location: pass --out-dir=DIR or set private.dir in the config (or its environment variable).');
        }
        PrivateOutput::assertOutside($dir . '/x', $c->repoRoot);

        $ref    = is_string($opts['commit'] ?? null) ? $opts['commit'] : 'HEAD';
        $commit = $git->commitOf($ref);
        if ($commit === null) {
            throw new \RuntimeException("Unknown commit '{$ref}'");
        }
        $version = Release::versionAt($c, $git, $commit);
        if ($version === null) {
            throw new \RuntimeException('The plugin version could not be read at ' . $commit . ' (see plugin.version_from).');
        }

        $builtByTool = !is_string($opts['zip'] ?? null);
        if ($builtByTool) {
            $zip = $dir . '/' . $c->slug . '-' . $version . '.zip';
            Zip::build($c, $git, $commit, $zip, false);
        } else {
            $zip = (string) $opts['zip'];
            if (!is_file($zip)) {
                throw new \RuntimeException("ZIP not found: {$zip}");
            }
        }

        $zipFindings = Finding::seal($c->slug, Zip::problems($c, Zip::entries($zip), basename($zip)));
        $errors      = array_filter($zipFindings, static function (array $f): bool {
            return $f['type'] === Finding::ERROR;
        });
        if ($errors) {
            if ($builtByTool) {
                @unlink($zip);
            }
            fwrite(STDERR, "The release ZIP is not runtime-only; no release record was written:\n");
            foreach ($errors as $f) {
                fwrite(STDERR, '  ' . $f['file'] . ': ' . $f['message'] . "\n    fix: " . $f['fix'] . "\n");
            }
            return 1;
        }

        $published = is_string($opts['published'] ?? null) ? $opts['published'] : null;
        $url       = is_string($opts['publication-url'] ?? null) ? $opts['publication-url'] : null;
        $record    = Release::record($c, $git, $commit, $zip, $builtByTool, $published, $url, $zipFindings);

        $file = $dir . '/release-record-' . $c->slug . '-' . $version . '.json';
        PrivateOutput::write($file, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", $c->repoRoot, 'the release record');

        echo 'ZIP:      ' . $record['zip']['file'] . ' (' . $record['zip']['bytes'] . ' bytes, ' . $record['zip']['entries'] . " entries)\n";
        echo 'SHA-256:  ' . $record['zip']['sha256'] . "\n";
        echo 'Commit:   ' . $record['commit'] . "\n";
        echo 'Recorded: ' . $record['recorded_at'] . '   Publication: ' . ($record['publication']['url'] ?? $record['publication']['published_on'] ?? 'not recorded') . "\n";
        echo 'Signing:  ' . $record['signing']['status'] . ' - ' . $record['signing']['detail'] . "\n";
        echo "Record:   {$file}\n";
        self::remind($dir);
        return 0;
    }

    /**
     * @param array<string,mixed> $opts
     * @param list<string> $pos
     */
    private static function verifyRelease(array $opts, array $pos): int
    {
        $file = $pos[0] ?? '';
        if ($file === '' || !is_file($file)) {
            throw new \RuntimeException('Usage: verify-release RECORD.json --config=FILE');
        }
        $c      = self::config($opts);
        $git    = self::git($c, $opts);
        if ($git === null) {
            throw new \RuntimeException('verify-release needs a git repository.');
        }
        $record = json_decode((string) file_get_contents($file), true);
        if (!is_array($record) || ($record['schema'] ?? '') !== Release::SCHEMA) {
            throw new \RuntimeException("{$file} is not a release record");
        }
        $result = Release::verify($c, $git, $record);
        if ($result['match'] === null) {
            echo 'NOT REBUILDABLE: ' . $result['detail'] . "\n";
            return 0;
        }
        echo ($result['match'] ? 'MATCH' : 'MISMATCH') . ": recorded {$result['recorded_sha256']}, rebuilt {$result['rebuilt_sha256']}\n";
        return $result['match'] ? 0 : 1;
    }

    /** @param array<string,mixed> $opts */
    private static function compare(array $opts): int
    {
        if (!is_string($opts['candidate'] ?? null)) {
            throw new \RuntimeException('compare needs --candidate=DIR (the tree to compare against the plugin in the config).');
        }
        $c      = self::config($opts);
        $report = Compare::run($c, new Tree($c), $opts['candidate']);
        $text   = (($opts['format'] ?? 'json') === 'md')
            ? Compare::markdown($report)
            : json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (is_string($opts['out'] ?? null)) {
            file_put_contents($opts['out'], $text);
            echo 'Assessment: ' . $report['assessment'] . "\n";
        } else {
            echo $text;
        }
        return 0;
    }

    /**
     * @param array<string,mixed> $opts
     * @param list<string> $pos
     */
    private static function diff(array $opts, array $pos): int
    {
        if (count($pos) < 2) {
            throw new \RuntimeException('Usage: diff OLD-REPORT.json NEW-REPORT.json [--fail-on-new]');
        }
        $old   = json_decode((string) @file_get_contents($pos[0]), true);
        $new   = json_decode((string) @file_get_contents($pos[1]), true);
        if (!is_array($old) || !is_array($new)) {
            throw new \RuntimeException('Both arguments must be JSON reports written by `check --format=json`.');
        }
        $delta = Finding::delta($old, $new);
        if (!$delta['comparable']) {
            echo 'Not comparable: ' . $delta['reason'] . "\n";
            return 2;
        }
        echo 'new: ' . count($delta['new_findings']) . ', fixed: ' . count($delta['fixed_findings']) . ', unchanged: ' . $delta['unchanged_findings'] . "\n";
        foreach ($delta['new_findings'] as $f) {
            echo '  NEW ' . $f['file'] . '  ' . $f['rule'] . '  [' . $f['fingerprint'] . "]\n";
        }
        return (isset($opts['fail-on-new']) && $delta['new_findings']) ? 1 : 0;
    }

    /** @param array<string,mixed> $opts */
    private static function demo(array $opts): int
    {
        $fixtures = is_string($opts['fixtures'] ?? null) ? $opts['fixtures'] : dirname(__DIR__, 3) . '/tests/fixtures/wp-provenance';
        $reports  = Demo::compareAll($fixtures);
        $broken   = Demo::checkBroken($fixtures);

        $failed = false;
        if (($opts['format'] ?? 'text') === 'json') {
            echo json_encode(['broken_plugin_check' => $broken, 'comparisons' => $reports], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            echo "== Deliberately broken fixture plugin ==\n" . Report::text($broken) . "\n== Comparison scenarios (invented fixtures) ==\n";
            printf("%-28s %-20s %-20s %s\n", 'candidate', 'assessment', 'expected', 'kinds of match');
        }
        foreach ($reports as $name => $report) {
            $ok      = $report['assessment'] === Demo::EXPECTED[$name];
            $failed  = $failed || !$ok;
            if (($opts['format'] ?? 'text') !== 'json') {
                $kinds = array_merge($report['kinds']['strong'], $report['kinds']['weak'], array_map(static function (string $k): string {
                    return '(' . $k . ')';
                }, $report['kinds']['informational']));
                printf("%-28s %-20s %-20s %s%s\n", $name, $report['assessment'], Demo::EXPECTED[$name], $kinds ? implode(', ', $kinds) : '-', $ok ? '' : '   <-- UNEXPECTED');
            }
        }
        if (($opts['format'] ?? 'text') !== 'json') {
            echo "\nObservations only: no scenario concludes that code was copied; brand name, same feature and WordPress boilerplate are informational.\n";
        }
        return ($failed || $broken['result']['clean']) ? 1 : 0;
    }
}
