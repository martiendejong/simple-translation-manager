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
        $queried = is_singular() ? get_queried_object() : null;
        $post    = ( $queried instanceof \WP_Post ) ? $queried : null;

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
            $url = ( $lang->code === $default )
                ? $current_url
                : self::language_url( $lang->code, $current_url, $post );

            echo '<link rel="alternate" hreflang="' . esc_attr( $lang->code ) . '" href="' . esc_url( $url ) . '">' . "\n";
        }

        // x-default always points to the default-language URL
        echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $current_url ) . '">' . "\n";
        echo "<!-- /STM hreflang -->\n\n";
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
