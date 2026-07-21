/**
 * Gutenberg Document Settings panel for STM translations.
 *
 * Registers a PluginDocumentSettingPanel showing per-language translation
 * status, with a jump-to-tab link into the classic meta box rendered below
 * the block editor (the meta box itself still handles all field editing —
 * this panel is a status/navigation surface, not a duplicate editor) plus a
 * Preview link per language that opens the live front-end rendering in a new
 * tab (the same "Preview in language" cycler the meta box exposes).
 */

(function(wp) {
    'use strict';

    if (!wp || !wp.plugins || !wp.element || !wp.components) {
        return;
    }

    var registerPlugin = wp.plugins.registerPlugin;
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var PanelRow = wp.components.PanelRow;
    var Button = wp.components.Button;

    // WP >= 5.8 moved PluginDocumentSettingPanel to wp-editor; wp-edit-post
    // still re-exports it for back-compat. Prefer wp-editor, fall back.
    var PluginDocumentSettingPanel =
        (wp.editor && wp.editor.PluginDocumentSettingPanel) ||
        (wp.editPost && wp.editPost.PluginDocumentSettingPanel);

    if (!PluginDocumentSettingPanel) {
        return;
    }

    var config = (typeof window.stmGutenberg !== 'undefined') ? window.stmGutenberg : {};
    var languages = config.languages || [];

    function statusLabel(status) {
        if (status === 'complete') return config.i18n && config.i18n.complete || 'Complete';
        if (status === 'partial') return config.i18n && config.i18n.partial || 'Partial';
        return config.i18n && config.i18n.empty || 'Not translated';
    }

    function jumpToTab(lang) {
        var $tabButton = window.jQuery && window.jQuery('.stm-tab-button[data-lang="' + lang + '"]');
        if ($tabButton && $tabButton.length) {
            $tabButton[0].click();
            $tabButton[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function openPreview(url) {
        if (url) {
            window.open(url, '_blank', 'noopener');
        }
    }

    function TranslationsPanel() {
        if (!languages.length) {
            return el(
                PluginDocumentSettingPanel,
                { name: 'stm-translations-panel', title: config.i18n && config.i18n.title || 'Translations', className: 'stm-gutenberg-panel' },
                el('p', {}, config.i18n && config.i18n.none || 'No other languages configured.')
            );
        }

        return el(
            PluginDocumentSettingPanel,
            { name: 'stm-translations-panel', title: config.i18n && config.i18n.title || 'Translations', className: 'stm-gutenberg-panel' },
            languages.map(function(lang) {
                return el(
                    PanelRow,
                    { key: lang.code },
                    el(Fragment, {},
                        el('span', {}, (lang.flag_emoji ? lang.flag_emoji + ' ' : '') + lang.name + ' — ' + statusLabel(lang.status)),
                        el(Button, {
                            variant: 'link',
                            onClick: function() { jumpToTab(lang.code); },
                        }, config.i18n && config.i18n.edit || 'Edit'),
                        lang.previewUrl ? el(Button, {
                            variant: 'link',
                            onClick: function() { openPreview(lang.previewUrl); },
                        }, config.i18n && config.i18n.preview || 'Preview') : null
                    )
                );
            })
        );
    }

    registerPlugin('stm-translations', { render: TranslationsPanel, icon: 'translation' });

})(window.wp);
