<?php
/**
 * Payment gateway for Satoshi Tickets.
 *
 * Uses SatoshiTickets Purchase API, redirects to BTCPay checkout.
 * Order completion via BTCPay webhook (shared with BTCPay WC plugin).
 *
 * @package BTCPaySatoshiTickets
 */

declare(strict_types=1);

namespace BTCPaySatoshiTickets;

if (!defined('ABSPATH')) {
    exit;
}

class GatewaySatoshiTickets extends \WC_Payment_Gateway
{
    public const GATEWAY_ID = 'btcpaygf_satoshi_tickets';

    public function __construct()
    {
        $this->id = self::GATEWAY_ID;
        $this->method_title = __('Satoshi Tickets (BTCPay)', 'btcpay-satoshi-tickets');
        $this->method_description = __('Accept Bitcoin for event tickets via SatoshiTickets on BTCPay Server.', 'btcpay-satoshi-tickets');
        $this->title = $this->get_option('title', __('Bitcoin (Satoshi Tickets)', 'btcpay-satoshi-tickets'));
        $this->description = $this->get_option('description', __('Pay with Bitcoin. You will be redirected to BTCPay to complete payment.', 'btcpay-satoshi-tickets'));
        $this->icon = self::getIconUrl($this->get_option('icon', ''));
        $this->has_fields = false;
        $this->supports = ['products'];
        $this->init_form_fields();
        $this->init_settings();
        $this->migrateSurchargeToDiscount();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueGatewayAdminScripts']);
        add_action('woocommerce_checkout_update_order_review', [$this, 'syncChosenPaymentFromPostData'], 1);
        add_filter('woocommerce_gateway_description', [$this, 'appendDiscountMessage'], 10, 2);
    }

    /**
     * Resolve icon URL from attachment ID or return default icon.
     */
    public static function getIconUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return BTCPAY_SATOSHI_TICKETS_PLUGIN_URL . 'assets/btc-ln-default.png';
        }
        if (is_numeric($value)) {
            $url = wp_get_attachment_image_url((int) $value, 'full');
            if ($url !== false) {
                return $url;
            }
        }
        return BTCPAY_SATOSHI_TICKETS_PLUGIN_URL . 'assets/btc-ln-default.png';
    }

    public function enqueueGatewayAdminScripts(string $hook): void
    {
        if ($hook !== 'woocommerce_page_wc-settings') {
            return;
        }
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'checkout' || !isset($_GET['section']) || $_GET['section'] !== self::GATEWAY_ID) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script(
            'btcpay-satoshi-gateway-admin',
            BTCPAY_SATOSHI_TICKETS_PLUGIN_URL . 'assets/js/gateway-admin.js',
            ['jquery'],
            BTCPAY_SATOSHI_TICKETS_VERSION,
            true
        );
        wp_localize_script('btcpay-satoshi-gateway-admin', 'btcpaySatoshiGateway', [
            'defaultIconUrl' => BTCPAY_SATOSHI_TICKETS_PLUGIN_URL . 'assets/btc-ln-default.png',
        ]);
    }

    /**
     * @param string $key
     * @param array<string, mixed> $data
     */
    public function generate_satoshi_icon_html(string $key, array $data): string
    {
        $field_key = $this->get_field_key($key);
        $value = $this->get_option($key, '');
        $defaults = [
            'title' => '',
            'description' => '',
        ];
        $data = wp_parse_args($data, $defaults);
        $preview_url = self::getIconUrl($value);
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo esc_html($data['title']); ?></label>
            </th>
            <td class="forminp">
                <input type="hidden" id="<?php echo esc_attr($field_key); ?>" name="<?php echo esc_attr($field_key); ?>" value="<?php echo esc_attr($value); ?>" />
                <div class="btcpay-satoshi-icon-upload">
                    <div class="btcpay-satoshi-icon-preview" style="margin-bottom:8px;">
                        <img src="<?php echo esc_url($preview_url); ?>" alt="" style="max-width:64px;max-height:64px;vertical-align:middle;" />
                    </div>
                    <button type="button" class="button btcpay-satoshi-icon-select"><?php esc_html_e('Select image', 'btcpay-satoshi-tickets'); ?></button>
                    <button type="button" class="button btcpay-satoshi-icon-remove" <?php echo $value === '' ? 'style="display:none;"' : ''; ?>><?php esc_html_e('Remove', 'btcpay-satoshi-tickets'); ?></button>
                </div>
                <?php if (!empty($data['description'])) : ?>
                    <p class="description"><?php echo esc_html($data['description']); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'btcpay-satoshi-tickets'),
                'type' => 'checkbox',
                'label' => __('Enable Satoshi Tickets payment', 'btcpay-satoshi-tickets'),
                'default' => 'yes',
            ],
            'title' => [
                'title' => __('Title', 'btcpay-satoshi-tickets'),
                'type' => 'text',
                'description' => __('Payment method title shown to customers.', 'btcpay-satoshi-tickets'),
                'default' => __('Bitcoin (Satoshi Tickets)', 'btcpay-satoshi-tickets'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'btcpay-satoshi-tickets'),
                'type' => 'textarea',
                'description' => __('Description shown to customers.', 'btcpay-satoshi-tickets'),
                'default' => __('Pay with Bitcoin. You will be redirected to BTCPay to complete payment.', 'btcpay-satoshi-tickets'),
                'desc_tip' => true,
            ],
            'icon' => [
                'title' => __('Gateway Icon', 'btcpay-satoshi-tickets'),
                'type' => 'satoshi_icon',
                'description' => __('Icon shown next to the payment method title in checkout. Upload from media library or leave empty to use default.', 'btcpay-satoshi-tickets'),
                'default' => '',
            ],
            'discount_enabled' => [
                'title' => __('Discount for Bitcoin payment', 'btcpay-satoshi-tickets'),
                'type' => 'checkbox',
                'label' => __('Enable discount when customer pays with Bitcoin', 'btcpay-satoshi-tickets'),
                'default' => 'no',
            ],
            'discount_percent' => [
                'title' => __('Discount percentage', 'btcpay-satoshi-tickets'),
                'type' => 'number',
                'description' => __('Product prices in the shop are the full (fiat) price. When paying with Bitcoin, this discount is applied. No fee is added for fiat payments.', 'btcpay-satoshi-tickets'),
                'default' => '10',
                'desc_tip' => true,
                'custom_attributes' => [
                    'min' => '0',
                    'max' => '99.99',
                    'step' => '0.01',
                ],
            ],
            'discount_message' => [
                'title' => __('Discount message', 'btcpay-satoshi-tickets'),
                'type' => 'text',
                'description' => __('Message shown for the discount when Bitcoin is selected. Use % as placeholder for the discount percent.', 'btcpay-satoshi-tickets'),
                'default' => __('% discount for Bitcoin payment', 'btcpay-satoshi-tickets'),
                'placeholder' => '% discount for Bitcoin payment',
                'desc_tip' => true,
            ],
        ];
    }

    /**
     * Migrate legacy surcharge_* settings to discount_*.
     */
    private function migrateSurchargeToDiscount(): void
    {
        $settings = $this->settings;
        if (!is_array($settings)) {
            return;
        }
        $changed = false;
        if (isset($settings['surcharge_enabled']) && !isset($settings['discount_enabled'])) {
            $settings['discount_enabled'] = $settings['surcharge_enabled'];
            $changed = true;
        }
        if (isset($settings['surcharge_percent']) && !isset($settings['discount_percent'])) {
            $surcharge = (float) $settings['surcharge_percent'];
            $settings['discount_percent'] = $surcharge > 0
                ? round(100 * $surcharge / (100 + $surcharge), 1)
                : '10';
            $changed = true;
        }
        if ($changed) {
            unset($settings['surcharge_enabled'], $settings['surcharge_percent'], $settings['surcharge_label']);
            $this->settings = $settings;
            update_option($this->get_option_key(), $settings);
        }
    }

    /**
     * Only show when cart contains ONLY Satoshi Ticket products (from one event).
     */
    public function is_available(): bool
    {
        if ($this->enabled !== 'yes') {
            return false;
        }

        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            return false;
        }

        $items = CheckoutHandler::getCartTicketItems();
        if (!$items || !CheckoutHandler::validateSingleEvent()) {
            return false;
        }

        $cart = WC()->cart;
        if (!$cart) {
            return false;
        }

        foreach ($cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            if ($product && !ProductTypeTicket::isSatoshiTicketProduct($product)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Ensure payment_method is in $_POST and session before totals are calculated.
     * WooCommerce checkout sends form data as post_data (serialized string);
     * $_POST['payment_method'] may be empty, so WC's session update gets wrong value.
     * We parse post_data and populate $_POST/session. If post_data is empty and our gateway
     * is the only one available, set it anyway (handles first AJAX request when form not yet serialized).
     */
    public function syncChosenPaymentFromPostData(string $postData): void
    {
        $pm = '';
        if ($postData !== '') {
            $post = [];
            parse_str($postData, $post);
            $pm = isset($post['payment_method']) && is_string($post['payment_method']) ? sanitize_text_field($post['payment_method']) : '';
        }
        if ($pm === '' && WC()->session && WC()->payment_gateways) {
            $available = WC()->payment_gateways()->get_available_payment_gateways();
            if (isset($available[self::GATEWAY_ID]) && count($available) === 1) {
                $pm = self::GATEWAY_ID;
            }
        }
        if ($pm !== '') {
            $_POST['payment_method'] = $pm;
            if (WC()->session) {
                WC()->session->set('chosen_payment_method', $pm);
            }
        }
    }

    /**
     * Append discount message to gateway description when Bitcoin is available and discount is enabled.
     */
    public function appendDiscountMessage(string $description, string $gatewayId): string
    {
        if ($gatewayId !== self::GATEWAY_ID) {
            return $description;
        }
        if ($this->get_option('discount_enabled', 'no') !== 'yes') {
            return $description;
        }
        $percent = (float) $this->get_option('discount_percent', '10');
        if ($percent <= 0 || $percent >= 100) {
            return $description;
        }
        $template = $this->get_option('discount_message', __('% discount for Bitcoin payment', 'btcpay-satoshi-tickets'));
        $msg = str_replace('%', (string) $percent, $template);
        if ($msg !== '' && $description !== '') {
            return $description . ' ' . $msg;
        }
        return $msg !== '' ? $msg : $description;
    }

    /**
     * Add discount (negative fee) when payment method IS Bitcoin.
     * Product prices = full fiat price. BTC customers get X% discount. Fiat = no fee.
     */
    public function applyBtcDiscount(\WC_Cart $cart): void
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        if ($this->get_option('discount_enabled', 'no') !== 'yes') {
            return;
        }
        $chosen = '';
        $hasFormData = false;
        if (wp_doing_ajax()) {
            if (isset($_POST['payment_method']) && is_string($_POST['payment_method'])) {
                $chosen = sanitize_text_field(wp_unslash($_POST['payment_method']));
                $hasFormData = true;
            } elseif (!empty($_POST['post_data']) && is_string($_POST['post_data'])) {
                $post = [];
                parse_str(wp_unslash($_POST['post_data']), $post);
                if (isset($post['payment_method']) && is_string($post['payment_method'])) {
                    $chosen = sanitize_text_field($post['payment_method']);
                    $hasFormData = true;
                }
            }
        }
        if (!$hasFormData) {
            $chosen = WC()->session ? WC()->session->get('chosen_payment_method') : '';
            $available = WC()->payment_gateways ? WC()->payment_gateways()->get_available_payment_gateways() : [];
            if ($chosen === '' && is_checkout() && isset($available[self::GATEWAY_ID]) && count($available) === 1) {
                $chosen = self::GATEWAY_ID;
            }
        }
        if ($chosen !== self::GATEWAY_ID) {
            return;
        }
        $discountPercent = (float) $this->get_option('discount_percent', '10');
        if ($discountPercent <= 0 || $discountPercent >= 100) {
            return;
        }
        $subtotal = (float) $cart->get_subtotal();
        if ($subtotal <= 0) {
            $subtotal = (float) $cart->get_cart_contents_total();
        }
        if ($subtotal <= 0) {
            return;
        }
        $discountAmount = round(($subtotal / 100) * $discountPercent, wc_get_price_decimals());
        if ($discountAmount <= 0) {
            return;
        }
        $template = $this->get_option('discount_message', __('% discount for Bitcoin payment', 'btcpay-satoshi-tickets'));
        $label = str_replace('%', (string) $discountPercent, $template);
        $cart->add_fee($label, -$discountAmount, false);
    }

    /**
     * @param int $order_id
     * @return array{result: string, redirect?: string, messages?: string}
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Order not found.', 'btcpay-satoshi-tickets'), 'error');
            return ['result' => 'failure'];
        }

        $eventId = CheckoutHandler::getEventIdFromOrder($order);
        if (!$eventId) {
            wc_add_notice(__('Invalid ticket order.', 'btcpay-satoshi-tickets'), 'error');
            return ['result' => 'failure'];
        }

        CheckoutHandler::ensureRecipientsFromBilling($order);

        $tickets = CheckoutHandler::buildPurchaseTicketsFromOrder($order);
        if (empty($tickets)) {
            wc_add_notice(__('Missing recipient details for tickets.', 'btcpay-satoshi-tickets'), 'error');
            return ['result' => 'failure'];
        }

        $client = new SatoshiApiClient();
        $toValidate = array_map(function ($t) {
            return ['ticketTypeId' => $t['ticketTypeId'], 'quantity' => $t['quantity']];
        }, $tickets);
        $validation = $client->validateTicketQuantities($eventId, $toValidate);
        if (!$validation['valid']) {
            wc_add_notice($validation['message'] ?? __('Not enough tickets available.', 'btcpay-satoshi-tickets'), 'error');
            return ['result' => 'failure'];
        }

        $orderTotal = 0.0;
        $eventCurrency = null;
        $eventResult = $client->getEvent($eventId);
        if ($eventResult['success'] && is_array($eventResult['data'] ?? null)) {
            $eventCurrency = $eventResult['data']['currency'] ?? $eventResult['data']['Currency'] ?? null;
        }
        $orderCurrency = $order->get_currency();
        if ($eventCurrency !== null && strtoupper((string) $eventCurrency) === strtoupper((string) $orderCurrency)) {
            $orderTotal = (float) $order->get_total();
        }

        $redirectUrl = $this->get_return_url($order);
        $result = $client->createPurchase($eventId, $tickets, $redirectUrl, $orderTotal);

        if (!$result['success']) {
            wc_add_notice(
                $result['message'] ?? __('Failed to create ticket purchase. Please try again.', 'btcpay-satoshi-tickets'),
                'error'
            );
            return ['result' => 'failure'];
        }

        $data = $result['data'] ?? [];
        $checkoutUrl = $data['checkoutUrl'] ?? '';
        $invoiceId = $data['invoiceId'] ?? '';

        if ($checkoutUrl === '' || $invoiceId === '') {
            wc_add_notice(__('Invalid response from ticket service.', 'btcpay-satoshi-tickets'), 'error');
            return ['result' => 'failure'];
        }

        $order->update_meta_data('BTCPay_id', $invoiceId);
        $order->update_meta_data('_btcpay_satoshi_order_id', $data['orderId'] ?? '');
        $order->update_meta_data('_btcpay_satoshi_txn_id', $data['txnId'] ?? '');
        $order->save();

        WC()->cart->empty_cart();

        return [
            'result' => 'success',
            'redirect' => $checkoutUrl,
        ];
    }
}
