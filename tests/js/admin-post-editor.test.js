/**
 * Jest unit tests for assets/admin-post-editor.js — translation tab
 * switching (with TinyMCE lazy init/destroy per tab) and the delete-
 * translation button flow.
 */

const path = require('path');

const SCRIPT_PATH = path.join(__dirname, '..', '..', 'assets', 'admin-post-editor.js');

function buildDom() {
    document.body.innerHTML = `
        <form id="post">
            <div class="stm-save-toast" role="status" aria-live="polite" hidden>
                <span class="stm-save-toast-icon"></span>
                <span class="stm-save-toast-text">Translations saved</span>
            </div>

            <div class="stm-tabs" role="tablist">
                <button type="button" class="stm-tab-button active stm-tab-empty" role="tab"
                        aria-controls="stm-tab-panel-nl" data-lang="nl">Dutch</button>
                <button type="button" class="stm-tab-button stm-tab-empty" role="tab"
                        aria-controls="stm-tab-panel-fr" data-lang="fr">French</button>
            </div>

            <div class="stm-tab-content active" id="stm-tab-panel-nl" data-lang="nl">
                <button type="button" class="button stm-delete-translation-btn" data-lang="nl" data-post-id="42"></button>
                <span class="stm-delete-translation-status"></span>
                <input id="stm_title_nl" class="stm-translation-field" data-field="post_title" value="Titel">
                <textarea id="stm_content_nl" class="stm-editor-area stm-translation-field" data-field="post_content">Inhoud</textarea>
            </div>

            <div class="stm-tab-content" id="stm-tab-panel-fr" data-lang="fr">
                <button type="button" class="button stm-delete-translation-btn" data-lang="fr" data-post-id="42"></button>
                <span class="stm-delete-translation-status"></span>
                <input id="stm_title_fr" class="stm-translation-field" data-field="post_title" value="">
                <textarea id="stm_content_fr" class="stm-editor-area stm-translation-field" data-field="post_content"></textarea>
            </div>
        </form>
    `;
}

function fakeJqXHR({ fail = false, response = {} } = {}) {
    const xhr = {
        done(cb) { if (!fail) cb(response); return xhr; },
        fail(cb) { if (fail) cb(response); return xhr; },
        always(cb) { cb(response); return xhr; },
    };
    return xhr;
}

function loadScript() {
    jest.resetModules();
    require(SCRIPT_PATH);
    // jQuery defers an already-"complete" document's ready callbacks via
    // window.setTimeout(jQuery.ready) — flush it under fake timers.
    jest.runAllTimers();
}

describe('admin-post-editor.js', () => {
    let tinymceEditors;

    beforeEach(() => {
        jest.useFakeTimers();
        buildDom();

        const $ = require('jquery');
        global.$ = $;
        global.jQuery = $;
        window.$ = $;
        window.jQuery = $;

        tinymceEditors = {};
        global.tinymce = {
            get: (id) => tinymceEditors[id] || null,
        };
        window.tinymce = global.tinymce;

        global.wp = {
            editor: {
                initialize: jest.fn((editorId) => {
                    tinymceEditors[editorId] = {
                        getContent: jest.fn(() => document.getElementById(editorId).value),
                        setContent: jest.fn((val) => { document.getElementById(editorId).value = val; }),
                        save: jest.fn(),
                    };
                }),
                remove: jest.fn((editorId) => {
                    delete tinymceEditors[editorId];
                }),
            },
        };
        window.wp = global.wp;

        window.stmPostEditor = {
            postId: 42,
            postsApiRoot: 'https://example.test/wp-json/stm/v1/posts/',
            restUrl: 'https://example.test/wp-json/stm/v1/translate/auto',
            restNonce: 'nonce-123',
            i18n: {
                deleteConfirm: 'Delete this translation?',
                deleted: 'Translation deleted',
                deleteFailed: 'Failed to delete translation',
                saved: 'Translations saved',
            },
        };
    });

    afterEach(() => {
        jest.useRealTimers();
        jest.restoreAllMocks();
        delete global.wp;
        delete global.tinymce;
        delete window.wp;
        delete window.tinymce;
    });

    describe('tab switching', () => {
        test('boots the TinyMCE editor for the initially active tab', () => {
            loadScript();
            expect(global.wp.editor.initialize).toHaveBeenCalledWith('stm_content_nl', expect.any(Object));
        });

        test('switching tabs saves+destroys the old editor and inits the new one', () => {
            loadScript();
            global.wp.editor.initialize.mockClear();

            global.$('.stm-tab-button[data-lang="fr"]').trigger('click');

            expect(global.wp.editor.remove).toHaveBeenCalledWith('stm_content_nl');
            expect(global.wp.editor.initialize).toHaveBeenCalledWith('stm_content_fr', expect.any(Object));

            expect(global.$('.stm-tab-button[data-lang="fr"]').hasClass('active')).toBe(true);
            expect(global.$('.stm-tab-button[data-lang="nl"]').hasClass('active')).toBe(false);
            expect(global.$('.stm-tab-content[data-lang="fr"]').hasClass('active')).toBe(true);
            expect(global.$('.stm-tab-content[data-lang="nl"]').hasClass('active')).toBe(false);
        });

        test('clicking the already-active tab is a no-op', () => {
            loadScript();
            global.wp.editor.initialize.mockClear();

            global.$('.stm-tab-button[data-lang="nl"]').trigger('click');

            expect(global.wp.editor.initialize).not.toHaveBeenCalled();
            expect(global.wp.editor.remove).not.toHaveBeenCalled();
        });
    });

    describe('delete translation button', () => {
        test('does nothing when the user cancels the confirm dialog', () => {
            window.confirm = jest.fn(() => false);
            const ajaxSpy = jest.spyOn(global.$, 'ajax');

            loadScript();
            global.$('.stm-delete-translation-btn[data-lang="fr"]').trigger('click');

            expect(window.confirm).toHaveBeenCalled();
            expect(ajaxSpy).not.toHaveBeenCalled();
        });

        test('calls DELETE on the posts REST route and clears the tab on success', () => {
            window.confirm = jest.fn(() => true);
            const ajaxSpy = jest.spyOn(global.$, 'ajax').mockReturnValue(fakeJqXHR({ response: { success: true, deleted: 1 } }));

            loadScript();
            global.$('#stm_title_nl').val('Titel');

            global.$('.stm-delete-translation-btn[data-lang="nl"]').trigger('click');

            expect(ajaxSpy).toHaveBeenCalledWith(expect.objectContaining({
                url: 'https://example.test/wp-json/stm/v1/posts/42/translations/nl',
                method: 'DELETE',
            }));

            expect(global.$('#stm_title_nl').val()).toBe('');

            const $tabButton = global.$('.stm-tab-button[data-lang="nl"]');
            expect($tabButton.hasClass('stm-tab-empty')).toBe(true);
            expect($tabButton.hasClass('stm-tab-complete')).toBe(false);
            expect($tabButton.hasClass('stm-tab-partial')).toBe(false);
        });

        test('shows an error status message when the delete request fails', () => {
            window.confirm = jest.fn(() => true);
            jest.spyOn(global.$, 'ajax').mockReturnValue(fakeJqXHR({ fail: true, response: {} }));

            loadScript();
            global.$('.stm-delete-translation-btn[data-lang="nl"]').trigger('click');

            const $status = global.$('.stm-tab-content[data-lang="nl"] .stm-delete-translation-status');
            expect($status.text()).toBe('Failed to delete translation');
            expect($status.hasClass('is-error')).toBe(true);
        });

        test('sends the X-WP-Nonce header on the delete request', () => {
            window.confirm = jest.fn(() => true);
            const setHeader = jest.fn();
            jest.spyOn(global.$, 'ajax').mockImplementation((opts) => {
                opts.beforeSend({ setRequestHeader: setHeader });
                return fakeJqXHR({ response: { success: true } });
            });

            loadScript();
            global.$('.stm-delete-translation-btn[data-lang="nl"]').trigger('click');

            expect(setHeader).toHaveBeenCalledWith('X-WP-Nonce', 'nonce-123');
        });
    });
});
