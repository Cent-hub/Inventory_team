<?php
/**
 * OrderService.php
 * Handles creation and business validation for Inbound and Outbound Orders.
 * Connects ERP procurement, production, sales, and warehouse dock operations.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/StockService.php';
require_once __DIR__ . '/AccountabilityService.php';

class OrderService {
    private PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * Cancel an Outbound Order and release its reserved stock
     *
     * @param int $orderId Outbound order ID
     * @param int $userId Performing user ID
     * @param string $reason Cancellation reason
     * @param array|null $authUser Authenticated session user
     * @return array
     */
    public function cancelOutboundOrder(int $orderId, int $userId, string $reason = '', ?array $authUser = null): array {
        if ($orderId <= 0) {
            throw new InvalidArgumentException("Valid outbound order ID is required.");
        }
        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmtOrder = $this->pdo->prepare("SELECT * FROM outbound_orders WHERE outbound_order_id = ? FOR UPDATE");
            $stmtOrder->execute([$orderId]);
            $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                throw new InvalidArgumentException("Outbound order #{$orderId} not found.");
            }
            if ($order['status'] === 'cancelled') {
                throw new DomainException("Order '{$order['order_number']}' is already cancelled.");
            }
            if ($order['status'] === 'dispatched') {
                throw new DomainException("Order '{$order['order_number']}' has already been fully dispatched and cannot be cancelled.");
            }

            // Facility check
            if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
                $userWhId = (int)($authUser['warehouse_id'] ?? 0);
                if ($userWhId > 0 && $userWhId !== (int)$order['warehouse_id']) {
                    throw new DomainException("Access Denied: You cannot cancel orders assigned to another warehouse facility.");
                }
            }

            // Fetch line items to release remaining reservations
            $stmtItems = $this->pdo->prepare("SELECT * FROM outbound_order_items WHERE outbound_order_id = ? ORDER BY item_id ASC");
            $stmtItems->execute([$orderId]);
            $items = StockService::sortItemsById($stmtItems->fetchAll(PDO::FETCH_ASSOC));

            $stockService = new StockService($this->pdo);
            $released = [];
            foreach ($items as $item) {
                $remainingReserved = (float)$item['requested_quantity'] - (float)$item['dispatched_quantity'];
                if ($remainingReserved > 0.0001) {
                    $stockService->releaseStockReservation(
                        (int)$item['item_id'],
                        (int)$order['warehouse_id'],
                        $remainingReserved,
                        $order['order_number'],
                        $userId
                    );
                    $released[] = [
                        'item_id'           => (int)$item['item_id'],
                        'quantity_released' => $remainingReserved
                    ];
                }
            }

            $stmtUpd = $this->pdo->prepare("UPDATE outbound_orders SET status = 'cancelled', updated_at = NOW() WHERE outbound_order_id = ?");
            $stmtUpd->execute([$orderId]);

            AccountabilityService::log([
                'user_id'          => $userId,
                'team'             => $authUser['team'] ?? 'Inventory',
                'action_type'      => 'OUTBOUND_ORDER_CANCELLED',
                'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'warehouse_id'     => (int)$order['warehouse_id'],
                'reference_number' => $order['order_number'],
                'notes'            => "Cancelled outbound order {$order['order_number']}. Reason: " . ($reason ?: 'No reason specified') . ". Released reservations for " . count($released) . " item(s)."
            ]);

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'success'           => true,
                'outbound_order_id' => $orderId,
                'order_number'      => $order['order_number'],
                'status'            => 'cancelled',
                'released_items'    => $released
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
