/**
 * Post Editor JavaScript
 *
 * Uses wp.editor.initialize() / wp.editor.remove() so TinyMCE is only active
 * for the visible tab — avoids the hidden-element init failure that breaks
 * wp_editor() when used inside display:none tab panels.
 */

(function($) {
    'use strict';

    var activeEditorLang = null;
    var initializedEditors = {};

    $(document).ready(function() {
        initTranslationTabs();

        // Boot the editor for whichever tab is active on load
        var $first = $('.stm-tab-button.active').first();
        if ($first.length) {
            var firstLang = $first.data('lang');
            activeEditorLang = firstLang;
            initEditor(firstLang);
        }
    });

    // Sync TinyMCE → textarea before the post form submits
    $('body').on('submit', '#post', function() {
        saveAllEditors();
    });

    // Also sync on the Gutenberg "Update" / "Publish" save path (fires before REST call)
    if (typeof wp !== 'undefined' && wp.data) {
        var lastSaving = false;
        wp.data.subscribe(function() {
            var editor = wp.data.select('core/editor');
            if (!editor) return;
            var isSaving = editor.isSavingPost();
            if (isSaving && !lastSaving) {
                saveAllEditors();
            }
            lastSaving = isSaving;
        });
    }

    function initTranslationTabs() {
        $('.stm-tab-button').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var newLang = $button.data('lang');

            if (newLang === activeEditorLang) return;

            // Save + remove current editor before hiding its tab
            if (activeEditorLang) {
                saveEditor(activeEditorLang);
                removeEditor(activeEditorLang);
            }

            // Switch tab UI
            $('.stm-tab-button').removeClass('active');
            $button.addClass('active');
            $('.stm-tab-content').removeClass('active');
            $('.stm-tab-content[data-lang="' + newLang + '"]').addClass('active');

            // Boot editor for newly visible tab
            activeEditorLang = newLang;
            initEditor(newLang);
        });
    }

    function initEditor(lang) {
        if (initializedEditors[lang]) return;

        var editorId = 'stm_content_' + lang;
        if (!document.getElementById(editorId)) return;

        if (typeof wp !== 'undefined' && wp.editor) {
            wp.editor.initialize(editorId, {
                tinymce: {
                    wpautop: true,
                    plugins: 'charmap colorpicker hr lists paste tabfocus textcolor fullscreen wordpress wpautoresize wpeditimage wpemoji wpgallery wplink wptextpattern',
                    toolbar1: 'formatselect,bold,italic,strikethrough,bullist,numlist,hr,alignleft,aligncenter,alignright,link,unlink,wp_more,spellchecker,wp_fullscreen',
                    toolbar2: 'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,wp_help',
                    setup: function(ed) {
                        // Ensure content is synced to textarea on every change
                        ed.on('change keyup', function() { ed.save(); });
                    },
                },
                quicktags: {
                    buttons: 'strong,em,link,block,del,ins,img,ul,ol,li,code,more,close',
                },
                mediaButtons: true,
            });
            initializedEditors[lang] = true;
        }
    }

    function saveEditor(lang) {
        var editorId = 'stm_content_' + lang;
        if (typeof tinymce !== 'undefined' && tinymce.get(editorId)) {
            tinymce.get(editorId).save();
        }
    }

    function saveAllEditors() {
        Object.keys(initializedEditors).forEach(function(lang) {
            saveEditor(lang);
        });
    }

    function removeEditor(lang) {
        saveEditor(lang); // always save before destroy
        var editorId = 'stm_content_' + lang;
        if (typeof wp !== 'undefined' && wp.editor && initializedEditors[lang]) {
            wp.editor.remove(editorId);
            delete initializedEditors[lang];
        }
    }

})(jQuery);
