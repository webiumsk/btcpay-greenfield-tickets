(function ($) {
    'use strict';

    var currentEventId = '';
    var currentEventTitle = '';

    function refreshEvents() {
        var $list = $('#btcpay-satoshi-events-list');
        $list.html('<p>' + btcpaySatoshiAdmin.strings.loading + '</p>');

        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_get_events',
            nonce: btcpaySatoshiAdmin.nonce
        }).done(function (r) {
            if (r.success && r.data && r.data.length > 0) {
                var checkinBase = btcpaySatoshiAdmin.satfluxCheckinBase || '';
                var checkinStr = btcpaySatoshiAdmin.strings.checkin || 'Check-in';
                var activeStr = btcpaySatoshiAdmin.strings.active || 'Active';
                var disabledStr = btcpaySatoshiAdmin.strings.disabled || 'Disabled';
                var enableStr = btcpaySatoshiAdmin.strings.enable || 'Enable';
                var disableStr = btcpaySatoshiAdmin.strings.disable || 'Disable';
                var rows = r.data.map(function (e) {
                    var eventId = e.id || '';
                    var eventState = e.eventState || e.EventState || '';
                    var en = (eventState === 'Active');
                    var statusBadge = '<span class="btcpay-satoshi-status-badge btcpay-satoshi-status-' + (en ? 'active' : 'disabled') + '">' + (en ? activeStr : disabledStr) + '</span>';
                    var toggleBtn = '<button type="button" class="button button-small btcpay-satoshi-toggle-event" data-event-id="' + eventId + '">' + (en ? disableStr : enableStr) + '</button>';
                    var checkinLink = (checkinBase && eventId) ? ' <a href="' + checkinBase + '/' + encodeURIComponent(eventId) + '" class="button button-small" target="_blank" rel="noopener noreferrer">' + checkinStr + '</a>' : '';
                    return '<tr data-event-id="' + eventId + '" data-event-enable="' + (en ? '1' : '0') + '">' +
                        '<td><strong>' + (e.title || '').replace(/</g, '&lt;') + '</strong></td>' +
                        '<td>' + (e.startDate || '').replace(/</g, '&lt;') + '</td>' +
                        '<td>' + statusBadge + ' ' + toggleBtn + '</td>' +
                        '<td>' + (e.ticketsSold || 0) + '</td>' +
                        '<td><button type="button" class="button button-small btcpay-satoshi-load-tickets">View Ticket Types</button> ' +
                        '<button type="button" class="button button-small btcpay-satoshi-edit-event" data-event-id="' + eventId + '">' +
                        (btcpaySatoshiAdmin.strings.editEvent || 'Edit') + '</button>' + checkinLink + '</td></tr>';
                }).join('');
                $list.html('<table class="wp-list-table widefat fixed striped"><thead><tr>' +
                    '<th>Event</th><th>Date</th><th>Status</th><th>Tickets Sold</th><th>Actions</th></tr></thead><tbody>' +
                    rows + '</tbody></table>');
            } else {
                $list.html('<p id="btcpay-satoshi-no-events">No active events found.</p>');
            }
        }).fail(function () {
            $list.html('<p class="notice notice-error">' + btcpaySatoshiAdmin.strings.error + '</p>');
        });
    }

    function loadTicketTypes(eventId, eventTitle) {
        currentEventId = String(eventId);
        currentEventTitle = eventTitle || eventId;
        $('#btcpay-satoshi-event-title').text(currentEventTitle);
        $('#btcpay-satoshi-ticket-types-list').html('<p>' + btcpaySatoshiAdmin.strings.loading + '</p>');
        $('#btcpay-satoshi-ticket-types-section').show();

        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_get_ticket_types',
            nonce: btcpaySatoshiAdmin.nonce,
            eventId: String(eventId)
        }, null, 'json').done(function (r) {
            if (r.success && r.data && r.data.length > 0) {
                var rows = r.data.map(function (tt) {
                    var ticketTypeId = tt.id || '';
                    var qty = tt.quantity;
                    if (qty === undefined) {
                        var avail = tt.quantityAvailable;
                        var sold = tt.quantitySold || 0;
                        qty = (avail !== undefined ? avail : 0) + sold;
                    }
                    if (qty === undefined || qty === null) qty = 999999;
                    var hasProduct = tt.hasProduct || (tt.productCount && tt.productCount > 0);
                    var productCount = tt.productCount || 0;
                    var productBadge = hasProduct
                        ? ' <span class="btcpay-satoshi-has-product">&#10003; ' + (btcpaySatoshiAdmin.strings.hasProduct || 'WooCommerce product') + (productCount > 1 ? ' (' + productCount + ')' : '') + '</span>'
                        : ' <span class="btcpay-satoshi-no-product">' + (btcpaySatoshiAdmin.strings.noProduct || 'No product') + '</span>';
                    return '<tr data-ticket-type-id="' + ticketTypeId + '" class="' + (hasProduct ? 'btcpay-satoshi-tt-has-product' : 'btcpay-satoshi-tt-no-product') + '">' +
                        '<td>' + (tt.name || '').replace(/</g, '&lt;') + productBadge + '</td>' +
                        '<td>' + (tt.price || 0) + '</td>' +
                        '<td>' + (tt.quantityAvailable !== undefined ? tt.quantityAvailable : ((tt.quantity || 0) - (tt.quantitySold || 0))) + '</td>' +
                        '<td>' +
                        '<button type="button" class="button button-small btcpay-satoshi-create-product" ' +
                        'data-event-id="' + eventId + '" data-ticket-type-id="' + ticketTypeId + '" ' +
                        'data-name="' + (tt.name || '').replace(/"/g, '&quot;') + '" data-price="' + (tt.price || 0) + '" ' +
                        'data-description="' + (tt.description || '').replace(/"/g, '&quot;').replace(/</g, '&lt;') + '">' +
                        btcpaySatoshiAdmin.strings.createProduct + '</button> ' +
                        '<button type="button" class="button button-small btcpay-satoshi-sync-stock" ' +
                        'data-event-id="' + eventId + '" data-ticket-type-id="' + ticketTypeId + '">' +
                        btcpaySatoshiAdmin.strings.syncStock + '</button> ' +
                        '<button type="button" class="button button-small btcpay-satoshi-sync-from-btcpay" ' +
                        'data-event-id="' + eventId + '" data-ticket-type-id="' + ticketTypeId + '">' +
                        (btcpaySatoshiAdmin.strings.syncFromBtcpay || 'Sync from BTCPay') + '</button> ' +
                        '<button type="button" class="button button-small btcpay-satoshi-edit-ticket-type" ' +
                        'data-event-id="' + eventId + '" data-ticket-type-id="' + ticketTypeId + '" ' +
                        'data-name="' + (tt.name || '').replace(/"/g, '&quot;') + '" data-price="' + (tt.price || 0) + '" ' +
                        'data-description="' + (tt.description || '').replace(/"/g, '&quot;').replace(/</g, '&lt;') + '" ' +
                        'data-quantity="' + (qty === 999999 ? '' : qty) + '">' +
                        (btcpaySatoshiAdmin.strings.editTicketType || 'Edit') + '</button>' +
                        '</td></tr>';
                }).join('');
                $('#btcpay-satoshi-ticket-types-list').html(
                    '<table class="wp-list-table widefat fixed striped btcpay-satoshi-ticket-types-table">' +
                    '<thead><tr><th>Name</th><th>Price</th><th>Available</th><th>Actions</th></tr></thead>' +
                    '<tbody>' + rows + '</tbody></table>'
                );
            } else if (r.success && (!r.data || r.data.length === 0)) {
                $('#btcpay-satoshi-ticket-types-list').html('<p>No ticket types found.</p>');
            } else {
                var errMsg = (r.data && r.data.message) ? r.data.message : btcpaySatoshiAdmin.strings.error;
                $('#btcpay-satoshi-ticket-types-list').html('<p class="notice notice-error">' + errMsg + '</p>');
            }
        }).fail(function (jqXHR) {
            var errMsg = btcpaySatoshiAdmin.strings.error;
            if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                errMsg = jqXHR.responseJSON.data.message;
            } else if (jqXHR.responseText) {
                try {
                    var parsed = JSON.parse(jqXHR.responseText);
                    if (parsed.data && parsed.data.message) errMsg = parsed.data.message;
                } catch (e) {}
            }
            if (jqXHR.status) errMsg += ' (HTTP ' + jqXHR.status + ')';
            $('#btcpay-satoshi-ticket-types-list').html('<p class="notice notice-error">' + errMsg + '</p>');
        });
    }

    $(document).on('click', '#btcpay-satoshi-refresh-events', refreshEvents);

    $(document).on('click', '.btcpay-satoshi-toggle-event', function () {
        var $btn = $(this).prop('disabled', true);
        var eventId = $btn.data('event-id');
        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_toggle_event',
            nonce: btcpaySatoshiAdmin.nonce,
            eventId: eventId
        }).done(function (r) {
            if (r.success) {
                refreshEvents();
            } else {
                alert(r.data && r.data.message ? r.data.message : btcpaySatoshiAdmin.strings.error);
            }
        }).fail(function () {
            alert(btcpaySatoshiAdmin.strings.error);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '.btcpay-satoshi-load-tickets', function () {
        var $row = $(this).closest('tr');
        var eventId = String($row.data('event-id') || $row.attr('data-event-id') || '');
        var eventTitle = $row.find('td:first strong').text();
        if (!eventId) {
            $('#btcpay-satoshi-ticket-types-list').html('<p class="notice notice-error">Event ID is missing.</p>');
            $('#btcpay-satoshi-ticket-types-section').show();
            return;
        }
        loadTicketTypes(eventId, eventTitle);
    });

    $(document).on('click', '.btcpay-satoshi-create-product', function () {
        var $btn = $(this).prop('disabled', true);
        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_create_product',
            nonce: btcpaySatoshiAdmin.nonce,
            eventId: $btn.data('event-id'),
            ticketTypeId: $btn.data('ticket-type-id'),
            name: $btn.data('name'),
            price: $btn.data('price'),
            description: $btn.data('description') || ''
        }).done(function (r) {
            if (r.success && r.data && r.data.editUrl) {
                window.location.href = r.data.editUrl;
            } else {
                alert(r.data && r.data.message ? r.data.message : 'Error creating product');
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            alert(btcpaySatoshiAdmin.strings.error);
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '.btcpay-satoshi-sync-stock', function () {
        var $btn = $(this).prop('disabled', true);
        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_sync_stock',
            nonce: btcpaySatoshiAdmin.nonce,
            eventId: $btn.data('event-id'),
            ticketTypeId: $btn.data('ticket-type-id')
        }).done(function (r) {
            if (r.success) {
                var msg = btcpaySatoshiAdmin.strings.synced;
                if (r.data && r.data.updated !== undefined) {
                    msg += ' (' + r.data.updated + ' product(s) updated, quantity: ' + (r.data.quantity || 0) + ')';
                }
                $btn.after('<span class="satoshi-sync-ok" style="margin-left:6px;color:green;">' + msg + '</span>');
                setTimeout(function () {
                    $btn.siblings('.satoshi-sync-ok').fadeOut(function () {
                        $(this).remove();
                    });
                }, 3000);
            } else {
                alert(r.data && r.data.message ? r.data.message : btcpaySatoshiAdmin.strings.error);
            }
        }).fail(function () {
            alert(btcpaySatoshiAdmin.strings.error);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '.btcpay-satoshi-sync-from-btcpay', function () {
        var $btn = $(this).prop('disabled', true);
        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_sync_ticket_type_from_btcpay',
            nonce: btcpaySatoshiAdmin.nonce,
            eventId: $btn.data('event-id'),
            ticketTypeId: $btn.data('ticket-type-id')
        }).done(function (r) {
            if (r.success) {
                var msg = btcpaySatoshiAdmin.strings.syncedFromBtcpay || 'Products synced from BTCPay.';
                $btn.after('<span class="satoshi-sync-ok" style="margin-left:6px;color:green;">' + msg + '</span>');
                setTimeout(function () {
                    $btn.siblings('.satoshi-sync-ok').fadeOut(function () {
                        $(this).remove();
                    });
                }, 3000);
            } else {
                alert(r.data && r.data.message ? r.data.message : btcpaySatoshiAdmin.strings.error);
            }
        }).fail(function () {
            alert(btcpaySatoshiAdmin.strings.error);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    function resetEventForm() {
        $('#st-event-edit-id').val('');
        $('#st-event-form-title').text(btcpaySatoshiAdmin.strings.createEvent || 'Create event');
        $('#st-event-form-mode-hint').hide();
        $('#btcpay-satoshi-submit-event').text(btcpaySatoshiAdmin.strings.createEvent || 'Create event');
    }
    function resetTicketTypeForm() {
        $('#st-tt-edit-id').val('');
        $('#st-tt-form-title').text(btcpaySatoshiAdmin.strings.createTicketType || 'Create ticket type');
        $('#st-tt-form-mode-hint').hide();
        $('#btcpay-satoshi-submit-tt').text(btcpaySatoshiAdmin.strings.createTicketType || 'Create ticket type');
    }

    $(document).on('click', '#btcpay-satoshi-add-event', function () {
        resetEventForm();
        $('#st-event-title, #st-event-start, #st-event-desc, #st-event-location').val('');
        $('#st-event-type').val('Physical');
        $('#st-event-enable').prop('checked', false);
        $('#btcpay-satoshi-add-event-form').slideToggle();
    });
    $(document).on('click', '#btcpay-satoshi-cancel-event', function () {
        $('#btcpay-satoshi-add-event-form').slideUp();
        resetEventForm();
    });
    $(document).on('click', '.btcpay-satoshi-edit-event', function () {
        var eventId = String($(this).data('event-id') || '');
        if (!eventId) return;
        var $btn = $(this).prop('disabled', true);
        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_get_event',
            nonce: btcpaySatoshiAdmin.nonce,
            eventId: eventId
        }).done(function (r) {
            if (r.success && r.data) {
                var e = r.data;
                $('#st-event-edit-id').val(eventId);
                $('#st-event-form-title').text(btcpaySatoshiAdmin.strings.editEvent || 'Edit event');
                $('#st-event-form-mode-hint').show();
                $('#btcpay-satoshi-submit-event').text('Save');
                $('#st-event-title').val(e.title || e.Title || '');
                var start = (e.startDate || e.StartDate || '').replace(' ', 'T').substring(0, 16);
                $('#st-event-start').val(start);
                $('#st-event-desc').val(e.description || e.Description || '');
                $('#st-event-location').val(e.location || e.Location || '');
                $('#st-event-type').val(e.eventType || e.EventType || 'Physical');
                $('#st-event-enable').prop('checked', !!(e.enable || e.Enable));
                $('#btcpay-satoshi-add-event-form').slideDown();
            } else {
                alert(r.data && r.data.message ? r.data.message : btcpaySatoshiAdmin.strings.error);
            }
        }).fail(function () {
            alert(btcpaySatoshiAdmin.strings.error);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });
    $(document).on('click', '#btcpay-satoshi-submit-event', function () {
        var $btn = $(this).prop('disabled', true);
        var editId = $('#st-event-edit-id').val();
        var isEdit = !!editId;
        var start = $('#st-event-start').val();
        if (start) {
            if (start.length === 10) start += 'T00:00:00';
            else if (start.indexOf('T') >= 0 && start.length === 16) start += ':00';
        }
        var payload = {
            action: isEdit ? 'btcpay_satoshi_update_event' : 'btcpay_satoshi_create_event',
            nonce: btcpaySatoshiAdmin.nonce,
            title: $('#st-event-title').val(),
            startDate: start || $('#st-event-start').val(),
            description: $('#st-event-desc').val(),
            location: $('#st-event-location').val(),
            eventType: $('#st-event-type').val(),
            enable: $('#st-event-enable').is(':checked') ? 1 : 0
        };
        if (isEdit) payload.eventId = editId;
        $.post(btcpaySatoshiAdmin.ajaxUrl, payload).done(function (r) {
            if (r.success) {
                $('#btcpay-satoshi-add-event-form').slideUp();
                resetEventForm();
                $('#st-event-title, #st-event-start, #st-event-desc, #st-event-location').val('');
                refreshEvents();
                alert(isEdit ? (btcpaySatoshiAdmin.strings.eventUpdated || 'Event updated.') : (btcpaySatoshiAdmin.strings.eventCreated || 'Event created.'));
            } else {
                alert(r.data && r.data.message ? r.data.message : btcpaySatoshiAdmin.strings.error);
            }
        }).fail(function () {
            alert(btcpaySatoshiAdmin.strings.error);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '#btcpay-satoshi-add-ticket-type', function () {
        resetTicketTypeForm();
        $('#st-tt-name, #st-tt-price, #st-tt-desc, #st-tt-qty').val('');
        $('#btcpay-satoshi-add-tt-form').slideToggle();
    });
    $(document).on('click', '#btcpay-satoshi-cancel-tt', function () {
        $('#btcpay-satoshi-add-tt-form').slideUp();
        resetTicketTypeForm();
    });
    $(document).on('click', '.btcpay-satoshi-edit-ticket-type', function () {
        var $el = $(this);
        $('#st-tt-edit-id').val($el.data('ticketTypeId') || $el.attr('data-ticket-type-id') || '');
        $('#st-tt-form-title').text(btcpaySatoshiAdmin.strings.editTicketType || 'Edit ticket type');
        $('#st-tt-form-mode-hint').show();
        $('#btcpay-satoshi-submit-tt').text('Save');
        $('#st-tt-name').val($el.attr('data-name') || '');
        $('#st-tt-price').val($el.attr('data-price') || 0);
        $('#st-tt-desc').val($el.attr('data-description') || '');
        $('#st-tt-qty').val($el.attr('data-quantity') || '');
        $('#btcpay-satoshi-add-tt-form').slideDown();
    });
    $(document).on('click', '#btcpay-satoshi-submit-tt', function () {
        if (!currentEventId) {
            alert('Please select an event first.');
            return;
        }
        var $btn = $(this).prop('disabled', true);
        var editId = $('#st-tt-edit-id').val();
        var isEdit = !!editId;
        var payload = {
            action: isEdit ? 'btcpay_satoshi_update_ticket_type' : 'btcpay_satoshi_create_ticket_type',
            nonce: btcpaySatoshiAdmin.nonce,
            eventId: currentEventId,
            name: $('#st-tt-name').val(),
            price: $('#st-tt-price').val(),
            description: $('#st-tt-desc').val(),
            quantity: $('#st-tt-qty').val() || ''
        };
        if (isEdit) payload.ticketTypeId = editId;
        $.post(btcpaySatoshiAdmin.ajaxUrl, payload).done(function (r) {
            if (r.success) {
                $('#btcpay-satoshi-add-tt-form').slideUp();
                resetTicketTypeForm();
                $('#st-tt-name, #st-tt-price, #st-tt-desc, #st-tt-qty').val('');
                loadTicketTypes(currentEventId, currentEventTitle);
                alert(isEdit ? (btcpaySatoshiAdmin.strings.ticketTypeUpdated || 'Ticket type updated.') : (btcpaySatoshiAdmin.strings.ticketTypeCreated || 'Ticket type created.'));
            } else {
                alert(r.data && r.data.message ? r.data.message : btcpaySatoshiAdmin.strings.error);
            }
        }).fail(function () {
            alert(btcpaySatoshiAdmin.strings.error);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '.btcpay-satoshi-fulfill-btn', function () {
        var $btn = $(this).prop('disabled', true);
        var orderId = $btn.data('order-id');
        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_fulfill_tickets',
            nonce: btcpaySatoshiAdmin.nonce,
            orderId: orderId
        }).done(function (r) {
            if (r.success) {
                $btn.closest('tr').fadeOut(function () { $(this).remove(); });
                alert(btcpaySatoshiAdmin.strings.ticketsCreated || 'Tickets created.');
            } else {
                alert(r.data && r.data.message ? r.data.message : btcpaySatoshiAdmin.strings.error);
            }
        }).fail(function () {
            alert(btcpaySatoshiAdmin.strings.error);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });
})(jQuery);
