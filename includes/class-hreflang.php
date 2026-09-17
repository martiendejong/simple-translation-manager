<?php
/**
 * Hreflang Tag Injector
 *
 * Injects <link rel="alternate" hreflang="..."> tags so Google knows
 * which language version of a page to show for each locale.
 *
 * @package SimpleTranslationManager
 */

namespace STM;

class Hreflang {

    public static function init() {
        add_action( 'wp_head', [ __CLASS__, 'inject' ], 3 );
    }

    /**
     * Output hreflang link tags for every active language.
     */
    public static function inject() {
        if ( ! Settings::is_url_routing_enabled() ) {
            return;
        }

        $languages = Database::get_languages();
        if ( empty( $languages ) ) {
            return;
        }

        $default = Settings::get_default_language();
        $queried = null;
        if ( is_singular() ) {
            $queried = get_queried_object();
        } elseif ( is_tax() || is_category() ) {
            // Category/subcategory archives (e.g. the bcc_category taxonomy)
            // query a WP_Term, not a WP_Post — resolving it here lets
            // has_translated_content() below check STM's own term
            // translations table instead of unconditionally treating every
            // archive as untranslatable (task 3346).
            $queried = get_queried_object();
        }
        $post = ( $queried instanceof \WP_Post ) ? $queried : null;
        $term = ( $queried instanceof \WP_Term ) ? $queried : null;

        // A post's OWN language (its real STM association, or SEO God's
        // detected content language, or the site default as last resort —
        // see PostEditor::get_post_language()) is what "self-references"
        // this URL, not necessarily the site default. Without this, a
        // genuinely Dutch post that was never manually registered through
        // STM's editor UI would self-declare hreflang="en" instead of
        // hreflang="nl" (task 3047). Non-singular requests (archives, the
        // front page) have no post to detect a language for, so they keep
        // asserting the site default, same as before.
        $self_lang = $post ? PostEditor::get_post_language( $post->ID ) : $default;

        // For singular content, build every language's URL from the post's
        // own default-language permalink (STM's substitution suppressed) so
        // each language's link can be resolved via Frontend::localize_permalink()
        // — the same lookup filter_permalink() uses for outbound links, and
        // resolve_translated_slug_request() uses in reverse for incoming
        // requests. Without this, a visit via an already-translated-slug URL
        // would leak that slug into every other language's hreflang tag.
        $current_url = $post ? Frontend::get_base_permalink( $post ) : self::canonical_url();
        if ( ! $current_url ) {
            return;
        }

        echo "\n<!-- STM hreflang -->\n";

        foreach ( $languages as $lang ) {
            if ( $lang->code === $self_lang ) {
                $url = $current_url;
            } else {
                // Only advertise a language version that actually has
                // translated content behind it — a hreflang tag whose target
                // just falls back to the default-language page (or, for
                // non-singular content with no post to translate against,
                // has no rendering path at all) is a fake localization
                // signal: it tells crawlers/AI engines a version exists that
                // doesn't, which erodes trust in the whole hreflang cluster
                // (task 958).
                if ( ! self::has_translated_content( $post, $term, $lang->code ) ) {
                    continue;
                }
                $url = self::language_url( $lang->code, $current_url, $post );
            }

            echo '<link rel="alternate" hreflang="' . esc_attr( $lang->code ) . '" href="' . esc_url( $url ) . '">' . "\n";
        }

        // x-default always points to this page's own (self-language) URL
        echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $current_url ) . '">' . "\n";
        echo "<!-- /STM hreflang -->\n\n";
    }

    /**
     * Whether real content exists for $lang_code: either the post is
     * natively written in that language, a saved translation of it exists,
     * or — for a category/subcategory archive — a registered term
     * translation exists in wp_stm_term_translations (task 3346). A
     * post-type archive (e.g. /types/) queries neither a WP_Post nor a
     * WP_Term, so it still has nothing to check translation existence
     * against and keeps asserting only the default language, same as before.
     */
    private static function has_translated_content( $post, $term, string $lang_code ): bool {
        if ( $post instanceof \WP_Post ) {
            if ( PostEditor::get_post_language( $post->ID ) === $lang_code ) {
                return true;
            }

            $translation = PostEditor::get_post_translation( $post->ID, $lang_code );

            return ! empty( $translation['post_title'] ) || ! empty( $translation['post_content'] );
        }

        if ( $term instanceof \WP_Term ) {
            return self::has_term_translation( $term->term_id, $lang_code );
        }

        return false;
    }

    /**
     * Whether a registered translation row exists for $term_id in
     * $lang_code — the same wp_stm_term_translations lookup
     * Frontend::filter_term() already uses to swap a term's displayed
     * name/slug/description for the current request's language.
     */
    private static function has_term_translation( int $term_id, string $lang_code ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'stm_term_translations';

        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT term_id FROM {$table} WHERE term_id = %d AND language_code = %s LIMIT 1",
            $term_id,
            $lang_code
        ) );

        return ! empty( $found );
    }

    /**
     * Build the language-prefixed URL for a non-default language.
     *
     * /topic/valsuani/  →  /fr/topic/valsuani/
     *
     * When $post is known, this reuses Frontend::localize_permalink() so
     * a translated slug set for $lang_code is substituted — otherwise it
     * falls back to a plain prefix of the current path (non-singular pages
     * have no post-specific slug to translate).
     */
    private static function language_url( string $lang_code, string $base_url, $post = null ): string {
        if ( $post instanceof \WP_Post ) {
            $localized = Frontend::localize_permalink( $base_url, $post, $lang_code );
            if ( $localized ) {
                return $localized;
            }
        }

        $home  = trailingslashit( home_url() );
        $path  = str_replace( $home, '', trailingslashit( $base_url ) );
        $path  = ltrim( $path, '/' );

        return $home . $lang_code . '/' . $path;
    }

    /**
     * Return the canonical (default-language) URL for the current request —
     * strips any language prefix and the ?lang= param.
     */
    private static function canonical_url(): string {
        $url = home_url( add_query_arg( [] ) );

        // Remove ?lang= query param
        $url = remove_query_arg( 'lang', $url );

        // Strip /{lang}/ prefix from path
        $parsed = wp_parse_url( $url );
        if ( ! empty( $parsed['path'] ) ) {
            $clean_path = preg_replace( '#^/([a-z]{2,3})/#', '/', $parsed['path'] );
            $url  = $parsed['scheme'] . '://' . $parsed['host'];
            $url .= $clean_path;
            if ( ! empty( $parsed['query'] ) ) {
                $url .= '?' . $parsed['query'];
            }
        }

        return $url;
    }
}
