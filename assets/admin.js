/* Simple Translation Manager — Admin JS */
/* Handles: session persistence, inline language CRUD, nonce heartbeat refresh */
(function ($) {
    'use strict';

    if (typeof stmAdmin === 'undefined') return;

    var ajaxUrl   = stmAdmin.ajaxUrl;
    var nonce     = stmAdmin.nonce;
    var restUrl   = stmAdmin.restUrl   || '/wp-json/stm/v1';
    var restNonce = stmAdmin.restNonce || '';

    // =========================================================================
    // Nonce refresh via WordPress heartbeat (keeps long-open pages valid)
    // =========================================================================
    $(document).on('heartbeat-send', function (e, data) {
        data.stm_refresh_nonce = true;
    });
    $(document).on('heartbeat-tick', function (e, data) {
        if (data.stm_nonce) {
            nonce = data.stm_nonce;
            stmAdmin.nonce = nonce;
        }
    });

    // =========================================================================
    // Session persistence — save/restore filter state per page
    // =========================================================================
    var pageKey = (function () {
        var params = new URLSearchParams(window.location.search);
        return params.get('page') || '';
    }());

    function savePrefs(prefs) {
        $.post(ajaxUrl, {
            action: 'stm_save_prefs',
            nonce:  nonce,
            page:   pageKey,
            prefs:  prefs,
        });
    }

    // Restore saved filters on page load if no filter params are set in URL
    function maybeRestoreFilters() {
        if (!pageKey) return;
        var params = new URLSearchParams(window.location.search);
        var hasFilter = params.has('lang') || params.has('context') || params.has('search') || params.has('status');
        if (hasFilter) return; // User navigated with explicit filters — honour them

        $.post(ajaxUrl, { action: 'stm_load_prefs', nonce: nonce, page: pageKey }, function (res) {
            if (!res.success || !res.data || $.isEmptyObject(res.data)) return;
            var prefs = res.data;
            var url   = new URL(window.location.href);
            var changed = false;
            ['lang', 'context', 'search', 'status', 'paged'].forEach(function (k) {
                if (prefs[k] && !url.searchParams.has(k)) {
                    url.searchParams.set(k, prefs[k]);
                    changed = true;
                }
            });
            if (changed) window.location.replace(url.toString());
        });
    }

    // Save filters when form is submitted
    $(document).on('submit', '#stm-filter-form', function () {
        var prefs = {};
        $(this).serializeArray().forEach(function (f) {
            if (['lang', 'context', 'search', 'status'].includes(f.name)) {
                prefs[f.name] = f.value;
            }
        });
        savePrefs(prefs);
    });

    maybeRestoreFilters();

    // =========================================================================
    // Inline language CRUD
    // =========================================================================

    // Edit button — toggle inline edit row
    $(document).on('click', '.stm-lang-edit', function (e) {
        e.preventDefault();
        var $row = $(this).closest('tr');
        var $edit = $row.next('.stm-lang-edit-row');
        if ($edit.length) {
            $edit.toggle();
        }
    });

    // Cancel inline edit
    $(document).on('click', '.stm-lang-edit-cancel', function () {
        $(this).closest('.stm-lang-edit-row').hide();
    });

    // Save inline edit via REST PUT /languages/{id}
    $(document).on('click', '.stm-lang-edit-save', function () {
        var $editRow = $(this).closest('.stm-lang-edit-row');
        var langId   = $editRow.data('lang-id');
        var payload  = {
            name:        $editRow.find('[name="name"]').val(),
            native_name: $editRow.find('[name="native_name"]').val(),
            flag_emoji:  $editRow.find('[name="flag_emoji"]').val(),
            order_index: parseInt($editRow.find('[name="order_index"]').val(), 10) || 0,
        };

        $.ajax({
            url:    restUrl + '/languages/' + langId,
            method: 'PUT',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', restNonce); },
            contentType: 'application/json',
            data:   JSON.stringify(payload),
            success: function () { window.location.reload(); },
            error:   function (xhr) { alert('Save failed: ' + (xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : xhr.status)); },
        });
    });

    // Toggle active via REST
    $(document).on('click', '.stm-lang-toggle-active', function () {
        var langId    = $(this).data('lang-id');
        var isActive  = $(this).data('is-active') === 1 || $(this).data('is-active') === true;
        var $btn      = $(this);

        $.ajax({
            url:    restUrl + '/languages/' + langId,
            method: 'PUT',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', restNonce); },
            contentType: 'application/json',
            data:   JSON.stringify({ is_active: !isActive }),
            success: function () { window.location.reload(); },
            error:   function () { alert('Toggle failed.'); },
        });
    });

    // Reorder (move up / move down) via REST
    $(document).on('click', '.stm-lang-move', function () {
        var direction = $(this).data('direction');  // 'up' or 'down'
        var langId    = $(this).data('lang-id');
        var order     = parseInt($(this).data('order'), 10);
        var newOrder  = direction === 'up' ? Math.max(0, order - 1) : order + 1;

        $.ajax({
            url:    restUrl + '/languages/' + langId,
            method: 'PUT',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', restNonce); },
            contentType: 'application/json',
            data:   JSON.stringify({ order_index: newOrder }),
            success: function () { window.location.reload(); },
            error:   function () { alert('Reorder failed.'); },
        });
    });

    // =========================================================================
    // Import dry-run preview
    // =========================================================================
    $(document).on('click', '#stm-import-preview', function (e) {
        e.preventDefault();
        var $form  = $('#stm-import-form');
        var $file  = $form.find('[type="file"]')[0];
        var $out   = $('#stm-import-preview-output');

        if (!$file.files.length) {
            alert('Select a JSON file first.');
            return;
        }

        var reader = new FileReader();
        reader.onload = function (ev) {
            var data;
            try { data = JSON.parse(ev.target.result); } catch (err) { alert('Invalid JSON.'); return; }

            $out.html('<em>Previewing…</em>');

            $.ajax({
                url:    restUrl + '/import?dry_run=true',
                method: 'POST',
                beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', restNonce); },
                contentType: 'application/json',
                data:   JSON.stringify(data),
                success: function (res) {
                    var html = '<div class="notice notice-info inline" style="margin:12px 0;">'
                        + '<p><strong>Dry-run preview</strong> — no changes written.</p>'
                        + '<ul style="margin:4px 0 4px 20px;list-style:disc;">'
                        + '<li>Would create: <strong>' + res.created + '</strong> strings</li>'
                        + '<li>Would update: <strong>' + res.updated + '</strong> strings</li>'
                        + (res.errors.length ? '<li style="color:#c00">Errors: ' + res.errors.join('; ') + '</li>' : '')
                        + '</ul></div>';
                    $out.html(html);
                },
                error: function (xhr) {
                    $out.html('<div class="notice notice-error inline"><p>Preview failed: ' + (xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : xhr.status) + '</p></div>');
                },
            });
        };
        reader.readAsText($file.files[0]);
    });

    // =========================================================================
    // Dashboard tab persistence
    // =========================================================================
    $(document).on('click', '.stm-dashboard-tab', function () {
        var tab = $(this).data('tab');
        if (tab) savePrefs({ tab: tab });
    });

    // =========================================================================
    // AI test-connection button
    // =========================================================================
    $(document).on('click', '#stm-test-ai-connection', function () {
        var $result = $('#stm-ai-test-result');
        $result.text('Testing…').css('color', '#777');

        $.ajax({
            url:    restUrl + '/translate/auto',
            method: 'POST',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', restNonce); },
            contentType: 'application/json',
            data: JSON.stringify({ text: 'Hello', source_lang: 'en', target_lang: 'nl' }),
            success: function (res) {
                if (res.success) {
                    $result.text('✓ Connected — "' + res.translation + '"').css('color', '#46b450');
                } else {
                    $result.text('✗ ' + (res.error || 'Unknown error')).css('color', '#c00');
                }
            },
            error: function (xhr) {
                var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'HTTP ' + xhr.status;
                $result.text('✗ ' + msg).css('color', '#c00');
            },
        });
    });

}(jQuery));
