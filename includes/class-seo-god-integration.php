<?php
/**
 * SEO God Integration
 *
 * Hooks into SEO God plugin filters so STM-translated content is used
 * for meta tags, schema markup, and multilingual provider detection.
 *
 * Activates automatically when SEO God is loaded; no configuration needed.
 *
 * @package SimpleTranslationManager
 */

namespace STM;

class SeoGodIntegration {

    public static function init() {
        if ( ! self::seo_god_active() ) {
            return;
        }

        // Meta title / description
        add_filter( 'seo_god_meta_title',       [ __CLASS__, 'translated_title' ],       10, 2 );
        add_filter( 'seo_god_meta_description', [ __CLASS__, 'translated_description' ], 10, 2 );

        // Schema.org headline / description (SEO God 1.2+)
        add_filter( 'seo_god_schema_headline',    [ __CLASS__, 'translated_title' ],       10, 2 );
        add_filter( 'seo_god_schema_description', [ __CLASS__, 'translated_description' ], 10, 2 );

        // Register STM in SEO God's multilingual detection
        add_filter( 'seo_god_detect_multilingual', [ __CLASS__, 'declare_stm' ] );
        add_filter( 'seo_god_active_languages',    [ __CLASS__, 'provide_languages' ] );
        add_filter( 'seo_god_current_language',    [ __CLASS__, 'provide_current_language' ], 10, 2 );
    }

    // -------------------------------------------------------------------------
    // Filter callbacks
    // -------------------------------------------------------------------------

    public static function translated_title( string $title, $post_id = null ): string {
        $lang = self::current_lang();
        if ( $lang === Settings::get_default_language() ) {
            return $title;
        }

        $post_id = $post_id ?: ( is_singular() ? get_the_ID() : null );
        if ( ! $post_id ) {
            return $title;
        }

        return stm_get_post_translation( $post_id, 'title', $lang, $title );
    }

    public static function translated_description( string $desc, $post_id = null ): string {
        $lang = self::current_lang();
        if ( $lang === Settings::get_default_language() ) {
            return $desc;
        }

        $post_id = $post_id ?: ( is_singular() ? get_the_ID() : null );
        if ( ! $post_id ) {
            return $desc;
        }

        // Try excerpt first, then first 160 chars of translated content
        $translated = stm_get_post_translation( $post_id, 'excerpt', $lang, '' );
        if ( ! $translated ) {
            $content    = stm_get_post_translation( $post_id, 'content', $lang, '' );
            $translated = $content ? mb_substr( wp_strip_all_tags( $content ), 0, 160 ) : '';
        }

        return $translated ?: $desc;
    }

    public static function declare_stm( string $detected ): string {
        // Only override if no other plugin was detected
        return $detected === 'none' ? 'stm' : $detected;
    }

    public static function provide_languages( array $existing ): array {
        // If another plugin already provided languages, respect that
        if ( ! empty( $existing ) ) {
            return $existing;
        }

        $default = Settings::get_default_language();

        return array_map(
            function ( $lang ) use ( $default ) {
                return [
                    'code'     => $lang->code,
                    'name'     => $lang->name,
                    'default'  => ( $lang->code === $default ),
                    'flag_url' => '',
                ];
            },
            Database::get_languages()
        );
    }

    public static function provide_current_language( string $current, string $plugin = '' ): string {
        // Only respond when SEO God has identified STM as the active provider
        if ( $plugin !== 'stm' ) {
            return $current;
        }

        $lang = self::current_lang();
        if ( $lang === Settings::get_default_language() ) {
            // Preserve the pre-hook fallback (e.g. get_locale()) on default-language pages
            return $current;
        }

        return $lang;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function current_lang(): string {
        return Frontend::get_current_language();
    }

    private static function seo_god_active(): bool {
        return defined( 'SEO_GOD_VERSION' ) || function_exists( 'seo_god_detect_multilingual' );
    }
}
