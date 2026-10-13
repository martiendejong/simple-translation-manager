<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.tools.provenance.tree

declare(strict_types=1);

namespace WpProvenance;

/** The files of a plugin directory that are the project's own source (as opposed to third-party code). */
final class Tree
{
    /** @var Config */
    private $config;
    /** @var array<string,string> */
    private $cache = [];
    /** @var list<string>|null */
    private $own;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function config(): Config
    {
        return $this->config;
    }

    /**
     * Own source files, relative to the plugin directory, forward slashes, sorted.
     *
     * Skipped: the configured skip directories (vendor, node_modules, ...), `source.third_party_paths`
     * (they keep their own notices and are never touched) and minified files.
     *
     * @return list<string>
     */
    public function ownSourceFiles(): array
    {
        if ($this->own === null) {
            $this->own = $this->walk(false);
        }
        return $this->own;
    }

    /**
     * Third-party source files that were left out of the own-source list, for the manifest.
     *
     * @return list<string>
     */
    public function thirdPartyFiles(): array
    {
        $own   = array_flip($this->ownSourceFiles());
        $found = [];
        foreach ($this->walk(true) as $rel) {
            if (!isset($own[$rel])) {
                $found[] = $rel;
            }
        }
        return $found;
    }

    public function read(string $rel): string
    {
        if (!isset($this->cache[$rel])) {
            $this->cache[$rel] = (string) @file_get_contents($this->config->pluginRoot() . '/' . $rel);
        }
        return $this->cache[$rel];
    }

    public function exists(string $rel): bool
    {
        return is_file($this->config->pluginRoot() . '/' . $rel);
    }

    /**
     * @return list<string>
     */
    private function walk(bool $includeThirdParty): array
    {
        $root = rtrim($this->config->pluginRoot(), '/');
        if (!is_dir($root)) {
            return [];
        }
        // The third-party listing shows vendor/ (shipped dependencies); node_modules and build output stay out.
        $skipDirs    = $includeThirdParty ? array_diff($this->config->skip_dirs, ['vendor']) : $this->config->skip_dirs;
        $thirdParty  = $this->config->third_party;
        $extensions  = $this->config->extensions;
        $found       = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $current) use ($skipDirs) {
                    return !($current->isDir() && in_array($current->getFilename(), $skipDirs, true));
                }
            )
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $extensions, true) || preg_match('/\.min\.[a-z]+$/i', $name)) {
                continue;
            }
            $rel = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($root)), '/');
            if (!$includeThirdParty && self::underAny($rel, $thirdParty)) {
                continue;
            }
            $found[] = $rel;
        }

        sort($found);
        return $found;
    }

    /** @param list<string> $paths */
    private static function underAny(string $rel, array $paths): bool
    {
        foreach ($paths as $path) {
            if ($rel === $path || strncmp($rel, $path . '/', strlen($path) + 1) === 0) {
                return true;
            }
        }
        return false;
    }
}
