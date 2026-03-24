<?php
/**
 * WooCommerce Settings tab: Satoshi Tickets.
 * Merges Connection and Events into one tab with sections (like BTCPay GF plugin).
 *
 * @package BTCPaySatoshiTickets
 */

declare(strict_types=1);

namespace BTCPaySatoshiTickets;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminWCSettingsTab
{
    public const TAB_ID = 'btcpay_satoshi';
    public const SECTION_CONNECTION = 'connection';
    public const SECTION_EVENTS = 'events';

    private const SATFLUX_CONNECT_DEFAULT = 'https://satflux.io';
    private const SATFLUX_CONNECT_PATH = '/woocommerce/satoshi-tickets/connect';

    private const BTCPAY_PERMISSIONS = [
        'btcpay.store.canviewinvoices',
        'btcpay.store.cancreateinvoice',
        'btcpay.store.canmodifyinvoices',
        'btcpay.store.webhooks.canmodifywebhooks',
        'btcpay.store.canviewsatoshitickets',
        'btcpay.store.canmanagesatoshitickets',
    ];

    public static function init(): void
    {
        add_filter('woocommerce_settings_tabs_array', [__CLASS__, 'addTab'], 50);
        add_filter('woocommerce_get_sections_' . self::TAB_ID, [__CLASS__, 'getSections']);
        add_filter('woocommerce_get_settings_' . self::TAB_ID, [__CLASS__, 'getSettings'], 10, 2);
        add_action('woocommerce_settings_' . self::TAB_ID, [__CLASS__, 'outputContent']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueScripts']);
        add_action('admin_init', [__CLASS__, 'handleSatfluxCallback']);
        add_action('admin_init', [__CLASS__, 'handleBtcpayApiKeyCallback']);
        add_action('admin_init', [__CLASS__, 'maybeSaveFromMainform'], 20);
        add_action('wp_ajax_btcpay_satoshi_test_connection', [__CLASS__, 'ajaxTestConnection']);
        add_action('wp_ajax_btcpay_satoshi_test_webhook', [__CLASS__, 'ajaxTestWebhook']);
        add_action('wp_ajax_btcpay_satoshi_get_stores', [__CLASS__, 'ajaxGetStores']);
        add_action('wp_ajax_btcpay_satoshi_wizard_save', [__CLASS__, 'ajaxWizardSave']);
    }

    public static function maybeSaveFromMainform(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        if (!isset($_GET['page']) || $_GET['page'] !== 'wc-settings') {
            return;
        }
        if (!isset($_GET['tab']) || $_GET['tab'] !== self::TAB_ID) {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if (!isset($_POST['btcpay_satoshi_url']) || empty($_POST['_wpnonce'])) {
            return;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'woocommerce-settings')) {
            return;
        }
        $url = isset($_POST['btcpay_satoshi_url']) ? sanitize_text_field(wp_unslash($_POST['btcpay_satoshi_url'])) : '';
        $key = isset($_POST['btcpay_satoshi_api_key']) ? sanitize_text_field(wp_unslash($_POST['btcpay_satoshi_api_key'])) : '';
        $storeId = isset($_POST['btcpay_satoshi_store_id']) ? sanitize_text_field(wp_unslash($_POST['btcpay_satoshi_store_id'])) : '';
        $satfluxUrl = isset($_POST['btcpay_satoshi_satflux_url']) ? sanitize_text_field(wp_unslash($_POST['btcpay_satoshi_satflux_url'])) : '';
        $satfluxStoreId = isset($_POST['btcpay_satoshi_satflux_store_id']) ? sanitize_text_field(wp_unslash($_POST['btcpay_satoshi_satflux_store_id'])) : '';
        $satfluxCheckinStoreId = isset($_POST['btcpay_satoshi_satflux_checkin_store_id']) ? sanitize_text_field(wp_unslash($_POST['btcpay_satoshi_satflux_checkin_store_id'])) : '';
        $showSatflux = !empty($_POST['btcpay_satoshi_show_satflux']);
        $deleteOnUninstall = !empty($_POST['btcpay_satoshi_delete_data_on_uninstall']);
        $stockSyncEnabled = !empty($_POST['btcpay_satoshi_stock_sync_enabled']);
        $stockSyncInterval = isset($_POST['btcpay_satoshi_stock_sync_interval']) ? max(5, min(1440, (int) $_POST['btcpay_satoshi_stock_sync_interval'])) : 15;
        $lowStockThreshold = isset($_POST['btcpay_satoshi_low_stock_threshold']) ? max(0, (int) $_POST['btcpay_satoshi_low_stock_threshold']) : 0;
        update_option('btcpay_satoshi_url', $url !== '' ? rtrim(esc_url_raw($url), '/') : '');
        update_option('btcpay_satoshi_api_key', $key);
        update_option('btcpay_satoshi_store_id', $storeId);
        update_option('btcpay_satoshi_satflux_url', $satfluxUrl !== '' ? rtrim(esc_url_raw($satfluxUrl), '/') : self::SATFLUX_CONNECT_DEFAULT);
        update_option('btcpay_satoshi_satflux_store_id', $satfluxStoreId);
        update_option('btcpay_satoshi_satflux_checkin_store_id', $satfluxCheckinStoreId);
        update_option('btcpay_satoshi_show_satflux', $showSatflux ? '1' : '0');
        update_option('btcpay_satoshi_delete_data_on_uninstall', $deleteOnUninstall ? '1' : '0');
        update_option('btcpay_satoshi_stock_sync_enabled', $stockSyncEnabled ? '1' : '0');
        update_option('btcpay_satoshi_stock_sync_interval', $stockSyncInterval);
        update_option('btcpay_satoshi_low_stock_threshold', $lowStockThreshold);
        if (class_exists(StockSyncCron::class)) {
            StockSyncCron::unschedule();
            StockSyncCron::scheduleIfNeeded();
        }
        $client = new SatoshiApiClient();
        if ($client->isConfigured()) {
            WebhookHandler::registerWebhook();
        }
    }

    public static function handleSatfluxCallback(): void
    {
        if (!isset($_GET['satflux_return']) || $_GET['satflux_return'] !== '1') {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $url = isset($_GET['btcpay_url']) ? sanitize_text_field(wp_unslash($_GET['btcpay_url'])) : '';
        $key = isset($_GET['api_key']) ? sanitize_text_field(wp_unslash($_GET['api_key'])) : '';
        $storeId = isset($_GET['store_id']) ? sanitize_text_field(wp_unslash($_GET['store_id'])) : '';
        $satfluxCheckinStoreId = isset($_GET['satflux_store_id']) ? sanitize_text_field(wp_unslash($_GET['satflux_store_id'])) : '';
        if ($url === '' || $key === '' || $storeId === '') {
            return;
        }
        update_option('btcpay_satoshi_url', rtrim(esc_url_raw($url), '/'));
        update_option('btcpay_satoshi_api_key', $key);
        update_option('btcpay_satoshi_store_id', $storeId);
        if ($satfluxCheckinStoreId !== '') {
            update_option('btcpay_satoshi_satflux_checkin_store_id', $satfluxCheckinStoreId);
        }
        update_option('btcpay_satoshi_satflux_store_id', $storeId);
        update_option('btcpay_satoshi_connected_via_satflux', '1');
        update_option('btcpay_satoshi_show_satflux', '1');
        $redirect = self::getTabUrl(self::SECTION_CONNECTION);
        $redirect = remove_query_arg(['satflux_return', 'btcpay_url', 'api_key', 'store_id', 'satflux_store_id'], $redirect);
        $redirect = add_query_arg('satflux_connected', '1', $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    public static function addTab(array $tabs): array
    {
        $tabs[self::TAB_ID] = __('Satoshi Tickets', 'btcpay-satoshi-tickets');
        return $tabs;
    }

    public static function getSections(array $sections): array
    {
        return $sections;
    }

    public static function getSettings(array $settings, string $currentSection): array
    {
        return [];
    }

    public static function outputContent(): void
    {
        $currentSection = self::getCurrentSection();
        echo '<div class="btcpay-satoshi-settings-content">';
        echo '<nav class="nav-tab-wrapper woo-nav-tab-wrapper btcpay-satoshi-subnav" style="margin-bottom:0;">';
        echo '<a href="' . esc_url(self::getTabUrl(self::SECTION_CONNECTION)) . '" class="nav-tab ' . ($currentSection === self::SECTION_CONNECTION ? 'nav-tab-active' : '') . '">' . esc_html__('Connection', 'btcpay-satoshi-tickets') . '</a>';
        echo '<a href="' . esc_url(self::getTabUrl(self::SECTION_EVENTS)) . '" class="nav-tab ' . ($currentSection === self::SECTION_EVENTS ? 'nav-tab-active' : '') . '">' . esc_html__('Events & Tickets', 'btcpay-satoshi-tickets') . '</a>';
        echo '</nav>';
        echo '<div class="btcpay-satoshi-tab-panel">';
        if ($currentSection === self::SECTION_EVENTS) {
            AdminEvents::renderPage();
        } else {
            self::renderConnectionSection();
        }
        echo '</div></div>';
    }

    private static function renderConnectionSection(): void
    {
        self::renderConnectionStatusBar();
        Settings::renderConnectionNotices();

        if (isset($_GET['btcpay_connected']) && $_GET['btcpay_connected'] === '1') {
            echo '<div class="notice notice-success is-dismissible" style="margin:0 0 16px;"><p>' .
                esc_html__('Connected to BTCPay Server successfully. Webhook has been registered.', 'btcpay-satoshi-tickets') . '</p></div>';
        }

        $pickStore     = isset($_GET['btcpay_pick_store']) && $_GET['btcpay_pick_store'] === '1';
        $ownUrl        = get_option('btcpay_satoshi_url', '');
        $ownKey        = get_option('btcpay_satoshi_api_key', '');
        $ownStore      = get_option('btcpay_satoshi_store_id', '');
        $cfg           = SatoshiApiClient::getConfig();
        $hasOwnConfig  = $ownUrl !== '' && $ownKey !== '' && $ownStore !== '';
        $usingFallback = !$hasOwnConfig && !empty($cfg);
        $hasGf         = defined('BTCPAYSERVER_PLUGIN_FILE_PATH');
        $satfluxUrl    = get_option('btcpay_satoshi_satflux_url', self::SATFLUX_CONNECT_DEFAULT);
        $returnUrl     = self::getTabUrl(self::SECTION_CONNECTION);
        $satfluxConnectUrl = rtrim($satfluxUrl, '/') . self::SATFLUX_CONNECT_PATH
            . '?return_url=' . rawurlencode($returnUrl) . '&return_satflux_store_id=1';
        $wizardCallbackUrl = add_query_arg('btcpay_satoshi_connect', '1', $returnUrl);
        ?>
        <?php if (isset($_GET['satflux_connected']) && $_GET['satflux_connected'] === '1') : ?>
        <div class="notice notice-success is-dismissible" style="margin:0 0 16px;"><p><?php esc_html_e('Connected to Satflux.io successfully.', 'btcpay-satoshi-tickets'); ?></p></div>
        <?php endif; ?>

        <!-- Always submit show_satflux=1 so the status badge remains consistent -->
        <input type="hidden" name="btcpay_satoshi_show_satflux" value="1" />

        <h3 class="title" style="margin-top:0;"><?php esc_html_e('Quick Setup', 'btcpay-satoshi-tickets'); ?></h3>
        <div class="btcpay-satoshi-connect-cards">

            <!-- Card A: Satflux.io -->
            <div class="btcpay-satoshi-connect-card">
                <h4><?php esc_html_e('Satflux.io', 'btcpay-satoshi-tickets'); ?></h4>
                <p class="description"><?php esc_html_e('One-click setup for Satflux.io users. Automatically configures your BTCPay connection and check-in links.', 'btcpay-satoshi-tickets'); ?></p>
                <p>
                    <a href="<?php echo esc_url($satfluxConnectUrl); ?>"
                       class="button button-primary"
                       id="btcpay-satoshi-connect-satflux"
                       data-return-url="<?php echo esc_attr($returnUrl); ?>"
                       data-connect-path="<?php echo esc_attr(self::SATFLUX_CONNECT_PATH); ?>">
                        <?php esc_html_e('Connect to Satflux.io →', 'btcpay-satoshi-tickets'); ?>
                    </a>
                </p>
                <details style="margin-top:10px;">
                    <summary style="cursor:pointer;font-size:12px;color:#646970;"><?php esc_html_e('Satflux.io settings', 'btcpay-satoshi-tickets'); ?></summary>
                    <div style="margin-top:10px;">
                        <p>
                            <label for="btcpay_satoshi_satflux_url" style="display:block;margin-bottom:3px;"><?php esc_html_e('Satflux.io URL', 'btcpay-satoshi-tickets'); ?></label>
                            <input type="url" id="btcpay_satoshi_satflux_url" name="btcpay_satoshi_satflux_url"
                                   value="<?php echo esc_attr($satfluxUrl); ?>"
                                   class="regular-text" placeholder="https://satflux.io" />
                        </p>
                        <p>
                            <label for="btcpay_satoshi_satflux_store_id" style="display:block;margin-bottom:3px;"><?php esc_html_e('Satflux Store ID (check-in links)', 'btcpay-satoshi-tickets'); ?></label>
                            <input type="text" id="btcpay_satoshi_satflux_store_id" name="btcpay_satoshi_satflux_store_id"
                                   value="<?php echo esc_attr(get_option('btcpay_satoshi_satflux_store_id', '')); ?>"
                                   class="regular-text" />
                        </p>
                        <p>
                            <label for="btcpay_satoshi_satflux_checkin_store_id" style="display:block;margin-bottom:3px;"><?php esc_html_e('Satflux Check-in Store ID', 'btcpay-satoshi-tickets'); ?></label>
                            <input type="text" id="btcpay_satoshi_satflux_checkin_store_id" name="btcpay_satoshi_satflux_checkin_store_id"
                                   value="<?php echo esc_attr(get_option('btcpay_satoshi_satflux_checkin_store_id', '')); ?>"
                                   class="regular-text" />
                            <br><span class="description" style="font-size:11px;"><?php esc_html_e('If different from Satflux Store ID above.', 'btcpay-satoshi-tickets'); ?></span>
                        </p>
                    </div>
                </details>
            </div>

            <!-- Card B: Direct BTCPay Server -->
            <div class="btcpay-satoshi-connect-card">
                <h4><?php esc_html_e('Direct BTCPay Server', 'btcpay-satoshi-tickets'); ?></h4>
                <p class="description"><?php esc_html_e('Connect to any self-hosted BTCPay Server instance. No third-party service required.', 'btcpay-satoshi-tickets'); ?></p>
                <?php if ($pickStore) : ?>
                    <p><strong><?php esc_html_e('Step 2: Select your store', 'btcpay-satoshi-tickets'); ?></strong></p>
                    <p class="description"><?php esc_html_e('API key authorized. Choose which store to use:', 'btcpay-satoshi-tickets'); ?></p>
                    <p id="btcpay-satoshi-stores-loading"><?php esc_html_e('Loading stores…', 'btcpay-satoshi-tickets'); ?></p>
                    <div id="btcpay-satoshi-stores-list" style="display:none;margin-top:8px;">
                        <select id="btcpay-satoshi-store-select" style="min-width:180px;margin-right:8px;">
                            <option value=""><?php esc_html_e('— Select a store —', 'btcpay-satoshi-tickets'); ?></option>
                        </select>
                        <button type="button" class="button button-primary" id="btcpay-satoshi-wizard-save">
                            <?php esc_html_e('Connect', 'btcpay-satoshi-tickets'); ?>
                        </button>
                        <span id="btcpay-satoshi-wizard-save-result" style="margin-left:10px;vertical-align:middle;"></span>
                    </div>
                    <p id="btcpay-satoshi-stores-error" style="display:none;color:#a00;"></p>
                <?php else : ?>
                    <p>
                        <label for="btcpay-satoshi-wizard-url" style="display:block;margin-bottom:3px;font-weight:600;"><?php esc_html_e('BTCPay Server URL', 'btcpay-satoshi-tickets'); ?></label>
                        <input type="url" id="btcpay-satoshi-wizard-url"
                               value="<?php echo esc_attr($ownUrl); ?>"
                               class="regular-text" placeholder="https://btcpay.example.com" />
                    </p>
                    <p>
                        <button type="button" class="button button-primary" id="btcpay-satoshi-wizard-authorize"
                                data-callback-url="<?php echo esc_attr($wizardCallbackUrl); ?>">
                            <?php esc_html_e('Authorize on BTCPay →', 'btcpay-satoshi-tickets'); ?>
                        </button>
                        <span id="btcpay-satoshi-wizard-error" style="display:none;color:#a00;margin-left:8px;font-size:12px;"></span>
                    </p>
                    <p class="description" style="font-size:11px;margin-top:0;">
                        <?php esc_html_e('You will be redirected to BTCPay to authorize the connection. Required permissions are requested automatically.', 'btcpay-satoshi-tickets'); ?>
                    </p>
                <?php endif; ?>
            </div>

        </div><!-- .btcpay-satoshi-connect-cards -->

        <!-- Advanced: Manual configuration -->
        <details class="btcpay-satoshi-manual-config" style="margin:20px 0 0;">
            <summary><?php esc_html_e('Advanced: Manual configuration', 'btcpay-satoshi-tickets'); ?></summary>
            <div style="margin-top:16px;">
            <?php if ($usingFallback) : ?>
                <div class="notice notice-info inline" style="margin:0 0 1em 0;">
                    <p><?php
                    if ($hasGf) {
                        printf(
                            esc_html__('Connection uses %s. Fill in all fields below to use your own settings.', 'btcpay-satoshi-tickets'),
                            '<strong>' . esc_html__('BTCPay Greenfield plugin settings', 'btcpay-satoshi-tickets') . '</strong>'
                        );
                    } else {
                        esc_html_e('Incomplete or missing configuration — some fields below are empty. Fill in all three fields (URL, API Key, Store ID) and save to configure this plugin directly.', 'btcpay-satoshi-tickets');
                    }
                    ?></p>
                </div>
            <?php endif; ?>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="btcpay_satoshi_url"><?php esc_html_e('BTCPay Server URL', 'btcpay-satoshi-tickets'); ?></label></th>
                        <td>
                            <input type="url" id="btcpay_satoshi_url" name="btcpay_satoshi_url"
                                   value="<?php echo esc_attr($ownUrl); ?>"
                                   class="regular-text"
                                   placeholder="<?php echo esc_attr($usingFallback ? ($cfg['url'] ?? '') : 'https://btcpay.example.com'); ?>" />
                            <?php if ($hasGf) : ?>
                                <p class="description"><?php esc_html_e('Override. Leave empty to use BTCPay Greenfield plugin settings.', 'btcpay-satoshi-tickets'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="btcpay_satoshi_api_key"><?php esc_html_e('API Key', 'btcpay-satoshi-tickets'); ?></label></th>
                        <td>
                            <input type="password" id="btcpay_satoshi_api_key" name="btcpay_satoshi_api_key"
                                   value="<?php echo esc_attr($ownKey); ?>"
                                   class="regular-text" autocomplete="off"
                                   placeholder="<?php echo $usingFallback ? esc_attr__('(from BTCPay Greenfield)', 'btcpay-satoshi-tickets') : ''; ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="btcpay_satoshi_store_id"><?php esc_html_e('Store ID', 'btcpay-satoshi-tickets'); ?></label></th>
                        <td>
                            <input type="text" id="btcpay_satoshi_store_id" name="btcpay_satoshi_store_id"
                                   value="<?php echo esc_attr($ownStore); ?>"
                                   class="regular-text"
                                   placeholder="<?php echo esc_attr($usingFallback && !empty($cfg['store_id']) ? $cfg['store_id'] : __('Store GUID', 'btcpay-satoshi-tickets')); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"></th>
                        <td>
                            <button type="button" class="button" id="btcpay-satoshi-test-connection"><?php esc_html_e('Test connection', 'btcpay-satoshi-tickets'); ?></button>
                            <span id="btcpay-satoshi-test-connection-result" style="margin-left:10px;vertical-align:middle;"></span>
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>
        </details>
        <h3 class="title" style="margin-top:24px;"><?php esc_html_e('Webhook', 'btcpay-satoshi-tickets'); ?></h3>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Webhook URL', 'btcpay-satoshi-tickets'); ?></th>
                    <td>
                        <code><?php echo esc_html(WebhookHandler::getWebhookUrl()); ?></code>
                        <p class="description"><?php esc_html_e('This URL is automatically registered with BTCPay when you save the connection settings.', 'btcpay-satoshi-tickets'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"></th>
                    <td>
                        <button type="button" class="button" id="btcpay-satoshi-test-webhook"><?php esc_html_e('Test webhook endpoint', 'btcpay-satoshi-tickets'); ?></button>
                        <span id="btcpay-satoshi-test-webhook-result" style="margin-left:10px;vertical-align:middle;"></span>
                        <p class="description"><?php esc_html_e('Verifies that your webhook URL is publicly reachable by BTCPay Server.', 'btcpay-satoshi-tickets'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>
        <h3 class="title" style="margin-top:24px;"><?php esc_html_e('Data', 'btcpay-satoshi-tickets'); ?></h3>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Automatic stock sync', 'btcpay-satoshi-tickets'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="btcpay_satoshi_stock_sync_enabled" name="btcpay_satoshi_stock_sync_enabled" value="1" <?php checked(get_option('btcpay_satoshi_stock_sync_enabled', '1'), '1'); ?> />
                            <?php esc_html_e('Sync ticket quantities from BTCPay to WooCommerce products periodically', 'btcpay-satoshi-tickets'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Keeps limited ticket stock in sync. Useful when tickets are sold elsewhere or fulfilled manually.', 'btcpay-satoshi-tickets'); ?>
                        </p>
                        <p style="margin-top:8px;">
                            <label for="btcpay_satoshi_stock_sync_interval"><?php esc_html_e('Interval (minutes):', 'btcpay-satoshi-tickets'); ?></label>
                            <input type="number" id="btcpay_satoshi_stock_sync_interval" name="btcpay_satoshi_stock_sync_interval"
                                   value="<?php echo esc_attr((string) get_option('btcpay_satoshi_stock_sync_interval', '15')); ?>"
                                   min="5" max="1440" step="1" style="width:70px;" />
                        </p>
                        <?php
                        $lastSync = class_exists(StockSyncCron::class) ? StockSyncCron::getLastSyncTime() : '';
                        if ($lastSync !== '') :
                        ?>
                        <p class="description">
                            <?php echo esc_html(sprintf(__('Last synced: %s', 'btcpay-satoshi-tickets'), $lastSync)); ?>
                        </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Low stock alert', 'btcpay-satoshi-tickets'); ?></th>
                    <td>
                        <label for="btcpay_satoshi_low_stock_threshold">
                            <?php esc_html_e('Send email alert when available tickets drop to or below:', 'btcpay-satoshi-tickets'); ?>
                        </label>
                        <p style="margin-top:6px;">
                            <input type="number" id="btcpay_satoshi_low_stock_threshold" name="btcpay_satoshi_low_stock_threshold"
                                   value="<?php echo esc_attr((string) get_option('btcpay_satoshi_low_stock_threshold', '0')); ?>"
                                   min="0" step="1" style="width:70px;" />
                            <?php esc_html_e('tickets', 'btcpay-satoshi-tickets'); ?>
                        </p>
                        <p class="description"><?php esc_html_e('Set to 0 to disable. Alert is sent to the WooCommerce stock notification email at most once per 6 hours per product.', 'btcpay-satoshi-tickets'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('On uninstall', 'btcpay-satoshi-tickets'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="btcpay_satoshi_delete_data_on_uninstall" name="btcpay_satoshi_delete_data_on_uninstall" value="1" <?php checked(get_option('btcpay_satoshi_delete_data_on_uninstall', '0'), '1'); ?> />
                            <?php esc_html_e('Delete all plugin data when the plugin is removed', 'btcpay-satoshi-tickets'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Removes options, webhook settings, order meta, product meta and transients. Products and orders remain, but plugin-specific data is removed.', 'btcpay-satoshi-tickets'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php submit_button(__('Save changes', 'btcpay-satoshi-tickets')); ?>
        <?php
    }

    private static function renderConnectionStatusBar(): void
    {
        $client       = new SatoshiApiClient();
        $isConfigured = $client->isConfigured();
        $hasWebhook   = WebhookHandler::hasWebhookSecret();
        $viaSatflux   = self::isConnectedViaSatflux();
        $cfg          = SatoshiApiClient::getConfig();
        $activeUrl    = $isConfigured ? preg_replace('#^https?://#', '', rtrim($cfg['url'] ?? '', '/')) : '';
        $hasOwnUrl    = get_option('btcpay_satoshi_url', '') !== '';
        $usingGfOpts  = $isConfigured && !$hasOwnUrl && !defined('BTCPAYSERVER_PLUGIN_FILE_PATH');
        ?>
        <div class="btcpay-satoshi-status-bar" style="margin-bottom:16px;">
            <?php if ($isConfigured) : ?>
                <span class="btcpay-satoshi-status btcpay-satoshi-status-connected">
                    <?php esc_html_e('BTCPay: Connected', 'btcpay-satoshi-tickets'); ?>
                    <?php if ($activeUrl) : ?>
                        &nbsp;<span style="font-weight:400;opacity:.75;">(<?php echo esc_html($activeUrl); ?>)</span>
                    <?php endif; ?>
                </span>
                <?php if ($hasWebhook) : ?>
                    <span class="btcpay-satoshi-status btcpay-satoshi-status-webhook-ok"><?php esc_html_e('Webhook: Registered', 'btcpay-satoshi-tickets'); ?></span>
                <?php else : ?>
                    <span class="btcpay-satoshi-status btcpay-satoshi-status-webhook-no"><?php esc_html_e('Webhook: Not registered', 'btcpay-satoshi-tickets'); ?></span>
                <?php endif; ?>
                <?php if ($viaSatflux) : ?>
                    <span class="btcpay-satoshi-status btcpay-satoshi-status-satflux"><?php esc_html_e('via Satflux.io', 'btcpay-satoshi-tickets'); ?></span>
                <?php elseif ($usingGfOpts) : ?>
                    <span class="btcpay-satoshi-status btcpay-satoshi-status-webhook-no">
                        <?php esc_html_e('Using leftover BTCPay Greenfield settings — save your own below to take over', 'btcpay-satoshi-tickets'); ?>
                    </span>
                <?php endif; ?>
            <?php else : ?>
                <span class="btcpay-satoshi-status btcpay-satoshi-status-disconnected"><?php esc_html_e('BTCPay: Not configured', 'btcpay-satoshi-tickets'); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }


    public static function getCurrentSection(): string
    {
        $s = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';
        return $s === '' ? self::SECTION_CONNECTION : $s;
    }

    public static function isConnectedViaSatflux(): bool
    {
        return get_option('btcpay_satoshi_connected_via_satflux', '0') === '1';
    }

    public static function getSatfluxStoreIdForCheckin(): string
    {
        $checkinId = (string) get_option('btcpay_satoshi_satflux_checkin_store_id', '');
        if ($checkinId !== '') {
            return $checkinId;
        }
        return (string) get_option('btcpay_satoshi_satflux_store_id', '');
    }

    public static function getSatfluxCheckinUrl(string $eventId): string
    {
        $storeId = self::getSatfluxStoreIdForCheckin();
        if ($storeId === '' || $eventId === '') {
            return '';
        }
        $base = rtrim(get_option('btcpay_satoshi_satflux_url', self::SATFLUX_CONNECT_DEFAULT), '/');
        return $base . '/stores/' . rawurlencode($storeId) . '/ticket-check-in/' . rawurlencode($eventId);
    }

    public static function getTabUrl(string $section = ''): string
    {
        $url = admin_url('admin.php?page=wc-settings&tab=' . self::TAB_ID);
        if ($section !== '') {
            $url = add_query_arg('section', $section, $url);
        }
        return $url;
    }

    public static function ajaxTestConnection(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $client = new SatoshiApiClient();
        if (!$client->isConfigured()) {
            wp_send_json_error(['message' => __('Not configured. Fill in BTCPay URL, API Key and Store ID first.', 'btcpay-satoshi-tickets')]);
        }
        $result = $client->getEvents();
        if ($result['success']) {
            $count = count($result['data'] ?? []);
            wp_send_json_success(['message' => sprintf(
                /* translators: %d: event count */
                _n('Connection successful. %d event found.', 'Connection successful. %d events found.', $count, 'btcpay-satoshi-tickets'),
                $count
            )]);
        } else {
            wp_send_json_error(['message' => $result['message'] ?? __('Connection failed.', 'btcpay-satoshi-tickets')]);
        }
    }

    public static function ajaxTestWebhook(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $webhookUrl = WebhookHandler::getWebhookUrl();
        $response = wp_remote_post($webhookUrl, [
            'body'      => '{}',
            'headers'   => ['Content-Type' => 'application/json'],
            'timeout'   => 10,
            'sslverify' => true,
        ]);
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => sprintf(
                /* translators: %s: error message */
                __('Webhook URL not reachable: %s', 'btcpay-satoshi-tickets'),
                $response->get_error_message()
            )]);
        }
        $code = wp_remote_retrieve_response_code($response);
        // 401 = endpoint exists and signature validation is active (expected for unsigned request)
        // 400 = endpoint exists but bad payload
        if ($code === 401 || $code === 400) {
            wp_send_json_success(['message' => sprintf(
                /* translators: 1: webhook URL, 2: HTTP code */
                __('Endpoint reachable at %1$s (HTTP %2$d — signature validation active)', 'btcpay-satoshi-tickets'),
                $webhookUrl,
                $code
            )]);
        } elseif ($code >= 200 && $code < 300) {
            wp_send_json_success(['message' => sprintf(
                /* translators: %d: HTTP status code */
                __('Endpoint responded (HTTP %d).', 'btcpay-satoshi-tickets'),
                $code
            )]);
        } else {
            wp_send_json_error(['message' => sprintf(
                /* translators: 1: HTTP code, 2: webhook URL */
                __('Unexpected response (HTTP %1$d) from %2$s. Check your site URL and SSL configuration.', 'btcpay-satoshi-tickets'),
                $code,
                $webhookUrl
            )]);
        }
    }

    /**
     * Handle BTCPay API key authorization callback.
     * BTCPay redirects back with ?btcpay_satoshi_connect=1&apiKey=...&storeId=...&btcpay_url=...
     */
    public static function handleBtcpayApiKeyCallback(): void
    {
        if (!isset($_GET['btcpay_satoshi_connect']) || $_GET['btcpay_satoshi_connect'] !== '1') {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $apiKey    = isset($_GET['apiKey'])     ? sanitize_text_field(wp_unslash($_GET['apiKey']))     : '';
        $storeId   = isset($_GET['storeId'])    ? sanitize_text_field(wp_unslash($_GET['storeId']))    : '';
        $btcpayUrl = isset($_GET['btcpay_url']) ? sanitize_text_field(wp_unslash($_GET['btcpay_url'])) : '';

        if ($apiKey === '' || $btcpayUrl === '') {
            return;
        }
        update_option('btcpay_satoshi_url', rtrim(esc_url_raw($btcpayUrl), '/'));
        update_option('btcpay_satoshi_api_key', $apiKey);
        update_option('btcpay_satoshi_connected_via_satflux', '0');

        $base = self::getTabUrl(self::SECTION_CONNECTION);
        if ($storeId !== '') {
            update_option('btcpay_satoshi_store_id', $storeId);
            WebhookHandler::registerWebhook();
            wp_safe_redirect(add_query_arg('btcpay_connected', '1', $base));
            exit;
        }
        // No storeId — show store picker (url+key already saved)
        wp_safe_redirect(add_query_arg('btcpay_pick_store', '1', $base));
        exit;
    }

    /**
     * AJAX: return list of stores for the currently-saved BTCPay connection.
     */
    public static function ajaxGetStores(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }
        $url = rtrim((string) get_option('btcpay_satoshi_url', ''), '/');
        $key = (string) get_option('btcpay_satoshi_api_key', '');
        if ($url === '' || $key === '') {
            wp_send_json_error(['message' => __('BTCPay URL or API key not set.', 'btcpay-satoshi-tickets')]);
        }
        $response = wp_remote_get($url . '/api/v1/stores', [
            'headers'  => ['Authorization' => 'token ' . $key],
            'timeout'  => 15,
            'sslverify' => true,
        ]);
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code === 200 && is_array($body)) {
            $stores = array_values(array_filter(array_map(
                fn($s) => ['id' => (string)($s['id'] ?? ''), 'name' => (string)($s['name'] ?? '')],
                $body
            ), fn($s) => $s['id'] !== ''));
            wp_send_json_success(['stores' => $stores]);
        } else {
            wp_send_json_error(['message' => sprintf(
                /* translators: %d: HTTP status code */
                __('Could not fetch stores (HTTP %d). Check API key permissions.', 'btcpay-satoshi-tickets'),
                $code
            )]);
        }
    }

    /**
     * AJAX: save store ID chosen in wizard and register webhook.
     */
    public static function ajaxWizardSave(): void
    {
        if (!check_ajax_referer('btcpay_satoshi_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }
        $storeId = isset($_POST['storeId']) ? sanitize_text_field(wp_unslash($_POST['storeId'])) : '';
        if ($storeId === '') {
            wp_send_json_error(['message' => __('Please select a store.', 'btcpay-satoshi-tickets')]);
        }
        update_option('btcpay_satoshi_store_id', $storeId);
        $client = new SatoshiApiClient();
        if ($client->isConfigured()) {
            WebhookHandler::registerWebhook();
        }
        wp_send_json_success(['message' => __('Connected successfully! Webhook registered.', 'btcpay-satoshi-tickets')]);
    }

    public static function enqueueScripts(string $hook): void
    {
        if ($hook !== 'woocommerce_page_wc-settings') {
            return;
        }
        if (!isset($_GET['tab']) || $_GET['tab'] !== self::TAB_ID) {
            return;
        }
        wp_enqueue_style(
            'btcpay-satoshi-admin',
            BTCPAY_SATOSHI_TICKETS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            BTCPAY_SATOSHI_TICKETS_VERSION
        );

        if (self::getCurrentSection() !== self::SECTION_EVENTS) {
            wp_enqueue_script(
                'btcpay-satoshi-settings',
                BTCPAY_SATOSHI_TICKETS_PLUGIN_URL . 'assets/js/admin-settings.js',
                ['jquery'],
                BTCPAY_SATOSHI_TICKETS_VERSION,
                true
            );
            wp_localize_script('btcpay-satoshi-settings', 'btcpaySatoshiSettings', [
                'ajaxUrl'            => admin_url('admin-ajax.php'),
                'nonce'              => wp_create_nonce('btcpay_satoshi_admin'),
                'pickStore'          => isset($_GET['btcpay_pick_store']) && $_GET['btcpay_pick_store'] === '1',
                'connectedUrl'       => add_query_arg('btcpay_connected', '1', self::getTabUrl(self::SECTION_CONNECTION)),
                'btcpayPermissions'  => self::BTCPAY_PERMISSIONS,
                'strings' => [
                    'testConnection'    => __('Test connection', 'btcpay-satoshi-tickets'),
                    'testWebhook'       => __('Test webhook endpoint', 'btcpay-satoshi-tickets'),
                    'testing'           => __('Testing…', 'btcpay-satoshi-tickets'),
                    'loading'           => __('Loading…', 'btcpay-satoshi-tickets'),
                    'error'             => __('Error. Check connection.', 'btcpay-satoshi-tickets'),
                    'wizardUrlRequired' => __('Please enter the BTCPay Server URL.', 'btcpay-satoshi-tickets'),
                    'wizardSelectStore' => __('Please select a store.', 'btcpay-satoshi-tickets'),
                    'wizardConnect'     => __('Connect', 'btcpay-satoshi-tickets'),
                ],
            ]);
        }

        if (self::getCurrentSection() === self::SECTION_EVENTS) {
        wp_enqueue_script(
            'btcpay-satoshi-admin',
            BTCPAY_SATOSHI_TICKETS_PLUGIN_URL . 'assets/js/admin-events.js',
            ['jquery'],
            BTCPAY_SATOSHI_TICKETS_VERSION,
            true
        );

        $satfluxStoreId = AdminWCSettingsTab::getSatfluxStoreIdForCheckin();
        $satfluxBase = rtrim(get_option('btcpay_satoshi_satflux_url', 'https://satflux.io'), '/');
        wp_localize_script('btcpay-satoshi-admin', 'btcpaySatoshiAdmin', [
            'ajaxUrl'            => admin_url('admin-ajax.php'),
            'adminPostUrl'       => admin_url('admin-post.php'),
            'nonce'              => wp_create_nonce('btcpay_satoshi_admin'),
            'exportNonce'        => wp_create_nonce('btcpay_satoshi_export_tickets'),
            'connectedViaSatflux' => AdminWCSettingsTab::isConnectedViaSatflux(),
            'satfluxCheckinBase' => ($satfluxStoreId !== '') ? $satfluxBase . '/stores/' . rawurlencode($satfluxStoreId) . '/ticket-check-in' : '',
            'strings' => [
                'loading'              => __('Loading...', 'btcpay-satoshi-tickets'),
                'error'                => __('Error loading data. Check BTCPay connection.', 'btcpay-satoshi-tickets'),
                'createProduct'        => __('Create WooCommerce Product', 'btcpay-satoshi-tickets'),
                'created'              => __('Product created.', 'btcpay-satoshi-tickets'),
                'syncStock'            => __('Sync stock', 'btcpay-satoshi-tickets'),
                'synced'               => __('Stock synced.', 'btcpay-satoshi-tickets'),
                'syncFromBtcpay'       => __('Sync from BTCPay', 'btcpay-satoshi-tickets'),
                'syncedFromBtcpay'     => __('Products synced from BTCPay.', 'btcpay-satoshi-tickets'),
                'addEvent'             => __('Add event', 'btcpay-satoshi-tickets'),
                'addTicketType'        => __('Add ticket type', 'btcpay-satoshi-tickets'),
                'createEvent'          => __('Create event', 'btcpay-satoshi-tickets'),
                'createTicketType'     => __('Create ticket type', 'btcpay-satoshi-tickets'),
                'eventCreated'         => __('Event created.', 'btcpay-satoshi-tickets'),
                'eventUpdated'         => __('Event updated.', 'btcpay-satoshi-tickets'),
                'ticketTypeCreated'    => __('Ticket type created.', 'btcpay-satoshi-tickets'),
                'ticketTypeUpdated'    => __('Ticket type updated.', 'btcpay-satoshi-tickets'),
                'editEvent'            => __('Edit event', 'btcpay-satoshi-tickets'),
                'checkin'              => __('Check-in', 'btcpay-satoshi-tickets'),
                'active'               => __('Active', 'btcpay-satoshi-tickets'),
                'disabled'             => __('Disabled', 'btcpay-satoshi-tickets'),
                'enable'               => __('Enable', 'btcpay-satoshi-tickets'),
                'disable'              => __('Disable', 'btcpay-satoshi-tickets'),
                'editTicketType'       => __('Edit ticket type', 'btcpay-satoshi-tickets'),
                'hasProduct'           => __('WooCommerce product', 'btcpay-satoshi-tickets'),
                'noProduct'            => __('No product', 'btcpay-satoshi-tickets'),
                'createTickets'        => __('Create tickets', 'btcpay-satoshi-tickets'),
                'ticketsCreated'       => __('Tickets created on BTCPay.', 'btcpay-satoshi-tickets'),
                'deleteEvent'          => __('Delete', 'btcpay-satoshi-tickets'),
                'deleteEventConfirm'   => __('Delete this event? This cannot be undone.', 'btcpay-satoshi-tickets'),
                'deleteTTConfirm'      => __('Delete this ticket type? This cannot be undone.', 'btcpay-satoshi-tickets'),
                'viewTickets'          => __('Tickets', 'btcpay-satoshi-tickets'),
                'viewOrders'           => __('Orders', 'btcpay-satoshi-tickets'),
                'exportCsv'            => __('Export CSV', 'btcpay-satoshi-tickets'),
                'checkedIn'            => __('Checked in', 'btcpay-satoshi-tickets'),
                'notCheckedIn'         => __('Not checked in', 'btcpay-satoshi-tickets'),
                'sendReminder'         => __('Send reminder', 'btcpay-satoshi-tickets'),
                'reminderSent'         => __('Reminder sent.', 'btcpay-satoshi-tickets'),
                'checkinSuccess'       => __('Check-in successful!', 'btcpay-satoshi-tickets'),
                'checkinError'         => __('Check-in failed.', 'btcpay-satoshi-tickets'),
                'noTickets'            => __('No tickets found.', 'btcpay-satoshi-tickets'),
                'noOrders'             => __('No orders found.', 'btcpay-satoshi-tickets'),
                'defaultBadge'         => __('Default', 'btcpay-satoshi-tickets'),
                'toggleTT'             => __('Toggle', 'btcpay-satoshi-tickets'),
                'deleteTT'             => __('Delete', 'btcpay-satoshi-tickets'),
                'uploadLogo'           => __('Upload logo', 'btcpay-satoshi-tickets'),
                'removeLogo'           => __('Remove logo', 'btcpay-satoshi-tickets'),
                'logoUploaded'         => __('Logo uploaded.', 'btcpay-satoshi-tickets'),
                'logoRemoved'          => __('Logo removed.', 'btcpay-satoshi-tickets'),
            ],
        ]);
        }
    }
}
