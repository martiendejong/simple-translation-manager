<?php
/**
 * Admin Template: Import/Export
 */
if (!defined('ABSPATH')) exit;

$languages = STM\Database::get_languages();

// Show feedback from import handler redirect
$imported   = isset($_GET['imported'])   ? intval($_GET['imported'])   : null;
$stm_errors = isset($_GET['stm_errors']) ? intval($_GET['stm_errors']) : 0;
$stm_error  = isset($_GET['stm_error'])  ? sanitize_text_field($_GET['stm_error']) : '';
?>

<div class="wrap">
    <h1>Import / Export</h1>

    <?php if ($imported !== null): ?>
        <div class="notice notice-success is-dismissible">
            <p>Import complete: <strong><?php echo esc_html($imported); ?></strong> strings imported<?php echo $stm_errors ? ' (' . esc_html($stm_errors) . ' skipped)' : ''; ?>.</p>
        </div>
    <?php endif; ?>

    <?php if ($stm_error): ?>
        <div class="notice notice-error is-dismissible">
            <p>Import failed: <?php echo esc_html(
                [
                    'no_file'      => 'No file uploaded.',
                    'invalid_type' => 'Only .json files are accepted.',
                    'invalid_json' => 'File does not contain valid JSON.',
                ][$stm_error] ?? $stm_error
            ); ?></p>
        </div>
    <?php endif; ?>

    <!-- Export -->
    <div class="card">
        <h2>Export</h2>
        <p>Download all string translations as a JSON file.</p>

        <form method="get" action="<?php echo esc_url(rest_url('stm/v1/export')); ?>" target="_blank">
            <input type="hidden" name="_wpnonce" value="<?php echo wp_create_nonce('wp_rest'); ?>">
            <table class="form-table">
                <tr>
                    <th><label for="export_lang">Language</label></th>
                    <td>
                        <select id="export_lang" name="lang">
                            <option value="">All languages</option>
                            <?php foreach ($languages as $lang): ?>
                                <option value="<?php echo esc_attr($lang->code); ?>">
                                    <?php echo esc_html($lang->flag_emoji . ' ' . $lang->native_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="export_context">Context</label></th>
                    <td>
                        <input type="text" id="export_context" name="context" placeholder="general" class="regular-text">
                        <p class="description">Leave empty to export all contexts.</p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" class="button button-primary">Download JSON</button>
            </p>
        </form>
    </div>

    <!-- Import: file upload -->
    <div class="card" style="margin-top: 20px;">
        <h2>Import — Upload JSON file</h2>
        <p>Accepted formats:</p>
        <ul style="list-style:disc;margin-left:20px;">
            <li><code>{"nl": {"nav.home": "Thuis", "nav.about": "Over ons"}, "en": {...}}</code></li>
            <li><code>{"lang": "nl", "translations": {"nav.home": "Thuis"}}</code></li>
        </ul>

        <form method="post"
              action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              enctype="multipart/form-data">
            <input type="hidden" name="action" value="stm_import_json">
            <?php wp_nonce_field('stm_import_json'); ?>

            <table class="form-table">
                <tr>
                    <th><label for="stm_import_file">JSON file</label></th>
                    <td>
                        <input type="file" id="stm_import_file" name="stm_import_file" accept=".json">
                        <p class="description">Max size: <?php echo esc_html(ini_get('upload_max_filesize')); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">Import</button>
            </p>
        </form>
    </div>

    <!-- Import: REST API reference -->
    <div class="card" style="margin-top: 20px;">
        <h2>Import — REST API</h2>
        <p>For bulk or automated imports use the REST endpoint directly:</p>
        <pre style="background:#f5f5f5;padding:15px;border-radius:4px;overflow:auto;">curl -X POST \
  -u "username:APP_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{"nl":{"nav.home":"Thuis","nav.about":"Over ons"}}' \
  <?php echo esc_url(rest_url('stm/v1/import')); ?></pre>

        <p style="margin-top:12px;">Or bulk-import post translations:</p>
        <pre style="background:#f5f5f5;padding:15px;border-radius:4px;overflow:auto;">curl -X POST \
  -u "username:APP_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{"lang":"nl","translations":{"123":{"title":"Titel","content":"&lt;p&gt;Inhoud&lt;/p&gt;"}}}' \
  <?php echo esc_url(rest_url('stm/v1/posts/bulk-translations')); ?></pre>
    </div>
</div>
