<?php
/**
 * PHPUnit tests: version tracking stays consistent across VERSION,
 * package.json, and the plugin's own "Version:" header + STM_VERSION
 * constant — and bin/bump-version.php keeps all three in sync on every
 * bump instead of relying on someone remembering to edit all three by hand.
 */

namespace STM\Tests;

use PHPUnit\Framework\TestCase;

class VersionConsistencyTest extends TestCase {

    private $repoRoot;

    protected function setUp(): void {
        parent::setUp();
        $this->repoRoot = dirname(__DIR__);
        require_once $this->repoRoot . '/bin/bump-version.php';
    }

    public function test_version_file_and_package_json_and_plugin_header_agree() {
        $versionFile = trim(file_get_contents($this->repoRoot . '/VERSION'));
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $versionFile);

        $packageJson = json_decode(file_get_contents($this->repoRoot . '/package.json'), true);
        $this->assertSame($versionFile, $packageJson['version'] ?? null, 'package.json "version" must match VERSION');

        $pluginSource = file_get_contents($this->repoRoot . '/simple-translation-manager.php');

        $this->assertMatchesRegularExpression(
            '/^ \* Version: ' . preg_quote($versionFile, '/') . '(?=\r?$)/m',
            $pluginSource,
            'Plugin header "Version:" must match VERSION'
        );
        $this->assertStringContainsString(
            "define('STM_VERSION', '{$versionFile}');",
            $pluginSource,
            'STM_VERSION constant must match VERSION'
        );
    }

    public function test_bump_version_updates_all_three_files_in_lockstep() {
        $tempRoot = $this->makeTempRepo('1.2.0');

        try {
            $result = \stm_bump_version('patch', $tempRoot);

            $this->assertSame(['previous' => '1.2.0', 'next' => '1.2.1'], $result);
            $this->assertSame('1.2.1', trim(file_get_contents($tempRoot . '/VERSION')));

            $packageJson = json_decode(file_get_contents($tempRoot . '/package.json'), true);
            $this->assertSame('1.2.1', $packageJson['version']);

            $pluginSource = file_get_contents($tempRoot . '/simple-translation-manager.php');
            $this->assertStringContainsString('* Version: 1.2.1', $pluginSource);
            $this->assertStringContainsString("define('STM_VERSION', '1.2.1');", $pluginSource);
        } finally {
            $this->removeTempRepo($tempRoot);
        }
    }

    public function test_bump_major_and_minor_reset_lower_parts() {
        $tempRoot = $this->makeTempRepo('1.2.3');

        try {
            $this->assertSame('1.3.0', \stm_bump_version('minor', $tempRoot)['next']);
        } finally {
            $this->removeTempRepo($tempRoot);
        }

        $tempRoot = $this->makeTempRepo('1.2.3');

        try {
            $this->assertSame('2.0.0', \stm_bump_version('major', $tempRoot)['next']);
        } finally {
            $this->removeTempRepo($tempRoot);
        }
    }

    public function test_bump_rejects_unknown_part() {
        $tempRoot = $this->makeTempRepo('1.0.0');

        try {
            $this->expectException(\InvalidArgumentException::class);
            \stm_bump_version('bogus', $tempRoot);
        } finally {
            $this->removeTempRepo($tempRoot);
        }
    }

    private function makeTempRepo($version) {
        $tempRoot = sys_get_temp_dir() . '/stm-version-bump-test-' . uniqid();
        mkdir($tempRoot);

        file_put_contents($tempRoot . '/VERSION', $version . "\n");
        file_put_contents(
            $tempRoot . '/package.json',
            json_encode(['name' => 'x', 'version' => $version], JSON_PRETTY_PRINT) . "\n"
        );
        file_put_contents(
            $tempRoot . '/simple-translation-manager.php',
            " * Version: {$version}\ndefine('STM_VERSION', '{$version}');\n"
        );

        return $tempRoot;
    }

    private function removeTempRepo($tempRoot) {
        @unlink($tempRoot . '/VERSION');
        @unlink($tempRoot . '/package.json');
        @unlink($tempRoot . '/simple-translation-manager.php');
        @rmdir($tempRoot);
    }
}
