<?php
/**
 * Admin Template: Documentation
 */
if (!defined('ABSPATH')) exit;
?>

<div class="wrap">
    <h1>Documentation</h1>
    <p>Guides for content editors and administrators who publish or maintain multilingual content with Translation Manager. No coding knowledge required.</p>

    <div class="card" style="max-width: 600px;">
        <h2>Editor's Guide</h2>
        <p>
            Basic concepts (translation groups, language detection, URL structure),
            step-by-step post/page translation, translating ACF/Pods/Elementor content,
            and best practices for when to create translations.
        </p>
        <p>
            <a class="button button-primary" href="<?php echo esc_url(STM_PLUGIN_URL . 'docs/editors/pdf/editors-guide.pdf'); ?>" target="_blank" rel="noopener">
                Download Editor's Guide (PDF)
            </a>
        </p>
    </div>

    <div class="card" style="margin-top: 20px; max-width: 600px;">
        <h2>Troubleshooting</h2>
        <p>
            Solutions for the most common problems: missing translations, the wrong
            language being shown, and cache issues.
        </p>
        <p>
            <a class="button button-primary" href="<?php echo esc_url(STM_PLUGIN_URL . 'docs/editors/pdf/troubleshooting.pdf'); ?>" target="_blank" rel="noopener">
                Download Troubleshooting Guide (PDF)
            </a>
        </p>
    </div>
</div>
