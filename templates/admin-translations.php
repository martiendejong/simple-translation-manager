<?php
/**
 * Admin Template: Translation Strings
 */
if (!defined('ABSPATH')) exit;
?>

<div class="wrap">
    <h1>Translation Strings</h1>

    <?php
    // These flags are only ever read back from a redirect the actual form
    // handlers issue after they have already verified their own nonce
    // (see Admin::save_translation()/scan_strings()); displaying them here
    // is read-only and causes no state change, so no nonce is required.
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    ?>
    <?php if (isset($_GET['updated'])): ?>
        <div class="notice notice-success is-dismissible">
            <p>Translation updated successfully.</p>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['stm_scanned'])): ?>
        <div class="notice notice-success is-dismissible">
            <p>
                Scan complete: <?php echo intval($_GET['stm_scan_found'] ?? 0); ?> strings found in the theme and plugin templates,
                <?php echo intval($_GET['stm_scan_added'] ?? 0); ?> new strings added.
            </p>
        </div>
    <?php endif; ?>

    <?php if (($_GET['stm_error'] ?? '') === 'scan_failed'): ?>
        <div class="notice notice-error is-dismissible">
            <p>Scanning for strings failed. Check the server error log for details.</p>
        </div>
    <?php endif; ?>
    <?php // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom: 15px;">
        <?php wp_nonce_field('stm_scan_strings'); ?>
        <input type="hidden" name="action" value="stm_scan_strings">
        <button type="submit" class="button">Scan theme &amp; plugin for strings</button>
        <span class="description">Finds <code>__stm()</code> / <code>_e_stm()</code> calls in the active theme and adds any not already listed below.</span>
    </form>

    <div class="tablenav top">
        <form method="get" action="" style="display: flex; gap: 10px; align-items: center;">
            <input type="hidden" name="page" value="stm-translations">

            <label>Search:</label>
            <input type="text"
                   name="search"
                   value="<?php echo esc_attr($search ?? ''); ?>"
                   placeholder="e.g. services.datadriven"
                   style="width: 250px;">

            <label>Context:</label>
            <select name="context">
                <option value="">All</option>
                <?php foreach ($contexts as $ctx): ?>
                    <option value="<?php echo esc_attr($ctx); ?>" <?php selected($context_filter, $ctx); ?>>
                        <?php echo esc_html($ctx); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Status:</label>
            <select name="status">
                <option value="">All</option>
                <option value="missing" <?php selected($status_filter, 'missing'); ?>>Missing translations</option>
                <option value="complete" <?php selected($status_filter, 'complete'); ?>>Fully translated</option>
            </select>

            <input type="submit" class="button" value="Filter">

            <?php if (!empty($search) || !empty($context_filter) || !empty($status_filter)): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=stm-translations')); ?>" class="button">Clear</a>
            <?php endif; ?>

            <span style="margin-left: auto;">
                Showing <?php echo absint(count($strings)); ?> of <?php echo absint($total_items); ?> strings
            </span>
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
                    <td colspan="<?php echo absint(count($languages) + 3); ?>">
                        No strings found. Use "Scan theme &amp; plugin for strings" above to auto-detect strings already used in your theme, or <a href="#add-string">add the first one manually</a> below.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($strings as $string): ?>
                    <tr>
                        <td><strong><?php echo esc_html($string->string_key); ?></strong></td>
                        <td><code><?php echo esc_html($string->context); ?></code></td>

                        <?php foreach ($languages as $lang): ?>
                            <?php
                            $translation = $translations_map[$string->id][$lang->code] ?? null;
                            $translation_value = $translation ? $translation->translation : '';
                            $is_default_lang = ($lang->code === $default_lang_code);

                            $default_translation_obj = $translations_map[$string->id][$default_lang_code] ?? null;
                            $default_translation_value = $default_translation_obj ? $default_translation_obj->translation : null;

                            $placeholder = STM\Admin::get_translation_placeholder($default_translation_value, $is_default_lang);

                            // Only true when we're actually about to show the default
                            // language's text in place of this cell's own (missing) translation.
                            $placeholder_is_default = ($translation_value === '' && $placeholder !== 'Translation');
                            ?>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                                    <?php wp_nonce_field('stm_save_translation'); ?>
                                    <input type="hidden" name="action" value="stm_save_translation">
                                    <input type="hidden" name="string_id" value="<?php echo absint($string->id); ?>">
                                    <input type="hidden" name="language_code" value="<?php echo esc_attr($lang->code); ?>">

                                    <input type="text"
                                           name="translation"
                                           value="<?php echo esc_attr($translation_value); ?>"
                                           placeholder="<?php echo esc_attr($placeholder); ?>"
                                           <?php if ($placeholder_is_default): ?>
                                           class="stm-placeholder-is-default"
                                           title="No translation yet for this language — showing the default language text so you know what visitors currently see."
                                           <?php endif; ?>
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
                                <?php echo absint($translated); ?>/<?php echo absint($total); ?>
                                (<?php echo absint($percentage); ?>%)
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo absint($total_items); ?> items</span>
                <?php
                $base_url = add_query_arg([
                    'page' => 'stm-translations',
                    'context' => $context_filter,
                    'status' => $status_filter ?? '',
                    'search' => $search ?? '',
                ], admin_url('admin.php'));

                // First page
                if ($current_page > 1) {
                    echo '<a class="first-page button" href="' . esc_url(add_query_arg('paged', 1, $base_url)) . '">«</a>';
                    echo '<a class="prev-page button" href="' . esc_url(add_query_arg('paged', $current_page - 1, $base_url)) . '">‹</a>';
                }

                // Page numbers
                echo '<span class="paging-input">';
                echo '<label for="current-page-selector" class="screen-reader-text">Current Page</label>';
                echo '<input class="current-page" id="current-page-selector" type="text"
                      name="paged" value="' . absint($current_page) . '" size="' . absint(strlen((string) $total_pages)) . '"
                      aria-describedby="table-paging" readonly>';
                echo '<span class="tablenav-paging-text"> of <span class="total-pages">' . absint($total_pages) . '</span></span>';
                echo '</span>';

                // Last page
                if ($current_page < $total_pages) {
                    echo '<a class="next-page button" href="' . esc_url(add_query_arg('paged', $current_page + 1, $base_url)) . '">›</a>';
                    echo '<a class="last-page button" href="' . esc_url(add_query_arg('paged', $total_pages, $base_url)) . '">»</a>';
                }
                ?>
            </div>
        </div>
    <?php endif; ?>

    <h2 id="add-string" style="margin-top: 40px;">Add New String</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
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
.stm-placeholder-is-default {
    border-left: 3px solid #dba617;
}
</style>
