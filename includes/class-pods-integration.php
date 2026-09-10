<?php
/**
 * Pods Framework Integration
 *
 * Makes Pods custom fields translatable via Simple Translation Manager.
 *
 * How it works:
 * - Detects whether Pods is active on the current site.
 * - For each Pods Pod that has fields, registers those fields with STM's
 *   existing `stm_post_translations` storage — no new tables needed.
 * - Hooks into `pods_field_display` to return the translated value for the
 *   current language, falling back gracefully to the source value when no
 *   translation is stored yet.
 * - Adds a "Translations" meta box to every Pods-managed post type in WP admin
 *   so editors can enter field translations without leaving the edit screen.
 * - Exposes a template helper `stm_get_pods_translation()` for theme code.
 *
 * Activates automatically when Pods is loaded; no configuration needed.
 *
 * @package SimpleTranslationManager
 */

namespace STM;

class PodsIntegration {

    /**
     * Whether Pods is active.
     */
    private static bool $pods_active = false;

    /**
     * Cache of Pod field definitions: pod_name => [ field_name => field_type ]
     */
    private static array $pod_fields_cache = [];

    // ── Boot ──────────────────────────────────────────────────────────────────

    public static function init() {
        if ( ! self::is_pods_active() ) {
            return;
        }
        self::$pods_active = true;

        // Filter Pods field output on the frontend.
        add_filter( 'pods_field_display', [ __CLASS__, 'filter_field_display' ], 10, 3 );

        // Add translation meta box to Pods-managed post types in WP admin.
        add_action( 'add_meta_boxes', [ __CLASS__, 'register_meta_boxes' ] );

        // Save translations when the meta box form is submitted.
        add_action( 'save_post', [ __CLASS__, 'save_meta_box_translations' ], 20, 1 );

        // Register Pods fields with STM's string scanner so they appear in
        // the bulk translation UI.
        add_filter( 'stm_scannable_meta_keys', [ __CLASS__, 'add_pods_fields_to_scanner' ], 10, 2 );
    }

    // ── Pods detection ────────────────────────────────────────────────────────

    /**
     * Returns true when the Pods plugin is loaded and functional.
     */
    public static function is_pods_active(): bool {
        return function_exists( 'pods' ) && function_exists( 'pods_api' );
    }

    // ── Field display filter ──────────────────────────────────────────────────

    /**
     * Intercept `pods_field_display` to return a translated value when one
     * exists for the current STM language.
     *
     * @param  mixed  $value      Original field value produced by Pods.
     * @param  string $field_name The Pods field name.
     * @param  mixed  $pod        The Pods object or pod name string.
     * @return mixed  Translated value, or the original if no translation stored.
     */
    public static function filter_field_display( $value, string $field_name, $pod ) {
        // Only translate scalar (string-like) values — skip arrays, objects, images.
        if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
            return $value;
        }

        $lang = Settings::get_current_language();
        if ( ! $lang || $lang === Settings::get_default_language() ) {
            return $value;
        }

        // Resolve the post ID from the $pod object if possible.
        $post_id = self::resolve_post_id( $pod );
        if ( ! $post_id ) {
            return $value;
        }

        $translated = Database::get_translation( $post_id, $field_name, $lang );
        return ( $translated !== null && $translated !== '' ) ? $translated : $value;
    }

    /**
     * Resolve a post ID from whatever `pods_field_display` passes as $pod.
     * Pods passes a Pods object, an array, or sometimes a post ID int.
     */
    private static function resolve_post_id( $pod ): int {
        if ( is_int( $pod ) || ctype_digit( (string) $pod ) ) {
            return (int) $pod;
        }
        if ( is_object( $pod ) && isset( $pod->id ) ) {
            return (int) $pod->id;
        }
        if ( is_array( $pod ) && isset( $pod['id'] ) ) {
            return (int) $pod['id'];
        }
        // Fall back to the current global post.
        $global_post = get_post();
        return $global_post ? (int) $global_post->ID : 0;
    }

    // ── Admin meta box ─────────────────────────────────────────────────────────

    /**
     * Register an STM translations meta box for every Pods-managed post type.
     */
    public static function register_meta_boxes() {
        $pod_post_types = self::get_pods_post_types();
        foreach ( $pod_post_types as $post_type => $pod_name ) {
            add_meta_box(
                'stm_pods_translations',
                __( 'STM: Pods Field Translations', 'simple-translation-manager' ),
                [ __CLASS__, 'render_meta_box' ],
                $post_type,
                'normal',
                'default',
                [ 'pod_name' => $pod_name ]
            );
        }
    }

    /**
     * Render the meta box HTML — one text input per Pods field per active language.
     *
     * @param \WP_Post $post
     * @param array    $meta_box_args  Contains 'args' => [ 'pod_name' => string ]
     */
    public static function render_meta_box( \WP_Post $post, array $meta_box_args ) {
        $pod_name   = $meta_box_args['args']['pod_name'] ?? '';
        $fields     = self::get_pod_translatable_fields( $pod_name );
        $languages  = Settings::get_active_languages();
        $default    = Settings::get_default_language();

        if ( empty( $fields ) || empty( $languages ) ) {
            echo '<p>' . esc_html__( 'No translatable Pods fields found.', 'simple-translation-manager' ) . '</p>';
            return;
        }

        wp_nonce_field( 'stm_pods_save_' . $post->ID, 'stm_pods_nonce' );

        echo '<table class="widefat stm-pods-translations" style="border-collapse:collapse;">';
        echo '<thead><tr><th>' . esc_html__( 'Field', 'simple-translation-manager' ) . '</th>';
        foreach ( $languages as $lang_code => $lang_label ) {
            if ( $lang_code === $default ) {
                continue;
            }
            echo '<th>' . esc_html( $lang_label ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( $fields as $field_name => $field_label ) {
            echo '<tr>';
            echo '<td><strong>' . esc_html( $field_label ?: $field_name ) . '</strong></td>';
            foreach ( $languages as $lang_code => $lang_label ) {
                if ( $lang_code === $default ) {
                    continue;
                }
                $existing   = Database::get_translation( $post->ID, $field_name, $lang_code ) ?? '';
                $input_name = 'stm_pods[' . esc_attr( $lang_code ) . '][' . esc_attr( $field_name ) . ']';
                echo '<td>';
                echo '<textarea name="' . esc_attr( $input_name ) . '" rows="2" style="width:100%;">';
                echo esc_textarea( $existing );
                echo '</textarea>';
                echo '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Save translations submitted via the meta box.
     *
     * @param int $post_id
     */
    public static function save_meta_box_translations( int $post_id ) {
        if ( ! isset( $_POST['stm_pods_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_key( $_POST['stm_pods_nonce'] ), 'stm_pods_save_' . $post_id ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        if ( ! isset( $_POST['stm_pods'] ) || ! is_array( $_POST['stm_pods'] ) ) {
            return;
        }

        $default = Settings::get_default_language();
        foreach ( $_POST['stm_pods'] as $lang_code => $fields ) { // phpcs:ignore WordPress.Security.NonceVerification
            $lang_code = sanitize_key( $lang_code );
            if ( $lang_code === $default || ! is_array( $fields ) ) {
                continue;
            }
            foreach ( $fields as $field_name => $value ) {
                $field_name = sanitize_key( $field_name );
                $value      = sanitize_textarea_field( $value );
                if ( $value !== '' ) {
                    Database::set_translation( $post_id, $field_name, $lang_code, $value );
                } else {
                    Database::delete_translation( $post_id, $field_name, $lang_code );
                }
            }
        }
    }

    // ── String scanner integration ─────────────────────────────────────────────

    /**
     * Add Pods fields to STM's meta-key scan list so they appear in the
     * bulk translation UI alongside regular post meta.
     *
     * @param  string[] $keys      Existing scannable meta keys.
     * @param  int      $post_id   The post being scanned.
     * @return string[]
     */
    public static function add_pods_fields_to_scanner( array $keys, int $post_id ): array {
        $post_type = get_post_type( $post_id );
        if ( ! $post_type ) {
            return $keys;
        }
        $pod_name = self::get_pod_name_for_post_type( $post_type );
        if ( ! $pod_name ) {
            return $keys;
        }
        $pod_fields = self::get_pod_translatable_fields( $pod_name );
        return array_unique( array_merge( $keys, array_keys( $pod_fields ) ) );
    }

    // ── Pods API helpers ───────────────────────────────────────────────────────

    /**
     * Return a map of [ post_type => pod_name ] for all Pods that wrap
     * existing WP post types (type = 'post_type').
     *
     * @return array<string,string>
     */
    private static function get_pods_post_types(): array {
        static $cache = null;
        if ( $cache !== null ) {
            return $cache;
        }
        $cache = [];
        try {
            $api  = pods_api();
            $pods = $api->load_pods( [ 'type' => 'post_type' ] );
            if ( is_array( $pods ) ) {
                foreach ( $pods as $pod ) {
                    $name      = is_array( $pod ) ? ( $pod['name'] ?? '' ) : ( $pod->name ?? '' );
                    $post_type = is_array( $pod ) ? ( $pod['object'] ?: $name ) : ( $pod->object ?: $name );
                    if ( $name && $post_type ) {
                        $cache[ $post_type ] = $name;
                    }
                }
            }
        } catch ( \Throwable $e ) {
            // Pods not fully initialised yet — return empty gracefully.
        }
        return $cache;
    }

    /**
     * Return the pod name that manages $post_type, or empty string.
     */
    private static function get_pod_name_for_post_type( string $post_type ): string {
        $map = self::get_pods_post_types();
        return $map[ $post_type ] ?? '';
    }

    /**
     * Return all translatable text fields for a given pod.
     *
     * "Translatable" = Pods field types that hold human-readable string values:
     * text, textarea, wysiwyg, slug, url, email, phone, boolean (label only).
     *
     * @return array<string,string>  field_name => field_label
     */
    private static function get_pod_translatable_fields( string $pod_name ): array {
        if ( isset( self::$pod_fields_cache[ $pod_name ] ) ) {
            return self::$pod_fields_cache[ $pod_name ];
        }

        $translatable_types = [
            'text', 'textarea', 'wysiwyg', 'slug', 'url', 'email', 'phone', 'paragraph',
        ];

        $result = [];
        try {
            $api    = pods_api();
            $pod    = $api->load_pod( [ 'name' => $pod_name ] );
            $fields = is_array( $pod ) ? ( $pod['fields'] ?? [] ) : ( $pod->fields ?? [] );
            foreach ( $fields as $field ) {
                $type  = is_array( $field ) ? ( $field['type'] ?? '' ) : ( $field->type ?? '' );
                $name  = is_array( $field ) ? ( $field['name'] ?? '' ) : ( $field->name ?? '' );
                $label = is_array( $field ) ? ( $field['label'] ?? $name ) : ( $field->label ?? $name );
                if ( $name && in_array( $type, $translatable_types, true ) ) {
                    $result[ $name ] = $label;
                }
            }
        } catch ( \Throwable $e ) {
            // Pods API unavailable — return empty.
        }

        self::$pod_fields_cache[ $pod_name ] = $result;
        return $result;
    }
}

// ── Template helper ────────────────────────────────────────────────────────────

if ( ! function_exists( 'stm_get_pods_translation' ) ) {
    /**
     * Get the translated value of a Pods custom field for the current language.
     *
     * Falls back to the raw Pods field value when no translation is stored.
     *
     * Usage in themes:
     *   echo stm_get_pods_translation( 'mypod', 'description', $post->ID );
     *
     * @param  string     $pod_name   The Pods pod name.
     * @param  string     $field_name The Pods field name / meta key.
     * @param  int|null   $post_id    Post ID; defaults to current global post.
     * @param  string|null $lang      Language code; defaults to current STM language.
     * @return string
     */
    function stm_get_pods_translation( string $pod_name, string $field_name, ?int $post_id = null, ?string $lang = null ): string {
        if ( ! class_exists( 'STM\PodsIntegration' ) || ! PodsIntegration::is_pods_active() ) {
            // Pods not active — return raw pod field value if possible.
            if ( function_exists( 'pods' ) && $post_id ) {
                $pod = pods( $pod_name, $post_id );
                return (string) ( $pod ? $pod->field( $field_name ) : '' );
            }
            return '';
        }

        if ( ! $post_id ) {
            $global_post = get_post();
            $post_id     = $global_post ? $global_post->ID : 0;
        }
        if ( ! $post_id ) {
            return '';
        }

        $lang = $lang ?? STM\Settings::get_current_language();
        $default = STM\Settings::get_default_language();

        if ( $lang && $lang !== $default ) {
            $translated = STM\Database::get_translation( $post_id, $field_name, $lang );
            if ( $translated !== null && $translated !== '' ) {
                return $translated;
            }
        }

        // Fall back to the raw Pods value.
        $pod = pods( $pod_name, $post_id );
        return (string) ( $pod ? $pod->field( $field_name ) : '' );
    }
}
