(function ($) {
    'use strict';

    var s = btcpaySatoshiSettings.strings;

    function runTest(btnId, resultId, action, labelKey) {
        var $btn = $('#' + btnId).prop('disabled', true).text(s.testing);
        var $result = $('#' + resultId).html('');

        $.post(btcpaySatoshiSettings.ajaxUrl, {
            action: action,
            nonce: btcpaySatoshiSettings.nonce
        }).done(function (r) {
            var msg = r.data && r.data.message ? r.data.message : '';
            if (r.success) {
                $result.html('<span style="color:green;">&#10003; ' + msg + '</span>');
            } else {
                $result.html('<span style="color:#a00;">&#10007; ' + msg + '</span>');
            }
        }).fail(function () {
            $result.html('<span style="color:#a00;">&#10007; Request failed.</span>');
        }).always(function () {
            $btn.prop('disabled', false).text(s[labelKey]);
        });
    }

    $(document).on('click', '#btcpay-satoshi-test-connection', function () {
        runTest('btcpay-satoshi-test-connection', 'btcpay-satoshi-test-connection-result', 'btcpay_satoshi_test_connection', 'testConnection');
    });

    $(document).on('click', '#btcpay-satoshi-test-webhook', function () {
        runTest('btcpay-satoshi-test-webhook', 'btcpay-satoshi-test-webhook-result', 'btcpay_satoshi_test_webhook', 'testWebhook');
    });

})(jQuery);
