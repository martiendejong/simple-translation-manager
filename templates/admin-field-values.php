<?php
/**
 * Admin Template: Field Value Translations - field list
 *
 * Variables from Admin::page_field_values():
 * - $registered: field_name => config
 * - $coverage: field_name => ['total' => int, 'languages' => [code => count]]
 * - $languages, $default_language, $default_code
 * - $post_types: post type objects with show_ui
 */
if (!defined('ABSPATH')) exit;

$added   = isset($_GET['stm_added']);
$deleted = isset($_GET['stm_deleted']);
$error   = isset($_GET['stm_error']) ? sanitize_text_field($_GET['stm_error']) : '';
$error_messages = [
    'invalid_field' => 'Invalid field name.',
];

$non_default_languages = array_filter($languages, function($language) use ($default_code) {
    return $language->code !== $default_code;
});
?>

<div class="wrap">
    <h1>Field Value Translations</h1>
    <p class="description" style="max-width:760px;">
        Fields listed here hold standardized values (for example coachwork or color) that repeat across many posts.
        Each distinct value is translated once per language and every post using that value shows the translation
        automatically. Manage the translations per field below.
    </p>

    <?php if ($added): ?>
        <div class="notice notice-success is-dismissible"><p>Field marked as value-translatable.</p></div>
    <?php endif; ?>
    <?php if ($deleted): ?>
        <div class="notice notice-success is-dismissible"><p>Field removed. Its stored value translations are kept and restored if you add it again.</p></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($error_messages[$error] ?? $error); ?></p></div>
    <?php endif; ?>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:16%;">Field</th>
                <th style="width:16%;">Label</th>
                <th style="width:16%;">Post Types</th>
                <th style="width:10%;">Registered By</th>
                <th style="width:10%;">Distinct Values</th>
                <th>Coverage</th>
                <th style="width:16%;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($registered)): ?>
                <tr><td colspan="7">No value-translatable fields yet. Add one below or register one in code with <code>stm_register_value_translatable_field()</code>.</td></tr>
            <?php endif; ?>
            <?php foreach ($registered as $name => $config):
                $stats = $coverage[$name] ?? ['total' => 0, 'languages' => []];
                $manage_url = admin_url('admin.php?page=stm-field-values&field=' . urlencode($name));
            ?>
                <tr>
                    <td><code><?php echo esc_html($name); ?></code></td>
                    <td><?php echo esc_html($config['label']); ?></td>
                    <td><?php echo esc_html($config['post_types'] ? implode(', ', $config['post_types']) : 'all'); ?></td>
                    <td><?php echo esc_html($config['source']); ?></td>
                    <td><?php echo (int) $stats['total']; ?></td>
                    <td>
                        <?php foreach ($non_default_languages as $language):
                            $count = $stats['languages'][$language->code] ?? 0;
                            $complete = $stats['total'] > 0 && $count >= $stats['total'];
                        ?>
                            <span style="display:inline-block;margin-right:10px;<?php echo $complete ? 'color:#00a32a;' : 'color:#b32d2e;'; ?>">
                                <?php echo esc_html($language->flag_emoji . ' ' . strtoupper($language->code)); ?>:
                                <?php echo (int) $count; ?>/<?php echo (int) $stats['total']; ?>
                            </span>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url($manage_url); ?>" class="button button-small button-primary">Manage values</a>
                        <?php if ($config['source'] !== 'code'): ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                  style="display:inline;"
                                  onsubmit="return confirm('Remove <?php echo esc_js($name); ?> from value translation? Stored translations are kept.')">
                                <input type="hidden" name="action" value="stm_remove_value_field">
                                <input type="hidden" name="field_name" value="<?php echo esc_attr($name); ?>">
                                <?php wp_nonce_field('stm_remove_value_field'); ?>
                                <button type="submit" class="button button-small button-link-delete">Remove</button>
                            </form>
                        <?php else: ?>
                            <em style="color:#999;font-size:12px;">registered in code</em>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="card" style="margin-top:30px;max-width:600px;">
        <h2>Add Value-Translatable Field</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="stm_add_value_field">
            <?php wp_nonce_field('stm_add_value_field'); ?>

            <table class="form-table">
                <tr>
                    <th><label for="field_name">Field (meta key) <span style="color:red">*</span></label></th>
                    <td>
                        <input type="text" id="field_name" name="field_name" class="regular-text"
                               placeholder="coachwork" required pattern="[a-z0-9_\-]+">
                        <p class="description">The post meta key whose values should be translated.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="field_label">Label</label></th>
                    <td>
                        <input type="text" id="field_label" name="field_label" class="regular-text"
                               placeholder="Coachwork">
                    </td>
                </tr>
                <tr>
                    <th><label>Post Types</label></th>
                    <td>
                        <?php foreach ($post_types as $type): ?>
                            <label style="display:inline-block;margin-right:14px;">
                                <input type="checkbox" name="field_post_types[]" value="<?php echo esc_attr($type->name); ?>">
                                <?php echo esc_html($type->labels->singular_name); ?> (<code><?php echo esc_html($type->name); ?></code>)
                            </label>
                        <?php endforeach; ?>
                        <p class="description">Limit value collection to these post types. Leave all unchecked to scan every post type.</p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">Add Field</button>
            </p>
        </form>
    </div>
</div>
