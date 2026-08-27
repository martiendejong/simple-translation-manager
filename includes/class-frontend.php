<?php
/**
 * Frontend Integration
 *
 * Filters post content to show translations
 * Handles language-specific URLs
 *
 * @package SimpleTranslationManager
 */

namespace STM;

class Frontend {

    /**
     * Initialize frontend hooks
     */
    public static function init() {
        // Content filters
        add_filter('the_title', [__CLASS__, 'filter_title'], 10, 2);
        add_filter('the_content', [__CLASS__, 'filter_content'], 10);
        add_filter('the_excerpt', [__CLASS__, 'filter_excerpt'], 10);
        // Priority 20 so these run after VISIT_Permalinks (priority 10) has
        // built the final URL — otherwise VISIT_Permalinks overwrites our prefix.
        add_filter('post_type_link', [__CLASS__, 'filter_permalink'], 20, 2);
        add_filter('page_link', [__CLASS__, 'filter_page_link'], 20, 3);
        add_filter('post_link', [__CLASS__, 'filter_post_link'], 20, 3);

        // Resolve an incoming translated-slug URL back to the real post
        // before WordPress's own post_name lookup runs.
        add_filter('request', [__CLASS__, 'resolve_translated_slug_request']);

        // Query modifications
        add_action('pre_get_posts', [__CLASS__, 'filter_query']);

        // Term translations
        add_filter('get_term', [__CLASS__, 'filter_term'], 10, 2);
    }

    /**
     * Get current language
     *
     * Priority: rewrite query var → GET param → cookie → default
     * The rewrite query var is set by WordPress when a /fr/... URL matches
     * a language-prefixed rewrite rule (see stm_rewrite_rules_array filter).
     *
     * Every candidate is also checked against the currently active language
     * list (Database::get_languages()) — a hidden language (is_active = 0)
     * must fall through to the default language instead of being served,
     * whether it comes from an old bookmark/rewrite hit, a ?lang= link, or a
     * stale stm_lang cookie set before the language was hidden.
     */
    /**
     * Persist the active language cookie — but only once per request, only before
     * headers are sent, and never on REST API calls (which are programmatic, not
     * user navigation). Calling setcookie() after output has started crashes
     * PHP-FPM because get_current_language() is invoked from content filters too.
     */
    private static function maybe_persist_cookie( string $lang ): void {
        static $done = false;
        if ( $done ) {
            return;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }
        if ( headers_sent() ) {
            return;
        }
        setcookie( 'stm_lang', $lang, time() + ( 86400 * 30 ), '/' );
        $done = true;
    }

    public static function get_current_language() {
        $active_codes = wp_list_pluck( Database::get_languages(), 'code' );

        // Priority 1: WordPress query var set by rewrite rules (/fr/topic/...)
        $from_query = get_query_var( 'lang', '' );
        if ( $from_query && Security::validate_language_code( $from_query ) && in_array( $from_query, $active_codes, true ) ) {
            $lang = sanitize_text_field( $from_query );
            self::maybe_persist_cookie( $lang );
            return $lang;
        }

        // Priority 2: explicit GET param (?lang=fr)
        if ( isset( $_GET['lang'] ) ) {
            $lang = sanitize_text_field( $_GET['lang'] );
            if ( Security::validate_language_code( $lang ) && in_array( $lang, $active_codes, true ) ) {
                self::maybe_persist_cookie( $lang );
                return $lang;
            }
        }

        // In URL routing mode the URL structure is authoritative: if neither a rewrite
        // query var nor an explicit ?lang= param is present, we are on a default-language
        // URL (e.g. /). The cookie must NOT override this — otherwise returning to /
        // after visiting /en/ stays in English because the stm_lang=en cookie lingers.
        if ( Settings::is_url_routing_enabled() ) {
            $default = Settings::get_default_language();
            self::maybe_persist_cookie( $default );
            return $default;
        }

        // Priority 3: cookie — only consulted in query-param (non-URL-routing) mode.
        if ( isset( $_COOKIE['stm_lang'] ) ) {
            $lang = sanitize_text_field( $_COOKIE['stm_lang'] );
            if ( in_array( $lang, $active_codes, true ) ) {
                return $lang;
            }
        }

        return Settings::get_default_language();
    }

    /**
     * Filter post title
     */
    public static function filter_title($title, $post_id = null) {
        if (!$post_id || is_admin()) {
            return $title;
        }

        $current_lang = self::get_current_language();
        $post_lang = PostEditor::get_post_language($post_id);

        // If post is in current language, return as-is
        if ($post_lang === $current_lang) {
            return $title;
        }

        // Get translation
        $translation = PostEditor::get_post_translation($post_id, $current_lang);

        if (!empty($translation['post_title'])) {
            return $translation['post_title'];
        }

        return $title;
    }

    /**
     * Filter post content
     */
    public static function filter_content($content) {
        if (is_admin()) {
            return $content;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return $content;
        }

        $current_lang = self::get_current_language();
        $post_lang = PostEditor::get_post_language($post_id);

        // If post is in current language, return as-is
        if ($post_lang === $current_lang) {
            return $content;
        }

        // Get translation
        $translation = PostEditor::get_post_translation($post_id, $current_lang);

        if (!empty($translation['post_content'])) {
            return $translation['post_content'];
        }

        return $content;
    }

    /**
     * Filter post excerpt
     */
    public static function filter_excerpt($excerpt) {
        if (is_admin()) {
            return $excerpt;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return $excerpt;
        }

        $current_lang = self::get_current_language();
        $post_lang = PostEditor::get_post_language($post_id);

        // If post is in current language, return as-is
        if ($post_lang === $current_lang) {
            return $excerpt;
        }

        // Get translation
        $translation = PostEditor::get_post_translation($post_id, $current_lang);

        if (!empty($translation['post_excerpt'])) {
            return $translation['post_excerpt'];
        }

        return $excerpt;
    }

    /**
     * Inject the current language prefix into a URL that starts with home_url().
     * No-ops on the default language or if the prefix is already present.
     */
    private static function add_language_prefix( string $url, string $lang ): string {
        $default_lang = Settings::get_default_language();
        if ( $lang === $default_lang ) {
            return $url;
        }
        $home = trailingslashit( home_url() );
        if ( strpos( $url, $home ) !== 0 ) {
            return $url;
        }
        $path = substr( $url, strlen( $home ) );
        // Guard against double-prefixing.
        if ( 0 === strpos( $path, $lang . '/' ) ) {
            return $url;
        }
        return $home . $lang . '/' . $path;
    }

    /**
     * Filter CPT permalink — runs at priority 20, after VISIT_Permalinks (10).
     */
    public static function filter_permalink( $permalink, $post ) {
        if ( is_admin() ) {
            return $permalink;
        }

        return self::localize_permalink( $permalink, $post, self::get_current_language() );
    }

    /**
     * Substitute a translated slug (when the editor set one) and inject the
     * /{lang}/ prefix into an already-built, default-language permalink.
     *
     * This is the single source of truth for "what does this post's URL
     * look like in language X" — filter_permalink() uses it for the
     * current request's language, Hreflang and Sitemap use it for every
     * OTHER active language, so none of the three duplicate the slug
     * lookup or the prefixing logic.
     */
    public static function localize_permalink( $permalink, $post, $language_code ) {
        $default_lang = Settings::get_default_language();

        if ( $language_code === $default_lang ) {
            return $permalink;
        }

        // Substitute translated slug when one exists
        $translation = PostEditor::get_post_translation( $post->ID, $language_code );
        if ( ! empty( $translation['post_name'] ) && $translation['post_name'] !== $post->post_name ) {
            $permalink = str_replace(
                '/' . $post->post_name . '/',
                '/' . $translation['post_name'] . '/',
                $permalink
            );
        }

        return self::add_language_prefix( $permalink, $language_code );
    }

    /**
     * Filter page permalink — runs at priority 20, after VISIT_Permalinks (10).
     */
    public static function filter_page_link( $url, $post_id, $sample ) {
        if ( is_admin() || $sample ) {
            return $url;
        }

        $current_lang = self::get_current_language();
        $post         = get_post( $post_id );
        if ( $post ) {
            $translation = PostEditor::get_post_translation( $post_id, $current_lang );
            if ( ! empty( $translation['post_name'] ) && $translation['post_name'] !== $post->post_name ) {
                $url = str_replace( '/' . $post->post_name . '/', '/' . $translation['post_name'] . '/', $url );
            }
        }

        return self::add_language_prefix( $url, $current_lang );
    }

    /**
     * Filter regular post permalink — runs at priority 20, after VISIT_Permalinks (10).
     */
    public static function filter_post_link( $url, $post, $leavename ) {
        if ( is_admin() || $leavename ) {
            return $url;
        }

        $current_lang = self::get_current_language();
        $translation  = PostEditor::get_post_translation( $post->ID, $current_lang );
        if ( ! empty( $translation['post_name'] ) && $translation['post_name'] !== $post->post_name ) {
            $url = str_replace( '/' . $post->post_name . '/', '/' . $translation['post_name'] . '/', $url );
        }

        return self::add_language_prefix( $url, $current_lang );
    }

    /**
     * The default-language permalink for $post with STM's own prefix/slug
     * substitution suppressed — the base path Hreflang and Sitemap build
     * every language's URL from, regardless of which language happens to
     * be active for the current request (filter_permalink() substitutes
     * based on get_current_language(), which would otherwise leak into
     * URLs meant for a different language).
     *
     * get_permalink() dispatches to a different filter depending on the
     * post's type — 'post_type_link' for custom post types, 'page_link'
     * for core pages, 'post_link' for core posts — so all three of STM's
     * permalink filters have to be suppressed here, at the same priority
     * they're registered with in init(), or get_permalink() still comes
     * back contaminated by the current request's language for pages/posts.
     */
    public static function get_base_permalink( $post ) {
        remove_filter( 'post_type_link', [ __CLASS__, 'filter_permalink' ], 20 );
        remove_filter( 'page_link', [ __CLASS__, 'filter_page_link' ], 20 );
        remove_filter( 'post_link', [ __CLASS__, 'filter_post_link' ], 20 );
        $permalink = get_permalink( $post );
        add_filter( 'post_type_link', [ __CLASS__, 'filter_permalink' ], 20, 2 );
        add_filter( 'page_link', [ __CLASS__, 'filter_page_link' ], 20, 3 );
        add_filter( 'post_link', [ __CLASS__, 'filter_post_link' ], 20, 3 );

        return $permalink;
    }

    /**
     * Resolve an incoming /{lang}/.../{slug}/ request back to the real post
     * when {slug} is a translated slug rather than the post's own post_name.
     *
     * stm_rewrite_rules_array() (simple-translation-manager.php) only
     * clones the existing rewrite rules with a language prefix — the
     * pattern itself still expects the ORIGINAL post_name, so a translated
     * slug set via the post editor (and already used in outbound links by
     * filter_permalink() above) 404s on the way back in. This hooks the
     * 'request' filter, after WordPress has turned the rewrite match into
     * query vars but before the main query runs, and swaps the translated
     * slug value back to the post's real post_name so the normal lookup
     * resolves it.
     */
    public static function resolve_translated_slug_request( $qv ) {
        if ( empty( $qv['lang'] ) ) {
            return $qv;
        }

        $language_code = $qv['lang'];
        if ( $language_code === Settings::get_default_language() ) {
            return $qv;
        }

        // Candidate query vars that can carry a post_name-style slug:
        // WordPress's generic 'name' (posts) / 'pagename' (pages) plus every
        // public post type's own query_var (custom post types register
        // their rewrite tag under their own name, e.g. visit_location=...
        // for /locatie/{slug}/).
        $candidates = [ 'name', 'pagename' ];
        foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $post_type ) {
            if ( ! empty( $post_type->query_var ) ) {
                $candidates[] = $post_type->query_var;
            }
        }

        foreach ( array_unique( $candidates ) as $key ) {
            if ( empty( $qv[ $key ] ) || ! is_string( $qv[ $key ] ) ) {
                continue;
            }

            $post_id = PostEditor::get_post_id_by_translated_slug( $language_code, $qv[ $key ] );
            if ( ! $post_id ) {
                continue;
            }

            $post = get_post( $post_id );
            if ( ! $post || $post->post_name === '' ) {
                continue;
            }

            $qv[ $key ] = $post->post_name;
        }

        return $qv;
    }

    /**
     * Filter query
     *
     * Optionally filter posts by language
     */
    public static function filter_query($query) {
        // Only filter main query on frontend
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        // Check if language filtering is enabled in settings
        // For now, we don't filter - show all posts but display translated content
        // This can be made configurable later

        return;
    }

    /**
     * Filter term (category/tag)
     */
    public static function filter_term($term, $taxonomy) {
        if (is_admin()) {
            return $term;
        }

        $current_lang = self::get_current_language();
        $default_lang = Settings::get_default_language();

        // If on default language, return as-is
        if ($current_lang === $default_lang) {
            return $term;
        }

        // Get term translation
        global $wpdb;
        $table = $wpdb->prefix . 'stm_term_translations';

        $translation = $wpdb->get_row($wpdb->prepare(
            "SELECT name, slug, description FROM {$table} WHERE term_id = %d AND language_code = %s",
            $term->term_id,
            $current_lang
        ));

        if ($translation) {
            $term->name = $translation->name;
            $term->slug = $translation->slug;
            if ($translation->description) {
                $term->description = $translation->description;
            }
        }

        return $term;
    }
}
