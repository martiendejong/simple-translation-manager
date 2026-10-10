<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.autoload


declare(strict_types=1);

// Tiny PSR-4 style autoloader so the tool runs with plain `php`, no composer install.
spl_autoload_register(static function (string $class): void {
    $prefix = 'WpPluginCheck\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
