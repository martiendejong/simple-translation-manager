(function ($) {
    'use strict';

    if (typeof stmDashboard === 'undefined') {
        return;
    }

    function setStatus($el, text, cls) {
        $el.removeClass('is-success is-error is-pending')
           .addClass(cls || '')
           .text(text || '');
    }

    $(document).on('click', '.stm-quick-save', function () {
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var postId = $row.data('postId');
        var langCode = $row.data('language');
        var $input = $row.find('.stm-quick-input');
        var $status = $row.find('.stm-quick-status');
        var value = $.trim($input.val() || '');

        if (!value) {
            setStatus($status, stmDashboard.i18n.error + ': empty', 'is-error');
            return;
        }

        setStatus($status, stmDashboard.i18n.saving, 'is-pending');
        $btn.prop('disabled', true);

        $.ajax({
            url: stmDashboard.ajaxUrl,
            method: 'POST',
            data: {
                action: 'stm_quick_save_translation',
                nonce: stmDashboard.nonce,
                post_id: postId,
                language_code: langCode,
                field_name: 'title',
                translation: value
            }
        }).done(function (resp) {
            if (resp && resp.success) {
                setStatus($status, stmDashboard.i18n.saved, 'is-success');
                $row.css('opacity', 0.6);
            } else {
                var msg = (resp && resp.data && resp.data.message) || stmDashboard.i18n.error;
                setStatus($status, msg, 'is-error');
            }
        }).fail(function (xhr) {
            var msg = stmDashboard.i18n.error;
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                msg = xhr.responseJSON.data.message;
            }
            setStatus($status, msg, 'is-error');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '#stm-refresh-coverage', function () {
        var $btn = $(this);
        var original = $btn.text();
        $btn.prop('disabled', true).text(stmDashboard.i18n.refreshing);

        $.ajax({
            url: stmDashboard.ajaxUrl,
            method: 'POST',
            data: {
                action: 'stm_refresh_coverage',
                nonce: stmDashboard.nonce
            }
        }).done(function () {
            window.location.reload();
        }).fail(function () {
            $btn.prop('disabled', false).text(original);
            window.alert(stmDashboard.i18n.error);
        });
    });
})(jQuery);
