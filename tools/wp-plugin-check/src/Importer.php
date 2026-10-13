<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.importer

declare(strict_types=1);

namespace WpPluginCheck;

use RuntimeException;

/**
 * Turns one scan report into Jengo Work tasks: one bounded task per root cause.
 *
 * The rules, each of them pinned by a test:
 *  - Only a COMPLETE, non-partial scan is imported. A failed or partial scan touches nothing,
 *    not even to read the board, so a broken scan can never close or reopen anything.
 *  - A task is found again by the marker in its description (plugin slug + group id), not by its
 *    title or by a local state file, so a lost state file or a renamed task does not cause duplicates.
 *    The group id is built from the check code and the file, not from line numbers: moved code keeps its task.
 *  - Importing the same report again changes nothing: no task, no comment.
 *  - A finding that is gone from a complete scan makes its open task a "Review candidate": one comment,
 *    never a status change. A person (or a human-owned review step) closes it.
 *  - A root cause whose task is already closed but that is reported again gets a NEW task that points back.
 *  - New tasks start in the board's intake status (default backlog) with no priority, so the board's own
 *    refinement and gates apply. At most $maxNew tasks are created per run; the rest is reported as deferred.
 */
final class Importer
{
    public const TASK_MARKER = '/\[wpc:([a-z0-9][a-z0-9._-]*):([a-f0-9]{16})\]/';
    public const FP_MARKER   = '/\[wpc-fp:([a-f0-9]{12})\]/';
    public const REVIEW_MARK = '/\[wpc-review:([A-Za-z0-9._-]+)\]/';
    public const MAX_LISTED  = 20;

    private const CLOSED = ['done', 'closed', 'complete', 'completed', 'obsolete', 'accept obsolete', 'archive', 'archived'];

    private JengoWork $api;
    private string $board;
    private string $intakeStatus;

    public function __construct(JengoWork $api, string $board, string $intakeStatus = 'backlog')
    {
        $this->api          = $api;
        $this->board        = $board;
        $this->intakeStatus = $intakeStatus;
    }

    /**
     * @param array<string,mixed> $report a report.json written by `scan`
     * @return array<string,mixed> what was (or, in a dry run, would be) done
     */
    public function run(array $report, bool $apply, int $maxNew = 25): array
    {
        $summary = [
            'mode'              => $apply ? 'apply' : 'dry-run',
            'accepted'          => false,
            'reason'            => '',
            'slug'              => $report['subject']['slug'] ?? null,
            'scan_id'           => $report['scan']['id'] ?? null,
            'groups'            => 0,
            'created'           => [],
            'would_create'      => [],
            'deferred'          => 0,
            'updated'           => [],
            'would_update'      => [],
            'review_candidates' => [],
            'reappeared'        => [],
            'unchanged'         => 0,
        ];

        $reason = self::rejectReason($report);
        if ($reason !== null) {
            $summary['reason'] = $reason;
            return $summary;
        }
        $summary['accepted'] = true;

        $slug     = strtolower((string) $report['subject']['slug']);
        $findings = self::allFindings($report);
        $groups   = Fingerprint::groups($findings);
        $summary['groups'] = count($groups);

        // 1. Read the board. Nothing is written before this has fully succeeded.
        $existing = self::indexByMarker($this->api->listTasks($this->board), $slug);

        $plan = ['create' => [], 'comment' => []];
        $seen = [];
        foreach ($groups as $group) {
            $gid        = Fingerprint::groupId($slug, $group['key']);
            $seen[$gid] = true;
            $fpHash     = self::fingerprintHash($group['findings']);
            $open       = null;
            $closed     = null;
            foreach ($existing[$gid] ?? [] as $task) {
                if (self::isOpen($task)) {
                    $open = $open ?? $task;
                } else {
                    $closed = $closed ?? $task;
                }
            }

            if ($open === null) {
                $plan['create'][] = ['group' => $group, 'gid' => $gid, 'fp' => $fpHash, 'regression_of' => $closed['id'] ?? null];
                continue;
            }

            $state = self::stateFromComments($open, $this->api->comments((string) $open['id']));
            if ($state['last'] === 'review') {
                $plan['comment'][] = ['task' => $open, 'kind' => 'reappeared', 'text' => self::reappearedComment($report, $group, $fpHash)];
            } elseif ($state['fp'] !== $fpHash) {
                $plan['comment'][] = ['task' => $open, 'kind' => 'changed', 'text' => self::changedComment($report, $group, $fpHash)];
            } else {
                $summary['unchanged']++;
            }
        }

        // 2. Open tasks of this plugin whose root cause is no longer reported: Review candidates, nothing more.
        foreach ($existing as $gid => $tasks) {
            if (isset($seen[$gid])) {
                continue;
            }
            foreach ($tasks as $task) {
                if (!self::isOpen($task)) {
                    continue;
                }
                $state = self::stateFromComments($task, $this->api->comments((string) $task['id']));
                if ($state['last'] === 'review') {
                    $summary['unchanged']++;
                    continue;
                }
                $plan['comment'][] = ['task' => $task, 'kind' => 'review', 'text' => self::reviewComment($report)];
            }
        }

        // 3. Carry the plan out (or just describe it).
        $created = 0;
        foreach ($plan['create'] as $item) {
            $entry = ['group' => $item['group']['key'], 'findings' => $item['group']['total'], 'type' => $item['group']['type']];
            if ($created >= $maxNew) {
                $summary['deferred']++;
                continue;
            }
            if (!$apply) {
                $summary['would_create'][] = $entry + ['regression_of' => $item['regression_of']];
                $created++;
                continue;
            }
            $task = $this->api->createTask(
                $this->board,
                self::taskName($slug, $item['group']),
                self::taskDescription($report, $slug, $item['group'], $item['gid'], $item['fp'], $item['regression_of']),
                $this->intakeStatus
            );
            $summary['created'][] = $entry + ['id' => (string) $task['id'], 'url' => $task['url'] ?? null, 'regression_of' => $item['regression_of']];
            $created++;
        }
        foreach ($plan['comment'] as $item) {
            $entry = ['id' => (string) $item['task']['id'], 'name' => (string) ($item['task']['name'] ?? ''), 'kind' => $item['kind']];
            if ($apply) {
                $this->api->comment((string) $item['task']['id'], $item['text']);
            }
            if ($item['kind'] === 'review') {
                $summary['review_candidates'][] = $entry;
            } elseif ($item['kind'] === 'reappeared') {
                $summary['reappeared'][] = $entry;
            } else {
                $summary[$apply ? 'updated' : 'would_update'][] = $entry;
            }
        }
        return $summary;
    }

    /** Why this report must not be imported, or null when it may be. */
    public static function rejectReason(array $report): ?string
    {
        if (($report['schema'] ?? '') !== Scanner::REPORT_SCHEMA) {
            return 'Not a wp-plugin-check report (schema ' . json_encode($report['schema'] ?? null) . ').';
        }
        $status = $report['scan']['status'] ?? 'unknown';
        if ($status !== 'complete' || !empty($report['scan']['partial'])) {
            return "The scan is '{$status}'" . (!empty($report['scan']['partial']) ? ' and partial' : '') . ', so it says nothing about what is fixed. Nothing was imported and no existing task was touched.';
        }
        if (!isset($report['subject']['slug']) || !preg_match('/^[a-z0-9][a-z0-9._-]*$/i', (string) $report['subject']['slug'])) {
            return 'The report has no usable plugin slug.';
        }
        if (!is_array($report['findings'] ?? null) || !is_array($report['result'] ?? null)) {
            return 'The report has no findings list or result.';
        }
        foreach ($report['findings'] as $f) {
            if (empty($f['fingerprint']) || empty($f['root_cause'])) {
                return 'A finding has no fingerprint; re-run the scan with this version of the tool.';
            }
        }
        return null;
    }

    /**
     * Plugin Check findings plus the scanner's own file-level problems (suppressions without a reason,
     * development files in the ZIP, secret-looking strings), all with fingerprints.
     *
     * @return list<array<string,mixed>>
     */
    public static function allFindings(array $report): array
    {
        $slug  = (string) $report['subject']['slug'];
        $extra = [];
        foreach ($report['suppressions']['problems'] ?? [] as $p) {
            $extra[] = [
                'check'    => 'scanner.suppression.' . ($p['problem'] ?? 'problem'),
                'type'     => 'WARNING',
                'severity' => null,
                'file'     => (string) ($p['file'] ?? ''),
                'line'     => (int) ($p['line'] ?? 0),
                'column'   => 0,
                'message'  => trim((string) ($p['detail'] ?? '') . ' (' . implode(', ', (array) ($p['rules'] ?? [])) . ')'),
                'docs'     => '',
            ];
        }
        foreach ($report['hygiene'] ?? [] as $h) {
            $extra[] = [
                'check'    => 'scanner.zip.' . ($h['kind'] ?? 'problem'),
                'type'     => 'ERROR',
                'severity' => null,
                'file'     => (string) ($h['file'] ?? ''),
                'line'     => 0,
                'column'   => 0,
                'message'  => (string) ($h['detail'] ?? ''),
                'docs'     => '',
            ];
        }
        $extra = Fingerprint::apply($slug, $extra, []);
        $all   = array_merge($report['findings'], $extra);
        usort($all, static fn (array $a, array $b): int => [$a['file'], $a['line'], $a['check']] <=> [$b['file'], $b['line'], $b['check']]);
        return $all;
    }

    /** Short stable hash of a group's fingerprints: changes when a finding is added, removed or edited. */
    public static function fingerprintHash(array $findings): string
    {
        $fps = array_map(static fn (array $f): string => (string) $f['fingerprint'], $findings);
        sort($fps);
        return substr(hash('sha256', implode(',', $fps)), 0, 12);
    }

    /**
     * Tasks of this plugin by group id, newest id first.
     *
     * @param list<array<string,mixed>> $tasks
     * @return array<string,list<array<string,mixed>>>
     */
    public static function indexByMarker(array $tasks, string $slug): array
    {
        $index = [];
        foreach ($tasks as $task) {
            if (!preg_match_all(self::TASK_MARKER, (string) ($task['description'] ?? ''), $m, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($m as $hit) {
                if (strtolower($hit[1]) === $slug) {
                    $index[$hit[2]][] = $task;
                }
            }
        }
        foreach ($index as &$list) {
            usort($list, static fn (array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);
        }
        return $index;
    }

    public static function isOpen(array $task): bool
    {
        $status = strtolower(trim((string) ($task['status']['status'] ?? $task['status'] ?? '')));
        return !in_array($status, self::CLOSED, true);
    }

    /**
     * What this tool last said on a task: the fingerprint hash it recorded, and whether the last thing
     * it wrote was a Review-candidate note. Comment order is taken from the date, not the list order
     * (migrated boards return comments in reverse).
     *
     * @param list<array<string,mixed>> $comments
     * @return array{fp:?string,last:?string}
     */
    public static function stateFromComments(array $task, array $comments): array
    {
        $fp   = null;
        $last = null;
        if (preg_match(self::FP_MARKER, (string) ($task['description'] ?? ''), $m)) {
            $fp   = $m[1];
            $last = 'fp';
        }
        usort($comments, static fn (array $a, array $b): int => [(int) ($a['date'] ?? 0), (int) ($a['id'] ?? 0)] <=> [(int) ($b['date'] ?? 0), (int) ($b['id'] ?? 0)]);
        foreach ($comments as $c) {
            $text = (string) ($c['comment_text'] ?? '');
            $isFp = preg_match(self::FP_MARKER, $text, $m1) === 1;
            if (preg_match(self::REVIEW_MARK, $text) === 1) {
                $last = 'review';
            } elseif ($isFp) {
                $fp   = $m1[1];
                $last = 'fp';
            }
        }
        return ['fp' => $fp, 'last' => $last];
    }

    /** @param array<string,mixed> $group */
    public static function taskName(string $slug, array $group): string
    {
        $where = $group['file'] . ($group['anchor'] !== '' && $group['anchor'] !== '(file scope)' ? ' :: ' . $group['anchor'] : '');
        $name  = "Plugin Check [{$slug}]: {$group['check']} in {$where}";
        $name  = Ascii::clean($name);
        return strlen($name) > 190 ? substr($name, 0, 187) . '...' : $name;
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $group
     */
    public static function taskDescription(array $report, string $slug, array $group, string $gid, string $fpHash, ?string $regressionOf): string
    {
        $s   = $report['subject'];
        $env = $report['environment'] ?? [];
        $out = [];
        $out[] = '**Plugin Check finding group** - one root cause, imported by wp-plugin-check from scan `' . ($report['scan']['id'] ?? '?') . '`.';
        $out[] = '';
        if ($regressionOf !== null) {
            $out[] = "**Regression:** an earlier task (#{$regressionOf}) for this root cause was closed, but a complete scan reports it again.";
            $out[] = '';
        }
        $out[] = '**Plugin:** ' . ($s['plugin_name'] ?? $slug) . " (`{$slug}` " . ($s['plugin_version'] ?? '?') . ')';
        $out[] = "**Root cause:** `{$group['check']}` in `{$group['file']}`" . ($group['anchor'] !== '' && $group['anchor'] !== '(file scope)' ? " (symbol `{$group['anchor']}`)" : '');
        $out[] = '**Severity:** ' . $group['type'] . ' - ' . $group['total'] . ' finding' . ($group['total'] === 1 ? '' : 's');
        $out[] = '**Evidence:** official Plugin Check ' . ($env['plugin_check'] ?? '?') . ' on WordPress ' . ($env['wordpress'] ?? '?') . ' / PHP ' . ($env['php'] ?? '?')
            . '; release ZIP sha256 `' . ($s['zip_sha256'] ?? '?') . '`' . (!empty($s['commit']) ? '; commit `' . $s['commit'] . '`' : '')
            . '; scan status ' . ($report['scan']['status'] ?? '?') . ' (runtime checks verified by the canary).';
        $out[] = '';
        $out[] = '**Findings**' . ($group['total'] > self::MAX_LISTED ? ' (first ' . self::MAX_LISTED . ' of ' . $group['total'] . '; the rest are in the report)' : '');
        $out[] = '';
        $out[] = '| Location | Message |';
        $out[] = '|---|---|';
        foreach (array_slice($group['findings'], 0, self::MAX_LISTED) as $f) {
            $out[] = '| `' . $f['file'] . ($f['line'] ? ':' . $f['line'] : '') . '` | ' . str_replace('|', '/', self::oneLine((string) $f['message'])) . ' |';
        }
        $docs = '';
        foreach ($group['findings'] as $f) {
            if (!empty($f['docs'])) {
                $docs = (string) $f['docs'];
                break;
            }
        }
        $out[] = '';
        $out[] = '**What to do**';
        $out[] = 'Fix the cause in the source. Do not add a global suppression. A real false positive may get a `phpcs:ignore` or a `phpcs:disable`/`phpcs:enable` pair on that one statement, with a written reason after ` -- `.' . ($docs !== '' ? ' Docs: ' . $docs : '');
        $out[] = '';
        $out[] = '**Done when**';
        $out[] = '- [ ] A new complete scan of the release ZIP no longer reports this root cause (same command, same pinned versions).';
        $out[] = '- [ ] No suppression without a reason, no file-wide or blanket suppression was added to get there.';
        $out[] = '- [ ] A person has looked at the result and closed this task. The importer only marks it as a Review candidate; it never closes it.';
        $out[] = '';
        $out[] = "Markers (do not edit): [wpc:{$slug}:{$gid}] [wpc-fp:{$fpHash}]";
        return Ascii::clean(implode("\n", $out));
    }

    /** @param array<string,mixed> $group */
    private static function listing(array $group): string
    {
        $lines = [];
        foreach (array_slice($group['findings'], 0, self::MAX_LISTED) as $f) {
            $lines[] = '- ' . $f['file'] . ($f['line'] ? ':' . $f['line'] : '') . ' ' . self::oneLine((string) $f['message']);
        }
        if ($group['total'] > self::MAX_LISTED) {
            $lines[] = '- ... and ' . ($group['total'] - self::MAX_LISTED) . ' more in the report';
        }
        return implode("\n", $lines);
    }

    /** @param array<string,mixed> $report @param array<string,mixed> $group */
    public static function changedComment(array $report, array $group, string $fpHash): string
    {
        return Ascii::clean("Scan {$report['scan']['id']} (ZIP " . substr((string) $report['subject']['zip_sha256'], 0, 12) . "): this root cause now has {$group['total']} finding(s); the list changed since the last import.\n"
            . self::listing($group) . "\n[wpc-fp:{$fpHash}]");
    }

    /** @param array<string,mixed> $report @param array<string,mixed> $group */
    public static function reappearedComment(array $report, array $group, string $fpHash): string
    {
        return Ascii::clean("Reappeared: scan {$report['scan']['id']} (ZIP " . substr((string) $report['subject']['zip_sha256'], 0, 12) . ") reports this root cause again with {$group['total']} finding(s), after it had been marked a Review candidate.\n"
            . self::listing($group) . "\n[wpc-fp:{$fpHash}]");
    }

    /** @param array<string,mixed> $report */
    public static function reviewComment(array $report): string
    {
        return Ascii::clean("Review candidate: the complete scan {$report['scan']['id']} of ZIP " . substr((string) $report['subject']['zip_sha256'], 0, 12)
            . ' no longer reports this root cause. Please check the fix and close the task yourself; the importer never moves a task to done.'
            . " [wpc-review:{$report['scan']['id']}]");
    }

    private static function oneLine(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        return strlen($text) > 300 ? substr($text, 0, 297) . '...' : $text;
    }

    /** Used by the CLI: read the Jengo Work key from a file or the environment, never from an argument. */
    public static function readKey(?string $file): string
    {
        $key = '';
        if ($file !== null && $file !== '') {
            if (!is_file($file)) {
                throw new RuntimeException("Key file not found: {$file}");
            }
            $key = trim((string) strtok((string) file_get_contents($file), "\r\n"));
        } elseif (getenv('JENGOWORK_API_KEY')) {
            $key = trim((string) getenv('JENGOWORK_API_KEY'));
        }
        if ($key === '') {
            throw new RuntimeException('No Jengo Work key: pass --key-file=FILE (first line) or set JENGOWORK_API_KEY.');
        }
        return $key;
    }
}
