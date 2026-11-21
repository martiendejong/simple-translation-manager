<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.seo-god-faq-sync

/**
 * SEO God FAQ synchronisation.
 *
 * SEO God keeps the FAQs of a post in post meta: `_seo_god_faqs` is the set in the
 * default language, `_seo_god_faqs_{lang}` is the set for another language. SEO God
 * only reads those sets, it does not translate them or notice when the default set
 * changes. This class gives them the same source-hash tracking as every other STM
 * translation:
 *
 * - The FAQs are one STM field (`seo_god_faqs`). The translation of that field is a
 *   JSON list of {question, answer, category}, stored in stm_post_translations next
 *   to the title and the content, with the usual source_hash / status / stale.
 * - Whenever `_seo_god_faqs` changes, by any route (meta box, front-end editing, the
 *   SEO God API, a script), the translations are flagged stale. With the option
 *   `stm_auto_retranslate` on, missing and stale machine translations are made again
 *   in the background; reviewed/approved ones wait for a person.
 * - The translation is projected into `_seo_god_faqs_{lang}`, so SEO God's own
 *   renderer and editor read it without knowing about STM. A set that a person edits
 *   there (or in the SEO God editor) is taken back as a reviewed translation.
 * - On the front end, readers that ask for `_seo_god_faqs` directly (the FAQPage
 *   schema, per-page SEO) get the set of the current language.
 *
 * Activates automatically when SEO God is loaded; no configuration needed.
 *
 * @package SimpleTranslationManager
 */

namespace STM;

class SeoGodFaqSync {

    /** STM field name under which the FAQ translations are stored. */
    const FIELD = 'seo_god_faqs';

    /** Post meta key of the FAQ set in the default language (SEO God's own key). */
    const SOURCE_KEY = '_seo_god_faqs';

    /** Cron hook that fills missing and refreshes stale FAQ translations of one post. */
    const CRON_SYNC = 'stm_seo_god_faq_sync';

    /** True while this class writes post meta or rows itself, so its own writes do not loop back. */
    private static $busy = false;

    /** True while the source set is read raw, i.e. without the front-end language swap. */
    private static $raw = false;

    public static function init() {
        if (!self::seo_god_active()) {
            return;
        }

        // The FAQ set as the text SourceSync hashes, and the way to translate that text.
        add_filter('stm_source_value', [__CLASS__, 'filter_source_value'], 10, 3);
        add_filter('stm_translate_field', [__CLASS__, 'filter_translate_field'], 10, 7);

        // A stored translation lands in SEO God's `_seo_god_faqs_{lang}` meta.
        add_action('stm_translation_saved', [__CLASS__, 'on_translation_saved'], 10, 5);

        // The default set or a language set changed, by whatever route.
        add_action('added_post_meta', [__CLASS__, 'on_meta_written'], 10, 4);
        add_action('updated_post_meta', [__CLASS__, 'on_meta_written'], 10, 4);
        add_action('deleted_post_meta', [__CLASS__, 'on_meta_deleted'], 10, 4);

        // Readers of the default set get the current language on the front end.
        add_filter('get_post_metadata', [__CLASS__, 'filter_frontend_faqs'], 10, 4);

        add_action(self::CRON_SYNC, [__CLASS__, 'sync_post'], 10, 1);
    }

    // -------------------------------------------------------------------------
    // Source text
    // -------------------------------------------------------------------------

    /**
     * The FAQs of a post in the default language as a normalised list.
     *
     * @return array[] [['question' =>, 'answer' =>, 'category' => (optional)], ...]
     */
    public static function read_source_faqs($post_id) {
        self::$raw = true;
        try {
            $value = get_post_meta($post_id, self::SOURCE_KEY, true);
        } finally {
            self::$raw = false;
        }
        return self::normalize($value);
    }

    /**
     * Normalise a stored FAQ set (array, or the JSON string some SEO God versions
     * write) to the list STM hashes and translates. Empty entries are dropped.
     */
    public static function normalize($value) {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value   = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $question = trim((string) ($item['question'] ?? ''));
            $answer   = trim((string) ($item['answer'] ?? ''));
            if ($question === '' && $answer === '') {
                continue;
            }
            $row = ['question' => $question, 'answer' => $answer];
            if (isset($item['category']) && (string) $item['category'] !== '') {
                $row['category'] = (string) $item['category'];
            }
            $list[] = $row;
        }
        return $list;
    }

    /** JSON form of a list: what is hashed and what is stored as the translation. */
    public static function encode(array $list) {
        return (string) wp_json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Filter stm_source_value: the text SourceSync hashes for the `seo_god_faqs` field. */
    public static function filter_source_value($value, $post_id, $field) {
        if ($field !== self::FIELD) {
            return $value;
        }
        $list = self::read_source_faqs((int) $post_id);
        return $list ? self::encode($list) : null;
    }

    // -------------------------------------------------------------------------
    // Translating
    // -------------------------------------------------------------------------

    /**
     * Translate every question and answer of a list. Categories are SEO God's own labels
     * and stay as they are.
     *
     * @param callable $translator fn($text, $source_lang, $target_lang, $context, $post_id): array
     * @return array{success: bool, translation: string, error: string, provider: string}
     */
    public static function translate_list(array $list, $source, $target, $post_id, $translator) {
        $out = [];
        foreach ($list as $i => $item) {
            $row = $item;
            foreach (['question', 'answer'] as $part) {
                $text = (string) ($item[$part] ?? '');
                if ($text === '') {
                    continue;
                }
                $result = call_user_func($translator, $text, $source, $target, 'seo_god_faq_' . $part, (int) $post_id);
                if (empty($result['success']) || !isset($result['translation']) || $result['translation'] === '') {
                    return [
                        'success'     => false,
                        'translation' => '',
                        'provider'    => '',
                        'error'       => 'FAQ ' . ($i + 1) . ' ' . $part . ': ' . ($result['error'] ?? 'unknown error'),
                    ];
                }
                $row[$part] = $result['translation'];
            }
            $out[] = $row;
        }
        return ['success' => true, 'translation' => self::encode($out), 'provider' => 'stm-faq', 'error' => ''];
    }

    /** Filter stm_translate_field: SourceSync asks how to translate a stale field's source text. */
    public static function filter_translate_field($result, $text, $source, $target, $field, $post_id, $translator) {
        if ($field !== self::FIELD) {
            return $result;
        }
        $list = self::normalize((string) $text);
        if (!$list) {
            return ['success' => false, 'translation' => '', 'provider' => '', 'error' => 'No FAQs to translate'];
        }
        return self::translate_list($list, $source, $target, $post_id, $translator);
    }

    // -------------------------------------------------------------------------
    // Reacting to changes
    // -------------------------------------------------------------------------

    public static function on_meta_written($meta_id, $post_id, $meta_key, $value = null) {
        if (self::$busy || !is_string($meta_key) || strpos($meta_key, self::SOURCE_KEY) !== 0 || wp_is_post_revision($post_id)) {
            return;
        }
        if ($meta_key === self::SOURCE_KEY) {
            self::on_source_changed((int) $post_id);
            return;
        }
        $lang = self::language_of_key($meta_key);
        if ($lang !== '') {
            self::on_language_set_changed((int) $post_id, $lang);
        }
    }

    public static function on_meta_deleted($meta_ids, $post_id, $meta_key, $value = null) {
        if (self::$busy || !is_string($meta_key) || strpos($meta_key, self::SOURCE_KEY . '_') !== 0 || wp_is_post_revision($post_id)) {
            return;
        }
        $lang = self::language_of_key($meta_key);
        if ($lang !== '') {
            self::delete_row((int) $post_id, $lang);
        }
    }

    /**
     * The default set changed: flag the translations stale and, with auto-retranslate on,
     * schedule the background job for the stale and the missing ones.
     */
    public static function on_source_changed($post_id) {
        $newly_stale = SourceSync::refresh_stale_flags($post_id);
        $missing     = self::missing_languages($post_id);

        if (($newly_stale || $missing) && get_option(SourceSync::OPTION_AUTO_RETRANSLATE) && !wp_next_scheduled(self::CRON_SYNC, [(int) $post_id])) {
            wp_schedule_single_event(time() + 60, self::CRON_SYNC, [(int) $post_id]);
        }
    }

    /**
     * A language set was written to `_seo_god_faqs_{lang}` by someone else (the SEO God
     * editor, its API, a script): that is the translation now, made from the source as
     * it is today, by a person. A set identical to the source is SEO God's
     * "copy before first edit" seed, not a translation, and is ignored.
     */
    public static function on_language_set_changed($post_id, $lang) {
        $list = self::normalize(get_post_meta($post_id, self::SOURCE_KEY . '_' . $lang, true));
        if (!$list) {
            self::delete_row($post_id, $lang);
            return;
        }
        if ($list === self::read_source_faqs($post_id)) {
            return;
        }
        $existing = PostEditor::get_post_translation($post_id, $lang);
        if (isset($existing[self::FIELD]) && $existing[self::FIELD] === self::encode($list)) {
            return;
        }
        self::store($post_id, $lang, $list, SourceSync::STATUS_REVIEWED, false);
    }

    /** stm_translation_saved: project a stored FAQ translation into SEO God's meta key. */
    public static function on_translation_saved($post_id, $lang, $field, $translation, $status = null) {
        if ($field !== self::FIELD || self::$busy) {
            return;
        }
        $list = self::normalize((string) $translation);
        self::$busy = true;
        try {
            if ($list) {
                update_post_meta((int) $post_id, self::SOURCE_KEY . '_' . $lang, $list);
            } else {
                delete_post_meta((int) $post_id, self::SOURCE_KEY . '_' . $lang);
            }
        } finally {
            self::$busy = false;
        }
    }

    // -------------------------------------------------------------------------
    // Rows
    // -------------------------------------------------------------------------

    /**
     * Store a FAQ translation row, stamped with the source as it is now.
     *
     * @param bool $project whether to write `_seo_god_faqs_{lang}` as well (false when the meta is where it came from)
     */
    public static function store($post_id, $lang, array $list, $status = SourceSync::STATUS_MACHINE, $project = true) {
        global $wpdb;
        $table       = $wpdb->prefix . 'stm_post_translations';
        $translation = self::encode($list);

        self::$busy = true;
        try {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the plugin's own table wp_stm_post_translations (no WordPress API for custom tables); read-before-write to choose update or insert
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}stm_post_translations WHERE post_id = %d AND field_name = %s AND language_code = %s",
                $post_id, self::FIELD, $lang
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

            $columns = array_merge(['translation' => $translation, 'updated_at' => current_time('mysql')], SourceSync::stamp($post_id, self::FIELD, $status));
            if ($existing) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- writes the plugin's own table wp_stm_post_translations (no WordPress API for custom tables); Cache::invalidate_post() drops the cached copies right after
                $wpdb->update($table, $columns, ['id' => $existing]);
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- writes the plugin's own table wp_stm_post_translations (no WordPress API for custom tables)
                $wpdb->insert($table, array_merge(['post_id' => $post_id, 'field_name' => self::FIELD, 'language_code' => $lang], $columns));
            }
            Cache::invalidate_post($post_id, self::FIELD);
        } finally {
            self::$busy = false;
        }

        if ($project) {
            self::on_translation_saved($post_id, $lang, self::FIELD, $translation, $status);
        }
    }

    private static function delete_row($post_id, $lang) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deletes a row of the plugin's own table wp_stm_post_translations (no WordPress API for custom tables); Cache::invalidate_post() drops the cached copies right after
        $wpdb->delete($wpdb->prefix . 'stm_post_translations', ['post_id' => $post_id, 'field_name' => self::FIELD, 'language_code' => $lang]);
        Cache::invalidate_post($post_id, self::FIELD);
    }

    // -------------------------------------------------------------------------
    // Languages
    // -------------------------------------------------------------------------

    /** Active, non-default language codes. */
    public static function target_languages() {
        $default = Settings::get_default_language();
        $codes   = [];
        foreach (Database::get_languages() as $language) {
            if ($language->code !== $default) {
                $codes[] = $language->code;
            }
        }
        return $codes;
    }

    /** The language code of a `_seo_god_faqs_{lang}` key when it is a target language, else ''. */
    private static function language_of_key($meta_key) {
        $lang = strtolower(substr((string) $meta_key, strlen(self::SOURCE_KEY . '_')));
        return in_array($lang, self::target_languages(), true) ? $lang : '';
    }

    /**
     * Target languages that have no FAQ translation row and no existing language set in
     * SEO God (such a set is somebody's work; `wp stm seo-god-faqs --adopt` takes it over).
     */
    public static function missing_languages($post_id) {
        if (!self::read_source_faqs($post_id)) {
            return [];
        }
        $missing = [];
        foreach (self::target_languages() as $lang) {
            $rows = PostEditor::get_post_translation($post_id, $lang);
            if (isset($rows[self::FIELD])) {
                continue;
            }
            if (self::normalize(get_post_meta($post_id, self::SOURCE_KEY . '_' . $lang, true))) {
                continue;
            }
            $missing[] = $lang;
        }
        return $missing;
    }

    // -------------------------------------------------------------------------
    // Sync job
    // -------------------------------------------------------------------------

    /**
     * Fill the missing FAQ translations of a post and refresh the stale machine ones.
     * Reviewed and approved translations stay as they are (SourceSync keeps them stale).
     *
     * @param callable|null $translator fn($text, $source_lang, $target_lang, $context, $post_id): array
     * @return array{created: int, retranslated: int, skipped: int, errors: string[]}
     */
    public static function sync_post($post_id, $translator = null) {
        $post_id    = (int) $post_id;
        $translator = $translator ?: [AutoTranslate::class, 'translate'];
        $source     = Settings::get_default_language();
        $result     = ['created' => 0, 'retranslated' => 0, 'skipped' => 0, 'errors' => []];

        $list = self::read_source_faqs($post_id);
        if (!$list) {
            return $result;
        }

        foreach (self::missing_languages($post_id) as $lang) {
            $out = self::translate_list($list, $source, $lang, $post_id, $translator);
            if (!$out['success']) {
                $result['errors'][] = $lang . '/' . self::FIELD . ': ' . $out['error'];
                continue;
            }
            self::store($post_id, $lang, self::normalize($out['translation']), SourceSync::STATUS_MACHINE);
            $result['created']++;
        }

        $stale = SourceSync::retranslate_post($post_id, $translator);
        $result['retranslated'] += $stale['retranslated'];
        $result['skipped']      += $stale['skipped'];
        $result['errors']        = array_merge($result['errors'], $stale['errors']);
        return $result;
    }

    /**
     * Take over the language sets already present in SEO God as STM translations, stamped
     * with the current default set. For sets nobody has tracked yet; run it on the owner's
     * word that they are up to date (same rule as `wp stm stamp-baseline`).
     *
     * @return int rows created
     */
    public static function adopt_existing($post_id, $status = SourceSync::STATUS_MACHINE, $dry_run = false) {
        $post_id = (int) $post_id;
        $source  = self::read_source_faqs($post_id);
        if (!$source) {
            return 0;
        }
        $adopted = 0;
        foreach (self::target_languages() as $lang) {
            $rows = PostEditor::get_post_translation($post_id, $lang);
            if (isset($rows[self::FIELD])) {
                continue;
            }
            $list = self::normalize(get_post_meta($post_id, self::SOURCE_KEY . '_' . $lang, true));
            if (!$list || $list === $source) {
                continue;
            }
            if (!$dry_run) {
                self::store($post_id, $lang, $list, $status, false);
            }
            $adopted++;
        }
        return $adopted;
    }

    /** Ids of the posts that have a default FAQ set. */
    public static function posts_with_faqs() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads core table wp_postmeta for a maintenance command (WP-CLI); no WordPress API lists "all posts with this meta key" without loading them
        $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::SOURCE_KEY));
        return array_map('intval', (array) $ids);
    }

    // -------------------------------------------------------------------------
    // Front end
    // -------------------------------------------------------------------------

    /**
     * Readers of `_seo_god_faqs` that do not ask SEO God for the language set (the FAQPage
     * schema, per-page SEO) get the set of the current language when there is one. Only on
     * the front end: admin screens and SEO God's editing AJAX see the real default set.
     */
    public static function filter_frontend_faqs($value, $post_id, $meta_key, $single) {
        if (self::$raw || $meta_key !== self::SOURCE_KEY || is_admin()) {
            return $value;
        }
        $lang = Frontend::get_current_language();
        if (!$lang || $lang === Settings::get_default_language()) {
            return $value;
        }
        $set = self::normalize(get_post_meta($post_id, self::SOURCE_KEY . '_' . $lang, true));
        if (!$set) {
            return $value;
        }
        return $single ? $set : [$set];
    }

    private static function seo_god_active() {
        return defined('SEO_GOD_VERSION') || function_exists('seo_god_detect_multilingual');
    }
}
