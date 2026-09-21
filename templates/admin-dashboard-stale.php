<?php
/**
 * Dashboard - Stale Translations tab
 *
 * @var array $stale
 */
if (!defined('ABSPATH')) exit;
?>
<div class="stm-dashboard-stale">
    <p class="description">
        <?php esc_html_e('Translations whose source content has changed since they were last saved. Nothing here is deleted or rewritten automatically — review and re-save each one.', 'simple-translation-manager'); ?>
    </p>

    <?php if (empty($stale)) : ?>
        <div class="notice notice-success inline">
            <p><?php esc_html_e('No stale translations detected.', 'simple-translation-manager'); ?></p>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Type', 'simple-translation-manager'); ?></th>
                    <th><?php esc_html_e('Language', 'simple-translation-manager'); ?></th>
                    <th><?php esc_html_e('Item', 'simple-translation-manager'); ?></th>
                    <th><?php esc_html_e('Field', 'simple-translation-manager'); ?></th>
                    <th><?php esc_html_e('Status', 'simple-translation-manager'); ?></th>
                    <th><?php esc_html_e('Last saved', 'simple-translation-manager'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($stale as $row): ?>
                <tr>
                    <td>
                        <?php echo $row['type'] === 'post'
                            ? esc_html__('Post', 'simple-translation-manager')
                            : esc_html__('Field value', 'simple-translation-manager'); ?>
                    </td>
                    <td><code><?php echo esc_html($row['language_code']); ?></code></td>
                    <td>
                        <?php if ($row['type'] === 'post'): ?>
                            <a href="<?php echo esc_url(admin_url('post.php?action=edit&post=' . $row['post_id'])); ?>">
                                <?php echo esc_html($row['post_title'] !== '' ? $row['post_title'] : '#' . $row['post_id']); ?>
                            </a>
                        <?php else: ?>
                            <?php echo esc_html($row['source_value']); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($row['field_name']); ?></td>
                    <td><span class="stm-badge stm-badge-stale"><?php esc_html_e('Stale', 'simple-translation-manager'); ?></span> <code><?php echo esc_html($row['status']); ?></code></td>
                    <td><?php echo esc_html($row['updated_at'] ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $row['updated_at']) : '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
