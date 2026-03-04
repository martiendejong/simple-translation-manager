<?php
/**
 * XLIFF/PO Import/Export
 *
 * Support for industry-standard translation file formats:
 * - XLIFF (XML Localization Interchange File Format) - used by SDL Trados, memoQ, Memsource
 * - PO/POT (Portable Object) - used by GNU gettext, Poedit, Loco Translate
 *
 * @package SimpleTranslationManager
 */

namespace STM;

class ImportExport {

    /**
     * Initialize hooks
     */
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('admin_post_stm_export_xliff', [__CLASS__, 'handle_export_xliff']);
        add_action('admin_post_stm_export_po', [__CLASS__, 'handle_export_po']);
        add_action('admin_post_stm_import_file', [__CLASS__, 'handle_import_file']);
    }

    /**
     * Register REST API routes
     */
    public static function register_routes() {
        $namespace = 'stm/v1';

        register_rest_route($namespace, '/export/xliff', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'rest_export_xliff'],
            'permission_callback' => [API::class, 'check_permissions'],
        ]);

        register_rest_route($namespace, '/export/po', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'rest_export_po'],
            'permission_callback' => [API::class, 'check_permissions'],
        ]);

        register_rest_route($namespace, '/import/file', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rest_import_file'],
            'permission_callback' => [API::class, 'check_permissions'],
        ]);
    }

    // =========================================================================
    // XLIFF Export
    // =========================================================================

    /**
     * Export translations as XLIFF 1.2
     *
     * @param string $source_lang Source language code
     * @param string $target_lang Target language code
     * @param string $context Optional context filter
     * @return string XLIFF XML content
     */
    public static function export_xliff($source_lang, $target_lang, $context = '') {
        global $wpdb;

        $table_strings = $wpdb->prefix . 'stm_strings';
        $table_translations = $wpdb->prefix . 'stm_translations';

        $where = ['1=1'];
        $params = [];

        if ($context) {
            $where[] = 's.context = %s';
            $params[] = $context;
        }

        $where_sql = implode(' AND ', $where);

        $query = "
            SELECT s.id, s.string_key, s.context, s.description,
                   src.translation as source_text,
                   tgt.translation as target_text,
                   tgt.status as target_status
            FROM {$table_strings} s
            LEFT JOIN {$table_translations} src ON s.id = src.string_id AND src.language_code = %s
            LEFT JOIN {$table_translations} tgt ON s.id = tgt.string_id AND tgt.language_code = %s
            WHERE {$where_sql}
            ORDER BY s.context, s.string_key
        ";

        array_unshift($params, $target_lang);
        array_unshift($params, $source_lang);

        $results = $wpdb->get_results($wpdb->prepare($query, ...$params));

        // Build XLIFF XML
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><xliff/>');
        $xml->addAttribute('version', '1.2');
        $xml->addAttribute('xmlns', 'urn:oasis:names:tc:xliff:document:1.2');

        $file = $xml->addChild('file');
        $file->addAttribute('source-language', $source_lang);
        $file->addAttribute('target-language', $target_lang);
        $file->addAttribute('datatype', 'plaintext');
        $file->addAttribute('original', 'stm-translations');
        $file->addAttribute('tool-id', 'simple-translation-manager');
        $file->addAttribute('date', gmdate('Y-m-d\TH:i:s\Z'));

        $body = $file->addChild('body');

        foreach ($results as $row) {
            $unit = $body->addChild('trans-unit');
            $unit->addAttribute('id', $row->string_key);

            if ($row->context && $row->context !== 'general') {
                $note = $unit->addChild('note', htmlspecialchars($row->context));
                $note->addAttribute('from', 'context');
            }

            if ($row->description) {
                $note = $unit->addChild('note', htmlspecialchars($row->description));
                $note->addAttribute('from', 'description');
            }

            $source = $unit->addChild('source', htmlspecialchars($row->source_text ?: $row->string_key));
            $source->addAttribute('xml:lang', $source_lang);

            if ($row->target_text) {
                $target = $unit->addChild('target', htmlspecialchars($row->target_text));
                $target->addAttribute('xml:lang', $target_lang);
                $target->addAttribute('state', $row->target_status === 'published' ? 'final' : 'needs-review-translation');
            } else {
                $target = $unit->addChild('target', '');
                $target->addAttribute('xml:lang', $target_lang);
                $target->addAttribute('state', 'new');
            }
        }

        // Pretty print
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());

        return $dom->saveXML();
    }

    // =========================================================================
    // PO Export
    // =========================================================================

    /**
     * Export translations as PO file
     *
     * @param string $lang Target language code
     * @param string $context Optional context filter
     * @return string PO file content
     */
    public static function export_po($lang, $context = '') {
        global $wpdb;

        $table_strings = $wpdb->prefix . 'stm_strings';
        $table_translations = $wpdb->prefix . 'stm_translations';

        $where = ['1=1'];
        $params = [$lang];

        if ($context) {
            $where[] = 's.context = %s';
            $params[] = $context;
        }

        $where_sql = implode(' AND ', $where);

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT s.string_key, s.context, s.description,
                   t.translation, t.status
            FROM {$table_strings} s
            LEFT JOIN {$table_translations} t ON s.id = t.string_id AND t.language_code = %s
            WHERE {$where_sql}
            ORDER BY s.context, s.string_key
        ", ...$params));

        $output = [];

        // PO Header
        $output[] = '# Simple Translation Manager - PO Export';
        $output[] = '# Language: ' . $lang;
        $output[] = '# Generated: ' . gmdate('Y-m-d H:i:s');
        $output[] = '#';
        $output[] = 'msgid ""';
        $output[] = 'msgstr ""';
        $output[] = '"Project-Id-Version: STM 1.0\\n"';
        $output[] = '"POT-Creation-Date: ' . gmdate('Y-m-d H:i+0000') . '\\n"';
        $output[] = '"PO-Revision-Date: ' . gmdate('Y-m-d H:i+0000') . '\\n"';
        $output[] = '"Language: ' . $lang . '\\n"';
        $output[] = '"MIME-Version: 1.0\\n"';
        $output[] = '"Content-Type: text/plain; charset=UTF-8\\n"';
        $output[] = '"Content-Transfer-Encoding: 8bit\\n"';
        $output[] = '';

        foreach ($results as $row) {
            // Description as comment
            if ($row->description) {
                $output[] = '#. ' . str_replace("\n", "\n#. ", $row->description);
            }

            // Context
            if ($row->context && $row->context !== 'general') {
                $output[] = 'msgctxt "' . self::escape_po_string($row->context) . '"';
            }

            // Fuzzy flag for draft translations
            if ($row->status === 'draft') {
                $output[] = '#, fuzzy';
            }

            // Source string (key)
            $output[] = 'msgid "' . self::escape_po_string($row->string_key) . '"';

            // Translation
            $translation = $row->translation ?: '';
            $output[] = 'msgstr "' . self::escape_po_string($translation) . '"';
            $output[] = '';
        }

        return implode("\n", $output);
    }

    // =========================================================================
    // Import
    // =========================================================================

    /**
     * Import translations from XLIFF content
     *
     * @param string $xml_content XLIFF XML string
     * @return array ['created' => int, 'updated' => int, 'errors' => array]
     */
    public static function import_xliff($xml_content) {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_content);

        if ($xml === false) {
            $errors = libxml_get_errors();
            $result['errors'][] = 'Invalid XLIFF XML: ' . ($errors[0]->message ?? 'parse error');
            libxml_clear_errors();
            return $result;
        }

        // Get target language from file element
        $file = $xml->file;
        if (!$file) {
            $result['errors'][] = 'Missing <file> element in XLIFF';
            return $result;
        }

        $target_lang = (string) $file['target-language'];
        if (!Security::validate_language_code($target_lang)) {
            $result['errors'][] = "Invalid target language: $target_lang";
            return $result;
        }

        $body = $file->body;
        if (!$body) {
            $result['errors'][] = 'Missing <body> element in XLIFF';
            return $result;
        }

        global $wpdb;
        $table_strings = $wpdb->prefix . 'stm_strings';
        $table_translations = $wpdb->prefix . 'stm_translations';

        foreach ($body->{'trans-unit'} as $unit) {
            $string_key = (string) $unit['id'];
            $target = $unit->target;

            if (!$target || !strlen((string) $target)) {
                $result['skipped']++;
                continue;
            }

            $translation = Security::sanitize_translation((string) $target);
            $state = (string) $target['state'];
            $status = ($state === 'final' || $state === 'translated') ? 'published' : 'draft';

            // Get context from note
            $context = 'general';
            foreach ($unit->note as $note) {
                if ((string) $note['from'] === 'context') {
                    $context = Security::sanitize_context((string) $note);
                    break;
                }
            }

            // Find or create string
            $string = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$table_strings} WHERE string_key = %s AND context = %s",
                $string_key, $context
            ));

            if (!$string) {
                $wpdb->insert($table_strings, [
                    'string_key' => $string_key,
                    'context' => $context,
                ]);
                $string_id = $wpdb->insert_id;
            } else {
                $string_id = $string->id;
            }

            // Upsert translation
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_translations} WHERE string_id = %d AND language_code = %s",
                $string_id, $target_lang
            ));

            $data = [
                'string_id' => $string_id,
                'language_code' => $target_lang,
                'translation' => $translation,
                'status' => $status,
                'translated_by' => get_current_user_id(),
                'translated_at' => current_time('mysql'),
            ];

            if ($existing) {
                $wpdb->update($table_translations, $data, ['id' => $existing]);
                $result['updated']++;
            } else {
                $wpdb->insert($table_translations, $data);
                $result['created']++;
            }

            Cache::invalidate_string($string_key, $context);
        }

        return $result;
    }

    /**
     * Import translations from PO file content
     *
     * @param string $po_content PO file string
     * @param string $lang Language code
     * @return array ['created' => int, 'updated' => int, 'errors' => array]
     */
    public static function import_po($po_content, $lang) {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        if (!Security::validate_language_code($lang)) {
            $result['errors'][] = "Invalid language code: $lang";
            return $result;
        }

        // Parse PO entries
        $entries = self::parse_po($po_content);

        global $wpdb;
        $table_strings = $wpdb->prefix . 'stm_strings';
        $table_translations = $wpdb->prefix . 'stm_translations';

        foreach ($entries as $entry) {
            if (empty($entry['msgid']) || empty($entry['msgstr'])) {
                $result['skipped']++;
                continue;
            }

            $string_key = $entry['msgid'];
            $translation = Security::sanitize_translation($entry['msgstr']);
            $context = !empty($entry['msgctxt']) ? Security::sanitize_context($entry['msgctxt']) : 'general';
            $is_fuzzy = !empty($entry['fuzzy']);

            // Find or create string
            $string = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$table_strings} WHERE string_key = %s AND context = %s",
                $string_key, $context
            ));

            if (!$string) {
                $wpdb->insert($table_strings, [
                    'string_key' => $string_key,
                    'context' => $context,
                ]);
                $string_id = $wpdb->insert_id;
            } else {
                $string_id = $string->id;
            }

            // Upsert translation
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_translations} WHERE string_id = %d AND language_code = %s",
                $string_id, $lang
            ));

            $data = [
                'string_id' => $string_id,
                'language_code' => $lang,
                'translation' => $translation,
                'status' => $is_fuzzy ? 'draft' : 'published',
                'translated_by' => get_current_user_id(),
                'translated_at' => current_time('mysql'),
            ];

            if ($existing) {
                $wpdb->update($table_translations, $data, ['id' => $existing]);
                $result['updated']++;
            } else {
                $wpdb->insert($table_translations, $data);
                $result['created']++;
            }

            Cache::invalidate_string($string_key, $context);
        }

        return $result;
    }

    // =========================================================================
    // PO Parser
    // =========================================================================

    /**
     * Parse PO file content into entries
     *
     * @param string $content PO file content
     * @return array Array of entries with msgid, msgstr, msgctxt, fuzzy
     */
    private static function parse_po($content) {
        $entries = [];
        $current = ['msgid' => '', 'msgstr' => '', 'msgctxt' => '', 'fuzzy' => false];
        $last_key = '';

        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = rtrim($line);

            // Skip pure comments (but track fuzzy flag)
            if (preg_match('/^#,.*fuzzy/', $line)) {
                $current['fuzzy'] = true;
                continue;
            }
            if (strpos($line, '#') === 0) {
                continue;
            }

            // Empty line = end of entry
            if ($line === '') {
                if (!empty($current['msgid'])) {
                    $entries[] = $current;
                }
                $current = ['msgid' => '', 'msgstr' => '', 'msgctxt' => '', 'fuzzy' => false];
                $last_key = '';
                continue;
            }

            // Parse keywords
            if (preg_match('/^(msgid|msgstr|msgctxt)\s+"(.*)"$/', $line, $m)) {
                $last_key = $m[1];
                $current[$last_key] = self::unescape_po_string($m[2]);
                continue;
            }

            // Continuation string
            if (preg_match('/^"(.*)"$/', $line, $m) && $last_key) {
                $current[$last_key] .= self::unescape_po_string($m[1]);
            }
        }

        // Don't forget last entry
        if (!empty($current['msgid'])) {
            $entries[] = $current;
        }

        return $entries;
    }

    // =========================================================================
    // String helpers
    // =========================================================================

    private static function escape_po_string($str) {
        return str_replace(['"', "\n", "\r", "\t"], ['\\"', '\\n', '\\r', '\\t'], $str);
    }

    private static function unescape_po_string($str) {
        return str_replace(['\\"', '\\n', '\\r', '\\t'], ['"', "\n", "\r", "\t"], $str);
    }

    // =========================================================================
    // REST Handlers
    // =========================================================================

    public static function rest_export_xliff($request) {
        $source = sanitize_text_field($request->get_param('source') ?: 'en');
        $target = sanitize_text_field($request->get_param('target') ?: 'nl');
        $context = sanitize_text_field($request->get_param('context') ?: '');

        $content = self::export_xliff($source, $target, $context);

        $response = new \WP_REST_Response($content);
        $response->header('Content-Type', 'application/xliff+xml; charset=utf-8');
        $response->header('Content-Disposition', "attachment; filename=stm-{$source}-{$target}.xliff");
        return $response;
    }

    public static function rest_export_po($request) {
        $lang = sanitize_text_field($request->get_param('lang') ?: 'nl');
        $context = sanitize_text_field($request->get_param('context') ?: '');

        $content = self::export_po($lang, $context);

        $response = new \WP_REST_Response($content);
        $response->header('Content-Type', 'text/x-po; charset=utf-8');
        $response->header('Content-Disposition', "attachment; filename=stm-{$lang}.po");
        return $response;
    }

    public static function rest_import_file($request) {
        $files = $request->get_file_params();
        $params = $request->get_json_params() ?: $request->get_body_params();

        if (empty($files['file'])) {
            return new \WP_Error('no_file', 'No file uploaded', ['status' => 400]);
        }

        $file = $files['file'];
        $content = file_get_contents($file['tmp_name']);
        $filename = strtolower($file['name']);
        $lang = sanitize_text_field($params['lang'] ?? 'nl');

        if (strpos($filename, '.xliff') !== false || strpos($filename, '.xlf') !== false) {
            $result = self::import_xliff($content);
        } elseif (strpos($filename, '.po') !== false) {
            $result = self::import_po($content, $lang);
        } else {
            return new \WP_Error('unsupported_format', 'Supported formats: .xliff, .xlf, .po', ['status' => 400]);
        }

        return rest_ensure_response($result);
    }

    // =========================================================================
    // Admin POST handlers
    // =========================================================================

    public static function handle_export_xliff() {
        if (!Security::verify_admin_action('stm_export_xliff')) {
            wp_die('Unauthorized', 403);
        }

        $source = sanitize_text_field($_POST['source_lang'] ?? 'en');
        $target = sanitize_text_field($_POST['target_lang'] ?? 'nl');
        $context = sanitize_text_field($_POST['context'] ?? '');

        $content = self::export_xliff($source, $target, $context);

        header('Content-Type: application/xliff+xml; charset=utf-8');
        header("Content-Disposition: attachment; filename=stm-{$source}-{$target}.xliff");
        echo $content;
        exit;
    }

    public static function handle_export_po() {
        if (!Security::verify_admin_action('stm_export_po')) {
            wp_die('Unauthorized', 403);
        }

        $lang = sanitize_text_field($_POST['lang'] ?? 'nl');
        $context = sanitize_text_field($_POST['context'] ?? '');

        $content = self::export_po($lang, $context);

        header('Content-Type: text/x-po; charset=utf-8');
        header("Content-Disposition: attachment; filename=stm-{$lang}.po");
        echo $content;
        exit;
    }

    public static function handle_import_file() {
        if (!Security::verify_admin_action('stm_import_file')) {
            wp_die('Unauthorized', 403);
        }

        if (empty($_FILES['import_file'])) {
            wp_die('No file uploaded', 400);
        }

        $file = $_FILES['import_file'];
        $content = file_get_contents($file['tmp_name']);
        $filename = strtolower($file['name']);
        $lang = sanitize_text_field($_POST['lang'] ?? 'nl');

        if (strpos($filename, '.xliff') !== false || strpos($filename, '.xlf') !== false) {
            $result = self::import_xliff($content);
        } elseif (strpos($filename, '.po') !== false) {
            $result = self::import_po($content, $lang);
        } else {
            wp_die('Unsupported file format. Use .xliff, .xlf, or .po files.', 400);
        }

        // Clear the transient warning cache
        delete_transient('stm_untranslated_warning');

        $msg = sprintf('Import complete: %d created, %d updated, %d skipped.', $result['created'], $result['updated'], $result['skipped']);
        if (!empty($result['errors'])) {
            $msg .= ' Errors: ' . implode('; ', $result['errors']);
        }

        wp_redirect(add_query_arg(['imported' => '1', 'message' => urlencode($msg)], wp_get_referer()));
        exit;
    }
}
