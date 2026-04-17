<?php
/**
 * Language Switcher
 *
 * Widget, shortcode, template function, and auto-inject for language switching.
 * Style and position are configurable site-wide via Settings, and per-instance
 * via widget/shortcode attributes.
 *
 * @package SimpleTranslationManager
 */

namespace STM;

class LanguageSwitcher extends \WP_Widget {

    public static function init() {
        add_action('widgets_init', function() {
            register_widget('STM\LanguageSwitcher');
        });

        add_shortcode('stm_language_switcher', [__CLASS__, 'shortcode']);

        // Enqueue frontend CSS on every page load
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_styles']);

        // Auto-inject into post content when position is configured
        add_filter('the_content', [__CLASS__, 'auto_inject'], 20);
    }

    // -------------------------------------------------------------------------
    // Widget
    // -------------------------------------------------------------------------

    public function __construct() {
        parent::__construct(
            'stm_language_switcher',
            'Language Switcher',
            ['description' => 'Display language switcher for multilingual content']
        );
    }

    public function widget($args, $instance) {
        echo $args['before_widget'];

        if (!empty($instance['title'])) {
            echo $args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title'];
        }

        self::render([
            'style'      => $instance['style']      ?? Settings::get_switcher_style(),
            'show_flags' => isset($instance['show_flags']) ? (bool) $instance['show_flags'] : Settings::switcher_show_flags(),
            'show_names' => isset($instance['show_names']) ? (bool) $instance['show_names'] : Settings::switcher_show_names(),
        ]);

        echo $args['after_widget'];
    }

    public function form($instance) {
        $title      = $instance['title']      ?? '';
        $style      = $instance['style']      ?? Settings::get_switcher_style();
        $show_flags = isset($instance['show_flags']) ? (bool) $instance['show_flags'] : Settings::switcher_show_flags();
        $show_names = isset($instance['show_names']) ? (bool) $instance['show_names'] : Settings::switcher_show_names();
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">Title:</label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text"
                   value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('style')); ?>">Style:</label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('style')); ?>"
                    name="<?php echo esc_attr($this->get_field_name('style')); ?>">
                <option value="list"     <?php selected($style, 'list');     ?>>List</option>
                <option value="dropdown" <?php selected($style, 'dropdown'); ?>>Dropdown</option>
                <option value="buttons"  <?php selected($style, 'buttons');  ?>>Buttons</option>
                <option value="flags"    <?php selected($style, 'flags');    ?>>Flags only</option>
            </select>
        </p>
        <p>
            <label>
                <input type="checkbox" name="<?php echo esc_attr($this->get_field_name('show_flags')); ?>"
                       value="1" <?php checked($show_flags); ?>>
                Show flag emoji
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="<?php echo esc_attr($this->get_field_name('show_names')); ?>"
                       value="1" <?php checked($show_names); ?>>
                Show language name
            </label>
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        return [
            'title'      => sanitize_text_field($new_instance['title'] ?? ''),
            'style'      => sanitize_text_field($new_instance['style'] ?? 'list'),
            'show_flags' => !empty($new_instance['show_flags']),
            'show_names' => !empty($new_instance['show_names']),
        ];
    }

    // -------------------------------------------------------------------------
    // Shortcode
    // -------------------------------------------------------------------------

    public static function shortcode($atts) {
        $atts = shortcode_atts([
            'style'      => Settings::get_switcher_style(),
            'show_flags' => Settings::switcher_show_flags() ? '1' : '0',
            'show_names' => Settings::switcher_show_names() ? '1' : '0',
        ], $atts);

        ob_start();
        self::render([
            'style'      => $atts['style'],
            'show_flags' => (bool) $atts['show_flags'],
            'show_names' => (bool) $atts['show_names'],
        ]);
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Auto-inject
    // -------------------------------------------------------------------------

    public static function auto_inject($content) {
        if (!is_singular()) {
            return $content;
        }

        $position = Settings::get_switcher_position();
        if ($position === 'none') {
            return $content;
        }

        ob_start();
        self::render();
        $switcher = '<div class="stm-auto-inject">' . ob_get_clean() . '</div>';

        if ($position === 'before_content') {
            return $switcher . $content;
        }
        if ($position === 'after_content') {
            return $content . $switcher;
        }
        // both
        return $switcher . $content . $switcher;
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    /**
     * Render the switcher.
     *
     * @param array $args {
     *   style:      'list'|'dropdown'|'buttons'|'flags'
     *   show_flags: bool
     *   show_names: bool
     * }
     */
    public static function render($args = []) {
        $style      = $args['style']      ?? Settings::get_switcher_style();
        $show_flags = $args['show_flags'] ?? Settings::switcher_show_flags();
        $show_names = $args['show_names'] ?? Settings::switcher_show_names();

        $languages   = Database::get_languages();
        $current     = Frontend::get_current_language();
        $current_url = self::get_current_url();

        if (count($languages) <= 1) {
            return;
        }

        switch ($style) {
            case 'dropdown':
                self::render_dropdown($languages, $current, $current_url, $show_flags, $show_names);
                break;
            case 'buttons':
                self::render_buttons($languages, $current, $current_url, $show_flags, $show_names);
                break;
            case 'flags':
                self::render_flags($languages, $current, $current_url);
                break;
            default:
                self::render_list($languages, $current, $current_url, $show_flags, $show_names);
                break;
        }
    }

    private static function render_list($languages, $current, $current_url, $show_flags, $show_names) {
        echo '<ul class="stm-language-switcher stm-style-list">';
        foreach ($languages as $lang) {
            $url   = self::get_language_url($lang->code, $current_url);
            $label = self::label($lang, $show_flags, $show_names);
            printf(
                '<li class="%s"><a href="%s">%s</a></li>',
                $lang->code === $current ? 'current' : '',
                esc_url($url),
                $label
            );
        }
        echo '</ul>';
    }

    private static function render_dropdown($languages, $current, $current_url, $show_flags, $show_names) {
        echo '<div class="stm-language-switcher stm-style-dropdown">';
        echo '<select onchange="window.location.href=this.value">';
        foreach ($languages as $lang) {
            $url   = self::get_language_url($lang->code, $current_url);
            $label = self::label($lang, $show_flags, $show_names);
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($url),
                $lang->code === $current ? ' selected' : '',
                esc_html(html_entity_decode($label, ENT_QUOTES | ENT_HTML5))
            );
        }
        echo '</select>';
        echo '</div>';
    }

    private static function render_buttons($languages, $current, $current_url, $show_flags, $show_names) {
        echo '<div class="stm-language-switcher stm-style-buttons">';
        foreach ($languages as $lang) {
            $url   = self::get_language_url($lang->code, $current_url);
            $label = self::label($lang, $show_flags, $show_names);
            printf(
                '<a href="%s" class="stm-lang-btn%s" lang="%s">%s</a>',
                esc_url($url),
                $lang->code === $current ? ' current' : '',
                esc_attr($lang->code),
                $label
            );
        }
        echo '</div>';
    }

    private static function render_flags($languages, $current, $current_url) {
        echo '<div class="stm-language-switcher stm-style-flags">';
        foreach ($languages as $lang) {
            $url = self::get_language_url($lang->code, $current_url);
            printf(
                '<a href="%s" class="%s" title="%s">%s</a>',
                esc_url($url),
                $lang->code === $current ? 'current' : '',
                esc_attr($lang->name),
                esc_html($lang->flag_emoji)
            );
        }
        echo '</div>';
    }

    /**
     * Build the display label for a language, respecting show_flags / show_names.
     * Always returns at least the language code as a fallback.
     */
    private static function label($lang, $show_flags, $show_names) {
        $parts = [];
        if ($show_flags && !empty($lang->flag_emoji)) {
            $parts[] = esc_html($lang->flag_emoji);
        }
        if ($show_names) {
            $parts[] = esc_html($lang->native_name ?: $lang->name);
        }
        return $parts ? implode(' ', $parts) : esc_html(strtoupper($lang->code));
    }

    // -------------------------------------------------------------------------
    // URL helpers
    // -------------------------------------------------------------------------

    private static function get_current_url() {
        $protocol = is_ssl() ? 'https://' : 'http://';
        $uri      = $_SERVER['REQUEST_URI'];

        // Strip ?lang= parameter
        $uri = preg_replace('/([?&])lang=[^&]*(&|$)/', '$1', $uri);
        $uri = rtrim($uri, '?&');

        // Strip existing language prefix (/en/, /nl/, etc.) when URL routing is on
        if (Settings::is_url_routing_enabled()) {
            $uri = preg_replace('#^/[a-z]{2,3}(/|$)#', '/', $uri);
        }

        return $protocol . $_SERVER['HTTP_HOST'] . $uri;
    }

    private static function get_language_url($lang_code, $base_url) {
        if (Settings::is_url_routing_enabled()) {
            // Parse the base URL and prepend /lang_code/
            $parsed = parse_url($base_url);
            $path   = ltrim($parsed['path'] ?? '/', '/');
            $query  = isset($parsed['query']) ? '?' . $parsed['query'] : '';
            return $parsed['scheme'] . '://' . $parsed['host'] . '/' . $lang_code . '/' . $path . $query;
        }

        // Query-param mode
        $sep = strpos($base_url, '?') !== false ? '&' : '?';
        return $base_url . $sep . 'lang=' . rawurlencode($lang_code);
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    public static function enqueue_styles() {
        wp_enqueue_style(
            'stm-frontend',
            STM_PLUGIN_URL . 'assets/frontend.css',
            [],
            STM_VERSION
        );
    }
}

/**
 * Template helper — keeps backwards compatibility with functions.php
 */
function stm_language_switcher($args = []) {
    \STM\LanguageSwitcher::render(is_array($args) ? $args : ['style' => $args]);
}
