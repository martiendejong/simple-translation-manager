<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.source-sync

/**
 * Source-hash tracking for post translations (task 3520).
 *
 * Every row in stm_post_translations remembers a hash of the source text it
 * was made from (source_hash), who made it (status: machine | reviewed |
 * approved, NULL = legacy row of unknown origin) and whether the source has
 * changed since (stale). When the source post changes, the matching
 * translations are flagged stale instead of silently staying live as if they
 * were current; optionally a background job retranslates them.
 *
 * Rules:
 * - A row without a source_hash (legacy) is never flagged: its age is
 *   unknown. `wp stm stamp-baseline` stamps the current source on those rows
 *   once, on the owner's word that they are up to date.
 * - Retranslation only ever overwrites machine/legacy rows. Reviewed and
 *   approved translations stay stale and wait for a human.
 */

namespace STM;

class SourceSync {

    const STATUS_MACHINE  = 'machine';
    const STATUS_REVIEWED = 'reviewed';
    const STATUS_APPROVED = 'approved';

    /** Option: when truthy, stale machine/legacy rows are retranslated in the background. */
    const OPTION_AUTO_RETRANSLATE = 'stm_auto_retranslate';

    /** Cron hook that retranslates the stale rows of one post. */
    const CRON_RETRANSLATE = 'stm_retranslate_post';

    /** Field names (REST/API aliases) that read the same source as a native post column. */
    const FIELD_ALIASES = [
        'title'   => 'post_title',
        'content' => 'post_content',
        'excerpt' => 'post_excerpt',
        'slug'    => 'post_name',
    ];

    public static function init() {
        // Priority 20: PostEditor::save_translations (priority 10) has already
        // written and re-stamped any translation the editor changed, so only
        // the rows nobody touched are compared against the new source.
        add_action('save_post', [__CLASS__, 'on_save_post'], 20, 2);
        add_action(self::CRON_RETRANSLATE, [__CLASS__, 'retranslate_post'], 10, 1);
    }

    public static function valid_status($status) {
        return in_array($status, [self::STATUS_MACHINE, self::STATUS_REVIEWED, self::STATUS_APPROVED], true);
    }

    /**
     * Hash of a source text. Line endings and outer whitespace are normalised so
     * an editor round-trip that only changes them does not look like an edit.
     */
    public static function hash($value) {
        if ($value === null) {
            return null;
        }
        $normalised = trim(str_replace("\r\n", "\n", (string) $value));
        return md5($normalised);
    }

    /**
     * Current source text for a translated field of a post, or null when the
     * field has no readable single-string source (nothing to hash).
     */
    public static function source_value($post_id, $field) {
        $column = self::FIELD_ALIASES[$field] ?? $field;
        $post   = get_post($post_id);
        if (!$post) {
            return null;
        }

        if (in_array($column, ['post_title', 'post_content', 'post_excerpt', 'post_name'], true)) {
            return isset($post->$column) ? (string) $post->$column : null;
        }

        $meta = get_post_meta($post_id, $field, true);
        if (is_scalar($meta) && $meta !== '') {
            return (string) $meta;
        }

        /**
         * Lets integrations (Elementor, Pods, ...) supply the source text of
         * their own fields so those translations get tracked too.
         *
         * @param string|null $value
         * @param int         $post_id
         * @param string      $field
         */
        $value = apply_filters('stm_source_value', null, $post_id, $field);
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Columns to merge into a translation write so the row records the source
     * it was made from. Always resets stale: the caller just wrote a
     * translation for the source as it is now.
     *
     * @return array{source_hash: ?string, status: string, stale: int}
     */
    public static function stamp($post_id, $field, $status = self::STATUS_MACHINE) {
        return [
            'source_hash' => self::hash(self::source_value($post_id, $field)),
            'status'      => self::valid_status($status) ? $status : self::STATUS_MACHINE,
            'stale'       => 0,
        ];
    }

    /**
     * save_post: flag the translations of this post whose source changed.
     */
    public static function on_save_post($post_id, $post = null) {
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }
        $newly_stale = self::refresh_stale_flags($post_id);

        if ($newly_stale && get_option(self::OPTION_AUTO_RETRANSLATE) && !wp_next_scheduled(self::CRON_RETRANSLATE, [(int) $post_id])) {
            wp_schedule_single_event(time() + 60, self::CRON_RETRANSLATE, [(int) $post_id]);
        }
    }

    /**
     * Compare every hashed translation row of a post with the current source
     * and bring the stale flag in line.
     *
     * @return array[] rows that became stale just now: [['language_code' =>, 'field_name' =>], ...]
     */
    public static function refresh_stale_flags($post_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_translations';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the plugin's own table wp_stm_post_translations (no WordPress API for custom tables); compares stored hashes with the live source, a cached copy would defeat the check
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, field_name, language_code, source_hash, stale FROM {$wpdb->prefix}stm_post_translations WHERE post_id = %d AND source_hash IS NOT NULL",
            $post_id
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        $newly_stale = [];
        $changed     = false;
        foreach ((array) $rows as $row) {
            $current = self::hash(self::source_value($post_id, $row['field_name']));
            if ($current === null) {
                continue;
            }
            $is_stale = ($current !== $row['source_hash']) ? 1 : 0;
            if ($is_stale === (int) $row['stale']) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- writes the plugin's own table wp_stm_post_translations (no WordPress API for custom tables); Cache::invalidate_post() drops the cached copies right after
            $wpdb->update($table, ['stale' => $is_stale], ['id' => $row['id']], ['%d'], ['%d']);
            $changed = true;
            if ($is_stale) {
                $newly_stale[] = ['language_code' => $row['language_code'], 'field_name' => $row['field_name']];
                /**
                 * Fires when a translation stops matching its source.
                 *
                 * @param int    $post_id
                 * @param string $language_code
                 * @param string $field_name
                 */
                do_action('stm_translation_stale', $post_id, $row['language_code'], $row['field_name']);
            }
        }

        if ($changed) {
            Cache::invalidate_post($post_id);
        }
        return $newly_stale;
    }

    /**
     * One-time baseline for legacy rows: stamp the current source hash on rows
     * that have none. Status stays NULL (origin unknown).
     *
     * @return int rows stamped (or that would be, with $dry_run)
     */
    public static function stamp_baseline($post_id = 0, $dry_run = false) {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_post_translations';

        $where = 'source_hash IS NULL';
        $args  = [];
        if ($post_id) {
            $where .= ' AND post_id = %d';
            $args[] = (int) $post_id;
        }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- reads the plugin's own table wp_stm_post_translations (no WordPress API for custom tables); maintenance command; $where holds only fixed SQL text with %d markers
        $sql  = "SELECT id, post_id, field_name FROM {$wpdb->prefix}stm_post_translations WHERE $where";
        $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql, ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $stamped = 0;
        foreach ((array) $rows as $row) {
            $hash = self::hash(self::source_value((int) $row['post_id'], $row['field_name']));
            if ($hash === null) {
                continue;
            }
            if (!$dry_run) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- writes the plugin's own table wp_stm_post_translations (no WordPress API for custom tables); maintenance command, cache flushed by the caller
                $wpdb->update($table, ['source_hash' => $hash, 'stale' => 0], ['id' => $row['id']], ['%s', '%d'], ['%d']);
            }
            $stamped++;
        }
        return $stamped;
    }

    /**
     * Stale translation rows, optionally for one post.
     *
     * @return array[]
     */
    public static function get_stale($post_id = 0) {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the plugin's own table wp_stm_post_translations (no WordPress API for custom tables); reporting query, must reflect the live flags
        if ($post_id) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, post_id, field_name, language_code, status FROM {$wpdb->prefix}stm_post_translations WHERE stale = 1 AND post_id = %d",
                (int) $post_id
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results(
                "SELECT id, post_id, field_name, language_code, status FROM {$wpdb->prefix}stm_post_translations WHERE stale = 1",
                ARRAY_A
            );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (array) $rows;
    }

    /**
     * Retranslate the stale machine/legacy rows of one post from the current
     * source. Reviewed and approved rows are left stale for a human.
     *
     * @param int           $post_id
     * @param callable|null $translator fn($text, $source_lang, $target_lang, $field, $post_id): array
     *                                  shaped like AutoTranslate::translate(); default is AutoTranslate.
     * @return array{retranslated: int, skipped: int, errors: string[]}
     */
    public static function retranslate_post($post_id, $translator = null) {
        global $wpdb;
        $table  = $wpdb->prefix . 'stm_post_translations';
        $source = Settings::get_default_language();
        if ($translator === null) {
            $translator = [AutoTranslate::class, 'translate'];
        }

        $result = ['retranslated' => 0, 'skipped' => 0, 'errors' => []];
        foreach (self::get_stale($post_id) as $row) {
            if ($row['status'] === self::STATUS_REVIEWED || $row['status'] === self::STATUS_APPROVED) {
                $result['skipped']++;
                continue;
            }
            $text = self::source_value((int) $post_id, $row['field_name']);
            if ($text === null || $text === '') {
                $result['skipped']++;
                continue;
            }

            $out = call_user_func($translator, $text, $source, $row['language_code'], $row['field_name'], (int) $post_id);
            if (empty($out['success'])) {
                $result['errors'][] = $row['language_code'] . '/' . $row['field_name'] . ': ' . ($out['error'] ?? 'unknown error');
                continue;
            }

            $translation = $out['translation'];
            if ($row['field_name'] === 'post_name') {
                $translation = sanitize_title($translation);
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- writes the plugin's own table wp_stm_post_translations (no WordPress API for custom tables); Cache::invalidate_post() drops the cached copies right after
            $wpdb->update(
                $table,
                array_merge(['translation' => $translation, 'updated_at' => current_time('mysql')], self::stamp((int) $post_id, $row['field_name'], self::STATUS_MACHINE)),
                ['id' => $row['id']]
            );
            $result['retranslated']++;
        }

        if ($result['retranslated']) {
            Cache::invalidate_post($post_id);
        }
        return $result;
    }
}
