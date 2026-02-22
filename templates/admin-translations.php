<?php
/**
 * Admin Template: Translation Strings
 */
if (!defined('ABSPATH')) exit;
?>

<div class="wrap">
    <h1>Translation Strings</h1>

    <?php if (isset($_GET['updated'])): ?>
        <div class="notice notice-success is-dismissible">
            <p>Translation updated successfully.</p>
        </div>
    <?php endif; ?>

    <div class="tablenav top">
        <form method="get" action="">
            <input type="hidden" name="page" value="stm-translations">

            <label>Context:</label>
            <select name="context">
                <option value="">All</option>
                <?php foreach ($contexts as $ctx): ?>
                    <option value="<?php echo esc_attr($ctx); ?>" <?php selected($context_filter, $ctx); ?>>
                        <?php echo esc_html($ctx); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="submit" class="button" value="Filter">
        </form>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 20%;">Key</th>
                <th style="width: 10%;">Context</th>
                <?php foreach ($languages as $lang): ?>
                    <th><?php echo esc_html($lang->flag_emoji . ' ' . $lang->code); ?></th>
                <?php endforeach; ?>
                <th style="width: 10%;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($strings)): ?>
                <tr>
                    <td colspan="<?php echo count($languages) + 3; ?>">
                        No strings found. <a href="<?php echo admin_url('admin.php?page=stm-add-string'); ?>">Add first string</a>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($strings as $string): ?>
                    <tr>
                        <td><strong><?php echo esc_html($string->string_key); ?></strong></td>
                        <td><code><?php echo esc_html($string->context); ?></code></td>

                        <?php foreach ($languages as $lang): ?>
                            <?php
                            global $wpdb;
                            $translation = $wpdb->get_row($wpdb->prepare(
                                "SELECT * FROM {$wpdb->prefix}stm_translations
                                WHERE string_id = %d AND language_code = %s",
                                $string->id,
                                $lang->code
                            ));
                            ?>
                            <td>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin:0;">
                                    <?php wp_nonce_field('stm_save_translation'); ?>
                                    <input type="hidden" name="action" value="stm_save_translation">
                                    <input type="hidden" name="string_id" value="<?php echo $string->id; ?>">
                                    <input type="hidden" name="language_code" value="<?php echo $lang->code; ?>">

                                    <input type="text"
                                           name="translation"
                                           value="<?php echo esc_attr($translation ? $translation->translation : ''); ?>"
                                           placeholder="Translation"
                                           style="width: 100%;">

                                    <button type="submit" class="button button-small" style="margin-top: 2px;">Save</button>
                                </form>
                            </td>
                        <?php endforeach; ?>

                        <td>
                            <?php
                            $total = count($languages);
                            $translated = $string->translated_count;
                            $percentage = $total > 0 ? round(($translated / $total) * 100) : 0;
                            ?>
                            <span class="translation-progress">
                                <?php echo $translated; ?>/<?php echo $total; ?>
                                (<?php echo $percentage; ?>%)
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <h2 style="margin-top: 40px;">Add New String</h2>
    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
        <?php wp_nonce_field('stm_add_string'); ?>
        <input type="hidden" name="action" value="stm_add_string">

        <table class="form-table">
            <tr>
                <th><label for="string_key">Translation Key *</label></th>
                <td>
                    <input type="text"
                           id="string_key"
                           name="string_key"
                           class="regular-text"
                           placeholder="e.g., nav.home"
                           required>
                    <p class="description">Use dot notation: nav.home, footer.copyright, etc.</p>
                </td>
            </tr>
            <tr>
                <th><label for="context">Context</label></th>
                <td>
                    <input type="text"
                           id="context"
                           name="context"
                           class="regular-text"
                           value="general"
                           placeholder="general">
                    <p class="description">Group related translations (e.g., navigation, footer, forms)</p>
                </td>
            </tr>
            <tr>
                <th><label for="description">Description</label></th>
                <td>
                    <textarea id="description"
                              name="description"
                              class="large-text"
                              rows="3"
                              placeholder="Optional: Help translators understand context"></textarea>
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="submit" class="button button-primary">Add String</button>
        </p>
    </form>
</div>

<style>
.translation-progress {
    font-size: 12px;
    color: #666;
}
</style>
