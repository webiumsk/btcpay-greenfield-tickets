<?php
/**
 * BTCPay webhook handler for invoice payment events.
 *
 * @package BTCPaySatoshiTickets
 */

declare(strict_types=1);

namespace BTCPaySatoshiTickets;

if (!defined('ABSPATH')) {
    exit;
}

final class WebhookHandler
{
    private const OPTION_WEBHOOK_SECRET = 'btcpay_satoshi_webhook_secret';
    private const OPTION_WEBHOOK_ID = 'btcpay_satoshi_webhook_id';

    public static function hasWebhookSecret(): bool
    {
        return get_option(self::OPTION_WEBHOOK_SECRET, '') !== '';
    }

    public static function init(): void
    {
        add_action('rest_api_init', [__CLASS__, 'registerRestRoute']);
        add_filter('woocommerce_payment_complete_order_status', [__CLASS__, 'paymentCompleteOrderStatus'], 10, 3);
    }

    /**
     * Set virtual-only orders to Completed when paid (BTCPay, Stripe, etc.).
     */
    public static function paymentCompleteOrderStatus(string $status, int $orderId, \WC_Order $order): string
    {
        if (!$order->needs_shipping()) {
            return 'completed';
        }
        return $status;
    }

    public static function registerRestRoute(): void
    {
        register_rest_route('btcpay-satoshi/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handleWebhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function getWebhookUrl(): string
    {
        return rest_url('btcpay-satoshi/v1/webhook');
    }

    /**
     * Register webhook with BTCPay. Requires btcpay.store.webhooks.canmodifywebhooks or canmodifystoresettings.
     */
    public static function registerWebhook(): bool
    {
        $cfg = SatoshiApiClient::getConfig();
        if (empty($cfg)) {
            return false;
        }
        $url = self::getWebhookUrl();
        $apiUrl = rtrim($cfg['url'], '/') . '/api/v1/stores/' . $cfg['store_id'] . '/webhooks';
        $headers = [
            'Authorization' => 'token ' . $cfg['api_key'],
            'Content-Type' => 'application/json',
        ];
        $body = wp_json_encode([
            'url' => $url,
            'authorizedEvents' => [
                'everything' => false,
                'specificEvents' => ['InvoiceSettled'],
            ],
        ]);
        $response = wp_remote_post($apiUrl, [
            'headers' => $headers,
            'body' => $body,
            'timeout' => 15,
        ]);
        if (is_wp_error($response)) {
            set_transient('btcpay_satoshi_webhook_last_error', $response->get_error_message(), 300);
            return false;
        }
        $code = wp_remote_retrieve_response_code($response);
        $respBody = wp_remote_retrieve_body($response);
        $data = json_decode($respBody, true);
        if ($code >= 200 && $code < 300 && is_array($data)) {
            delete_transient('btcpay_satoshi_webhook_last_error');
            $secret = $data['secret'] ?? '';
            $webhookId = $data['id'] ?? '';
            if ($secret !== '') {
                update_option(self::OPTION_WEBHOOK_SECRET, $secret);
                update_option(self::OPTION_WEBHOOK_ID, $webhookId);
                return true;
            }
        }
        $err = is_array($data) && isset($data['message']) ? $data['message'] : $respBody;
        if (is_array($data) && !empty($data['errors'])) {
            $err = implode(' ', array_map(fn ($e) => $e['message'] ?? json_encode($e), $data['errors']));
        }
        set_transient('btcpay_satoshi_webhook_last_error', trim((string) $err) ?: "HTTP $code", 300);
        return false;
    }

    public static function handleWebhook(\WP_REST_Request $request): \WP_REST_Response
    {
        $sigHeader = $request->get_header('BTCPay-Sig');
        $body = $request->get_body();
        $secret = get_option(self::OPTION_WEBHOOK_SECRET, '');
        if ($secret === '' || $sigHeader === null || $sigHeader === '') {
            return new \WP_REST_Response(null, 401);
        }
        $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
        if (!hash_equals($expected, $sigHeader)) {
            return new \WP_REST_Response(null, 401);
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return new \WP_REST_Response(null, 400);
        }
        $type = $data['type'] ?? '';
        if ($type !== 'InvoiceSettled') {
            return new \WP_REST_Response(['received' => true], 200);
        }
        $invoiceId = $data['invoiceId'] ?? ($data['invoice']['id'] ?? '');
        if ($invoiceId === '') {
            return new \WP_REST_Response(['received' => true], 200);
        }
        $orders = wc_get_orders([
            'limit' => 1,
            'meta_key' => 'BTCPay_id',
            'meta_value' => $invoiceId,
            'return' => 'ids',
        ]);
        if (empty($orders)) {
            return new \WP_REST_Response(['received' => true], 200);
        }
        $order = wc_get_order($orders[0]);
        if (!$order || $order->is_paid()) {
            return new \WP_REST_Response(['received' => true], 200);
        }
        $order->payment_complete();
        return new \WP_REST_Response(['received' => true], 200);
    }
}
