<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.cli


declare(strict_types=1);

namespace WpPluginCheck;

use Throwable;

/** Command line front end. See scan.php for the usage lines. */
final class Cli
{
    /** @param string[] $argv */
    public static function main(array $argv, string $toolDir): int
    {
        $args    = array_slice($argv, 1);
        $command = array_shift($args) ?? '';
        [$positional, $options] = self::parse($args);

        try {
            switch ($command) {
                case 'scan':
                    return self::scan($positional, $options, $toolDir);
                case 'compare':
                    return self::compare($positional, $options);
                case 'import':
                    return ImportCommand::run($positional, $options);
                case 'status':
                    return StatusCommand::run($positional, $options);
                default:
                    fwrite(STDERR, "Usage: php scan.php <scan|compare|import|status> ... (see the header of scan.php and README.md)\n");
                    return 64;
            }
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
            return Scanner::EXIT_FAILED;
        }
    }

    /**
     * @param string[] $args
     * @return array{0:list<string>,1:array<string,string|bool>}
     */
    public static function parse(array $args): array
    {
        $positional = [];
        $options    = [];
        foreach ($args as $arg) {
            if (strpos($arg, '--') === 0) {
                $eq = strpos($arg, '=');
                if ($eq === false) {
                    $options[substr($arg, 2)] = true;
                } else {
                    $options[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
                }
            } else {
                $positional[] = $arg;
            }
        }
        return [$positional, $options];
    }

    /**
     * @param list<string>               $positional
     * @param array<string,string|bool>  $options
     */
    private static function scan(array $positional, array $options, string $toolDir): int
    {
        $zip = $positional[0] ?? '';
        if ($zip === '' || empty($options['out-dir']) || !is_string($options['out-dir'])) {
            fwrite(STDERR, "Usage: php scan.php scan <plugin.zip> --out-dir=DIR [--commit=SHA] [--php=BIN] [--mariadb-bin=DIR] [--work-dir=DIR] [--checks=a,b] [--keep-sandbox]\n");
            return 64;
        }
        $workDir = is_string($options['work-dir'] ?? null) ? $options['work-dir'] : sys_get_temp_dir() . '/wp-plugin-check';
        $scanner = new Scanner(
            $toolDir,
            $workDir,
            is_string($options['php'] ?? null) ? $options['php'] : null,
            is_string($options['mariadb-bin'] ?? null) ? $options['mariadb-bin'] : null
        );

        $report = $scanner->scan($zip, [
            'out_dir'      => $options['out-dir'],
            'commit'       => is_string($options['commit'] ?? null) ? $options['commit'] : null,
            'checks'       => is_string($options['checks'] ?? null) ? $options['checks'] : null,
            'keep_sandbox' => !empty($options['keep-sandbox']),
        ]);

        $summary = [
            'status'     => $report['scan']['status'],
            'reason'     => $report['scan']['status_reason'],
            'scan_id'    => $report['scan']['id'],
            'zip_sha256' => $report['subject']['zip_sha256'] ?? null,
            'errors'     => $report['result']['errors'] ?? null,
            'warnings'   => $report['result']['warnings'] ?? null,
            'clean'      => $report['result']['clean'] ?? false,
            'report'     => rtrim((string) $options['out-dir'], '/\\') . '/report.json',
        ];
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return Scanner::exitCode($report);
    }

    /**
     * @param list<string>               $positional
     * @param array<string,string|bool>  $options
     */
    private static function compare(array $positional, array $options): int
    {
        if (count($positional) < 2) {
            fwrite(STDERR, "Usage: php scan.php compare <old-report.json> <new-report.json> [--format=json|md]\n");
            return 64;
        }
        $old   = self::readJson($positional[0]);
        $new   = self::readJson($positional[1]);
        $delta = Delta::compare($old, $new);
        echo ($options['format'] ?? 'json') === 'md' ? Delta::markdown($delta) : json_encode($delta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return $delta['comparable'] ? 0 : Scanner::EXIT_FAILED;
    }

    /** @return array<string,mixed> */
    public static function readJson(string $path): array
    {
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($data)) {
            throw new \RuntimeException("Cannot read JSON from {$path}");
        }
        return $data;
    }
}
