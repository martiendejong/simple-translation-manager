#!/usr/bin/env node
/**
 * Gate: % of touched (git-diff-added) executable lines that are covered,
 * per Jest's lcov.info report.
 *
 * Whole-file coverage would demand 80% of legacy code in
 * admin-post-editor.js unrelated to this change — diff coverage measures
 * only the lines a PR actually adds or modifies.
 *
 * Usage: node bin/diff-coverage.js <lcov.info> <base-ref> <min-percent>
 */

const fs = require('fs');
const { execSync } = require('child_process');

const [, , lcovPath, baseRefArg, minPercentArg] = process.argv;
const baseRef = baseRefArg || 'origin/master';
const minPercent = minPercentArg ? parseFloat(minPercentArg) : 80;

if (!lcovPath || !fs.existsSync(lcovPath)) {
    console.error(`lcov file not found: ${lcovPath}`);
    process.exit(2);
}

const lcov = fs.readFileSync(lcovPath, 'utf8');
const files = {};
let currentFile = null;

for (const line of lcov.split('\n')) {
    if (line.startsWith('SF:')) {
        currentFile = line.slice(3).trim();
        files[currentFile] = {};
    } else if (line.startsWith('DA:') && currentFile) {
        const [num, hits] = line.slice(3).split(',');
        files[currentFile][parseInt(num, 10)] = parseInt(hits, 10);
    } else if (line.startsWith('end_of_record')) {
        currentFile = null;
    }
}

function changedLines(relPath) {
    let diff;
    try {
        diff = execSync(`git diff -U0 ${baseRef} -- "${relPath}"`, { encoding: 'utf8' });
    } catch (e) {
        return [];
    }

    const result = [];
    let current = null;

    for (const line of diff.split('\n')) {
        const m = line.match(/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/);
        if (m) {
            current = parseInt(m[1], 10);
            continue;
        }
        if (current === null) continue;
        if (line.startsWith('+++') || line.startsWith('---')) continue;
        if (line.startsWith('+')) {
            result.push(current);
            current++;
        }
        // '-' lines were removed from the old file — no new-file line number.
    }

    return result;
}

let root;
try {
    root = execSync('git rev-parse --show-toplevel', { encoding: 'utf8' }).trim().replace(/\\/g, '/');
} catch (e) {
    root = process.cwd().replace(/\\/g, '/');
}

let totalTouched = 0;
let totalCovered = 0;
const report = [];

for (const [absPath, lineCoverage] of Object.entries(files)) {
    const norm = absPath.replace(/\\/g, '/');
    const relPath = norm.startsWith(root) ? norm.slice(root.length).replace(/^\/+/, '') : norm;

    const touchedLines = changedLines(relPath);
    if (!touchedLines.length) continue;

    let fileTouched = 0;
    let fileCovered = 0;
    for (const ln of touchedLines) {
        if (!(ln in lineCoverage)) continue; // not an instrumented executable line
        fileTouched++;
        if (lineCoverage[ln] > 0) fileCovered++;
    }

    if (fileTouched > 0) {
        report.push([relPath, fileCovered, fileTouched]);
        totalTouched += fileTouched;
        totalCovered += fileCovered;
    }
}

console.log(`Diff coverage report (base: ${baseRef})`);
console.log('-'.repeat(60));
for (const [file, covered, touched] of report) {
    const pct = touched ? ((covered / touched) * 100).toFixed(1) : '100.0';
    console.log(`${file.padEnd(45)} ${covered}/${touched} (${pct}%)`);
}
console.log('-'.repeat(60));

if (totalTouched === 0) {
    console.log('No coverable changed lines detected in tracked source files — nothing to gate.');
    process.exit(0);
}

const overallPct = (totalCovered / totalTouched) * 100;
console.log(`Overall: ${totalCovered}/${totalTouched} changed executable lines covered (${overallPct.toFixed(2)}%)`);

if (overallPct < minPercent) {
    console.error(`FAIL: diff coverage ${overallPct.toFixed(2)}% is below the required ${minPercent}%`);
    process.exit(1);
}

console.log(`PASS: diff coverage meets the ${minPercent}% threshold`);
process.exit(0);
