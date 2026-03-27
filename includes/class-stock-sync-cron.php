<?php
/**
 * Automatic stock sync from BTCPay SatoshiTickets to WooCommerce products.
 *
 * @package BTCPaySatoshiTickets
 */

declare(strict_types=1);

namespace BTCPaySatoshiTickets;

if (!defined('ABSPATH')) {
    exit;
}

final class StockSyncCron
{
    private const HOOK = 'btcpay_satoshi_sync_stock_cron';
    private const OPTION_ENABLED = 'btcpay_satoshi_stock_sync_enabled';
    private const OPTION_INTERVAL = 'btcpay_satoshi_stock_sync_interval';
    private const OPTION_LOW_STOCK_THRESHOLD = 'btcpay_satoshi_low_stock_threshold';
    private const OPTION_LAST_SYNC = 'btcpay_satoshi_last_sync_time';

    public static function init(): void
    {
        add_action(self::HOOK, [__CLASS__, 'runSync']);
        add_action('init', [__CLASS__, 'scheduleIfNeeded']);
    }

    public static function isEnabled(): bool
    {
        return get_option(self::OPTION_ENABLED, '1') === '1';
    }

    public static function getIntervalMinutes(): int
    {
        return (int) get_option(self::OPTION_INTERVAL, '15');
    }

    public static function getLowStockThreshold(): int
    {
        return (int) get_option(self::OPTION_LOW_STOCK_THRESHOLD, '0');
    }

    public static function getLastSyncTime(): string
    {
        return (string) get_option(self::OPTION_LAST_SYNC, '');
    }

    public static function schedule(): void
    {
        if (!self::isEnabled()) {
            self::unschedule();
            return;
        }
        $interval = max(5, min(1440, self::getIntervalMinutes()));
        $next = time() + ($interval * 60);
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event($next, 'btcpay_satoshi_stock_interval', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }

    public static function scheduleIfNeeded(): void
    {
        if (!function_exists('wc_get_product')) {
            return;
        }
        add_filter('cron_schedules', [__CLASS__, 'addCronSchedule']);
        if (self::isEnabled() && !wp_next_scheduled(self::HOOK)) {
            self::schedule();
        }
    }

    public static function addCronSchedule(array $schedules): array
    {
        $mins = self::getIntervalMinutes();
        $schedules['btcpay_satoshi_stock_interval'] = [
            'interval' => $mins * 60,
            'display' => sprintf(
                /* translators: %d: minutes */
                _n('Every %d minute', 'Every %d minutes', $mins, 'btcpay-satoshi-tickets'),
                $mins
            ),
        ];
        return $schedules;
    }

    /**
     * Sync stock from BTCPay for all Satoshi Ticket products.
     */
    public static function runSync(): void
    {
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            return;
        }

        $productIds = get_posts([
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => 'any',
            'meta_query' => [
                'relation' => 'AND',
                ['key' => ProductTypeTicket::META_EVENT_ID, 'compare' => 'EXISTS'],
                ['key' => ProductTypeTicket::META_TICKET_TYPE_ID, 'compare' => 'EXISTS'],
            ],
        ]);

        if (empty($productIds)) {
            return;
        }

        $byPair = [];
        foreach ($productIds as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }
            $eventId = (string) $product->get_meta(ProductTypeTicket::META_EVENT_ID, true);
            $ticketTypeId = (string) $product->get_meta(ProductTypeTicket::META_TICKET_TYPE_ID, true);
            if ($eventId === '' || $ticketTypeId === '') {
                continue;
            }
            $key = $eventId . '|' . $ticketTypeId;
            if (!isset($byPair[$key])) {
                $byPair[$key] = ['eventId' => $eventId, 'ticketTypeId' => $ticketTypeId, 'productIds' => []];
            }
            $byPair[$key]['productIds'][] = $pid;
        }

        $threshold = self::getLowStockThreshold();
        $alerted = [];

        foreach ($byPair as $p) {
            $qty = $client->getTicketTypeQuantityAvailable($p['eventId'], $p['ticketTypeId']);
            if ($qty === null) {
                continue;
            }
            foreach ($p['productIds'] as $pid) {
                $product = wc_get_product($pid);
                if ($product) {
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity($qty);
                    $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
                    $product->save();
                    if (function_exists('wc_delete_product_transients')) {
                        wc_delete_product_transients($pid);
                    }
                    // Low stock alert (once per product per 6h)
                    $pairKey = $p['eventId'] . '|' . $p['ticketTypeId'];
                    if ($threshold > 0 && $qty <= $threshold && !isset($alerted[$pairKey])) {
                        self::sendLowStockAlert($product->get_name(), $qty, $threshold);
                        $alerted[$pairKey] = true;
                    }
                }
            }
        }

        update_option(self::OPTION_LAST_SYNC, current_time('mysql'));
    }

    /**
     * Send low stock email to admin (rate-limited to once per 6 hours per product).
     */
    private static function sendLowStockAlert(string $productName, int $qty, int $threshold): void
    {
        $transientKey = 'btcpay_satoshi_low_stock_' . md5($productName);
        if (get_transient($transientKey)) {
            return;
        }
        $adminEmail = get_option('woocommerce_stock_email_recipient', get_option('admin_email'));
        $siteName   = get_bloginfo('name');
        /* translators: 1: site name, 2: product name */
        $subject = sprintf(__('[%1$s] Low ticket stock: %2$s', 'btcpay-satoshi-tickets'), $siteName, $productName);
        $message = sprintf(
            /* translators: 1: product name, 2: available qty, 3: threshold */
            __("Low ticket stock alert\n\nProduct: %1\$s\nAvailable: %2\$d\nThreshold: %3\$d\n\nLog in to manage your events: %4\$s", 'btcpay-satoshi-tickets'),
            $productName,
            $qty,
            $threshold,
            admin_url('admin.php?page=wc-settings&tab=btcpay_satoshi&section=events')
        );
        wp_mail($adminEmail, $subject, $message);
        set_transient($transientKey, '1', 6 * HOUR_IN_SECONDS);
    }
}
