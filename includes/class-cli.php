<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.cli

/**
 * WP-CLI Commands for Simple Translation Manager
 *
 * Usage:
 *   wp stm import-posts translations.json --lang=nl
 *   wp stm find-missing --post-type=mdj_service --lang=nl
 */

namespace STM;

if (!defined('ABSPATH')) exit;

if (!class_exists('WP_CLI')) {
    return;
}

class CLI {

    /**
     * Import post translations from JSON
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to JSON file with translations
     *
     * [--lang=<code>]
     * : Language code (default: nl)
     *
     * [--dry-run]
     * : Preview changes without saving
     *
     * ## EXAMPLES
     *
     *     wp stm import-posts translations.json --lang=nl
     *     wp stm import-posts /path/to/translations.json --lang=en --dry-run
     *
     * ## JSON FORMAT
     *
     * {
     *   "2903": {
     *     "category": "Innovatie",
     *     "title": "AI Integratie",
     *     "description": "Op maat gemaakte AI oplossingen..."
     *   },
     *   "2904": {
     *     "category": "Ontwikkeling",
     *     "title": "Web Ontwikkeling"
     *   }
     * }
     */
    public function import_posts($args, $assoc_args) {
        $file = $args[0];
        $lang = $assoc_args['lang'] ?? 'nl';
        $dry_run = isset($assoc_args['dry-run']);

        if (!file_exists($file)) {
            \WP_CLI::error("File not found: $file");
        }

        $data = json_decode(file_get_contents($file), true);

        if (!$data) {
            \WP_CLI::error("Invalid JSON file");
        }

        $success = 0;
        $failed = 0;

        \WP_CLI::line("Processing " . count($data) . " posts...\n");

        foreach ($data as $post_id => $translations) {
            $post = get_post($post_id);

            if (!$post) {
                \WP_CLI::warning("Post $post_id not found - skipping");
                $failed++;
                continue;
            }

            \WP_CLI::log("Post $post_id ({$post->post_title}):");

            if ($dry_run) {
                \WP_CLI::log("  [DRY RUN] Would save " . count($translations) . " translations");
                foreach ($translations as $field => $value) {
                    \WP_CLI::log("    - $field: " . substr($value, 0, 50) . "...");
                }
            } else {
                $result = API::save_post_translations($post_id, $translations, $lang);

                if (count($result['errors']) > 0) {
                    \WP_CLI::warning("  Partial success: {$result['success']}/{$result['total']} saved");
                    foreach ($result['errors'] as $error) {
                        \WP_CLI::warning("    $error");
                    }
                    $failed++;
                } else {
                    \WP_CLI::success("  {$result['success']}/{$result['total']} translations saved");
                    $success++;
                }
            }

            \WP_CLI::line("");
        }

        if ($dry_run) {
            \WP_CLI::success("Dry run complete. " . count($data) . " posts would be processed.");
        } else {
            \WP_CLI::success("Import complete: $success posts updated, $failed failed");
        }
    }

    /**
     * Find posts missing translations
     *
     * ## OPTIONS
     *
     * [--post-type=<type>]
     * : Post type to check (default: all)
     *
     * [--lang=<code>]
     * : Language code (default: nl)
     *
     * [--field=<field>]
     * : Specific field to check (default: title)
     *
     * [--export=<file>]
     * : Export missing posts to JSON template file
     *
     * ## EXAMPLES
     *
     *     wp stm find-missing --post-type=mdj_service --lang=nl
     *     wp stm find-missing --post-type=mdj_project --lang=nl --export=missing.json
     */
    public function find_missing($args, $assoc_args) {
        $post_type = $assoc_args['post-type'] ?? 'any';
        $lang = $assoc_args['lang'] ?? 'nl';
        $field = $assoc_args['field'] ?? 'title';
        $export_file = $assoc_args['export'] ?? null;

        $posts = get_posts([
            'post_type' => $post_type,
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ]);

        $missing = [];
        $total = count($posts);

        \WP_CLI::line("Checking $total posts for missing $lang translations...\n");

        foreach ($posts as $post) {
            $has_translation = stm_get_post_translation($post->ID, $field, $lang);

            // Check if translation exists and is different from original
            if (!$has_translation || $has_translation === $post->post_title) {
                $missing[] = $post;
                \WP_CLI::log("[MISSING] {$post->ID} - {$post->post_title} ({$post->post_type})");
            }
        }

        \WP_CLI::line("");

        if (count($missing) === 0) {
            \WP_CLI::success("All posts have $lang translations!");
        } else {
            \WP_CLI::warning(count($missing) . " posts missing $lang translations");

            // Export template if requested
            if ($export_file) {
                $template = [];
                foreach ($missing as $post) {
                    $template[$post->ID] = [
                        'title' => '',
                        'description' => '',
                        'category' => '',
                        '_note' => "Original: {$post->post_title}"
                    ];
                }

                file_put_contents($export_file, json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                \WP_CLI::success("Template exported to: $export_file");
            }
        }
    }

    /**
     * Show translation statistics
     *
     * ## OPTIONS
     *
     * [--post-type=<type>]
     * : Post type to analyze (default: all)
     *
     * ## EXAMPLES
     *
     *     wp stm stats
     *     wp stm stats --post-type=mdj_service
     */
    public function stats($args, $assoc_args) {
        global $wpdb;
        $post_type = $assoc_args['post-type'] ?? 'any';

        $languages = Database::get_languages();

        \WP_CLI::line("=== Translation Statistics ===\n");

        // Posts query
        $posts = get_posts([
            'post_type' => $post_type,
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ]);

        $total_posts = count($posts);
        \WP_CLI::line("Total posts: $total_posts\n");

        // Check translations per language

        foreach ($languages as $lang) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the plugin's own table wp_stm_post_translations (no WordPress API for custom tables); runs only on an administrator's request (admin screen, AJAX call or WP-CLI)
            $translated = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->prefix}stm_post_translations WHERE language_code = %s",
                $lang->code
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

            $percentage = $total_posts > 0 ? round(($translated / $total_posts) * 100, 1) : 0;

            \WP_CLI::line("{$lang->flag_emoji} {$lang->native_name} ({$lang->code}): $translated/$total_posts ($percentage%)");
        }

        \WP_CLI::line("");
    }

    /**
     * List translations whose source text changed after they were made
     *
     * ## OPTIONS
     *
     * [--post=<id>]
     * : Only this post
     *
     * [--scan]
     * : Re-compare every hashed translation with its current source first. Use after
     *   changes that bypass save_post (imports, direct database edits).
     *
     * [--format=<format>]
     * : table, json, csv or count (default: table)
     *
     * ## EXAMPLES
     *
     *     wp stm stale
     *     wp stm stale --scan --format=count
     */
    public function stale($args, $assoc_args) {
        global $wpdb;
        $post_id = isset($assoc_args['post']) ? (int) $assoc_args['post'] : 0;

        if (isset($assoc_args['scan'])) {
            if ($post_id) {
                SourceSync::refresh_stale_flags($post_id);
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the plugin's own table wp_stm_post_translations (no WordPress API for custom tables); WP-CLI maintenance command
                $ids = $wpdb->get_col("SELECT DISTINCT post_id FROM {$wpdb->prefix}stm_post_translations WHERE source_hash IS NOT NULL");
                foreach ($ids as $id) {
                    SourceSync::refresh_stale_flags((int) $id);
                }
            }
        }

        $rows = SourceSync::get_stale($post_id);
        \WP_CLI\Utils\format_items($assoc_args['format'] ?? 'table', $rows, ['post_id', 'field_name', 'language_code', 'status']);
    }

    /**
     * Stamp the current source hash on legacy translations that have none
     *
     * Rows made before source tracking existed have an unknown age and are never
     * flagged stale. Run this once when you are sure they are up to date; from then
     * on a source change flags them.
     *
     * ## OPTIONS
     *
     * [--post=<id>]
     * : Only this post
     *
     * [--dry-run]
     * : Count the rows without writing
     *
     * ## EXAMPLES
     *
     *     wp stm stamp-baseline --dry-run
     *     wp stm stamp-baseline
     */
    public function stamp_baseline($args, $assoc_args) {
        $post_id = isset($assoc_args['post']) ? (int) $assoc_args['post'] : 0;
        $dry_run = isset($assoc_args['dry-run']);

        $count = SourceSync::stamp_baseline($post_id, $dry_run);
        if (!$dry_run && $count) {
            Cache::flush_all();
        }
        \WP_CLI::success(($dry_run ? 'Would stamp ' : 'Stamped ') . $count . ' translation row(s).');
    }

    /**
     * Retranslate stale machine/legacy translations from their current source
     *
     * Reviewed and approved translations are never overwritten; they stay stale for a human.
     *
     * ## OPTIONS
     *
     * [--post=<id>]
     * : Only this post (default: every post with stale rows)
     *
     * ## EXAMPLES
     *
     *     wp stm retranslate-stale
     *     wp stm retranslate-stale --post=123
     */
    public function retranslate_stale($args, $assoc_args) {
        $post_id = isset($assoc_args['post']) ? (int) $assoc_args['post'] : 0;
        $ids = $post_id ? [$post_id] : array_values(array_unique(array_map(function ($row) {
            return (int) $row['post_id'];
        }, SourceSync::get_stale())));

        $totals = ['retranslated' => 0, 'skipped' => 0, 'errors' => 0];
        foreach ($ids as $id) {
            $result = SourceSync::retranslate_post($id);
            $totals['retranslated'] += $result['retranslated'];
            $totals['skipped'] += $result['skipped'];
            $totals['errors'] += count($result['errors']);
            foreach ($result['errors'] as $error) {
                \WP_CLI::warning("Post $id: $error");
            }
        }
        \WP_CLI::success("Retranslated {$totals['retranslated']}, left for review {$totals['skipped']}, failed {$totals['errors']}.");
    }

    /**
     * Keep the SEO God FAQ translations in line with the default-language FAQs
     *
     * Takes over language sets that already exist in SEO God, translates the languages that
     * have none and refreshes the stale machine translations. Reviewed and approved
     * translations are left for a person.
     *
     * ## OPTIONS
     *
     * [--post=<id>]
     * : Only this post
     *
     * [--adopt]
     * : First take over the existing `_seo_god_faqs_{lang}` sets as translations stamped with the
     *   current default set. Only on your word that they are up to date.
     *
     * [--adopt-status=<status>]
     * : Status for adopted sets: machine, reviewed or approved (default: machine)
     *
     * [--no-translate]
     * : Do not call the translation provider; only adopt and flag stale
     *
     * [--dry-run]
     * : With --adopt, count without writing
     *
     * ## EXAMPLES
     *
     *     wp stm seo-god-faqs --adopt --adopt-status=reviewed
     *     wp stm seo-god-faqs --post=10
     */
    public function seo_god_faqs($args, $assoc_args) {
        $ids = isset($assoc_args['post']) ? [(int) $assoc_args['post']] : SeoGodFaqSync::posts_with_faqs();
        $status = $assoc_args['adopt-status'] ?? SourceSync::STATUS_MACHINE;
        if (!SourceSync::valid_status($status)) {
            \WP_CLI::error('--adopt-status must be machine, reviewed or approved.');
        }

        $totals = ['adopted' => 0, 'created' => 0, 'retranslated' => 0, 'skipped' => 0, 'errors' => 0];
        foreach ($ids as $id) {
            if (isset($assoc_args['adopt'])) {
                $totals['adopted'] += SeoGodFaqSync::adopt_existing($id, $status, isset($assoc_args['dry-run']));
            }
            SourceSync::refresh_stale_flags($id);
            if (isset($assoc_args['no-translate']) || isset($assoc_args['dry-run'])) {
                continue;
            }
            $result = SeoGodFaqSync::sync_post($id);
            $totals['created'] += $result['created'];
            $totals['retranslated'] += $result['retranslated'];
            $totals['skipped'] += $result['skipped'];
            $totals['errors'] += count($result['errors']);
            foreach ($result['errors'] as $error) {
                \WP_CLI::warning("Post $id: $error");
            }
        }
        \WP_CLI::success("Adopted {$totals['adopted']}, created {$totals['created']}, retranslated {$totals['retranslated']}, left for review {$totals['skipped']}, failed {$totals['errors']}.");
    }
}

\WP_CLI::add_command('stm', 'STM\CLI');
