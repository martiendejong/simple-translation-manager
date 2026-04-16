<?php
/**
 * Meta Box Template: Post Translations
 *
 * @var WP_Post $post
 * @var array $languages
 * @var string $current_lang
 * @var string $translation_group
 * @var array $translations
 */

if (!defined('ABSPATH')) exit;
?>

<div class="stm-post-translations">

    <!-- Current Post Language -->
    <div class="stm-current-language">
        <p>
            <strong>This post is written in:</strong>
            <select name="stm_post_language" id="stm_post_language">
                <?php foreach ($languages as $lang): ?>
                    <option value="<?php echo esc_attr($lang->code); ?>"
                            <?php selected($current_lang, $lang->code); ?>>
                        <?php echo esc_html($lang->flag_emoji . ' ' . $lang->name . ' (' . $lang->code . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
    </div>

    <hr style="margin: 20px 0;">

    <!-- Translation Tabs -->
    <div class="stm-translation-tabs">
        <h3>Translations</h3>
        <p class="description">Add translations for this post in other languages.</p>

        <div class="stm-tabs">
            <?php $first = true; ?>
            <?php foreach ($languages as $lang): ?>
                <?php if ($lang->code === $current_lang) continue; ?>

                <button type="button" class="stm-tab-button <?php echo $first ? 'active' : ''; ?>"
                        data-lang="<?php echo esc_attr($lang->code); ?>">
                    <?php echo esc_html($lang->flag_emoji . ' ' . $lang->name); ?>
                </button>

                <?php $first = false; ?>
            <?php endforeach; ?>
        </div>

        <?php $first = true; ?>
        <?php foreach ($languages as $lang): ?>
            <?php if ($lang->code === $current_lang) continue; ?>

            <?php
            $translation = $translations[$lang->code] ?? [];
            $title = $translation['post_title'] ?? '';
            $slug = $translation['post_name'] ?? '';
            $excerpt = $translation['post_excerpt'] ?? '';
            $content = $translation['post_content'] ?? '';
            ?>

            <div class="stm-tab-content <?php echo $first ? 'active' : ''; ?>"
                 data-lang="<?php echo esc_attr($lang->code); ?>">

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="stm_title_<?php echo esc_attr($lang->code); ?>">
                                Title (<?php echo esc_html($lang->name); ?>)
                            </label>
                        </th>
                        <td>
                            <input type="text"
                                   name="stm_translations[<?php echo esc_attr($lang->code); ?>][post_title]"
                                   id="stm_title_<?php echo esc_attr($lang->code); ?>"
                                   value="<?php echo esc_attr($title); ?>"
                                   class="widefat">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="stm_slug_<?php echo esc_attr($lang->code); ?>">
                                Slug (<?php echo esc_html($lang->name); ?>)
                            </label>
                        </th>
                        <td>
                            <input type="text"
                                   name="stm_translations[<?php echo esc_attr($lang->code); ?>][post_name]"
                                   id="stm_slug_<?php echo esc_attr($lang->code); ?>"
                                   value="<?php echo esc_attr($slug); ?>"
                                   class="widefat">
                            <p class="description">The URL slug for this translation (e.g., mijn-blog-post)</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="stm_excerpt_<?php echo esc_attr($lang->code); ?>">
                                Excerpt (<?php echo esc_html($lang->name); ?>)
                            </label>
                        </th>
                        <td>
                            <textarea name="stm_translations[<?php echo esc_attr($lang->code); ?>][post_excerpt]"
                                      id="stm_excerpt_<?php echo esc_attr($lang->code); ?>"
                                      rows="4"
                                      class="widefat"><?php echo esc_textarea($excerpt); ?></textarea>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="stm_content_<?php echo esc_attr($lang->code); ?>">
                                Content (<?php echo esc_html($lang->name); ?>)
                            </label>
                        </th>
                        <td>
                            <textarea
                                name="stm_translations[<?php echo esc_attr($lang->code); ?>][post_content]"
                                id="stm_content_<?php echo esc_attr($lang->code); ?>"
                                class="stm-editor-area"
                                rows="20"
                                style="width:100%;display:block;"
                            ><?php echo esc_textarea($content); ?></textarea>
                        </td>
                    </tr>
                </table>

            </div>

            <?php $first = false; ?>
        <?php endforeach; ?>

        <?php if (count($languages) === 1 || (count($languages) === 2 && $current_lang)): ?>
            <p class="description" style="margin-top: 20px;">
                <strong>No other languages available.</strong>
                Go to <a href="<?php echo admin_url('admin.php?page=stm-languages'); ?>">Languages</a> to add more.
            </p>
        <?php endif; ?>
    </div>

</div>
