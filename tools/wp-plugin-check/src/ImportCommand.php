<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.importcommand

declare(strict_types=1);

namespace WpPluginCheck;

/**
 * php scan.php import <report.json> --board=ID [--api=URL] [--key-file=FILE] [--status=backlog] [--max-new=25] [--apply]
 *
 * Without --apply nothing is written: the output lists what would be created and commented.
 * Exit codes: 0 imported (or dry run) ok, 2 error, 3 report not importable (failed or partial scan: nothing was touched).
 */
final class ImportCommand
{
    public const DEFAULT_API = 'https://tasks.prospergenics.com/api/v2';

    /**
     * @param list<string>              $positional
     * @param array<string,string|bool> $options
     */
    public static function run(array $positional, array $options, ?callable $transport = null): int
    {
        if (empty($positional[0]) || empty($options['board']) || !is_string($options['board'])) {
            fwrite(STDERR, "Usage: php scan.php import <report.json> --board=ID [--api=URL] [--key-file=FILE] [--status=backlog] [--max-new=25] [--state=FILE] [--apply]\n");
            return 64;
        }
        $report = Cli::readJson($positional[0]);

        // A report that must not be imported is refused before any key is read or any request is made.
        $reason = Importer::rejectReason($report);
        $summary = null;
        if ($reason === null) {
            $api      = new JengoWork(is_string($options['api'] ?? null) ? $options['api'] : self::DEFAULT_API, Importer::readKey(is_string($options['key-file'] ?? null) ? $options['key-file'] : null), $transport);
            $importer = new Importer($api, $options['board'], is_string($options['status'] ?? null) ? $options['status'] : 'backlog');
            $summary  = $importer->run($report, !empty($options['apply']), is_string($options['max-new'] ?? null) ? max(0, (int) $options['max-new']) : 25);
        } else {
            $summary = (new Importer(new JengoWork(self::DEFAULT_API, 'unused', static function (): array {
                throw new \LogicException('A rejected report must not cause a request');
            }), $options['board']))->run($report, false);
        }

        if (is_string($options['state'] ?? null) && $options['state'] !== '') {
            self::appendState($options['state'], $summary);
        }
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return $summary['accepted'] ? 0 : 3;
    }

    /** A line per import in a JSON-lines audit file: when, which scan, what happened. @param array<string,mixed> $summary */
    private static function appendState(string $file, array $summary): void
    {
        $line = json_encode([
            'at'                => gmdate('c'),
            'mode'              => $summary['mode'],
            'accepted'          => $summary['accepted'],
            'slug'              => $summary['slug'],
            'scan_id'           => $summary['scan_id'],
            'created'           => array_column($summary['created'], 'id'),
            'updated'           => array_column($summary['updated'], 'id'),
            'review_candidates' => array_column($summary['review_candidates'], 'id'),
            'reappeared'        => array_column($summary['reappeared'], 'id'),
            'unchanged'         => $summary['unchanged'],
            'deferred'          => $summary['deferred'],
            'reason'            => $summary['reason'],
        ], JSON_UNESCAPED_SLASHES);
        file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
