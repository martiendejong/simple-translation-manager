<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.reportstore

declare(strict_types=1);

namespace WpPluginCheck;

/**
 * Scan reports on disk, one folder per scan: <root>/<slug>/<scan id>-<zip hash prefix>/report.json.
 * The folder name carries the scan id, so two scans never overwrite each other and the history
 * (last scan, delta against the one before) can be rebuilt from the files alone.
 */
final class ReportStore
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    /** Where the report of a scan of this ZIP belongs. */
    public function reportDir(string $slug, string $scanTime, string $zipSha256): string
    {
        return $this->root . '/' . $slug . '/' . $scanTime . '-' . substr($zipSha256, 0, 12);
    }

    /**
     * Every readable report of a plugin, oldest first. A folder that holds no valid report is skipped.
     *
     * @return list<array{path:string,report:array<string,mixed>}>
     */
    public function reports(string $slug): array
    {
        $found = [];
        foreach (glob($this->root . '/' . $slug . '/*/report.json') ?: [] as $path) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data) && ($data['schema'] ?? '') === Scanner::REPORT_SCHEMA) {
                $found[] = ['path' => str_replace('\\', '/', $path), 'report' => $data];
            }
        }
        usort($found, static fn (array $a, array $b): int => [(string) ($a['report']['scan']['started_at'] ?? ''), (string) ($a['report']['scan']['id'] ?? '')] <=> [(string) ($b['report']['scan']['started_at'] ?? ''), (string) ($b['report']['scan']['id'] ?? '')]);
        return $found;
    }

    /**
     * The newest complete, non-partial report, optionally not counting one scan id.
     *
     * @return array{path:string,report:array<string,mixed>}|null
     */
    public function latestComplete(string $slug, ?string $exceptScanId = null): ?array
    {
        $all = array_reverse($this->reports($slug));
        foreach ($all as $entry) {
            $scan = $entry['report']['scan'] ?? [];
            if (($scan['status'] ?? '') === 'complete' && empty($scan['partial']) && ($exceptScanId === null || ($scan['id'] ?? '') !== $exceptScanId)) {
                return $entry;
            }
        }
        return null;
    }

    /** One scan, reduced to what a status screen shows. @param array<string,mixed> $report @return array<string,mixed> */
    public static function describe(array $report, string $path = ''): array
    {
        return [
            'scan_id'     => $report['scan']['id'] ?? null,
            'status'      => $report['scan']['status'] ?? 'unknown',
            'partial'     => !empty($report['scan']['partial']),
            'reason'      => $report['scan']['status_reason'] ?? '',
            'started_at'  => $report['scan']['started_at'] ?? null,
            'finished_at' => $report['scan']['finished_at'] ?? null,
            'zip_sha256'  => $report['subject']['zip_sha256'] ?? null,
            'version'     => $report['subject']['plugin_version'] ?? null,
            'commit'      => $report['subject']['commit'] ?? null,
            'errors'      => $report['result']['errors'] ?? null,
            'warnings'    => $report['result']['warnings'] ?? null,
            'clean'       => $report['result']['clean'] ?? null,
            'environment' => $report['environment'] ?? null,
            'report'      => $path,
        ];
    }
}
