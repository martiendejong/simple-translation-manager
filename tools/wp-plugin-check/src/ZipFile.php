<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.zipfile


declare(strict_types=1);

namespace WpPluginCheck;

use RuntimeException;

/**
 * Minimal ZIP reader (stored + deflate) so the scanner needs no ext-zip.
 * It reads the central directory, which is the list WordPress itself unpacks.
 */
final class ZipFile
{
    private string $data;

    /** @var array<string,array{name:string,method:int,csize:int,size:int,crc:int,offset:int,dir:bool}> */
    private array $entries = [];

    public function __construct(string $path)
    {
        if (!is_file($path)) {
            throw new RuntimeException("ZIP not found: {$path}");
        }
        $this->data = (string) file_get_contents($path);
        $this->readCentralDirectory($path);
    }

    /** @return string[] entry names, in archive order */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    /** @return string[] names of file entries (no directories) */
    public function fileNames(): array
    {
        return array_keys(array_filter($this->entries, static fn (array $e): bool => !$e['dir']));
    }

    public function read(string $name): string
    {
        $e = $this->entries[$name] ?? null;
        if ($e === null || $e['dir']) {
            throw new RuntimeException("No such file entry: {$name}");
        }
        $head = unpack('vnamelen/vextralen', substr($this->data, $e['offset'] + 26, 4));
        $from = $e['offset'] + 30 + $head['namelen'] + $head['extralen'];
        $raw  = substr($this->data, $from, $e['csize']);
        if ($e['method'] === 0) {
            $content = $raw;
        } elseif ($e['method'] === 8) {
            $content = gzinflate($raw);
            if ($content === false) {
                throw new RuntimeException("Cannot inflate {$name}");
            }
        } else {
            throw new RuntimeException("Unsupported compression method {$e['method']} for {$name}");
        }
        if (strlen($content) !== $e['size'] || (crc32($content) & 0xFFFFFFFF) !== $e['crc']) {
            throw new RuntimeException("Corrupt ZIP entry {$name} (size or CRC mismatch)");
        }
        return $content;
    }

    /**
     * Extract into $dir, dropping $stripPrefix from every entry name (entries outside the prefix are skipped).
     * Refuses names that would escape $dir.
     */
    public function extractTo(string $dir, string $stripPrefix = ''): int
    {
        $dir   = rtrim(str_replace('\\', '/', $dir), '/');
        $count = 0;
        foreach ($this->entries as $name => $entry) {
            if ($stripPrefix !== '') {
                if (strpos($name, $stripPrefix) !== 0) {
                    continue;
                }
                $relative = substr($name, strlen($stripPrefix));
            } else {
                $relative = $name;
            }
            if ($relative === '') {
                continue;
            }
            if (preg_match('#(^|/)\.\.(/|$)#', $relative) || preg_match('#^([A-Za-z]:)?[\\\\/]#', $relative)) {
                throw new RuntimeException("Refusing ZIP entry that escapes the target folder: {$name}");
            }
            $target = $dir . '/' . rtrim($relative, '/');
            if ($entry['dir']) {
                if (!is_dir($target)) {
                    mkdir($target, 0777, true);
                }
                continue;
            }
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            file_put_contents($target, $this->read($name));
            $count++;
        }
        return $count;
    }

    /** @return array<string,string> file name => sha256 of its content */
    public function hashes(): array
    {
        $out = [];
        foreach ($this->fileNames() as $name) {
            $out[$name] = hash('sha256', $this->read($name));
        }
        return $out;
    }

    /**
     * The single top-level directory every entry sits in (the plugin folder, which WordPress uses as the slug).
     *
     * @throws RuntimeException when entries sit in more than one place
     */
    public function topLevelDirectory(): string
    {
        $roots = [];
        foreach ($this->names() as $name) {
            $roots[explode('/', $name)[0]] = true;
        }
        if (count($roots) !== 1) {
            throw new RuntimeException('A plugin ZIP must contain exactly one top-level folder, found: ' . implode(', ', array_keys($roots)));
        }
        $root = (string) array_key_first($roots);
        foreach ($this->fileNames() as $name) {
            if (strpos($name, '/') === false) {
                throw new RuntimeException("File '{$name}' sits at the ZIP root, outside the plugin folder");
            }
        }
        return $root;
    }

    private function readCentralDirectory(string $path): void
    {
        $eocd = strrpos($this->data, "PK\x05\x06");
        if ($eocd === false) {
            throw new RuntimeException("{$path} is not a ZIP file (no end-of-central-directory record)");
        }
        $total = unpack('v', substr($this->data, $eocd + 10, 2))[1];
        $pos   = unpack('V', substr($this->data, $eocd + 16, 4))[1];
        for ($i = 0; $i < $total; $i++) {
            if (substr($this->data, $pos, 4) !== "PK\x01\x02") {
                throw new RuntimeException("{$path}: corrupt central directory entry {$i}");
            }
            $h = unpack('vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnamelen/vextralen/vcommentlen', substr($this->data, $pos + 10, 26));
            $name = substr($this->data, $pos + 46, $h['namelen']);
            $off  = unpack('V', substr($this->data, $pos + 42, 4))[1];
            $this->entries[$name] = [
                'name'   => $name,
                'method' => $h['method'],
                'csize'  => $h['csize'],
                'size'   => $h['size'],
                'crc'    => $h['crc'],
                'offset' => $off,
                'dir'    => substr($name, -1) === '/',
            ];
            $pos += 46 + $h['namelen'] + $h['extralen'] + $h['commentlen'];
        }
    }
}
