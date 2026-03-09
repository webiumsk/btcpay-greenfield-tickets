<?php
/**
 * Fired when the plugin is uninstalled.
 * Deletes all plugin data if the user opted in via "Delete all data on uninstall".
 *
 * @package BTCPaySatoshiTickets
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (get_option('btcpay_satoshi_delete_data_on_uninstall', '0') !== '1') {
    return;
}

global $wpdb;

$options = [
    'btcpay_satoshi_url',
    'btcpay_satoshi_api_key',
    'btcpay_satoshi_store_id',
    'btcpay_satoshi_satflux_url',
    'btcpay_satoshi_show_satflux',
    'btcpay_satoshi_satflux_store_id',
    'btcpay_satoshi_satflux_checkin_store_id',
    'btcpay_satoshi_connected_via_satflux',
    'btcpay_satoshi_webhook_secret',
    'btcpay_satoshi_webhook_id',
    'btcpay_satoshi_delete_data_on_uninstall',
    'btcpay_satoshi_tickets_version',
    'btcpay_satoshi_stock_sync_enabled',
    'btcpay_satoshi_stock_sync_interval',
];
foreach ($options as $opt) {
    delete_option($opt);
}

delete_option('woocommerce_btcpaygf_satoshi_tickets_settings');

delete_transient('btcpay_satoshi_webhook_register_retry');
delete_transient('btcpay_satoshi_webhook_last_error');

$order_meta_keys = ['BTCPay_id', '_btcpay_satoshi_order_id', '_btcpay_satoshi_txn_id', '_satoshi_tickets_fulfilled'];
$product_meta_keys = ['_satoshi_event_id', '_satoshi_ticket_type_id'];
$item_meta_keys = ['_satoshi_event_id', '_satoshi_ticket_type_id', '_satoshi_recipients'];

$posts = $wpdb->prefix . 'posts';
$postmeta = $wpdb->prefix . 'postmeta';
$order_items = $wpdb->prefix . 'woocommerce_order_items';
$order_itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';

foreach ($order_meta_keys as $key) {
    $wpdb->query($wpdb->prepare(
        "DELETE pm FROM {$postmeta} pm
        INNER JOIN {$posts} p ON p.ID = pm.post_id
        WHERE p.post_type = 'shop_order' AND pm.meta_key = %s",
        $key
    ));
}

foreach ($product_meta_keys as $key) {
    $wpdb->query($wpdb->prepare(
        "DELETE pm FROM {$postmeta} pm
        INNER JOIN {$posts} p ON p.ID = pm.post_id
        WHERE p.post_type = 'product' AND pm.meta_key = %s",
        $key
    ));
}

foreach ($item_meta_keys as $key) {
    $wpdb->query($wpdb->prepare(
        "DELETE oim FROM {$order_itemmeta} oim WHERE oim.meta_key = %s",
        $key
    ));
}

if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wc_orders_meta'") === $wpdb->prefix . 'wc_orders_meta') {
    foreach ($order_meta_keys as $key) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = %s",
            $key
        ));
    }
}
