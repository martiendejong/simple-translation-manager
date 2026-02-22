<?php
/**
 * Admin Template: Languages
 */
if (!defined('ABSPATH')) exit;
?>

<div class="wrap">
    <h1>Languages</h1>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 10%;">Code</th>
                <th style="width: 20%;">Name</th>
                <th style="width: 20%;">Native Name</th>
                <th style="width: 5%;">Flag</th>
                <th style="width: 10%;">Default</th>
                <th style="width: 10%;">Active</th>
                <th style="width: 10%;">Order</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($languages as $lang): ?>
                <tr>
                    <td><code><?php echo esc_html($lang->code); ?></code></td>
                    <td><?php echo esc_html($lang->name); ?></td>
                    <td><?php echo esc_html($lang->native_name); ?></td>
                    <td><?php echo esc_html($lang->flag_emoji); ?></td>
                    <td><?php echo $lang->is_default ? '✓' : ''; ?></td>
                    <td><?php echo $lang->is_active ? '✓' : ''; ?></td>
                    <td><?php echo esc_html($lang->order_index); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2 style="margin-top: 40px;">Add Language via REST API</h2>
    <p>Use the REST API to add languages:</p>
    <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px;">
POST /wp-json/stm/v1/languages
{
  "code": "fr",
  "name": "French",
  "native_name": "Français",
  "flag_emoji": "🇫🇷",
  "is_default": 0,
  "is_active": 1,
  "order_index": 3
}
    </pre>
</div>
