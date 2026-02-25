/**
 * Post Editor JavaScript
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        initTranslationTabs();
    });

    /**
     * Initialize translation tabs
     */
    function initTranslationTabs() {
        $('.stm-tab-button').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var lang = $button.data('lang');

            // Update button states
            $('.stm-tab-button').removeClass('active');
            $button.addClass('active');

            // Update content visibility
            $('.stm-tab-content').removeClass('active');
            $('.stm-tab-content[data-lang="' + lang + '"]').addClass('active');

            // Trigger editor refresh for TinyMCE
            if (typeof tinymce !== 'undefined') {
                var editorId = 'stm_content_' + lang;
                var editor = tinymce.get(editorId);
                if (editor) {
                    editor.fire('wp-body-class-change');
                }
            }
        });
    }

})(jQuery);
