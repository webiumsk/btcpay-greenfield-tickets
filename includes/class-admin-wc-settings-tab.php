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

    public static function init(): void
    {
        add_filter('woocommerce_settings_tabs_array', [__CLASS__, 'addTab'], 50);
        add_filter('woocommerce_get_sections_' . self::TAB_ID, [__CLASS__, 'getSections']);
        add_filter('woocommerce_get_settings_' . self::TAB_ID, [__CLASS__, 'getSettings'], 10, 2);
        add_action('woocommerce_settings_' . self::TAB_ID, [__CLASS__, 'outputContent']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueScripts']);
        add_action('admin_init', [__CLASS__, 'handleSatfluxCallback']);
        add_action('admin_init', [__CLASS__, 'maybeSaveFromMainform'], 20);
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

        $ownUrl = get_option('btcpay_satoshi_url', '');
        $ownKey = get_option('btcpay_satoshi_api_key', '');
        $ownStore = get_option('btcpay_satoshi_store_id', '');
        $cfg = SatoshiApiClient::getConfig();
        $usingFallback = !empty($cfg) && ($ownUrl === '' && $ownKey === '' && $ownStore === '');
        $hasGf = defined('BTCPAYSERVER_PLUGIN_FILE_PATH');
        ?>
        <?php self::renderSatfluxConnectSection(); ?>
        <h3 class="title"><?php esc_html_e('Manual configuration', 'btcpay-satoshi-tickets'); ?></h3>
        <?php if ($usingFallback) : ?>
            <div class="notice notice-info inline" style="margin:0 0 1em 0;">
                <p>
                    <?php
                    printf(
                        esc_html__('Connection uses %s. Fill in the fields below to override.', 'btcpay-satoshi-tickets'),
                        '<strong>' . esc_html__('BTCPay Greenfield plugin settings', 'btcpay-satoshi-tickets') . '</strong>'
                    );
                    ?>
                </p>
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
        $client = new SatoshiApiClient();
        $isConfigured = $client->isConfigured();
        $hasWebhook = WebhookHandler::hasWebhookSecret();
        $viaSatflux = self::isConnectedViaSatflux();
        ?>
        <div class="btcpay-satoshi-status-bar">
            <?php if ($isConfigured) : ?>
                <span class="btcpay-satoshi-status btcpay-satoshi-status-connected"><?php esc_html_e('BTCPay: Connected', 'btcpay-satoshi-tickets'); ?></span>
                <?php if ($hasWebhook) : ?>
                    <span class="btcpay-satoshi-status btcpay-satoshi-status-webhook-ok"><?php esc_html_e('Webhook: Registered', 'btcpay-satoshi-tickets'); ?></span>
                <?php else : ?>
                    <span class="btcpay-satoshi-status btcpay-satoshi-status-webhook-no"><?php esc_html_e('Webhook: Not registered', 'btcpay-satoshi-tickets'); ?></span>
                <?php endif; ?>
                <?php if ($viaSatflux) : ?>
                    <span class="btcpay-satoshi-status btcpay-satoshi-status-satflux"><?php esc_html_e('Connected via Satflux.io', 'btcpay-satoshi-tickets'); ?></span>
                <?php endif; ?>
            <?php else : ?>
                <span class="btcpay-satoshi-status btcpay-satoshi-status-disconnected"><?php esc_html_e('BTCPay: Not configured', 'btcpay-satoshi-tickets'); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function renderSatfluxConnectSection(): void
    {
        $showSatflux = get_option('btcpay_satoshi_show_satflux', '0') === '1';
        $satfluxUrl = get_option('btcpay_satoshi_satflux_url', self::SATFLUX_CONNECT_DEFAULT);
        $returnUrl = self::getTabUrl(self::SECTION_CONNECTION);
        $connectUrl = rtrim($satfluxUrl, '/') . self::SATFLUX_CONNECT_PATH . '?return_url=' . rawurlencode($returnUrl) . '&return_satflux_store_id=1';
        if (isset($_GET['satflux_connected']) && $_GET['satflux_connected'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' .
                esc_html__('Connected to Satflux.io successfully.', 'btcpay-satoshi-tickets') . '</p></div>';
        }
        ?>
        <div class="btcpay-satoshi-satflux-connect">
            <p style="margin-top:0;">
                <label>
                    <input type="checkbox" id="btcpay_satoshi_show_satflux" name="btcpay_satoshi_show_satflux" value="1" <?php checked($showSatflux); ?> />
                    <?php esc_html_e('Quick connect to Satflux.io', 'btcpay-satoshi-tickets'); ?>
                </label>
            </p>
            <p class="description" style="margin-bottom:12px;">
                <?php
                printf(
                    /* translators: %s: URL to Satflux.io */
                    esc_html__('Satflux.io is an external frontend for BTCPay Server. One-click setup for Satflux users. Learn more: %s', 'btcpay-satoshi-tickets'),
                    '<a href="https://satflux.io" target="_blank" rel="noopener noreferrer">https://satflux.io</a>'
                );
                ?>
            </p>
            <div id="btcpay-satoshi-satflux-fields" style="<?php echo $showSatflux ? '' : 'display:none;'; ?>">
                <p>
                    <label for="btcpay_satoshi_satflux_url"><?php esc_html_e('Satflux.io URL', 'btcpay-satoshi-tickets'); ?></label><br>
                    <input type="url" id="btcpay_satoshi_satflux_url" name="btcpay_satoshi_satflux_url" value="<?php echo esc_attr($satfluxUrl); ?>"
                           class="regular-text" placeholder="https://satflux.io" />
                </p>
                <p>
                    <label for="btcpay_satoshi_satflux_store_id"><?php esc_html_e('BTCPay Store ID', 'btcpay-satoshi-tickets'); ?></label><br>
                    <input type="text" id="btcpay_satoshi_satflux_store_id" name="btcpay_satoshi_satflux_store_id"
                           value="<?php echo esc_attr(get_option('btcpay_satoshi_satflux_store_id', '')); ?>"
                           class="regular-text" placeholder="<?php esc_attr_e('BTCPay Store ID (GUID)', 'btcpay-satoshi-tickets'); ?>" />
                    <br><span class="description"><?php esc_html_e('Your BTCPay Server store identifier (GUID).', 'btcpay-satoshi-tickets'); ?></span>
                </p>
                <p>
                    <label for="btcpay_satoshi_satflux_checkin_store_id"><?php esc_html_e('Satflux Store ID (for Check-in)', 'btcpay-satoshi-tickets'); ?></label><br>
                    <input type="text" id="btcpay_satoshi_satflux_checkin_store_id" name="btcpay_satoshi_satflux_checkin_store_id"
                           value="<?php echo esc_attr(get_option('btcpay_satoshi_satflux_checkin_store_id', '')); ?>"
                           class="regular-text" placeholder="<?php esc_attr_e('Satflux store ID for check-in', 'btcpay-satoshi-tickets'); ?>" />
                    <br><span class="description"><?php esc_html_e('Required for Check-in links in Events. This is the store ID in Satflux.io (different from BTCPay Store ID). You find it in your Satflux.io dashboard or after connecting via the button below if Satflux returns it.', 'btcpay-satoshi-tickets'); ?></span>
                </p>
                <p>
                    <a href="<?php echo esc_url($connectUrl); ?>" class="button button-primary" id="btcpay-satoshi-connect-satflux" data-return-url="<?php echo esc_attr($returnUrl); ?>" data-connect-path="<?php echo esc_attr(self::SATFLUX_CONNECT_PATH); ?>">
                        <?php esc_html_e('Connect to Satflux.io', 'btcpay-satoshi-tickets'); ?>
                    </a>
                </p>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var cb = document.getElementById('btcpay_satoshi_show_satflux');
                var fields = document.getElementById('btcpay-satoshi-satflux-fields');
                var btn = document.getElementById('btcpay-satoshi-connect-satflux');
                var input = document.getElementById('btcpay_satoshi_satflux_url');
                if (cb && fields) {
                    cb.addEventListener('change', function() { fields.style.display = this.checked ? 'block' : 'none'; });
                }
                if (btn && input) {
                    btn.addEventListener('click', function(e) {
                        var base = (input.value || '').trim().replace(/\/+$/, '') || 'https://satflux.io';
                        var path = btn.getAttribute('data-connect-path') || '/woocommerce/satoshi-tickets/connect';
                        var returnUrl = btn.getAttribute('data-return-url') || '';
                        var sep = '?';
                        var q = returnUrl ? 'return_url=' + encodeURIComponent(returnUrl) : '';
                        if (q) { q += '&'; }
                        q += 'return_satflux_store_id=1';
                        btn.href = base + path + sep + q;
                    });
                }
            });
            </script>
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
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('btcpay_satoshi_admin'),
            'connectedViaSatflux' => AdminWCSettingsTab::isConnectedViaSatflux(),
            'satfluxCheckinBase' => ($satfluxStoreId !== '') ? $satfluxBase . '/stores/' . rawurlencode($satfluxStoreId) . '/ticket-check-in' : '',
            'strings' => [
                'loading' => __('Loading...', 'btcpay-satoshi-tickets'),
                'error' => __('Error loading data. Check BTCPay connection.', 'btcpay-satoshi-tickets'),
                'createProduct' => __('Create WooCommerce Product', 'btcpay-satoshi-tickets'),
                'created' => __('Product created.', 'btcpay-satoshi-tickets'),
                'syncStock' => __('Sync stock', 'btcpay-satoshi-tickets'),
                'synced' => __('Stock synced.', 'btcpay-satoshi-tickets'),
                'syncFromBtcpay' => __('Sync from BTCPay', 'btcpay-satoshi-tickets'),
                'syncedFromBtcpay' => __('Products synced from BTCPay.', 'btcpay-satoshi-tickets'),
                'addEvent' => __('Add event', 'btcpay-satoshi-tickets'),
                'addTicketType' => __('Add ticket type', 'btcpay-satoshi-tickets'),
                'createEvent' => __('Create event', 'btcpay-satoshi-tickets'),
                'createTicketType' => __('Create ticket type', 'btcpay-satoshi-tickets'),
                'eventCreated' => __('Event created.', 'btcpay-satoshi-tickets'),
                'eventUpdated' => __('Event updated.', 'btcpay-satoshi-tickets'),
                'ticketTypeCreated' => __('Ticket type created.', 'btcpay-satoshi-tickets'),
                'ticketTypeUpdated' => __('Ticket type updated.', 'btcpay-satoshi-tickets'),
                'editEvent' => __('Edit event', 'btcpay-satoshi-tickets'),
                'checkin' => __('Check-in', 'btcpay-satoshi-tickets'),
                'active' => __('Active', 'btcpay-satoshi-tickets'),
                'disabled' => __('Disabled', 'btcpay-satoshi-tickets'),
                'enable' => __('Enable', 'btcpay-satoshi-tickets'),
                'disable' => __('Disable', 'btcpay-satoshi-tickets'),
                'editTicketType' => __('Edit ticket type', 'btcpay-satoshi-tickets'),
                'hasProduct' => __('WooCommerce product', 'btcpay-satoshi-tickets'),
                'noProduct' => __('No product', 'btcpay-satoshi-tickets'),
                'createTickets' => __('Create tickets', 'btcpay-satoshi-tickets'),
                'ticketsCreated' => __('Tickets created on BTCPay.', 'btcpay-satoshi-tickets'),
            ],
        ]);
        }
    }
}
