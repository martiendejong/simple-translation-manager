<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.zipcontent


declare(strict_types=1);

namespace WpPluginCheck;

/**
 * What can be learned about a plugin ZIP without running WordPress:
 * the main plugin header, development leftovers, secret-looking strings and
 * the external hosts the code talks to.
 */
final class ZipContent
{
    /** Paths that do not belong in a distributed plugin. */
    private const DEV_PATH = '#(^|/)(\.git|\.github|\.svn|\.idea|\.vscode|node_modules|tests?|__tests__|\.phpunit\.cache|coverage)(/|$)|(^|/)(phpunit\.xml(\.dist)?|phpcs\.xml(\.dist)?|composer\.lock|package(-lock)?\.json|jest\.config\.js|AGENT_PROGRESS\.md|\.env(\..*)?|\.DS_Store|Thumbs\.db)$|\.(log|sql|bak|orig|rej|swp)$|~$#i';

    /** Strings that look like live credentials. */
    private const SECRET = [
        'private key'        => '/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/',
        'AWS access key'     => '/\bAKIA[0-9A-Z]{16}\b/',
        'OpenAI-style key'   => '/\bsk-[A-Za-z0-9_-]{20,}\b/',
        'GitHub token'       => '/\b(?:ghp|gho|ghs|ghu)_[A-Za-z0-9]{30,}\b/',
        'Slack token'        => '/\bxox[abpr]-[A-Za-z0-9-]{10,}\b/',
        'Google API key'     => '/\bAIza[0-9A-Za-z_-]{35}\b/',
    ];

    private ZipFile $zip;
    private string $slug;
    /** @var array<string,string> plugin-relative path => content, files up to 2 MB */
    private array $files = [];

    public function __construct(ZipFile $zip)
    {
        $this->zip  = $zip;
        $this->slug = $zip->topLevelDirectory();
        foreach ($zip->fileNames() as $name) {
            $relative = substr($name, strlen($this->slug) + 1);
            $content  = $zip->read($name);
            if (strlen($content) <= 2 * 1024 * 1024) {
                $this->files[$relative] = $content;
            }
        }
    }

    public function slug(): string
    {
        return $this->slug;
    }

    /** @return array<string,string> */
    public function files(): array
    {
        return $this->files;
    }

    /** @return array<string,string> only the PHP files */
    public function phpFiles(): array
    {
        return array_filter($this->files, static fn (string $path): bool => substr($path, -4) === '.php', ARRAY_FILTER_USE_KEY);
    }

    /**
     * Header of the main plugin file (the PHP file in the plugin root that declares "Plugin Name:").
     *
     * @return array{file:string,name:string,version:string}|null
     */
    public function mainHeader(): ?array
    {
        foreach ($this->files as $path => $content) {
            if (strpos($path, '/') !== false || substr($path, -4) !== '.php') {
                continue;
            }
            $head = substr($content, 0, 8192);
            if (preg_match('/^[ \t\/*#@]*Plugin Name:\s*(.+)$/mi', $head, $name)) {
                preg_match('/^[ \t\/*#@]*Version:\s*(.+)$/mi', $head, $version);
                return ['file' => $path, 'name' => trim($name[1]), 'version' => trim($version[1] ?? '')];
            }
        }
        return null;
    }

    /**
     * Problems with what the ZIP contains, independent of Plugin Check.
     *
     * @return list<array{kind:string,file:string,detail:string}>
     */
    public function hygiene(): array
    {
        $problems = [];
        foreach ($this->zip->names() as $name) {
            $relative = substr($name, strlen($this->slug) + 1);
            if ($relative !== '' && preg_match(self::DEV_PATH, $relative)) {
                $problems[] = ['kind' => 'development-file', 'file' => $relative, 'detail' => 'Development-only file inside the distribution ZIP.'];
            }
        }
        foreach ($this->files as $path => $content) {
            if (preg_match('/\.(php|js|json|txt|md|css|html|xml|ini|yml|yaml)$/i', $path) !== 1) {
                continue;
            }
            foreach (self::SECRET as $label => $pattern) {
                if (preg_match($pattern, $content)) {
                    $problems[] = ['kind' => 'secret-like-string', 'file' => $path, 'detail' => "Looks like a {$label}."];
                }
            }
        }
        return $problems;
    }

    /**
     * Hosts that appear in http(s) URLs inside PHP and JS code. An inventory for the
     * "External services" disclosure and the "no site-specific endpoints" review, not a verdict.
     *
     * @return array<string,list<string>> host => files (sorted)
     */
    public function externalHosts(): array
    {
        $hosts = [];
        foreach ($this->files as $path => $content) {
            if (!preg_match('/\.(php|js)$/i', $path)) {
                continue;
            }
            if (preg_match_all('#https?://([A-Za-z0-9.-]+\.[A-Za-z]{2,}|localhost|\d{1,3}(?:\.\d{1,3}){3})#', $content, $m)) {
                foreach (array_unique($m[1]) as $host) {
                    $hosts[strtolower($host)][$path] = true;
                }
            }
        }
        ksort($hosts);
        $out = [];
        foreach ($hosts as $host => $files) {
            $names = array_keys($files);
            sort($names);
            $out[$host] = $names;
        }
        return $out;
    }
}
