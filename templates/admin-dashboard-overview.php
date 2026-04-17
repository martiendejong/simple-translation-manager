<?php
/**
 * Dashboard - Overview tab
 *
 * @var array $coverage
 * @var object|null $default_lang
 */
if (!defined('ABSPATH')) exit;
?>
<div class="stm-dashboard-overview">
    <p class="description">
        <?php
        printf(
            /* translators: %s: default language name */
            esc_html__('Source language: %s. Coverage is measured for each additional language configured under Translations → Languages.', 'simple-translation-manager'),
            '<strong>' . esc_html($default_lang ? $default_lang->native_name : '—') . '</strong>'
        );
        ?>
    </p>

    <div class="stm-overview-summary">
        <div class="stm-card">
            <span class="stm-card-label"><?php esc_html_e('Published posts', 'simple-translation-manager'); ?></span>
            <strong class="stm-card-value"><?php echo esc_html(number_format_i18n($coverage['total_posts'])); ?></strong>
        </div>
        <div class="stm-card">
            <span class="stm-card-label"><?php esc_html_e('UI strings', 'simple-translation-manager'); ?></span>
            <strong class="stm-card-value"><?php echo esc_html(number_format_i18n($coverage['strings_total'])); ?></strong>
        </div>
        <div class="stm-card">
            <span class="stm-card-label"><?php esc_html_e('Target languages', 'simple-translation-manager'); ?></span>
            <strong class="stm-card-value"><?php echo esc_html(number_format_i18n(count($coverage['by_language']))); ?></strong>
        </div>
        <div class="stm-card">
            <span class="stm-card-label"><?php esc_html_e('Last refreshed', 'simple-translation-manager'); ?></span>
            <strong class="stm-card-value"><?php echo esc_html(mysql2date(get_option('time_format') . ' ' . get_option('date_format'), $coverage['generated_at'])); ?></strong>
        </div>
    </div>

    <div class="stm-actions" style="margin: 16px 0;">
        <button type="button" class="button" id="stm-refresh-coverage">
            <?php esc_html_e('Refresh coverage', 'simple-translation-manager'); ?>
        </button>
        <?php
        $export_url = wp_nonce_url(
            admin_url('admin-post.php?action=stm_export_coverage_csv'),
            'stm_export_coverage_csv'
        );
        ?>
        <a class="button" href="<?php echo esc_url($export_url); ?>">
            <?php esc_html_e('Export coverage as CSV', 'simple-translation-manager'); ?>
        </a>
    </div>

    <?php if (empty($coverage['by_language'])) : ?>
        <div class="notice notice-info inline">
            <p><?php esc_html_e('No target languages configured. Add languages under Translations → Languages.', 'simple-translation-manager'); ?></p>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Language', 'simple-translation-manager'); ?></th>
                    <th><?php esc_html_e('Post titles', 'simple-translation-manager'); ?></th>
                    <th><?php esc_html_e('Post content', 'simple-translation-manager'); ?></th>
                    <th><?php esc_html_e('UI strings', 'simple-translation-manager'); ?></th>
                    <th><?php esc_html_e('Actions', 'simple-translation-manager'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($coverage['by_language'] as $row): ?>
                <tr>
                    <td>
                        <?php echo esc_html($row['emoji']); ?>
                        <strong><?php echo esc_html($row['name']); ?></strong>
                        <code><?php echo esc_html($row['code']); ?></code>
                    </td>
                    <?php foreach (['title', 'content', 'strings'] as $field) :
                        $cell = $row[$field];
                        $pct  = $cell['pct'];
                        $bar_class = $pct >= 90 ? 'is-good' : ($pct >= 50 ? 'is-mid' : 'is-low');
                    ?>
                        <td>
                            <div class="stm-progress">
                                <div class="stm-progress-bar <?php echo esc_attr($bar_class); ?>" style="width: <?php echo esc_attr(min(100, $pct)); ?>%"></div>
                                <span class="stm-progress-label"><?php echo esc_html($pct); ?>%</span>
                            </div>
                            <small><?php echo esc_html(sprintf(
                                /* translators: 1: translated count, 2: total count */
                                __('%1$s / %2$s', 'simple-translation-manager'),
                                number_format_i18n($cell['translated']),
                                number_format_i18n($cell['total'])
                            )); ?></small>
                        </td>
                    <?php endforeach; ?>
                    <td>
                        <a class="button button-small"
                           href="<?php echo esc_url(add_query_arg(['tab' => 'missing', 'flang' => $row['code']], admin_url('admin.php?page=stm-dashboard'))); ?>">
                            <?php esc_html_e('View missing', 'simple-translation-manager'); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
