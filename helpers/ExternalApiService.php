<?php
/**
 * helpers/ExternalApiService.php
 * Outbound B2B Integration Service (PHP cURL)
 *
 * Handles outgoing HTTP/REST API notifications to partner teams
 * (Sales, Procurement, Production) when warehouse inventory lifecycle events occur.
 *
 * Architecture & Fault-Tolerance:
 * - Decoupled & Non-blocking: Strict 2-3s timeouts prevent warehouse UI hangs.
 * - Graceful Placeholders: If an external team's URL is empty in .env, requests
 *   are safely skipped without error.
 * - Exception Shield: All network exceptions are caught and logged so local database
 *   transactions and UI feedback are never disrupted.
 */

require_once __DIR__ . '/../config/bootstrap.php';

class ExternalApiService {

    /**
     * Check if outgoing notifications are globally enabled.
     */
    public static function isEnabled(): bool {
        $val = strtolower(trim((string)(getenv('EXTERNAL_API_NOTIFICATIONS_ENABLED') ?: 'true')));
        return in_array($val, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Core cURL HTTP Request Dispatcher.
     *
     * @param string      $url     Destination API endpoint
     * @param string      $method  HTTP Verb ('POST', 'PUT', 'GET')
     * @param array       $payload JSON payload data
     * @param string|null $token   Optional Bearer API token
     * @return array Standardized result status
     */
    public static function send(string $url, string $method = 'POST', array $payload = [], ?string $token = null): array {
        if (!self::isEnabled()) {
            return [
                'success' => false,
                'skipped' => true,
                'reason'  => 'External API notifications are disabled in configuration.'
            ];
        }

        $url = trim($url);
        if ($url === '') {
            return [
                'success' => false,
                'skipped' => true,
                'reason'  => 'Target URL placeholder is currently empty in .env.'
            ];
        }

        if (!extension_loaded('curl')) {
            error_log('[ExternalApiService] Notice: PHP cURL extension is not installed or enabled.');
            return [
                'success' => false,
                'skipped' => false,
                'error'   => 'PHP cURL extension not enabled.'
            ];
        }

        $timeout = (int)(getenv('EXTERNAL_API_TIMEOUT_SECONDS') ?: 3);
        if ($timeout <= 0) {
            $timeout = 3;
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: InventoryERP-IntegrationClient/1.0',
            'X-Notification-Timestamp: ' . (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM)
        ];

        if (!empty($token)) {
            $headers[] = "Authorization: Bearer {$token}";
        }

        $method = strtoupper(trim($method));
        $ch = curl_init();

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => min(2, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false, // Set to false to support local testing & self-signed certs
            CURLOPT_SSL_VERIFYHOST => false,
        ];

        if (!empty($payload) && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($ch, $options);

        try {
            $responseBody = curl_exec($ch);
            $curlErrno = curl_errno($ch);
            $curlError = curl_error($ch);
            $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlErrno !== 0) {
                error_log("[ExternalApiService] Outgoing request to '{$url}' failed: ({$curlErrno}) {$curlError}");
                return [
                    'success'   => false,
                    'skipped'   => false,
                    'error'     => $curlError,
                    'errno'     => $curlErrno,
                    'http_code' => $httpCode
                ];
            }

            $decoded = @json_decode((string)$responseBody, true);

            return [
                'success'   => ($httpCode >= 200 && $httpCode < 300),
                'skipped'   => false,
                'http_code' => $httpCode,
                'response'  => $decoded !== null ? $decoded : $responseBody
            ];
        } catch (Throwable $e) {
            if (is_resource($ch)) {
                curl_close($ch);
            }
            error_log("[ExternalApiService] Exception during dispatch to '{$url}': " . $e->getMessage());
            return [
                'success' => false,
                'skipped' => false,
                'error'   => $e->getMessage()
            ];
        }
    }

    /**
     * Notify Partner Team when an Outbound Order is Physically Dispatched.
     *
     * - SALES_DELIVERY   => Notifies Sales Team
     * - MATERIAL_REQUEST => Notifies Production Team
     * - SUPPLIER_RETURN  => Notifies Procurement Team
     *
     * @param array  $order             Outbound order row from database
     * @param array  $itemsDispatched   List of dispatched items [{item_id, quantity}]
     * @param string $transactionNumber The generated stock out movement tracking number
     * @return array Integration dispatch summary
     */
    public static function notifyOutboundDispatch(array $order, array $itemsDispatched, string $transactionNumber): array {
        try {
            $sourceType = strtoupper(trim((string)($order['source_type'] ?? '')));

            switch ($sourceType) {
                case 'SALES_DELIVERY':
                    $url = (string)getenv('SALES_API_URL');
                    $token = (string)getenv('SALES_API_TOKEN');
                    $event = 'SALES_ORDER_DISPATCHED';
                    break;

                case 'MATERIAL_REQUEST':
                    $url = (string)getenv('PRODUCTION_API_URL');
                    $token = (string)getenv('PRODUCTION_API_TOKEN');
                    $event = 'MATERIAL_REQUEST_DISPATCHED';
                    break;

                case 'SUPPLIER_RETURN':
                    $url = (string)getenv('PROCUREMENT_API_URL');
                    $token = (string)getenv('PROCUREMENT_API_TOKEN');
                    $event = 'SUPPLIER_RETURN_DISPATCHED';
                    break;

                default:
                    return [
                        'success' => false,
                        'skipped' => true,
                        'reason'  => "No external integration configured for source_type '{$sourceType}'."
                    ];
            }

            if (empty(trim($url))) {
                return [
                    'success' => false,
                    'skipped' => true,
                    'target'  => $sourceType,
                    'reason'  => "API URL for {$sourceType} is not set in .env."
                ];
            }

            $payload = [
                'event'                 => $event,
                'order_number'          => $order['order_number'] ?? '',
                'transaction_number'    => $transactionNumber,
                'warehouse_id'          => (int)($order['warehouse_id'] ?? 0),
                'status'                => 'dispatched',
                'dispatched_timestamp'  => date('Y-m-d H:i:s'),
                'items'                 => $itemsDispatched,
                'remarks'               => $order['notes'] ?? $order['remarks'] ?? null
            ];

            return self::send($url, 'POST', $payload, $token);
        } catch (Throwable $e) {
            error_log('[ExternalApiService] notifyOutboundDispatch error: ' . $e->getMessage());
            return ['success' => false, 'skipped' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Notify Partner Team when an Inbound Order is Received at the Loading Dock.
     *
     * - PURCHASE_ORDER     => Notifies Procurement Team (GRN confirmation)
     * - PRODUCTION_RETURN  => Notifies Production Team (Finished Goods accepted)
     * - CUSTOMER_RETURN    => Notifies Sales Team (RMA accepted/inspected)
     *
     * @param array  $order             Inbound order row from database
     * @param array  $itemsReceived     List of received items [{item_id, quantity}]
     * @param string $transactionNumber The generated stock in movement tracking number
     * @param string $condition         Condition ('salable' or 'damaged')
     * @return array Integration dispatch summary
     */
    public static function notifyInboundReceipt(array $order, array $itemsReceived, string $transactionNumber, string $condition = 'salable'): array {
        try {
            $sourceType = strtoupper(trim((string)($order['source_type'] ?? '')));

            switch ($sourceType) {
                case 'PURCHASE_ORDER':
                    $url = (string)getenv('PROCUREMENT_API_URL');
                    $token = (string)getenv('PROCUREMENT_API_TOKEN');
                    $event = 'GOODS_RECEIPT_CONFIRMED';
                    break;

                case 'PRODUCTION_RETURN':
                    $url = (string)getenv('PRODUCTION_API_URL');
                    $token = (string)getenv('PRODUCTION_API_TOKEN');
                    $event = 'FINISHED_GOODS_STORED';
                    break;

                case 'CUSTOMER_RETURN':
                    $url = (string)getenv('SALES_API_URL');
                    $token = (string)getenv('SALES_API_TOKEN');
                    $event = 'CUSTOMER_RETURN_RECEIVED';
                    break;

                default:
                    return [
                        'success' => false,
                        'skipped' => true,
                        'reason'  => "No external integration configured for source_type '{$sourceType}'."
                    ];
            }

            if (empty(trim($url))) {
                return [
                    'success' => false,
                    'skipped' => true,
                    'target'  => $sourceType,
                    'reason'  => "API URL for {$sourceType} is not set in .env."
                ];
            }

            $payload = [
                'event'                 => $event,
                'order_number'          => $order['order_number'] ?? '',
                'transaction_number'    => $transactionNumber,
                'warehouse_id'          => (int)($order['warehouse_id'] ?? 0),
                'status'                => 'received',
                'condition'             => $condition,
                'received_timestamp'    => date('Y-m-d H:i:s'),
                'items'                 => $itemsReceived,
                'remarks'               => $order['notes'] ?? $order['remarks'] ?? null
            ];

            return self::send($url, 'POST', $payload, $token);
        } catch (Throwable $e) {
            error_log('[ExternalApiService] notifyInboundReceipt error: ' . $e->getMessage());
            return ['success' => false, 'skipped' => false, 'error' => $e->getMessage()];
        }
    }
}
