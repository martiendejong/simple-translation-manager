<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.tools.provenance.git

declare(strict_types=1);

namespace WpProvenance;

/**
 * Thin, read-only wrapper around the git CLI.
 *
 * Arguments go through proc_open() as an array, so no shell quoting is involved (Windows and POSIX
 * behave the same). Only commands that read the repository are issued; `archive` writes a file the
 * caller names, never inside the repository.
 */
final class Git
{
    /** @var string */
    private $root;

    public function __construct(string $root)
    {
        $this->root = $root;
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Run git in the repository; returns stdout (trimmed). $code receives the exit status.
     *
     * @param list<string> $args
     */
    public function run(array $args, ?int &$code = null, ?string &$stderr = null): string
    {
        $command = array_merge(['git', '-C', $this->root], $args);
        $env     = array_merge(getenv() ?: [], ['GIT_TERMINAL_PROMPT' => '0', 'LC_ALL' => 'C']);
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($process)) {
            $code   = 127;
            $stderr = 'git could not be started';
            return '';
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        return trim($stdout);
    }

    /** stdout of a command that must succeed; null when it fails or prints nothing. */
    public function out(array $args): ?string
    {
        $out = $this->run($args, $code);
        return ($code === 0 && $out !== '') ? $out : null;
    }

    public function isRepository(): bool
    {
        return $this->out(['rev-parse', '--git-dir']) !== null;
    }

    /** Full commit id for a ref, or null when the ref does not exist. */
    public function commitOf(string $ref): ?string
    {
        return $this->out(['rev-parse', '--verify', '--quiet', $ref . '^{commit}']);
    }

    /** Contents of a file at a commit, or null. */
    public function show(string $commit, string $path): ?string
    {
        $out = $this->run(['show', $commit . ':' . $path], $code);
        return $code === 0 ? $out : null;
    }
}
