<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.statuscommand

declare(strict_types=1);

namespace WpPluginCheck;

/**
 * php scan.php status <slug> --reports-dir=DIR [--board=ID --api=URL --key-file=FILE] [--format=json|md]
 *
 * What a status screen shows for one plugin: the last scan attempt, the last complete scan, the delta against
 * the complete scan before it, and the linked Jengo Work tasks (when a board is given).
 */
final class StatusCommand
{
    /**
     * @param list<string>              $positional
     * @param array<string,string|bool> $options
     */
    public static function run(array $positional, array $options, ?callable $transport = null): int
    {
        if (empty($positional[0]) || empty($options['reports-dir']) || !is_string($options['reports-dir'])) {
            fwrite(STDERR, "Usage: php scan.php status <slug> --reports-dir=DIR [--board=ID] [--api=URL] [--key-file=FILE] [--format=json|md]\n");
            return 64;
        }
        $slug   = $positional[0];
        $tasks  = null;
        if (is_string($options['board'] ?? null) && $options['board'] !== '') {
            $api   = new JengoWork(is_string($options['api'] ?? null) ? $options['api'] : ImportCommand::DEFAULT_API, Importer::readKey(is_string($options['key-file'] ?? null) ? $options['key-file'] : null), $transport);
            $tasks = $api->listTasks($options['board']);
        }
        $status = self::build((new ReportStore($options['reports-dir']))->reports($slug), $slug, $tasks);
        echo (($options['format'] ?? 'json') === 'md' ? self::markdown($status) : json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return $status['last_attempt'] === null ? 1 : 0;
    }

    /**
     * @param list<array{path:string,report:array<string,mixed>}> $reports oldest first
     * @param list<array<string,mixed>>|null                      $boardTasks
     * @return array<string,mixed>
     */
    public static function build(array $reports, string $slug, ?array $boardTasks = null): array
    {
        $attempt  = $reports ? end($reports) : null;
        $complete = [];
        foreach ($reports as $entry) {
            $scan = $entry['report']['scan'] ?? [];
            if (($scan['status'] ?? '') === 'complete' && empty($scan['partial'])) {
                $complete[] = $entry;
            }
        }
        $last     = $complete ? end($complete) : null;
        $previous = count($complete) >= 2 ? $complete[count($complete) - 2] : null;

        $delta = null;
        if ($last !== null && $previous !== null) {
            $d     = Delta::compare($previous['report'], $last['report']);
            $delta = [
                'comparable' => $d['comparable'],
                'new'        => count($d['new_findings']),
                'fixed'      => count($d['fixed_findings']),
                'unchanged'  => $d['unchanged_findings'],
                'since_scan' => $d['old']['scan_id'],
            ];
        }

        $taskList = null;
        if ($boardTasks !== null) {
            $taskList = [];
            foreach (Importer::indexByMarker($boardTasks, strtolower($slug)) as $gid => $tasks) {
                foreach ($tasks as $task) {
                    $taskList[] = [
                        'id'     => (string) $task['id'],
                        'name'   => (string) ($task['name'] ?? ''),
                        'status' => (string) ($task['status']['status'] ?? ''),
                        'open'   => Importer::isOpen($task),
                        'url'    => $task['url'] ?? null,
                        'group'  => $gid,
                    ];
                }
            }
        }

        return [
            'slug'              => $slug,
            'reports_found'     => count($reports),
            'last_attempt'      => $attempt === null ? null : ReportStore::describe($attempt['report'], $attempt['path']),
            'last_complete'     => $last === null ? null : ReportStore::describe($last['report'], $last['path']),
            'previous_complete' => $previous === null ? null : ReportStore::describe($previous['report'], $previous['path']),
            'delta'             => $delta,
            'attempt_is_newer_than_complete' => $attempt !== null && $last !== null && $attempt['path'] !== $last['path'],
            'tasks'             => $taskList,
            'open_tasks'        => $taskList === null ? null : count(array_filter($taskList, static fn (array $t): bool => $t['open'])),
        ];
    }

    /** @param array<string,mixed> $s */
    public static function markdown(array $s): string
    {
        $out = ["# Plugin Check status: {$s['slug']}", ''];
        if ($s['last_attempt'] === null) {
            return implode("\n", $out) . "No scan report found.\n";
        }
        $a = $s['last_attempt'];
        $out[] = "- Last attempt: scan `{$a['scan_id']}` **{$a['status']}**" . ($a['partial'] ? ' (partial)' : '') . ($a['status'] !== 'complete' ? ' - ' . $a['reason'] : '');
        if ($s['last_complete'] !== null) {
            $c = $s['last_complete'];
            $out[] = "- Last complete scan: `{$c['scan_id']}`, version {$c['version']}, ZIP `" . substr((string) $c['zip_sha256'], 0, 12) . "`, {$c['errors']} errors / {$c['warnings']} warnings" . ($c['clean'] ? ' (clean)' : '');
        } else {
            $out[] = '- No complete scan yet.';
        }
        if ($s['delta'] !== null) {
            $d = $s['delta'];
            $out[] = "- Delta since `{$d['since_scan']}`: {$d['new']} new, {$d['fixed']} fixed, {$d['unchanged']} unchanged";
        }
        if ($s['tasks'] !== null) {
            $out[] = "- Tasks: {$s['open_tasks']} open of " . count($s['tasks']);
            foreach ($s['tasks'] as $t) {
                $out[] = "  - #{$t['id']} [{$t['status']}] {$t['name']}";
            }
        }
        return implode("\n", $out) . "\n";
    }
}
