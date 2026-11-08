<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.proc


declare(strict_types=1);

namespace WpPluginCheck;

use RuntimeException;

/**
 * Runs external programs without a shell, capturing output into files so large
 * outputs (a full Plugin Check report) never fill a pipe buffer.
 */
final class Proc
{
    /**
     * Run to completion.
     *
     * @param string[]              $argv    program and arguments, no shell quoting needed
     * @param array<string,string>  $env     extra environment variables
     * @return array{code:int,stdout:string,stderr:string,seconds:float,timed_out:bool}
     */
    public static function run(array $argv, string $workDir, array $env = [], int $timeoutSeconds = 600, string $logDir = ''): array
    {
        $logDir = $logDir !== '' ? $logDir : sys_get_temp_dir();
        $out    = self::tempFile($logDir, 'out');
        $err    = self::tempFile($logDir, 'err');

        $started = microtime(true);
        $proc    = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, $workDir, self::mergeEnv($env));
        if (!is_resource($proc)) {
            throw new RuntimeException('Cannot start ' . $argv[0]);
        }
        fclose($pipes[0]);

        $timedOut = false;
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) - $started > $timeoutSeconds) {
                $timedOut = true;
                self::killTree((int) $status['pid']);
                break;
            }
            usleep(100000);
        }
        // proc_get_status only reports the exit code once, so keep the first answer.
        $code = $timedOut ? -1 : (int) $status['exitcode'];
        $close = proc_close($proc);
        if (!$timedOut && $code === -1) {
            $code = $close;
        }

        $result = [
            'code'      => $code,
            'stdout'    => (string) file_get_contents($out),
            'stderr'    => (string) file_get_contents($err),
            'seconds'   => round(microtime(true) - $started, 2),
            'timed_out' => $timedOut,
        ];
        @unlink($out);
        @unlink($err);
        return $result;
    }

    /**
     * Start a long-running process (the throwaway database). Output goes to $logFile.
     *
     * @param string[] $argv
     * @return array{proc:resource,pid:int}
     */
    public static function start(array $argv, string $workDir, string $logFile): array
    {
        $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']], $pipes, $workDir);
        if (!is_resource($proc)) {
            throw new RuntimeException('Cannot start ' . $argv[0]);
        }
        fclose($pipes[0]);
        $status = proc_get_status($proc);
        return ['proc' => $proc, 'pid' => (int) $status['pid']];
    }

    /** Kill exactly this process and its children. Never kills by image name. */
    public static function killTree(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /F /T /PID ' . $pid . ' 2>&1');
        } else {
            exec('kill -9 ' . $pid . ' 2>&1');
        }
    }

    /** @return array<string,string> */
    private static function mergeEnv(array $extra): array
    {
        $env = getenv();
        return array_merge(is_array($env) ? $env : [], $extra);
    }

    private static function tempFile(string $dir, string $kind): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $path = tempnam($dir, 'wpc-' . $kind . '-');
        if ($path === false) {
            throw new RuntimeException("Cannot create a temp file in {$dir}");
        }
        return $path;
    }
}
