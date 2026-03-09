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
                }
            }
        }
    }
}
