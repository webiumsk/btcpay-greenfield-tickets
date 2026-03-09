<?php
/**
 * SatoshiTickets Greenfield API client.
 *
 * Uses BTCPay Greenfield for WooCommerce credentials when available,
 * otherwise own plugin settings (btcpay_satoshi_*).
 *
 * @package BTCPaySatoshiTickets
 */

declare(strict_types=1);

namespace BTCPaySatoshiTickets;

if (!defined('ABSPATH')) {
    exit;
}

final class SatoshiApiClient
{
    private string $url;
    private string $apiKey;
    private string $storeId;

    public function __construct()
    {
        $cfg = self::getConfig();
        $this->url = rtrim($cfg['url'] ?? '', '/');
        $this->apiKey = $cfg['api_key'] ?? '';
        $this->storeId = $cfg['store_id'] ?? '';
    }

    public function isConfigured(): bool
    {
        return $this->url !== '' && $this->apiKey !== '' && $this->storeId !== '';
    }

    private function getBaseUrl(): string
    {
        return $this->url . '/api/v1/stores/' . $this->storeId . '/satoshi-tickets';
    }

    /**
     * Get BTCPay config. Prefers own plugin settings; falls back to GF plugin.
     *
     * @return array{url: string, api_key: string, store_id: string}|array{}
     */
    public static function getConfig(): array
    {
        $url = get_option('btcpay_satoshi_url', '') ?: get_option('woocommerce_btcpay_satoshi_url', '');
        $key = get_option('btcpay_satoshi_api_key', '') ?: get_option('woocommerce_btcpay_satoshi_api_key', '');
        $storeId = get_option('btcpay_satoshi_store_id', '') ?: get_option('woocommerce_btcpay_satoshi_store_id', '');
        if ($url === '' || $key === '' || $storeId === '') {
            $url = get_option('btcpay_gf_url', '');
            $key = get_option('btcpay_gf_api_key', '');
            $storeId = get_option('btcpay_gf_store_id', '');
        }
        if ($url && $key && $storeId) {
            return [
                'url' => rtrim((string) $url, '/'),
                'api_key' => (string) $key,
                'store_id' => (string) $storeId,
            ];
        }
        return [];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{success: bool, data?: mixed, code?: int, message?: string}
     */
    private function request(string $method, string $path, array $args = []): array
    {
        $url = $this->getBaseUrl() . $path;
        $headers = [
            'Authorization' => 'token ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ];

        if ($method === 'GET' && !empty($args)) {
            $url = add_query_arg($args, $url);
            $args = [];
        }

        $options = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
        ];

        if ($method !== 'GET' && !empty($args)) {
            $options['body'] = wp_json_encode($args);
        }

        $response = wp_remote_request($url, $options);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code >= 200 && $code < 300) {
            return [
                'success' => true,
                'data' => $data,
                'code' => $code,
            ];
        }

        $message = is_array($data) && isset($data['message']) ? $data['message'] : $body;
        return [
            'success' => false,
            'code' => $code,
            'message' => is_string($message) ? $message : 'Unknown error',
            'data' => $data,
        ];
    }

    /**
     * List events.
     *
     * @param bool $expired Include expired events
     * @return array{success: bool, data?: array<int, array>, message?: string}
     */
    public function getEvents(bool $expired = false): array
    {
        $result = $this->request('GET', '/events', $expired ? ['expired' => 'true'] : []);
        if (!$result['success']) {
            return $result;
        }
        $raw = $result['data'] ?? null;
        if (is_array($raw) && isset($raw['data']) && is_array($raw['data'])) {
            $result['data'] = $raw['data'];
        } elseif (is_array($raw)) {
            $result['data'] = $raw;
        } else {
            $result['data'] = [];
        }
        return $result;
    }

    /**
     * Get single event.
     *
     * @return array{success: bool, data?: array, message?: string}
     */
    public function getEvent(string $eventId): array
    {
        return $this->request('GET', '/events/' . rawurlencode($eventId));
    }

    /**
     * List ticket types for event.
     *
     * @return array{success: bool, data?: array<int, array>, message?: string}
     */
    public function getTicketTypes(string $eventId): array
    {
        $result = $this->request('GET', '/events/' . rawurlencode($eventId) . '/ticket-types');
        if (!$result['success']) {
            return $result;
        }
        $result['data'] = is_array($result['data'] ?? null) ? $result['data'] : [];
        return $result;
    }

    /**
     * Get quantity available for a ticket type.
     *
     * @return int|null Available quantity, or null on failure
     */
    public function getTicketTypeQuantityAvailable(string $eventId, string $ticketTypeId): ?int
    {
        $result = $this->getTicketTypes($eventId);
        if (!$result['success'] || !is_array($result['data'] ?? null)) {
            return null;
        }
        foreach ($result['data'] as $tt) {
            $id = $tt['id'] ?? $tt['Id'] ?? '';
            if ((string) $id === (string) $ticketTypeId) {
                if (isset($tt['quantityAvailable'])) {
                    return (int) $tt['quantityAvailable'];
                }
                $qty = (int) ($tt['quantity'] ?? $tt['Quantity'] ?? 0);
                $sold = (int) ($tt['quantitySold'] ?? $tt['QuantitySold'] ?? 0);
                return max(0, $qty - $sold);
            }
        }
        return null;
    }

    /**
     * Validate that requested quantities are available for given ticket types.
     *
     * @param array<int, array{ticketTypeId: string, quantity: int}> $tickets
     * @return array{valid: bool, message?: string}
     */
    public function validateTicketQuantities(string $eventId, array $tickets): array
    {
        $types = $this->getTicketTypes($eventId);
        if (!$types['success'] || !is_array($types['data'] ?? null)) {
            return ['valid' => false, 'message' => $types['message'] ?? 'Failed to fetch ticket types'];
        }
        $byId = [];
        foreach ($types['data'] as $tt) {
            $id = $tt['id'] ?? $tt['Id'] ?? '';
            $available = isset($tt['quantityAvailable']) ? (int) $tt['quantityAvailable'] : null;
            if ($available === null) {
                $qty = (int) ($tt['quantity'] ?? $tt['Quantity'] ?? 0);
                $sold = (int) ($tt['quantitySold'] ?? $tt['QuantitySold'] ?? 0);
                $available = max(0, $qty - $sold);
            }
            $byId[(string) $id] = ['available' => $available, 'name' => $tt['name'] ?? $tt['Name'] ?? ''];
        }
        foreach ($tickets as $t) {
            $tid = (string) ($t['ticketTypeId'] ?? '');
            $req = (int) ($t['quantity'] ?? 0);
            $info = $byId[$tid] ?? null;
            if (!$info) {
                return ['valid' => false, 'message' => sprintf(__('Ticket type %s not found.', 'btcpay-satoshi-tickets'), $tid)];
            }
            if ($info['available'] < $req) {
                return [
                    'valid' => false,
                    'message' => sprintf(
                        __('Not enough tickets available for "%s". Available: %d, requested: %d.', 'btcpay-satoshi-tickets'),
                        $info['name'] ?: $tid,
                        $info['available'],
                        $req
                    ),
                ];
            }
        }
        return ['valid' => true];
    }

    /**
     * Create event.
     *
     * @param array<string, mixed> $data title, startDate, description?, location?, eventType?, currency?, hasMaximumCapacity?, maximumEventCapacity?, enable?
     * @return array{success: bool, data?: array, message?: string}
     */
    public function createEvent(array $data): array
    {
        $payload = self::normalizeEventPayload($data);
        return $this->request('POST', '/events', $payload);
    }

    /**
     * Update event.
     *
     * @param array<string, mixed> $data
     * @return array{success: bool, data?: array, message?: string}
     */
    public function updateEvent(string $eventId, array $data): array
    {
        $payload = self::normalizeEventPayload($data);
        return $this->request('PUT', '/events/' . rawurlencode($eventId), $payload);
    }

    /**
     * Delete event.
     *
     * @return array{success: bool, data?: mixed, message?: string}
     */
    public function deleteEvent(string $eventId): array
    {
        return $this->request('DELETE', '/events/' . rawurlencode($eventId));
    }

    /**
     * Toggle event (Active/Disabled).
     *
     * @return array{success: bool, data?: array, message?: string}
     */
    public function toggleEvent(string $eventId): array
    {
        return $this->request('PUT', '/events/' . rawurlencode($eventId) . '/toggle', []);
    }

    /**
     * Create ticket type.
     *
     * @param array<string, mixed> $data name, price, description?, quantity?
     * @return array{success: bool, data?: array, message?: string}
     */
    public function createTicketType(string $eventId, array $data): array
    {
        $payload = self::normalizeTicketTypePayload($data);
        return $this->request('POST', '/events/' . rawurlencode($eventId) . '/ticket-types', $payload);
    }

    /**
     * Update ticket type.
     *
     * @return array{success: bool, data?: array, message?: string}
     */
    public function updateTicketType(string $eventId, string $ticketTypeId, array $data): array
    {
        $payload = self::normalizeTicketTypePayload($data);
        return $this->request('PUT', '/events/' . rawurlencode($eventId) . '/ticket-types/' . rawurlencode($ticketTypeId), $payload);
    }

    /**
     * Delete ticket type.
     *
     * @return array{success: bool, data?: mixed, message?: string}
     */
    public function deleteTicketType(string $eventId, string $ticketTypeId): array
    {
        return $this->request('DELETE', '/ticket-types/' . rawurlencode($ticketTypeId) . '?eventId=' . rawurlencode($eventId), []);
    }

    /**
     * Toggle ticket type (Active/Disabled).
     *
     * @return array{success: bool, data?: array, message?: string}
     */
    public function toggleTicketType(string $eventId, string $ticketTypeId): array
    {
        return $this->request('PUT', '/ticket-types/' . rawurlencode($ticketTypeId) . '/toggle?eventId=' . rawurlencode($eventId), []);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function normalizeEventPayload(array $data): array
    {
        $allowed = ['title', 'description', 'eventType', 'location', 'startDate', 'endDate', 'currency', 'redirectUrl', 'emailSubject', 'emailBody', 'hasMaximumCapacity', 'maximumEventCapacity', 'eventLogoFileId', 'enable'];
        $out = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) {
                $v = $data[$k];
                if ($k === 'hasMaximumCapacity' || $k === 'enable') {
                    $out[$k] = (bool) $v;
                } elseif ($k === 'maximumEventCapacity' && $v !== null && $v !== '') {
                    $out[$k] = (int) $v;
                } elseif ($v !== null && $v !== '') {
                    $out[$k] = $v;
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function normalizeTicketTypePayload(array $data): array
    {
        $out = [];
        if (isset($data['name'])) {
            $out['name'] = (string) $data['name'];
        }
        if (isset($data['price'])) {
            $out['price'] = (float) $data['price'];
        }
        if (array_key_exists('description', $data)) {
            $out['description'] = $data['description'] === null || $data['description'] === '' ? null : (string) $data['description'];
        }
        if (array_key_exists('quantity', $data)) {
            $qty = $data['quantity'];
            $out['quantity'] = $qty === null || $qty === '' ? 999999 : (int) $qty;
        }
        return $out;
    }

    /**
     * Create tickets for offline/manual payment (no BTCPay invoice).
     * Requires SatoshiTickets plugin to implement this endpoint.
     *
     * @param array<int, array{ticketTypeId: string, quantity: int, recipients: array}> $tickets
     * @return array{success: bool, data?: array, message?: string}
     */
    public function createTicketsOffline(string $eventId, array $tickets, string $orderReference = ''): array
    {
        $body = ['tickets' => $tickets];
        if ($orderReference !== '') {
            $body['orderReference'] = $orderReference;
        }
        return $this->request('POST', '/events/' . rawurlencode($eventId) . '/create-tickets-offline', $body);
    }

    /**
     * Create purchase - returns checkout URL.
     *
     * @param string $eventId Event ID
     * @param array<int, array{ticketTypeId: string, quantity: int, recipients: array<int, array{firstName?: string, lastName?: string, email: string}>}> $tickets
     * @param string $redirectUrl URL after payment
     * @param float $orderTotal Optional. WooCommerce order total (with coupons/discounts). When > 0 and in same currency as event, invoice amount will use this instead of sum of ticket prices.
     * @return array{success: bool, data?: array{orderId: string, txnId: string, invoiceId: string, checkoutUrl: string}, message?: string}
     */
    public function createPurchase(string $eventId, array $tickets, string $redirectUrl = '', float $orderTotal = 0.0): array
    {
        $body = [
            'tickets' => $tickets,
        ];
        if ($redirectUrl !== '') {
            $body['redirectUrl'] = $redirectUrl;
        }
        if ($orderTotal > 0) {
            $body['orderTotal'] = $orderTotal;
        }
        return $this->request('POST', '/events/' . rawurlencode($eventId) . '/purchase', $body);
    }
}
