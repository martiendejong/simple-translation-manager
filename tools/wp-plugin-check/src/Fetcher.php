<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.fetcher


declare(strict_types=1);

namespace WpPluginCheck;

use RuntimeException;

/** Downloads the pinned tools once, verifies their SHA-256, and keeps them in a cache directory. */
final class Fetcher
{
    private string $cacheDir;

    public function __construct(string $cacheDir)
    {
        $this->cacheDir = $cacheDir;
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
    }

    /**
     * Path of the verified file. A cached file with a wrong hash is deleted and fetched again;
     * a freshly downloaded file with a wrong hash aborts the scan.
     *
     * @param array{url:string,sha256:string,version?:string} $pin
     */
    public function fetch(array $pin): string
    {
        $path = $this->cacheDir . '/' . basename(parse_url($pin['url'], PHP_URL_PATH) ?: 'download.bin');

        if (is_file($path) && hash_file('sha256', $path) === $pin['sha256']) {
            return $path;
        }
        @unlink($path);

        $this->download($pin['url'], $path);
        $actual = hash_file('sha256', $path);
        if ($actual !== $pin['sha256']) {
            @unlink($path);
            throw new RuntimeException("SHA-256 mismatch for {$pin['url']}: expected {$pin['sha256']}, got {$actual}. The pin or the download is wrong; nothing was used.");
        }
        return $path;
    }

    private function download(string $url, string $path): void
    {
        $tmp = $path . '.part';
        $in  = @fopen($url, 'rb', false, stream_context_create(['http' => ['follow_location' => 1, 'timeout' => 120, 'user_agent' => 'wp-plugin-check/1']]));
        if ($in === false) {
            throw new RuntimeException("Cannot download {$url}");
        }
        $out = fopen($tmp, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        rename($tmp, $path);
    }
}
