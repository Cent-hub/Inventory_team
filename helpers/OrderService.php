<?php
/*
 * Order management helper. Creates, validates, and cancels inbound and outbound
 * inventory orders while keeping stock reservations in sync.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/StockService.php';
require_once __DIR__ . '/AccountabilityService.php';

class OrderService {
    private PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Database::getConnection();
    }

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

            if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
                $userWhId = (int)($authUser['warehouse_id'] ?? 0);
                if ($userWhId > 0 && $userWhId !== (int)$order['warehouse_id']) {
                    throw new DomainException("Access Denied: You cannot cancel orders assigned to another warehouse facility.");
                }
            }

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

    public function cancelInboundOrder(int $orderId, int $userId, string $reason = '', ?array $authUser = null): array {
        if ($orderId <= 0) {
            throw new InvalidArgumentException("Valid inbound order ID is required.");
        }
        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmtOrder = $this->pdo->prepare("SELECT * FROM inbound_orders WHERE inbound_order_id = ? FOR UPDATE");
            $stmtOrder->execute([$orderId]);
            $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                throw new InvalidArgumentException("Inbound order #{$orderId} not found.");
            }
            if ($order['status'] === 'cancelled') {
                throw new DomainException("Order '{$order['order_number']}' is already cancelled.");
            }
            if ($order['status'] === 'received') {
                throw new DomainException("Order '{$order['order_number']}' has already been fully received and cannot be cancelled.");
            }

            if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
                $userWhId = (int)($authUser['warehouse_id'] ?? 0);
                if ($userWhId > 0 && $userWhId !== (int)$order['warehouse_id']) {
                    throw new DomainException("Access Denied: You cannot reject orders assigned to another warehouse facility.");
                }
            }

            $stmtUpd = $this->pdo->prepare("UPDATE inbound_orders SET status = 'cancelled', updated_at = NOW() WHERE inbound_order_id = ?");
            $stmtUpd->execute([$orderId]);

            if ($order['source_type'] === 'PURCHASE_ORDER') {
                $stmtGr = $this->pdo->prepare("SELECT id, remarks FROM goods_receipts WHERE gr_number = ? FOR UPDATE");
                $stmtGr->execute([$order['order_number']]);
                $gr = $stmtGr->fetch(PDO::FETCH_ASSOC);
                if ($gr) {
                    $grId = (int)$gr['id'];
                    $cleanNote = trim($reason);
                    $stmtUpdGrItems = $this->pdo->prepare("
                        UPDATE goods_receipt_items
                        SET quantity_accepted = 0.00,
                            quantity_rejected = quantity_received,
                            remarks = CASE WHEN ? != '' THEN ? ELSE remarks END
                        WHERE gr_id = ?
                    ");
                    $stmtUpdGrItems->execute([$cleanNote, $cleanNote, $grId]);

                    $newRemarks = $gr['remarks'];
                    if ($cleanNote !== '') {
                        $newRemarks = trim(($newRemarks ? $newRemarks . "\n" : '') . '[Inventory Rejected] ' . $cleanNote);
                    }
                    $stmtUpdGr = $this->pdo->prepare("UPDATE goods_receipts SET status = 'Rejected', remarks = ?, updated_at = NOW() WHERE id = ?");
                    $stmtUpdGr->execute([$newRemarks, $grId]);
                }
            }

            AccountabilityService::log([
                'user_id'          => $userId,
                'team'             => $authUser['team'] ?? 'Inventory',
                'action_type'      => 'INBOUND_ORDER_CANCELLED',
                'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'warehouse_id'     => (int)$order['warehouse_id'],
                'reference_number' => $order['order_number'],
                'notes'            => "Rejected/cancelled inbound order {$order['order_number']}. Reason: " . ($reason ?: 'No reason specified')
            ]);

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'success'          => true,
                'inbound_order_id' => $orderId,
                'order_number'     => $order['order_number'],
                'status'           => 'cancelled'
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
