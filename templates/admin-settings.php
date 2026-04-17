<?php
/**
 * Admin Template: Settings
 */
if (!defined('ABSPATH')) exit;

// Handle form submission
if (isset($_POST['stm_save_settings']) && check_admin_referer('stm_settings')) {
    if (current_user_can('manage_options')) {
        // Save settings
        STM\Settings::set_default_language(sanitize_text_field($_POST['default_language'] ?? ''));
        STM\Settings::set_url_routing(isset($_POST['enable_url_routing']));
        STM\Settings::set_cache_duration((int) $_POST['cache_duration'] ?? 3600);
        STM\Settings::set_keep_data_on_uninstall(isset($_POST['keep_data_on_uninstall']));
        STM\Settings::set_debug_mode(isset($_POST['debug_mode']));
        STM\Settings::set_switcher_style(sanitize_text_field($_POST['switcher_style'] ?? 'list'));
        STM\Settings::set_switcher_show_flags(isset($_POST['switcher_show_flags']));
        STM\Settings::set_switcher_show_names(isset($_POST['switcher_show_names']));
        STM\Settings::set_switcher_position(sanitize_text_field($_POST['switcher_position'] ?? 'none'));

        echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully.</p></div>';
    }
}

// Get current settings
$settings = STM\Settings::get_all();

// Get available languages
$languages = STM\Database::get_languages();
?>

<div class="wrap">
    <h1>Translation Manager Settings</h1>

    <form method="post" action="">
        <?php wp_nonce_field('stm_settings'); ?>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="default_language">Default Language</label>
                </th>
                <td>
                    <select name="default_language" id="default_language">
                        <?php foreach ($languages as $lang): ?>
                            <option value="<?php echo esc_attr($lang->code); ?>"
                                    <?php selected($settings['default_language'], $lang->code); ?>>
                                <?php echo esc_html($lang->flag_emoji . ' ' . $lang->name . ' (' . $lang->code . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Primary language for your site</p>
                </td>
            </tr>

            <tr>
                <th scope="row">URL Routing</th>
                <td>
                    <label>
                        <input type="checkbox" name="enable_url_routing" value="1"
                               <?php checked($settings['enable_url_routing']); ?>>
                        Enable language URLs (e.g., /en/, /nl/)
                    </label>
                    <p class="description">
                        When enabled, visitors can access <code>/en/</code> and <code>/nl/</code> URLs.<br>
                        Disable if you only need backend translations.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="cache_duration">Cache Duration</label>
                </th>
                <td>
                    <input type="number" name="cache_duration" id="cache_duration"
                           value="<?php echo esc_attr($settings['cache_duration']); ?>"
                           min="0" max="86400" step="60"
                           style="width: 100px;">
                    seconds
                    <p class="description">
                        How long to cache translations. Default: 3600 (1 hour).<br>
                        Set to 0 to disable caching (not recommended).
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">Data Handling</th>
                <td>
                    <label>
                        <input type="checkbox" name="keep_data_on_uninstall" value="1"
                               <?php checked($settings['keep_data_on_uninstall']); ?>>
                        Keep translations when uninstalling plugin
                    </label>
                    <p class="description">
                        When unchecked, all translations will be permanently deleted on uninstall.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">Debug Mode</th>
                <td>
                    <label>
                        <input type="checkbox" name="debug_mode" value="1"
                               <?php checked($settings['debug_mode']); ?>>
                        Enable debug logging
                    </label>
                    <p class="description">
                        Logs translation lookups and cache misses to debug.log<br>
                        Only enable for troubleshooting (impacts performance).
                    </p>
                </td>
            </tr>
        </table>

        <h2 style="margin-top: 2em;">Language Switcher</h2>
        <p>Configure how the language switcher looks and where it appears. Use the <code>[stm_language_switcher]</code> shortcode or the Language Switcher widget to place it manually.</p>

        <table class="form-table">
            <tr>
                <th scope="row"><label for="switcher_style">Display Style</label></th>
                <td>
                    <select name="switcher_style" id="switcher_style">
                        <option value="list"     <?php selected($settings['switcher_style'], 'list');     ?>>List — horizontal list of links</option>
                        <option value="dropdown" <?php selected($settings['switcher_style'], 'dropdown'); ?>>Dropdown — single select menu</option>
                        <option value="buttons"  <?php selected($settings['switcher_style'], 'buttons');  ?>>Buttons — pill buttons per language</option>
                        <option value="flags"    <?php selected($settings['switcher_style'], 'flags');    ?>>Flags only — emoji flags, no text</option>
                    </select>
                    <p class="description">Default style used by shortcode, widget, and auto-inject.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Display Options</th>
                <td>
                    <label style="display:block;margin-bottom:6px;">
                        <input type="checkbox" name="switcher_show_flags" value="1"
                               <?php checked($settings['switcher_show_flags']); ?>>
                        Show flag emoji
                    </label>
                    <label style="display:block;">
                        <input type="checkbox" name="switcher_show_names" value="1"
                               <?php checked($settings['switcher_show_names']); ?>>
                        Show language name
                    </label>
                    <p class="description">
                        At least one of these should be enabled. "Flags only" style ignores these and always shows flags.
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="switcher_position">Auto-inject Position</label></th>
                <td>
                    <select name="switcher_position" id="switcher_position">
                        <option value="none"           <?php selected($settings['switcher_position'], 'none');           ?>>None — place manually via shortcode or widget</option>
                        <option value="before_content" <?php selected($settings['switcher_position'], 'before_content'); ?>>Before post content</option>
                        <option value="after_content"  <?php selected($settings['switcher_position'], 'after_content');  ?>>After post content</option>
                        <option value="both"           <?php selected($settings['switcher_position'], 'both');           ?>>Before and after content</option>
                    </select>
                    <p class="description">
                        Auto-inject adds the switcher to single post/page content automatically.<br>
                        Use "None" if you place it with the widget or shortcode.
                    </p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="submit" name="stm_save_settings" class="button button-primary">
                Save Settings
            </button>
        </p>
    </form>

    <hr style="margin: 40px 0;">

    <!-- AI / Auto-translate settings -->
    <?php
    $ai = STM\AutoTranslate::get_settings();
    $ai_saved = isset($_GET['stm_saved']);
    ?>
    <?php if ($ai_saved): ?>
        <div class="notice notice-success is-dismissible"><p>AI settings saved.</p></div>
    <?php endif; ?>

    <div class="card" style="max-width: 600px;">
        <h2>Auto-Translate (AI)</h2>
        <p>Used by the <strong>Auto-translate</strong> button in the post editor. Requires an API key from the chosen provider.</p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="stm_save_ai_settings">
            <?php wp_nonce_field('stm_ai_settings'); ?>

            <table class="form-table">
                <tr>
                    <th><label for="ai_provider">Provider</label></th>
                    <td>
                        <select id="ai_provider" name="ai_provider">
                            <option value="openai" <?php selected($ai['provider'], 'openai'); ?>>OpenAI (GPT-4o-mini) — best quality</option>
                            <option value="deepl"  <?php selected($ai['provider'], 'deepl');  ?>>DeepL — free tier 500k chars/mo</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="openai_key">OpenAI API Key</label></th>
                    <td>
                        <input type="password" id="openai_key" name="openai_key" class="regular-text"
                               placeholder="<?php echo $ai['openai_key_set'] ? '••••••••••••••••' : 'sk-...'; ?>">
                        <?php if ($ai['openai_key_set']): ?>
                            <span style="color:#46b450;margin-left:8px;">✓ configured</span>
                        <?php endif; ?>
                        <p class="description">Leave blank to keep current key. Get one at <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a>.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="deepl_key">DeepL API Key</label></th>
                    <td>
                        <input type="password" id="deepl_key" name="deepl_key" class="regular-text"
                               placeholder="<?php echo $ai['deepl_key_set'] ? '••••••••••••••••' : 'xxxxxxxx-xxxx-...:fx'; ?>">
                        <?php if ($ai['deepl_key_set']): ?>
                            <span style="color:#46b450;margin-left:8px;">✓ configured</span>
                        <?php endif; ?>
                        <p class="description">Leave blank to keep current key. Free keys end in <code>:fx</code>. Get one at <a href="https://www.deepl.com/pro-api" target="_blank">deepl.com</a>.</p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">Save AI Settings</button>
            </p>
        </form>
    </div>

    <hr style="margin: 40px 0;">

    <div class="card">
        <h2>REST API Endpoints</h2>
        <p>All endpoints support Application Password authentication (WordPress 5.6+).</p>

        <table class="widefat fixed" style="width: 100%; margin-top: 20px;">
            <thead>
                <tr>
                    <th style="width: 30%;">Endpoint</th>
                    <th style="width: 50%;">Description</th>
                    <th style="width: 20%;">Auth</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>GET /stm/v1/languages</code></td>
                    <td>List all languages</td>
                    <td>No</td>
                </tr>
                <tr>
                    <td><code>POST /stm/v1/languages</code></td>
                    <td>Create language</td>
                    <td>Yes</td>
                </tr>
                <tr>
                    <td><code>GET /stm/v1/translations</code></td>
                    <td>List translations</td>
                    <td>No</td>
                </tr>
                <tr>
                    <td><code>POST /stm/v1/translations/bulk</code></td>
                    <td>Bulk import translations</td>
                    <td>Yes</td>
                </tr>
                <tr>
                    <td><code>GET /stm/v1/export</code></td>
                    <td>Export all translations as JSON</td>
                    <td>Yes</td>
                </tr>
            </tbody>
        </table>

        <h3 style="margin-top: 30px;">Generate Application Password</h3>
        <ol>
            <li>Go to <a href="<?php echo esc_url(admin_url('profile.php')); ?>">Users → Profile</a></li>
            <li>Scroll to "Application Passwords"</li>
            <li>Create new password with name "Translation Manager"</li>
            <li>Copy the password (shown only once)</li>
            <li>Use in API: <code>-u "username:APP_PASSWORD"</code></li>
        </ol>
    </div>

    <div class="card" style="margin-top: 20px;">
        <h2>Usage in Templates</h2>
        <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; font-size: 13px;">
// Simple translation
echo __t('nav.home');  // Returns translation or key

// Translation with fallback
echo __t('welcome.message', 'Welcome!');

// Post field translation
echo stm_get_post_translation($post_id, 'title');
echo stm_get_post_translation($post_id, 'description', 'nl');

// Get current language
$lang = stm_get_current_language();  // 'en', 'nl', etc.

// Language switcher widget
[stm_language_switcher]  // Shortcode
stm_language_switcher();  // Function</pre>
    </div>
</div>
