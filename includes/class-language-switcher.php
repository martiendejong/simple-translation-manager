<?php
/**
 * Language Switcher
 *
 * Widget, shortcode, and function for language switching
 *
 * @package SimpleTranslationManager
 */

namespace STM;

class LanguageSwitcher extends \WP_Widget {

    /**
     * Initialize
     */
    public static function init() {
        // Register widget
        add_action('widgets_init', function() {
            register_widget('STM\LanguageSwitcher');
        });

        // Register shortcode
        add_shortcode('stm_language_switcher', [__CLASS__, 'shortcode']);
    }

    /**
     * Widget constructor
     */
    public function __construct() {
        parent::__construct(
            'stm_language_switcher',
            'Language Switcher',
            ['description' => 'Display language switcher for multilingual content']
        );
    }

    /**
     * Widget output
     */
    public function widget($args, $instance) {
        echo $args['before_widget'];

        if (!empty($instance['title'])) {
            echo $args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title'];
        }

        $style = $instance['style'] ?? 'list';
        self::render($style);

        echo $args['after_widget'];
    }

    /**
     * Widget form
     */
    public function form($instance) {
        $title = $instance['title'] ?? 'Language';
        $style = $instance['style'] ?? 'list';
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                Title:
            </label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text"
                   value="<?php echo esc_attr($title); ?>">
        </p>

        <p>
            <label for="<?php echo esc_attr($this->get_field_id('style')); ?>">
                Style:
            </label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('style')); ?>"
                    name="<?php echo esc_attr($this->get_field_name('style')); ?>">
                <option value="list" <?php selected($style, 'list'); ?>>List</option>
                <option value="dropdown" <?php selected($style, 'dropdown'); ?>>Dropdown</option>
                <option value="flags" <?php selected($style, 'flags'); ?>>Flags only</option>
            </select>
        </p>
        <?php
    }

    /**
     * Update widget
     */
    public function update($new_instance, $old_instance) {
        $instance = [];
        $instance['title'] = (!empty($new_instance['title'])) ? sanitize_text_field($new_instance['title']) : '';
        $instance['style'] = (!empty($new_instance['style'])) ? sanitize_text_field($new_instance['style']) : 'list';
        return $instance;
    }

    /**
     * Shortcode handler
     */
    public static function shortcode($atts) {
        $atts = shortcode_atts([
            'style' => 'list'
        ], $atts);

        ob_start();
        self::render($atts['style']);
        return ob_get_clean();
    }

    /**
     * Render language switcher
     */
    public static function render($style = 'list') {
        $languages = Database::get_languages();
        $current_lang = Frontend::get_current_language();
        $current_url = self::get_current_url();

        if (count($languages) <= 1) {
            return;
        }

        if ($style === 'dropdown') {
            self::render_dropdown($languages, $current_lang, $current_url);
        } elseif ($style === 'flags') {
            self::render_flags($languages, $current_lang, $current_url);
        } else {
            self::render_list($languages, $current_lang, $current_url);
        }
    }

    /**
     * Render as list
     */
    private static function render_list($languages, $current_lang, $current_url) {
        echo '<ul class="stm-language-switcher stm-style-list">';

        foreach ($languages as $lang) {
            $url = self::get_language_url($lang->code, $current_url);
            $is_current = ($lang->code === $current_lang);

            printf(
                '<li class="%s"><a href="%s">%s %s</a></li>',
                $is_current ? 'current' : '',
                esc_url($url),
                esc_html($lang->flag_emoji),
                esc_html($lang->name)
            );
        }

        echo '</ul>';
    }

    /**
     * Render as dropdown
     */
    private static function render_dropdown($languages, $current_lang, $current_url) {
        echo '<div class="stm-language-switcher stm-style-dropdown">';
        echo '<select onchange="window.location.href=this.value">';

        foreach ($languages as $lang) {
            $url = self::get_language_url($lang->code, $current_url);
            $is_current = ($lang->code === $current_lang);

            printf(
                '<option value="%s" %s>%s %s</option>',
                esc_attr($url),
                $is_current ? 'selected' : '',
                esc_html($lang->flag_emoji),
                esc_html($lang->name)
            );
        }

        echo '</select>';
        echo '</div>';
    }

    /**
     * Render as flags only
     */
    private static function render_flags($languages, $current_lang, $current_url) {
        echo '<div class="stm-language-switcher stm-style-flags">';

        foreach ($languages as $lang) {
            $url = self::get_language_url($lang->code, $current_url);
            $is_current = ($lang->code === $current_lang);

            printf(
                '<a href="%s" class="%s" title="%s">%s</a> ',
                esc_url($url),
                $is_current ? 'current' : '',
                esc_attr($lang->name),
                esc_html($lang->flag_emoji)
            );
        }

        echo '</div>';
    }

    /**
     * Get current URL
     */
    private static function get_current_url() {
        $protocol = is_ssl() ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $uri = $_SERVER['REQUEST_URI'];

        // Remove existing lang parameter
        $uri = preg_replace('/([?&])lang=[^&]*(&|$)/', '$1', $uri);
        $uri = rtrim($uri, '?&');

        return $protocol . $host . $uri;
    }

    /**
     * Get URL for specific language
     */
    private static function get_language_url($lang_code, $base_url) {
        $separator = (strpos($base_url, '?') !== false) ? '&' : '?';
        return $base_url . $separator . 'lang=' . $lang_code;
    }
}

/**
 * Helper function for templates
 */
function stm_language_switcher($args = []) {
    $defaults = ['style' => 'list'];
    $args = wp_parse_args($args, $defaults);
    \STM\LanguageSwitcher::render($args['style']);
}
