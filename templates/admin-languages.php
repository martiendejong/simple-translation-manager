<?php
/**
 * Admin Template: Languages
 */
if (!defined('ABSPATH')) exit;

$added       = isset($_GET['stm_added']);
$reactivated = isset($_GET['stm_reactivated']);
$deleted     = isset($_GET['stm_deleted']);
$toggled     = isset($_GET['stm_toggled']);
$error       = isset($_GET['stm_error']) ? sanitize_text_field($_GET['stm_error']) : '';
$error_messages = [
    'invalid_fields'              => 'Invalid language code or name.',
    'db_error'                    => 'Database error — language may already exist.',
    'already_active'              => 'That language code is already active — nothing to add.',
    'cannot_delete_default'       => 'Cannot delete the default language. Set another language as default first.',
    'cannot_deactivate_default'   => 'Cannot deactivate the default language. Set another language as default first.',
    'not_found'                   => 'Language not found.',
];
?>

<div class="wrap">
    <h1>Languages</h1>

    <?php if ($added): ?>
        <div class="notice notice-success is-dismissible"><p>Language added.</p></div>
    <?php endif; ?>
    <?php if ($reactivated): ?>
        <div class="notice notice-success is-dismissible"><p>Language reactivated — it was already registered but inactive, so the existing entry (and its translations) was reactivated instead of adding a duplicate.</p></div>
    <?php endif; ?>
    <?php if ($deleted): ?>
        <div class="notice notice-success is-dismissible"><p>Language deleted.</p></div>
    <?php endif; ?>
    <?php if ($toggled): ?>
        <div class="notice notice-success is-dismissible"><p>Language status updated.</p></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($error_messages[$error] ?? $error); ?></p></div>
    <?php endif; ?>

    <!-- Language list -->
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:7%;">Code</th>
                <th style="width:16%;">Name</th>
                <th style="width:16%;">Native Name</th>
                <th style="width:6%;">Flag</th>
                <th style="width:7%;">Default</th>
                <th style="width:7%;">Active</th>
                <th style="width:7%;">Order</th>
                <th style="width:34%;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($languages as $lang): ?>
                <tr data-lang-id="<?php echo esc_attr($lang->id); ?>">
                    <td><code><?php echo esc_html($lang->code); ?></code></td>
                    <td><?php echo esc_html($lang->name); ?></td>
                    <td><?php echo esc_html($lang->native_name); ?></td>
                    <td><?php echo esc_html($lang->flag_emoji); ?></td>
                    <td>
                        <?php echo wp_kses_post($lang->is_default ? '<strong>✓ default</strong>' : ''); ?>
                    </td>

                    <td>
                        <button type="button"
                                class="button button-small stm-lang-toggle-active"
                                data-lang-id="<?php echo esc_attr($lang->id); ?>"
                                data-is-active="<?php echo (int) $lang->is_active; ?>"
                                <?php echo $lang->is_default
                                    ? 'disabled title="' . esc_attr__('Default language is always active', 'stm') . '"'
                                    : ''; ?>>
                            <?php echo esc_html($lang->is_active ? 'Active' : 'Inactive'); ?>
                        </button>
                    </td>

                    <td>
                        <button type="button"
                                class="button button-small stm-lang-move"
                                data-lang-id="<?php echo esc_attr($lang->id); ?>"
                                data-direction="up"
                                data-order="<?php echo esc_attr($lang->order_index); ?>"
                                title="<?php echo esc_attr__('Move up', 'stm'); ?>">
                            ↑
                        </button>

                        <button type="button"
                                class="button button-small stm-lang-move"
                                data-lang-id="<?php echo esc_attr($lang->id); ?>"
                                data-direction="down"
                                data-order="<?php echo esc_attr($lang->order_index); ?>"
                                title="<?php echo esc_attr__('Move down', 'stm'); ?>">
                            ↓
                        </button>

                        <?php echo esc_html($lang->order_index); ?>
                    </td>

                    <td>
                        <button type="button"
                                class="button button-small stm-lang-edit"
                                data-lang-id="<?php echo esc_attr($lang->id); ?>">
                            <?php echo esc_html__('Edit', 'stm'); ?>
                        </button>
                        <?php if (!$lang->is_default): ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                  style="display:inline;"
                                  onsubmit="return confirm('Delete <?php echo esc_js($lang->name); ?> and all its translations?')">
                                <input type="hidden" name="action" value="stm_delete_language">
                                <input type="hidden" name="lang_code" value="<?php echo esc_attr($lang->code); ?>">
                                <?php wp_nonce_field('stm_delete_language'); ?>
                                <button type="submit" class="button button-small button-link-delete">Delete</button>
                            </form>
                        <?php else: ?>
                            <em style="color:#999;font-size:12px;">default</em>
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- Inline edit row (hidden by default) -->
                <tr class="stm-lang-edit-row" data-lang-id="<?php echo esc_attr($lang->id); ?>" style="display:none;background:#f9f9f9;">
                    <td colspan="8" style="padding:16px;">
                        <strong>Edit: <?php echo esc_html($lang->name); ?></strong>
                        <table class="form-table" style="margin-top:10px;">
                            <tr>
                                <th style="width:150px;"><label>Name</label></th>
                                <td><input type="text" name="name" class="regular-text" value="<?php echo esc_attr($lang->name); ?>"></td>
                            </tr>
                            <tr>
                                <th><label>Native Name</label></th>
                                <td><input type="text" name="native_name" class="regular-text" value="<?php echo esc_attr($lang->native_name); ?>"></td>
                            </tr>
                            <tr>
                                <th><label>Flag Emoji</label></th>
                                <td><input type="text" name="flag_emoji" class="small-text" value="<?php echo esc_attr($lang->flag_emoji); ?>" maxlength="10"></td>
                            </tr>
                            <tr>
                                <th><label>Sort Order</label></th>
                                <td><input type="number" name="order_index" class="small-text" value="<?php echo esc_attr($lang->order_index); ?>" min="0" max="999"></td>
                            </tr>
                        </table>
                        <p style="margin-top:10px;">
                            <button type="button" class="button button-primary stm-lang-edit-save">Save</button>
                            <button type="button" class="button stm-lang-edit-cancel" style="margin-left:6px;">Cancel</button>
                        </p>
                    </td>
                </tr>

            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Add language form -->
    <div class="card" style="margin-top: 30px; max-width: 600px;">
        <h2>Add Language</h2>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="stm_add_language">
            <?php wp_nonce_field('stm_add_language'); ?>

            <table class="form-table">
                <tr>
                    <th><label for="lang_code">Code <span style="color:red">*</span></label></th>
                    <td>
                        <input type="text" id="lang_code" name="lang_code" class="small-text"
                               maxlength="3" placeholder="nl" required>
                        <p class="description">2–3 lowercase letters (ISO 639-1/2)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lang_name">Name <span style="color:red">*</span></label></th>
                    <td>
                        <input type="text" id="lang_name" name="lang_name" class="regular-text"
                               placeholder="Dutch" required>
                    </td>
                </tr>
                <tr>
                    <th><label for="lang_native">Native Name</label></th>
                    <td>
                        <input type="text" id="lang_native" name="lang_native" class="regular-text"
                               placeholder="Nederlands">
                        <p class="description">Leave empty to use Name</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lang_flag">Flag Emoji</label></th>
                    <td>
                        <input type="text" id="lang_flag" name="lang_flag" class="small-text"
                               placeholder="🇳🇱" maxlength="10">
                    </td>
                </tr>
                <tr>
                    <th><label for="lang_order">Sort Order</label></th>
                    <td>
                        <input type="number" id="lang_order" name="lang_order" class="small-text"
                               value="99" min="0" max="999">
                    </td>
                </tr>
                <tr>
                    <th>Default</th>
                    <td>
                        <label>
                            <input type="checkbox" name="lang_default" value="1">
                            Make this the default language
                        </label>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">Add Language</button>
            </p>
        </form>
    </div>
</div>
