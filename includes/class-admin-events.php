<?php
/**
 * Admin page: Satoshi Tickets - Events & Ticket Types.
 *
 * @package BTCPaySatoshiTickets
 */

declare(strict_types=1);

namespace BTCPaySatoshiTickets;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminEvents
{
    public static function init(): void
    {
        add_action('wp_ajax_btcpay_satoshi_get_events', [__CLASS__, 'ajaxGetEvents']);
        add_action('wp_ajax_btcpay_satoshi_get_ticket_types', [__CLASS__, 'ajaxGetTicketTypes']);
        add_action('wp_ajax_btcpay_satoshi_create_product', [__CLASS__, 'ajaxCreateProduct']);
        add_action('wp_ajax_btcpay_satoshi_sync_stock', [__CLASS__, 'ajaxSyncStock']);
        add_action('wp_ajax_btcpay_satoshi_sync_ticket_type_from_btcpay', [__CLASS__, 'ajaxSyncTicketTypeFromBtcpay']);
        add_action('wp_ajax_btcpay_satoshi_create_event', [__CLASS__, 'ajaxCreateEvent']);
        add_action('wp_ajax_btcpay_satoshi_create_ticket_type', [__CLASS__, 'ajaxCreateTicketType']);
        add_action('wp_ajax_btcpay_satoshi_update_event', [__CLASS__, 'ajaxUpdateEvent']);
        add_action('wp_ajax_btcpay_satoshi_update_ticket_type', [__CLASS__, 'ajaxUpdateTicketType']);
        add_action('wp_ajax_btcpay_satoshi_get_event', [__CLASS__, 'ajaxGetEvent']);
        add_action('wp_ajax_btcpay_satoshi_fulfill_tickets', [__CLASS__, 'ajaxFulfillTickets']);
        add_action('wp_ajax_btcpay_satoshi_toggle_event', [__CLASS__, 'ajaxToggleEvent']);
        add_action('wp_ajax_btcpay_satoshi_delete_event', [__CLASS__, 'ajaxDeleteEvent']);
        add_action('wp_ajax_btcpay_satoshi_delete_ticket_type', [__CLASS__, 'ajaxDeleteTicketType']);
        add_action('wp_ajax_btcpay_satoshi_toggle_ticket_type', [__CLASS__, 'ajaxToggleTicketType']);
        add_action('wp_ajax_btcpay_satoshi_get_tickets', [__CLASS__, 'ajaxGetTickets']);
        add_action('wp_ajax_btcpay_satoshi_checkin_ticket', [__CLASS__, 'ajaxCheckInTicket']);
        add_action('wp_ajax_btcpay_satoshi_get_orders', [__CLASS__, 'ajaxGetOrders']);
        add_action('wp_ajax_btcpay_satoshi_send_reminder', [__CLASS__, 'ajaxSendReminder']);
        add_action('wp_ajax_btcpay_satoshi_upload_event_logo', [__CLASS__, 'ajaxUploadEventLogo']);
        add_action('wp_ajax_btcpay_satoshi_delete_event_logo', [__CLASS__, 'ajaxDeleteEventLogo']);
        add_action('admin_post_btcpay_satoshi_export_tickets', [__CLASS__, 'handleExportTickets']);
    }

    public static function renderPage(): void
    {
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            $settingsUrl = AdminWCSettingsTab::getTabUrl(AdminWCSettingsTab::SECTION_CONNECTION);
            echo '<div class="wrap"><h1>' . esc_html__('Satoshi Tickets', 'btcpay-satoshi-tickets') . '</h1>';
            echo '<p class="notice notice-error">' . esc_html__('BTCPay Server is not configured.', 'btcpay-satoshi-tickets') .
                ' <a href="' . esc_url($settingsUrl) . '">' . esc_html__('Configure connection', 'btcpay-satoshi-tickets') . '</a></p></div>';
            return;
        }

        $events = $client->getEvents();
        $eventsList = $events['success'] ? ($events['data'] ?? []) : [];
        $eventsError = $events['success'] ? '' : ($events['message'] ?? '');
        $hasWebhook = WebhookHandler::hasWebhookSecret();
        $viaSatflux = AdminWCSettingsTab::isConnectedViaSatflux();
        ?>
        <div class="wrap btcpay-satoshi-admin">
            <h1><?php esc_html_e('Satoshi Tickets', 'btcpay-satoshi-tickets'); ?></h1>
            <div class="btcpay-satoshi-status-bar" style="margin-bottom:16px;">
                <?php if ($hasWebhook) : ?>
                    <span class="btcpay-satoshi-status btcpay-satoshi-status-webhook-ok"><?php esc_html_e('Webhook: Registered', 'btcpay-satoshi-tickets'); ?></span>
                <?php else :
                    $connUrl = AdminWCSettingsTab::getTabUrl(AdminWCSettingsTab::SECTION_CONNECTION);
                    $webhookUrl = add_query_arg('btcpay_satoshi_register_webhook', '1', $connUrl);
                    ?>
                    <span class="btcpay-satoshi-status btcpay-satoshi-status-webhook-no">
                        <?php esc_html_e('Webhook: Not registered', 'btcpay-satoshi-tickets'); ?>
                        <a href="<?php echo esc_url(wp_nonce_url($webhookUrl, 'btcpay_satoshi_register_webhook')); ?>" style="margin-left:6px;"><?php esc_html_e('Register now', 'btcpay-satoshi-tickets'); ?></a>
                    </span>
                <?php endif; ?>
                <?php if ($viaSatflux) : ?>
                    <span class="btcpay-satoshi-status btcpay-satoshi-status-satflux"><?php esc_html_e('Connected via Satflux.io', 'btcpay-satoshi-tickets'); ?></span>
                <?php endif; ?>
            </div>
            <p><?php esc_html_e('Import events and ticket types from your BTCPay Server SatoshiTickets plugin. Create WooCommerce products linked to ticket types.', 'btcpay-satoshi-tickets'); ?></p>

            <div class="btcpay-satoshi-events-section">
                <h2><?php esc_html_e('Available Events', 'btcpay-satoshi-tickets'); ?></h2>
                <button type="button" class="button" id="btcpay-satoshi-refresh-events"><?php esc_html_e('Refresh', 'btcpay-satoshi-tickets'); ?></button>
                <button type="button" class="button button-primary" id="btcpay-satoshi-add-event"><?php esc_html_e('Add event', 'btcpay-satoshi-tickets'); ?></button>
                <div id="btcpay-satoshi-add-event-form" class="btcpay-satoshi-form" style="display:none; margin-top:1em; padding:1em; border:1px solid #ccc; max-width:500px;">
                    <input type="hidden" id="st-event-edit-id" value="" />
                    <h3 id="st-event-form-title"><?php esc_html_e('Create event', 'btcpay-satoshi-tickets'); ?></h3>
                    <p><label><?php esc_html_e('Title', 'btcpay-satoshi-tickets'); ?> *<br><input type="text" id="st-event-title" class="regular-text" required /></label></p>
                    <p><label><?php esc_html_e('Start date', 'btcpay-satoshi-tickets'); ?> *<br><input type="datetime-local" id="st-event-start" required /></label></p>
                    <p><label><?php esc_html_e('End date (optional)', 'btcpay-satoshi-tickets'); ?><br><input type="datetime-local" id="st-event-end" /></label></p>
                    <p><label><?php esc_html_e('Description', 'btcpay-satoshi-tickets'); ?><br><textarea id="st-event-desc" rows="3" class="large-text"></textarea></label></p>
                    <p><label><?php esc_html_e('Location', 'btcpay-satoshi-tickets'); ?><br><input type="text" id="st-event-location" class="regular-text" /></label></p>
                    <p><label><?php esc_html_e('Event type', 'btcpay-satoshi-tickets'); ?><br>
                        <select id="st-event-type"><option value="Physical"><?php esc_html_e('Physical', 'btcpay-satoshi-tickets'); ?></option><option value="Virtual"><?php esc_html_e('Virtual', 'btcpay-satoshi-tickets'); ?></option></select>
                    </label></p>
                    <p><label><?php esc_html_e('Currency (e.g. USD, EUR, BTC)', 'btcpay-satoshi-tickets'); ?><br><input type="text" id="st-event-currency" class="small-text" placeholder="USD" maxlength="10" /></label></p>
                    <p><label><?php esc_html_e('Redirect URL after payment (optional)', 'btcpay-satoshi-tickets'); ?><br><input type="url" id="st-event-redirect" class="regular-text" /></label></p>
                    <hr style="margin:12px 0;" />
                    <p><label><?php esc_html_e('Email subject', 'btcpay-satoshi-tickets'); ?><br><input type="text" id="st-event-email-subject" class="regular-text" /></label></p>
                    <p><label><?php esc_html_e('Email body', 'btcpay-satoshi-tickets'); ?><br>
                        <span class="description"><?php esc_html_e('Placeholders: {{Title}} {{Name}} {{Email}} {{Location}} {{Description}} {{EventDate}} {{Currency}}', 'btcpay-satoshi-tickets'); ?></span><br>
                        <textarea id="st-event-email-body" rows="5" class="large-text"></textarea>
                    </label></p>
                    <hr style="margin:12px 0;" />
                    <p><label><input type="checkbox" id="st-event-has-capacity" /> <?php esc_html_e('Limit total capacity', 'btcpay-satoshi-tickets'); ?></label></p>
                    <p id="st-event-max-capacity-row" style="display:none;"><label><?php esc_html_e('Maximum capacity', 'btcpay-satoshi-tickets'); ?><br><input type="number" id="st-event-max-capacity" min="1" class="small-text" /></label></p>
                    <hr style="margin:12px 0;" />
                    <p><label><?php esc_html_e('Event logo', 'btcpay-satoshi-tickets'); ?><br>
                        <div id="st-event-logo-current" style="margin-bottom:6px;"></div>
                        <input type="file" id="st-event-logo-file" accept="image/jpeg,image/png,image/gif,image/webp" />
                        <button type="button" class="button button-small" id="btcpay-satoshi-upload-logo" style="margin-left:6px;"><?php esc_html_e('Upload logo', 'btcpay-satoshi-tickets'); ?></button>
                        <button type="button" class="button button-small" id="btcpay-satoshi-remove-logo" style="margin-left:4px; display:none;"><?php esc_html_e('Remove logo', 'btcpay-satoshi-tickets'); ?></button>
                    </label></p>
                    <p><label><input type="checkbox" id="st-event-enable" /> <?php esc_html_e('Activate immediately (requires at least one ticket type)', 'btcpay-satoshi-tickets'); ?></label></p>
                    <p><button type="button" class="button button-primary" id="btcpay-satoshi-submit-event"><?php esc_html_e('Create event', 'btcpay-satoshi-tickets'); ?></button>
                    <button type="button" class="button" id="btcpay-satoshi-cancel-event"><?php esc_html_e('Cancel', 'btcpay-satoshi-tickets'); ?></button></p>
                </div>
                <p id="st-event-form-mode-hint" class="description" style="display:none;"><?php esc_html_e('Edit mode – change values and click Save.', 'btcpay-satoshi-tickets'); ?></p>
                <div id="btcpay-satoshi-events-list">
                    <?php if (!empty($eventsList)) : ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Event', 'btcpay-satoshi-tickets'); ?></th>
                                    <th><?php esc_html_e('Date', 'btcpay-satoshi-tickets'); ?></th>
                                    <th><?php esc_html_e('Status', 'btcpay-satoshi-tickets'); ?></th>
                                    <th><?php esc_html_e('Tickets Sold', 'btcpay-satoshi-tickets'); ?></th>
                                    <th><?php esc_html_e('Actions', 'btcpay-satoshi-tickets'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($eventsList as $event) :
                                    $eventId = $event['id'] ?? $event['Id'] ?? '';
                                    $eventTitle = $event['title'] ?? $event['Title'] ?? '';
                                    $eventStartDate = $event['startDate'] ?? $event['StartDate'] ?? '';
                                    $eventTicketsSold = $event['ticketsSold'] ?? $event['TicketsSold'] ?? 0;
                                    $eventState = $event['eventState'] ?? $event['EventState'] ?? '';
                                    $eventEnable = ($eventState === 'Active');
                                ?>
                                    <tr data-event-id="<?php echo esc_attr($eventId); ?>" data-event-enable="<?php echo $eventEnable ? '1' : '0'; ?>">
                                        <td><strong><?php echo esc_html($eventTitle); ?></strong></td>
                                        <td><?php echo esc_html($eventStartDate); ?></td>
                                        <td><span class="btcpay-satoshi-status-badge btcpay-satoshi-status-<?php echo $eventEnable ? 'active' : 'disabled'; ?>"><?php echo $eventEnable ? esc_html__('Active', 'btcpay-satoshi-tickets') : esc_html__('Disabled', 'btcpay-satoshi-tickets'); ?></span>
                                            <button type="button" class="button button-small btcpay-satoshi-toggle-event" data-event-id="<?php echo esc_attr($eventId); ?>" title="<?php echo $eventEnable ? esc_attr__('Disable', 'btcpay-satoshi-tickets') : esc_attr__('Enable', 'btcpay-satoshi-tickets'); ?>"><?php echo $eventEnable ? esc_html__('Disable', 'btcpay-satoshi-tickets') : esc_html__('Enable', 'btcpay-satoshi-tickets'); ?></button>
                                        </td>
                                        <td><?php echo esc_html((string) $eventTicketsSold); ?></td>
                                        <td>
                                            <button type="button" class="button button-small btcpay-satoshi-load-tickets"><?php esc_html_e('Ticket Types', 'btcpay-satoshi-tickets'); ?></button>
                                            <button type="button" class="button button-small btcpay-satoshi-view-tickets" data-event-id="<?php echo esc_attr($eventId); ?>"><?php esc_html_e('Tickets', 'btcpay-satoshi-tickets'); ?></button>
                                            <button type="button" class="button button-small btcpay-satoshi-view-orders" data-event-id="<?php echo esc_attr($eventId); ?>"><?php esc_html_e('Orders', 'btcpay-satoshi-tickets'); ?></button>
                                            <button type="button" class="button button-small btcpay-satoshi-edit-event" data-event-id="<?php echo esc_attr($eventId); ?>"><?php esc_html_e('Edit', 'btcpay-satoshi-tickets'); ?></button>
                                            <button type="button" class="button button-small btcpay-satoshi-delete-event" data-event-id="<?php echo esc_attr($eventId); ?>" style="color:#a00;"><?php esc_html_e('Delete', 'btcpay-satoshi-tickets'); ?></button>
                                            <?php
                                            if (AdminWCSettingsTab::isConnectedViaSatflux()) {
                                                $checkinUrl = AdminWCSettingsTab::getSatfluxCheckinUrl($eventId);
                                                if ($checkinUrl !== '') {
                                                    echo ' <a href="' . esc_url($checkinUrl) . '" class="button button-small" target="_blank" rel="noopener noreferrer">' . esc_html__('Check-in', 'btcpay-satoshi-tickets') . '</a>';
                                                }
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php elseif ($eventsError) : ?>
                        <?php
                        $isPermError = (
                            stripos($eventsError, 'insufficient') !== false ||
                            stripos($eventsError, 'canmodifystoresettings') !== false ||
                            stripos($eventsError, 'permission') !== false
                        );
                        $btcpaySettingsUrl = SatoshiApiClient::getConfig() ? admin_url('admin.php?page=wc-settings&tab=btcpay_settings') : AdminWCSettingsTab::getTabUrl(AdminWCSettingsTab::SECTION_CONNECTION);
                        ?>
                        <div class="notice notice-error">
                            <p><?php echo esc_html($eventsError); ?></p>
                            <?php if ($isPermError) : ?>
                                <p><strong><?php esc_html_e('Solution:', 'btcpay-satoshi-tickets'); ?></strong>
                                    <?php esc_html_e('Create a new API key in BTCPay Server with permission "Can modify store settings" (btcpay.store.canmodifystoresettings).', 'btcpay-satoshi-tickets'); ?>
                                    <a href="<?php echo esc_url($btcpaySettingsUrl); ?>"><?php esc_html_e('Open BTCPay settings', 'btcpay-satoshi-tickets'); ?></a>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php else : ?>
                        <p id="btcpay-satoshi-no-events"><?php esc_html_e('No active events found. Create events in your BTCPay Server SatoshiTickets plugin.', 'btcpay-satoshi-tickets'); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div id="btcpay-satoshi-ticket-types-section" style="display:none;">
                <h2><?php esc_html_e('Ticket Types', 'btcpay-satoshi-tickets'); ?>: <span id="btcpay-satoshi-event-title"></span></h2>
                <button type="button" class="button" id="btcpay-satoshi-add-ticket-type"><?php esc_html_e('Add ticket type', 'btcpay-satoshi-tickets'); ?></button>
                <div id="btcpay-satoshi-add-tt-form" class="btcpay-satoshi-form" style="display:none; margin-top:1em; padding:1em; border:1px solid #ccc; max-width:500px;">
                    <input type="hidden" id="st-tt-edit-id" value="" />
                    <h3 id="st-tt-form-title"><?php esc_html_e('Create ticket type', 'btcpay-satoshi-tickets'); ?></h3>
                    <p><label><?php esc_html_e('Name', 'btcpay-satoshi-tickets'); ?> *<br><input type="text" id="st-tt-name" class="regular-text" required /></label></p>
                    <p><label><?php esc_html_e('Price', 'btcpay-satoshi-tickets'); ?> *<br><input type="number" id="st-tt-price" step="0.01" min="0" required /></label></p>
                    <p><label><?php esc_html_e('Description', 'btcpay-satoshi-tickets'); ?><br><textarea id="st-tt-desc" rows="2" class="large-text"></textarea></label></p>
                    <p><label><?php esc_html_e('Quantity (leave empty for unlimited)', 'btcpay-satoshi-tickets'); ?><br><input type="number" id="st-tt-qty" min="1" placeholder="999999" /></label></p>
                    <p><label><input type="checkbox" id="st-tt-is-default" /> <?php esc_html_e('Set as default ticket type', 'btcpay-satoshi-tickets'); ?></label></p>
                    <p><button type="button" class="button button-primary" id="btcpay-satoshi-submit-tt"><?php esc_html_e('Create ticket type', 'btcpay-satoshi-tickets'); ?></button>
                    <button type="button" class="button" id="btcpay-satoshi-cancel-tt"><?php esc_html_e('Cancel', 'btcpay-satoshi-tickets'); ?></button></p>
                </div>
                <p id="st-tt-form-mode-hint" class="description" style="display:none;"><?php esc_html_e('Edit mode – change values and click Save.', 'btcpay-satoshi-tickets'); ?></p>
                <div id="btcpay-satoshi-ticket-types-list"></div>
            </div>

            <div id="btcpay-satoshi-tickets-section" style="display:none; margin-top:24px;">
                <h2><?php esc_html_e('Tickets', 'btcpay-satoshi-tickets'); ?>: <span id="btcpay-satoshi-tickets-event-title"></span></h2>
                <div class="btcpay-satoshi-tickets-toolbar">
                    <input type="text" id="st-tickets-search" class="regular-text" placeholder="<?php esc_attr_e('Search by name, email, ticket #…', 'btcpay-satoshi-tickets'); ?>" style="max-width:280px;" />
                    <button type="button" class="button" id="btcpay-satoshi-search-tickets"><?php esc_html_e('Search', 'btcpay-satoshi-tickets'); ?></button>
                    <a id="btcpay-satoshi-export-tickets-link" href="#" class="button" target="_blank"><?php esc_html_e('Export CSV', 'btcpay-satoshi-tickets'); ?></a>
                </div>
                <div id="btcpay-satoshi-tickets-stats" style="margin:8px 0; font-weight:500;"></div>
                <div id="btcpay-satoshi-tickets-list"></div>
                <div class="btcpay-satoshi-checkin-box" style="margin-top:20px; padding:16px; border:1px solid #c3c4c7; background:#f6f7f7; max-width:520px;">
                    <h3 style="margin-top:0;"><?php esc_html_e('Manual Check-in', 'btcpay-satoshi-tickets'); ?></h3>
                    <p>
                        <input type="text" id="st-checkin-input" class="regular-text" placeholder="<?php esc_attr_e('Ticket number or transaction ID', 'btcpay-satoshi-tickets'); ?>" style="max-width:280px;" />
                        <button type="button" class="button button-primary" id="btcpay-satoshi-checkin-btn"><?php esc_html_e('Check in', 'btcpay-satoshi-tickets'); ?></button>
                    </p>
                    <div id="btcpay-satoshi-checkin-result"></div>
                </div>
            </div>

            <div id="btcpay-satoshi-orders-section" style="display:none; margin-top:24px;">
                <h2><?php esc_html_e('Orders', 'btcpay-satoshi-tickets'); ?>: <span id="btcpay-satoshi-orders-event-title"></span></h2>
                <div style="margin-bottom:12px;">
                    <input type="text" id="st-orders-search" class="regular-text" placeholder="<?php esc_attr_e('Search…', 'btcpay-satoshi-tickets'); ?>" style="max-width:280px;" />
                    <button type="button" class="button" id="btcpay-satoshi-search-orders"><?php esc_html_e('Search', 'btcpay-satoshi-tickets'); ?></button>
                </div>
                <div id="btcpay-satoshi-orders-list"></div>
            </div>

            <div id="btcpay-satoshi-fulfillment-section" class="btcpay-satoshi-fulfillment">
                <h2><?php esc_html_e('Ticket fulfillment', 'btcpay-satoshi-tickets'); ?></h2>
                <p class="description"><?php esc_html_e('Orders with tickets paid by bank transfer or other non-Bitcoin methods. Create tickets on BTCPay after payment is confirmed.', 'btcpay-satoshi-tickets'); ?></p>
                <div id="btcpay-satoshi-fulfillment-list">
                    <?php
                    $fulfillOrders = self::getOrdersNeedingFulfillment();
                    if (empty($fulfillOrders)) :
                        echo '<p>' . esc_html__('No orders need fulfillment.', 'btcpay-satoshi-tickets') . '</p>';
                    else :
                        echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>' . esc_html__('Order', 'btcpay-satoshi-tickets') . '</th><th>' . esc_html__('Payment', 'btcpay-satoshi-tickets') . '</th><th>' . esc_html__('Action', 'btcpay-satoshi-tickets') . '</th></tr></thead><tbody>';
                        foreach ($fulfillOrders as $row) :
                            $order = $row['order'];
                            ?>
                            <tr data-order-id="<?php echo esc_attr((string) $order->get_id()); ?>">
                                <td><a href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html((string) $order->get_id()); ?></a> - <?php echo esc_html($order->get_billing_email()); ?></td>
                                <td><?php echo esc_html($order->get_payment_method_title()); ?></td>
                                <td><button type="button" class="button button-small btcpay-satoshi-fulfill-btn" data-order-id="<?php echo esc_attr((string) $order->get_id()); ?>"><?php esc_html_e('Create tickets', 'btcpay-satoshi-tickets'); ?></button></td>
                            </tr>
                        <?php endforeach;
                        echo '</tbody></table>';
                    endif;
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    public static function ajaxGetEvents(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed. Please refresh the page (Ctrl+F5) and try again.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => 'BTCPay not configured']);
        }

        $result = $client->getEvents();
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message'] ?? 'API error']);
        }

        delete_transient('btcpay_satoshi_events_options');
        wp_send_json_success($result['data'] ?? []);
    }

    public static function ajaxGetTicketTypes(): void
    {
        try {
            if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
                wp_send_json_error(['message' => 'Security check failed. Please refresh the page (Ctrl+F5) and try again.']);
            }

            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error(['message' => 'Unauthorized']);
            }

            $eventId = isset($_POST['eventId']) ? sanitize_text_field(wp_unslash($_POST['eventId'])) : '';
            $eventId = trim($eventId);
            if ($eventId === '') {
                wp_send_json_error(['message' => 'Event ID required']);
            }

            $client = new SatoshiApiClient();
            if (!$client->isConfigured()) {
                wp_send_json_error(['message' => 'BTCPay not configured']);
            }

            $result = $client->getTicketTypes($eventId);
            if (!$result['success']) {
                $msg = $result['message'] ?? 'API error';
                if (isset($result['code']) && $result['code'] > 0) {
                    $msg .= ' (HTTP ' . $result['code'] . ')';
                }
                wp_send_json_error(['message' => $msg]);
            }

            $types = $result['data'] ?? [];
            foreach ($types as &$tt) {
                $ttId = $tt['id'] ?? $tt['Id'] ?? '';
                $count = self::getProductCountForTicketType($eventId, (string) $ttId);
                $tt['productCount'] = $count;
                $tt['hasProduct'] = $count > 0;
            }
            unset($tt);

            wp_send_json_success($types);
        } catch (\Throwable $e) {
            error_log('BTCPay Satoshi Tickets ajaxGetTicketTypes: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $msg = 'Server error: ' . $e->getMessage();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $msg .= ' (' . $e->getFile() . ':' . $e->getLine() . ')';
            }
            wp_send_json_error(['message' => $msg]);
        }
    }

    public static function ajaxGetEvent(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $eventId = isset($_POST['eventId']) ? sanitize_text_field(wp_unslash($_POST['eventId'])) : '';
        if ($eventId === '') {
            wp_send_json_error(['message' => 'Event ID required']);
        }
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => 'BTCPay not configured']);
        }
        $result = $client->getEvent($eventId);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message'] ?? 'API error']);
        }
        wp_send_json_success($result['data'] ?? []);
    }

    public static function ajaxUpdateEvent(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $eventId = isset($_POST['eventId']) ? sanitize_text_field(wp_unslash($_POST['eventId'])) : '';
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $startDate = isset($_POST['startDate']) ? sanitize_text_field(wp_unslash($_POST['startDate'])) : '';
        if ($eventId === '' || $title === '' || $startDate === '') {
            wp_send_json_error(['message' => __('Event ID, title and start date are required.', 'btcpay-satoshi-tickets')]);
        }
        $endDate = isset($_POST['endDate']) ? sanitize_text_field(wp_unslash($_POST['endDate'])) : '';
        $hasMaxCapacity = !empty($_POST['hasMaximumCapacity']);
        $maxCapacity = isset($_POST['maximumEventCapacity']) && $_POST['maximumEventCapacity'] !== '' ? (int) $_POST['maximumEventCapacity'] : null;
        $data = [
            'title'                 => $title,
            'startDate'             => $startDate,
            'description'           => isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '',
            'location'              => isset($_POST['location']) ? sanitize_text_field(wp_unslash($_POST['location'])) : '',
            'eventType'             => isset($_POST['eventType']) ? sanitize_text_field(wp_unslash($_POST['eventType'])) : 'Physical',
            'currency'              => isset($_POST['currency']) ? sanitize_text_field(wp_unslash($_POST['currency'])) : '',
            'redirectUrl'           => isset($_POST['redirectUrl']) ? esc_url_raw(wp_unslash($_POST['redirectUrl'])) : '',
            'emailSubject'          => isset($_POST['emailSubject']) ? sanitize_text_field(wp_unslash($_POST['emailSubject'])) : '',
            'emailBody'             => isset($_POST['emailBody']) ? sanitize_textarea_field(wp_unslash($_POST['emailBody'])) : '',
            'hasMaximumCapacity'    => $hasMaxCapacity,
            'maximumEventCapacity'  => $maxCapacity,
            'enable'                => !empty($_POST['enable']),
        ];
        if ($endDate !== '') {
            $data['endDate'] = $endDate;
        }
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => 'BTCPay not configured']);
        }
        $result = $client->updateEvent($eventId, $data);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message'] ?? 'API error']);
        }
        wp_send_json_success($result['data'] ?? []);
    }

    public static function ajaxUpdateTicketType(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $eventId = isset($_POST['eventId']) ? sanitize_text_field(wp_unslash($_POST['eventId'])) : '';
        $ticketTypeId = isset($_POST['ticketTypeId']) ? sanitize_text_field(wp_unslash($_POST['ticketTypeId'])) : '';
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
        if ($eventId === '' || $ticketTypeId === '' || $name === '') {
            wp_send_json_error(['message' => __('Event ID, ticket type ID and name are required.', 'btcpay-satoshi-tickets')]);
        }
        $data = [
            'name'        => $name,
            'price'       => $price,
            'description' => isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '',
            'quantity'    => isset($_POST['quantity']) && $_POST['quantity'] !== '' ? (int) $_POST['quantity'] : 999999,
            'isDefault'   => !empty($_POST['isDefault']),
        ];
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => 'BTCPay not configured']);
        }
        $result = $client->updateTicketType($eventId, $ticketTypeId, $data);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message'] ?? 'API error']);
        }
        self::syncTicketTypeToProducts($eventId, $ticketTypeId, $name, $price, $data['description']);
        wp_send_json_success($result['data'] ?? []);
    }

    private static function getProductCountForTicketType(string $eventId, string $ticketTypeId): int
    {
        $ids = get_posts([
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => 'any',
            'meta_query' => [
                'relation' => 'AND',
                ['key' => ProductTypeTicket::META_EVENT_ID, 'value' => $eventId, 'compare' => '='],
                ['key' => ProductTypeTicket::META_TICKET_TYPE_ID, 'value' => $ticketTypeId, 'compare' => '='],
            ],
        ]);
        return is_array($ids) ? count($ids) : 0;
    }

    /**
     * Sync ticket type data (name, price, description) from BTCPay to all linked WooCommerce products.
     * BTCPay is the source of truth – all products linked to the same ticket type get these values.
     */
    private static function syncTicketTypeToProducts(string $eventId, string $ticketTypeId, string $name, float $price, string $description): void
    {
        $productIds = get_posts([
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => 'any',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => ProductTypeTicket::META_EVENT_ID,
                    'value' => $eventId,
                    'compare' => '=',
                ],
                [
                    'key' => ProductTypeTicket::META_TICKET_TYPE_ID,
                    'value' => $ticketTypeId,
                    'compare' => '=',
                ],
            ],
        ]);
        foreach ($productIds as $productId) {
            $product = wc_get_product($productId);
            if (!$product) {
                continue;
            }
            $prodEventId = (string) $product->get_meta(ProductTypeTicket::META_EVENT_ID, true);
            $prodTicketTypeId = (string) $product->get_meta(ProductTypeTicket::META_TICKET_TYPE_ID, true);
            if ($prodEventId !== $eventId || $prodTicketTypeId !== $ticketTypeId) {
                continue;
            }
            $product->set_name($name);
            $product->set_regular_price((string) $price);
            $product->set_description($description);
            $product->save();
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($productId);
            }
        }
    }

    public static function ajaxSyncTicketTypeFromBtcpay(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $eventId = isset($_POST['eventId']) ? sanitize_text_field(wp_unslash($_POST['eventId'])) : '';
        $ticketTypeId = isset($_POST['ticketTypeId']) ? sanitize_text_field(wp_unslash($_POST['ticketTypeId'])) : '';
        if ($eventId === '' || $ticketTypeId === '') {
            wp_send_json_error(['message' => __('Event ID and ticket type ID are required.', 'btcpay-satoshi-tickets')]);
        }
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => 'BTCPay not configured']);
        }
        $result = $client->getTicketTypes($eventId);
        if (!$result['success'] || !is_array($result['data'] ?? null)) {
            wp_send_json_error(['message' => $result['message'] ?? __('Failed to fetch ticket types from BTCPay.', 'btcpay-satoshi-tickets')]);
        }
        $ticketType = null;
        foreach ($result['data'] as $tt) {
            $id = $tt['id'] ?? $tt['Id'] ?? '';
            if ((string) $id === (string) $ticketTypeId) {
                $ticketType = $tt;
                break;
            }
        }
        if (!$ticketType) {
            wp_send_json_error(['message' => __('Ticket type not found on BTCPay.', 'btcpay-satoshi-tickets')]);
        }
        $name = $ticketType['name'] ?? $ticketType['Name'] ?? '';
        $price = (float) ($ticketType['price'] ?? $ticketType['Price'] ?? 0);
        $description = $ticketType['description'] ?? $ticketType['Description'] ?? '';
        self::syncTicketTypeToProducts($eventId, $ticketTypeId, $name, $price, $description);
        wp_send_json_success(['message' => __('Products synced from BTCPay.', 'btcpay-satoshi-tickets')]);
    }

    public static function ajaxCreateProduct(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed. Please refresh the page (Ctrl+F5) and try again.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $eventId = isset($_POST['eventId']) ? sanitize_text_field(wp_unslash($_POST['eventId'])) : '';
        $ticketTypeId = isset($_POST['ticketTypeId']) ? sanitize_text_field(wp_unslash($_POST['ticketTypeId'])) : '';
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';

        if ($eventId === '' || $ticketTypeId === '' || $name === '') {
            wp_send_json_error(['message' => 'Missing required fields']);
        }

        try {
            $product = new \BTCPaySatoshiTickets\WC_Product_Satoshi_Ticket();
            $product->set_name($name);
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_description($description);
            $product->set_regular_price((string) $price);
            $product->set_virtual(true);
            $product->set_sold_individually(false);
            $product->save();

            $productId = $product->get_id();
            if (!$productId) {
                wp_send_json_error(['message' => 'Failed to create product']);
            }

            $product->update_meta_data(ProductTypeTicket::META_EVENT_ID, $eventId);
            $product->update_meta_data(ProductTypeTicket::META_TICKET_TYPE_ID, $ticketTypeId);

            $client = new SatoshiApiClient();
            if ($client->isConfigured()) {
                $qty = $client->getTicketTypeQuantityAvailable($eventId, $ticketTypeId);
                if ($qty !== null) {
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity($qty);
                    $product->set_stock_status('instock');
                }
            }

            $product->save();
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($productId);
            }

            $editUrl = admin_url('post.php?post=' . $productId . '&action=edit');

            wp_send_json_success([
                'productId' => $productId,
                'editUrl' => $editUrl,
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    public static function ajaxSyncStock(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $eventId = isset($_POST['eventId']) ? sanitize_text_field(wp_unslash($_POST['eventId'])) : '';
        $ticketTypeId = isset($_POST['ticketTypeId']) ? sanitize_text_field(wp_unslash($_POST['ticketTypeId'])) : '';
        if ($eventId === '' || $ticketTypeId === '') {
            wp_send_json_error(['message' => 'Missing eventId or ticketTypeId']);
        }

        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => 'BTCPay not configured']);
        }

        $qty = $client->getTicketTypeQuantityAvailable($eventId, $ticketTypeId);
        if ($qty === null) {
            wp_send_json_error(['message' => __('Failed to fetch available quantity from SatoshiTickets.', 'btcpay-satoshi-tickets')]);
        }

        $productIds = wc_get_products([
            'limit' => -1,
            'return' => 'ids',
            'meta_query' => [
                [
                    'key' => ProductTypeTicket::META_EVENT_ID,
                    'value' => $eventId,
                ],
                [
                    'key' => ProductTypeTicket::META_TICKET_TYPE_ID,
                    'value' => $ticketTypeId,
                ],
            ],
        ]);

        $count = 0;
        foreach ($productIds as $productId) {
            $product = wc_get_product($productId);
            if ($product) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity($qty);
                $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
                $product->save();
                if (function_exists('wc_delete_product_transients')) {
                    wc_delete_product_transients($productId);
                }
                $count++;
            }
        }

        wp_send_json_success([
            'updated' => $count,
            'quantity' => $qty,
        ]);
    }

    public static function ajaxCreateEvent(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $startDate = isset($_POST['startDate']) ? sanitize_text_field(wp_unslash($_POST['startDate'])) : '';
        if ($title === '' || $startDate === '') {
            wp_send_json_error(['message' => __('Title and start date are required.', 'btcpay-satoshi-tickets')]);
        }
        $endDate = isset($_POST['endDate']) ? sanitize_text_field(wp_unslash($_POST['endDate'])) : '';
        $hasMaxCapacity = !empty($_POST['hasMaximumCapacity']);
        $maxCapacity = isset($_POST['maximumEventCapacity']) && $_POST['maximumEventCapacity'] !== '' ? (int) $_POST['maximumEventCapacity'] : null;
        $data = [
            'title'                 => $title,
            'startDate'             => $startDate,
            'description'           => isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '',
            'location'              => isset($_POST['location']) ? sanitize_text_field(wp_unslash($_POST['location'])) : '',
            'eventType'             => isset($_POST['eventType']) ? sanitize_text_field(wp_unslash($_POST['eventType'])) : 'Physical',
            'currency'              => isset($_POST['currency']) ? sanitize_text_field(wp_unslash($_POST['currency'])) : '',
            'redirectUrl'           => isset($_POST['redirectUrl']) ? esc_url_raw(wp_unslash($_POST['redirectUrl'])) : '',
            'emailSubject'          => isset($_POST['emailSubject']) ? sanitize_text_field(wp_unslash($_POST['emailSubject'])) : '',
            'emailBody'             => isset($_POST['emailBody']) ? sanitize_textarea_field(wp_unslash($_POST['emailBody'])) : '',
            'hasMaximumCapacity'    => $hasMaxCapacity,
            'maximumEventCapacity'  => $maxCapacity,
            'enable'                => !empty($_POST['enable']),
        ];
        if ($endDate !== '') {
            $data['endDate'] = $endDate;
        }
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => 'BTCPay not configured']);
        }
        $result = $client->createEvent($data);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message'] ?? 'API error']);
        }
        wp_send_json_success($result['data'] ?? []);
    }

    public static function ajaxCreateTicketType(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $eventId = isset($_POST['eventId']) ? sanitize_text_field(wp_unslash($_POST['eventId'])) : '';
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
        if ($eventId === '' || $name === '') {
            wp_send_json_error(['message' => __('Event ID and name are required.', 'btcpay-satoshi-tickets')]);
        }
        $data = [
            'name'        => $name,
            'price'       => $price,
            'description' => isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '',
            'quantity'    => isset($_POST['quantity']) && $_POST['quantity'] !== '' ? (int) $_POST['quantity'] : 999999,
            'isDefault'   => !empty($_POST['isDefault']),
        ];
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => 'BTCPay not configured']);
        }
        $result = $client->createTicketType($eventId, $data);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message'] ?? 'API error']);
        }
        wp_send_json_success($result['data'] ?? []);
    }

    public static function ajaxFulfillTickets(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $orderId = isset($_POST['orderId']) ? (int) $_POST['orderId'] : 0;
        if ($orderId < 1) {
            wp_send_json_error(['message' => 'Invalid order ID']);
        }
        $order = wc_get_order($orderId);
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
        }
        if ($order->get_payment_method() === GatewaySatoshiTickets::GATEWAY_ID) {
            wp_send_json_error(['message' => __('This order was paid via Bitcoin; tickets are created automatically.', 'btcpay-satoshi-tickets')]);
        }
        $eventId = CheckoutHandler::getEventIdFromOrder($order);
        if (!$eventId) {
            wp_send_json_error(['message' => __('Order has no ticket data.', 'btcpay-satoshi-tickets')]);
        }
        $tickets = CheckoutHandler::buildPurchaseTicketsFromOrder($order);
        if (empty($tickets)) {
            wp_send_json_error(['message' => __('No recipient data for tickets.', 'btcpay-satoshi-tickets')]);
        }
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => 'BTCPay not configured']);
        }
        $result = $client->createTicketsOffline($eventId, $tickets, (string) $orderId);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message'] ?? 'API error']);
        }
        $order->update_meta_data('_satoshi_tickets_fulfilled', 'yes');
        $order->save();
        wp_send_json_success(['message' => __('Tickets created.', 'btcpay-satoshi-tickets')]);
    }

    public static function ajaxToggleEvent(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $eventId = isset($_POST['eventId']) ? sanitize_text_field(wp_unslash($_POST['eventId'])) : '';
        if ($eventId === '') {
            wp_send_json_error(['message' => __('Event ID required.', 'btcpay-satoshi-tickets')]);
        }
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => 'BTCPay not configured']);
        }
        $result = $client->toggleEvent($eventId);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message'] ?? 'API error']);
        }
        $data = $result['data'] ?? [];
        wp_send_json_success(['enable' => $data['enable'] ?? null]);
    }

    public static function ajaxDeleteEvent(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $eventId = isset($_POST['eventId']) ? sanitize_text_field(wp_unslash($_POST['eventId'])) : '';
        if ($eventId === '') {
            wp_send_json_error(['message' => __('Event ID required.', 'btcpay-satoshi-tickets')]);
        }
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => 'BTCPay not configured']);
        }
        $result = $client->deleteEvent($eventId);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message'] ?? 'API error']);
        }
        wp_send_json_success(['message' => __('Event deleted.', 'btcpay-satoshi-tickets')]);
    }

    public static function ajaxDeleteTicketType(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $eventId = isset($_POST['eventId']) ? sanitize_text_field(wp_unslash($_POST['eventId'])) : '';
        $ticketTypeId = isset($_POST['ticketTypeId']) ? sanitize_text_field(wp_unslash($_POST['ticketTypeId'])) : '';
        if ($eventId === '' || $ticketTypeId === '') {
            wp_send_json_error(['message' => __('Event ID and ticket type ID required.', 'btcpay-satoshi-tickets')]);
        }
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => 'BTCPay not configured']);
        }
        $result = $client->deleteTicketType($eventId, $ticketTypeId);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message'] ?? 'API error']);
        }
        wp_send_json_success(['message' => __('Ticket type deleted.', 'btcpay-satoshi-tickets')]);
    }

    public static function ajaxToggleTicketType(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $eventId = isset($_POST['eventId']) ? sanitize_text_field(wp_unslash($_POST['eventId'])) : '';
        $ticketTypeId = isset($_POST['ticketTypeId']) ? sanitize_text_field(wp_unslash($_POST['ticketTypeId'])) : '';
        if ($eventId === '' || $ticketTypeId === '') {
            wp_send_json_error(['message' => __('Event ID and ticket type ID required.', 'btcpay-satoshi-tickets')]);
        }
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => 'BTCPay not configured']);
        }
        $result = $client->toggleTicketType($eventId, $ticketTypeId);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message'] ?? 'API error']);
        }
        wp_send_json_success($result['data'] ?? []);
    }

    public static function ajaxGetTickets(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $eventId = isset($_POST['eventId']) ? sanitize_text_field(wp_unslash($_POST['eventId'])) : '';
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        if ($eventId === '') {
            wp_send_json_error(['message' => __('Event ID required.', 'btcpay-satoshi-tickets')]);
        }
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => 'BTCPay not configured']);
        }
        $result = $client->getTickets($eventId, $search);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message'] ?? 'API error']);
        }
        wp_send_json_success($result['data'] ?? []);
    }

    public static function ajaxCheckInTicket(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $eventId = isset($_POST['eventId']) ? sanitize_text_field(wp_unslash($_POST['eventId'])) : '';
        $ticketNumber = isset($_POST['ticketNumber']) ? sanitize_text_field(wp_unslash($_POST['ticketNumber'])) : '';
        if ($eventId === '' || $ticketNumber === '') {
            wp_send_json_error(['message' => __('Event ID and ticket number required.', 'btcpay-satoshi-tickets')]);
        }
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => 'BTCPay not configured']);
        }
        $result = $client->checkInTicket($eventId, $ticketNumber);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message'] ?? 'API error']);
        }
        wp_send_json_success($result['data'] ?? []);
    }

    public static function ajaxGetOrders(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $eventId = isset($_POST['eventId']) ? sanitize_text_field(wp_unslash($_POST['eventId'])) : '';
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        if ($eventId === '') {
            wp_send_json_error(['message' => __('Event ID required.', 'btcpay-satoshi-tickets')]);
        }
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => 'BTCPay not configured']);
        }
        $result = $client->getOrders($eventId, $search);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message'] ?? 'API error']);
        }
        wp_send_json_success($result['data'] ?? []);
    }

    public static function ajaxSendReminder(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $eventId = isset($_POST['eventId']) ? sanitize_text_field(wp_unslash($_POST['eventId'])) : '';
        $orderId = isset($_POST['orderId']) ? sanitize_text_field(wp_unslash($_POST['orderId'])) : '';
        $ticketId = isset($_POST['ticketId']) ? sanitize_text_field(wp_unslash($_POST['ticketId'])) : '';
        if ($eventId === '' || $orderId === '' || $ticketId === '') {
            wp_send_json_error(['message' => __('Event ID, order ID and ticket ID required.', 'btcpay-satoshi-tickets')]);
        }
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => 'BTCPay not configured']);
        }
        $result = $client->sendReminderEmail($eventId, $orderId, $ticketId);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message'] ?? 'API error']);
        }
        wp_send_json_success(['message' => __('Reminder email sent.', 'btcpay-satoshi-tickets')]);
    }

    public static function handleExportTickets(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }
        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'btcpay_satoshi_export_tickets')) {
            wp_die('Security check failed.');
        }
        $eventId = isset($_GET['eventId']) ? sanitize_text_field(wp_unslash($_GET['eventId'])) : '';
        if ($eventId === '') {
            wp_die('Event ID required.');
        }
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_die('BTCPay not configured.');
        }
        $result = $client->exportTicketsCsv($eventId);
        if (!$result['success']) {
            wp_die(esc_html($result['message'] ?? 'Export failed.'));
        }
        $filename = 'tickets-' . sanitize_file_name($eventId) . '-' . gmdate('Ymd') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        echo $result['csv']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public static function ajaxUploadEventLogo(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $eventId = isset($_POST['eventId']) ? sanitize_text_field(wp_unslash($_POST['eventId'])) : '';
        if ($eventId === '' || !isset($_FILES['logo'])) {
            wp_send_json_error(['message' => __('Event ID and file required.', 'btcpay-satoshi-tickets')]);
        }
        $file = $_FILES['logo']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $mimeType = wp_check_filetype($file['name'])['type'] ?? ($file['type'] ?? '');
        if (!in_array($mimeType, $allowedMimes, true)) {
            wp_send_json_error(['message' => __('Only JPEG, PNG, GIF and WebP images are allowed.', 'btcpay-satoshi-tickets')]);
        }
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => 'BTCPay not configured']);
        }
        $result = $client->uploadEventLogo($eventId, $file['tmp_name'], $file['name'], $mimeType);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message'] ?? 'Upload failed.']);
        }
        wp_send_json_success($result['data'] ?? []);
    }

    public static function ajaxDeleteEventLogo(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $eventId = isset($_POST['eventId']) ? sanitize_text_field(wp_unslash($_POST['eventId'])) : '';
        if ($eventId === '') {
            wp_send_json_error(['message' => __('Event ID required.', 'btcpay-satoshi-tickets')]);
        }
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => 'BTCPay not configured']);
        }
        $result = $client->deleteEventLogo($eventId);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message'] ?? 'API error']);
        }
        wp_send_json_success(['message' => __('Logo removed.', 'btcpay-satoshi-tickets')]);
    }

    /**
     * Get orders needing ticket fulfillment (paid by non-Bitcoin, not yet fulfilled).
     *
     * @return array<int, array{order: \WC_Order, event_id: string}>
     */
    public static function getOrdersNeedingFulfillment(): array
    {
        $orders = wc_get_orders([
            'status' => ['processing', 'completed'],
            'limit' => 50,
            'return' => 'ids',
        ]);
        $out = [];
        foreach ($orders as $orderId) {
            $order = wc_get_order($orderId);
            if (!$order || $order->get_payment_method() === GatewaySatoshiTickets::GATEWAY_ID) {
                continue;
            }
            if ($order->get_meta('_satoshi_tickets_fulfilled') === 'yes') {
                continue;
            }
            $eventId = CheckoutHandler::getEventIdFromOrder($order);
            if ($eventId && !empty(CheckoutHandler::buildPurchaseTicketsFromOrder($order))) {
                $out[] = ['order' => $order, 'event_id' => $eventId];
            }
        }
        return $out;
    }
}
