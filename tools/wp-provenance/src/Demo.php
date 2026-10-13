<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.tools.provenance.demo

declare(strict_types=1);

namespace WpProvenance;

/**
 * The synthetic scenarios that show what the tool does, built from invented fixture plugins (never real code).
 *
 * Fixture sources are stored as `*.php.fixture` so that no PHP tool, linter or header check treats them as
 * source; they are given their real names in a temporary directory that is removed afterwards.
 *
 * Layout of a fixtures directory:
 *   reference/        the invented plugin ("Fixture Lantern Sync", prefix fls) with its provenance.json
 *   candidates/<n>/   independent, same-feature, brand-name-only, boilerplate-only candidates
 *   broken/           a deliberately broken plugin plus registered-manifest.json (what it looked like before)
 * The literal copy and the prefix-renamed copy are generated from reference/.
 */
final class Demo
{
    /** Candidate name => the assessment it must get. */
    public const EXPECTED = [
        'literal-copy'               => 'strong-indicators',
        'prefix-renamed-copy'        => 'strong-indicators',
        'independent'                => 'none',
        'same-feature'               => 'none',
        'brand-name-only'            => 'none',
        'wordpress-boilerplate-only' => 'none',
    ];

    /**
     * Compare every scenario candidate with the reference plugin.
     *
     * @param array{boilerplate?:bool} $options
     * @return array<string,array<string,mixed>> scenario name => comparison report
     */
    public static function compareAll(string $fixtures, array $options = []): array
    {
        $base = self::tempDir('compare');
        try {
            $ref = $base . '/reference';
            self::copyTree($fixtures . '/reference', $ref);
            $config    = Config::load($ref . '/provenance.json');
            $reference = new Tree($config);

            $literal = $base . '/literal-copy';
            self::copyTree($fixtures . '/reference', $literal);

            $renamed = $base . '/prefix-renamed-copy';
            self::copyTree($fixtures . '/reference', $renamed);
            self::renamePrefix($renamed, $config->prefixes[0] ?? 'fls', 'zzq');

            $paths = ['literal-copy' => $literal, 'prefix-renamed-copy' => $renamed];
            foreach (['independent', 'same-feature', 'brand-name-only', 'wordpress-boilerplate-only'] as $name) {
                $dir = $base . '/' . $name;
                self::copyTree($fixtures . '/candidates/' . $name, $dir);
                $paths[$name] = $dir;
            }

            $reports = [];
            foreach (self::EXPECTED as $name => $expected) {
                $reports[$name] = Compare::run($config, $reference, $paths[$name], $options);
            }
            return $reports;
        } finally {
            self::removeTree($base);
        }
    }

    /**
     * Run the check on the deliberately broken plugin, including a release ZIP that carries development files
     * and a manifest that registers markers the plugin no longer has.
     *
     * @return array<string,mixed> check report
     */
    public static function checkBroken(string $fixtures): array
    {
        $base = self::tempDir('broken');
        try {
            $dir = $base . '/plugin';
            self::copyTree($fixtures . '/broken', $dir);
            $manifest = $dir . '/registered-manifest.json';
            $private  = $base . '/private-manifest.json';
            rename($manifest, $private); // the register lives outside the plugin, like a real private manifest

            $git = new Git($dir);
            $git->run(['init', '-q']);
            $git->run(['add', '-A']);
            $git->run(['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-q', '-m', 'broken fixture']);

            $config = Config::load($dir . '/provenance.json');
            // A ZIP built the careless way: the whole repository, so tests, fixtures and tooling ride along.
            $zip = $base . '/broken-plugin.zip';
            $git->run(['archive', '--format=zip', '--prefix=broken-fixture/', '-o', $zip, 'HEAD']);

            return Checker::run($config, $git, ['manifest' => $private, 'zip' => $zip, 'zip_label' => 'broken-plugin.zip']);
        } finally {
            self::removeTree($base);
        }
    }

    /** Copy a fixture tree; `x.php.fixture` becomes `x.php`. */
    public static function copyTree(string $from, string $to): void
    {
        if (!is_dir($from)) {
            throw new \RuntimeException("Fixture directory not found: {$from}");
        }
        $from = rtrim(str_replace('\\', '/', $from), '/');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $rel    = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($from)), '/');
            $target = rtrim($to, '/') . '/' . (substr($rel, -8) === '.fixture' ? substr($rel, 0, -8) : $rel);
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            copy($file->getPathname(), $target);
        }
    }

    /** Change only the project prefix, in every spelling the sources use (FLS_, fls_, FLS-, Fls...). */
    public static function renamePrefix(string $dir, string $from, string $to): void
    {
        $pairs = [strtoupper($from) => strtoupper($to), strtolower($from) => strtolower($to), ucfirst(strtolower($from)) => ucfirst(strtolower($to))];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && in_array(strtolower($file->getExtension()), ['php', 'js', 'txt'], true)) {
                file_put_contents($file->getPathname(), strtr((string) file_get_contents($file->getPathname()), $pairs));
            }
        }
    }

    public static function tempDir(string $label): string
    {
        $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/wp-provenance-' . $label . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        return $dir;
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @chmod($item->getPathname(), 0666); // git object files are read-only on Windows
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
