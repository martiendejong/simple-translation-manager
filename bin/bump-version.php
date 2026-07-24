<?php
/**
 * Bumps the plugin version everywhere it is declared, so a release is one
 * command instead of three manual, easy-to-desync edits: the VERSION file,
 * package.json's "version" field, and simple-translation-manager.php's
 * "Version:" header + STM_VERSION constant.
 *
 * Usage: php bin/bump-version.php <major|minor|patch> [repo-root]
 *
 * After bumping, commit the changed files and push to master. The
 * "Tag release" GitHub Actions workflow (.github/workflows/release-tag.yml)
 * creates and pushes the matching vX.Y.Z git tag automatically.
 */

function stm_read_version(string $repoRoot): string
{
    $versionFile = $repoRoot . '/VERSION';
    if (!is_file($versionFile)) {
        throw new RuntimeException("VERSION file not found at {$versionFile}");
    }

    $version = trim(file_get_contents($versionFile));
    if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
        throw new RuntimeException("VERSION file does not contain a valid semver string: '{$version}'");
    }

    return $version;
}

function stm_next_version(string $current, string $part): string
{
    [$major, $minor, $patch] = array_map('intval', explode('.', $current));

    switch ($part) {
        case 'major':
            $major++;
            $minor = 0;
            $patch = 0;
            break;
        case 'minor':
            $minor++;
            $patch = 0;
            break;
        case 'patch':
            $patch++;
            break;
        default:
            throw new InvalidArgumentException("Unknown version part '{$part}', expected major|minor|patch");
    }

    return "{$major}.{$minor}.{$patch}";
}

/**
 * @return array{previous: string, next: string}
 */
function stm_bump_version(string $part, string $repoRoot): array
{
    $current = stm_read_version($repoRoot);
    $next = stm_next_version($current, $part);

    file_put_contents($repoRoot . '/VERSION', $next . "\n");

    $pluginFile = $repoRoot . '/simple-translation-manager.php';
    $pluginSource = file_get_contents($pluginFile);

    // Lookahead (not a consumed match) so an existing CRLF line ending is left untouched.
    $pluginSource = preg_replace(
        '/^( \* Version: )' . preg_quote($current, '/') . '(?=\r?$)/m',
        '${1}' . $next,
        $pluginSource,
        1,
        $headerReplacements
    );
    $pluginSource = preg_replace(
        "/(define\\('STM_VERSION', ')" . preg_quote($current, '/') . "('\\);)/",
        '${1}' . $next . '${2}',
        $pluginSource,
        1,
        $constantReplacements
    );

    if ($headerReplacements !== 1 || $constantReplacements !== 1) {
        throw new RuntimeException(
            "Could not find exactly one '* Version: {$current}' header and one STM_VERSION constant to bump in simple-translation-manager.php"
        );
    }

    file_put_contents($pluginFile, $pluginSource);

    $packageJsonFile = $repoRoot . '/package.json';
    $packageJsonSource = file_get_contents($packageJsonFile);
    $packageJsonSource = preg_replace(
        '/("version":\s*")' . preg_quote($current, '/') . '(")/',
        '${1}' . $next . '${2}',
        $packageJsonSource,
        1,
        $packageJsonReplacements
    );

    if ($packageJsonReplacements !== 1) {
        throw new RuntimeException("Could not find a \"version\": \"{$current}\" field to bump in package.json");
    }

    file_put_contents($packageJsonFile, $packageJsonSource);

    return ['previous' => $current, 'next' => $next];
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $part = $argv[1] ?? null;
    $repoRoot = isset($argv[2]) ? rtrim($argv[2], '/\\') : dirname(__DIR__);

    if (!in_array($part, ['major', 'minor', 'patch'], true)) {
        fwrite(STDERR, "Usage: php bin/bump-version.php <major|minor|patch> [repo-root]\n");
        exit(1);
    }

    try {
        $result = stm_bump_version($part, $repoRoot);
        echo "Bumped version {$result['previous']} -> {$result['next']}\n";
        echo "Updated: VERSION, package.json, simple-translation-manager.php\n";
        echo "Next: commit these files and push to master — CI tags v{$result['next']} automatically.\n";
    } catch (Throwable $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
