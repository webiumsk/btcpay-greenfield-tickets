<?php
/**
 * WooCommerce product class for Satoshi Ticket.
 *
 * @package BTCPaySatoshiTickets
 */

declare(strict_types=1);

namespace BTCPaySatoshiTickets;

if (!defined('ABSPATH')) {
    exit;
}

class WC_Product_Satoshi_Ticket extends \WC_Product_Simple
{
    protected string $product_type = ProductTypeTicket::TYPE;

    public function __construct($product = 0)
    {
        parent::__construct($product);
        $this->product_type = ProductTypeTicket::TYPE;
        $this->set_virtual(true);
        $this->set_sold_individually(false);
    }

    public function get_type(): string
    {
        return ProductTypeTicket::TYPE;
    }

    public function get_event_id(): string
    {
        return (string) $this->get_meta(ProductTypeTicket::META_EVENT_ID, true);
    }

    public function get_ticket_type_id(): string
    {
        return (string) $this->get_meta(ProductTypeTicket::META_TICKET_TYPE_ID, true);
    }

    public function is_purchasable(): bool
    {
        return parent::is_purchasable();
    }

    public function has_valid_ticket_config(): bool
    {
        return $this->get_event_id() !== '' && $this->get_ticket_type_id() !== '';
    }
}
