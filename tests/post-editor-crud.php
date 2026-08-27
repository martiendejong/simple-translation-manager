<?php
/**
 * CLI entry point for the post/page editor translation CRUD test suite.
 *
 * PHPUnit's TestSuiteLoader requires a test file's class name to match its
 * filename (PHPUnit\Runner\TestSuiteLoader::load()), and PHP identifiers
 * can't contain hyphens — so the actual PHPUnit\Framework\TestCase lives in
 * PostEditorCrudTest.php (discovered normally by `vendor/bin/phpunit`, and
 * by the CI workflow). This script is a convenience runner that mirrors
 * wp-integration-smoke.php's `php tests/...` usage.
 *
 * Run: php tests/post-editor-crud.php
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line: php tests/post-editor-crud.php\n");
}

$phpunitBin = dirname(__DIR__) . '/vendor/bin/phpunit';
$testFile   = __DIR__ . '/PostEditorCrudTest.php';

if (!file_exists($phpunitBin)) {
    fwrite(STDERR, "PHPUnit not installed — run `composer install` first.\n");
    exit(2);
}

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunitBin)
     . ' --bootstrap ' . escapeshellarg(__DIR__ . '/bootstrap.php')
     . ' --colors=always '
     . escapeshellarg($testFile);

passthru($cmd, $exitCode);
exit($exitCode);
