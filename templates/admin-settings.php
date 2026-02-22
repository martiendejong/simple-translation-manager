<?php
/**
 * Admin Template: Settings
 */
if (!defined('ABSPATH')) exit;
?>

<div class="wrap">
    <h1>Translation Manager Settings</h1>

    <div class="card">
        <h2>REST API Endpoints</h2>
        <p>All endpoints support Application Password authentication (WordPress 5.6+).</p>

        <table class="widefat fixed" style="width: 100%; margin-top: 20px;">
            <thead>
                <tr>
                    <th style="width: 20%;">Endpoint</th>
                    <th style="width: 60%;">Description</th>
                    <th style="width: 20%;">Auth Required</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>GET /stm/v1/languages</code></td>
                    <td>List all languages</td>
                    <td>No</td>
                </tr>
                <tr>
                    <td><code>POST /stm/v1/languages</code></td>
                    <td>Create language</td>
                    <td>Yes</td>
                </tr>
                <tr>
                    <td><code>GET /stm/v1/strings</code></td>
                    <td>List strings</td>
                    <td>No</td>
                </tr>
                <tr>
                    <td><code>POST /stm/v1/strings</code></td>
                    <td>Create string</td>
                    <td>Yes</td>
                </tr>
                <tr>
                    <td><code>POST /stm/v1/translations</code></td>
                    <td>Create translation</td>
                    <td>Yes</td>
                </tr>
                <tr>
                    <td><code>POST /stm/v1/translations/bulk</code></td>
                    <td>Bulk create/update</td>
                    <td>Yes</td>
                </tr>
                <tr>
                    <td><code>GET /stm/v1/posts/{id}/slugs</code></td>
                    <td>Get post slugs per language</td>
                    <td>No</td>
                </tr>
                <tr>
                    <td><code>POST /stm/v1/posts/{id}/slugs</code></td>
                    <td>Save post slug per language</td>
                    <td>Yes</td>
                </tr>
                <tr>
                    <td><code>GET /stm/v1/export</code></td>
                    <td>Export as JSON</td>
                    <td>Yes</td>
                </tr>
            </tbody>
        </table>

        <h3 style="margin-top: 30px;">Generate Application Password</h3>
        <ol>
            <li>Go to <a href="<?php echo admin_url('profile.php'); ?>">Users → Profile</a></li>
            <li>Scroll to "Application Passwords"</li>
            <li>Create new password with name "Translation AI"</li>
            <li>Copy the password (shown only once)</li>
            <li>Use in API requests: <code>username:APPLICATION_PASSWORD</code></li>
        </ol>
    </div>

    <div class="card" style="margin-top: 20px;">
        <h2>Usage in Templates</h2>
        <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px;">
// Get translation
echo __stm('nav.home', 'Home');
echo __stm('footer.copyright', '© 2024', 'footer');

// Post field translation
echo stm_get_post_translation($post_id, 'title');
echo stm_get_post_translation($post_id, 'excerpt', 'nl');

// Language switcher
stm_language_switcher('dropdown');
stm_language_switcher('flags');
stm_language_switcher('list');

// Current language
$lang = stm_get_current_language();  // 'en', 'nl', etc.
        </pre>
    </div>

    <div class="card" style="margin-top: 20px;">
        <h2>Slug per Language (Per Page)</h2>
        <p>Je kunt per pagina kiezen tussen:</p>
        <ul>
            <li><strong>Shared slug</strong> (default WordPress): <code>/about</code> werkt voor alle talen</li>
            <li><strong>Language-specific slugs</strong>: <code>/en/about</code>, <code>/nl/over</code>, <code>/fr/a-propos</code></li>
        </ul>

        <p>Via API (per post instelbaar):</p>
        <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px;">
POST /wp-json/stm/v1/posts/123/slugs
{
  "language_code": "nl",
  "slug": "over-ons"
}

GET /wp-json/stm/v1/posts/123/slugs
→ {"en": "about-us", "nl": "over-ons"}
        </pre>
    </div>
</div>
