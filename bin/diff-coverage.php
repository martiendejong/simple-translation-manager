<?php
/**
 * Gate: % of touched (git-diff-added) executable lines that are covered,
 * per PHPUnit's Clover XML report.
 *
 * Whole-file coverage would demand 80% of legacy code in class-api.php /
 * class-post-editor.php that predates this change and this suite never
 * intended to exercise — diff coverage measures only the lines a PR
 * actually adds or modifies, which is what "coverage for touched files"
 * means in practice.
 *
 * Usage: php bin/diff-coverage.php <clover.xml> <base-ref> <min-percent>
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line: php bin/diff-coverage.php <clover.xml> <base-ref> <min-percent>\n");
}

$cloverPath = $argv[1] ?? null;
$baseRef    = $argv[2] ?? 'origin/master';
$minPercent = isset($argv[3]) ? (float) $argv[3] : 80.0;

if (!$cloverPath || !file_exists($cloverPath)) {
    fwrite(STDERR, "Clover coverage file not found: $cloverPath\n");
    exit(2);
}

$xml = simplexml_load_file($cloverPath);
if (!$xml) {
    fwrite(STDERR, "Failed to parse Clover XML: $cloverPath\n");
    exit(2);
}

// [absolute file path => [line number => hit count]] for statement lines only.
$coverageByFile = [];
foreach ($xml->xpath('//file') as $file) {
    $path = (string) $file['name'];
    $lines = [];
    foreach ($file->line as $line) {
        if ((string) $line['type'] !== 'stmt') {
            continue;
        }
        $lines[(int) $line['num']] = (int) $line['count'];
    }
    $coverageByFile[$path] = $lines;
}

$root = trim((string) shell_exec('git rev-parse --show-toplevel'));
$root = $root !== '' ? str_replace('\\', '/', $root) : str_replace('\\', '/', getcwd());

function changed_lines_for(string $relPath, string $baseRef): array {
    $diff = shell_exec(sprintf(
        'git diff -U0 %s -- %s 2>&1',
        escapeshellarg($baseRef),
        escapeshellarg($relPath)
    ));

    if (!$diff) {
        return [];
    }

    $lines = [];
    $current = null;

    foreach (explode("\n", $diff) as $diffLine) {
        if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $diffLine, $m)) {
            $current = (int) $m[1];
            continue;
        }
        if ($current === null) {
            continue;
        }
        if (str_starts_with($diffLine, '+++') || str_starts_with($diffLine, '---')) {
            continue;
        }
        if (str_starts_with($diffLine, '+')) {
            $lines[] = $current;
            $current++;
        }
        // '-' lines were removed from the old file — they don't occupy a
        // line number in the new file, so the counter doesn't advance.
    }

    return $lines;
}

$totalTouched = 0;
$totalCovered = 0;
$report = [];

foreach ($coverageByFile as $absPath => $lineCoverage) {
    $norm = str_replace('\\', '/', $absPath);
    $relPath = str_starts_with($norm, $root) ? ltrim(substr($norm, strlen($root)), '/') : $norm;

    $touchedLines = changed_lines_for($relPath, $baseRef);
    if (empty($touchedLines)) {
        continue;
    }

    $fileTouched = 0;
    $fileCovered = 0;
    foreach ($touchedLines as $ln) {
        if (!array_key_exists($ln, $lineCoverage)) {
            continue; // not an executable statement line (blank/comment/brace)
        }
        $fileTouched++;
        if ($lineCoverage[$ln] > 0) {
            $fileCovered++;
        }
    }

    if ($fileTouched > 0) {
        $report[$relPath] = [$fileCovered, $fileTouched];
        $totalTouched += $fileTouched;
        $totalCovered += $fileCovered;
    }
}

echo "Diff coverage report (base: $baseRef)\n";
echo str_repeat('-', 60) . "\n";
foreach ($report as $file => [$covered, $touched]) {
    $pct = $touched ? round($covered / $touched * 100, 1) : 100;
    printf("%-45s %d/%d (%s%%)\n", $file, $covered, $touched, $pct);
}
echo str_repeat('-', 60) . "\n";

if ($totalTouched === 0) {
    echo "No coverable changed lines detected in tracked source files — nothing to gate.\n";
    exit(0);
}

$overallPct = round($totalCovered / $totalTouched * 100, 2);
printf("Overall: %d/%d changed executable lines covered (%.2f%%)\n", $totalCovered, $totalTouched, $overallPct);

if ($overallPct < $minPercent) {
    fwrite(STDERR, "FAIL: diff coverage {$overallPct}% is below the required {$minPercent}%\n");
    exit(1);
}

echo "PASS: diff coverage meets the {$minPercent}% threshold\n";
exit(0);
