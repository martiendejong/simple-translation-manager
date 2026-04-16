<?php
/**
 * Dashboard - Missing Translations tab
 *
 * @var array $languages
 * @var object|null $default_lang
 * @var array $filters
 * @var array $missing
 */
if (!defined('ABSPATH')) exit;

$default_code = $default_lang ? $default_lang->code : 'en';
$post_types   = STM\Dashboard::get_translatable_post_types();
$base_url     = admin_url('admin.php?page=stm-dashboard');

$export_url = wp_nonce_url(
    add_query_arg(
        [
            'action'     => 'stm_export_missing_csv',
            'flang'      => $filters['language'],
            'fptype'     => $filters['post_type'],
            'fdate_from' => $filters['date_from'],
            'fdate_to'   => $filters['date_to'],
        ],
        admin_url('admin-post.php')
    ),
    'stm_export_missing_csv'
);
?>
<div class="stm-dashboard-missing">
    <form method="get" action="" class="stm-filter-bar" style="margin: 16px 0;">
        <input type="hidden" name="page" value="stm-dashboard">
        <input type="hidden" name="tab" value="missing">

        <label>
            <?php esc_html_e('Language', 'simple-translation-manager'); ?>:
            <select name="flang">
                <option value=""><?php esc_html_e('All target languages', 'simple-translation-manager'); ?></option>
                <?php foreach ($languages as $lang): ?>
                    <?php if ($lang->code === $default_code) continue; ?>
                    <option value="<?php echo esc_attr($lang->code); ?>" <?php selected($filters['language'], $lang->code); ?>>
                        <?php echo esc_html($lang->flag_emoji . ' ' . $lang->native_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            <?php esc_html_e('Post type', 'simple-translation-manager'); ?>:
            <select name="fptype">
                <option value=""><?php esc_html_e('All', 'simple-translation-manager'); ?></option>
                <?php foreach ($post_types as $pt): ?>
                    <option value="<?php echo esc_attr($pt); ?>" <?php selected($filters['post_type'], $pt); ?>>
                        <?php echo esc_html($pt); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            <?php esc_html_e('From', 'simple-translation-manager'); ?>:
            <input type="date" name="fdate_from" value="<?php echo esc_attr($filters['date_from']); ?>">
        </label>

        <label>
            <?php esc_html_e('To', 'simple-translation-manager'); ?>:
            <input type="date" name="fdate_to" value="<?php echo esc_attr($filters['date_to']); ?>">
        </label>

        <input type="submit" class="button button-primary" value="<?php esc_attr_e('Filter', 'simple-translation-manager'); ?>">
        <a href="<?php echo esc_url(add_query_arg('tab', 'missing', $base_url)); ?>" class="button"><?php esc_html_e('Clear', 'simple-translation-manager'); ?></a>
        <a href="<?php echo esc_url($export_url); ?>" class="button"><?php esc_html_e('Export CSV', 'simple-translation-manager'); ?></a>
    </form>

    <p class="description">
        <?php
        printf(
            /* translators: %s: number of rows */
            esc_html__('%s missing translation(s) match the current filters.', 'simple-translation-manager'),
            '<strong>' . esc_html(number_format_i18n($missing['total'])) . '</strong>'
        );
        ?>
    </p>

    <?php if (empty($missing['rows'])) : ?>
        <div class="notice notice-success inline">
            <p><?php esc_html_e('Nothing missing — every post in scope has translations.', 'simple-translation-manager'); ?></p>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat striped stm-missing-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Post', 'simple-translation-manager'); ?></th>
                    <th><?php esc_html_e('Type', 'simple-translation-manager'); ?></th>
                    <th><?php esc_html_e('Date', 'simple-translation-manager'); ?></th>
                    <th><?php esc_html_e('Target language', 'simple-translation-manager'); ?></th>
                    <th><?php esc_html_e('Quick translate (title)', 'simple-translation-manager'); ?></th>
                    <th><?php esc_html_e('Actions', 'simple-translation-manager'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($missing['rows'] as $row): ?>
                <tr data-post-id="<?php echo esc_attr($row['post_id']); ?>" data-language="<?php echo esc_attr($row['language_code']); ?>">
                    <td>
                        <strong><?php echo esc_html($row['title']); ?></strong>
                        <br><code>#<?php echo esc_html($row['post_id']); ?></code>
                    </td>
                    <td><?php echo esc_html($row['post_type']); ?></td>
                    <td><?php echo esc_html(mysql2date(get_option('date_format'), $row['post_date'])); ?></td>
                    <td>
                        <?php echo esc_html($row['language_emoji'] . ' ' . $row['language_name']); ?>
                        <code><?php echo esc_html($row['language_code']); ?></code>
                    </td>
                    <td>
                        <input type="text" class="regular-text stm-quick-input"
                               placeholder="<?php esc_attr_e('Translated title…', 'simple-translation-manager'); ?>"
                               style="width: 100%;">
                        <span class="stm-quick-status" aria-live="polite"></span>
                    </td>
                    <td>
                        <button type="button" class="button button-primary button-small stm-quick-save">
                            <?php esc_html_e('Save', 'simple-translation-manager'); ?>
                        </button>
                        <a class="button button-small"
                           href="<?php echo esc_url(admin_url('post.php?action=edit&post=' . $row['post_id'])); ?>"
                           target="_blank">
                            <?php esc_html_e('Edit post', 'simple-translation-manager'); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($missing['total_pages'] > 1): ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    $page_links = paginate_links([
                        'base'      => add_query_arg('paged', '%#%'),
                        'format'    => '',
                        'prev_text' => __('&laquo;', 'simple-translation-manager'),
                        'next_text' => __('&raquo;', 'simple-translation-manager'),
                        'total'     => $missing['total_pages'],
                        'current'   => $missing['current'],
                    ]);
                    echo wp_kses_post($page_links);
                    ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
