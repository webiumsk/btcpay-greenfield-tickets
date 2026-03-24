(function ($) {
    'use strict';

    var currentEventId = '';
    var currentEventTitle = '';
    var currentTicketsEventId = '';
    var currentOrdersEventId = '';

    var s = btcpaySatoshiAdmin.strings;

    /* -----------------------------------------------------------------------
     * Helpers
     * --------------------------------------------------------------------- */

    function esc(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function stateBadge(state) {
        var active = (state === 'Active');
        return '<span class="btcpay-satoshi-status-badge btcpay-satoshi-status-' + (active ? 'active' : 'disabled') + '">'
            + esc(active ? s.active : s.disabled) + '</span>';
    }

    function showInlineMsg($el, msg, ok) {
        $el.html('<span style="color:' + (ok ? 'green' : '#a00') + ';margin-left:6px;">' + esc(msg) + '</span>');
        setTimeout(function () { $el.fadeOut(400, function () { $el.html('').show(); }); }, 4000);
    }

    /* -----------------------------------------------------------------------
     * Export CSV link helper
     * --------------------------------------------------------------------- */

    function buildExportUrl(eventId) {
        return btcpaySatoshiAdmin.adminPostUrl
            + '?action=btcpay_satoshi_export_tickets'
            + '&eventId=' + encodeURIComponent(eventId)
            + '&nonce=' + encodeURIComponent(btcpaySatoshiAdmin.exportNonce);
    }

    /* -----------------------------------------------------------------------
     * Events list
     * --------------------------------------------------------------------- */

    function refreshEvents() {
        var $list = $('#btcpay-satoshi-events-list');
        $list.html('<p>' + s.loading + '</p>');

        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_get_events',
            nonce: btcpaySatoshiAdmin.nonce
        }).done(function (r) {
            if (r.success && r.data && r.data.length > 0) {
                var checkinBase = btcpaySatoshiAdmin.satfluxCheckinBase || '';
                var rows = r.data.map(function (e) {
                    var eventId = e.id || '';
                    var eventState = e.eventState || e.EventState || '';
                    var en = (eventState === 'Active');
                    var toggleBtn = '<button type="button" class="button button-small btcpay-satoshi-toggle-event" data-event-id="' + esc(eventId) + '">'
                        + esc(en ? s.disable : s.enable) + '</button>';
                    var checkinLink = (checkinBase && eventId)
                        ? ' <a href="' + checkinBase + '/' + encodeURIComponent(eventId) + '" class="button button-small" target="_blank" rel="noopener noreferrer">' + esc(s.checkin) + '</a>'
                        : '';
                    return '<tr data-event-id="' + esc(eventId) + '">'
                        + '<td><strong>' + esc(e.title || '') + '</strong></td>'
                        + '<td>' + esc(e.startDate || '') + '</td>'
                        + '<td>' + stateBadge(eventState) + ' ' + toggleBtn + '</td>'
                        + '<td>' + esc(e.ticketsSold || 0) + '</td>'
                        + '<td class="btcpay-satoshi-event-actions">'
                        + '<button type="button" class="button button-small btcpay-satoshi-load-tickets">' + esc(s.addTicketType ? 'Ticket Types' : 'Ticket Types') + '</button> '
                        + '<button type="button" class="button button-small btcpay-satoshi-view-tickets" data-event-id="' + esc(eventId) + '">' + esc(s.viewTickets) + '</button> '
                        + '<button type="button" class="button button-small btcpay-satoshi-view-orders" data-event-id="' + esc(eventId) + '">' + esc(s.viewOrders) + '</button> '
                        + '<button type="button" class="button button-small btcpay-satoshi-edit-event" data-event-id="' + esc(eventId) + '">' + esc(s.editEvent) + '</button> '
                        + '<button type="button" class="button button-small btcpay-satoshi-delete-event" data-event-id="' + esc(eventId) + '" style="color:#a00;">' + esc(s.deleteEvent) + '</button>'
                        + checkinLink
                        + '</td></tr>';
                }).join('');
                $list.html('<table class="wp-list-table widefat fixed striped"><thead><tr>'
                    + '<th>Event</th><th>Start Date</th><th>Status</th><th>Sold</th><th>Actions</th>'
                    + '</tr></thead><tbody>' + rows + '</tbody></table>');
            } else {
                $list.html('<p>' + (r.data && r.data.message ? esc(r.data.message) : 'No active events found.') + '</p>');
            }
        }).fail(function () {
            $list.html('<p class="notice notice-error">' + s.error + '</p>');
        });
    }

    $(document).on('click', '#btcpay-satoshi-refresh-events', refreshEvents);

    /* -----------------------------------------------------------------------
     * Toggle event
     * --------------------------------------------------------------------- */

    $(document).on('click', '.btcpay-satoshi-toggle-event', function () {
        var $btn = $(this).prop('disabled', true);
        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_toggle_event',
            nonce: btcpaySatoshiAdmin.nonce,
            eventId: $btn.data('event-id')
        }).done(function (r) {
            if (r.success) { refreshEvents(); }
            else { alert(r.data && r.data.message ? r.data.message : s.error); }
        }).fail(function () { alert(s.error); })
          .always(function () { $btn.prop('disabled', false); });
    });

    /* -----------------------------------------------------------------------
     * Delete event
     * --------------------------------------------------------------------- */

    $(document).on('click', '.btcpay-satoshi-delete-event', function () {
        var eventId = String($(this).data('event-id') || '');
        if (!eventId) return;
        if (!confirm(s.deleteEventConfirm)) return;
        var $btn = $(this).prop('disabled', true);
        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_delete_event',
            nonce: btcpaySatoshiAdmin.nonce,
            eventId: eventId
        }).done(function (r) {
            if (r.success) { refreshEvents(); }
            else { alert(r.data && r.data.message ? r.data.message : s.error); $btn.prop('disabled', false); }
        }).fail(function () { alert(s.error); $btn.prop('disabled', false); });
    });

    /* -----------------------------------------------------------------------
     * Load ticket types
     * --------------------------------------------------------------------- */

    function loadTicketTypes(eventId, eventTitle) {
        currentEventId = String(eventId);
        currentEventTitle = eventTitle || eventId;
        $('#btcpay-satoshi-event-title').text(currentEventTitle);
        $('#btcpay-satoshi-ticket-types-list').html('<p>' + s.loading + '</p>');
        $('#btcpay-satoshi-ticket-types-section').show();

        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_get_ticket_types',
            nonce: btcpaySatoshiAdmin.nonce,
            eventId: String(eventId)
        }, null, 'json').done(function (r) {
            if (r.success && r.data && r.data.length > 0) {
                var rows = r.data.map(function (tt) {
                    var ttId = tt.id || '';
                    var qty = tt.quantity;
                    if (qty === undefined) {
                        var avail = tt.quantityAvailable;
                        var sold = tt.quantitySold || 0;
                        qty = (avail !== undefined ? avail : 0) + sold;
                    }
                    if (qty === undefined || qty === null) qty = 999999;
                    var ttState = tt.ticketTypeState || tt.TicketTypeState || 'Active';
                    var isActive = (ttState === 'Active');
                    var isDefault = !!(tt.isDefault || tt.IsDefault);
                    var hasProduct = tt.hasProduct || (tt.productCount && tt.productCount > 0);
                    var productCount = tt.productCount || 0;
                    var productBadge = hasProduct
                        ? ' <span class="btcpay-satoshi-has-product">&#10003; ' + esc(s.hasProduct) + (productCount > 1 ? ' (' + productCount + ')' : '') + '</span>'
                        : ' <span class="btcpay-satoshi-no-product">' + esc(s.noProduct) + '</span>';
                    var defaultBadge = isDefault ? ' <span class="btcpay-satoshi-default-badge">' + esc(s.defaultBadge) + '</span>' : '';
                    var dataAttrs = 'data-event-id="' + esc(eventId) + '" data-ticket-type-id="' + esc(ttId) + '" '
                        + 'data-name="' + esc(tt.name || '') + '" data-price="' + esc(tt.price || 0) + '" '
                        + 'data-description="' + esc(tt.description || '') + '" '
                        + 'data-quantity="' + esc(qty === 999999 ? '' : qty) + '" '
                        + 'data-is-default="' + (isDefault ? '1' : '0') + '"';
                    return '<tr data-ticket-type-id="' + esc(ttId) + '" class="' + (hasProduct ? 'btcpay-satoshi-tt-has-product' : 'btcpay-satoshi-tt-no-product') + '">'
                        + '<td>' + esc(tt.name || '') + defaultBadge + productBadge + '</td>'
                        + '<td>' + esc(tt.price || 0) + '</td>'
                        + '<td>' + esc(tt.quantityAvailable !== undefined ? tt.quantityAvailable : ((tt.quantity || 0) - (tt.quantitySold || 0))) + '</td>'
                        + '<td>' + stateBadge(ttState) + '</td>'
                        + '<td>'
                        + '<button type="button" class="button button-small btcpay-satoshi-create-product" ' + dataAttrs + '>' + esc(s.createProduct) + '</button> '
                        + '<button type="button" class="button button-small btcpay-satoshi-sync-stock" ' + dataAttrs + '>' + esc(s.syncStock) + '</button> '
                        + '<button type="button" class="button button-small btcpay-satoshi-sync-from-btcpay" ' + dataAttrs + '>' + esc(s.syncFromBtcpay) + '</button> '
                        + '<button type="button" class="button button-small btcpay-satoshi-edit-ticket-type" ' + dataAttrs + '>' + esc(s.editTicketType) + '</button> '
                        + '<button type="button" class="button button-small btcpay-satoshi-toggle-tt" ' + dataAttrs + '>' + esc(isActive ? s.disable : s.enable) + '</button> '
                        + '<button type="button" class="button button-small btcpay-satoshi-delete-tt" ' + dataAttrs + ' style="color:#a00;">' + esc(s.deleteTT) + '</button>'
                        + '</td></tr>';
                }).join('');
                $('#btcpay-satoshi-ticket-types-list').html(
                    '<table class="wp-list-table widefat fixed striped btcpay-satoshi-ticket-types-table">'
                    + '<thead><tr><th>Name</th><th>Price</th><th>Available</th><th>Status</th><th>Actions</th></tr></thead>'
                    + '<tbody>' + rows + '</tbody></table>'
                );
            } else if (r.success) {
                $('#btcpay-satoshi-ticket-types-list').html('<p>No ticket types found.</p>');
            } else {
                var errMsg = (r.data && r.data.message) ? r.data.message : s.error;
                $('#btcpay-satoshi-ticket-types-list').html('<p class="notice notice-error">' + esc(errMsg) + '</p>');
            }
        }).fail(function (jqXHR) {
            var errMsg = s.error;
            try {
                var parsed = JSON.parse(jqXHR.responseText);
                if (parsed.data && parsed.data.message) errMsg = parsed.data.message;
            } catch (e) {}
            if (jqXHR.status) errMsg += ' (HTTP ' + jqXHR.status + ')';
            $('#btcpay-satoshi-ticket-types-list').html('<p class="notice notice-error">' + esc(errMsg) + '</p>');
        });
    }

    $(document).on('click', '.btcpay-satoshi-load-tickets', function () {
        var $row = $(this).closest('tr');
        var eventId = String($row.data('event-id') || $row.attr('data-event-id') || '');
        var eventTitle = $row.find('td:first strong').text();
        if (!eventId) { return; }
        loadTicketTypes(eventId, eventTitle);
    });

    /* -----------------------------------------------------------------------
     * Toggle ticket type
     * --------------------------------------------------------------------- */

    $(document).on('click', '.btcpay-satoshi-toggle-tt', function () {
        var $btn = $(this).prop('disabled', true);
        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_toggle_ticket_type',
            nonce: btcpaySatoshiAdmin.nonce,
            eventId: $btn.data('event-id'),
            ticketTypeId: $btn.data('ticket-type-id')
        }).done(function (r) {
            if (r.success) { loadTicketTypes(currentEventId, currentEventTitle); }
            else { alert(r.data && r.data.message ? r.data.message : s.error); $btn.prop('disabled', false); }
        }).fail(function () { alert(s.error); $btn.prop('disabled', false); });
    });

    /* -----------------------------------------------------------------------
     * Delete ticket type
     * --------------------------------------------------------------------- */

    $(document).on('click', '.btcpay-satoshi-delete-tt', function () {
        if (!confirm(s.deleteTTConfirm)) return;
        var $btn = $(this).prop('disabled', true);
        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_delete_ticket_type',
            nonce: btcpaySatoshiAdmin.nonce,
            eventId: $btn.data('event-id'),
            ticketTypeId: $btn.data('ticket-type-id')
        }).done(function (r) {
            if (r.success) { loadTicketTypes(currentEventId, currentEventTitle); }
            else { alert(r.data && r.data.message ? r.data.message : s.error); $btn.prop('disabled', false); }
        }).fail(function () { alert(s.error); $btn.prop('disabled', false); });
    });

    /* -----------------------------------------------------------------------
     * Create WooCommerce product
     * --------------------------------------------------------------------- */

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
            if (r.success && r.data && r.data.editUrl) { window.location.href = r.data.editUrl; }
            else { alert(r.data && r.data.message ? r.data.message : 'Error creating product'); $btn.prop('disabled', false); }
        }).fail(function () { alert(s.error); $btn.prop('disabled', false); });
    });

    /* -----------------------------------------------------------------------
     * Sync stock
     * --------------------------------------------------------------------- */

    $(document).on('click', '.btcpay-satoshi-sync-stock', function () {
        var $btn = $(this).prop('disabled', true);
        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_sync_stock',
            nonce: btcpaySatoshiAdmin.nonce,
            eventId: $btn.data('event-id'),
            ticketTypeId: $btn.data('ticket-type-id')
        }).done(function (r) {
            if (r.success) {
                var msg = s.synced + (r.data && r.data.updated !== undefined ? ' (' + r.data.updated + ' product(s), qty: ' + (r.data.quantity || 0) + ')' : '');
                $btn.after('<span class="satoshi-sync-ok" style="margin-left:6px;color:green;">' + esc(msg) + '</span>');
                setTimeout(function () { $btn.siblings('.satoshi-sync-ok').fadeOut(function () { $(this).remove(); }); }, 3000);
            } else { alert(r.data && r.data.message ? r.data.message : s.error); }
        }).fail(function () { alert(s.error); })
          .always(function () { $btn.prop('disabled', false); });
    });

    /* -----------------------------------------------------------------------
     * Sync from BTCPay
     * --------------------------------------------------------------------- */

    $(document).on('click', '.btcpay-satoshi-sync-from-btcpay', function () {
        var $btn = $(this).prop('disabled', true);
        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_sync_ticket_type_from_btcpay',
            nonce: btcpaySatoshiAdmin.nonce,
            eventId: $btn.data('event-id'),
            ticketTypeId: $btn.data('ticket-type-id')
        }).done(function (r) {
            if (r.success) {
                $btn.after('<span class="satoshi-sync-ok" style="margin-left:6px;color:green;">' + esc(s.syncedFromBtcpay) + '</span>');
                setTimeout(function () { $btn.siblings('.satoshi-sync-ok').fadeOut(function () { $(this).remove(); }); }, 3000);
            } else { alert(r.data && r.data.message ? r.data.message : s.error); }
        }).fail(function () { alert(s.error); })
          .always(function () { $btn.prop('disabled', false); });
    });

    /* -----------------------------------------------------------------------
     * Event form
     * --------------------------------------------------------------------- */

    function resetEventForm() {
        $('#st-event-edit-id').val('');
        $('#st-event-form-title').text(s.createEvent || 'Create event');
        $('#st-event-form-mode-hint').hide();
        $('#btcpay-satoshi-submit-event').text(s.createEvent || 'Create event');
        $('#st-event-logo-current').empty();
        $('#btcpay-satoshi-remove-logo').hide();
    }

    $(document).on('click', '#btcpay-satoshi-add-event', function () {
        resetEventForm();
        $('#st-event-title,#st-event-start,#st-event-end,#st-event-desc,#st-event-location').val('');
        $('#st-event-currency,#st-event-redirect,#st-event-email-subject,#st-event-email-body').val('');
        $('#st-event-max-capacity').val('');
        $('#st-event-type').val('Physical');
        $('#st-event-enable,#st-event-has-capacity').prop('checked', false);
        $('#st-event-max-capacity-row').hide();
        $('#st-event-logo-file').val('');
        $('#btcpay-satoshi-add-event-form').slideToggle();
    });

    $(document).on('change', '#st-event-has-capacity', function () {
        $('#st-event-max-capacity-row').toggle($(this).is(':checked'));
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
                $('#st-event-form-title').text(s.editEvent || 'Edit event');
                $('#st-event-form-mode-hint').show();
                $('#btcpay-satoshi-submit-event').text('Save');

                $('#st-event-title').val(e.title || e.Title || '');
                $('#st-event-start').val(((e.startDate || e.StartDate || '').replace(' ', 'T')).substring(0, 16));
                $('#st-event-end').val(((e.endDate || e.EndDate || '').replace(' ', 'T')).substring(0, 16));
                $('#st-event-desc').val(e.description || e.Description || '');
                $('#st-event-location').val(e.location || e.Location || '');
                $('#st-event-type').val(e.eventType || e.EventType || 'Physical');
                $('#st-event-currency').val(e.currency || e.Currency || '');
                $('#st-event-redirect').val(e.redirectUrl || e.RedirectUrl || '');
                $('#st-event-email-subject').val(e.emailSubject || e.EmailSubject || '');
                $('#st-event-email-body').val(e.emailBody || e.EmailBody || '');
                var hasCap = !!(e.hasMaximumCapacity || e.HasMaximumCapacity);
                $('#st-event-has-capacity').prop('checked', hasCap);
                $('#st-event-max-capacity-row').toggle(hasCap);
                $('#st-event-max-capacity').val(e.maximumEventCapacity || e.MaximumEventCapacity || '');
                $('#st-event-enable').prop('checked', !!(e.enable !== undefined ? e.enable : (e.eventState || e.EventState) === 'Active'));

                // Show current logo if any
                var logoUrl = e.eventLogoUrl || e.EventLogoUrl || '';
                var $logoDiv = $('#st-event-logo-current');
                if (logoUrl) {
                    $logoDiv.html('<img src="' + esc(logoUrl) + '" style="max-height:80px;max-width:200px;display:block;margin-bottom:6px;" />'
                        + '<span style="font-size:11px;color:#666;">' + esc(logoUrl) + '</span>');
                    $('#btcpay-satoshi-remove-logo').show().data('event-id', eventId);
                } else {
                    $logoDiv.empty();
                    $('#btcpay-satoshi-remove-logo').hide();
                }
                $('#btcpay-satoshi-upload-logo').data('event-id', eventId);
                $('#st-event-logo-file').val('');

                $('#btcpay-satoshi-add-event-form').slideDown();
            } else {
                alert(r.data && r.data.message ? r.data.message : s.error);
            }
        }).fail(function () { alert(s.error); })
          .always(function () { $btn.prop('disabled', false); });
    });

    $(document).on('click', '#btcpay-satoshi-submit-event', function () {
        var $btn = $(this).prop('disabled', true);
        var editId = $('#st-event-edit-id').val();
        var isEdit = !!editId;

        function normalizeDate(val) {
            if (!val) return '';
            if (val.length === 10) return val + 'T00:00:00';
            if (val.indexOf('T') >= 0 && val.length === 16) return val + ':00';
            return val;
        }

        var payload = {
            action: isEdit ? 'btcpay_satoshi_update_event' : 'btcpay_satoshi_create_event',
            nonce: btcpaySatoshiAdmin.nonce,
            title: $('#st-event-title').val(),
            startDate: normalizeDate($('#st-event-start').val()),
            endDate: normalizeDate($('#st-event-end').val()),
            description: $('#st-event-desc').val(),
            location: $('#st-event-location').val(),
            eventType: $('#st-event-type').val(),
            currency: $('#st-event-currency').val(),
            redirectUrl: $('#st-event-redirect').val(),
            emailSubject: $('#st-event-email-subject').val(),
            emailBody: $('#st-event-email-body').val(),
            hasMaximumCapacity: $('#st-event-has-capacity').is(':checked') ? 1 : 0,
            maximumEventCapacity: $('#st-event-max-capacity').val(),
            enable: $('#st-event-enable').is(':checked') ? 1 : 0
        };
        if (isEdit) payload.eventId = editId;

        $.post(btcpaySatoshiAdmin.ajaxUrl, payload).done(function (r) {
            if (r.success) {
                $('#btcpay-satoshi-add-event-form').slideUp();
                resetEventForm();
                refreshEvents();
                alert(isEdit ? (s.eventUpdated || 'Event updated.') : (s.eventCreated || 'Event created.'));
            } else {
                alert(r.data && r.data.message ? r.data.message : s.error);
            }
        }).fail(function () { alert(s.error); })
          .always(function () { $btn.prop('disabled', false); });
    });

    /* -----------------------------------------------------------------------
     * Logo upload / remove
     * --------------------------------------------------------------------- */

    $(document).on('click', '#btcpay-satoshi-upload-logo', function () {
        var eventId = $(this).data('event-id') || $('#st-event-edit-id').val();
        if (!eventId) { alert('Save the event first, then upload a logo.'); return; }
        var file = $('#st-event-logo-file')[0].files[0];
        if (!file) { alert('Please select an image file.'); return; }
        var formData = new FormData();
        formData.append('action', 'btcpay_satoshi_upload_event_logo');
        formData.append('nonce', btcpaySatoshiAdmin.nonce);
        formData.append('eventId', eventId);
        formData.append('logo', file);
        var $btn = $(this).prop('disabled', true);
        $.ajax({
            url: btcpaySatoshiAdmin.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false
        }).done(function (r) {
            if (r.success) {
                alert(s.logoUploaded);
                // Reload event data to show new logo
                $('.btcpay-satoshi-edit-event[data-event-id="' + eventId + '"]').trigger('click');
            } else {
                alert(r.data && r.data.message ? r.data.message : s.error);
            }
        }).fail(function () { alert(s.error); })
          .always(function () { $btn.prop('disabled', false); });
    });

    $(document).on('click', '#btcpay-satoshi-remove-logo', function () {
        var eventId = $(this).data('event-id') || $('#st-event-edit-id').val();
        if (!eventId) return;
        var $btn = $(this).prop('disabled', true);
        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_delete_event_logo',
            nonce: btcpaySatoshiAdmin.nonce,
            eventId: eventId
        }).done(function (r) {
            if (r.success) {
                $('#st-event-logo-current').empty();
                $btn.hide();
                alert(s.logoRemoved);
            } else {
                alert(r.data && r.data.message ? r.data.message : s.error);
            }
        }).fail(function () { alert(s.error); })
          .always(function () { $btn.prop('disabled', false); });
    });

    /* -----------------------------------------------------------------------
     * Ticket type form
     * --------------------------------------------------------------------- */

    function resetTicketTypeForm() {
        $('#st-tt-edit-id').val('');
        $('#st-tt-form-title').text(s.createTicketType || 'Create ticket type');
        $('#st-tt-form-mode-hint').hide();
        $('#btcpay-satoshi-submit-tt').text(s.createTicketType || 'Create ticket type');
    }

    $(document).on('click', '#btcpay-satoshi-add-ticket-type', function () {
        resetTicketTypeForm();
        $('#st-tt-name,#st-tt-price,#st-tt-desc,#st-tt-qty').val('');
        $('#st-tt-is-default').prop('checked', false);
        $('#btcpay-satoshi-add-tt-form').slideToggle();
    });

    $(document).on('click', '#btcpay-satoshi-cancel-tt', function () {
        $('#btcpay-satoshi-add-tt-form').slideUp();
        resetTicketTypeForm();
    });

    $(document).on('click', '.btcpay-satoshi-edit-ticket-type', function () {
        var $el = $(this);
        $('#st-tt-edit-id').val($el.data('ticketTypeId') || $el.attr('data-ticket-type-id') || '');
        $('#st-tt-form-title').text(s.editTicketType || 'Edit ticket type');
        $('#st-tt-form-mode-hint').show();
        $('#btcpay-satoshi-submit-tt').text('Save');
        $('#st-tt-name').val($el.attr('data-name') || '');
        $('#st-tt-price').val($el.attr('data-price') || 0);
        $('#st-tt-desc').val($el.attr('data-description') || '');
        $('#st-tt-qty').val($el.attr('data-quantity') || '');
        $('#st-tt-is-default').prop('checked', $el.attr('data-is-default') === '1');
        $('#btcpay-satoshi-add-tt-form').slideDown();
    });

    $(document).on('click', '#btcpay-satoshi-submit-tt', function () {
        if (!currentEventId) { alert('Please select an event first.'); return; }
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
            quantity: $('#st-tt-qty').val() || '',
            isDefault: $('#st-tt-is-default').is(':checked') ? 1 : 0
        };
        if (isEdit) payload.ticketTypeId = editId;
        $.post(btcpaySatoshiAdmin.ajaxUrl, payload).done(function (r) {
            if (r.success) {
                $('#btcpay-satoshi-add-tt-form').slideUp();
                resetTicketTypeForm();
                $('#st-tt-name,#st-tt-price,#st-tt-desc,#st-tt-qty').val('');
                $('#st-tt-is-default').prop('checked', false);
                loadTicketTypes(currentEventId, currentEventTitle);
                alert(isEdit ? (s.ticketTypeUpdated || 'Ticket type updated.') : (s.ticketTypeCreated || 'Ticket type created.'));
            } else {
                alert(r.data && r.data.message ? r.data.message : s.error);
            }
        }).fail(function () { alert(s.error); })
          .always(function () { $btn.prop('disabled', false); });
    });

    /* -----------------------------------------------------------------------
     * Fulfill tickets (non-Bitcoin orders)
     * --------------------------------------------------------------------- */

    $(document).on('click', '.btcpay-satoshi-fulfill-btn', function () {
        var $btn = $(this).prop('disabled', true);
        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_fulfill_tickets',
            nonce: btcpaySatoshiAdmin.nonce,
            orderId: $btn.data('order-id')
        }).done(function (r) {
            if (r.success) {
                $btn.closest('tr').fadeOut(function () { $(this).remove(); });
                alert(s.ticketsCreated || 'Tickets created.');
            } else {
                alert(r.data && r.data.message ? r.data.message : s.error);
            }
        }).fail(function () { alert(s.error); })
          .always(function () { $btn.prop('disabled', false); });
    });

    /* -----------------------------------------------------------------------
     * View Tickets section
     * --------------------------------------------------------------------- */

    function loadTicketsSection(eventId, eventTitle) {
        currentTicketsEventId = eventId;
        $('#btcpay-satoshi-tickets-event-title').text(eventTitle || eventId);
        $('#btcpay-satoshi-export-tickets-link').attr('href', buildExportUrl(eventId));
        $('#btcpay-satoshi-tickets-section').show();
        $('#btcpay-satoshi-checkin-result').empty();
        $('#st-checkin-input').val('');
        loadTickets(eventId, '');
    }

    function loadTickets(eventId, search) {
        var $list = $('#btcpay-satoshi-tickets-list');
        var $stats = $('#btcpay-satoshi-tickets-stats');
        $list.html('<p>' + s.loading + '</p>');
        $stats.empty();

        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_get_tickets',
            nonce: btcpaySatoshiAdmin.nonce,
            eventId: eventId,
            search: search
        }).done(function (r) {
            if (!r.success) {
                $list.html('<p class="notice notice-error">' + esc(r.data && r.data.message ? r.data.message : s.error) + '</p>');
                return;
            }
            var tickets = r.data || [];
            if (!tickets.length) {
                $list.html('<p>' + s.noTickets + '</p>');
                return;
            }
            var total = tickets.length;
            var checkedIn = tickets.filter(function (t) { return !!(t.usedAt || t.UsedAt || t.checkedIn || t.CheckedIn); }).length;
            $stats.text('Total: ' + total + ' | Checked in: ' + checkedIn);

            var rows = tickets.map(function (t) {
                var ticketId = t.id || t.Id || '';
                var orderId  = t.orderId || t.OrderId || '';
                var usedAt   = t.usedAt || t.UsedAt || t.checkedInAt || t.CheckedInAt || '';
                var isCheckedIn = !!(usedAt || t.checkedIn || t.CheckedIn);
                var statusBadge = isCheckedIn
                    ? '<span class="btcpay-satoshi-status-badge btcpay-satoshi-status-active">' + esc(s.checkedIn) + '</span>'
                    : '<span class="btcpay-satoshi-status-badge btcpay-satoshi-status-disabled">' + esc(s.notCheckedIn) + '</span>';
                var checkedInTime = isCheckedIn ? esc(usedAt) : '-';
                var reminderBtn = (orderId && ticketId)
                    ? '<button type="button" class="button button-small btcpay-satoshi-send-reminder" '
                        + 'data-event-id="' + esc(eventId) + '" data-order-id="' + esc(orderId) + '" data-ticket-id="' + esc(ticketId) + '">'
                        + esc(s.sendReminder) + '</button>'
                    : '';
                return '<tr>'
                    + '<td>' + esc(t.ticketNumber || t.TicketNumber || '') + '</td>'
                    + '<td>' + esc((t.firstName || t.FirstName || '') + ' ' + (t.lastName || t.LastName || '')) + '</td>'
                    + '<td>' + esc(t.email || t.Email || '') + '</td>'
                    + '<td>' + esc(t.ticketTypeName || t.TicketTypeName || '') + '</td>'
                    + '<td>' + esc(t.amount || t.Amount || '') + '</td>'
                    + '<td>' + statusBadge + ' ' + checkedInTime + '</td>'
                    + '<td>' + reminderBtn + '</td>'
                    + '</tr>';
            }).join('');

            $list.html('<table class="wp-list-table widefat fixed striped">'
                + '<thead><tr><th>Ticket #</th><th>Name</th><th>Email</th><th>Type</th><th>Amount</th><th>Check-in</th><th>Action</th></tr></thead>'
                + '<tbody>' + rows + '</tbody></table>');
        }).fail(function () {
            $list.html('<p class="notice notice-error">' + s.error + '</p>');
        });
    }

    $(document).on('click', '.btcpay-satoshi-view-tickets', function () {
        var $row = $(this).closest('tr');
        var eventId = String($(this).data('event-id') || $row.data('event-id') || '');
        var eventTitle = $row.find('td:first strong').text();
        if (!eventId) return;
        loadTicketsSection(eventId, eventTitle);
        $('html, body').animate({ scrollTop: $('#btcpay-satoshi-tickets-section').offset().top - 40 }, 400);
    });

    $(document).on('click', '#btcpay-satoshi-search-tickets', function () {
        if (!currentTicketsEventId) return;
        loadTickets(currentTicketsEventId, $('#st-tickets-search').val());
    });

    $(document).on('keypress', '#st-tickets-search', function (e) {
        if (e.which === 13) { $('#btcpay-satoshi-search-tickets').trigger('click'); }
    });

    /* -----------------------------------------------------------------------
     * Send reminder email
     * --------------------------------------------------------------------- */

    $(document).on('click', '.btcpay-satoshi-send-reminder', function () {
        var $btn = $(this).prop('disabled', true);
        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_send_reminder',
            nonce: btcpaySatoshiAdmin.nonce,
            eventId: $btn.data('event-id'),
            orderId: $btn.data('order-id'),
            ticketId: $btn.data('ticket-id')
        }).done(function (r) {
            if (r.success) {
                $btn.after('<span style="margin-left:6px;color:green;">' + esc(s.reminderSent) + '</span>');
                setTimeout(function () { $btn.siblings('span').fadeOut(function () { $(this).remove(); }); }, 3000);
            } else {
                alert(r.data && r.data.message ? r.data.message : s.error);
            }
        }).fail(function () { alert(s.error); })
          .always(function () { $btn.prop('disabled', false); });
    });

    /* -----------------------------------------------------------------------
     * Manual check-in
     * --------------------------------------------------------------------- */

    $(document).on('click', '#btcpay-satoshi-checkin-btn', function () {
        if (!currentTicketsEventId) { alert('Select an event first.'); return; }
        var ticketNumber = $.trim($('#st-checkin-input').val());
        if (!ticketNumber) { alert('Enter a ticket number.'); return; }
        var $btn = $(this).prop('disabled', true);
        var $result = $('#btcpay-satoshi-checkin-result');
        $result.empty();

        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_checkin_ticket',
            nonce: btcpaySatoshiAdmin.nonce,
            eventId: currentTicketsEventId,
            ticketNumber: ticketNumber
        }).done(function (r) {
            if (r.success) {
                var d = r.data || {};
                var ticket = d.ticket || d.Ticket || d;
                var msg = s.checkinSuccess + ' — '
                    + esc((ticket.firstName || ticket.FirstName || '') + ' ' + (ticket.lastName || ticket.LastName || ''))
                    + ' (' + esc(ticket.ticketNumber || ticket.TicketNumber || ticketNumber) + ')';
                $result.html('<div class="notice notice-success inline" style="margin:0;padding:6px 12px;">' + msg + '</div>');
                $('#st-checkin-input').val('');
                loadTickets(currentTicketsEventId, '');
            } else {
                var errMsg = (r.data && r.data.message) ? r.data.message : s.checkinError;
                $result.html('<div class="notice notice-error inline" style="margin:0;padding:6px 12px;">' + esc(errMsg) + '</div>');
            }
        }).fail(function () {
            $result.html('<div class="notice notice-error inline" style="margin:0;padding:6px 12px;">' + s.error + '</div>');
        }).always(function () { $btn.prop('disabled', false); });
    });

    $(document).on('keypress', '#st-checkin-input', function (e) {
        if (e.which === 13) { $('#btcpay-satoshi-checkin-btn').trigger('click'); }
    });

    /* -----------------------------------------------------------------------
     * View Orders section
     * --------------------------------------------------------------------- */

    function loadOrdersSection(eventId, eventTitle) {
        currentOrdersEventId = eventId;
        $('#btcpay-satoshi-orders-event-title').text(eventTitle || eventId);
        $('#btcpay-satoshi-orders-section').show();
        loadOrders(eventId, '');
    }

    function loadOrders(eventId, search) {
        var $list = $('#btcpay-satoshi-orders-list');
        $list.html('<p>' + s.loading + '</p>');

        $.post(btcpaySatoshiAdmin.ajaxUrl, {
            action: 'btcpay_satoshi_get_orders',
            nonce: btcpaySatoshiAdmin.nonce,
            eventId: eventId,
            search: search
        }).done(function (r) {
            if (!r.success) {
                $list.html('<p class="notice notice-error">' + esc(r.data && r.data.message ? r.data.message : s.error) + '</p>');
                return;
            }
            var orders = r.data || [];
            if (!orders.length) {
                $list.html('<p>' + s.noOrders + '</p>');
                return;
            }
            var rows = orders.map(function (o) {
                var status = o.paymentStatus || o.PaymentStatus || '';
                var ticketCount = (o.tickets || o.Tickets || []).length;
                return '<tr>'
                    + '<td>' + esc(o.id || o.Id || '') + '</td>'
                    + '<td>' + esc(o.createdAt || o.CreatedAt || '') + '</td>'
                    + '<td>' + esc(o.totalAmount || o.TotalAmount || '') + ' ' + esc(o.currency || o.Currency || '') + '</td>'
                    + '<td>' + esc(status) + '</td>'
                    + '<td>' + esc(ticketCount) + '</td>'
                    + '<td>' + esc(o.invoiceId || o.InvoiceId || '') + '</td>'
                    + '</tr>';
            }).join('');

            $list.html('<table class="wp-list-table widefat fixed striped">'
                + '<thead><tr><th>Order ID</th><th>Date</th><th>Total</th><th>Status</th><th>Tickets</th><th>Invoice ID</th></tr></thead>'
                + '<tbody>' + rows + '</tbody></table>');
        }).fail(function () {
            $list.html('<p class="notice notice-error">' + s.error + '</p>');
        });
    }

    $(document).on('click', '.btcpay-satoshi-view-orders', function () {
        var $row = $(this).closest('tr');
        var eventId = String($(this).data('event-id') || $row.data('event-id') || '');
        var eventTitle = $row.find('td:first strong').text();
        if (!eventId) return;
        loadOrdersSection(eventId, eventTitle);
        $('html, body').animate({ scrollTop: $('#btcpay-satoshi-orders-section').offset().top - 40 }, 400);
    });

    $(document).on('click', '#btcpay-satoshi-search-orders', function () {
        if (!currentOrdersEventId) return;
        loadOrders(currentOrdersEventId, $('#st-orders-search').val());
    });

    $(document).on('keypress', '#st-orders-search', function (e) {
        if (e.which === 13) { $('#btcpay-satoshi-search-orders').trigger('click'); }
    });

})(jQuery);
