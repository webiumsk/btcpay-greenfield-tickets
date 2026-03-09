<?php
/**
 * Store API extensions for Blocks checkout.
 * Adds Satoshi ticket data to cart items and ticket_items list for recipient fields.
 *
 * @package BTCPaySatoshiTickets
 */

declare(strict_types=1);

namespace BTCPaySatoshiTickets;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;

if (!defined('ABSPATH')) {
    exit;
}

final class StoreApiExtensions
{
    private const NAMESPACE = 'btcpay_satoshi_tickets';

    public static function init(): void
    {
        add_action('woocommerce_blocks_loaded', [__CLASS__, 'registerCartItemData']);
        add_action('woocommerce_blocks_loaded', [__CLASS__, 'registerCartTicketItems']);
    }

    private const CART_EXT_NAMESPACE = 'btcpay_satoshi_tickets_cart';

    /**
     * Add ticket_items to cart extensions - used by Block checkout when cart items extensions may be missing.
     */
    public static function registerCartTicketItems(): void
    {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }
        woocommerce_store_api_register_endpoint_data([
            'endpoint' => CartSchema::IDENTIFIER,
            'namespace' => self::CART_EXT_NAMESPACE,
            'data_callback' => [__CLASS__, 'getCartTicketItemsData'],
            'schema_callback' => [__CLASS__, 'getCartTicketItemsSchema'],
            'schema_type' => ARRAY_A,
        ]);
    }

    /**
     * @return array{ticket_items: array<int, array{key: string, name: string, quantity: int}>}
     */
    public static function getCartTicketItemsData(): array
    {
        $items = CheckoutHandler::getCartTicketItems();
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
        return ['ticket_items' => $payload];
    }

    /**
     * @return array<string, array{description: string, type: string|array, readonly?: bool}>
     */
    public static function getCartTicketItemsSchema(): array
    {
        return [
            'ticket_items' => [
                'description' => __('Satoshi ticket items for recipient fields.', 'btcpay-satoshi-tickets'),
                'type' => 'array',
                'readonly' => true,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'key' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                        'quantity' => ['type' => 'integer'],
                    ],
                ],
            ],
        ];
    }

    public static function registerCartItemData(): void
    {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }
        woocommerce_store_api_register_endpoint_data([
            'endpoint' => CartItemSchema::IDENTIFIER,
            'namespace' => self::NAMESPACE,
            'data_callback' => [__CLASS__, 'getCartItemData'],
            'schema_callback' => [__CLASS__, 'getCartItemSchema'],
            'schema_type' => ARRAY_A,
        ]);
    }

    /**
     * @param array{data: \WC_Product, quantity: int, key: string} $cart_item
     * @return array{is_ticket: bool, event_id?: string, ticket_type_id?: string, name?: string}
     */
    public static function getCartItemData(array $cart_item): array
    {
        $product = $cart_item['data'] ?? null;
        if (!$product || !ProductTypeTicket::isSatoshiTicketProduct($product)) {
            return ['is_ticket' => false];
        }
        $eventId = $product instanceof WC_Product_Satoshi_Ticket
            ? $product->get_event_id()
            : (string) $product->get_meta(ProductTypeTicket::META_EVENT_ID, true);
        $ticketTypeId = $product instanceof WC_Product_Satoshi_Ticket
            ? $product->get_ticket_type_id()
            : (string) $product->get_meta(ProductTypeTicket::META_TICKET_TYPE_ID, true);
        if (!$eventId || !$ticketTypeId) {
            return ['is_ticket' => false];
        }
        return [
            'is_ticket' => true,
            'event_id' => $eventId,
            'ticket_type_id' => $ticketTypeId,
            'name' => $product->get_name(),
        ];
    }

    /**
     * @return array<string, array{description: string, type: string|array, readonly?: bool}>
     */
    public static function getCartItemSchema(): array
    {
        return [
            'is_ticket' => [
                'description' => __('Whether this cart item is a Satoshi ticket.', 'btcpay-satoshi-tickets'),
                'type' => 'boolean',
                'readonly' => true,
            ],
            'event_id' => [
                'description' => __('Satoshi event ID.', 'btcpay-satoshi-tickets'),
                'type' => ['string', 'null'],
                'readonly' => true,
            ],
            'ticket_type_id' => [
                'description' => __('Satoshi ticket type ID.', 'btcpay-satoshi-tickets'),
                'type' => ['string', 'null'],
                'readonly' => true,
            ],
            'name' => [
                'description' => __('Product name.', 'btcpay-satoshi-tickets'),
                'type' => ['string', 'null'],
                'readonly' => true,
            ],
        ];
    }
}
