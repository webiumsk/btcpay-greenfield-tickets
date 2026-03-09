<?php
/**
 * WooCommerce Blocks integration for Satoshi Tickets payment gateway.
 *
 * Registers the payment method for the Cart & Checkout blocks.
 *
 * @package BTCPaySatoshiTickets
 */

declare(strict_types=1);

namespace BTCPaySatoshiTickets;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (!defined('ABSPATH')) {
    exit;
}

final class GatewaySatoshiTicketsBlocks extends AbstractPaymentMethodType
{
    protected $name = GatewaySatoshiTickets::GATEWAY_ID;

    public function initialize(): void
    {
        $this->settings = get_option('woocommerce_' . $this->name . '_settings', []);
    }

    public function is_active(): bool
    {
        return !empty($this->settings['enabled']) && $this->settings['enabled'] === 'yes';
    }

    public function get_payment_method_script_handles(): array
    {
        $script_path = 'assets/js/checkout-blocks.js';
        wp_register_script(
            'btcpay-satoshi-tickets-blocks',
            BTCPAY_SATOSHI_TICKETS_PLUGIN_URL . $script_path,
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
        wp_localize_script('btcpay-satoshi-tickets-blocks', 'btcpaySatoshiBlocks', [
            'cartTicketsUrl' => rest_url('btcpay-satoshi-tickets/v1/cart-tickets'),
            'restNonce' => wp_create_nonce('wp_rest'),
        ]);
        return ['btcpay-satoshi-tickets-blocks'];
    }

    public function get_payment_method_data(): array
    {
        $baseDescription = $this->get_setting('description', __('Pay with Bitcoin. You will be redirected to BTCPay to complete payment.', 'btcpay-satoshi-tickets'));
        $description = $baseDescription;
        if ($this->get_setting('discount_enabled', 'no') === 'yes') {
            $percent = (float) $this->get_setting('discount_percent', '10');
            if ($percent > 0 && $percent < 100) {
                $template = $this->get_setting('discount_message', __('% zľava pri platbe bitcoinom', 'btcpay-satoshi-tickets'));
                $msg = str_replace('%', (string) $percent, $template);
                $description = $baseDescription !== '' ? $baseDescription . ' ' . $msg : $msg;
            }
        }
        return [
            'title' => $this->get_setting('title', __('Bitcoin (Satoshi Tickets)', 'btcpay-satoshi-tickets')),
            'description' => $description,
            'icon' => GatewaySatoshiTickets::getIconUrl($this->get_setting('icon', '')),
            'supports' => ['products'],
            'blocksRecipientLabel' => __('Ticket recipient details', 'btcpay-satoshi-tickets'),
            'blocksBillingOption' => __('Send all tickets to my billing email', 'btcpay-satoshi-tickets'),
            'blocksMultipleOption' => __('Send to different addresses', 'btcpay-satoshi-tickets'),
            'blocksEmailLabel' => __('Email', 'btcpay-satoshi-tickets'),
            'blocksFirstNameLabel' => __('First name', 'btcpay-satoshi-tickets'),
            'blocksLastNameLabel' => __('Last name', 'btcpay-satoshi-tickets'),
            'blocksEmailRequiredError' => __('Please enter a valid email for each ticket recipient.', 'btcpay-satoshi-tickets'),
            'cartTicketsUrl' => rest_url('btcpay-satoshi-tickets/v1/cart-tickets'),
            'restNonce' => wp_create_nonce('wp_rest'),
        ];
    }
}
