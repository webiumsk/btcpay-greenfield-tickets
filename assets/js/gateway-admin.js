/**
 * Gateway settings: media uploader for icon field.
 */
(function ($) {
    'use strict';

    var frame;
    var defaultIconUrl = (window.btcpaySatoshiGateway && window.btcpaySatoshiGateway.defaultIconUrl) || '';

    function init() {
        var $wrap = $('.btcpay-satoshi-icon-upload');
        if (!$wrap.length) return;

        $wrap.on('click', '.btcpay-satoshi-icon-select', function (e) {
            e.preventDefault();
            var $row = $(this).closest('.btcpay-satoshi-icon-upload');
            var $input = $row.closest('.forminp').find('input[type="hidden"]');
            if (!frame) {
                frame = wp.media({
                    title: $row.data('title') || 'Select icon',
                    button: { text: 'Use this image' },
                    library: { type: 'image' },
                    multiple: false
                });
                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    $input.val(attachment.id);
                    $row.find('.btcpay-satoshi-icon-preview img').attr('src', attachment.url);
                    $row.find('.btcpay-satoshi-icon-remove').show();
                });
            }
            frame.open();
        });

        $wrap.on('click', '.btcpay-satoshi-icon-remove', function (e) {
            e.preventDefault();
            var $row = $(this).closest('.btcpay-satoshi-icon-upload');
            var $input = $row.closest('.forminp').find('input[type="hidden"]');
            $input.val('');
            $row.find('.btcpay-satoshi-icon-preview img').attr('src', defaultIconUrl);
            $(this).hide();
        });
    }

    $(function () {
        init();
    });
})(jQuery);
