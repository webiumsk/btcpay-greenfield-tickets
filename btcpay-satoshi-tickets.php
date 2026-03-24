<?php
/**
 * Plugin Name: BTCPay GF Satoshi Tickets for WooCommerce
 * Plugin URI: https://github.com/webiumsk/btcpay-greenfield-tickets-woocommerce
 * Description: Sell SatoshiTickets (event tickets) via WooCommerce. Integrates with BTCPay Server SatoshiTickets plugin. Works with or without BTCPay Greenfield for WooCommerce.
 * Version: 1.1.0
 * Author: webiumsk
 * Author URI: https://www.webium.sk
 * License: MIT
 * Text Domain: btcpay-satoshi-tickets
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 8.0
 * WC requires at least: 6.0
 * WC tested up to: 8.5
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('BTCPAY_SATOSHI_TICKETS_VERSION', '1.1.0');
define('BTCPAY_SATOSHI_TICKETS_PLUGIN_FILE', __FILE__);
define('BTCPAY_SATOSHI_TICKETS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('BTCPAY_SATOSHI_TICKETS_PLUGIN_URL', plugin_dir_url(__FILE__));

add_action('before_woocommerce_init', function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

/**
 * Check dependencies on plugins_loaded.
 */
add_action('woocommerce_blocks_loaded', function (): void {
    if (!class_exists('WooCommerce')) {
        return;
    }
    require_once BTCPAY_SATOSHI_TICKETS_PLUGIN_PATH . 'includes/class-store-api-extensions.php';
    \BTCPaySatoshiTickets\StoreApiExtensions::init();
    if (function_exists('woocommerce_store_api_register_update_callback')) {
        woocommerce_store_api_register_update_callback([
            'namespace' => 'btcpay_satoshi_tickets',
            'callback' => function (array $data): void {
                if (WC()->session) {
                    if (isset($data['payment_method']) && $data['payment_method'] === \BTCPaySatoshiTickets\GatewaySatoshiTickets::GATEWAY_ID) {
                        WC()->session->set('chosen_payment_method', \BTCPaySatoshiTickets\GatewaySatoshiTickets::GATEWAY_ID);
                    }
                    if (isset($data['satoshi_recipients'])) {
                        $payload = $data['satoshi_recipients'];
                        if (is_string($payload)) {
                            $decoded = json_decode($payload, true);
                            $payload = is_array($decoded) ? $decoded : null;
                        }
                        WC()->session->set('btcpay_satoshi_recipients', is_array($payload) ? $payload : null);
                    }
                }
            },
        ]);
    }
    if (!class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
        return;
    }
    require_once BTCPAY_SATOSHI_TICKETS_PLUGIN_PATH . 'includes/class-gateway-satoshi-tickets-blocks.php';
    require_once BTCPAY_SATOSHI_TICKETS_PLUGIN_PATH . 'includes/class-checkout-recipients-integration.php';
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function (\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry) {
            $registry->register(new \BTCPaySatoshiTickets\GatewaySatoshiTicketsBlocks());
        }
    );
    $registerIntegration = function ($registry) {
        if (method_exists($registry, 'register')) {
            $registry->register(new \BTCPaySatoshiTickets\CheckoutRecipientsIntegration());
        }
    };
    add_action('woocommerce_blocks_checkout_block_registration', $registerIntegration);
    add_action('woocommerce_blocks_cart_block_registration', $registerIntegration);
});

add_action('plugins_loaded', function (): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>' .
                esc_html__('BTCPay Satoshi Tickets requires WooCommerce to be installed and active.', 'btcpay-satoshi-tickets') .
                '</p></div>';
        });
        return;
    }

    require_once BTCPAY_SATOSHI_TICKETS_PLUGIN_PATH . 'includes/class-satoshi-api-client.php';
    require_once BTCPAY_SATOSHI_TICKETS_PLUGIN_PATH . 'includes/class-product-type-ticket.php';
    require_once BTCPAY_SATOSHI_TICKETS_PLUGIN_PATH . 'includes/class-admin-events.php';
    require_once BTCPAY_SATOSHI_TICKETS_PLUGIN_PATH . 'includes/class-checkout-handler.php';
    require_once BTCPAY_SATOSHI_TICKETS_PLUGIN_PATH . 'includes/class-gateway-satoshi-tickets.php';
    require_once BTCPAY_SATOSHI_TICKETS_PLUGIN_PATH . 'includes/class-admin-wc-settings-tab.php';
    require_once BTCPAY_SATOSHI_TICKETS_PLUGIN_PATH . 'includes/class-settings.php';

    \BTCPaySatoshiTickets\ProductTypeTicket::init();
    \BTCPaySatoshiTickets\AdminEvents::init();
    \BTCPaySatoshiTickets\CheckoutHandler::init();
    \BTCPaySatoshiTickets\AdminWCSettingsTab::init();
    \BTCPaySatoshiTickets\Settings::init();

    add_action('admin_menu', function (): void {
        add_submenu_page(
            'woocommerce',
            __('Satoshi Tickets', 'btcpay-satoshi-tickets'),
            __('Satoshi Tickets', 'btcpay-satoshi-tickets'),
            'manage_woocommerce',
            'btcpay-satoshi-tickets',
            function (): void {
                wp_safe_redirect(\BTCPaySatoshiTickets\AdminWCSettingsTab::getTabUrl(\BTCPaySatoshiTickets\AdminWCSettingsTab::SECTION_EVENTS));
                exit;
            },
            56
        );
    }, 60);

    add_filter('woocommerce_payment_gateways', function (array $gateways): array {
        $gateways[] = \BTCPaySatoshiTickets\GatewaySatoshiTickets::class;
        return $gateways;
    });

    add_action('woocommerce_cart_calculate_fees', function ($cart): void {
        $gateway = new \BTCPaySatoshiTickets\GatewaySatoshiTickets();
        $gateway->applyBtcDiscount($cart);
    }, 5);

    require_once BTCPAY_SATOSHI_TICKETS_PLUGIN_PATH . 'includes/class-webhook-handler.php';
    require_once BTCPAY_SATOSHI_TICKETS_PLUGIN_PATH . 'includes/class-stock-sync-cron.php';
    \BTCPaySatoshiTickets\WebhookHandler::init();
    \BTCPaySatoshiTickets\StockSyncCron::init();

    add_filter('plugin_action_links_' . plugin_basename(BTCPAY_SATOSHI_TICKETS_PLUGIN_FILE), function (array $links): array {
        $links[] = '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=btcpay_satoshi')) . '">' . esc_html__('Settings', 'btcpay-satoshi-tickets') . '</a>';
        return $links;
    });
}, 20);

add_action('admin_notices', function (): void {
    if (!class_exists('WooCommerce')) {
        return;
    }
    $client = new \BTCPaySatoshiTickets\SatoshiApiClient();
    if (!$client->isConfigured()) {
        $screen = get_current_screen();
        if ($screen && (strpos($screen->id, 'woocommerce') !== false || strpos($screen->id, 'btcpay') !== false)) {
            $cfg = \BTCPaySatoshiTickets\SatoshiApiClient::getConfig();
            $url = $cfg ? admin_url('admin.php?page=wc-settings&tab=btcpay_settings') : \BTCPaySatoshiTickets\AdminWCSettingsTab::getTabUrl();
            echo '<div class="notice notice-warning"><p>' .
                esc_html__('BTCPay Satoshi Tickets: Please configure BTCPay Server connection.', 'btcpay-satoshi-tickets') .
                ' <a href="' . esc_url($url) . '">' . esc_html__('Settings', 'btcpay-satoshi-tickets') . '</a></p></div>';
        }
    }
});

register_activation_hook(__FILE__, function (): void {
    if (!get_option('btcpay_satoshi_tickets_version')) {
        add_option('btcpay_satoshi_tickets_version', BTCPAY_SATOSHI_TICKETS_VERSION);
    }
    require_once plugin_dir_path(__FILE__) . 'includes/class-stock-sync-cron.php';
    if (class_exists(\BTCPaySatoshiTickets\StockSyncCron::class)) {
        add_filter('cron_schedules', [\BTCPaySatoshiTickets\StockSyncCron::class, 'addCronSchedule']);
        \BTCPaySatoshiTickets\StockSyncCron::scheduleIfNeeded();
    }
});

register_deactivation_hook(__FILE__, function (): void {
    if (class_exists(\BTCPaySatoshiTickets\StockSyncCron::class)) {
        \BTCPaySatoshiTickets\StockSyncCron::unschedule();
    }
});
