# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**BTCPay Satoshi Tickets for WooCommerce** — a WordPress plugin that integrates BTCPay Server's SatoshiTickets event ticketing system with WooCommerce, enabling Bitcoin-only ticket sales.

Requirements: WordPress 5.8+, WooCommerce 6.0+, PHP 8.0+, BTCPay Server with SatoshiTickets plugin.

## Development

This is a pure PHP WordPress plugin with no build system. There are no npm, Composer, or Makefile tasks. Assets (CSS/JS) are loaded directly without bundling.

**To develop locally:** Install WordPress + WooCommerce, symlink or copy the plugin directory into `wp-content/plugins/`, and activate via WP Admin.

**PHP standard:** All files use `declare(strict_types=1)` and require PHP 8.0+.

## Architecture

The plugin follows a single-responsibility class pattern. Each class handles one concern:

### Core Data Flow

1. Admin connects to BTCPay via Settings → enters URL/API key/Store ID (or uses Satflux.io quick-connect)
2. Admin browses events from BTCPay and creates WooCommerce products of type `satoshi_ticket`
3. Customer adds ticket products to cart, fills per-ticket recipient fields (name, email) at checkout
4. "Bitcoin (Satoshi Tickets)" payment gateway triggers `SatoshiApiClient::createPurchase()` → returns BTCPay checkout URL
5. Customer pays; BTCPay fires `InvoiceSettled` webhook → `WebhookHandler` verifies HMAC-SHA256 and marks order completed

### Key Classes

| Class | File | Responsibility |
|---|---|---|
| `SatoshiApiClient` | `class-satoshi-api-client.php` | REST client for BTCPay SatoshiTickets Greenfield API |
| `WC_Gateway_Satoshi_Tickets` | `class-gateway-satoshi-tickets.php` | WooCommerce payment gateway (ID: `btcpaygf_satoshi_tickets`) |
| `WebhookHandler` | `class-webhook-handler.php` | REST endpoint `/wp-json/btcpay-satoshi/v1/webhook`, HMAC verification |
| `CheckoutHandler` | `class-checkout-handler.php` | Per-ticket recipient fields; REST endpoint `/wp-json/btcpay-satoshi-tickets/v1/cart-tickets` |
| `AdminWCSettingsTab` | `class-admin-wc-settings-tab.php` | Settings UI (connection + events sections) |
| `AdminEvents` | `class-admin-events.php` | Browse/manage BTCPay events, create WooCommerce products |
| `ProductTypeTicket` | `class-product-type-ticket.php` | Custom product type `satoshi_ticket` with event/ticket-type metadata |
| `StockSyncCron` | `class-stock-sync-cron.php` | WP cron job syncing BTCPay ticket quantities → WooCommerce stock |
| `Settings` | `class-settings.php` | WP Settings API registration, sanitization, auto-webhook registration |
| `StoreApiExtensions` | `class-store-api-extensions.php` | Block-based checkout integration |

### Settings / Options

All plugin settings are stored as WP options:
- `btcpay_satoshi_url`, `btcpay_satoshi_api_key`, `btcpay_satoshi_store_id` — connection
- `btcpay_satoshi_webhook_secret`, `btcpay_satoshi_webhook_id` — webhook
- `btcpay_satoshi_stock_sync_enabled`, `btcpay_satoshi_stock_sync_interval` — cron (default 15 min)
- `woocommerce_btcpaygf_satoshi_tickets_settings` — gateway options (title, description, discount)

### Metadata

- **Product meta:** `_satoshi_event_id`, `_satoshi_ticket_type_id`
- **Order meta:** `BTCPay_id`, `_btcpay_satoshi_order_id`, `_btcpay_satoshi_txn_id`, `_satoshi_tickets_fulfilled`
- **Order item meta:** `_satoshi_event_id`, `_satoshi_ticket_type_id`, `_satoshi_recipients` (JSON array)

### Compatibility

- WooCommerce HPOS (Custom Order Tables) — declared via `before_woocommerce_init`
- WooCommerce block-based checkout — handled by `StoreApiExtensions`, `GatewaySatoshiTicketsBlocks`, `CheckoutRecipientsIntegration`
- Falls back to BTCPay Greenfield plugin settings if that plugin is installed but its own settings are not set

### Satflux.io Integration

OAuth-style quick-connect that auto-fills `btcpay_url`, `api_key`, `store_id`, and `satflux_store_id`. Check-in links for event attendees use the Satflux store ID.
