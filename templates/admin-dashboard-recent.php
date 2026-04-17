<?php
/**
 * Dashboard - Recent Translations tab
 *
 * @var array $recent
 */
if (!defined('ABSPATH')) exit;
?>
<div class="stm-dashboard-recent">
    <p class="description">
        <?php esc_html_e('Most recent translation changes across posts and UI strings.', 'simple-translation-manager'); ?>
    </p>

    <?php if (empty($recent)) : ?>
        <div class="notice notice-info inline">
            <p><?php esc_html_e('No translations recorded yet.', 'simple-translation-manager'); ?></p>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('When', 'simple-translation-manager'); ?></th>
                    <th><?php esc_html_e('Type', 'simple-translation-manager'); ?></th>
                    <th><?php esc_html_e('Language', 'simple-translation-manager'); ?></th>
                    <th><?php esc_html_e('Item', 'simple-translation-manager'); ?></th>
                    <th><?php esc_html_e('Field', 'simple-translation-manager'); ?></th>
                    <th><?php esc_html_e('By', 'simple-translation-manager'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recent as $row): ?>
                <tr>
                    <td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $row->updated_at)); ?></td>
                    <td>
                        <?php echo $row->kind === 'post'
                            ? esc_html__('Post', 'simple-translation-manager')
                            : esc_html__('UI string', 'simple-translation-manager'); ?>
                    </td>
                    <td><code><?php echo esc_html($row->language_code); ?></code></td>
                    <td>
                        <?php if ($row->kind === 'post'): ?>
                            <?php if ($row->post_id): ?>
                                <a href="<?php echo esc_url(admin_url('post.php?action=edit&post=' . $row->post_id)); ?>">
                                    <?php echo esc_html($row->post_title ?: '#' . $row->post_id); ?>
                                </a>
                                <small>(<?php echo esc_html($row->post_type); ?>)</small>
                            <?php else: ?>
                                <em><?php esc_html_e('(deleted post)', 'simple-translation-manager'); ?></em>
                            <?php endif; ?>
                        <?php else: ?>
                            <code><?php echo esc_html($row->string_key ?: '—'); ?></code>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($row->field_name ?: '—'); ?></td>
                    <td>
                        <?php
                        if (!empty($row->user_id)) {
                            $user = get_userdata($row->user_id);
                            echo $user ? esc_html($user->display_name) : '—';
                        } else {
                            echo '—';
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
