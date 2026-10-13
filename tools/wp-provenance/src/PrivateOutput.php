<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.tools.provenance.private-output

declare(strict_types=1);

namespace WpProvenance;

/**
 * Private outputs (manifest, release record, release ZIP) never land inside the plugin repository, and
 * never contain a secret. Both rules are enforced here, not left to the caller.
 *
 * Why: the manifest lists every recognition point of a plugin whose repository may be public. Next to the
 * code it would defeat its purpose; inside the ZIP it would be distributed.
 */
final class PrivateOutput
{
    /** Patterns that look like credentials. Matched against the text about to be written. */
    private const SECRET_PATTERNS = [
        'private key'            => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        'GitHub token'           => '/\bgh[pousr]_[A-Za-z0-9]{20,}\b|\bgithub_pat_[A-Za-z0-9_]{20,}\b/',
        'AWS access key id'      => '/\bAKIA[0-9A-Z]{16}\b/',
        'API secret key'         => '/\bsk-[A-Za-z0-9]{20,}\b/',
        'Slack token'            => '/\bxox[baprs]-[A-Za-z0-9\-]{10,}\b/',
        'JSON web token'         => '/\beyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\b/',
        'URL with credentials'   => '#://[^/\s:@]+:[^/\s@]+@#',
        'password/secret assignment' => '/(?:password|passwd|secret|api[_-]?key|access[_-]?token|auth[_-]?token)["\']?\s*[:=]\s*["\']?[^\s"\',;]{8,}/i',
    ];

    /**
     * Is $path inside $root? Resolves the nearest existing ancestor (so symlinks and `..` count) and
     * compares case-insensitively on Windows. Creates nothing.
     */
    public static function isInside(string $path, string $root): bool
    {
        $rootReal = realpath($root);
        if ($rootReal === false) {
            return false;
        }
        $resolved = self::resolve($path);
        $a = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
        $b = rtrim(str_replace('\\', '/', $resolved), '/') . '/';
        if (DIRECTORY_SEPARATOR === '\\') {
            $a = strtolower($a);
            $b = strtolower($b);
        }
        return strncmp($b, $a, strlen($a)) === 0;
    }

    private static function resolve(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $tail = [];
        $head = $path;
        while ($head !== '' && $head !== '/' && realpath($head) === false) {
            array_unshift($tail, basename($head));
            $next = dirname($head);
            if ($next === $head) {
                break;
            }
            $head = $next;
        }
        $real = realpath($head === '' ? '.' : $head);
        $base = $real === false ? $head : str_replace('\\', '/', $real);
        $clean = [];
        foreach ($tail as $part) {
            if ($part === '..') {
                array_pop($clean);
            } elseif ($part !== '.' && $part !== '') {
                $clean[] = $part;
            }
        }
        return rtrim($base, '/') . ($clean ? '/' . implode('/', $clean) : '');
    }

    /** @throws \RuntimeException */
    public static function assertOutside(string $path, string $repoRoot): void
    {
        if (self::isInside($path, $repoRoot)) {
            throw new \RuntimeException("Refusing to write {$path}: it is inside the plugin repository ({$repoRoot}). Use a private location outside it (config private.dir, or --out / --out-dir).");
        }
    }

    /**
     * Names of the secret patterns that match $text. Never returns the matched text itself.
     *
     * @return list<string>
     */
    public static function secretsIn(string $text): array
    {
        $hits = [];
        foreach (self::SECRET_PATTERNS as $name => $regex) {
            if (preg_match($regex, $text)) {
                $hits[] = $name;
            }
        }
        return $hits;
    }

    /** @throws \RuntimeException */
    public static function assertNoSecrets(string $text, string $label): void
    {
        $hits = self::secretsIn($text);
        if ($hits) {
            throw new \RuntimeException("Refusing to write {$label}: it contains text that looks like a secret (" . implode(', ', $hits) . '). A private manifest must hold no secrets; remove the text from the source comment it came from.');
        }
    }

    /**
     * Write a private text output after both guards.
     *
     * @throws \RuntimeException
     */
    public static function write(string $path, string $contents, string $repoRoot, string $label): void
    {
        self::assertOutside($path, $repoRoot);
        self::assertNoSecrets($contents, $label);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("Cannot write {$path}");
        }
    }

    /**
     * Where the repository of the output directory is, if it is inside some git repository: a reminder
     * that "outside this plugin repo" is not the same as "private". Only a path is returned, never a remote URL.
     */
    public static function enclosingRepository(string $dir): ?string
    {
        $probe = self::resolve($dir);
        while ($probe !== '' && !is_dir($probe)) {
            $next = dirname($probe);
            if ($next === $probe) {
                return null;
            }
            $probe = $next;
        }
        if ($probe === '') {
            return null;
        }
        return (new Git($probe))->out(['rev-parse', '--show-toplevel']);
    }
}
