<?php
/**
 * Standalone readme.txt header check (no WordPress install required).
 * Mirrors the header block WordPress.org's readme parser / Plugin Check
 * plugin look for, so the 4 rules cited in ClickUp task 869efjuha can be
 * verified in a headless environment.
 */

$readme = file_get_contents(__DIR__ . '/../readme.txt');
$plugin = file_get_contents(__DIR__ . '/../simple-translation-manager.php');

$fail = [];

// no_plugin_readme
if ($readme === false || trim($readme) === '') {
    $fail[] = 'no_plugin_readme: readme.txt missing or empty';
}

// Header block must start with === Name ===
if (!preg_match('/^===\s*(.+?)\s*===/', $readme, $m)) {
    $fail[] = 'readme.txt does not start with a === Plugin Name === header';
}

// missing_readme_header_tested
if (!preg_match('/^Tested up to:\s*(.+)$/mi', $readme, $tested)) {
    $fail[] = 'missing_readme_header_tested: "Tested up to" header missing';
}

// no_stable_tag
if (!preg_match('/^Stable tag:\s*(.+)$/mi', $readme, $stable)) {
    $fail[] = 'no_stable_tag: "Stable tag" header missing';
}

// no_license
if (!preg_match('/^License:\s*(.+)$/mi', $readme, $license)) {
    $fail[] = 'no_license: "License" header missing';
}

// Stable tag must match the plugin file's Version header
if (!preg_match('/^\s*\*?\s*Version:\s*(.+)$/mi', $plugin, $pluginVersion)) {
    $fail[] = 'could not read Version header from simple-translation-manager.php';
} elseif (isset($stable) && trim($stable[1]) !== trim($pluginVersion[1])) {
    $fail[] = sprintf(
        'Stable tag (%s) does not match plugin Version header (%s)',
        trim($stable[1]),
        trim($pluginVersion[1])
    );
}

if ($fail) {
    fwrite(STDERR, "FAIL:\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}

echo "PASS: readme.txt headers valid\n";
echo " Tested up to: " . trim($tested[1]) . "\n";
echo " Stable tag:   " . trim($stable[1]) . "\n";
echo " License:      " . trim($license[1]) . "\n";
echo " Plugin Version header: " . trim($pluginVersion[1]) . "\n";
