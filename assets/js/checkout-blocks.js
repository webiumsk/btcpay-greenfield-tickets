/**
 * WooCommerce Blocks checkout integration for Satoshi Tickets.
 * Registers the payment method and recipient fields for multiple tickets.
 */
(function () {
    'use strict';

    var registry = window.wc && window.wc.wcBlocksRegistry;
    var settings = window.wc && window.wc.wcSettings;
    var element = window.wp && window.wp.element;
    var data = window.wp && window.wp.data;
    var useState = element && element.useState;
    var useEffect = element && element.useEffect;
    var useRef = element && element.useRef;
    var useSelect = data && data.useSelect;
    var decodeEntities = (window.wp && window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities) ||
        function (s) { return s != null ? String(s) : ''; };

    var registerPlugin = window.wp && window.wp.plugins && window.wp.plugins.registerPlugin;
    var blocksCheckout = window.wc && window.wc.blocksCheckout;
    var ExperimentalOrderMeta = blocksCheckout && blocksCheckout.ExperimentalOrderMeta;

    if (!registry || !settings || !element || !useState || !useEffect || !useSelect) {
        return;
    }

    var paymentMethodData = settings.getSetting('btcpaygf_satoshi_tickets_data', {});
    var blocksData = (typeof btcpaySatoshiBlocks !== 'undefined' && btcpaySatoshiBlocks) ? btcpaySatoshiBlocks : {};
    var cartTicketsUrl = blocksData.cartTicketsUrl || paymentMethodData.cartTicketsUrl || '';
    var restNonce = blocksData.restNonce || paymentMethodData.restNonce || '';
    var title = decodeEntities(paymentMethodData.title || 'Bitcoin (Satoshi Tickets)');
    var description = decodeEntities(paymentMethodData.description || '');
    var blocksRecipientLabel = decodeEntities(paymentMethodData.blocksRecipientLabel || 'Ticket recipient details');
    var blocksBillingOption = decodeEntities(paymentMethodData.blocksBillingOption || 'Send all tickets to my billing email');
    var blocksMultipleOption = decodeEntities(paymentMethodData.blocksMultipleOption || 'Send to different addresses');
    var blocksEmailLabel = decodeEntities(paymentMethodData.blocksEmailLabel || 'Email');
    var blocksFirstNameLabel = decodeEntities(paymentMethodData.blocksFirstNameLabel || 'First name');
    var blocksLastNameLabel = decodeEntities(paymentMethodData.blocksLastNameLabel || 'Last name');
    var blocksEmailRequiredError = decodeEntities(paymentMethodData.blocksEmailRequiredError || 'Please enter a valid email for each ticket.');
    var iconUrl = paymentMethodData.icon || '';
    var supports = paymentMethodData.supports || ['products'];

    function getTicketItems(cartData) {
        var extCart = (cartData && cartData.extensions && cartData.extensions.btcpay_satoshi_tickets_cart) || {};
        if (extCart.ticket_items && Array.isArray(extCart.ticket_items) && extCart.ticket_items.length > 0) {
            return extCart.ticket_items;
        }
        var ext = (cartData && cartData.extensions && cartData.extensions.btcpay_satoshi_tickets) || {};
        if (ext.ticket_items && Array.isArray(ext.ticket_items) && ext.ticket_items.length > 0) {
            return ext.ticket_items;
        }
        var itemList = (cartData && cartData.items && Array.isArray(cartData.items))
            ? cartData.items
            : (cartData && cartData.cartItems && Array.isArray(cartData.cartItems))
                ? cartData.cartItems
                : [];
        var items = [];
        for (var i = 0; i < itemList.length; i++) {
            var item = itemList[i];
            var itemExt = (item.extensions && item.extensions.btcpay_satoshi_tickets) || {};
            var qty = Math.max(1, parseInt(item.quantity, 10) || 1);
            if (itemExt.is_ticket && itemExt.event_id && itemExt.ticket_type_id) {
                items.push({ key: item.key || String(i), name: itemExt.name || item.name || 'Ticket', quantity: qty });
            } else if (typeof itemExt.is_ticket === 'undefined') {
                items.push({ key: item.key || String(i), name: (item && item.name) || 'Ticket', quantity: qty });
            }
        }
        return items;
    }

    var extensionCartUpdate = (window.wc && window.wc.blocksCheckout && window.wc.blocksCheckout.extensionCartUpdate) || null;
    var EXT_NAMESPACE = 'btcpay_satoshi_tickets';

    function canPayWithSatoshi(context) {
        var cart = (context && context.cart) || (context && context.cartData) || context || {};
        var items = cart.items || cart.cartItems || [];
        var extensions = cart.extensions || {};
        var cartData = { items: items, cartItems: items, extensions: extensions };
        var ticketItems = getTicketItems(cartData);
        if (!ticketItems || ticketItems.length === 0) return false;
        for (var i = 0; i < items.length; i++) {
            var ext = (items[i].extensions && items[i].extensions.btcpay_satoshi_tickets) || {};
            if (ext.is_ticket === false) return false;
        }
        var eventIds = {};
        for (var j = 0; j < items.length; j++) {
            var e = (items[j].extensions && items[j].extensions.btcpay_satoshi_tickets) || {};
            if (e.event_id) eventIds[e.event_id] = true;
        }
        if (Object.keys(eventIds).length > 1) return false;
        return true;
    }

    var RecipientSlotFill = function (props) {
        var cartFromSlot = props.cart || {};
        var slotExtensions = props.extensions || {};
        var cartFromStore = useSelect(function (select) {
            try {
                var cartSelect = select('wc/store/cart');
                return (cartSelect && typeof cartSelect.getCartData === 'function')
                    ? cartSelect.getCartData() : {};
            } catch (e) {
                return {};
            }
        }, []) || {};
        var cart = (cartFromSlot && cartFromSlot.items && cartFromSlot.items.length > 0)
            ? cartFromSlot
            : cartFromStore;
        var extensions = (slotExtensions && Object.keys(slotExtensions).length > 0)
            ? slotExtensions
            : (cartFromStore.extensions || cartFromSlot.extensions || {});
        var cartData = cart && cart.items ? cart : { items: (cart && cart.items) ? cart.items : (cart && cart.cartItems) ? cart.cartItems : [], extensions: extensions };
        var ticketItems = getTicketItems(cartData);
        var totalTickets = ticketItems.reduce(function (s, i) { return s + (i.quantity || 1); }, 0);
        if (totalTickets < 1) return null;
        var modeState = useState('billing');
        var recipientsState = useState({});
        var mode = modeState[0];
        var setMode = modeState[1];
        var recipients = recipientsState[0];
        var setRecipients = recipientsState[1];
        if (typeof window !== 'undefined') {
            window.__btcpaySatoshiRecipients = { mode: mode, recipients: recipients, ticketItems: ticketItems, totalTickets: totalTickets };
        }
        useEffect(function () {
            if (!extensionCartUpdate) return;
            var payload = mode === 'billing' ? { mode: 'billing' } : (function () {
                var p = [];
                for (var i = 0; i < ticketItems.length; i++) {
                    var arr = [];
                    for (var j = 0; j < (ticketItems[i].quantity || 1); j++) {
                        var k = i + '-' + j;
                        var r = recipients[k] || {};
                        arr.push({
                            first_name: (r.first_name || '').trim(),
                            last_name: (r.last_name || '').trim(),
                            email: (r.email || '').trim(),
                        });
                    }
                    p.push(arr);
                }
                return p;
            }());
            extensionCartUpdate({ namespace: EXT_NAMESPACE, data: { satoshi_recipients: typeof payload === 'object' ? JSON.stringify(payload) : payload } });
        }, [mode, JSON.stringify(recipients), JSON.stringify(ticketItems), extensionCartUpdate]);
        var parts = [
            element.createElement('p', { key: 'p', style: { marginBottom: '8px', fontWeight: 'bold' } }, blocksRecipientLabel),
            element.createElement('label', { key: 'l1', style: { display: 'block', marginBottom: '6px' } }, [
                element.createElement('input', { type: 'radio', name: 'satoshi_recipient_mode', value: 'billing', checked: mode === 'billing', onChange: function () { setMode('billing'); } }),
                ' ' + blocksBillingOption,
            ]),
            element.createElement('label', { key: 'l2', style: { display: 'block', marginBottom: '12px' } }, [
                element.createElement('input', { type: 'radio', name: 'satoshi_recipient_mode', value: 'multiple', checked: mode === 'multiple', onChange: function () { setMode('multiple'); } }),
                ' ' + blocksMultipleOption,
            ]),
        ];
        if (mode === 'multiple') {
            var rows = [];
            for (var it = 0; it < ticketItems.length; it++) {
                for (var t = 0; t < (ticketItems[it].quantity || 1); t++) {
                    var key = it + '-' + t;
                    var r = recipients[key] || {};
                    (function (k, val) {
                        rows.push(element.createElement('div', { key: k, style: { marginBottom: '12px', padding: '12px', background: '#f5f5f5', borderRadius: '4px' } }, [
                            element.createElement('strong', { key: 'lbl' }, (ticketItems[it].quantity > 1 ? ticketItems[it].name + ' #' + (t + 1) : ticketItems[it].name)),
                            element.createElement('div', { key: 'f1', style: { marginTop: '8px' } }, [
                                element.createElement('label', { key: 'a', style: { display: 'block', marginBottom: '4px', fontSize: '12px' } }, blocksFirstNameLabel),
                                element.createElement('input', { key: 'b', type: 'text', placeholder: blocksFirstNameLabel, value: val.first_name || '', onChange: function (ev) { setRecipients(function (p) { var n = Object.assign({}, p); n[k] = Object.assign({}, n[k], { first_name: ev.target.value }); return n; }); }, style: { width: '100%', padding: '8px' } }),
                            ]),
                            element.createElement('div', { key: 'f2', style: { marginTop: '8px' } }, [
                                element.createElement('label', { key: 'a', style: { display: 'block', marginBottom: '4px', fontSize: '12px' } }, blocksLastNameLabel),
                                element.createElement('input', { key: 'b', type: 'text', placeholder: blocksLastNameLabel, value: val.last_name || '', onChange: function (ev) { setRecipients(function (p) { var n = Object.assign({}, p); n[k] = Object.assign({}, n[k], { last_name: ev.target.value }); return n; }); }, style: { width: '100%', padding: '8px' } }),
                            ]),
                            element.createElement('div', { key: 'f3', style: { marginTop: '8px' } }, [
                                element.createElement('label', { key: 'a', style: { display: 'block', marginBottom: '4px', fontSize: '12px' } }, blocksEmailLabel + ' *'),
                                element.createElement('input', { key: 'b', type: 'email', placeholder: blocksEmailLabel, value: val.email || '', onChange: function (ev) { setRecipients(function (p) { var n = Object.assign({}, p); n[k] = Object.assign({}, n[k], { email: ev.target.value }); return n; }); }, style: { width: '100%', padding: '8px' } }),
                            ]),
                        ]));
                    })(key, r);
                }
            }
            parts.push(element.createElement('div', { key: 'rows', style: { marginTop: '8px' } }, rows));
        }
        return element.createElement('div', { className: 'btcpay-satoshi-recipient-slot', style: { marginTop: '16px', marginBottom: '16px', padding: '16px', border: '1px solid #ddd', borderRadius: '4px' } }, parts);
    };

    var Content = function (props) {
        useEffect(function () {
            if (extensionCartUpdate) {
                extensionCartUpdate({ namespace: EXT_NAMESPACE, data: { payment_method: 'btcpaygf_satoshi_tickets' } });
            }
        }, []);

        var cartFromStore = useSelect(function (select) {
            try {
                var cartSelect = select('wc/store/cart');
                return (cartSelect && typeof cartSelect.getCartData === 'function')
                    ? cartSelect.getCartData() : {};
            } catch (e) {
                return {};
            }
        }, []) || {};
        var cartData = props.cartData || props.cart || cartFromStore;
        var ticketItemsFromStore = getTicketItems(cartData);
        var ticketItemsApiState = useState ? useState(null) : [null, function () {}];
        var ticketItemsFromApi = ticketItemsApiState[0];
        var setTicketItemsFromApi = ticketItemsApiState[1];
        useEffect(function () {
            if (!cartTicketsUrl || !fetch) return;
            var opts = { credentials: 'same-origin' };
            if (restNonce) { opts.headers = { 'X-WP-Nonce': restNonce }; }
            fetch(cartTicketsUrl, opts)
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (data && data.items && Array.isArray(data.items)) {
                        setTicketItemsFromApi(data.items);
                    } else {
                        setTicketItemsFromApi([]);
                    }
                })
                .catch(function () { setTicketItemsFromApi([]); });
        }, []);
        var ticketItems = (ticketItemsFromApi && ticketItemsFromApi.length > 0)
            ? ticketItemsFromApi
            : ticketItemsFromStore;
        var totalTickets = ticketItems.reduce(function (sum, i) { return sum + i.quantity; }, 0);
        var eventRegistration = props.eventRegistration || {};
        var emitResponse = props.emitResponse || {};
        var billing = props.billing || {};

        var modeRef = useRef('billing');
        var recipientsRef = useRef({});
        var modeState = useState ? useState('billing') : ['billing'];
        var recipientsState = useState ? useState({}) : [{}];
        var mode = modeState[0];
        var setMode = modeState[1];
        var recipients = recipientsState[0];
        var setRecipients = recipientsState[1];

        var billingEmail = (billing.billingAddress && billing.billingAddress.email) || '';
        var billingFirstName = (billing.billingAddress && billing.billingAddress.first_name) || '';
        var billingLastName = (billing.billingAddress && billing.billingAddress.last_name) || '';

        useEffect(function () {
            var onPaymentProcessing = eventRegistration.onPaymentProcessing;
            if (!onPaymentProcessing) return;

            var unsubscribe = onPaymentProcessing(function () {
                var slotData = (typeof window !== 'undefined' && window.__btcpaySatoshiRecipients) || null;
                var useMode = slotData ? slotData.mode : modeRef.current;
                var useRecipients = slotData ? slotData.recipients : recipientsRef.current;
                var useTicketItems = slotData ? (slotData.ticketItems || ticketItems) : ticketItems;
                var useTotal = slotData ? slotData.totalTickets : totalTickets;
                var payload = [];
                if (useMode === 'billing' || useTotal < 1) {
                    for (var i = 0; i < useTicketItems.length; i++) {
                        var arr = [];
                        for (var j = 0; j < (useTicketItems[i].quantity || 1); j++) {
                            arr.push({ first_name: billingFirstName, last_name: billingLastName, email: billingEmail });
                        }
                        payload.push(arr);
                    }
                } else {
                    var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    for (var ii = 0; ii < useTicketItems.length; ii++) {
                        var arr2 = [];
                        for (var jj = 0; jj < (useTicketItems[ii].quantity || 1); jj++) {
                            var k = ii + '-' + jj;
                            var r = useRecipients[k] || {};
                            var em = (r.email || '').trim();
                            if (!em || !re.test(em)) {
                                return {
                                    type: emitResponse.responseTypes ? emitResponse.responseTypes.ERROR : 'error',
                                    message: blocksEmailRequiredError,
                                };
                            }
                            arr2.push({
                                first_name: (r.first_name || '').trim(),
                                last_name: (r.last_name || '').trim(),
                                email: em,
                            });
                        }
                        payload.push(arr2);
                    }
                }
                return {
                    type: emitResponse.responseTypes ? emitResponse.responseTypes.SUCCESS : 'success',
                    meta: {
                        paymentMethodData: {
                            satoshi_recipients: String(JSON.stringify(payload)),
                        },
                    },
                };
            });
            return function () { if (unsubscribe && typeof unsubscribe === 'function') unsubscribe(); };
        }, [eventRegistration.onPaymentProcessing, emitResponse, totalTickets, ticketItems.length, billingEmail, billingFirstName, billingLastName]);

        modeRef.current = mode;
        recipientsRef.current = recipients;

        return element.createElement('div', { className: 'btcpay-satoshi-blocks-content' }, [
            element.createElement('div', { key: 'desc' }, description),
        ]);
    };

    var Label = function (props) {
        var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
        var children = [];
        if (iconUrl) {
            children.push(element.createElement('img', {
                key: 'icon',
                src: iconUrl,
                alt: '',
                style: { maxHeight: '24px', marginRight: '8px' },
            }));
        }
        if (PaymentMethodLabel) {
            children.push(element.createElement(PaymentMethodLabel, { text: title, key: 'label' }));
        } else {
            children.push(element.createElement('span', { key: 'label' }, title));
        }
        return element.createElement('span', { style: { display: 'flex', alignItems: 'center' } }, children);
    };

    registry.registerPaymentMethod({
        name: 'btcpaygf_satoshi_tickets',
        label: element.createElement(Label),
        content: element.createElement(Content),
        edit: element.createElement(Content),
        canMakePayment: function (context) {
            return canPayWithSatoshi(context || {});
        },
        ariaLabel: title,
        supports: {
            features: supports,
        },
    });

    if (registerPlugin && ExperimentalOrderMeta) {
        registerPlugin('btcpay-satoshi-tickets-recipients', {
            render: function () {
                return element.createElement(ExperimentalOrderMeta, null, element.createElement(RecipientSlotFill, {}));
            },
            scope: 'woocommerce-checkout',
        });
    }
})();
