<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.tools.provenance.config

declare(strict_types=1);

namespace WpProvenance;

/**
 * The per-plugin config (`provenance.json`): the only place that knows what "correct" means for a plugin.
 *
 * Nothing in the tool is specific to one plugin. The rights holder, license, project prefix, marker
 * patterns and ZIP contents all come from here, so a plugin under another license or another rights
 * holder is checked against its own expectations, never against a global default.
 */
final class Config
{
    public const SCHEMA = 'wp-provenance-config/1';

    /** Directories that never contain own source (third-party or generated). */
    public const DEFAULT_SKIP_DIRS = ['vendor', 'node_modules', 'coverage', '.git', '.phpunit.cache', 'dist'];

    /** Path segments that must never appear in a release ZIP. `release.forbidden_extra` adds to this, `release.allow` removes from it. */
    public const DEFAULT_FORBIDDEN_SEGMENTS = ['tests', 'test', 'bin', 'tools', 'fixtures', '__fixtures__', 'node_modules', 'coverage', '.git', '.github', '.phpunit.cache', '.idea', '.vscode'];

    /** File names that must never appear in a release ZIP. */
    public const DEFAULT_FORBIDDEN_FILES = ['AGENT_PROGRESS.md', 'phpunit.xml', 'phpunit.xml.dist', 'phpcs.xml', 'phpcs.xml.dist', 'jest.config.js', 'package.json', 'package-lock.json', 'composer.lock', '.gitignore', '.gitattributes', '.editorconfig', 'provenance.json'];

    /** @var string absolute path of the config file */
    public $file;
    /** @var string absolute path of the git repository root */
    public $repoRoot;
    /** @var array<string,mixed> normalised settings */
    private $d;

    /** @param array<string,mixed> $data */
    private function __construct(string $file, string $repoRoot, array $data)
    {
        $this->file     = $file;
        $this->repoRoot = $repoRoot;
        $this->d        = $data;
    }

    /**
     * @param string|null $repoRoot Git repository root. Defaults to the directory that holds the config file.
     * @throws ConfigException
     */
    public static function load(string $file, ?string $repoRoot = null): self
    {
        $real = realpath($file);
        if ($real === false || !is_file($real)) {
            throw new ConfigException(["Config file not found: {$file}"]);
        }
        $data = json_decode((string) file_get_contents($real), true);
        if (!is_array($data)) {
            throw new ConfigException(['Config file is not valid JSON: ' . json_last_error_msg()]);
        }
        $root = $repoRoot !== null ? realpath($repoRoot) : dirname($real);
        if ($root === false || !is_dir($root)) {
            throw new ConfigException(["Repository root not found: {$repoRoot}"]);
        }
        return self::fromArray($data, str_replace('\\', '/', $real), str_replace('\\', '/', $root));
    }

    /**
     * @param array<string,mixed> $data
     * @throws ConfigException
     */
    public static function fromArray(array $data, string $file, string $repoRoot): self
    {
        $problems = [];
        $get      = static function (array $a, string $key, $default = null) {
            return array_key_exists($key, $a) ? $a[$key] : $default;
        };
        $section  = static function (string $name) use ($data): array {
            return isset($data[$name]) && is_array($data[$name]) ? $data[$name] : [];
        };

        if (($data['schema'] ?? null) !== self::SCHEMA) {
            $problems[] = 'schema must be "' . self::SCHEMA . '"';
        }

        $plugin  = $section('plugin');
        $rights  = $section('rights');
        $source  = $section('source');
        $release = $section('release');
        $brand   = $section('brand');
        $private = $section('private');

        $slug = (string) $get($plugin, 'slug', '');
        if (!preg_match('/^[a-z0-9][a-z0-9\-]*$/', $slug)) {
            $problems[] = 'plugin.slug is required (lower-case letters, digits and dashes)';
        }
        $mainFile = (string) $get($plugin, 'main_file', '');
        if ($mainFile === '') {
            $problems[] = 'plugin.main_file is required';
        }
        $dir = trim(str_replace('\\', '/', (string) $get($plugin, 'dir', '.')), '/');
        $dir = ($dir === '' || $dir === '.') ? '.' : $dir;

        $holder = trim((string) $get($rights, 'holder', ''));
        if ($holder === '') {
            $problems[] = 'rights.holder is required';
        }
        $status = (string) $get($rights, 'holder_status', '');
        if (!in_array($status, ['verified', 'unconfirmed'], true)) {
            $problems[] = 'rights.holder_status must be "verified" or "unconfirmed"';
        }
        if (!preg_match('/^\d{4}$/', (string) $get($rights, 'year', ''))) {
            $problems[] = 'rights.year must be a four-digit year';
        }
        if (trim((string) $get($rights, 'license', '')) === '') {
            $problems[] = 'rights.license is required (an SPDX identifier)';
        }

        $sourceId = is_array($get($source, 'source_id')) ? $source['source_id'] : [];
        $siRequired = (bool) $get($sourceId, 'required', true);
        $siPrefix   = (string) $get($sourceId, 'prefix', '');
        if ($siRequired && !preg_match('/^[a-z0-9][a-z0-9\-]*$/', $siPrefix)) {
            $problems[] = 'source.source_id.prefix is required when source ids are required (lower-case, e.g. "stm")';
        }

        $markers = [];
        $kinds   = [];
        foreach ((array) $get($data, 'markers', []) as $i => $m) {
            if (!is_array($m)) {
                $problems[] = "markers[{$i}] must be an object";
                continue;
            }
            $kind = (string) $get($m, 'kind', '');
            if (!preg_match('/^[a-z0-9][a-z0-9\-]*$/', $kind) || in_array($kind, ['source-id', 'copyright-header'], true)) {
                $problems[] = "markers[{$i}].kind must be a lower-case name and not a built-in kind (source-id, copyright-header)";
                continue;
            }
            if (isset($kinds[$kind])) {
                $problems[] = "markers[{$i}].kind \"{$kind}\" is used twice";
                continue;
            }
            $kinds[$kind] = true;
            $pattern = (string) $get($m, 'pattern', '');
            if ($pattern === '' || @preg_match(self::regex($pattern), '') === false) {
                $problems[] = "markers[{$i}].pattern for \"{$kind}\" is not a valid regular expression";
                continue;
            }
            $scope = (string) $get($m, 'scope', 'distributed');
            if (!in_array($scope, ['distributed', 'all'], true) && strncmp($scope, 'dir:', 4) !== 0) {
                $problems[] = "markers[{$i}].scope for \"{$kind}\" must be distributed, all or dir:<path>";
                continue;
            }
            $markers[] = [
                'kind'     => $kind,
                'pattern'  => $pattern,
                'id_group' => (int) $get($m, 'id_group', 0),
                'needle'   => (string) $get($m, 'needle', '{id}'),
                'scope'    => $scope,
                'min'      => max(0, (int) $get($m, 'min', 1)),
                'context'  => (bool) $get($m, 'context', false),
                'compare'  => (bool) $get($m, 'compare', true),
            ];
        }

        $distPaths = [];
        foreach ((array) $get($release, 'dist_paths', []) as $p) {
            $p = trim(str_replace('\\', '/', (string) $p), '/');
            if ($p !== '') {
                $distPaths[] = $p;
            }
        }
        if (!$distPaths) {
            $problems[] = 'release.dist_paths is required (the allowlist of files that make up the release ZIP)';
        }
        $exclude = [];
        foreach ((array) $get($release, 'exclude', []) as $p) {
            $p = trim(str_replace('\\', '/', (string) $p), '/');
            if ($p !== '') {
                $exclude[] = $p;
            }
        }

        if ($problems) {
            throw new ConfigException($problems);
        }

        $forbiddenSegments = array_values(array_diff(
            array_merge(self::DEFAULT_FORBIDDEN_SEGMENTS, (array) $get($release, 'forbidden_extra', [])),
            (array) $get($release, 'allow', [])
        ));
        $forbiddenFiles = array_values(array_diff(
            array_merge(self::DEFAULT_FORBIDDEN_FILES, (array) $get($release, 'forbidden_extra', [])),
            (array) $get($release, 'allow', [])
        ));

        $name = (string) $get($plugin, 'name', $slug);

        return new self($file, $repoRoot, [
            'slug'         => $slug,
            'name'         => $name,
            'dir'          => $dir,
            'main_file'    => ltrim(str_replace('\\', '/', $mainFile), '/'),
            'version_from' => is_array($get($plugin, 'version_from')) ? $plugin['version_from'] : ['header' => 'Version'],
            'prefixes'     => array_values(array_map('strtolower', array_map('strval', (array) $get($plugin, 'prefixes', [])))),
            'holder'       => $holder,
            'holder_status' => $status,
            'holder_evidence' => trim((string) $get($rights, 'holder_evidence', '')),
            'year'         => (string) $rights['year'],
            'license'      => trim((string) $rights['license']),
            'extensions'   => array_values(array_map('strtolower', array_map('strval', (array) $get($source, 'extensions', ['php', 'js', 'mjs'])))),
            'skip_dirs'    => array_values(array_map('strval', (array) $get($source, 'skip_dirs', self::DEFAULT_SKIP_DIRS))),
            'third_party'  => array_values(array_map(static function ($p) {
                return trim(str_replace('\\', '/', (string) $p), '/');
            }, (array) $get($source, 'third_party_paths', []))),
            'source_id_required'  => $siRequired,
            'source_id_prefix'    => $siPrefix,
            'source_id_overrides' => is_array($get($sourceId, 'overrides')) ? $sourceId['overrides'] : [],
            'markers'      => $markers,
            'dist_paths'   => $distPaths,
            'exclude'      => $exclude,
            'zip_root'     => (string) $get($release, 'zip_root', $slug),
            'forbidden_segments' => $forbiddenSegments,
            'forbidden_files'    => $forbiddenFiles,
            'tag_prefix'   => (string) $get($release, 'tag_prefix', 'v'),
            'brand_names'  => array_values(array_filter(array_map('strval', (array) $get($brand, 'names', [$name])), static function ($n) {
                return trim($n) !== '';
            })),
            'private_dir'      => self::expand($get($private, 'dir')),
            'private_manifest' => self::expand($get($private, 'manifest')),
        ]);
    }

    /** Delimiter-free pattern -> preg regex. */
    public static function regex(string $pattern): string
    {
        return '~' . str_replace('~', '\~', $pattern) . '~';
    }

    /** `${VAR}` in a path is replaced from the environment; an unset variable makes the whole value null. */
    private static function expand($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $missing = false;
        $out     = preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/', static function (array $m) use (&$missing): string {
            $v = getenv($m[1]);
            if ($v === false || $v === '') {
                $missing = true;
                return '';
            }
            return $v;
        }, $value);
        return $missing ? null : str_replace('\\', '/', (string) $out);
    }

    /** @return mixed */
    public function __get(string $name)
    {
        if (!array_key_exists($name, $this->d)) {
            throw new \LogicException("Unknown config setting: {$name}");
        }
        return $this->d[$name];
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->d);
    }

    /** Absolute path of the plugin directory. */
    public function pluginRoot(): string
    {
        return $this->d['dir'] === '.' ? $this->repoRoot : $this->repoRoot . '/' . $this->d['dir'];
    }

    /** Repository-relative form of a path inside the plugin directory. */
    public function repoPath(string $rel): string
    {
        return $this->d['dir'] === '.' ? $rel : $this->d['dir'] . '/' . $rel;
    }

    /** Is $rel (relative to the plugin directory) part of the release ZIP allowlist, and not carved out of it again? */
    public function isDistributed(string $rel): bool
    {
        foreach ($this->d['exclude'] as $path) {
            if ($rel === $path || strncmp($rel, $path . '/', strlen($path) + 1) === 0) {
                return false;
            }
        }
        foreach ($this->d['dist_paths'] as $path) {
            if ($rel === $path || strncmp($rel, $path . '/', strlen($path) + 1) === 0) {
                return true;
            }
        }
        return false;
    }

    /** Config file path relative to the repository when it lives inside it, else its file name. */
    public function configLabel(): string
    {
        $prefix = rtrim($this->repoRoot, '/') . '/';
        return strncmp($this->file, $prefix, strlen($prefix)) === 0 ? substr($this->file, strlen($prefix)) : basename($this->file);
    }

    /** Hash of the config contents: tells two scans apart when the rules changed between them. */
    public function hash(): string
    {
        return hash('sha256', (string) file_get_contents($this->file));
    }
}
