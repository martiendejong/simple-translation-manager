<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.tools.provenance.zip

declare(strict_types=1);

namespace WpProvenance;

/**
 * The release ZIP: listing it, judging the listing, and building it reproducibly from a commit.
 *
 * The check works on the LISTING (central directory) only, so it needs neither ext-zip nor extraction,
 * and it judges the exact file that would be published.
 */
final class Zip
{
    /**
     * Entry names of a ZIP file, read from its central directory.
     *
     * @return list<string>
     * @throws \RuntimeException
     */
    public static function entries(string $zipPath): array
    {
        $data = @file_get_contents($zipPath);
        if ($data === false) {
            throw new \RuntimeException("Cannot read {$zipPath}");
        }
        $eocd = strrpos($data, "PK\x05\x06");
        if ($eocd === false) {
            throw new \RuntimeException("{$zipPath} is not a ZIP file (no end-of-central-directory record)");
        }
        $total = unpack('v', substr($data, $eocd + 10, 2))[1];
        $pos   = unpack('V', substr($data, $eocd + 16, 4))[1];
        $names = [];
        for ($i = 0; $i < $total; $i++) {
            if (substr($data, $pos, 4) !== "PK\x01\x02") {
                throw new \RuntimeException("{$zipPath}: corrupt central directory entry {$i}");
            }
            $len     = unpack('vname/vextra/vcomment', substr($data, $pos + 28, 6));
            $names[] = substr($data, $pos + 46, $len['name']);
            $pos    += 46 + $len['name'] + $len['extra'] + $len['comment'];
        }
        return $names;
    }

    /**
     * Findings for a ZIP listing: runtime files only, under the plugin folder, nothing development-only.
     * "Runtime only" is an allowlist (release.dist_paths), not an exclude list: a new top-level file stays out
     * of the ZIP until somebody adds it on purpose.
     *
     * @param list<string> $entries
     * @return list<array<string,mixed>>
     */
    public static function problems(Config $c, array $entries, string $zipLabel): array
    {
        $findings = [];
        $root     = $c->zip_root . '/';
        $hasMain  = false;

        foreach ($entries as $name) {
            if (substr($name, -1) === '/') {
                continue; // directory entry
            }
            if (strncmp($name, $root, strlen($root)) !== 0) {
                $findings[] = Finding::make('provenance.zip.outside-plugin-folder', Finding::ERROR, $name, 0,
                    "ZIP entry is not inside the plugin folder \"{$c->zip_root}/\".",
                    "Build the ZIP so every entry starts with \"{$c->zip_root}/\" (WordPress installs the folder name as the plugin slug).", '(zip)');
                continue;
            }
            $rel = substr($name, strlen($root));
            if ($rel === $c->main_file) {
                $hasMain = true;
            }

            $why = self::forbiddenReason($c, $rel);
            if ($why !== null) {
                $findings[] = Finding::make('provenance.zip.development-file', Finding::ERROR, $rel, 0,
                    "Release ZIP contains a development-only file ({$why}).",
                    'Remove it from the ZIP build: it must not be listed under release.dist_paths, and the build must not copy it in. Tests, fixtures, tooling and provenance outputs stay in the repository or in the private location.', '(zip)');
                continue;
            }
            if (!$c->isDistributed($rel)) {
                $findings[] = Finding::make('provenance.zip.not-allowlisted', Finding::ERROR, $rel, 0,
                    'Release ZIP contains a file that is not on the runtime allowlist (release.dist_paths).',
                    'Either the file belongs in the plugin (add its path to release.dist_paths after a review) or the ZIP build includes more than it should.', '(zip)');
            }
        }

        if (!$hasMain) {
            $findings[] = Finding::make('provenance.zip.main-file-missing', Finding::ERROR, $c->zip_root . '/' . $c->main_file, 0,
                "Release ZIP ({$zipLabel}) does not contain the main plugin file.",
                'Check release.dist_paths and the build: WordPress cannot activate a plugin whose main file is missing.', '(zip)');
        }
        return $findings;
    }

    /** Why a ZIP-relative path is development-only, or null. */
    public static function forbiddenReason(Config $c, string $rel): ?string
    {
        $segments = explode('/', $rel);
        $file     = (string) array_pop($segments);
        foreach ($segments as $segment) {
            if (in_array($segment, $c->forbidden_segments, true)) {
                return "folder \"{$segment}/\"";
            }
        }
        if (in_array($file, $c->forbidden_files, true)) {
            return "file \"{$file}\"";
        }
        // Provenance outputs must never be distributed.
        if (preg_match('/^(?:provenance[\w.\-]*\.json|release-record[\w.\-]*\.json|[\w.\-]*\.provenance\.json|[\w.\-]*provenance-manifest[\w.\-]*\.json)$/i', $file)) {
            return 'a provenance manifest or release record';
        }
        return null;
    }

    /**
     * Build the release ZIP for $commit with git archive, from the allowlist only.
     *
     * Reproducible: the archive is taken from the commit's TREE (archiving a commit writes its id into the ZIP
     * comment) and every entry is stamped with the time of the last commit that touched a shipped path. A commit
     * that only changes docs, tests or tooling therefore does not change the bytes, and the SHA-256 recorded for
     * a release stays valid.
     *
     * @param bool $reject delete the ZIP and fail when its listing has problems (false only to build a deliberately broken ZIP for a demo)
     * @throws \RuntimeException
     */
    public static function build(Config $c, Git $git, string $commit, string $zipPath, bool $reject = true): void
    {
        PrivateOutput::assertOutside($zipPath, $c->repoRoot);
        $dir = dirname($zipPath);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }

        $paths = array_map(static function (string $p) use ($c): string {
            return $c->repoPath($p);
        }, $c->dist_paths);
        $time  = $git->out(array_merge(['log', '-1', '--format=%cI', $commit, '--'], $paths))
            ?? $git->out(['log', '-1', '--format=%cI', $commit]);
        // A tree-ish, never the commit itself; a plugin in a sub-folder of the repository archives just that folder.
        $treeish = $c->dir === '.' ? $commit . '^{tree}' : $commit . ':' . $c->dir;

        // release.exclude carves development-only folders out of an allowed folder (pathspec exclude).
        $excludes = array_map(static function (string $p): string {
            return ':(exclude)' . $p;
        }, $c->exclude);

        $git->run(array_merge(
            ['archive', '--format=zip', '--mtime=' . (string) $time, '--prefix=' . $c->zip_root . '/', '-o', $zipPath, $treeish, '--'],
            $c->dist_paths,
            $excludes
        ), $code, $err);
        if ($code !== 0) {
            @unlink($zipPath);
            throw new \RuntimeException('git archive failed: ' . trim((string) $err));
        }

        $problems = $reject ? self::problems($c, self::entries($zipPath), basename($zipPath)) : [];
        if ($problems) {
            @unlink($zipPath);
            $lines = array_map(static function (array $f): string {
                return $f['file'] . ': ' . $f['message'];
            }, $problems);
            throw new \RuntimeException("Release ZIP rejected:\n" . implode("\n", $lines));
        }
    }
}
