<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.tools.provenance.manifest

declare(strict_types=1);

namespace WpProvenance;

/**
 * The private provenance manifest: every recognition point of a plugin with file, symbol, the commit that
 * introduced it and the release it first shipped in, plus one hash per own module.
 *
 * It is the REGISTER the check compares against ("registered markers"). It is written only outside the plugin
 * repository (see PrivateOutput) and contains nothing but facts derived from the repository itself.
 */
final class Manifest
{
    public const SCHEMA = 'wp-provenance-manifest/1';
    /** Manifests written by the STM pilot (task 5182) have the same marker rows and are accepted as input. */
    private const ACCEPTED = ['wp-provenance-manifest/1', 'stm-provenance-manifest/1'];

    /**
     * @param bool $withIntro look up the introducing commit and release of every marker (needs full git history)
     * @return array<string,mixed>
     */
    public static function build(Tree $tree, ?Git $git, bool $withIntro = true): array
    {
        $c       = $tree->config();
        $markers = Markers::collect($tree);
        $defs    = Markers::defsByKind($c);
        $head    = $git !== null ? $git->out(['rev-parse', 'HEAD']) : null;
        $withGit = $git !== null && $withIntro;

        $introCache = [];
        $releaseOf  = static function (?string $sha) use ($git, $c, $withGit): ?string {
            if (!$withGit || $sha === null) {
                return null;
            }
            $tag = $git->out(['tag', '--contains', $sha, '--sort=version:refname']);
            if ($tag !== null) {
                return strtok($tag, "\n") ?: null;
            }
            return 'unreleased (version at introduction: ' . (Release::versionAt($c, $git, $sha) ?? 'unknown') . ')';
        };

        $rows = [];
        foreach ($markers as $marker) {
            $needle = self::needleFor($defs, $marker['kind'], $marker['id']);
            $key    = $marker['file'] . "\0" . $needle;
            $sha    = null;
            if ($withGit) {
                if (!array_key_exists($key, $introCache)) {
                    $log = $git->out(['log', '--reverse', '--format=%H', '-S' . $needle, '--', $c->repoPath($marker['file'])]);
                    $introCache[$key] = $log === null ? null : strtok($log, "\n");
                }
                $sha = $introCache[$key] ?: null;
            }
            $rows[] = $marker + ['introduced_in' => $sha, 'release' => $releaseOf($sha)];
        }

        $modules = [];
        foreach ($tree->ownSourceFiles() as $rel) {
            $contents  = $tree->read($rel);
            $parsed    = Headers::parse($contents);
            $modules[] = ['source_id' => $parsed['source_id'], 'file' => $rel, 'sha256' => hash('sha256', $contents)];
        }

        return [
            'schema'        => self::SCHEMA,
            'generated_at'  => gmdate('Y-m-d\TH:i:s\Z'),
            'plugin'        => ['slug' => $c->slug, 'name' => $c->name, 'dir' => $c->dir, 'version' => Release::versionAt($c, null, null), 'commit' => $head],
            'rights_holder' => [
                'name'     => $c->holder,
                'status'   => $c->holder_status,
                'evidence' => $c->holder_evidence,
                'license'  => $c->license,
                'note'     => 'Third-party code (source.third_party_paths, vendor/) is excluded and keeps its own notices. No other party is named as rights holder.',
            ],
            'policy'        => 'Recognition points support provenance research. They do not prove copying, intent, AI use or infringement; GPL forks are permitted.',
            'third_party'   => $tree->thirdPartyFiles(),
            'modules'       => $modules,
            'markers'       => $rows,
        ];
    }

    /** The text a marker is registered under: its needle, or the header line for the two built-in kinds. */
    public static function needleFor(array $defsByKind, string $kind, string $id): string
    {
        if ($kind === 'source-id' || $kind === 'copyright-header') {
            return $id;
        }
        return isset($defsByKind[$kind]) ? Markers::needle($defsByKind[$kind], $id) : $id;
    }

    /**
     * Read a manifest file.
     *
     * @return array<string,mixed>
     * @throws \RuntimeException
     */
    public static function load(string $file): array
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException("Manifest not found: {$file}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !in_array($data['schema'] ?? '', self::ACCEPTED, true) || !isset($data['markers']) || !is_array($data['markers'])) {
            throw new \RuntimeException("{$file} is not a provenance manifest (expected schema " . implode(' or ', self::ACCEPTED) . ')');
        }
        return $data;
    }
}
