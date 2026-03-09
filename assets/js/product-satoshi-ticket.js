(function ($) {
    'use strict';

    var eventSelect = '#_satoshi_event_id';
    var ticketTypeSelect = '#_satoshi_ticket_type_id';

    function isSatoshiTicketSelected() {
        var $type = $('select#product_type, select#product-type, [name="product_type"], [name="product-type"]').first();
        return $type.length && $type.val() === (typeof btcpaySatoshiProduct !== 'undefined' ? btcpaySatoshiProduct.type : 'satoshi_ticket');
    }

    function refreshEnhancedSelect($el) {
        if ($el.length && $el.hasClass('select') && $el.data('select2')) {
            $el.trigger('change');
        }
    }

    function populateEventsFromData($eventSel, $ttSel, data) {
        if (!data || !data.length) {
            $eventSel.find('option:first').text('— No events (configure BTCPay) —');
            refreshEnhancedSelect($eventSel);
            return;
        }
        data.forEach(function (e) {
            var id = e.id || e.Id || '';
            var title = e.title || e.Title || id;
            $eventSel.append($('<option></option>').attr('value', id).text(title));
        });
        var currentEventId = $eventSel.data('current') || $eventSel.attr('data-current');
        if (currentEventId) {
            $eventSel.val(currentEventId);
            loadTicketTypes(currentEventId);
        }
        refreshEnhancedSelect($eventSel);
    }

    function loadEvents() {
        if (!btcpaySatoshiProduct) return;
        var $eventSel = $(eventSelect);
        var $ttSel = $(ticketTypeSelect);
        if (!$eventSel.length) return;

        $eventSel.prop('disabled', true).find('option:not(:first)').remove();
        $ttSel.prop('disabled', true).find('option:not(:first)').remove();
        $ttSel.find('option:first').text('— Select event first —');

        var preloaded = btcpaySatoshiProduct.events;
        if (Array.isArray(preloaded) && preloaded.length > 0) {
            populateEventsFromData($eventSel, $ttSel, preloaded);
            $eventSel.prop('disabled', false);
            return;
        }

        if (!btcpaySatoshiProduct.ajaxUrl) {
            $eventSel.find('option:first').text('— No events (configure BTCPay) —');
            $eventSel.prop('disabled', false);
            return;
        }

        $.post(btcpaySatoshiProduct.ajaxUrl, {
            action: 'btcpay_satoshi_get_events',
            nonce: btcpaySatoshiProduct.nonce
        }).done(function (r) {
            if (r.success && r.data && r.data.length > 0) {
                populateEventsFromData($eventSel, $ttSel, r.data);
            } else {
                $eventSel.find('option:first').text('— No events (configure BTCPay) —');
            }
        }).fail(function () {
            $eventSel.find('option:first').text('— Error loading events —');
        }).always(function () {
            $eventSel.prop('disabled', false);
        });
    }

    function loadTicketTypes(eventId) {
        if (!btcpaySatoshiProduct || !btcpaySatoshiProduct.ajaxUrl || !eventId) return;
        var $ttSel = $(ticketTypeSelect);
        if (!$ttSel.length) return;

        $ttSel.prop('disabled', true).find('option:not(:first)').remove();
        $ttSel.find('option:first').text('— Loading… —');

        $.post(btcpaySatoshiProduct.ajaxUrl, {
            action: 'btcpay_satoshi_get_ticket_types',
            nonce: btcpaySatoshiProduct.nonce,
            eventId: eventId
        }).done(function (r) {
            if (r.success && r.data && r.data.length > 0) {
                $ttSel.find('option:first').text('— Select ticket type —');
                r.data.forEach(function (tt) {
                    var id = tt.id || tt.Id || '';
                    var name = tt.name || tt.Name || id;
                    $ttSel.append($('<option></option>').attr('value', id).text(name));
                });
                var current = $ttSel.data('current');
                if (current) {
                    $ttSel.val(current);
                }
                refreshEnhancedSelect($ttSel);
            } else {
                $ttSel.find('option:first').text('— No ticket types —');
            }
        }).fail(function () {
            $ttSel.find('option:first').text('— Error loading —');
        }).always(function () {
            $ttSel.prop('disabled', false);
        });
    }

    function maybeLoadEvents() {
        var $es = $(eventSelect);
        var needsEvents = $es.length && $es.find('option').length <= 1;
        if (needsEvents) {
            loadEvents();
        }
        if (!isSatoshiTicketSelected()) {
            $(ticketTypeSelect).find('option:not(:first)').remove();
            $(ticketTypeSelect).find('option:first').text('— Select event first —');
        }
    }

    function init() {
        maybeLoadEvents();
    }

    function pollUntilPopulated() {
        var attempts = 0;
        var maxAttempts = 30;
        var interval = setInterval(function () {
            attempts++;
            var $es = $(eventSelect);
            if ($es.length && $es.find('option').length <= 1 && btcpaySatoshiProduct && Array.isArray(btcpaySatoshiProduct.events) && btcpaySatoshiProduct.events.length > 0) {
                loadEvents();
            }
            if (attempts >= maxAttempts) {
                clearInterval(interval);
            }
        }, 500);
    }

    function watchAndRestore() {
        var $es = $(eventSelect);
        if (!$es.length || typeof btcpaySatoshiProduct === 'undefined' || !Array.isArray(btcpaySatoshiProduct.events) || btcpaySatoshiProduct.events.length === 0) return;
        var el = $es[0];
        if (el._satoshiWatcher) return;
        el._satoshiWatcher = true;
        var restoreTimer;
        var observer = new MutationObserver(function () {
            var $s = $(eventSelect);
            if ($s.length && $s.find('option').length <= 1) {
                clearTimeout(restoreTimer);
                restoreTimer = setTimeout(function () { loadEvents(); }, 100);
            }
        });
        observer.observe(el, { childList: true, subtree: true });
        setTimeout(function () { observer.disconnect(); el._satoshiWatcher = false; }, 15000);
    }

    $(document).ready(function () {
        init();
        setTimeout(init, 300);
        setTimeout(init, 800);
        setTimeout(init, 2000);
        setTimeout(init, 3500);
        setTimeout(watchAndRestore, 500);
        pollUntilPopulated();

        $(document).on('change', 'select#product_type, select.product_type, select#product-type, [name="product_type"], [name="product-type"]', function () {
            maybeLoadEvents();
        });

        $(document.body).on('woocommerce-product-type-change', function () {
            maybeLoadEvents();
        });

        $(document).on('change', eventSelect, function () {
            var eventId = $(this).val();
            var $ttSel = $(ticketTypeSelect);
            $ttSel.data('current', '');
            if (eventId) {
                loadTicketTypes(eventId);
            } else {
                $ttSel.find('option:not(:first)').remove();
                $ttSel.find('option:first').text('— Select event first —');
            }
        });
    });
})(jQuery);
