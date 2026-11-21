<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.frontend

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
     * [STM-SYM-06] Name of the cookie that remembers a visitor's ?lang= choice
     * (consulted only in query-param mode, never in URL-routing mode).
     */
    const LANG_COOKIE = 'stm_lang';

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

        // [STM-DN-09] Resolve an incoming translated-slug URL back to the real post
        // before WordPress's own post_name lookup runs. Priority 2 — right
        // after stm_strip_lang_prefix_from_request() (priority 1, see
        // simple-translation-manager.php) — so this runs before ANY other
        // plugin's own 'request' filter, regardless of plugin load order.
        // Some CPT plugins (e.g. visit-platform's VISIT_Permalinks::resolve())
        // re-parse $wp->request directly instead of trusting query vars; this
        // must rewrite that raw path too (see resolve_translated_slug_request()
        // below) or such a plugin's own lookup 404s on a translated slug even
        // though the query var it also inspects was already corrected.
        add_filter('request', [__CLASS__, 'resolve_translated_slug_request'], 2);

        // Query modifications
        add_action('pre_get_posts', [__CLASS__, 'filter_query']);

        // Term translations
        add_filter('get_term', [__CLASS__, 'filter_term'], 10, 2);

        // Language preference (URL routing mode): send a bare default-language
        // URL to the visitor's explicitly chosen language, and canonicalize
        // ?lang= / /{default}/-prefixed URLs. Priority 2: AFTER the handlers
        // that own their own URLs and exit (visit-platform ShortLinks and
        // Maintenance/Passport/Preview run at 0-1), BEFORE redirect_canonical
        // (10) — so a short link still resolves and a prefixed URL is never
        // stripped back first.
        add_action('template_redirect', [__CLASS__, 'maybe_redirect_to_preferred_language'], 2);

        // WordPress's canonical redirect turns the preview link STM builds for a
        // hidden language (/?page_id=91&preview=true&lang=en) into /slug/?lang=en and
        // drops preview, so the editor's "Preview in language" showed the default
        // language. Skip it for exactly that request.
        add_filter('redirect_canonical', [__CLASS__, 'skip_canonical_redirect_for_language_preview'], 5);

        // Mirror the stm_lang cookie into localStorage and restore it after
        // cookie expiry (assets/language-preference.js).
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_language_preference_script']);
    }

    /**
     * Get current language
     *
     * Priority: rewrite query var → GET param → cookie → default
     * The rewrite query var is set by WordPress when a /fr/... URL matches
     * a language-prefixed rewrite rule (see stm_rewrite_rules_array filter).
     *
     * A requested language is only honored when it is active, or when the
     * current request is an authenticated "preview in language" of the
     * exact post being viewed (see is_authorized_language_preview()) — that
     * is what lets the editor's preview cycler show inactive-language
     * content to the admin preparing it, while keeping it unreachable to
     * ordinary visitors via ?lang=, the /{lang}/ URL prefix, or the cookie.
     */
    public static function get_current_language() {
        $requested = self::get_requested_language();

        if ( $requested !== '' && self::is_language_visible( $requested ) ) {
            return $requested;
        }

        return Settings::get_default_language();
    }

    /**
     * False until the stm_lang cookie has been (re)written during this
     * request, then the language code it was written with.
     * remember_language_choice() runs from template_redirect only (see
     * maybe_redirect_to_preferred_language()), so this is a safety net
     * rather than the primary guard it used to be: when the write still sat
     * in get_requested_language(), a search/archive loop called it once per
     * post (via get_permalink()/get_the_title()) and a single request could
     * emit a dozen-plus identical Set-Cookie headers. On this host's
     * PHP-FPM/IIS front end that makes the reverse proxy treat the response
     * as invalid and return a 502 — see the 869ebjzzc REST search crash.
     *
     * @var string|false
     */
    private static $cookie_written = false;

    /**
     * The language the request itself asks for — the rewrite query var
     * (/fr/topic/...) or the ?lang= GET param — or '' when it names none
     * in a valid format. Says nothing about whether that language is active
     * and never touches the cookie.
     */
    private static function get_explicit_language_request() {
        // Priority 1: WordPress query var set by rewrite rules (/fr/topic/...)
        $from_query = get_query_var( 'lang', '' );
        if ( $from_query && Security::validate_language_code( $from_query ) ) {
            return sanitize_text_field( $from_query );
        }

        // Priority 2: explicit GET param (?lang=fr)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public language link for visitors (cannot carry a nonce); the value must pass validate_language_code() and only picks which stored translation to show
        if ( isset( $_GET['lang'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same public language link as the line above
            $lang = sanitize_text_field( wp_unslash( $_GET['lang'] ) );
            if ( Security::validate_language_code( $lang ) ) {
                return $lang;
            }
        }

        return '';
    }

    /**
     * Read the requested language code from the rewrite query var, the
     * ?lang= GET param, or the persisted cookie — without yet checking
     * whether that language may actually be shown.
     *
     * Pure read: this runs from content filters (once per post in a loop),
     * from REST handlers that echo the page's ?lang= back, and for inactive
     * or garbage codes, none of which may move the stored preference. The
     * cookie is only ever written by remember_language_choice(), called
     * from maybe_redirect_to_preferred_language() at template_redirect.
     */
    private static function get_requested_language() {
        $explicit = self::get_explicit_language_request();
        if ( '' !== $explicit ) {
            return $explicit;
        }

        // [STM-DN-10] In URL routing mode the URL structure stays authoritative for
        // RENDERING: without a rewrite query var or ?lang= this is a
        // default-language URL and it renders the default language. The
        // cookie is not consulted here — maybe_redirect_to_preferred_language()
        // honors it instead, by 302'ing the whole request to the preferred
        // /{lang}/ URL before anything renders. The cookie is also no longer
        // reset to the default on every bare URL: it now means "last
        // EXPLICITLY chosen language" (via a /{lang}/ URL, ?lang=, or a
        // typed /{default}/ prefix), and only those paths may write it.
        if ( Settings::is_url_routing_enabled() ) {
            return '';
        }

        // Priority 3: cookie — only consulted in query-param (non-URL-routing) mode.
        if ( isset( $_COOKIE[self::LANG_COOKIE] ) ) {
            $lang = sanitize_text_field( wp_unslash( $_COOKIE[self::LANG_COOKIE] ) );
            if ( Security::validate_language_code( $lang ) ) {
                return $lang;
            }
        }

        return '';
    }

    /**
     * Whether this request is a visitor navigating to a page — the only
     * kind of request that may act on or persist a language preference.
     * Shared by the preference redirect and the cookie write so the two can
     * never disagree. Must run after the main query is parsed
     * (template_redirect or later): is_robots()/is_feed()/is_preview() are
     * not valid earlier.
     *
     * Not a navigation: wp-admin, REST/AJAX/cron (programmatic — the React
     * front-end sends ?lang=<page language> on every REST call), non-GET/
     * HEAD methods, robots/feeds, WP and Elementor previews, and core
     * sitemaps (which list canonical URLs per language already).
     */
    private static function is_navigation_request() {
        if ( is_admin() ) {
            return false;
        }
        if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() || wp_doing_cron() ) {
            return false;
        }

        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
        if ( 'GET' !== $method && 'HEAD' !== $method ) {
            return false;
        }
        if ( is_robots() || is_feed() || is_preview() || is_customize_preview() || self::is_elementor_preview() ) {
            return false;
        }
        if ( '' !== get_query_var( 'sitemap', '' ) ) {
            return false;
        }

        return true;
    }

    /**
     * Whether this is the Elementor editor's preview iframe
     * (/?elementor-preview={post}&ver=...). WP's is_preview() only knows
     * ?preview=true, so without this an editor whose browser holds an
     * stm_lang cookie would get the redirected twin in the editor iframe.
     * The query arg is checked first because it is present on the iframe
     * request regardless of who sends it; Elementor's own check, when the
     * plugin is loaded, covers the authenticated editor case.
     */
    private static function is_elementor_preview() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presence check, no action taken on the value.
        if ( isset( $_GET['elementor-preview'] ) ) {
            return true;
        }

        if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->preview ) ) {
            return (bool) \Elementor\Plugin::$instance->preview->is_preview_mode();
        }

        return false;
    }

    /**
     * [STM-DN-11] Persist an explicit language choice in the stm_lang cookie.
     *
     * Only an ACTIVE language, on a page navigation (is_navigation_request()),
     * moves the stored preference: a stray ?lang=xx / ?lang=en-US from a
     * crafted or tracking-tagged link, or a REST call echoing the page's
     * language, must not wipe a visitor's stored taalkeuze. Written at most
     * once per request and only while a header can still be sent.
     */
    private static function remember_language_choice( $lang ) {
        if ( false !== self::$cookie_written || headers_sent() ) {
            return;
        }
        if ( ! self::is_active_language( $lang ) || ! self::is_navigation_request() ) {
            return;
        }

        self::$cookie_written = $lang;
        setcookie( 'stm_lang', $lang, [
            'expires'  => time() + ( 86400 * 365 ),
            'path'     => '/',
            'secure'   => is_ssl(),
            'samesite' => 'Lax',
        ] );
    }

    /**
     * redirect_canonical filter: leave a "preview in language" request for a
     * hidden (inactive) language alone. Only an editor sees that language, and
     * only on a ?preview=true&lang=xx request; every other request keeps WordPress's
     * normal canonical redirect.
     *
     * @param string|false $redirect_url Where core wants to redirect to.
     * @return string|false
     */
    public static function skip_canonical_redirect_for_language_preview( $redirect_url ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of the request; no state change.
        if ( ! isset( $_GET['preview'], $_GET['lang'] ) ) {
            return $redirect_url;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same read-only check.
        $lang = sanitize_text_field( wp_unslash( $_GET['lang'] ) );
        if ( ! Security::validate_language_code( $lang ) || self::is_active_language( $lang ) ) {
            return $redirect_url;
        }

        return current_user_can( 'edit_posts' ) ? false : $redirect_url;
    }

    /**
     * Whether $code is one of the active languages. Unlike
     * is_language_visible() this never grants the authenticated-preview
     * exception — redirects must only ever target publicly reachable
     * languages.
     */
    private static function is_active_language( $code ) {
        if ( '' === $code ) {
            return false;
        }

        foreach ( Database::get_languages() as $language ) {
            if ( $language->code === $code ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip a leading /{lang}/ segment (any active language or the default)
     * off a site-relative path.
     */
    private static function strip_language_prefix( $path ) {
        $segments = explode( '/', ltrim( (string) $path, '/' ), 2 );
        $first    = $segments[0];

        if ( '' !== $first && ( $first === Settings::get_default_language() || self::is_active_language( $first ) ) ) {
            return '/' . ( isset( $segments[1] ) ? $segments[1] : '' );
        }

        return '' === $path ? '/' : $path;
    }

    /**
     * template_redirect callback: persist an explicit /{lang}/ URL or ?lang=
     * visit and perform the language-preference redirect decided by
     * get_language_preference_redirect().
     */
    public static function maybe_redirect_to_preferred_language() {
        // The ONLY place a language choice is persisted: here headers can
        // still be sent, whereas a content filter (the_title/post_type_link)
        // runs mid-output, once per post, and on REST calls too.
        // remember_language_choice() ignores inactive codes and anything
        // that is not a plain page navigation.
        $redirect = self::get_language_preference_redirect();

        if ( $redirect && ! headers_sent() ) {
            // The redirect target IS the choice (?lang=, typed /{default}/
            // prefix) or the already stored preference (bare URL).
            self::remember_language_choice( $redirect['lang'] );
            wp_safe_redirect( $redirect['url'], 302, 'STM' );
            exit;
        }

        // No redirect: visiting a /{lang}/ URL (or ?lang= in query-param
        // mode, which never redirects) is still an explicit choice.
        $explicit = self::get_explicit_language_request();
        if ( '' !== $explicit ) {
            self::remember_language_choice( $explicit );
        }
    }

    /**
     * Decide whether this request should be redirected for language
     * preference, and to where. URL routing mode only. Three cases:
     *
     *  1. ?lang=xx — explicit choice: persist, then redirect to the
     *     canonical URL for that language (prefixed path for a non-default
     *     language, the bare path for the default). This is how the
     *     language switcher's default-language link works: a plain bare
     *     link would immediately bounce back to the cookie language, so it
     *     carries ?lang={default} to make the choice explicit.
     *  2. /{default}/... — STM never generates these (the default language
     *     has no rewrite rules; bare URLs ARE the default), but a typed or
     *     shared one is still an explicit choice: persist and redirect to
     *     the bare equivalent instead of letting it 404.
     *  3. A bare URL with no explicit language and a stored non-default
     *     preference (stm_lang cookie): redirect to the same path under
     *     /{lang}/. An explicit language in the URL always wins over the
     *     cookie, so deep links and hreflang alternates are never hijacked;
     *     bots never send the cookie, so crawling is unaffected.
     *
     * Returns ['url' => absolute target, 'lang' => code] or null. Split from
     * maybe_redirect_to_preferred_language() so the decision is unit-testable
     * without the exit.
     */
    public static function get_language_preference_redirect() {
        if ( ! Settings::is_url_routing_enabled() || ! self::is_navigation_request() ) {
            return null;
        }

        $default     = Settings::get_default_language();
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
        $parsed      = wp_parse_url( $request_uri );
        $path        = isset( $parsed['path'] ) && '' !== $parsed['path'] ? $parsed['path'] : '/';
        $query       = isset( $parsed['query'] ) ? $parsed['query'] : '';

        // Path relative to the WordPress home path (subdirectory installs).
        $home_path = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
        $rel       = ( '' !== $home_path && 0 === strpos( $path, $home_path ) ) ? substr( $path, strlen( $home_path ) ) : $path;
        if ( '' === $rel ) {
            $rel = '/';
        }

        // Case 1: explicit ?lang= — redirect to that language's canonical URL.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only language lookup, validated against the active languages; no state change.
        if ( isset( $_GET['lang'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same read-only lookup.
            $lang = sanitize_text_field( wp_unslash( $_GET['lang'] ) );
            if ( ! Security::validate_language_code( $lang ) || ! self::is_active_language( $lang ) ) {
                return null; // unknown/inactive: render as-is (preview flow handles its own case)
            }

            $bare       = self::strip_language_prefix( $rel );
            $target_rel = ( $lang === $default ) ? $bare : '/' . $lang . $bare;

            parse_str( $query, $params );
            unset( $params['lang'] );
            $url = home_url( $target_rel );
            if ( ! empty( $params ) ) {
                $url .= '?' . http_build_query( $params );
            }

            return [ 'lang' => $lang, 'url' => $url ];
        }

        $segments = explode( '/', ltrim( $rel, '/' ), 2 );
        $first    = $segments[0];

        // Case 2: a typed /{default}/... URL — explicit default choice.
        if ( '' !== $first && $first === $default ) {
            $url = home_url( self::strip_language_prefix( $rel ) );
            if ( '' !== $query ) {
                $url .= '?' . $query;
            }

            return [ 'lang' => $default, 'url' => $url ];
        }

        // Explicit language already in the URL (matched rewrite rule or an
        // active-language first segment): the URL wins, never redirect.
        if ( '' !== get_query_var( 'lang', '' ) || self::is_active_language( $first ) ) {
            return null;
        }

        // Case 3: bare URL + stored preference.
        if ( ! isset( $_COOKIE['stm_lang'] ) ) {
            return null;
        }
        $preferred = sanitize_text_field( wp_unslash( $_COOKIE['stm_lang'] ) );
        if ( ! Security::validate_language_code( $preferred ) || $preferred === $default || ! self::is_active_language( $preferred ) ) {
            return null;
        }
        // A 404 stays a 404 — don't bounce it into the prefixed twin 404.
        if ( is_404() ) {
            return null;
        }

        $url = home_url( '/' . $preferred . $rel );
        if ( '' !== $query ) {
            $url .= '?' . $query;
        }

        return [ 'lang' => $preferred, 'url' => $url ];
    }

    /**
     * Front-end helper that mirrors the stm_lang cookie into localStorage
     * and restores it from localStorage when the cookie has expired or been
     * cleared — so a language choice survives long absences. Only loaded in
     * URL routing mode with more than one active language.
     */
    public static function enqueue_language_preference_script() {
        if ( ! Settings::is_url_routing_enabled() ) {
            return;
        }

        $languages = Database::get_languages();
        if ( count( $languages ) <= 1 ) {
            return;
        }

        wp_enqueue_script(
            'stm-language-preference',
            STM_PLUGIN_URL . 'assets/language-preference.js',
            [],
            STM_VERSION,
            true
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presence check, no action taken on the value.
        $explicit = '' !== get_query_var( 'lang', '' ) || isset( $_GET['lang'] );
        wp_add_inline_script(
            'stm-language-preference',
            'window.stmLangPreference = ' . wp_json_encode( [
                'current'     => self::get_current_language(),
                'defaultLang' => Settings::get_default_language(),
                'active'      => array_values( wp_list_pluck( $languages, 'code' ) ),
                'explicit'    => $explicit,
            ] ) . ';',
            'before'
        );
    }

    /**
     * A language may be rendered on the front end when it is active, or
     * when the current request is a genuine preview of the specific post
     * being viewed by someone allowed to edit it.
     */
    private static function is_language_visible( $lang_code ) {
        foreach ( Database::get_languages() as $language ) {
            if ( $language->code === $lang_code ) {
                return true;
            }
        }

        return self::is_authorized_language_preview();
    }

    /**
     * True when the current request is WordPress' own preview mechanism
     * (?preview=true, as generated by get_preview_post_link()) for a post
     * the current user is allowed to edit — the only case in which an
     * inactive language's content may reach the front end, and how the
     * "Preview in language" cycler works for languages that aren't live yet.
     */
    private static function is_authorized_language_preview() {
        // core's redirect_canonical() switches is_preview() back off for a PUBLISHED
        // post whose preview link carries no nonce, which is what STM's own preview
        // link is (the front page of weerzienmara.nl lost its preview that way).
        // The request itself says "preview" and the edit-capability check below is
        // what really gates this, so do not depend on that flag alone.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presence check; access is decided by current_user_can() below.
        $preview_requested = isset( $_GET['preview'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['preview'] ) );
        if ( ! is_preview() && ! $preview_requested ) {
            return false;
        }

        $post_id = get_queried_object_id();

        return $post_id && current_user_can( 'edit_post', $post_id );
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
     *
     * Two things get corrected, both from the SAME lookup
     * (PostEditor::get_post_id_by_translated_slug()):
     *
     *  1. The query vars WordPress's own rewrite match produced ('name',
     *     'pagename', a CPT's own query_var) — this is enough when nothing
     *     else re-parses the URL.
     *  2. $wp->request itself — the raw, un-rewritten path. Some CPT plugins
     *     that own a URL structure WordPress's native rewrite rules can't
     *     express (e.g. visit-platform's VISIT_Permalinks::resolve(), which
     *     builds /{locatie}/{aanbod}/-style paths) read $wp->request directly
     *     on their OWN 'request' filter rather than trusting query vars, to
     *     do their own post_name lookup. Left uncorrected, such a plugin
     *     sees the translated slug, finds no post with that literal
     *     post_name, and the request falls through to a 404 even though (1)
     *     already fixed the query var it never looks at. Registered at
     *     priority 2 (see init() above) so this runs before any such
     *     plugin's own request filter, regardless of plugin load order.
     */
    public static function resolve_translated_slug_request( $qv ) {
        if ( empty( $qv['lang'] ) ) {
            return $qv;
        }

        $language_code = $qv['lang'];
        if ( $language_code === Settings::get_default_language() ) {
            return $qv;
        }

        // Rewrite every segment of the raw request path that matches a
        // translated slug back to the real post_name, so any OTHER plugin's
        // own 'request' filter that re-parses $wp->request directly (instead
        // of trusting query vars) sees the real slug too.
        if ( ! empty( $GLOBALS['wp'] ) && is_string( $GLOBALS['wp']->request ) && '' !== $GLOBALS['wp']->request ) {
            $segments = explode( '/', trim( $GLOBALS['wp']->request, '/' ) );
            $changed  = false;

            foreach ( $segments as $i => $segment ) {
                if ( '' === $segment ) {
                    continue;
                }

                $post_id = PostEditor::get_post_id_by_translated_slug( $language_code, $segment );
                if ( ! $post_id ) {
                    continue;
                }

                $post = get_post( $post_id );
                if ( ! $post || '' === $post->post_name || $post->post_name === $segment ) {
                    continue;
                }

                $segments[ $i ] = $post->post_name;
                $changed        = true;
            }

            if ( $changed ) {
                $GLOBALS['wp']->request = implode( '/', $segments );
            }
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

            // A translated PAGE slug never arrives as 'pagename': with
            // verbose page rules WP::parse_request() only accepts the
            // page rule when get_page_by_path() finds the matched path,
            // which a translated slug never is. The request falls through
            // to the post rule ('name=...'), and swapping the value alone
            // then makes WP query post_type=post for a page -> 404
            // (/en/boat-rental-giethoorn/, task 3615). Hand it over as a
            // page request instead, with the page's full hierarchical path.
            if ( 'name' === $key && 'page' === $post->post_type ) {
                unset( $qv['name'] );
                $qv['pagename'] = function_exists( 'get_page_uri' ) ? (string) get_page_uri( $post ) : $post->post_name;
                if ( '' === $qv['pagename'] ) {
                    $qv['pagename'] = $post->post_name;
                }
                unset( $qv['error'] );
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

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the plugin's own table wp_stm_term_translations (no WordPress API for custom tables); single indexed lookup (term_id, language_code) per translated term on a non-default-language page; term translations are written outside this plugin, so there is no invalidation point for a cache
        $translation = $wpdb->get_row($wpdb->prepare(
            "SELECT name, slug, description FROM {$wpdb->prefix}stm_term_translations WHERE term_id = %d AND language_code = %s",
            $term->term_id,
            $current_lang
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
