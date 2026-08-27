<?php
/**
 * Per-language XML sitemap provider.
 *
 * The core WP sitemap only lists default-language URLs. This provider adds
 * a sitemap per active non-default language with the language-prefixed
 * URL of every public, published post — so every language has its own
 * indexable SEO structure (SEO-first requirement, Living Village-manifest 24-07).
 *
 * URLs: /wp-sitemap-stmlang-{code}-{page}.xml, discoverable via the
 * regular /wp-sitemap.xml index. NB: the provider name must be lowercase
 * letters only — WP core's sitemap rewrite rule matches the provider
 * segment with ([a-z]+?), so an underscore would 404 every sitemap page.
 *
 * @package SimpleTranslationManager
 */

namespace STM;

defined( 'ABSPATH' ) || exit;

class Sitemap {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_provider' ], 20 );
    }

    public static function register_provider() {
        if ( ! Settings::is_url_routing_enabled() || ! function_exists( 'wp_register_sitemap_provider' ) ) {
            return;
        }
        wp_register_sitemap_provider( 'stmlang', new Sitemap_Provider() );
    }
}

class Sitemap_Provider extends \WP_Sitemaps_Provider {

    public function __construct() {
        $this->name        = 'stmlang';
        $this->object_type = 'post';
    }

    /**
     * Active non-default language codes = the sitemap subtypes.
     *
     * @return string[]
     */
    private function language_codes(): array {
        $default = Settings::get_default_language();
        $codes   = [];
        foreach ( Database::get_languages() as $lang ) {
            if ( $lang->code !== $default && ! empty( $lang->is_active ) ) {
                $codes[] = $lang->code;
            }
        }
        return $codes;
    }

    /**
     * Public post types that appear in sitemaps.
     *
     * @return string[]
     */
    private function post_types(): array {
        $types = get_post_types( [ 'public' => true ], 'names' );
        unset( $types['attachment'] );
        return array_values( $types );
    }

    public function get_object_subtypes() {
        $subtypes = [];
        foreach ( $this->language_codes() as $code ) {
            $subtypes[ $code ] = (object) [ 'name' => $code ];
        }
        return $subtypes;
    }

    public function get_url_list( $page_num, $object_subtype = '' ) {
        if ( ! in_array( $object_subtype, $this->language_codes(), true ) ) {
            return [];
        }

        $query = new \WP_Query( [
            'post_type'              => $this->post_types(),
            'post_status'            => 'publish',
            'posts_per_page'         => wp_sitemaps_get_max_urls( $this->object_type ),
            'paged'                  => max( 1, (int) $page_num ),
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ] );

        $urls = [];

        // Build every URL from the post's default-language permalink (STM's
        // own current-request substitution suppressed) via the same
        // Frontend::localize_permalink() lookup filter_permalink() and
        // Hreflang use — so a translated slug set for $object_subtype is
        // picked up here too, instead of listing the original slug under
        // the language prefix.
        foreach ( $query->posts as $post ) {
            $base_permalink = Frontend::get_base_permalink( $post );
            if ( ! $base_permalink ) {
                continue;
            }

            $loc = Frontend::localize_permalink( $base_permalink, $post, $object_subtype );
            if ( $loc ) {
                $urls[] = [ 'loc' => $loc ];
            }
        }

        return $urls;
    }

    public function get_max_num_pages( $object_subtype = '' ) {
        if ( ! in_array( $object_subtype, $this->language_codes(), true ) ) {
            return 0;
        }

        // Zelfde aantal voor elk subtype; wp_count_posts() is object-cached,
        // dus geen COUNT-query per taal bij elke sitemap-index-request.
        static $total = null;
        if ( null === $total ) {
            $total = 0;
            foreach ( $this->post_types() as $type ) {
                $counts = wp_count_posts( $type );
                $total += isset( $counts->publish ) ? (int) $counts->publish : 0;
            }
        }

        return (int) ceil( $total / wp_sitemaps_get_max_urls( $this->object_type ) );
    }
}
