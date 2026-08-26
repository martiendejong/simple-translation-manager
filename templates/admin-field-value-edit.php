<?php
/**
 * Admin Template: Field Value Translations - values for one field
 *
 * Variables from Admin::page_field_values():
 * - $field: field name
 * - $field_config: ['label', 'post_types', 'source']
 * - $values: [['value', 'hash', 'in_use', 'post_count'], ...]
 * - $translations: value_hash => [language_code => translation]
 * - $languages, $default_language, $default_code
 */
if (!defined('ABSPATH')) exit;

$saved      = isset($_GET['stm_saved']) ? (int) $_GET['stm_saved'] : null;
$autofilled = isset($_GET['stm_autofilled']) ? (int) $_GET['stm_autofilled'] : null;
$autofail   = isset($_GET['stm_autofill_failed']) ? (int) $_GET['stm_autofill_failed'] : 0;
$error      = isset($_GET['stm_error']) ? sanitize_text_field(urldecode($_GET['stm_error'])) : '';

$non_default_languages = array_values(array_filter($languages, function($language) use ($default_code) {
    return $language->code !== $default_code;
}));

$list_url = admin_url('admin.php?page=stm-field-values');
?>

<div class="wrap">
    <h1>
        Field Values: <?php echo esc_html($field_config['label']); ?>
        <code style="font-size:14px;"><?php echo esc_html($field); ?></code>
    </h1>
    <p><a href="<?php echo esc_url($list_url); ?>">&larr; Back to field list</a></p>

    <?php if ($saved !== null): ?>
        <div class="notice notice-success is-dismissible"><p><?php echo (int) $saved; ?> translation(s) saved.</p></div>
    <?php endif; ?>
    <?php if ($autofilled !== null): ?>
        <div class="notice notice-<?php echo $autofail ? 'warning' : 'success'; ?> is-dismissible">
            <p>
                Auto-translate: <?php echo (int) $autofilled; ?> value(s) filled<?php echo $autofail ? ', ' . (int) $autofail . ' failed' : ''; ?>.
                <?php if ($error): ?><br>Last error: <?php echo esc_html($error); ?><?php endif; ?>
            </p>
        </div>
    <?php elseif ($error): ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div>
    <?php endif; ?>

    <?php if (empty($values)): ?>
        <p>No values found for this field<?php echo $field_config['post_types'] ? ' in post types: ' . esc_html(implode(', ', $field_config['post_types'])) : ''; ?>.</p>
    <?php else: ?>

    <!-- Auto-translate missing values -->
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:16px;">
        <input type="hidden" name="action" value="stm_autofill_field_values">
        <input type="hidden" name="field" value="<?php echo esc_attr($field); ?>">
        <?php wp_nonce_field('stm_autofill_field_values'); ?>
        <label for="target_language"><strong>Auto-translate missing values:</strong></label>
        <select name="target_language" id="target_language">
            <option value="">All languages</option>
            <?php foreach ($non_default_languages as $language): ?>
                <option value="<?php echo esc_attr($language->code); ?>">
                    <?php echo esc_html($language->flag_emoji . ' ' . $language->name); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button">Auto-translate</button>
        <span class="description">Uses the AI provider from Translations &gt; Settings. Existing translations are never overwritten.</span>
    </form>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="stm_save_field_values">
        <input type="hidden" name="field" value="<?php echo esc_attr($field); ?>">
        <?php wp_nonce_field('stm_save_field_values'); ?>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>
                        <?php echo esc_html($default_language ? $default_language->flag_emoji . ' ' : ''); ?>Value
                        (<?php echo esc_html(strtoupper($default_code)); ?>, source)
                    </th>
                    <?php foreach ($non_default_languages as $language): ?>
                        <th><?php echo esc_html($language->flag_emoji . ' ' . $language->name); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($values as $value_row): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($value_row['value']); ?></strong>
                            <input type="hidden" name="source[<?php echo esc_attr($value_row['hash']); ?>]"
                                   value="<?php echo esc_attr($value_row['value']); ?>">
                            <br>
                            <?php if ($value_row['in_use']): ?>
                                <span class="description"><?php echo (int) $value_row['post_count']; ?> post(s)</span>
                            <?php else: ?>
                                <span class="description" style="color:#b32d2e;">no longer in use</span>
                            <?php endif; ?>
                        </td>
                        <?php foreach ($non_default_languages as $language):
                            $current = $translations[$value_row['hash']][$language->code] ?? '';
                        ?>
                            <td>
                                <input type="text" style="width:100%;"
                                       name="translations[<?php echo esc_attr($value_row['hash']); ?>][<?php echo esc_attr($language->code); ?>]"
                                       value="<?php echo esc_attr($current); ?>"
                                       placeholder="<?php echo esc_attr($value_row['value']); ?>"
                                       <?php echo $current === '' ? 'class="stm-missing" data-missing="1"' : ''; ?>>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="submit">
            <button type="submit" class="button button-primary">Save All Translations</button>
            <span class="description">Leave a field empty to fall back to the source value on the site.</span>
        </p>
    </form>

    <style>
        input.stm-missing { border-color: #d63638; }
    </style>

    <?php endif; ?>
</div>
