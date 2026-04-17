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
    var initializedEditors = {}; // lang → true once TinyMCE 'init' fires
    var pendingEditors = {};     // lang → true while wp.editor.initialize() is in flight
    var config = (typeof window.stmPostEditor !== 'undefined') ? window.stmPostEditor : {};
    var i18n = config.i18n || {};
    var toastTimer = null;

    $(document).ready(function() {
        initTranslationTabs();
        initAutoTranslateButtons();

        // Boot the editor for whichever tab is active on load
        var $first = $('.stm-tab-button.active').first();
        if ($first.length) {
            var firstLang = $first.data('lang');
            activeEditorLang = firstLang;
            initEditor(firstLang);
        }
    });

    // Sync TinyMCE → textarea before the post form submits, then flash the toast
    $('body').on('submit', '#post', function() {
        saveAllEditors();
        // Defer toast until after form roundtrip
        sessionStorage.setItem('stm_show_save_toast', '1');
    });

    // Restore flash after classic-editor reload
    if (sessionStorage.getItem('stm_show_save_toast') === '1') {
        sessionStorage.removeItem('stm_show_save_toast');
        $(function() { showSaveToast(i18n.saved || 'Translations saved'); });
    }

    // Also sync on the Gutenberg "Update" / "Publish" save path (fires before REST call)
    if (typeof wp !== 'undefined' && wp.data) {
        var lastSaving = false;
        var gutenbergEditor = wp.data.select('core/editor'); // cache — subscribe fires on every state mutation
        if (gutenbergEditor) {
            wp.data.subscribe(function() {
                var isSaving = gutenbergEditor.isSavingPost();
                var isAutosaving = gutenbergEditor.isAutosavingPost && gutenbergEditor.isAutosavingPost();
                if (isSaving && !lastSaving && !isAutosaving) {
                    saveAllEditors();
                }
                // Trigger toast when save transitions from in-flight → done
                if (!isSaving && lastSaving && !isAutosaving) {
                    showSaveToast(i18n.saved || 'Translations saved');
                }
                lastSaving = isSaving;
            });
        }
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
            $('.stm-tab-button')
                .removeClass('active')
                .attr('aria-selected', 'false');
            $button
                .addClass('active')
                .attr('aria-selected', 'true');
            $('.stm-tab-content').removeClass('active');
            $('.stm-tab-content[data-lang="' + newLang + '"]').addClass('active');

            // Boot editor for newly visible tab
            activeEditorLang = newLang;
            initEditor(newLang);
        });
    }

    function initEditor(lang) {
        if (initializedEditors[lang] || pendingEditors[lang]) return;

        var editorId = 'stm_content_' + lang;
        if (!document.getElementById(editorId)) return;

        if (typeof wp !== 'undefined' && wp.editor) {
            pendingEditors[lang] = true;
            wp.editor.initialize(editorId, {
                tinymce: {
                    wpautop: true,
                    plugins: 'charmap colorpicker hr lists paste tabfocus textcolor fullscreen wordpress wpautoresize wpeditimage wpemoji wpgallery wplink wptextpattern',
                    toolbar1: 'formatselect,bold,italic,bullist,numlist,hr,alignleft,aligncenter,alignright,link,unlink,wp_more,spellchecker,wp_fullscreen',
                    toolbar2: 'forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,wp_help',
                    setup: function(ed) {
                        ed.on('init', function() {
                            initializedEditors[lang] = true;
                            delete pendingEditors[lang];
                        });
                        ed.on('change keyup', function() { ed.save(); });
                    },
                },
                quicktags: {
                    buttons: 'strong,em,link,block,del,ins,img,ul,ol,li,code,more,close',
                },
                mediaButtons: true,
            });
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
        if (typeof wp !== 'undefined' && wp.editor) {
            if (initializedEditors[lang] || pendingEditors[lang]) {
                wp.editor.remove(editorId);
            }
        }
        delete initializedEditors[lang];
        delete pendingEditors[lang];
    }

    // -----------------------------------------------------------------------
    // Save confirmation toast
    // -----------------------------------------------------------------------

    function showSaveToast(message) {
        var $toast = $('.stm-save-toast').first();
        if (!$toast.length) return;

        $toast.find('.stm-save-toast-text').text(message);
        $toast.removeAttr('hidden').addClass('is-visible');

        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(function() {
            $toast.removeClass('is-visible');
            // Hide from AT once fade completes
            setTimeout(function() { $toast.attr('hidden', true); }, 350);
        }, 2200);
    }

    // -----------------------------------------------------------------------
    // Auto-translate
    // -----------------------------------------------------------------------

    function initAutoTranslateButtons() {
        $('.stm-auto-translate-btn').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            handleAutoTranslate($btn);
        });
    }

    function handleAutoTranslate($btn) {
        var targetLang = $btn.data('lang');
        var sourceLang = $btn.data('source-lang') || config.sourceLang || 'en';

        if (!config.restUrl) {
            setStatus($btn, i18n.translateFailed || 'Auto-translate failed', 'error');
            return;
        }

        var sourceFields = collectSourceFields();
        var hasContent = Object.keys(sourceFields).some(function(k) { return sourceFields[k].length > 0; });
        if (!hasContent) {
            setStatus($btn, i18n.nothingToTranslate || 'Nothing to translate', 'error');
            return;
        }

        if (hasExistingTranslations(targetLang)) {
            var msg = i18n.overwriteConfirm || 'Overwrite existing translations?';
            if (!window.confirm(msg)) return;
        }

        var $status = $btn.siblings('.stm-auto-translate-status');
        $btn.prop('disabled', true).addClass('is-loading');
        setStatus($btn, i18n.translating || 'Translating…', 'loading');

        var fields = ['post_title', 'post_name', 'post_excerpt', 'post_content'];
        var promises = fields.map(function(field) {
            var text = sourceFields[field] || '';
            if (!text) return $.Deferred().resolve({ field: field, success: true, translation: '' }).promise();
            return translateField(field, text, sourceLang, targetLang);
        });

        $.when.apply($, promises).done(function() {
            var results = (promises.length === 1) ? [arguments[0]] : Array.prototype.slice.call(arguments);
            var anyFailed = false;
            results.forEach(function(r) {
                if (!r) return;
                if (r.success && typeof r.translation === 'string' && r.translation.length > 0) {
                    applyTranslation(targetLang, r.field, r.translation);
                } else if (r.success === false && (sourceFields[r.field] || '').length > 0) {
                    anyFailed = true;
                }
            });

            $btn.prop('disabled', false).removeClass('is-loading');
            if (anyFailed) {
                setStatus($btn, i18n.translateFailed || 'Auto-translate failed', 'error');
            } else {
                setStatus($btn, i18n.translated || 'Translation complete', 'success');
            }
        }).fail(function() {
            $btn.prop('disabled', false).removeClass('is-loading');
            setStatus($btn, i18n.translateFailed || 'Auto-translate failed', 'error');
        });
    }

    function collectSourceFields() {
        var sourceTitle = '';
        var sourceSlug = '';
        var sourceExcerpt = '';
        var sourceContent = '';

        // Classic editor source
        sourceTitle = $('#title').val() || '';
        sourceSlug = $('#editable-post-name-full').text() || $('#post_name').val() || '';
        sourceExcerpt = $('#excerpt').val() || '';
        if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
            sourceContent = tinymce.get('content').getContent();
        } else {
            sourceContent = $('#content').val() || '';
        }

        // Gutenberg source — overrides classic if present
        if (typeof wp !== 'undefined' && wp.data) {
            var ed = wp.data.select('core/editor');
            if (ed) {
                var attrTitle = ed.getEditedPostAttribute('title');
                var attrSlug = ed.getEditedPostAttribute('slug');
                var attrExcerpt = ed.getEditedPostAttribute('excerpt');
                var attrContent = ed.getEditedPostAttribute('content');
                if (typeof attrTitle === 'string') sourceTitle = attrTitle;
                if (typeof attrSlug === 'string' && attrSlug) sourceSlug = attrSlug;
                if (typeof attrExcerpt === 'string') sourceExcerpt = attrExcerpt;
                if (typeof attrContent === 'string') sourceContent = attrContent;
            }
        }

        return {
            post_title: sourceTitle,
            post_name: sourceSlug,
            post_excerpt: sourceExcerpt,
            post_content: sourceContent,
        };
    }

    function hasExistingTranslations(lang) {
        var fields = ['post_title', 'post_name', 'post_excerpt', 'post_content'];
        return fields.some(function(field) {
            var $field = $('.stm-tab-content[data-lang="' + lang + '"] .stm-translation-field[data-field="' + field + '"]');
            if (!$field.length) return false;
            if (field === 'post_content') {
                var editorId = 'stm_content_' + lang;
                if (typeof tinymce !== 'undefined' && tinymce.get(editorId)) {
                    return tinymce.get(editorId).getContent().trim().length > 0;
                }
            }
            return ($field.val() || '').trim().length > 0;
        });
    }

    function translateField(field, text, sourceLang, targetLang) {
        var deferred = $.Deferred();

        $.ajax({
            url: config.restUrl,
            method: 'POST',
            contentType: 'application/json',
            beforeSend: function(xhr) {
                if (config.restNonce) xhr.setRequestHeader('X-WP-Nonce', config.restNonce);
            },
            data: JSON.stringify({
                text: text,
                source_lang: sourceLang,
                target_lang: targetLang,
                context: field,
            }),
        }).done(function(resp) {
            deferred.resolve({
                field: field,
                success: !!(resp && resp.success),
                translation: (resp && resp.translation) || '',
                error: (resp && resp.error) || '',
            });
        }).fail(function() {
            deferred.resolve({ field: field, success: false, translation: '', error: 'request failed' });
        });

        return deferred.promise();
    }

    function applyTranslation(lang, field, translation) {
        if (field === 'post_content') {
            var editorId = 'stm_content_' + lang;
            if (typeof tinymce !== 'undefined' && tinymce.get(editorId)) {
                tinymce.get(editorId).setContent(translation);
                tinymce.get(editorId).save();
            }
            $('#' + editorId).val(translation).trigger('change');
            return;
        }

        var inputId = field === 'post_title' ? 'stm_title_' + lang
                     : field === 'post_name' ? 'stm_slug_' + lang
                     : field === 'post_excerpt' ? 'stm_excerpt_' + lang
                     : null;
        if (inputId) {
            $('#' + inputId).val(translation).trigger('change');
        }
    }

    function setStatus($btn, message, kind) {
        var $status = $btn.siblings('.stm-auto-translate-status');
        if (!$status.length) return;
        $status
            .removeClass('is-success is-error is-loading')
            .addClass('is-' + kind)
            .text(message);
        if (kind === 'success') {
            setTimeout(function() {
                $status.removeClass('is-success').text('');
            }, 3000);
        }
    }

})(jQuery);
