<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.pins


declare(strict_types=1);

namespace WpPluginCheck;

use RuntimeException;

/** The pinned versions and download hashes (pins.json). */
final class Pins
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        foreach (['php', 'wordpress', 'wp_cli', 'plugin_check', 'runtime_canary'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                throw new RuntimeException("pins.json: missing section '{$key}'");
            }
        }
        foreach (['wordpress', 'wp_cli', 'plugin_check'] as $key) {
            foreach (['version', 'url', 'sha256'] as $field) {
                if (empty($data[$key][$field]) || !is_string($data[$key][$field])) {
                    throw new RuntimeException("pins.json: {$key}.{$field} is required");
                }
            }
            if (!preg_match('/^[a-f0-9]{64}$/', $data[$key]['sha256'])) {
                throw new RuntimeException("pins.json: {$key}.sha256 must be 64 hex characters");
            }
        }
        if (!preg_match('/^\d+\.\d+$/', (string) ($data['php']['minor'] ?? ''))) {
            throw new RuntimeException('pins.json: php.minor must look like 8.5');
        }
        $this->data = $data;
    }

    public static function load(string $file): self
    {
        $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($json)) {
            throw new RuntimeException("Cannot read pins from {$file}");
        }
        return new self($json);
    }

    /** @return array<string,mixed> */
    public function section(string $name): array
    {
        return $this->data[$name];
    }

    public function phpMinor(): string
    {
        return (string) $this->data['php']['minor'];
    }

    /** Versions as recorded in a report. @return array<string,string> */
    public function versions(): array
    {
        return [
            'wordpress'    => (string) $this->data['wordpress']['version'],
            'wp_cli'       => (string) $this->data['wp_cli']['version'],
            'plugin_check' => (string) $this->data['plugin_check']['version'],
            'php_minor'    => $this->phpMinor(),
        ];
    }
}
