<?php
/**
 * Pods Integration
 *
 * Makes custom fields defined by the Pods framework translatable, the same
 * way this plugin already handles Elementor widget content and core post
 * fields:
 *
 * - Translations are stored per post/field/language in the plugin's existing
 *   `stm_post_translations` table (field_name = the Pods field's own name),
 *   reusing the same table/cache layer post title/content translations use.
 * - A dedicated "Pods Field Translations" meta box lists every plain-text
 *   Pods field for the current post's pod, with one tab per non-default
 *   language — mirroring the structure of the core Translations meta box.
 * - Relationship-shaped field types ('pick', 'file', 'avatar', 'taxonomy')
 *   are never offered for direct-text translation: the item they point at
 *   carries its own translation (if any), and overwriting the reference
 *   itself with translated text would silently break the relationship. They
 *   still round-trip untouched through stm_get_pods_translation().
 *
 * Activates automatically when Pods is loaded; no configuration needed.
 *
 * @package SimpleTranslationManager
 */

namespace STM;

class PodsIntegration {

    /**
     * Pods field types treated as plain translatable text content.
     */
    const TRANSLATABLE_FIELD_TYPES = [
        'text', 'website', 'phone', 'email', 'password', 'slug',
        'paragraph', 'wysiwyg', 'html', 'code', 'oembed', 'color',
    ];

    /**
     * Pods field types that reference other items (relationships/media)
     * rather than holding translatable text of their own.
     */
    const RELATIONSHIP_FIELD_TYPES = [ 'pick', 'file', 'avatar', 'taxonomy' ];

    public static function init() {
        if ( ! self::is_pods_active() ) {
            return;
        }

        add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );
        add_action( 'save_post', [ __CLASS__, 'save_translations' ], 10, 2 );
    }

    /**
     * Detect whether the Pods framework is active on this site.
     */
    public static function is_pods_active(): bool {
        return function_exists( 'pods_api' );
    }

    // -------------------------------------------------------------------------
    // Pod / field discovery
    // -------------------------------------------------------------------------

    /**
     * Resolve the Pods pod name backing a WordPress post type, or null when
     * that post type isn't managed by Pods (or Pods isn't active).
     */
    public static function get_pod_name_for_post_type( string $post_type ) {
        if ( ! self::is_pods_active() ) {
            return null;
        }

        $pod = pods_api()->load_pod( [ 'name' => $post_type ] );

        return ( is_array( $pod ) && ! empty( $pod['name'] ) ) ? $pod['name'] : null;
    }

    /**
     * Translatable fields for a pod: `{ field_name: { label, type } }`.
     * Only plain-text field types are included — see TRANSLATABLE_FIELD_TYPES.
     */
    public static function get_translatable_fields( string $pod_name ): array {
        if ( ! self::is_pods_active() ) {
            return [];
        }

        $pod = pods_api()->load_pod( [ 'name' => $pod_name ] );
        if ( empty( $pod['fields'] ) || ! is_array( $pod['fields'] ) ) {
            return [];
        }

        $fields = [];
        foreach ( $pod['fields'] as $field_name => $field ) {
            $type = is_array( $field ) ? ( $field['type'] ?? '' ) : '';
            if ( in_array( $type, self::TRANSLATABLE_FIELD_TYPES, true ) ) {
                $fields[ $field_name ] = [
                    'label' => $field['label'] ?? $field_name,
                    'type'  => $type,
                ];
            }
        }

        return $fields;
    }

    public static function is_relationship_field_type( string $type ): bool {
        return in_array( $type, self::RELATIONSHIP_FIELD_TYPES, true );
    }

    // -------------------------------------------------------------------------
    // Meta box
    // -------------------------------------------------------------------------

    public static function add_meta_box() {
        $post_types = get_post_types( [ 'public' => true ], 'names' );

        foreach ( $post_types as $post_type ) {
            $pod_name = self::get_pod_name_for_post_type( $post_type );
            if ( ! $pod_name || empty( self::get_translatable_fields( $pod_name ) ) ) {
                continue;
            }

            add_meta_box(
                'stm_pods_translations',
                __( 'Pods Field Translations', 'simple-translation-manager' ),
                [ __CLASS__, 'render_meta_box' ],
                $post_type,
                'normal',
                'default'
            );
        }
    }

    public static function render_meta_box( $post ) {
        $pod_name = self::get_pod_name_for_post_type( $post->post_type );
        if ( ! $pod_name ) {
            return;
        }

        $fields = self::get_translatable_fields( $pod_name );
        if ( empty( $fields ) ) {
            return;
        }

        wp_nonce_field( 'stm_save_pods_translations', 'stm_pods_translations_nonce' );

        // All languages, active or not — same convention as the core
        // Translations meta box (PostEditor::render_meta_box).
        $languages    = Database::get_all_languages();
        $current_lang = PostEditor::get_post_language( $post->ID );

        $translations = [];
        foreach ( $languages as $lang ) {
            if ( $lang->code === $current_lang ) {
                continue;
            }
            $translations[ $lang->code ] = self::get_post_pods_translations( $post->ID, $lang->code, array_keys( $fields ) );
        }

        include STM_PLUGIN_DIR . 'templates/meta-box-pods-translations.php';
    }

    /**
     * Saved translations for a post/language, limited to the given Pods
     * field names — reuses PostEditor's existing per-post/language lookup
     * (all field_name rows for that post/language) and filters it down,
     * rather than duplicating the query.
     */
    public static function get_post_pods_translations( int $post_id, string $lang_code, array $field_names ): array {
        $all = PostEditor::get_post_translation( $post_id, $lang_code );

        return array_intersect_key( $all, array_flip( $field_names ) );
    }

    /**
     * Save translated Pods field values submitted by the meta box.
     */
    public static function save_translations( $post_id, $post ) {
        if ( ! isset( $_POST['stm_pods_translations_nonce'] )
            || ! wp_verify_nonce( wp_unslash( $_POST['stm_pods_translations_nonce'] ), 'stm_save_pods_translations' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( ! isset( $_POST['stm_pods_translations'] ) || ! is_array( $_POST['stm_pods_translations'] ) ) {
            return;
        }

        $pod_name = self::get_pod_name_for_post_type( $post->post_type );
        if ( ! $pod_name ) {
            return;
        }

        $translatable_fields = self::get_translatable_fields( $pod_name );
        if ( empty( $translatable_fields ) ) {
            return;
        }

        global $wpdb;
        $table  = $wpdb->prefix . 'stm_post_translations';
        $posted = wp_unslash( $_POST['stm_pods_translations'] );

        foreach ( $posted as $lang_code => $fields ) {
            if ( ! Security::validate_language_code( $lang_code ) || ! is_array( $fields ) ) {
                continue;
            }

            foreach ( $fields as $field_name => $value ) {
                $field_name = sanitize_text_field( (string) $field_name );

                // Only ever write fields this pod actually declares as
                // translatable — never trust the posted field name list on
                // its own, or a crafted form could write arbitrary rows.
                if ( ! isset( $translatable_fields[ $field_name ] ) ) {
                    continue;
                }

                $value = Security::sanitize_translation( (string) $value );

                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE post_id = %d AND field_name = %s AND language_code = %s",
                    $post_id,
                    $field_name,
                    $lang_code
                ) );

                if ( '' === $value ) {
                    if ( $existing ) {
                        $wpdb->delete( $table, [ 'id' => $existing ] );
                    }
                    continue;
                }

                $data = [
                    'post_id'       => $post_id,
                    'field_name'    => $field_name,
                    'language_code' => $lang_code,
                    'translation'   => $value,
                ];

                if ( $existing ) {
                    $wpdb->update( $table, $data, [ 'id' => $existing ] );
                } else {
                    $wpdb->insert( $table, $data );
                }
            }
        }

        Cache::invalidate_post( $post_id );
    }
}
