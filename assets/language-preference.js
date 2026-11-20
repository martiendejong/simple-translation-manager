// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.js.language-preference

/**
 * Language preference persistence (URL routing mode).
 *
 * The server keeps the visitor's explicit language choice in the stm_lang
 * cookie and redirects bare default-language URLs to the chosen /{lang}/
 * URL (STM\Frontend::maybe_redirect_to_preferred_language()). This script
 * adds a second, longer-lived copy of that choice in localStorage:
 *
 *  - on a page whose language was explicitly requested (URL prefix or
 *    ?lang=), mirror the choice into localStorage and keep the cookie in
 *    sync;
 *  - on a bare page view where the cookie is present, keep localStorage equal
 *    to it (the cookie is the server's record of the last explicit choice);
 *  - on a bare page view where the cookie is gone (expired or cleared) but
 *    localStorage still remembers a non-default active language, restore
 *    the cookie and re-enter via the /{lang}/ URL — exactly what the
 *    server-side redirect would have done had the cookie survived.
 *
 * window.stmLangPreference is inlined by
 * STM\Frontend::enqueue_language_preference_script().
 */
(function () {
    'use strict';

    var cfg = window.stmLangPreference;
    if (!cfg) {
        return;
    }

    var KEY = 'stm_lang';
    var YEAR = 60 * 60 * 24 * 365;

    function readCookie() {
        var match = document.cookie.match(/(?:^|;\s*)stm_lang=([a-zA-Z-]{2,10})(?:;|$)/);
        return match ? match[1].toLowerCase() : '';
    }

    function writeCookie(lang) {
        document.cookie = 'stm_lang=' + encodeURIComponent(lang) +
            '; path=/; max-age=' + YEAR + '; samesite=Lax' +
            ('https:' === window.location.protocol ? '; secure' : '');
    }

    try {
        if (cfg.explicit) {
            window.localStorage.setItem(KEY, cfg.current);
            if (readCookie() !== cfg.current) {
                writeCookie(cfg.current);
            }
            return;
        }

        var cookie = readCookie();
        if (cookie) {
            // Cookie still present: the server already decided for this view.
            // It always holds the last EXPLICIT choice, including a switch
            // back to the default language (the ?lang= switcher link is
            // redirected server-side, so no explicit page ever renders for
            // it) — keep localStorage in step, or a stale non-default value
            // would resurrect itself once the cookie expires.
            if (cfg.active.indexOf(cookie) !== -1 && window.localStorage.getItem(KEY) !== cookie) {
                window.localStorage.setItem(KEY, cookie);
            }
            return;
        }

        var stored = window.localStorage.getItem(KEY);
        if (!stored || stored === cfg.defaultLang || cfg.active.indexOf(stored) === -1) {
            return;
        }

        writeCookie(stored);
        window.location.replace(
            window.location.origin + '/' + stored +
            window.location.pathname + window.location.search + window.location.hash
        );
    } catch (e) {
        // localStorage unavailable (private mode / blocked): the plain
        // cookie flow set by the server still works on its own.
    }
})();
