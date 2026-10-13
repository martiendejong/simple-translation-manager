<?php
/**
 * Meta Box Template: Pods Field Translations
 *
 * @var WP_Post $post
 * @var array $languages     All configured STM languages (Language objects)
 * @var string $current_lang This post's own language
 * @var array $fields        {field_name: {label, type}} — translatable Pods fields
 * @var array $translations  {lang_code: {field_name: translation}}
 */

if (!defined('ABSPATH')) exit;
?>

<div class="stm-pods-translations" data-current-lang="<?php echo esc_attr($current_lang); ?>">

    <?php if (count($languages) <= 1): ?>

        <p class="description">
            <strong>No other languages available.</strong>
            Go to <a href="<?php echo esc_url(admin_url('admin.php?page=stm-languages')); ?>">Languages</a> to add more.
        </p>

    <?php else: ?>

        <div class="stm-tabs" role="tablist">
            <?php $first = true; ?>
            <?php foreach ($languages as $lang): ?>
                <?php if ($lang->code === $current_lang) continue; ?>
                <button type="button"
                        class="stm-tab-button <?php echo esc_attr($first ? 'active' : ''); ?>"
                        role="tab"
                        aria-selected="<?php echo esc_attr($first ? 'true' : 'false'); ?>"
                        aria-controls="stm-pods-tab-panel-<?php echo esc_attr($lang->code); ?>"
                        data-lang="<?php echo esc_attr($lang->code); ?>">
                    <?php echo esc_html($lang->flag_emoji . ' ' . $lang->name); ?>
                </button>
                <?php $first = false; ?>
            <?php endforeach; ?>
        </div>

        <?php $first = true; ?>
        <?php foreach ($languages as $lang): ?>
            <?php if ($lang->code === $current_lang) continue; ?>

            <?php $translation = $translations[$lang->code] ?? []; ?>

            <div class="stm-tab-content <?php echo esc_attr($first ? 'active' : ''); ?>"
                 id="stm-pods-tab-panel-<?php echo esc_attr($lang->code); ?>"
                 role="tabpanel"
                 data-lang="<?php echo esc_attr($lang->code); ?>">

                <table class="form-table">
                    <?php foreach ($fields as $field_name => $field): ?>
                        <?php $value = $translation[$field_name] ?? ''; ?>
                        <tr>
                            <th scope="row">
                                <label for="stm_pods_<?php echo esc_attr($lang->code . '_' . $field_name); ?>">
                                    <?php echo esc_html($field['label']); ?> (<?php echo esc_html($lang->name); ?>)
                                </label>
                            </th>
                            <td>
                                <?php if (in_array($field['type'], ['paragraph', 'wysiwyg', 'html', 'code'], true)): ?>
                                    <textarea name="stm_pods_translations[<?php echo esc_attr($lang->code); ?>][<?php echo esc_attr($field_name); ?>]"
                                              id="stm_pods_<?php echo esc_attr($lang->code . '_' . $field_name); ?>"
                                              rows="6"
                                              class="widefat stm-translation-field"
                                              data-field="<?php echo esc_attr($field_name); ?>"><?php echo esc_textarea($value); ?></textarea>
                                <?php else: ?>
                                    <input type="text"
                                           name="stm_pods_translations[<?php echo esc_attr($lang->code); ?>][<?php echo esc_attr($field_name); ?>]"
                                           id="stm_pods_<?php echo esc_attr($lang->code . '_' . $field_name); ?>"
                                           value="<?php echo esc_attr($value); ?>"
                                           class="widefat stm-translation-field"
                                           data-field="<?php echo esc_attr($field_name); ?>">
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

            </div>

            <?php $first = false; ?>
        <?php endforeach; ?>

    <?php endif; ?>

</div>
