<?php
/**
 * Admin Template: Import/Export
 */
if (!defined('ABSPATH')) exit;

$languages = STM\Database::get_languages();
?>

<div class="wrap">
    <h1>Import/Export</h1>

    <div class="card">
        <h2>Export Translations</h2>
        <p>Download translations as JSON for backup or version control.</p>

        <form method="get" action="<?php echo rest_url('stm/v1/export'); ?>" target="_blank">
            <table class="form-table">
                <tr>
                    <th><label for="export_lang">Language</label></th>
                    <td>
                        <select id="export_lang" name="lang">
                            <option value="">All languages</option>
                            <?php foreach ($languages as $lang): ?>
                                <option value="<?php echo esc_attr($lang->code); ?>">
                                    <?php echo esc_html($lang->native_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="export_context">Context</label></th>
                    <td>
                        <input type="text" id="export_context" name="context" placeholder="general" class="regular-text">
                        <p class="description">Leave empty for all contexts</p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">Download JSON</button>
            </p>
        </form>
    </div>

    <div class="card" style="margin-top: 20px;">
        <h2>Import Translations</h2>
        <p>Upload JSON file or use the REST API for bulk imports.</p>

        <h3>Via REST API (Recommended)</h3>
        <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px;">
POST /wp-json/stm/v1/translations/bulk
[
  {"key": "nav.home", "lang": "nl", "translation": "Home", "context": "general"},
  {"key": "nav.about", "lang": "nl", "translation": "Over", "context": "general"}
]
        </pre>
    </div>

    <div class="card" style="margin-top: 20px;">
        <h2>Migration from Existing Theme</h2>
        <p>If you have existing JSON files (like martiendejong theme), use this:</p>

        <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px;">
# Convert nl.json to STM format
python convert-theme-translations.py \
  --input themes/martiendejong/languages/nl.json \
  --lang nl \
  --context general \
  --output stm-import.json

# Import via API
curl -X POST \
  -u "username:APP_PASSWORD" \
  -H "Content-Type: application/json" \
  -d @stm-import.json \
  https://martiendejong.nl/wp-json/stm/v1/translations/bulk
        </pre>
    </div>
</div>
