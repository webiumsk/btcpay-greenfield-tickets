/**
 * Checkout: Toggle recipient fields, refresh totals on payment method change (Classic checkout).
 */
(function ($) {
    'use strict';

    function toggleRecipientRows() {
        var mode = $('input[name="satoshi_recipient_mode"]:checked').val();
        var $rows = $('#satoshi-recipient-rows');
        if (!$rows.length) return;
        if (mode === 'multiple') {
            $rows.slideDown(200);
            $rows.find('input[type="email"]').prop('required', true);
        } else {
            $rows.slideUp(200);
            $rows.find('input[type="email"]').prop('required', false);
        }
    }

    function initRecipientToggle() {
        var $fields = $('#satoshi-recipient-fields');
        if (!$fields.length) return;
        $('input[name="satoshi_recipient_mode"]').off('change.btcpay_satoshi').on('change.btcpay_satoshi', toggleRecipientRows);
        toggleRecipientRows();
    }

    $(function () {
        initRecipientToggle();
        $(document.body).on('updated_checkout', initRecipientToggle);

        if (!$('form.woocommerce-checkout').length) return;

        var lastPaymentMethod = $('input[name="payment_method"]:checked').val() || '';
        function maybeUpdate() {
            var current = $('input[name="payment_method"]:checked').val();
            if (current && current !== lastPaymentMethod) {
                lastPaymentMethod = current;
                $(document.body).trigger('update_checkout');
            }
        }

        $('body').on('change', 'input[name="payment_method"]', function () {
            lastPaymentMethod = $(this).val();
            $(document.body).trigger('update_checkout');
        });

        $('body').on('click', '#payment, .woocommerce-checkout-payment, .payment_methods, li.wc_payment_method', function () {
            setTimeout(maybeUpdate, 100);
        });

        $(document.body).on('updated_checkout', function () {
            var v = $('input[name="payment_method"]:checked').val();
            if (v) lastPaymentMethod = v;
        });
    });
})(jQuery);
