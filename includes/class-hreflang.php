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

        $default     = Settings::get_default_language();
        $current_url = self::canonical_url();

        echo "\n<!-- STM hreflang -->\n";

        foreach ( $languages as $lang ) {
            if ( $lang->code === $default ) {
                $url = $current_url;
            } else {
                $url = self::language_url( $lang->code, $current_url );
            }

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
     */
    private static function language_url( string $lang_code, string $base_url ): string {
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
