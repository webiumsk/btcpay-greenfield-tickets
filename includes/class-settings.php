<?php
/**
 * Plugin settings for standalone BTCPay connection.
 *
 * @package BTCPaySatoshiTickets
 */

declare(strict_types=1);

namespace BTCPaySatoshiTickets;

if (!defined('ABSPATH')) {
    exit;
}

final class Settings
{
    private const TRANSIENT_WEBHOOK_RETRY = 'btcpay_satoshi_webhook_register_retry';
    private const WEBHOOK_RETRY_HOURS = 24;

    public static function init(): void
    {
        add_action('admin_init', [__CLASS__, 'registerSettings']);
        add_action('admin_init', [__CLASS__, 'maybeRegisterWebhook']);
        add_action('admin_init', [__CLASS__, 'maybeAutoRegisterWebhook']);
        add_action('admin_init', [__CLASS__, 'handleRegisterWebhookAction']);
        add_action('load-woocommerce_page_wc-settings', [__CLASS__, 'maybeRegisterWebhookAfterSave']);
    }

    public static function registerSettings(): void
    {
        register_setting('btcpay_satoshi_settings', 'btcpay_satoshi_url', [
            'type' => 'string',
            'sanitize_callback' => function ($v) {
                return is_string($v) ? rtrim(esc_url_raw($v), '/') : '';
            },
        ]);
        register_setting('btcpay_satoshi_settings', 'btcpay_satoshi_api_key', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('btcpay_satoshi_settings', 'btcpay_satoshi_store_id', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('btcpay_satoshi_settings', 'btcpay_satoshi_satflux_url', [
            'type' => 'string',
            'sanitize_callback' => function ($v) {
                return is_string($v) && $v !== '' ? rtrim(esc_url_raw($v), '/') : 'https://satflux.io';
            },
        ]);
        register_setting('btcpay_satoshi_settings', 'btcpay_satoshi_satflux_store_id', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('btcpay_satoshi_settings', 'btcpay_satoshi_satflux_checkin_store_id', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
    }

    public static function maybeRegisterWebhook(): void
    {
        if (!isset($_POST['option_page']) || $_POST['option_page'] !== 'btcpay_satoshi_settings') {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $client = new SatoshiApiClient();
        if ($client->isConfigured()) {
            WebhookHandler::registerWebhook();
        }
    }

    public static function maybeRegisterWebhookAfterSave(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if (!isset($_GET['tab']) || $_GET['tab'] !== AdminWCSettingsTab::TAB_ID) {
            return;
        }
        if (!isset($_GET['updated']) || $_GET['updated'] !== 'true') {
            return;
        }
        $client = new SatoshiApiClient();
        if ($client->isConfigured()) {
            WebhookHandler::registerWebhook();
        }
    }

    /**
     * Auto-register webhook when we have config but no webhook secret.
     * Runs once per 24h (transient) to avoid hammering BTCPay API.
     */
    public static function maybeAutoRegisterWebhook(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if (WebhookHandler::hasWebhookSecret()) {
            return;
        }
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            return;
        }
        if (get_transient(self::TRANSIENT_WEBHOOK_RETRY)) {
            return;
        }
        set_transient(self::TRANSIENT_WEBHOOK_RETRY, '1', self::WEBHOOK_RETRY_HOURS * HOUR_IN_SECONDS);
        WebhookHandler::registerWebhook();
    }

    /**
     * Handle manual "Register webhook" button click.
     */
    public static function handleRegisterWebhookAction(): void
    {
        if (!isset($_GET['btcpay_satoshi_register_webhook']) || $_GET['btcpay_satoshi_register_webhook'] !== '1') {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized.', 'btcpay-satoshi-tickets'));
        }
        check_admin_referer('btcpay_satoshi_register_webhook');
        delete_transient(self::TRANSIENT_WEBHOOK_RETRY);
        $ok = WebhookHandler::registerWebhook();
        $redirect = AdminWCSettingsTab::getTabUrl(AdminWCSettingsTab::SECTION_CONNECTION);
        $redirect = add_query_arg('webhook_registered', $ok ? '1' : '0', $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Output webhook notices and register button (Connection section).
     * Form fields come from WC Settings API via getSettings.
     */
    public static function renderConnectionNotices(): void
    {
        $hasSecret = WebhookHandler::hasWebhookSecret();
        $client = new SatoshiApiClient();
        $isConfigured = $client->isConfigured();

        if (isset($_GET['webhook_registered'])) {
            if ($_GET['webhook_registered'] === '1') {
                echo '<div class="notice notice-success is-dismissible"><p>' .
                    esc_html__('Webhook has been registered successfully.', 'btcpay-satoshi-tickets') . '</p></div>';
            } else {
                $lastErr = get_transient('btcpay_satoshi_webhook_last_error');
                delete_transient('btcpay_satoshi_webhook_last_error');
                $msg = esc_html__('Webhook registration failed.', 'btcpay-satoshi-tickets');
                if ($lastErr) {
                    $msg .= ' ' . esc_html__('BTCPay response:', 'btcpay-satoshi-tickets') . ' ' . esc_html($lastErr);
                } else {
                    $msg .= ' ' . esc_html__('Check API key: add permission btcpay.store.webhooks.canmodifywebhooks', 'btcpay-satoshi-tickets');
                }
                echo '<div class="notice notice-error is-dismissible"><p>' . $msg . '</p></div>';
            }
        }

        if ($hasSecret) {
            echo '<p class="description"><strong>' . esc_html__('Webhook:', 'btcpay-satoshi-tickets') . '</strong> ' .
                esc_html__('Registered. Orders will be marked paid when BTCPay sends InvoiceSettled.', 'btcpay-satoshi-tickets') . '</p>';
        } elseif ($isConfigured) {
            $webhookUrl = AdminWCSettingsTab::getTabUrl(AdminWCSettingsTab::SECTION_CONNECTION) . '&btcpay_satoshi_register_webhook=1';
            echo '<div class="notice notice-warning inline"><p>' .
                esc_html__('Webhook is not registered. Order status will not update after payment.', 'btcpay-satoshi-tickets') . ' ' .
                '<a href="' . esc_url(wp_nonce_url($webhookUrl, 'btcpay_satoshi_register_webhook')) . '" class="button button-secondary">' .
                esc_html__('Register webhook now', 'btcpay-satoshi-tickets') . '</a></p></div>';
        }
    }
}
