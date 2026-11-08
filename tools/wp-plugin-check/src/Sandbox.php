<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.sandbox


declare(strict_types=1);

namespace WpPluginCheck;

use RuntimeException;

/**
 * A throwaway WordPress: its own MariaDB instance on a free loopback port, its
 * own php.ini, the pinned WordPress core, WP-CLI and Plugin Check. Only
 * synthetic site data is created. Everything is deleted afterwards and the
 * database process is stopped by the PID this class started, never by name.
 */
final class Sandbox
{
    private const REQUIRED_MODULES = ['mysqli', 'mbstring', 'openssl', 'curl', 'zlib', 'dom', 'xml', 'json', 'hash', 'fileinfo'];
    private const OPTIONAL_MODULES = ['zip', 'exif', 'intl', 'gd', 'sodium'];

    private string $root;
    private Pins $pins;
    private Fetcher $fetcher;
    private string $php;
    private string $dbBinDir;
    private bool $keep;
    private string $siteUrl = 'http://plugin-check.test';
    private bool $withPluginCheck = true;
    private bool $reuse = false;

    private ?int $webPid = null;
    /** @var resource|null */
    private $webProc = null;
    private int $webPort = 0;

    private ?int $dbPid = null;
    /** @var resource|null */
    private $dbProc = null;
    private int $dbPort = 0;
    private string $wpCliPhar = '';
    private string $adminPassword = '';
    /** @var array<string,string> */
    private array $environment = [];

    public function __construct(string $root, Pins $pins, Fetcher $fetcher, string $phpBinary, string $dbBinDir, bool $keep = false)
    {
        $this->root     = rtrim(str_replace('\\', '/', $root), '/');
        $this->pins     = $pins;
        $this->fetcher  = $fetcher;
        $this->php      = $phpBinary;
        $this->dbBinDir = rtrim(str_replace('\\', '/', $dbBinDir), '/');
        $this->keep     = $keep;
    }

    /** Address the site is installed under. Call before provision(). */
    public function useSiteUrl(string $url): void
    {
        $this->siteUrl = rtrim($url, '/');
    }

    /** Lifecycle tests do not need Plugin Check in the site. Call before provision(). */
    public function withoutPluginCheck(): void
    {
        $this->withPluginCheck = false;
    }

    /** Start from a sandbox kept by an earlier run (database and site files), instead of a new one. */
    public function reuseExisting(bool $reuse = true): void
    {
        $this->reuse = $reuse;
    }

    public function siteUrl(): string
    {
        return $this->siteUrl;
    }

    public function sitePath(): string
    {
        return $this->root . '/site';
    }

    public function rootPath(): string
    {
        return $this->root;
    }

    public function pluginsDir(): string
    {
        return $this->sitePath() . '/wp-content/plugins';
    }

    /** Where the ZIP under test is copied to before it is installed. */
    public function inputDir(): string
    {
        return $this->root . '/input';
    }

    public function wpCliPath(): string
    {
        return $this->wpCliPhar;
    }

    public function phpIni(): string
    {
        return $this->root . '/php.ini';
    }

    public function dbPort(): int
    {
        return $this->dbPort;
    }

    public function adminPassword(): string
    {
        return $this->adminPassword;
    }

    /** @return array<string,string> exact versions of what actually ran */
    public function environment(): array
    {
        return $this->environment;
    }

    /** Find the MariaDB bin directory: explicit path, env var, common Windows locations, then PATH. */
    public static function findDbBinDir(?string $explicit): ?string
    {
        $candidates = [];
        if ($explicit) {
            $candidates[] = $explicit;
        }
        if (getenv('WPC_MARIADB_BIN')) {
            $candidates[] = (string) getenv('WPC_MARIADB_BIN');
        }
        if (PHP_OS_FAMILY === 'Windows') {
            foreach (['C:/Program Files/MariaDB */bin', 'C:/Program Files (x86)/MariaDB */bin'] as $pattern) {
                $candidates = array_merge($candidates, glob($pattern) ?: []);
            }
        } else {
            $candidates = array_merge($candidates, ['/usr/bin', '/usr/sbin', '/usr/local/bin', '/usr/local/mysql/bin']);
        }
        foreach ($candidates as $dir) {
            if (self::dbBinary($dir, ['mariadbd', 'mysqld']) !== null) {
                return str_replace('\\', '/', $dir);
            }
        }
        return null;
    }

    /** @param string[] $names */
    private static function dbBinary(string $dir, array $names): ?string
    {
        foreach ($names as $name) {
            foreach ([$name . '.exe', $name] as $file) {
                if (is_file($dir . '/' . $file)) {
                    return str_replace('\\', '/', $dir . '/' . $file);
                }
            }
        }
        return null;
    }

    /**
     * Problems that stop a scan before anything is started. Empty = ready.
     *
     * @return string[]
     */
    public function preflight(): array
    {
        $problems = [];

        $version = $this->phpVersion();
        if (strpos($version, $this->pins->phpMinor() . '.') !== 0) {
            $problems[] = "PHP {$version} found, but the scan is pinned to PHP {$this->pins->phpMinor()}.x (pins.json). Use --php=<binary> or change the pin deliberately.";
        }
        if (self::dbBinary($this->dbBinDir, ['mariadbd', 'mysqld']) === null) {
            $problems[] = "No MariaDB server binary in '{$this->dbBinDir}'. Pass --mariadb-bin=<dir> or set WPC_MARIADB_BIN.";
        }
        if (self::dbBinary($this->dbBinDir, ['mariadb-install-db', 'mysql_install_db']) === null) {
            $problems[] = "No mariadb-install-db in '{$this->dbBinDir}'.";
        }
        return $problems;
    }

    public function phpVersion(): string
    {
        $r = Proc::run([$this->php, '-r', 'echo PHP_VERSION;'], getcwd() ?: '.', [], 30);
        return trim($r['stdout']);
    }

    /** Create everything. Call teardown() in a finally block. */
    public function provision(): void
    {
        foreach (['', '/tmp', '/logs', '/input'] as $dir) {
            if (!is_dir($this->root . $dir)) {
                mkdir($this->root . $dir, 0777, true);
            }
        }

        $this->writePhpIni();
        $marker = $this->root . '/provisioned.json';
        if ($this->reuse && is_file($marker)) {
            $this->attach($marker);
            return;
        }
        $this->startDatabase(true);

        $wpZip = $this->fetcher->fetch($this->pins->section('wordpress'));
        (new ZipFile($wpZip))->extractTo($this->sitePath(), 'wordpress/');
        $this->wpCliPhar = $this->fetcher->fetch($this->pins->section('wp_cli'));

        if ($this->withPluginCheck) {
            $pcZip = $this->fetcher->fetch($this->pins->section('plugin_check'));
            (new ZipFile($pcZip))->extractTo($this->pluginsDir(), '');
        }

        $this->adminPassword = bin2hex(random_bytes(12));
        $url                 = $this->siteUrl;

        $this->mustWp(['config', 'create', '--dbname=wp', '--dbuser=root', '--dbpass=', '--dbhost=127.0.0.1:' . $this->dbPort, '--skip-check'], 'wp config create');
        foreach (['DISABLE_WP_CRON' => 'true', 'AUTOMATIC_UPDATER_DISABLED' => 'true', 'WP_DEBUG' => 'false'] as $name => $value) {
            $this->mustWp(['config', 'set', $name, $value, '--raw'], "wp config set {$name}");
        }
        $this->mustWp(['config', 'set', 'WP_ENVIRONMENT_TYPE', 'local'], 'wp config set WP_ENVIRONMENT_TYPE');
        $this->mustWp(['config', 'set', 'WP_HOME', $url], 'wp config set WP_HOME');
        $this->mustWp(['config', 'set', 'WP_SITEURL', $url], 'wp config set WP_SITEURL');
        $this->mustWp([
            'core', 'install', '--url=' . $url, '--title=Plugin Check Sandbox', '--admin_user=scan-admin',
            '--admin_password=' . $this->adminPassword, '--admin_email=admin@example.test', '--skip-email',
        ], 'wp core install');
        if ($this->withPluginCheck) {
            $this->mustWp(['plugin', 'activate', 'plugin-check'], 'activate plugin-check');
        }

        $this->environment = $this->collectEnvironment();
        file_put_contents($marker, json_encode(['admin_password' => $this->adminPassword, 'environment' => $this->environment], JSON_PRETTY_PRINT));
    }

    /** Come back to a kept sandbox: same files and database, new processes and ports. */
    private function attach(string $marker): void
    {
        $saved = json_decode((string) file_get_contents($marker), true);
        if (!is_array($saved)) {
            throw new RuntimeException('The kept sandbox has no readable provisioned.json');
        }
        $this->adminPassword = (string) $saved['admin_password'];
        $this->wpCliPhar     = $this->fetcher->fetch($this->pins->section('wp_cli'));
        $this->startDatabase(false);
        $this->mustWp(['config', 'set', 'DB_HOST', '127.0.0.1:' . $this->dbPort], 'wp config set DB_HOST');
        foreach (['WP_HOME', 'WP_SITEURL'] as $name) {
            $this->mustWp(['config', 'set', $name, $this->siteUrl], "wp config set {$name}");
        }
        $this->mustWp(['option', 'update', 'home', $this->siteUrl], 'wp option update home');
        $this->mustWp(['option', 'update', 'siteurl', $this->siteUrl], 'wp option update siteurl');
        $this->environment = $this->collectEnvironment();
    }

    /**
     * Run WP-CLI against the sandbox.
     *
     * Global flags (--path, --require) go AFTER the subcommand: Plugin Check
     * decides whether to switch on runtime checks by reading argv[1] and argv[2]
     * as "plugin" and "check", so nothing may come before them.
     *
     * @param string[] $args
     * @param string[] $requires absolute paths passed as --require
     * @param string[] $flags    further global flags, for example --user=scan-admin
     * @return array{code:int,stdout:string,stderr:string,seconds:float,timed_out:bool}
     */
    public function wp(array $args, array $requires = [], int $timeout = 900, array $flags = []): array
    {
        $argv = [$this->php, '-c', $this->phpIni(), '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED), $this->wpCliPhar];
        foreach ($args as $a) {
            $argv[] = $a;
        }
        foreach ($requires as $r) {
            $argv[] = '--require=' . $r;
        }
        $argv[] = '--path=' . $this->sitePath();
        $argv[] = '--allow-root';
        foreach ($flags as $flag) {
            $argv[] = $flag;
        }

        return Proc::run($argv, $this->sitePath(), [
            'WP_CLI_CONFIG_PATH'            => $this->root . '/wp-cli.yml',
            'WP_CLI_CACHE_DIR'              => $this->root . '/tmp/wp-cli-cache',
            'WP_CLI_PACKAGES_DIR'           => $this->root . '/tmp/wp-cli-packages',
            'WP_CLI_DISABLE_AUTO_CHECK_UPDATE' => '1',
            'PHP_INI_SCAN_DIR'              => '',
            'PHPRC'                         => $this->root,
            'HOME'                          => $this->root,
        ], $timeout, $this->root . '/tmp');
    }

    /** @param string[] $args */
    public function mustWp(array $args, string $what): string
    {
        $r = $this->wp($args);
        if ($r['code'] !== 0) {
            throw new RuntimeException("{$what} failed (exit {$r['code']}): " . self::tail($r['stderr'] . "\n" . $r['stdout']));
        }
        return $r['stdout'];
    }

    public function teardown(): void
    {
        $this->stopWebServer();
        $this->stopDatabase();
        if (!$this->keep && is_dir($this->root)) {
            self::removeTree($this->root);
        }
    }

    private function writePhpIni(): void
    {
        $extDir = $this->extensionDir();
        $lines  = ['extension_dir="' . $extDir . '"'];
        foreach (array_merge(self::REQUIRED_MODULES, self::OPTIONAL_MODULES) as $module) {
            if (in_array($module, ['zlib', 'dom', 'xml', 'json', 'hash'], true)) {
                continue; // built in
            }
            $dll = PHP_OS_FAMILY === 'Windows' ? "{$extDir}/php_{$module}.dll" : "{$extDir}/{$module}.so";
            if (is_file($dll)) {
                $lines[] = 'extension=' . $module;
            }
        }
        $lines = array_merge($lines, [
            'memory_limit=1024M',
            'display_errors=Off',
            'log_errors=On',
            'date.timezone=UTC',
            'max_execution_time=0',
            'default_charset=UTF-8',
        ]);
        file_put_contents($this->phpIni(), implode("\n", $lines) . "\n");

        $r       = Proc::run([$this->php, '-c', $this->phpIni(), '-m'], $this->root, [], 30);
        $modules = array_map('strtolower', array_filter(array_map('trim', explode("\n", $r['stdout']))));
        $missing = array_values(array_diff(self::REQUIRED_MODULES, $modules));
        if ($missing) {
            throw new RuntimeException('PHP is missing required extensions for WordPress: ' . implode(', ', $missing) . " (looked in {$extDir}). Install or enable them.");
        }
    }

    private function extensionDir(): string
    {
        $r   = Proc::run([$this->php, '-r', 'echo ini_get("extension_dir");'], getcwd() ?: '.', [], 30);
        $dir = trim($r['stdout']);
        if ($dir !== '' && !preg_match('#^([A-Za-z]:)?[\\\\/]#', $dir)) {
            $dir = dirname($this->php) . '/' . $dir;
        }
        if ($dir === '' || !is_dir($dir)) {
            $dir = dirname($this->php) . '/ext';
        }
        return str_replace('\\', '/', $dir);
    }

    private function startDatabase(bool $fresh): void
    {
        $install = self::dbBinary($this->dbBinDir, ['mariadb-install-db', 'mysql_install_db']);
        $server  = self::dbBinary($this->dbBinDir, ['mariadbd', 'mysqld']);
        $admin   = self::dbBinary($this->dbBinDir, ['mariadb-admin', 'mysqladmin']);
        $client  = self::dbBinary($this->dbBinDir, ['mariadb', 'mysql']);
        if (!$install || !$server || !$admin || !$client) {
            throw new RuntimeException("Incomplete MariaDB installation in {$this->dbBinDir}");
        }

        $data = $this->root . '/db';
        if ($fresh) {
            $r = Proc::run([$install, '--datadir=' . $data], $this->root, [], 300, $this->root . '/tmp');
            if ($r['code'] !== 0) {
                throw new RuntimeException('mariadb-install-db failed: ' . self::tail($r['stderr'] . $r['stdout']));
            }
        }

        $this->dbPort = $this->freePort();
        $started      = Proc::start([
            $server, '--no-defaults', '--datadir=' . $data, '--port=' . $this->dbPort, '--bind-address=127.0.0.1',
            '--skip-name-resolve', '--console', '--tmpdir=' . $this->root . '/tmp', '--innodb-buffer-pool-size=64M',
        ], $this->root, $this->root . '/logs/mariadb.log');
        $this->dbProc = $started['proc'];
        $this->dbPid  = $started['pid'];

        $deadline = time() + 90;
        while (time() < $deadline) {
            $ping = Proc::run([$admin, '--no-defaults', '-h127.0.0.1', '-P' . $this->dbPort, '-uroot', 'ping'], $this->root, [], 15, $this->root . '/tmp');
            if ($ping['code'] === 0) {
                break;
            }
            usleep(500000);
        }
        if (!isset($ping) || $ping['code'] !== 0) {
            throw new RuntimeException('The throwaway MariaDB did not become ready: ' . self::tail((string) @file_get_contents($this->root . '/logs/mariadb.log')));
        }

        if ($fresh) {
            $create = Proc::run([$client, '--no-defaults', '-h127.0.0.1', '-P' . $this->dbPort, '-uroot', '-e', 'CREATE DATABASE wp CHARACTER SET utf8mb4'], $this->root, [], 60, $this->root . '/tmp');
            if ($create['code'] !== 0) {
                throw new RuntimeException('CREATE DATABASE failed: ' . self::tail($create['stderr']));
            }
        }
        $ver = Proc::run([$server, '--version'], $this->root, [], 30, $this->root . '/tmp');
        $this->environment['mariadb'] = trim(explode("\n", $ver['stdout'] . $ver['stderr'])[0]);
    }

    private function stopDatabase(): void
    {
        if ($this->dbPid === null) {
            return;
        }
        $admin = self::dbBinary($this->dbBinDir, ['mariadb-admin', 'mysqladmin']);
        if ($admin !== null && $this->dbPort > 0) {
            Proc::run([$admin, '--no-defaults', '-h127.0.0.1', '-P' . $this->dbPort, '-uroot', 'shutdown'], $this->root, [], 60, sys_get_temp_dir());
        }
        for ($i = 0; $i < 40; $i++) {
            $status = is_resource($this->dbProc) ? proc_get_status($this->dbProc) : ['running' => false];
            if (!$status['running']) {
                break;
            }
            usleep(250000);
        }
        // Only the process this run started: kill it by PID if it is still around.
        $status = is_resource($this->dbProc) ? proc_get_status($this->dbProc) : ['running' => false];
        if ($status['running']) {
            Proc::killTree($this->dbPid);
        }
        if (is_resource($this->dbProc)) {
            proc_close($this->dbProc);
        }
        $this->dbPid  = null;
        $this->dbProc = null;
    }

    /**
     * Serve the site over HTTP with PHP's built-in web server, for tests that need real requests
     * (unauthenticated REST calls, a logged-in admin session, frontend pages). One request at a time,
     * which is enough because only the test script talks to it.
     */
    public function startWebServer(int $port): void
    {
        $this->webPort = $port;
        $router        = $this->root . '/router.php';
        file_put_contents($router, <<<'PHP'
<?php
// Router for PHP's built-in web server: real files are served as they are, everything else goes to WordPress.
$docroot = $_SERVER['DOCUMENT_ROOT'];
$path    = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$full    = realpath($docroot . $path);
if ($full !== false && (is_file($full) || (is_dir($full) && is_file($full . '/index.php')))) {
    return false;
}
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $docroot . '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
chdir($docroot);
require $docroot . '/index.php';
PHP
        );
        $started       = Proc::start([
            $this->php, '-c', $this->phpIni(), '-S', '127.0.0.1:' . $port, '-t', $this->sitePath(), $router,
        ], $this->sitePath(), $this->root . '/logs/webserver.log');
        $this->webProc = $started['proc'];
        $this->webPid  = $started['pid'];

        $deadline = time() + 30;
        while (time() < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(250000);
        }
        throw new RuntimeException('The throwaway web server did not start: ' . self::tail((string) @file_get_contents($this->root . '/logs/webserver.log')));
    }

    private function stopWebServer(): void
    {
        if ($this->webPid === null) {
            return;
        }
        Proc::killTree($this->webPid);
        if (is_resource($this->webProc)) {
            proc_close($this->webProc);
        }
        $this->webPid  = null;
        $this->webProc = null;
    }

    public function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("Cannot find a free port: {$errstr}");
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
    }

    /** @return array<string,string> */
    private function collectEnvironment(): array
    {
        $env = $this->environment;
        $env['php']           = $this->phpVersion();
        $env['wordpress']     = trim($this->mustWp(['core', 'version'], 'wp core version'));
        $env['wp_cli']        = trim(str_replace('WP-CLI ', '', $this->mustWp(['cli', 'version'], 'wp cli version')));
        if ($this->withPluginCheck) {
            $env['plugin_check'] = trim($this->mustWp(['plugin', 'get', 'plugin-check', '--field=version'], 'plugin-check version'));
        }
        $env['os']            = PHP_OS_FAMILY . ' ' . php_uname('r');
        return $env;
    }

    public static function tail(string $text, int $lines = 12): string
    {
        $parts = preg_split('/\R/', trim($text)) ?: [];
        return implode(' | ', array_slice($parts, -$lines));
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            @chmod($file->getPathname(), 0666);
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
