<?php
/**
 * WooCommerce product type: Satoshi Ticket.
 *
 * @package BTCPaySatoshiTickets
 */

declare(strict_types=1);

namespace BTCPaySatoshiTickets;

if (!defined('ABSPATH')) {
    exit;
}

final class ProductTypeTicket
{
    public const TYPE = 'satoshi_ticket';
    public const META_EVENT_ID = '_satoshi_event_id';
    public const META_TICKET_TYPE_ID = '_satoshi_ticket_type_id';

    public static function init(): void
    {
        add_filter('product_type_selector', [__CLASS__, 'addProductType']);
        add_action('init', [__CLASS__, 'registerProductClass']);
        add_filter('woocommerce_product_class', [__CLASS__, 'getProductClass'], 10, 4);
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'addProductFields']);
        add_action('woocommerce_process_product_meta', [__CLASS__, 'saveProductFields']);
        add_action('woocommerce_update_product', [__CLASS__, 'clearProductCacheOnUpdate'], 10, 1);
        add_filter('woocommerce_product_data_tabs', [__CLASS__, 'productDataTab']);
        add_action('admin_footer', [__CLASS__, 'productTypeScript']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueProductScript']);
        add_action('woocommerce_satoshi_ticket_add_to_cart', [__CLASS__, 'addToCartTemplate']);
    }

    public static function addToCartTemplate(): void
    {
        wc_get_template('single-product/add-to-cart/simple.php');
    }

    public static function addProductType(array $types): array
    {
        $types[self::TYPE] = __('Satoshi Ticket', 'btcpay-satoshi-tickets');
        return $types;
    }

    public static function registerProductClass(): void
    {
        if (!class_exists('WC_Product_Simple')) {
            return;
        }
        require_once BTCPAY_SATOSHI_TICKETS_PLUGIN_PATH . 'includes/class-wc-product-satoshi-ticket.php';
    }

    /**
     * @param string|false $class
     * @param string $product_type
     * @param string $post_type
     * @param int $product_id
     */
    public static function getProductClass($class, $product_type, $post_type, $product_id): string
    {
        if ($product_type === self::TYPE) {
            return \BTCPaySatoshiTickets\WC_Product_Satoshi_Ticket::class;
        }
        return is_string($class) ? $class : \WC_Product_Simple::class;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private static function getCachedEventOptions(): array
    {
        $cacheKey = 'btcpay_satoshi_events_options';
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        $eventOptions = [];
        $client = new SatoshiApiClient();
        if ($client->isConfigured()) {
            $result = $client->getEvents(false);
            $events = $result['data'] ?? [];
            if (empty($events)) {
                $resultExpired = $client->getEvents(true);
                $events = $resultExpired['data'] ?? [];
            }
            foreach ($events as $e) {
                $id = $e['id'] ?? $e['Id'] ?? '';
                $title = $e['title'] ?? $e['Title'] ?? (string) $id;
                $eventOptions[] = ['value' => (string) $id, 'label' => $title];
            }
            set_transient($cacheKey, $eventOptions, 60);
        }
        return $eventOptions;
    }

    public static function addProductFields(): void
    {
        global $post;
        if (!$post || $post->post_type !== 'product') {
            return;
        }

        $product = wc_get_product($post->ID);
        $currentEventId = $product ? $product->get_meta(self::META_EVENT_ID, true) : '';
        $currentTicketTypeId = $product ? $product->get_meta(self::META_TICKET_TYPE_ID, true) : '';

        $eventOptions = self::getCachedEventOptions();
        $client = new SatoshiApiClient();

        echo '<div class="options_group satoshi_ticket_options show_if_' . esc_attr(self::TYPE) . '">';
        echo '<p class="form-field">';
        echo '<label for="' . esc_attr(self::META_EVENT_ID) . '">' . esc_html__('Event', 'btcpay-satoshi-tickets') . '</label>';
        echo '<select id="' . esc_attr(self::META_EVENT_ID) . '" name="' . esc_attr(self::META_EVENT_ID) . '" class="satoshi-select short" data-current="' . esc_attr($currentEventId) . '">';
        echo '<option value="">' . esc_html__('— Select event —', 'btcpay-satoshi-tickets') . '</option>';
        foreach ($eventOptions as $opt) {
            $selected = ($opt['value'] === $currentEventId) ? ' selected' : '';
            echo '<option value="' . esc_attr($opt['value']) . '"' . $selected . '>' . esc_html($opt['label']) . '</option>';
        }
        echo '</select>';
        echo '<span class="description">' . esc_html__('Select event from BTCPay SatoshiTickets.', 'btcpay-satoshi-tickets') . '</span>';
        echo '</p>';
        echo '<p class="form-field">';
        echo '<label for="' . esc_attr(self::META_TICKET_TYPE_ID) . '">' . esc_html__('Ticket type', 'btcpay-satoshi-tickets') . '</label>';
        echo '<select id="' . esc_attr(self::META_TICKET_TYPE_ID) . '" name="' . esc_attr(self::META_TICKET_TYPE_ID) . '" class="satoshi-select short" data-current="' . esc_attr($currentTicketTypeId) . '" data-event-id="' . esc_attr($currentEventId) . '">';
        $ticketOptions = [];
        if ($currentEventId && $client->isConfigured()) {
            $ttResult = $client->getTicketTypes($currentEventId);
            if (!empty($ttResult['success']) && is_array($ttResult['data'] ?? null)) {
                foreach ($ttResult['data'] as $tt) {
                    $id = $tt['id'] ?? $tt['Id'] ?? '';
                    $name = $tt['name'] ?? $tt['Name'] ?? (string) $id;
                    $ticketOptions[] = ['value' => (string) $id, 'label' => $name];
                }
            }
        }
        $ticketPlaceholder = empty($ticketOptions) ? __('— Select event first —', 'btcpay-satoshi-tickets') : __('— Select ticket type —', 'btcpay-satoshi-tickets');
        echo '<option value="">' . esc_html($ticketPlaceholder) . '</option>';
        foreach ($ticketOptions as $opt) {
            $selected = ($opt['value'] === $currentTicketTypeId) ? ' selected' : '';
            echo '<option value="' . esc_attr($opt['value']) . '"' . $selected . '>' . esc_html($opt['label']) . '</option>';
        }
        echo '</select>';
        echo '<span class="description">' . esc_html__('Select ticket type for the chosen event.', 'btcpay-satoshi-tickets') . '</span>';
        echo '</p>';
        echo '</div>';
    }

    public static function saveProductFields(int $postId): void
    {
        $product = wc_get_product($postId);
        if (!$product) {
            return;
        }
        $formProductType = isset($_POST['product_type']) ? sanitize_text_field(wp_unslash($_POST['product_type'])) : '';
        if ($product->get_type() !== self::TYPE && $formProductType !== self::TYPE && !$product->get_meta(self::META_EVENT_ID) && !$product->get_meta(self::META_TICKET_TYPE_ID)) {
            return;
        }
        $eventId = isset($_POST[self::META_EVENT_ID]) ? sanitize_text_field(wp_unslash($_POST[self::META_EVENT_ID])) : '';
        $ticketTypeId = isset($_POST[self::META_TICKET_TYPE_ID]) ? sanitize_text_field(wp_unslash($_POST[self::META_TICKET_TYPE_ID])) : '';
        $product->update_meta_data(self::META_EVENT_ID, $eventId);
        $product->update_meta_data(self::META_TICKET_TYPE_ID, $ticketTypeId);
        $product->save();
        self::clearProductCache($postId);
    }

    /**
     * Clear product transients so price and other updates show immediately.
     */
    public static function clearProductCacheOnUpdate($product): void
    {
        $id = is_object($product) ? $product->get_id() : (int) $product;
        if ($id > 0) {
            $p = wc_get_product($id);
            if ($p && self::isSatoshiTicketProduct($p)) {
                self::clearProductCache($id);
            }
        }
    }

    private static function clearProductCache(int $productId): void
    {
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($productId);
        }
    }

    public static function productDataTab(array $tabs): array
    {
        if (isset($tabs['general']['class'])) {
            $tabs['general']['class'][] = 'show_if_' . self::TYPE;
        }
        if (isset($tabs['inventory']['class'])) {
            $tabs['inventory']['class'][] = 'show_if_' . self::TYPE;
        }
        return $tabs;
    }

    public static function enqueueProductScript(string $hook): void
    {
        $isProduct = false;
        $pagenow = $GLOBALS['pagenow'] ?? '';
        if (in_array($hook, ['post', 'post.php', 'post-new', 'post-new.php'], true) || in_array($pagenow, ['post.php', 'post-new.php'], true)) {
            $postType = null;
            if (($hook === 'post-new' || $hook === 'post-new.php') || $pagenow === 'post-new.php') {
                $postType = isset($_GET['post_type']) ? sanitize_text_field(wp_unslash($_GET['post_type'])) : null;
                if ($postType === null) {
                    global $post;
                    $postType = ($post && isset($post->post_type)) ? $post->post_type : null;
                }
            } else {
                $postId = isset($_GET['post']) ? (int) $_GET['post'] : 0;
                $postType = $postId ? get_post_type($postId) : null;
            }
            $isProduct = $postType === 'product';
        }
        if (!$isProduct) {
            $screen = get_current_screen();
            if ($screen && (($screen->id ?? '') === 'product' || ($screen->post_type ?? '') === 'product')) {
                $isProduct = true;
            }
        }
        if (!$isProduct) {
            return;
        }
        wp_enqueue_style(
            'btcpay-satoshi-product-style',
            BTCPAY_SATOSHI_TICKETS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            BTCPAY_SATOSHI_TICKETS_VERSION
        );
        wp_enqueue_script(
            'btcpay-satoshi-product',
            BTCPAY_SATOSHI_TICKETS_PLUGIN_URL . 'assets/js/product-satoshi-ticket.js',
            ['jquery'],
            BTCPAY_SATOSHI_TICKETS_VERSION,
            true
        );
        $eventOpts = self::getCachedEventOptions();
        $events = array_map(static fn (array $o) => ['id' => $o['value'], 'title' => $o['label']], $eventOpts);
        wp_localize_script('btcpay-satoshi-product', 'btcpaySatoshiProduct', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('btcpay_satoshi_admin'),
            'type' => self::TYPE,
            'events' => $events,
        ]);
    }

    public static function productTypeScript(): void
    {
        $screen = get_current_screen();
        if (!$screen || ($screen->id !== 'product' && ($screen->post_type ?? '') !== 'product')) {
            return;
        }
        ?>
        <script>
        (function($){
            $(document).ready(function(){
                var $gp = $('#general_product_data');
                if ($gp.length) {
                    $gp.find('.pricing').addClass('show_if_<?php echo esc_js(self::TYPE); ?>');
                }
            });
        })(jQuery);
        </script>
        <?php
    }

    public static function isSatoshiTicketProduct(\WC_Product $product): bool
    {
        if ($product->get_type() === self::TYPE) {
            return true;
        }
        return $product->get_meta(self::META_EVENT_ID) !== '' && $product->get_meta(self::META_TICKET_TYPE_ID) !== '';
    }
}
