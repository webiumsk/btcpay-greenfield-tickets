<?php
/**
 * Block Integration: loads recipient slot fill on checkout regardless of payment method.
 *
 * @package BTCPaySatoshiTickets
 */

declare(strict_types=1);

namespace BTCPaySatoshiTickets;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

if (!defined('ABSPATH')) {
    exit;
}

final class CheckoutRecipientsIntegration implements IntegrationInterface
{
    private const SCRIPT_HANDLE = 'btcpay-satoshi-tickets-blocks';

    public function get_name(): string
    {
        return 'btcpay_satoshi_tickets_recipients';
    }

    public function initialize(): void
    {
        $script_path = 'assets/js/checkout-blocks.js';
        $script_url = BTCPAY_SATOSHI_TICKETS_PLUGIN_URL . $script_path;

        if (!wp_script_is(self::SCRIPT_HANDLE, 'registered')) {
            wp_register_script(
                self::SCRIPT_HANDLE,
                $script_url,
                [
                    'wc-blocks-registry',
                    'wc-blocks-checkout',
                    'wc-settings',
                    'wp-element',
                    'wp-html-entities',
                    'wp-data',
                    'wp-plugins',
                ],
                BTCPAY_SATOSHI_TICKETS_VERSION,
                true
            );
            wp_localize_script(self::SCRIPT_HANDLE, 'btcpaySatoshiBlocks', [
                'cartTicketsUrl' => rest_url('btcpay-satoshi-tickets/v1/cart-tickets'),
                'restNonce' => wp_create_nonce('wp_rest'),
            ]);
        }
    }

    public function get_script_handles(): array
    {
        return [self::SCRIPT_HANDLE];
    }

    public function get_editor_script_handles(): array
    {
        return [];
    }

    public function get_script_data(): array
    {
        return [];
    }
}
