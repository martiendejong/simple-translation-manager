<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.tools.provenance.release

declare(strict_types=1);

namespace WpProvenance;

/**
 * The release record: which commit, which exact ZIP (SHA-256), when it was recorded, where it was published,
 * and whether the release tag is signed.
 *
 * The SHA-256 identifies that exact file only. It says nothing about a rewritten, repackaged or modified copy,
 * and it is not evidence of copying.
 */
final class Release
{
    public const SCHEMA   = 'wp-provenance-release-record/1';
    public const BUILT_BY = 'git archive of the commit tree, limited to release.dist_paths, entry times fixed to the last commit that touched a shipped path';

    /**
     * Plugin version, from the config's version source. At a commit when $git and $commit are given, else from the working tree.
     */
    public static function versionAt(Config $c, ?Git $git, ?string $commit): ?string
    {
        $from = $c->version_from;
        if (isset($from['file'])) {
            $file = ltrim(str_replace('\\', '/', (string) $from['file']), '/');
            $text = ($git !== null && $commit !== null)
                ? $git->show($commit, $c->repoPath($file))
                : (is_file($c->pluginRoot() . '/' . $file) ? (string) file_get_contents($c->pluginRoot() . '/' . $file) : null);
            $version = $text === null ? '' : trim($text);
            return $version !== '' ? $version : null;
        }

        $field = (string) ($from['header'] ?? 'Version');
        $text  = ($git !== null && $commit !== null)
            ? $git->show($commit, $c->repoPath($c->main_file))
            : (is_file($c->pluginRoot() . '/' . $c->main_file) ? (string) file_get_contents($c->pluginRoot() . '/' . $c->main_file) : null);
        if ($text !== null && preg_match('/^[ \t\/*#@]*' . preg_quote($field, '/') . ':[ \t]*(\S[^\r\n]*)$/mi', substr($text, 0, 8192), $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Build the record for an existing ZIP.
     *
     * @param list<array<string,mixed>> $zipFindings result of Zip::problems() for this ZIP
     * @return array<string,mixed>
     * @throws \RuntimeException
     */
    public static function record(Config $c, Git $git, string $commit, string $zipPath, bool $builtByTool, ?string $publishedOn, ?string $publicationUrl, array $zipFindings): array
    {
        if ($publishedOn !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $publishedOn)) {
            throw new \InvalidArgumentException("--published must be YYYY-MM-DD, got '{$publishedOn}'");
        }
        if ($publicationUrl !== null && !preg_match('#^https?://#i', $publicationUrl)) {
            throw new \InvalidArgumentException('--publication-url must be an http(s) link');
        }
        $sha = $git->commitOf($commit);
        if ($sha === null) {
            throw new \RuntimeException("Unknown commit '{$commit}'");
        }
        $version = self::versionAt($c, $git, $sha);
        if ($version === null) {
            throw new \RuntimeException('The plugin version could not be read at ' . $sha . ' (see plugin.version_from in the config).');
        }

        $errors = count(array_filter($zipFindings, static function (array $f): bool {
            return $f['type'] === Finding::ERROR;
        }));

        return [
            'schema'      => self::SCHEMA,
            'plugin'      => ['slug' => $c->slug, 'name' => $c->name, 'version' => $version],
            'commit'      => $sha,
            'tree'        => $git->out(['rev-parse', $sha . '^{tree}']),
            'zip'         => [
                'file'     => basename($zipPath),
                'bytes'    => filesize($zipPath),
                'sha256'   => hash_file('sha256', $zipPath),
                'entries'  => count(Zip::entries($zipPath)),
                'built_by' => $builtByTool ? self::BUILT_BY : 'not built by this tool: an existing ZIP was recorded as given',
            ],
            'zip_check'   => ['passed' => $errors === 0, 'errors' => $errors, 'warnings' => count($zipFindings) - $errors],
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'publication' => [
                'url'          => $publicationUrl,
                'published_on' => $publishedOn,
                'status'       => ($publicationUrl !== null || $publishedOn !== null) ? 'published' : 'not recorded',
            ],
            'tag'         => self::tagStatus($c, $git, $version, $sha),
            'signing'     => self::signing($c, $git, $version, $sha),
            'notice'      => 'The SHA-256 identifies this exact file as published. It says nothing about a rewritten, repackaged or modified copy, and it is not evidence of copying.',
        ];
    }

    /** State of the release tag for $commit: missing, existing, signed. */
    public static function tagStatus(Config $c, Git $git, string $version, string $commit): array
    {
        $tag = $c->tag_prefix . $version;
        $obj = $git->out(['rev-parse', '--verify', '--quiet', 'refs/tags/' . $tag]);
        if ($obj === null) {
            return ['name' => $tag, 'exists' => false, 'points_at' => null, 'matches_commit' => null, 'signed' => null];
        }
        $target = $git->out(['rev-parse', $tag . '^{commit}']);
        $body   = (string) $git->out(['cat-file', '-p', $obj]);
        return [
            'name'           => $tag,
            'exists'         => true,
            'points_at'      => $target,
            'matches_commit' => $target === $commit,
            'signed'         => (bool) preg_match('/-----BEGIN (PGP|SSH) SIGNATURE-----/', $body),
        ];
    }

    /**
     * Signing status of the release: "signed" only when the tag for this commit carries a signature,
     * otherwise "unsigned" with the reason. Whether a key exists on this machine is reported, never assumed.
     *
     * @return array<string,mixed>
     */
    public static function signing(Config $c, Git $git, string $version, string $commit): array
    {
        $tag     = self::tagStatus($c, $git, $version, $commit);
        $format  = $git->out(['config', '--get', 'gpg.format']) ?? 'openpgp';
        $key     = $git->out(['config', '--get', 'user.signingkey']);
        $keyAvailable = ($format === 'ssh') ? ($key !== null) : (self::gpgHasSecretKey() || $key !== null);

        if ($tag['exists'] && $tag['matches_commit'] && $tag['signed']) {
            $git->run(['verify-tag', $tag['name']], $verifyCode);
            return ['status' => 'signed', 'tag' => $tag['name'], 'verified_here' => $verifyCode === 0, 'key_available_here' => $keyAvailable,
                'detail' => $verifyCode === 0 ? 'The release tag is signed and the signature verifies on this machine.' : 'The release tag carries a signature that could not be verified on this machine.'];
        }

        if (!$tag['exists']) {
            $why = 'No tag ' . $tag['name'] . ' exists for this release.';
        } elseif (!$tag['matches_commit']) {
            $why = 'Tag ' . $tag['name'] . ' points at a different commit than the one recorded.';
        } else {
            $why = 'Tag ' . $tag['name'] . ' exists but is not signed.';
        }
        return ['status' => 'unsigned', 'tag' => $tag['name'], 'verified_here' => null, 'key_available_here' => $keyAvailable,
            'detail' => $why . ($keyAvailable ? ' A signing key is configured here, so a signed tag can be created.' : ' No signing key is configured on this machine, so it cannot be signed here.')];
    }

    /** Is any GPG secret key on this machine? Bounded to a few seconds so a stuck agent cannot hang a CI job. */
    private static function gpgHasSecretKey(): bool
    {
        $process = @proc_open(['gpg', '--batch', '--no-tty', '--list-secret-keys', '--with-colons'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return false;
        }
        stream_set_blocking($pipes[1], false);
        $out      = '';
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $out .= (string) stream_get_contents($pipes[1]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                break;
            }
            usleep(50000);
        }
        $running = proc_get_status($process)['running'];
        if ($running) {
            proc_terminate($process);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return !$running && preg_match('/^sec:/m', $out) === 1;
    }

    /**
     * Rebuild the ZIP from the recorded commit and compare hashes. Only possible for a ZIP this tool built.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public static function verify(Config $c, Git $git, array $record): array
    {
        if (strpos((string) ($record['zip']['built_by'] ?? ''), 'git archive') !== 0) {
            return ['match' => null, 'recorded_sha256' => $record['zip']['sha256'] ?? null, 'rebuilt_sha256' => null,
                'detail' => 'The recorded ZIP was not built by this tool, so it cannot be rebuilt; compare the published file against the recorded SHA-256 instead.'];
        }
        $tmp = sys_get_temp_dir() . '/wp-provenance-verify-' . bin2hex(random_bytes(4)) . '.zip';
        try {
            Zip::build($c, $git, (string) $record['commit'], $tmp);
            $rebuilt = hash_file('sha256', $tmp);
        } finally {
            @unlink($tmp);
        }
        return ['match' => $record['zip']['sha256'] === $rebuilt, 'recorded_sha256' => $record['zip']['sha256'], 'rebuilt_sha256' => $rebuilt, 'detail' => ''];
    }
}
