<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.tools.provenance.checker

declare(strict_types=1);

namespace WpProvenance;

/**
 * Runs every rule against a plugin and returns one report.
 *
 * Rules (each finding names file, rule, message and a concrete fix):
 *   provenance.rights.*     rights holder confirmed, with evidence
 *   provenance.header.*     SPDX / copyright / Source-Id on every own file
 *   provenance.license.*    LICENSE file and plugin-header license agree with the config
 *   provenance.marker.*     configured recognition-point kinds are present; registered markers are still there
 *   provenance.release.*    the ZIP allowlist matches the tree
 *   provenance.zip.*        the release ZIP holds runtime files only
 *
 * A check that could not run (no manifest, no ZIP) is listed under `checks_skipped`; a report never silently
 * claims more than it looked at.
 */
final class Checker
{
    public const REPORT_SCHEMA = 'wp-provenance-report/1';
    public const TOOL_VERSION  = '1.0.0';

    /**
     * @param array{manifest?:?string, zip?:?string, zip_label?:?string, zip_error?:?string} $options
     * @return array<string,mixed>
     */
    public static function run(Config $c, ?Git $git, array $options = []): array
    {
        $tree     = new Tree($c);
        $started  = gmdate('Y-m-d\TH:i:s\Z');
        $findings = [];
        $run      = [];
        $skipped  = [];

        $findings = array_merge($findings, self::rights($c));
        $run[]    = 'rights holder';

        $findings = array_merge($findings, self::configVsTree($c, $tree));
        $run[]    = 'plugin files and release allowlist';

        $findings = array_merge($findings, self::license($c, $tree));
        $run[]    = 'license consistency';

        $findings = array_merge($findings, Headers::check($tree));
        $run[]    = 'headers (' . count($tree->ownSourceFiles()) . ' own source files)';

        if ($c->markers) {
            $findings = array_merge($findings, self::markerKinds($c, $tree));
            $run[]    = 'recognition-point kinds present';
        } else {
            $skipped[] = ['check' => 'recognition points', 'reason' => 'No marker kinds are configured (markers is empty).'];
        }

        $manifestPath = $options['manifest'] ?? null;
        if ($manifestPath !== null) {
            $findings = array_merge($findings, self::registered($c, $tree, Manifest::load($manifestPath)));
            $run[]    = 'registered markers against the private manifest';
        } else {
            $skipped[] = ['check' => 'registered markers', 'reason' => 'No private manifest was given (--manifest or private.manifest in the config); only the configured minimum per marker kind was checked.'];
        }

        $zipPath = $options['zip'] ?? null;
        if (($options['zip_error'] ?? null) !== null) {
            $findings[] = Finding::make('provenance.zip.build-failed', Finding::ERROR, $c->configLabel(), 0,
                'The release ZIP could not be built from the commit: ' . preg_replace('/\s+/', ' ', (string) $options['zip_error']),
                'Fix the paths in release.dist_paths / release.exclude (see provenance.release.dist-path-missing), or the build itself, and run the check again.',
                '(config)');
            $skipped[] = ['check' => 'release ZIP', 'reason' => 'The ZIP could not be built, so its listing was not checked.'];
        } elseif ($zipPath !== null) {
            $label    = (string) ($options['zip_label'] ?? basename($zipPath));
            $findings = array_merge($findings, Zip::problems($c, Zip::entries($zipPath), $label));
            $run[]    = 'release ZIP listing (' . $label . ')';
        } else {
            $skipped[] = ['check' => 'release ZIP', 'reason' => 'No ZIP was given (--zip) or built (--build-zip).'];
        }

        $findings = Finding::seal($c->slug, $findings);
        $errors   = count(array_filter($findings, static function (array $f): bool {
            return $f['type'] === Finding::ERROR;
        }));

        return [
            'schema'  => self::REPORT_SCHEMA,
            'scan'    => [
                'status'      => 'complete',
                'started_at'  => $started,
                'tool'        => 'wp-provenance ' . self::TOOL_VERSION,
                'config'      => $c->configLabel(),
                'config_sha256' => $c->hash(),
            ],
            'subject' => [
                'slug'    => $c->slug,
                'name'    => $c->name,
                'version' => Release::versionAt($c, null, null),
                'commit'  => $git !== null ? $git->out(['rev-parse', 'HEAD']) : null,
                'dir'     => $c->dir,
            ],
            'result'  => [
                'errors'   => $errors,
                'warnings' => count($findings) - $errors,
                'clean'    => $errors === 0,
            ],
            'checks_run'     => $run,
            'checks_skipped' => $skipped,
            'findings'       => $findings,
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function rights(Config $c): array
    {
        $label = $c->configLabel();
        $out   = [];
        if ($c->holder_status !== 'verified') {
            $out[] = Finding::make('provenance.rights.holder-unconfirmed', Finding::ERROR, $label, 0,
                'The rights holder "' . $c->holder . '" is not confirmed (rights.holder_status is "' . $c->holder_status . '").',
                'Establish who holds the rights (plugin header Author, commit history, any contract), then set rights.holder_status to "verified" and write the evidence into rights.holder_evidence. Until then do not add our copyright to any file: this tool refuses apply-headers for an unconfirmed holder.',
                '(config)');
        } elseif ($c->holder_evidence === '') {
            $out[] = Finding::make('provenance.rights.evidence-missing', Finding::ERROR, $label, 0,
                'The rights holder is marked verified but rights.holder_evidence is empty.',
                'State in one sentence how the holder was verified (for example "plugin header Author and repository history, checked 2026-10-10"), so the next reader can repeat it.',
                '(config)');
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function configVsTree(Config $c, Tree $tree): array
    {
        $out = [];
        if (!is_dir($c->pluginRoot())) {
            return [Finding::make('provenance.release.plugin-dir-missing', Finding::ERROR, $c->configLabel(), 0,
                'plugin.dir "' . $c->dir . '" does not exist in the repository.',
                'Point plugin.dir at the folder that holds the main plugin file, relative to the repository root.', '(config)')];
        }
        if (!$tree->exists($c->main_file)) {
            $out[] = Finding::make('provenance.release.main-file-missing', Finding::ERROR, $c->main_file, 0,
                'plugin.main_file "' . $c->main_file . '" does not exist.',
                'Correct plugin.main_file in the config.', '(config)');
        }
        foreach ($c->dist_paths as $path) {
            if (!file_exists($c->pluginRoot() . '/' . $path)) {
                $out[] = Finding::make('provenance.release.dist-path-missing', Finding::ERROR, $path, 0,
                    'release.dist_paths lists "' . $path . '" but it does not exist in the plugin folder.',
                    'Remove it from release.dist_paths or restore the file; the release ZIP cannot be built while a listed path is missing.', '(config)');
            }
        }
        return $out;
    }

    /**
     * License consistency. The plugin header "License:" line and the LICENSE file are the places WordPress.org
     * and downstream users look; both are compared with the config, never with a global default.
     *
     * @return list<array<string,mixed>>
     */
    private static function license(Config $c, Tree $tree): array
    {
        $out = [];

        $found = false;
        foreach ([$c->pluginRoot(), $c->repoRoot] as $dir) {
            foreach (['LICENSE', 'LICENSE.txt', 'LICENSE.md', 'COPYING', 'license.txt'] as $name) {
                if (is_file($dir . '/' . $name)) {
                    $found = true;
                }
            }
        }
        if (!$found) {
            $out[] = Finding::make('provenance.license.file-missing', Finding::WARNING, 'LICENSE', 0,
                'There is no LICENSE file in the plugin folder or the repository root, although the config says the plugin is "' . $c->license . '".',
                'Add the license text as LICENSE (for GPL-2.0-or-later: the GNU GPL v2 text). A repository that is public without a license file gives downstream users no stated terms.', '(repository)');
        }

        if ($tree->exists($c->main_file)) {
            $head = substr($tree->read($c->main_file), 0, 8192);
            if (preg_match('/^[ \t\/*#@]*License:[ \t]*(\S[^\r\n]*)$/mi', $head, $m)) {
                $declared = trim($m[1]);
                if (self::normaliseLicense($declared) !== self::normaliseLicense($c->license)) {
                    $out[] = Finding::make('provenance.license.plugin-header-mismatch', Finding::WARNING, $c->main_file, 0,
                        'The plugin header says "License: ' . $declared . '" but the config says "' . $c->license . '".',
                        'Make the two agree: fix the plugin header (and readme.txt) or the config, whichever is wrong. WordPress.org reads the plugin header.', '(file scope)', $m[0]);
                }
            }
        }
        return $out;
    }

    /** "GPL v2 or later", "GPLv2+", "GPL-2.0-or-later" and "GPL-2.0+" are the same license; MIT is MIT. */
    public static function normaliseLicense(string $license): string
    {
        $l = (string) preg_replace('/[\s_\-.]+/', '', strtolower(trim($license)));
        if (preg_match('/^gplv?2(?:0)?(?:orlater|\+)$/', $l)) {
            return 'gpl-2.0-or-later';
        }
        if (preg_match('/^gplv?2(?:0)?(?:only)?$/', $l)) {
            return 'gpl-2.0-only';
        }
        return $l;
    }

    /**
     * Every configured marker kind must have at least `min` occurrences in its scope: a kind that vanished
     * entirely (for example after a refactor that dropped all diagnostic codes) is caught even without a manifest.
     *
     * @return list<array<string,mixed>>
     */
    private static function markerKinds(Config $c, Tree $tree): array
    {
        $counts = [];
        foreach (Markers::collect($tree) as $m) {
            $counts[$m['kind']] = ($counts[$m['kind']] ?? 0) + 1;
        }
        $out = [];
        foreach ($c->markers as $def) {
            $have = $counts[$def['kind']] ?? 0;
            if ($have < $def['min']) {
                $out[] = Finding::make('provenance.marker.kind-absent', Finding::ERROR, $c->configLabel(), 0,
                    'Recognition-point kind "' . $def['kind'] . '" has ' . $have . ' occurrence(s) in scope "' . $def['scope'] . '", the config expects at least ' . $def['min'] . '.',
                    'Restore the markers that were removed (compare with the private manifest), or lower markers[].min in the config if the kind was retired on purpose.',
                    '(config: ' . $def['kind'] . ')');
            }
        }
        return $out;
    }

    /**
     * Registered markers: every marker in the private manifest must still be in the tree. Same file = fine;
     * another file = moved (warning); nowhere = missing (error).
     *
     * @param array<string,mixed> $manifest
     * @return list<array<string,mixed>>
     */
    private static function registered(Config $c, Tree $tree, array $manifest): array
    {
        $defs = Markers::defsByKind($c);
        $out  = [];

        $corpus = [];
        foreach ($tree->ownSourceFiles() as $rel) {
            $corpus[$rel] = $tree->read($rel);
        }
        $fileText = static function (string $rel) use ($c, $corpus): ?string {
            if (isset($corpus[$rel])) {
                return $corpus[$rel];
            }
            $path = $c->pluginRoot() . '/' . $rel;
            return is_file($path) ? (string) @file_get_contents($path) : null;
        };

        foreach ($manifest['markers'] as $m) {
            $kind = (string) ($m['kind'] ?? '');
            if ($kind === 'copyright-header' || !isset($m['id'], $m['file'])) {
                continue; // the header rules already cover the copyright line of every file
            }
            if ($kind !== 'source-id' && !isset($defs[$kind])) {
                continue; // a kind this config no longer defines: nothing to compare against
            }
            $id     = (string) $m['id'];
            $file   = (string) $m['file'];
            $needle = Manifest::needleFor($defs, $kind, $id);
            $has    = static function (string $text) use ($kind, $needle): bool {
                return $kind === 'source-id'
                    ? (bool) preg_match('/Source-Id:\s*' . preg_quote($needle, '/') . '(?![A-Za-z0-9._\-])/', $text)
                    : strpos($text, $needle) !== false;
            };

            $text = $fileText($file);
            if ($text !== null && $has($text)) {
                continue;
            }

            $movedTo = null;
            foreach ($corpus as $rel => $contents) {
                if ($rel !== $file && $has($contents)) {
                    $movedTo = $rel;
                    break;
                }
            }
            $symbol = isset($m['symbol']) ? ' (' . $m['symbol'] . ')' : '';
            if ($movedTo !== null) {
                $out[] = Finding::make('provenance.marker.registered-moved', Finding::WARNING, $file, (int) ($m['line'] ?? 0),
                    'Registered ' . $kind . ' ' . $id . $symbol . ' is no longer in this file; it is now in ' . $movedTo . '.',
                    'If the code moved on purpose, regenerate the private manifest (`manifest`) after review so the register follows the move. Otherwise move the marker back.',
                    (string) ($m['symbol'] ?? '(file scope)'));
            } else {
                $out[] = Finding::make('provenance.marker.registered-missing', Finding::ERROR, $file, (int) ($m['line'] ?? 0),
                    'Registered ' . $kind . ' ' . $id . $symbol . ' is missing from the tree.',
                    'Restore it at the same place. If it was removed on purpose, review that removal and then regenerate the private manifest so the register matches; do not silently drop recognition points.',
                    (string) ($m['symbol'] ?? '(file scope)'));
            }
        }
        return $out;
    }
}
