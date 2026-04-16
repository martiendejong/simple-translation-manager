<?php
/**
 * Admin Template: Translation Dashboard
 *
 * Variables available:
 * - $active_tab (string): overview|missing|recent
 * - $languages (array)
 * - $default_lang (object|null)
 * - $coverage (array|null): overview tab
 * - $filters (array|null), $missing (array|null): missing tab
 * - $recent (array|null): recent tab
 */
if (!defined('ABSPATH')) exit;

$base_url = admin_url('admin.php?page=stm-dashboard');
$tabs = [
    'overview' => __('Overview', 'simple-translation-manager'),
    'missing'  => __('Missing Translations', 'simple-translation-manager'),
    'recent'   => __('Recent Translations', 'simple-translation-manager'),
];
?>
<div class="wrap stm-dashboard">
    <h1><?php esc_html_e('Translation Dashboard', 'simple-translation-manager'); ?></h1>

    <nav class="nav-tab-wrapper">
        <?php foreach ($tabs as $slug => $label): ?>
            <a href="<?php echo esc_url(add_query_arg('tab', $slug, $base_url)); ?>"
               class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($active_tab === 'overview') : ?>
        <?php require __DIR__ . '/admin-dashboard-overview.php'; ?>
    <?php elseif ($active_tab === 'missing') : ?>
        <?php require __DIR__ . '/admin-dashboard-missing.php'; ?>
    <?php elseif ($active_tab === 'recent') : ?>
        <?php require __DIR__ . '/admin-dashboard-recent.php'; ?>
    <?php endif; ?>
</div>
