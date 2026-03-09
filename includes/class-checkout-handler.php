<?php
/**
 * Checkout: Per-ticket recipient fields for Satoshi Ticket products.
 *
 * @package BTCPaySatoshiTickets
 */

declare(strict_types=1);

namespace BTCPaySatoshiTickets;

if (!defined('ABSPATH')) {
    exit;
}

final class CheckoutHandler
{
    private const FIELD_PREFIX = 'satoshi_recipient_';

    public static function init(): void
    {
        add_action('rest_api_init', [__CLASS__, 'registerCartTicketsEndpoint']);
        add_filter('rest_request_before_callbacks', [__CLASS__, 'sanitizePaymentDataForBlocks'], 10, 3);
        add_action('woocommerce_checkout_after_order_review', [__CLASS__, 'renderRecipientFields']);
        add_action('woocommerce_before_order_notes', [__CLASS__, 'renderRecipientFields']);
        add_action('woocommerce_review_order_before_payment', [__CLASS__, 'renderRecipientFields']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueueCheckoutAssets']);
        add_action('woocommerce_checkout_process', [__CLASS__, 'validateRecipientFields']);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'saveRecipientMeta'], 10, 4);
        add_action('woocommerce_single_product_summary', [__CLASS__, 'maybeShowTicketConfigNotice'], 25);
        add_action('woocommerce_rest_checkout_process_payment_with_context', [__CLASS__, 'saveBlocksRecipientsToOrder'], 5, 2);
    }

    /**
     * REST endpoint for Block checkout: return cart ticket items.
     */
    public static function registerCartTicketsEndpoint(): void
    {
        register_rest_route('btcpay-satoshi-tickets/v1', '/cart-tickets', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'restCartTickets'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * @return \WP_REST_Response
     */
    public static function restCartTickets(\WP_REST_Request $request): \WP_REST_Response
    {
        $items = self::getCartTicketItems();
        $payload = [];
        if ($items) {
            foreach ($items as $key => $data) {
                $payload[] = [
                    'key' => $key,
                    'name' => $data['name'],
                    'quantity' => (int) $data['quantity'],
                ];
            }
        }
        return new \WP_REST_Response(['items' => $payload], 200);
    }

    /**
     * Sanitize payment_data before Store API validation.
     * Store API expects each payment_data item value to be string or boolean.
     * Converts arrays/objects to JSON strings.
     */
    public static function sanitizePaymentDataForBlocks($response, $handler, $request): mixed
    {
        if (!$request instanceof \WP_REST_Request) {
            return $response;
        }
        $route = $request->get_route();
        if (strpos($route, '/wc/store/') === false || strpos($route, 'checkout') === false) {
            return $response;
        }
        $params = $request->get_json_params();
        if (!is_array($params) || !isset($params['payment_data'])) {
            return $response;
        }
        $paymentData = $params['payment_data'];
        if (!is_array($paymentData)) {
            return $response;
        }
        $items = [];
        foreach ($paymentData as $idx => $item) {
            if (is_array($item) && array_key_exists('key', $item)) {
                $key = $item['key'];
                $value = $item['value'] ?? null;
            } else {
                $key = (string) $idx;
                $value = $item;
            }
            if (is_string($value) || is_bool($value)) {
                $items[] = ['key' => $key, 'value' => $value];
            } elseif (is_array($value) || is_object($value)) {
                $items[] = ['key' => $key, 'value' => wp_json_encode($value)];
            } elseif (is_numeric($value) || $value === null) {
                $items[] = ['key' => $key, 'value' => $value === null ? '' : (string) $value];
            } else {
                $items[] = ['key' => $key, 'value' => (string) $value];
            }
        }
        $params['payment_data'] = $items;
        $request->set_body(wp_json_encode($params));
        return $response;
    }

    /**
     * Save recipient data from Blocks checkout payment_data to order line items.
     */
    public static function saveBlocksRecipientsToOrder($context, &$result): void
    {
        $paymentData = $context->payment_data ?? [];
        $recipientsPayload = $paymentData['satoshi_recipients'] ?? null;
        if (is_string($recipientsPayload)) {
            $recipientsPayload = json_decode($recipientsPayload, true);
        }
        if (!is_array($recipientsPayload) || empty($recipientsPayload)) {
            $recipientsPayload = WC()->session ? WC()->session->get('btcpay_satoshi_recipients') : null;
        }
        $order = $context->order;
        if (!$order instanceof \WC_Order) {
            return;
        }
        $ticketItems = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product || !ProductTypeTicket::isSatoshiTicketProduct($product)) {
                continue;
            }
            $eventId = self::getProductEventId($product);
            $ticketTypeId = self::getProductTicketTypeId($product);
            if (!$eventId || !$ticketTypeId) {
                continue;
            }
            $ticketItems[] = ['item' => $item, 'qty' => (int) $item->get_quantity()];
        }
        if (empty($ticketItems)) {
            return;
        }
        $isBillingMode = is_array($recipientsPayload)
            && isset($recipientsPayload['mode'])
            && $recipientsPayload['mode'] === 'billing';
        if ($isBillingMode) {
            $billing = [
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
            ];
            $recipientsPayload = [];
            foreach ($ticketItems as $t) {
                $arr = [];
                for ($i = 0; $i < $t['qty']; $i++) {
                    $arr[] = $billing;
                }
                $recipientsPayload[] = $arr;
            }
        } elseif (!is_array($recipientsPayload) || empty($recipientsPayload)) {
            return;
        }
        foreach ($ticketItems as $idx => $t) {
            $item = $t['item'];
            $product = $item->get_product();
            $eventId = self::getProductEventId($product);
            $ticketTypeId = self::getProductTicketTypeId($product);
            $item->update_meta_data('_satoshi_event_id', $eventId);
            $item->update_meta_data('_satoshi_ticket_type_id', $ticketTypeId);
            $rec = $recipientsPayload[$idx] ?? [];
            if (is_array($rec) && !empty($rec)) {
                $sanitized = [];
                foreach ($rec as $r) {
                    $sanitized[] = [
                        'first_name' => isset($r['first_name']) ? sanitize_text_field($r['first_name']) : '',
                        'last_name' => isset($r['last_name']) ? sanitize_text_field($r['last_name']) : '',
                        'email' => isset($r['email']) ? sanitize_email($r['email']) : '',
                    ];
                }
                $item->update_meta_data('_satoshi_recipients', $sanitized);
            }
            $idx++;
        }
        $order->save();
    }

    public static function maybeShowTicketConfigNotice(): void
    {
        $product = wc_get_product(get_the_ID());
        if (!$product || !ProductTypeTicket::isSatoshiTicketProduct($product)) {
            return;
        }
        if ($product instanceof WC_Product_Satoshi_Ticket && $product->has_valid_ticket_config()) {
            return;
        }
        if (!self::getProductEventId($product) || !self::getProductTicketTypeId($product)) {
            echo '<p class="woocommerce-info">' . esc_html__('This ticket product needs configuration (Event ID and Ticket Type ID). Please contact the store owner.', 'btcpay-satoshi-tickets') . '</p>';
        }
    }

    public static function enqueueCheckoutAssets(): void
    {
        if (is_checkout()) {
            wp_enqueue_style(
                'btcpay-satoshi-checkout',
                BTCPAY_SATOSHI_TICKETS_PLUGIN_URL . 'assets/css/checkout.css',
                [],
                BTCPAY_SATOSHI_TICKETS_VERSION
            );
            wp_enqueue_script(
                'btcpay-satoshi-checkout',
                BTCPAY_SATOSHI_TICKETS_PLUGIN_URL . 'assets/js/checkout.js',
                ['jquery'],
                BTCPAY_SATOSHI_TICKETS_VERSION,
                true
            );
        }
    }

    /**
     * @return array<string, array<int, array>>|null
     */
    public static function getCartTicketItems(): ?array
    {
        $cart = WC()->cart;
        if (!$cart) {
            return null;
        }

        $items = [];
        foreach ($cart->get_cart() as $key => $item) {
            $product = $item['data'] ?? null;
            if (!$product || !ProductTypeTicket::isSatoshiTicketProduct($product)) {
                continue;
            }
            $qty = (int) ($item['quantity'] ?? 0);
            if ($qty < 1) {
                continue;
            }
            $eventId = self::getProductEventId($product);
            $ticketTypeId = self::getProductTicketTypeId($product);
            if (!$eventId || !$ticketTypeId) {
                continue;
            }
            $items[$key] = [
                'product' => $product,
                'quantity' => $qty,
                'event_id' => $eventId,
                'ticket_type_id' => $ticketTypeId,
                'name' => $product->get_name(),
            ];
        }
        return empty($items) ? null : $items;
    }

    private static function getProductEventId(\WC_Product $product): string
    {
        if ($product instanceof WC_Product_Satoshi_Ticket) {
            return $product->get_event_id();
        }
        return (string) $product->get_meta(ProductTypeTicket::META_EVENT_ID, true);
    }

    private static function getProductTicketTypeId(\WC_Product $product): string
    {
        if ($product instanceof WC_Product_Satoshi_Ticket) {
            return $product->get_ticket_type_id();
        }
        return (string) $product->get_meta(ProductTypeTicket::META_TICKET_TYPE_ID, true);
    }

    /**
     * Ensure all ticket items are from the same event.
     */
    public static function validateSingleEvent(): bool
    {
        $items = self::getCartTicketItems();
        if (!$items) {
            return true;
        }

        $eventIds = array_unique(array_column($items, 'event_id'));
        return count($eventIds) <= 1;
    }

    public static function renderRecipientFields(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $items = self::getCartTicketItems();
        if (!$items) {
            return;
        }

        if (!self::validateSingleEvent()) {
            echo '<div class="woocommerce-error">' .
                esc_html__('Your cart contains tickets from different events. Please complete one event at a time.', 'btcpay-satoshi-tickets') .
                '</div>';
            return;
        }

        $totalTickets = array_sum(array_column($items, 'quantity'));
        if ($totalTickets < 1) {
            return;
        }

        echo '<div class="satoshi-recipient-fields" id="satoshi-recipient-fields">';
        echo '<h3>' . esc_html__('Ticket recipient details', 'btcpay-satoshi-tickets') . '</h3>';

        echo '<p class="form-row satoshi-recipient-mode">';
        echo '<label><input type="radio" name="satoshi_recipient_mode" value="billing" checked="checked" /> ';
        echo esc_html__('Send all tickets to my billing email', 'btcpay-satoshi-tickets') . '</label><br />';
        echo '<label><input type="radio" name="satoshi_recipient_mode" value="multiple" /> ';
        echo esc_html__('Send to multiple/different addresses', 'btcpay-satoshi-tickets') . '</label>';
        echo '</p>';

        echo '<div class="satoshi-recipient-rows" id="satoshi-recipient-rows" style="display:none;">';
        echo '<p class="form-row">' .
            esc_html__('Enter the name and email for each ticket. Each recipient will receive their ticket via email.', 'btcpay-satoshi-tickets') .
            '</p>';

        $index = 0;
        foreach ($items as $cartKey => $data) {
            $productName = $data['name'];
            $qty = $data['quantity'];
            for ($i = 0; $i < $qty; $i++) {
                $idx = $index;
                $label = $qty > 1 ? sprintf('%s #%d', $productName, $i + 1) : $productName;
                ?>
                <div class="satoshi-recipient-row" data-cart-key="<?php echo esc_attr($cartKey); ?>" data-index="<?php echo esc_attr((string) $idx); ?>">
                    <p class="form-row form-row-wide">
                        <strong><?php echo esc_html($label); ?></strong>
                    </p>
                    <p class="form-row form-row-first">
                        <label for="<?php echo esc_attr(self::FIELD_PREFIX . $idx . '_first'); ?>"><?php esc_html_e('First name', 'btcpay-satoshi-tickets'); ?></label>
                        <input type="text" id="<?php echo esc_attr(self::FIELD_PREFIX . $idx . '_first'); ?>"
                               name="satoshi_recipients[<?php echo esc_attr($cartKey); ?>][<?php echo esc_attr((string) $i); ?>][first_name]"
                               placeholder="<?php esc_attr_e('First name', 'btcpay-satoshi-tickets'); ?>" />
                    </p>
                    <p class="form-row form-row-last">
                        <label for="<?php echo esc_attr(self::FIELD_PREFIX . $idx . '_last'); ?>"><?php esc_html_e('Last name', 'btcpay-satoshi-tickets'); ?></label>
                        <input type="text" id="<?php echo esc_attr(self::FIELD_PREFIX . $idx . '_last'); ?>"
                               name="satoshi_recipients[<?php echo esc_attr($cartKey); ?>][<?php echo esc_attr((string) $i); ?>][last_name]"
                               placeholder="<?php esc_attr_e('Last name', 'btcpay-satoshi-tickets'); ?>" />
                    </p>
                    <p class="form-row form-row-wide">
                        <label for="<?php echo esc_attr(self::FIELD_PREFIX . $idx . '_email'); ?>"><?php esc_html_e('Email', 'btcpay-satoshi-tickets'); ?> <span class="required">*</span></label>
                        <input type="email" id="<?php echo esc_attr(self::FIELD_PREFIX . $idx . '_email'); ?>"
                               name="satoshi_recipients[<?php echo esc_attr($cartKey); ?>][<?php echo esc_attr((string) $i); ?>][email]"
                               placeholder="<?php esc_attr_e('Email', 'btcpay-satoshi-tickets'); ?>" />
                    </p>
                    <input type="hidden" name="satoshi_recipients[<?php echo esc_attr($cartKey); ?>][<?php echo esc_attr((string) $i); ?>][event_id]" value="<?php echo esc_attr($data['event_id']); ?>" />
                    <input type="hidden" name="satoshi_recipients[<?php echo esc_attr($cartKey); ?>][<?php echo esc_attr((string) $i); ?>][ticket_type_id]" value="<?php echo esc_attr($data['ticket_type_id']); ?>" />
                </div>
                <?php
                $index++;
            }
        }
        echo '</div></div>';
        $rendered = true;
    }

    public static function validateRecipientFields(): void
    {
        $cart = WC()->cart;
        if ($cart) {
            foreach ($cart->get_cart() as $item) {
                $product = $item['data'] ?? null;
                if ($product && ProductTypeTicket::isSatoshiTicketProduct($product)) {
                    if (!self::getProductEventId($product) || !self::getProductTicketTypeId($product)) {
                        wc_add_notice(
                            sprintf(
                                __('Product "%s" is not properly configured. Please edit it and set Event ID and Ticket Type ID in the product data.', 'btcpay-satoshi-tickets'),
                                $product->get_name()
                            ),
                            'error'
                        );
                        return;
                    }
                }
            }
        }

        $items = self::getCartTicketItems();
        if (!$items) {
            return;
        }

        if ($cart) {
            foreach ($cart->get_cart() as $item) {
                $product = $item['data'] ?? null;
                if ($product && !ProductTypeTicket::isSatoshiTicketProduct($product)) {
                    wc_add_notice(
                        __('Your cart contains both tickets and other products. Please checkout tickets separately.', 'btcpay-satoshi-tickets'),
                        'error'
                    );
                    return;
                }
            }
        }

        if (!self::validateSingleEvent()) {
            wc_add_notice(
                __('Your cart contains tickets from different events. Please complete one event at a time.', 'btcpay-satoshi-tickets'),
                'error'
            );
            return;
        }

        $mode = isset($_POST['satoshi_recipient_mode']) ? sanitize_text_field(wp_unslash($_POST['satoshi_recipient_mode'])) : 'billing';
        if ($mode !== 'multiple') {
            $billingEmail = isset($_POST['billing_email']) ? sanitize_email(wp_unslash($_POST['billing_email'])) : '';
            if (!is_email($billingEmail)) {
                wc_add_notice(
                    __('Valid billing email is required for ticket delivery.', 'btcpay-satoshi-tickets'),
                    'error'
                );
            }
            return;
        }

        $posted = isset($_POST['satoshi_recipients']) && is_array($_POST['satoshi_recipients']) ? wp_unslash($_POST['satoshi_recipients']) : [];

        foreach ($items as $cartKey => $data) {
            $qty = $data['quantity'];
            $recipients = $posted[$cartKey] ?? [];
            if (count($recipients) !== $qty) {
                wc_add_notice(
                    sprintf(
                        __('Please provide recipient details for all %d ticket(s) of "%s".', 'btcpay-satoshi-tickets'),
                        $qty,
                        $data['name']
                    ),
                    'error'
                );
                return;
            }
            foreach ($recipients as $i => $r) {
                $email = isset($r['email']) ? sanitize_email($r['email']) : '';
                if (!is_email($email)) {
                    wc_add_notice(
                        sprintf(__('Valid email required for ticket %d of "%s".', 'btcpay-satoshi-tickets'), $i + 1, $data['name']),
                        'error'
                    );
                    return;
                }
            }
        }
    }

    /**
     * Save recipient data to order for gateway to use.
     */
    public static function saveRecipientMeta($item, $cartItemKey, $values, $order): void
    {
        $product = $values['data'] ?? null;
        if (!$product || !ProductTypeTicket::isSatoshiTicketProduct($product)) {
            return;
        }

        $eventId = self::getProductEventId($product);
        $ticketTypeId = self::getProductTicketTypeId($product);
        $item->add_meta_data('_satoshi_event_id', $eventId, true);
        $item->add_meta_data('_satoshi_ticket_type_id', $ticketTypeId, true);

        $mode = isset($_POST['satoshi_recipient_mode']) ? sanitize_text_field(wp_unslash($_POST['satoshi_recipient_mode'])) : 'billing';
        if ($mode === 'multiple') {
            $posted = isset($_POST['satoshi_recipients']) && is_array($_POST['satoshi_recipients']) ? wp_unslash($_POST['satoshi_recipients']) : [];
            $recipients = $posted[$cartItemKey] ?? [];
            if (!empty($recipients)) {
                $item->add_meta_data('_satoshi_recipients', $recipients, true);
            }
            return;
        }

        $qty = (int) ($values['quantity'] ?? 0);
        if ($qty < 1) {
            return;
        }
        $email = $order->get_billing_email();
        if (empty($email) && isset($_POST['billing_email'])) {
            $email = sanitize_email(wp_unslash($_POST['billing_email']));
        }
        $firstName = $order->get_billing_first_name();
        if ($firstName === '' && isset($_POST['billing_first_name'])) {
            $firstName = sanitize_text_field(wp_unslash($_POST['billing_first_name']));
        }
        $lastName = $order->get_billing_last_name();
        if ($lastName === '' && isset($_POST['billing_last_name'])) {
            $lastName = sanitize_text_field(wp_unslash($_POST['billing_last_name']));
        }
        $recipients = [];
        for ($i = 0; $i < $qty; $i++) {
            $recipients[] = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
            ];
        }
        $item->add_meta_data('_satoshi_recipients', $recipients, true);
    }

    /**
     * Ensure all Satoshi ticket line items have valid recipient emails.
     * Fills missing/empty recipients from order billing (e.g. Blocks checkout).
     */
    public static function ensureRecipientsFromBilling(\WC_Order $order): void
    {
        $billingEmail = $order->get_billing_email();
        if (!is_email($billingEmail)) {
            return;
        }
        $firstName = $order->get_billing_first_name();
        $lastName = $order->get_billing_last_name();
        $needsSave = false;

        foreach ($order->get_items() as $item) {
            $eventId = $item->get_meta('_satoshi_event_id');
            $ticketTypeId = $item->get_meta('_satoshi_ticket_type_id');
            if (!$eventId || !$ticketTypeId) {
                continue;
            }
            $recipients = $item->get_meta('_satoshi_recipients');
            if (!is_array($recipients)) {
                $recipients = [];
            }
            $qty = max(1, (int) $item->get_quantity());
            $valid = 0;
            foreach ($recipients as $r) {
                if (isset($r['email']) && is_email($r['email'])) {
                    $valid++;
                }
            }
            if ($valid >= $qty && !empty($recipients)) {
                continue;
            }
            $filled = [];
            for ($i = 0; $i < $qty; $i++) {
                $r = $recipients[$i] ?? [];
                $email = isset($r['email']) && is_email($r['email']) ? $r['email'] : $billingEmail;
                $filled[] = [
                    'first_name' => $r['first_name'] ?? $firstName,
                    'last_name' => $r['last_name'] ?? $lastName,
                    'email' => $email,
                ];
            }
            $item->update_meta_data('_satoshi_recipients', $filled);
            $needsSave = true;
        }
        if ($needsSave) {
            $order->save();
        }
    }

    /**
     * Build Purchase API tickets array from order items.
     *
     * @return array<int, array{ticketTypeId: string, quantity: int, recipients: array}>
     */
    public static function buildPurchaseTicketsFromOrder(\WC_Order $order): array
    {
        $byTicketType = [];
        foreach ($order->get_items() as $item) {
            $recipients = $item->get_meta('_satoshi_recipients');
            $eventId = $item->get_meta('_satoshi_event_id');
            $ticketTypeId = $item->get_meta('_satoshi_ticket_type_id');
            if (!$recipients || !$ticketTypeId || !is_array($recipients)) {
                continue;
            }
            $key = $ticketTypeId;
            if (!isset($byTicketType[$key])) {
                $byTicketType[$key] = [
                    'ticketTypeId' => $ticketTypeId,
                    'quantity' => 0,
                    'recipients' => [],
                ];
            }
            foreach ($recipients as $r) {
                $byTicketType[$key]['recipients'][] = [
                    'firstName' => isset($r['first_name']) ? sanitize_text_field($r['first_name']) : '',
                    'lastName' => isset($r['last_name']) ? sanitize_text_field($r['last_name']) : '',
                    'email' => isset($r['email']) ? sanitize_email($r['email']) : '',
                ];
                $byTicketType[$key]['quantity']++;
            }
        }
        return array_values($byTicketType);
    }

    /**
     * Get event ID from order (all items should be same event).
     */
    public static function getEventIdFromOrder(\WC_Order $order): ?string
    {
        foreach ($order->get_items() as $item) {
            $eventId = $item->get_meta('_satoshi_event_id');
            if ($eventId) {
                return $eventId;
            }
        }
        return null;
    }

}
