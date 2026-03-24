(function ($) {
    'use strict';

    var cfg = btcpaySatoshiSettings;
    var s   = cfg.strings;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------
    function esc(str) {
        return $('<span>').text(str).html();
    }

    function runTest(btnId, resultId, action, labelKey) {
        var $btn    = $('#' + btnId).prop('disabled', true).text(s.testing);
        var $result = $('#' + resultId).html('');
        $.post(cfg.ajaxUrl, { action: action, nonce: cfg.nonce })
            .done(function (r) {
                var msg = r.data && r.data.message ? r.data.message : '';
                if (r.success) {
                    $result.html('<span style="color:green;">&#10003; ' + esc(msg) + '</span>');
                } else {
                    $result.html('<span style="color:#a00;">&#10007; ' + esc(msg) + '</span>');
                }
            })
            .fail(function () {
                $result.html('<span style="color:#a00;">&#10007; Request failed.</span>');
            })
            .always(function () {
                $btn.prop('disabled', false).text(s[labelKey]);
            });
    }

    // -------------------------------------------------------------------------
    // Test connection / webhook buttons
    // -------------------------------------------------------------------------
    $(document).on('click', '#btcpay-satoshi-test-connection', function () {
        runTest('btcpay-satoshi-test-connection', 'btcpay-satoshi-test-connection-result',
            'btcpay_satoshi_test_connection', 'testConnection');
    });

    $(document).on('click', '#btcpay-satoshi-test-webhook', function () {
        runTest('btcpay-satoshi-test-webhook', 'btcpay-satoshi-test-webhook-result',
            'btcpay_satoshi_test_webhook', 'testWebhook');
    });

    // -------------------------------------------------------------------------
    // Satflux connect button: update href when URL input changes
    // -------------------------------------------------------------------------
    (function () {
        var $btn   = $('#btcpay-satoshi-connect-satflux');
        var $input = $('#btcpay_satoshi_satflux_url');
        if (!$btn.length || !$input.length) { return; }
        var connectPath = $btn.data('connect-path') || '/woocommerce/satoshi-tickets/connect';
        var returnUrl   = $btn.data('return-url')   || '';
        function updateHref() {
            var base = ($input.val() || '').trim().replace(/\/+$/, '') || 'https://satflux.io';
            $btn.attr('href', base + connectPath + '?return_url=' + encodeURIComponent(returnUrl) + '&return_satflux_store_id=1');
        }
        $input.on('input change', updateHref);
    }());

    // -------------------------------------------------------------------------
    // BTCPay direct wizard — Step 1: Authorize on BTCPay
    // -------------------------------------------------------------------------
    $(document).on('click', '#btcpay-satoshi-wizard-authorize', function () {
        var btcpayUrl = ($('#btcpay-satoshi-wizard-url').val() || '').trim().replace(/\/+$/, '');
        var $err      = $('#btcpay-satoshi-wizard-error');
        if (!btcpayUrl) {
            $err.text(s.wizardUrlRequired).show();
            return;
        }
        $err.hide();
        var callbackUrl  = $(this).data('callback-url') + '&btcpay_url=' + encodeURIComponent(btcpayUrl);
        var permissions  = cfg.btcpayPermissions || [];
        var params       = 'applicationName=' + encodeURIComponent('Satoshi Tickets for WooCommerce');
        params          += '&redirect='        + encodeURIComponent(callbackUrl);
        params          += '&strict=true&selectiveStores=true';
        permissions.forEach(function (p) {
            params += '&permissions=' + encodeURIComponent(p);
        });
        window.location.href = btcpayUrl + '/api-keys/authorize?' + params;
    });

    // -------------------------------------------------------------------------
    // BTCPay direct wizard — Step 2: Load stores and save
    // -------------------------------------------------------------------------
    if (cfg.pickStore) {
        $.post(cfg.ajaxUrl, { action: 'btcpay_satoshi_get_stores', nonce: cfg.nonce })
            .done(function (r) {
                $('#btcpay-satoshi-stores-loading').hide();
                if (r.success && r.data.stores && r.data.stores.length) {
                    var $sel = $('#btcpay-satoshi-store-select');
                    r.data.stores.forEach(function (store) {
                        $sel.append('<option value="' + esc(store.id) + '">' + esc(store.name) + '</option>');
                    });
                    if (r.data.stores.length === 1) {
                        $sel.val(r.data.stores[0].id);
                    }
                    $('#btcpay-satoshi-stores-list').show();
                } else {
                    var msg = r.data && r.data.message ? r.data.message : s.error;
                    $('#btcpay-satoshi-stores-error').text(msg).show();
                }
            })
            .fail(function () {
                $('#btcpay-satoshi-stores-loading').hide();
                $('#btcpay-satoshi-stores-error').text(s.error).show();
            });
    }

    $(document).on('click', '#btcpay-satoshi-wizard-save', function () {
        var storeId = $('#btcpay-satoshi-store-select').val();
        var $result = $('#btcpay-satoshi-wizard-save-result');
        if (!storeId) {
            $result.html('<span style="color:#a00;">' + esc(s.wizardSelectStore) + '</span>');
            return;
        }
        var $btn = $(this).prop('disabled', true).text(s.loading);
        $.post(cfg.ajaxUrl, {
            action:  'btcpay_satoshi_wizard_save',
            nonce:   cfg.nonce,
            storeId: storeId
        })
            .done(function (r) {
                if (r.success) {
                    window.location.href = cfg.connectedUrl;
                } else {
                    var msg = r.data && r.data.message ? r.data.message : s.error;
                    $result.html('<span style="color:#a00;">&#10007; ' + esc(msg) + '</span>');
                    $btn.prop('disabled', false).text(s.wizardConnect);
                }
            })
            .fail(function () {
                $result.html('<span style="color:#a00;">&#10007; ' + esc(s.error) + '</span>');
                $btn.prop('disabled', false).text(s.wizardConnect);
            });
    });

})(jQuery);
