<?php
/**
 * WP-CLI Commands for Simple Translation Manager
 *
 * Usage:
 *   wp stm import-posts translations.json --lang=nl
 *   wp stm find-missing --post-type=mdj_service --lang=nl
 */

namespace STM;

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
        $table = $wpdb->prefix . 'stm_post_translations';

        foreach ($languages as $lang) {
            $translated = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT post_id) FROM {$table} WHERE language_code = %s",
                $lang->code
            ));

            $percentage = $total_posts > 0 ? round(($translated / $total_posts) * 100, 1) : 0;

            \WP_CLI::line("{$lang->flag_emoji} {$lang->native_name} ({$lang->code}): $translated/$total_posts ($percentage%)");
        }

        \WP_CLI::line("");
    }
}

\WP_CLI::add_command('stm', 'STM\CLI');
