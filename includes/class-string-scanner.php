<?php
/**
 * String Scanner
 *
 * Scans the active theme (and the plugin's own templates) for calls to the
 * plugin's translation helpers (__stm() / _e_stm(), see includes/functions.php)
 * and registers each distinct key/context as a row in wp_stm_strings, seeding
 * the default-language translation with the fallback text already used in
 * the call. Re-running the scan never creates duplicate strings or
 * overwrites a translation a human has since edited.
 */

namespace STM;

class StringScanner {

    /** Function names in functions.php that carry translatable text. */
    const SCANNED_FUNCTIONS = ['__stm', '_e_stm'];

    /** Directory names to skip entirely while walking the filesystem. */
    const SKIPPED_DIRS = ['node_modules', 'vendor', '.git'];

    /**
     * Scan every configured directory and register newly discovered strings.
     *
     * @return array{files_scanned:int, unique_found:int, added:int, skipped_existing:int}
     */
    public static function scan_and_register() {
        $entries = [];
        $files_scanned = 0;

        foreach (self::get_scan_directories() as $dir) {
            foreach (self::find_php_files($dir) as $file) {
                $files_scanned++;
                $found = self::extract_calls_from_file($file);
                if ($found) {
                    $entries = array_merge($entries, $found);
                }
            }
        }

        // Dedupe within this run: the same key/context can legitimately
        // appear in more than one template.
        $unique = [];
        foreach ($entries as $entry) {
            $unique[$entry['key'] . '|' . $entry['context']] = $entry;
        }

        $result = self::register_strings(array_values($unique));
        $result['files_scanned'] = $files_scanned;
        $result['unique_found'] = count($unique);

        return $result;
    }

    /**
     * Directories to scan: the active theme (child + parent, deduped) and
     * the plugin's own templates/ directory. Filterable so a site can widen
     * or narrow the set (e.g. a must-use plugin with its own templates).
     *
     * @return string[]
     */
    public static function get_scan_directories() {
        $dirs = [];

        if (function_exists('get_stylesheet_directory')) {
            $stylesheet = get_stylesheet_directory();
            if ($stylesheet && is_dir($stylesheet)) {
                $dirs[] = $stylesheet;
            }
        }

        if (function_exists('get_template_directory')) {
            $template = get_template_directory();
            if ($template && is_dir($template) && !in_array($template, $dirs, true)) {
                $dirs[] = $template;
            }
        }

        $plugin_templates = STM_PLUGIN_DIR . 'templates';
        if (is_dir($plugin_templates)) {
            $dirs[] = $plugin_templates;
        }

        if (function_exists('apply_filters')) {
            $dirs = apply_filters('stm_scan_directories', $dirs);
        }

        return $dirs;
    }

    /**
     * Recursively find .php files under $dir, skipping vendor/build noise.
     *
     * @return string[]
     */
    private static function find_php_files($dir) {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            foreach (self::SKIPPED_DIRS as $skip) {
                if (strpos($path, DIRECTORY_SEPARATOR . $skip . DIRECTORY_SEPARATOR) !== false
                    || strpos(str_replace('\\', '/', $path), '/' . $skip . '/') !== false) {
                    continue 2;
                }
            }

            $files[] = $path;
        }

        return $files;
    }

    /**
     * Tokenize a single PHP file and extract every literal __stm()/_e_stm()
     * call found in real code (comments and string interpolation are
     * naturally excluded — see parse_tokens()).
     *
     * @return array<int, array{key:string, fallback:string, context:string}>
     */
    public static function extract_calls_from_file($file_path) {
        $code = @file_get_contents($file_path);

        if ($code === false || strpos($code, '<?php') === false) {
            return [];
        }

        $tokens = @token_get_all($code);

        if (!is_array($tokens)) {
            return [];
        }

        return self::parse_tokens($tokens);
    }

    /**
     * Walk a token stream looking for calls to the scanned helper functions
     * with a literal (non-dynamic) first argument.
     */
    private static function parse_tokens(array $tokens) {
        $entries = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            if (!in_array(strtolower($token[1]), self::SCANNED_FUNCTIONS, true)) {
                continue;
            }

            $j = self::skip_whitespace_and_comments($tokens, $i + 1, $count);

            if ($j >= $count || $tokens[$j] !== '(') {
                continue;
            }

            $j++;
            $depth = 1;
            $argTokens = [];

            while ($j < $count && $depth > 0) {
                $t = $tokens[$j];

                if ($t === '(') {
                    $depth++;
                    $argTokens[] = $t;
                } elseif ($t === ')') {
                    $depth--;
                    if ($depth > 0) {
                        $argTokens[] = $t;
                    }
                } else {
                    $argTokens[] = $t;
                }

                $j++;
            }

            $args = self::split_arguments($argTokens);
            $literalArgs = array_map([__CLASS__, 'extract_literal'], $args);

            // The key must be a plain string literal we can statically read.
            if (count($literalArgs) < 1 || $literalArgs[0] === null || $literalArgs[0] === '') {
                $i = $j - 1;
                continue;
            }

            $key = $literalArgs[0];
            $fallback = $literalArgs[1] ?? '';
            $context = $literalArgs[2] ?? '';

            $entries[] = [
                'key' => $key,
                'fallback' => $fallback !== null ? $fallback : '',
                'context' => ($context !== null && $context !== '') ? $context : 'general',
            ];

            $i = $j - 1;
        }

        return $entries;
    }

    private static function skip_whitespace_and_comments(array $tokens, $start, $count) {
        $j = $start;
        while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $j++;
        }
        return $j;
    }

    /**
     * Split a flat token list into per-argument token lists on top-level commas.
     */
    private static function split_arguments(array $tokens) {
        $args = [];
        $current = [];
        $depth = 0;

        foreach ($tokens as $t) {
            if ($t === '(') {
                $depth++;
                $current[] = $t;
                continue;
            }
            if ($t === ')') {
                $depth--;
                $current[] = $t;
                continue;
            }
            if ($t === ',' && $depth === 0) {
                $args[] = $current;
                $current = [];
                continue;
            }
            $current[] = $t;
        }

        if (!empty($current)) {
            $args[] = $current;
        }

        return $args;
    }

    /**
     * Reduce an argument's token list to a literal string value, or null if
     * it isn't a plain, statically-known string (a variable, concatenation,
     * function call, or interpolated string).
     */
    private static function extract_literal(array $tokenList) {
        $filtered = array_values(array_filter($tokenList, function ($t) {
            return !(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true));
        }));

        if (count($filtered) !== 1) {
            return null;
        }

        $t = $filtered[0];

        if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return self::unquote($t[1]);
    }

    private static function unquote($raw) {
        $quote = $raw[0];
        $inner = substr($raw, 1, -1);

        if ($quote === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
        }

        $map = [
            '\\"' => '"', '\\\\' => '\\', '\\n' => "\n", '\\t' => "\t",
            '\\r' => "\r", '\\$' => '$', '\\v' => "\x0B", '\\f' => "\x0C", '\\e' => "\x1B",
        ];

        return strtr($inner, $map);
    }

    /**
     * Insert any strings not already known, seeding each new row's
     * default-language translation with its discovered text. Existing
     * strings and existing translations (including ones a human has since
     * edited) are left untouched.
     *
     * @param array<int, array{key:string, fallback:string, context:string}> $entries
     * @return array{added:int, skipped_existing:int, total_found:int}
     */
    public static function register_strings(array $entries) {
        global $wpdb;

        $table_strings = $wpdb->prefix . 'stm_strings';
        $table_translations = $wpdb->prefix . 'stm_translations';

        $default_lang = Database::get_default_language();
        $default_code = $default_lang ? $default_lang->code : 'en';

        $added = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            $key = Security::sanitize_translation_key($entry['key']);
            $context = Security::sanitize_context($entry['context'] ?? 'general');

            if ($key === '' || !Security::validate_translation_key($key) || !Security::validate_context($context)) {
                $skipped++;
                continue;
            }

            $string_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_strings} WHERE string_key = %s AND context = %s",
                $key,
                $context
            ));

            if (!$string_id) {
                $inserted = $wpdb->insert($table_strings, [
                    'string_key' => $key,
                    'context' => $context,
                    'description' => '',
                ]);

                if ($inserted === false) {
                    $skipped++;
                    continue;
                }

                $string_id = $wpdb->insert_id;
                $added++;
            } else {
                $skipped++;
            }

            $existing_translation = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_translations} WHERE string_id = %d AND language_code = %s",
                $string_id,
                $default_code
            ));

            if (!$existing_translation) {
                $text = ($entry['fallback'] ?? '') !== '' ? $entry['fallback'] : $key;

                $wpdb->insert($table_translations, [
                    'string_id' => $string_id,
                    'language_code' => $default_code,
                    'translation' => $text,
                    'status' => 'published',
                    'translated_by' => null,
                    'translated_at' => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
                ]);
            }
        }

        return [
            'added' => $added,
            'skipped_existing' => $skipped,
            'total_found' => count($entries),
        ];
    }
}
