<?php
/*
 * Core inventory stock service. Handles stock-in, stock-out, warehouse
 * transfers, stock adjustments, damaged goods tracking, and balance calculations
 * inside database transactions.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AccountabilityService.php';

class StockService {
    public const MAX_DECIMAL_QUANTITY = 99999999.9999;
    public const DISCRETE_UNITS = [
        'pcs', 'pc', 'piece', 'pieces',
        'bottle', 'bottles',
        'box', 'boxes',
        'case', 'cases',
        'pack', 'packs',
        'can', 'cans'
    ];
    public const VALID_STOCK_IN_SOURCES = ['PURCHASE_ORDER', 'PRODUCTION_RECEIPT', 'CUSTOMER_RETURN', 'PRODUCTION_RETURN'];
    public const VALID_STOCK_OUT_SOURCES = ['MATERIAL_REQUEST', 'SALES_DELIVERY'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    public static function sortItemsById(array $items): array {
        usort($items, function($a, $b) {
            $idA = (int)($a['item_id'] ?? $a['material_id'] ?? $a['product_id'] ?? 0);
            $idB = (int)($b['item_id'] ?? $b['material_id'] ?? $b['product_id'] ?? 0);
            return $idA <=> $idB;
        });
        return $items;
    }

    private function isExternalDepartmentUser(?array $authUser): bool {
        if (!$authUser) {
            return false;
        }
        $role = strtolower(trim((string)($authUser['role'] ?? '')));
        if ($role === 'super_admin') {
            return false;
        }
        $team = strtolower(trim((string)($authUser['team'] ?? '')));
        return in_array($team, ['procurement', 'production', 'sales'], true);
    }

    public static function validatePositiveQuantity($rawQty, ?string $unit = null, string $fieldLabel = 'quantity'): float {
        if ($rawQty === null || $rawQty === '' || is_bool($rawQty) || is_array($rawQty) || !is_numeric($rawQty)) {
            throw new InvalidArgumentException("Invalid {$fieldLabel}: a valid numeric value greater than 0 is required.");
        }
        $qty = (float)$rawQty;
        if (is_nan($qty) || is_infinite($qty) || $qty <= 0) {
            throw new InvalidArgumentException("Invalid item ID or quantity (must be > 0).");
        }
        if ($qty < 0.001) {
            throw new InvalidArgumentException("Invalid {$fieldLabel}: minimum allowed positive quantity is 0.001.");
        }
        if ($qty > self::MAX_DECIMAL_QUANTITY) {
            throw new InvalidArgumentException("Invalid {$fieldLabel}: exceeds maximum database limit (99,999,999.9999).");
        }
        if (abs($qty - round($qty, 3)) > 0.000001) {
            throw new InvalidArgumentException("Invalid {$fieldLabel}: maximum of 3 decimal places is allowed.");
        }
        if ($unit !== null && in_array(strtolower(trim($unit)), self::DISCRETE_UNITS, true)) {
            if (abs($qty - round($qty)) > 0.000001) {
                throw new InvalidArgumentException(
                    "Fractional quantity ({$qty}) is not allowed for discrete unit '{$unit}'. Quantity must be a whole number."
                );
            }
        }
        return round($qty, 3);
    }

    public static function validateNonNegativeQuantity($rawQty, ?string $unit = null, string $fieldLabel = 'adjusted_quantity'): float {
        if ($rawQty === null || $rawQty === '' || is_bool($rawQty) || is_array($rawQty) || !is_numeric($rawQty)) {
            throw new InvalidArgumentException("A valid numeric {$fieldLabel} is required.");
        }
        $qty = (float)$rawQty;
        if (is_nan($qty) || is_infinite($qty)) {
            throw new InvalidArgumentException("Invalid numeric value for {$fieldLabel}.");
        }
        if ($qty < 0) {
            throw new InvalidArgumentException("Physical adjusted count cannot be negative.");
        }
        if ($qty > 0 && $qty < 0.001) {
            throw new InvalidArgumentException("Invalid {$fieldLabel}: non-zero quantity must be at least 0.001.");
        }
        if ($qty > self::MAX_DECIMAL_QUANTITY) {
            throw new InvalidArgumentException("Invalid {$fieldLabel}: exceeds maximum database limit (99,999,999.9999).");
        }
        if (abs($qty - round($qty, 3)) > 0.000001) {
            throw new InvalidArgumentException("Invalid {$fieldLabel}: maximum of 3 decimal places is allowed.");
        }
        if ($unit !== null && in_array(strtolower(trim($unit)), self::DISCRETE_UNITS, true)) {
            if (abs($qty - round($qty)) > 0.000001) {
                throw new InvalidArgumentException(
                    "Fractional quantity ({$qty}) is not allowed for discrete unit '{$unit}'. Quantity must be a whole number."
                );
            }
        }
        return round($qty, 3);
    }

    public static function validateDateNotFuture(string $dateStr, string $fieldLabel = 'date'): string {
        $dateStr = trim($dateStr);
        $dt = DateTime::createFromFormat('Y-m-d', $dateStr);
        if (!$dt || $dt->format('Y-m-d') !== $dateStr) {
            throw new InvalidArgumentException("Invalid {$fieldLabel} format '{$dateStr}'. Expected YYYY-MM-DD.");
        }
        if ($dateStr > date('Y-m-d')) {
            throw new InvalidArgumentException("Invalid {$fieldLabel} '{$dateStr}': future dates are not allowed.");
        }
        return $dateStr;
    }

    public static function generateUuidV4(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); 
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); 
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function recordStockIn(
        int $warehouseId,
        string $sourceType,
        ?string $sourceReferenceNo,
        array $items,
        int $userId,
        ?string $remarks = null,
        ?array $authUser = null
    ): array {
        if (empty($items)) {
            throw new InvalidArgumentException("At least one item is required for Stock IN.");
        }
        if (!in_array($sourceType, self::VALID_STOCK_IN_SOURCES, true)) {
            throw new InvalidArgumentException(
                "Invalid source_type '{$sourceType}'. Allowed values: " . implode(', ', self::VALID_STOCK_IN_SOURCES)
            );
        }
        if ($sourceReferenceNo !== null && mb_strlen(trim($sourceReferenceNo)) > 100) {
            throw new InvalidArgumentException("source_reference_no cannot exceed 100 characters.");
        }

        if (!$authUser && $userId > 0) {
            $stmtU = $this->pdo->prepare("SELECT role, team, warehouse_id FROM users WHERE user_id = ?");
            $stmtU->execute([$userId]);
            $uRow = $stmtU->fetch(PDO::FETCH_ASSOC);
            if ($uRow) {
                $authUser = $uRow;
            }
        }
        if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
            $userWhId = (int)($authUser['warehouse_id'] ?? 0);
            if ($userWhId > 0 && $userWhId !== $warehouseId) {
                throw new DomainException("Access Denied: You cannot record stock in for a facility other than your assigned warehouse.");
            }
        }

        $stmtWh = $this->pdo->prepare("SELECT name AS warehouse_name FROM warehouses WHERE warehouse_id = ? AND status = 'active'");
        $stmtWh->execute([$warehouseId]);
        $wh = $stmtWh->fetch(PDO::FETCH_ASSOC);
        if (!$wh) {
            throw new InvalidArgumentException("Active warehouse with ID {$warehouseId} not found.");
        }

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            $items = self::sortItemsById($items); 
            $txnNumber = 'IN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $batchUuid = self::generateUuidV4();
            $effectiveRefNo = $sourceReferenceNo ?: $txnNumber;

            $stmtCheckItem = $this->pdo->prepare("
                SELECT item_id, code AS item_code, name AS item_name, type AS item_type, unit, status
                FROM items WHERE item_id = ?
            ");

            $stmtMove = $this->pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, warehouse_id, movement_type, quantity, quantity_delta, balance_after,
                    source_type, reference_id, reference_number, batch_uuid, remarks, created_by, created_at
                ) VALUES (
                    ?, ?, 'STOCK_IN', ?, ?, ?,
                    ?, NULL, ?, ?, ?, ?, NOW()
                )
            ");

            $stmtUpdStock = $this->pdo->prepare("
                INSERT INTO stock (item_id, warehouse_id, qty_on_hand, reorder_level)
                VALUES (?, ?, ?, 0.00)
                ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand)
            ");

            $stmtBal = $this->pdo->prepare("
                SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ?
            ");

            $processedItems = [];
            $seenItemIds = [];
            $firstMovementId = 0;

            foreach ($items as $entry) {
                $rawItemId = $entry['item_id'] ?? $entry['material_id'] ?? $entry['product_id'] ?? null;
                $rawQty = $entry['quantity'] ?? null;

                $itemId = (is_numeric($rawItemId) && (int)$rawItemId == $rawItemId) ? (int)$rawItemId : 0;
                $qty = self::validatePositiveQuantity($rawQty, null, 'quantity');

                if ($itemId <= 0) {
                    throw new InvalidArgumentException("Invalid item ID or quantity (must be > 0).");
                }

                if (isset($seenItemIds[$itemId])) {
                    throw new InvalidArgumentException(
                        "Duplicate item ID {$itemId} in Stock IN request. Combine quantities into a single line item."
                    );
                }
                $seenItemIds[$itemId] = true;

                $stmtCheckItem->execute([$itemId]);
                $itemInfo = $stmtCheckItem->fetch(PDO::FETCH_ASSOC);
                if (!$itemInfo) {
                    throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
                }
                if ($itemInfo['status'] === 'pending') {
                    throw new DomainException("Item '{$itemInfo['item_code']}' is pending Super Admin approval and cannot be used for inventory transactions.");
                }
                if (strtolower($itemInfo['status'] ?? '') !== 'active') {
                    throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
                }

                $qty = self::validatePositiveQuantity($rawQty, $itemInfo['unit'], "quantity for item '{$itemInfo['item_code']}'");

                if ($sourceType === 'PURCHASE_ORDER' && !in_array(strtolower(str_replace(' ', '_', $itemInfo['item_type'] ?? '')), ['raw_material', 'raw'], true)) {
                    throw new InvalidArgumentException(
                        "Procurement PO violation: Item '{$itemInfo['item_code']}' ({$itemInfo['item_name']}) is a finished good. Purchase orders from Procurement can only receive raw materials."
                    );
                }
                if ($sourceType === 'PRODUCTION_RECEIPT' && !in_array(strtolower(str_replace(' ', '_', $itemInfo['item_type'] ?? '')), ['finished_good', 'finished'], true)) {
                    throw new InvalidArgumentException(
                        "Production Receipt violation: Item '{$itemInfo['item_code']}' ({$itemInfo['item_name']}) is a raw material. Production receipts can only receive finished goods."
                    );
                }
                if ($sourceType === 'CUSTOMER_RETURN' && !in_array(strtolower(str_replace(' ', '_', $itemInfo['item_type'] ?? '')), ['finished_good', 'finished'], true)) {
                    throw new InvalidArgumentException(
                        "Customer Return violation: Item '{$itemInfo['item_code']}' ({$itemInfo['item_name']}) is a raw material. Customer returns only apply to finished goods."
                    );
                }

                $movRemarks = "[{$sourceType}: " . ($sourceReferenceNo ?: $txnNumber) . "]" . ($remarks ? " {$remarks}" : "");

                $stmtUpdStock->execute([$itemId, $warehouseId, $qty]);

                $stmtBal->execute([$itemId, $warehouseId]);
                $newQty = (float)($stmtBal->fetchColumn() ?: 0.00);

                $stmtMove->execute([$itemId, $warehouseId, $qty, $qty, $newQty, $sourceType, $effectiveRefNo, $batchUuid, $movRemarks, $userId]);
                $movementId = (int)$this->pdo->lastInsertId();
                if ($firstMovementId === 0) {
                    $firstMovementId = $movementId;
                }

                $processedItems[] = [
                    'item_id'       => $itemId,
                    'item_code'     => $itemInfo['item_code'],
                    'item_name'     => $itemInfo['item_name'],
                    'quantity_in'   => $qty,
                    'unit'          => $itemInfo['unit'],
                    'balance_after' => $newQty
                ];

                $inboundTeam = match($sourceType) {
                    'PURCHASE_ORDER'                      => 'Procurement',
                    'CUSTOMER_RETURN'                     => 'Sales',
                    'PRODUCTION_RETURN', 'PRODUCTION_RECEIPT' => 'Production',
                    default                               => 'Inventory'
                };

                AccountabilityService::log([
                    'user_id'          => $userId,
                    'team'             => $inboundTeam,
                    'action_type'      => 'STOCK_IN',
                    'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                    'item_id'          => $itemId,
                    'quantity'         => $qty,
                    'warehouse_id'     => $warehouseId,
                    'reference_number' => $sourceReferenceNo ?: $txnNumber,
                    'notes'            => $remarks ?: "Inbound stock received ({$sourceType})"
                ]);
            }

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'success'             => true,
                'stock_in_id'         => $firstMovementId,
                'transaction_number'  => $txnNumber,
                'batch_uuid'          => $batchUuid,
                'source_type'         => $sourceType,
                'source_reference_no' => $sourceReferenceNo,
                'warehouse_id'        => $warehouseId,
                'warehouse_name'      => $wh['warehouse_name'],
                'items_count'         => count($processedItems),
                'items'               => $processedItems
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function recordStockOut(
        int $warehouseId,
        string $sourceType,
        ?string $sourceReferenceNo,
        array $items,
        int $userId,
        ?string $remarks = null,
        ?array $authUser = null,
        bool $isReservedDispatch = false
    ): array {
        if (empty($items)) {
            throw new InvalidArgumentException("At least one item is required for Stock OUT.");
        }
        if (!in_array($sourceType, self::VALID_STOCK_OUT_SOURCES, true)) {
            throw new InvalidArgumentException(
                "Invalid source_type '{$sourceType}'. Allowed values: " . implode(', ', self::VALID_STOCK_OUT_SOURCES)
            );
        }
        if ($sourceReferenceNo !== null && mb_strlen(trim($sourceReferenceNo)) > 100) {
            throw new InvalidArgumentException("source_reference_no cannot exceed 100 characters.");
        }

        if (!$authUser && $userId > 0) {
            $stmtU = $this->pdo->prepare("SELECT role, team, warehouse_id FROM users WHERE user_id = ?");
            $stmtU->execute([$userId]);
            $uRow = $stmtU->fetch(PDO::FETCH_ASSOC);
            if ($uRow) {
                $authUser = $uRow;
            }
        }
        if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
            $userWhId = (int)($authUser['warehouse_id'] ?? 0);
            if ($userWhId > 0 && $userWhId !== $warehouseId) {
                throw new DomainException("Access Denied: You cannot record stock out for a facility other than your assigned warehouse.");
            }
        }

        $stmtWh = $this->pdo->prepare("SELECT name AS warehouse_name FROM warehouses WHERE warehouse_id = ? AND status = 'active'");
        $stmtWh->execute([$warehouseId]);
        $wh = $stmtWh->fetch(PDO::FETCH_ASSOC);
        if (!$wh) {
            throw new InvalidArgumentException("Active warehouse with ID {$warehouseId} not found.");
        }

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            $items = self::sortItemsById($items); 
            $txnNumber = 'OUT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $batchUuid = self::generateUuidV4();
            $effectiveRefNo = $sourceReferenceNo ?: $txnNumber;

            $stmtLock = $this->pdo->prepare("
                SELECT qty_on_hand, COALESCE(qty_reserved, 0.000) AS qty_reserved FROM stock 
                WHERE item_id = ? AND warehouse_id = ? 
                FOR UPDATE
            ");

            $stmtCheckItem = $this->pdo->prepare("
                SELECT item_id, code AS item_code, name AS item_name, type AS item_type, unit, status
                FROM items WHERE item_id = ?
            ");

            $validatedEntries = [];
            $seenItemIds = [];

            foreach ($items as $entry) {
                $rawItemId = $entry['item_id'] ?? $entry['material_id'] ?? $entry['product_id'] ?? null;
                $rawQty = $entry['quantity'] ?? null;

                $itemId = (is_numeric($rawItemId) && (int)$rawItemId == $rawItemId) ? (int)$rawItemId : 0;
                $qty = self::validatePositiveQuantity($rawQty, null, 'quantity');

                if ($itemId <= 0) {
                    throw new InvalidArgumentException("Invalid item ID or quantity (must be > 0).");
                }

                if (isset($seenItemIds[$itemId])) {
                    throw new InvalidArgumentException(
                        "Duplicate item ID {$itemId} in Stock OUT request. Combine quantities into a single line item."
                    );
                }
                $seenItemIds[$itemId] = true;

                $stmtCheckItem->execute([$itemId]);
                $itemInfo = $stmtCheckItem->fetch(PDO::FETCH_ASSOC);
                if (!$itemInfo) {
                    throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
                }
                if ($itemInfo['status'] === 'pending') {
                    throw new DomainException("Item '{$itemInfo['item_code']}' is pending Super Admin approval and cannot be used for inventory transactions.");
                }
                if (strtolower($itemInfo['status'] ?? '') !== 'active') {
                    throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
                }

                $qty = self::validatePositiveQuantity($rawQty, $itemInfo['unit'], "quantity for item '{$itemInfo['item_code']}'");

                if ($sourceType === 'MATERIAL_REQUEST' && !in_array(strtolower(str_replace(' ', '_', $itemInfo['item_type'] ?? '')), ['raw_material', 'raw'], true)) {
                    throw new InvalidArgumentException(
                        "Material Request violation: Item '{$itemInfo['item_code']}' is a finished good. Production requests can only consume raw materials."
                    );
                }
                if ($sourceType === 'SALES_DELIVERY' && !in_array(strtolower(str_replace(' ', '_', $itemInfo['item_type'] ?? '')), ['finished_good', 'finished'], true)) {
                    throw new InvalidArgumentException(
                        "Sales Delivery violation: Item '{$itemInfo['item_code']}' is a raw material. Sales can only deliver finished goods."
                    );
                }

                $stmtLock->execute([$itemId, $warehouseId]);
                $stockRow = $stmtLock->fetch(PDO::FETCH_ASSOC);
                $currentOnHand = $stockRow ? (float)$stockRow['qty_on_hand'] : 0.00;
                $currentReserved = $stockRow ? (float)$stockRow['qty_reserved'] : 0.00;

                $available = $isReservedDispatch ? $currentOnHand : max(0.000, $currentOnHand - $currentReserved);

                if ($available < $qty) {
                    $resNote = (!$isReservedDispatch && $currentReserved > 0) ? " (Reserved: {$currentReserved} {$itemInfo['unit']})" : "";
                    throw new InvalidArgumentException(
                        "Insufficient inventory for item '{$itemInfo['item_code']}' ({$itemInfo['item_name']}). Available: {$available} {$itemInfo['unit']}, Requested: {$qty} {$itemInfo['unit']}.{$resNote}"
                    );
                }

                $validatedEntries[] = [
                    'item'              => $itemInfo,
                    'quantity'          => $qty,
                    'current_stock'     => $available,
                    'reserved_released' => $isReservedDispatch ? min($qty, $currentReserved) : 0.0
                ];
            }

            $stmtMove = $this->pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, warehouse_id, movement_type, quantity, quantity_delta, balance_after,
                    source_type, reference_id, reference_number, batch_uuid, remarks, created_by, created_at
                ) VALUES (
                    ?, ?, 'STOCK_OUT', ?, ?, ?,
                    ?, NULL, ?, ?, ?, ?, NOW()
                )
            ");

            $stmtDeductStock = $this->pdo->prepare(
                $isReservedDispatch
                    ? "UPDATE stock SET qty_on_hand = qty_on_hand - ?, qty_reserved = GREATEST(0.000, qty_reserved - ?) WHERE item_id = ? AND warehouse_id = ?"
                    : "UPDATE stock SET qty_on_hand = qty_on_hand - ? WHERE item_id = ? AND warehouse_id = ?"
            );

            $stmtBal = $this->pdo->prepare("
                SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ?
            ");

            $processedItems = [];
            $firstMovementId = 0;

            foreach ($validatedEntries as $entry) {
                $itemInfo = $entry['item'];
                $itemId = (int)$itemInfo['item_id'];
                $qty = (float)$entry['quantity'];

                $resTag = ($entry['reserved_released'] > 0) ? " [ResReleased:" . number_format((float)$entry['reserved_released'], 4, '.', '') . "]" : "";
                $movRemarks = "[{$sourceType}: " . ($sourceReferenceNo ?: $txnNumber) . "]" . ($remarks ? " {$remarks}" : "") . $resTag;

                if ($isReservedDispatch) {
                    $stmtDeductStock->execute([$qty, $qty, $itemId, $warehouseId]);
                } else {
                    $stmtDeductStock->execute([$qty, $itemId, $warehouseId]);
                }

                $stmtBal->execute([$itemId, $warehouseId]);
                $newQty = (float)($stmtBal->fetchColumn() ?: 0.00);

                $stmtMove->execute([$itemId, $warehouseId, $qty, -$qty, $newQty, $sourceType, $effectiveRefNo, $batchUuid, $movRemarks, $userId]);
                $movementId = (int)$this->pdo->lastInsertId();
                if ($firstMovementId === 0) {
                    $firstMovementId = $movementId;
                }

                $processedItems[] = [
                    'item_id'       => $itemId,
                    'item_code'     => $itemInfo['item_code'],
                    'item_name'     => $itemInfo['item_name'],
                    'quantity_out'  => $qty,
                    'unit'          => $itemInfo['unit'],
                    'balance_after' => $newQty
                ];

                $outboundTeam = match($sourceType) {
                    'MATERIAL_REQUEST' => 'Production',
                    'SALES_DELIVERY'   => 'Sales',
                    default            => 'Inventory'
                };

                AccountabilityService::log([
                    'user_id'          => $userId,
                    'team'             => $outboundTeam,
                    'action_type'      => 'STOCK_OUT',
                    'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                    'item_id'          => $itemId,
                    'quantity'         => $qty,
                    'warehouse_id'     => $warehouseId,
                    'reference_number' => $sourceReferenceNo ?: $txnNumber,
                    'notes'            => $remarks ?: "Outbound stock dispatched ({$sourceType})"
                ]);
            }

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'success'             => true,
                'stock_out_id'        => $firstMovementId,
                'transaction_number'  => $txnNumber,
                'batch_uuid'          => $batchUuid,
                'source_type'         => $sourceType,
                'source_reference_no' => $sourceReferenceNo,
                'warehouse_id'        => $warehouseId,
                'warehouse_name'      => $wh['warehouse_name'],
                'items_count'         => count($processedItems),
                'items'               => $processedItems
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function receiveInboundOrder(int $orderId, array $receivedQuantities, int $userId, ?string $remarks = null, ?array $currentUser = null, string $condition = 'salable'): array {
        $stmtOrder = $this->pdo->prepare("SELECT * FROM inbound_orders WHERE inbound_order_id = ? FOR UPDATE");
        $stmtItems = $this->pdo->prepare("SELECT * FROM inbound_order_items WHERE inbound_order_id = ?");

        $this->pdo->beginTransaction();
        try {
            $stmtOrder->execute([$orderId]);
            $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                throw new InvalidArgumentException("Inbound order #{$orderId} not found.");
            }
            if (!$currentUser && $userId > 0) {
                $stmtU = $this->pdo->prepare("SELECT user_id, role, team, warehouse_id FROM users WHERE user_id = ?");
                $stmtU->execute([$userId]);
                $currentUser = $stmtU->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if ($currentUser && ($currentUser['role'] ?? '') !== 'super_admin') {
                if ($this->isExternalDepartmentUser($currentUser)) {
                    throw new DomainException("Access Denied: External department accounts cannot execute physical dock receiving.");
                }
                $userWhId = (int)($currentUser['warehouse_id'] ?? 0);
                if ($userWhId <= 0 || $userWhId !== (int)$order['warehouse_id']) {
                    throw new DomainException("Access Denied: You cannot receive inbound orders assigned to another warehouse facility.");
                }
            }
            if ($order['status'] === 'received') {
                throw new DomainException("Order '{$order['order_number']}' has already been fully received.");
            }
            if ($order['status'] === 'cancelled') {
                throw new DomainException("Order '{$order['order_number']}' is cancelled.");
            }

            $stmtItems->execute([$orderId]);
            $orderItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
            if (empty($orderItems)) {
                throw new DomainException("Order '{$order['order_number']}' has no line items.");
            }

            $itemsToStock = [];
            $allCompleted = true;
            $anyReceived = false;

            $stmtUpdItem = $this->pdo->prepare("UPDATE inbound_order_items SET received_quantity = received_quantity + ? WHERE item_entry_id = ?");

            foreach ($orderItems as $oi) {
                $itemId = (int)$oi['item_id'];
                $qtyToReceive = (float)($receivedQuantities[$itemId] ?? 0);
                if ($qtyToReceive <= 0) {
                    $remaining = (float)$oi['expected_quantity'] - (float)$oi['received_quantity'];
                    if ($remaining > 0.0001) {
                        $allCompleted = false;
                    }
                    continue;
                }

                $remaining = (float)$oi['expected_quantity'] - (float)$oi['received_quantity'];
                if ($qtyToReceive > ($remaining + 0.0001)) {
                    throw new InvalidArgumentException("Received quantity ({$qtyToReceive}) exceeds remaining expected quantity ({$remaining}) for item ID {$itemId}.");
                }

                $stmtUpdItem->execute([$qtyToReceive, $oi['item_entry_id']]);
                $itemsToStock[] = ['item_id' => $itemId, 'quantity' => $qtyToReceive];
                $anyReceived = true;

                if (($remaining - $qtyToReceive) > 0.0001) {
                    $allCompleted = false;
                }
            }

            if (!$anyReceived) {
                throw new InvalidArgumentException("No valid quantities were specified to receive.");
            }

            $newStatus = $allCompleted ? 'received' : 'partially_received';
            $stmtUpdOrder = $this->pdo->prepare("UPDATE inbound_orders SET status = ?, updated_at = NOW() WHERE inbound_order_id = ?");
            $stmtUpdOrder->execute([$newStatus, $orderId]);

            if ($condition === 'damaged') {

                $stmtBal = $this->pdo->prepare("SELECT COALESCE(qty_on_hand, 0.0000) FROM stock WHERE item_id = ? AND warehouse_id = ?");
                $stmtBp = $this->pdo->prepare("
                    INSERT INTO bad_products (
                        item_id, warehouse_id, quantity, reason, status, reported_by, confirmed_by, reported_at, confirmed_at
                    ) VALUES (?, ?, ?, ?, 'confirmed', ?, ?, NOW(), NOW())
                ");
                $stmtMov = $this->pdo->prepare("
                    INSERT INTO stock_movements (
                        item_id, warehouse_id, movement_type, quantity, quantity_delta, balance_after,
                        source_type, reference_type, reference_id, reference_no, reference_number, remarks, created_by, created_at
                    ) VALUES (?, ?, 'BAD_PRODUCT_DISCARD', ?, 0.0000, ?, ?, 'BAD_PRODUCT', ?, ?, ?, ?, ?, NOW())
                ");

                $results = [];
                $firstBpNumber = null;
                $reasonPrefix = "Damaged Inbound ({$order['order_number']})";

                foreach ($itemsToStock as $its) {
                    $itemId = (int)$its['item_id'];
                    $qty = (float)$its['quantity'];
                    $bpReason = mb_substr($reasonPrefix . ($remarks ? ": {$remarks}" : ""), 0, 255);

                    $stmtBal->execute([$itemId, (int)$order['warehouse_id']]);
                    $currentSalableBal = (float)($stmtBal->fetchColumn() ?: 0.0000);

                    $stmtBp->execute([
                        $itemId,
                        (int)$order['warehouse_id'],
                        $qty,
                        $bpReason,
                        $userId,
                        $userId
                    ]);
                    $badProductId = (int)$this->pdo->lastInsertId();
                    $bpNumber = 'BP-' . str_pad((string)$badProductId, 6, '0', STR_PAD_LEFT);
                    if ($firstBpNumber === null) {
                        $firstBpNumber = $bpNumber;
                    }

                    $movRemarks = "[{$order['source_type']}: {$order['order_number']}] Verified Bad Product on Inbound ({$bpNumber})" . ($remarks ? ": {$remarks}" : "");
                    $stmtMov->execute([
                        $itemId,
                        (int)$order['warehouse_id'],
                        $qty,
                        $currentSalableBal,
                        $order['source_type'],
                        $badProductId,
                        $order['order_number'],
                        $bpNumber,
                        $movRemarks,
                        $userId
                    ]);

                    $bpTeam = match ($order['source_type']) {
                        'PURCHASE_ORDER' => 'Procurement',
                        'CUSTOMER_RETURN' => 'Sales',
                        'PRODUCTION_RECEIPT', 'PRODUCTION_RETURN' => 'Production',
                        default => 'Inventory',
                    };
                    AccountabilityService::log([
                        'user_id'          => $userId,
                        'team'             => $bpTeam,
                        'action_type'      => 'BAD_PRODUCT',
                        'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                        'item_id'          => $itemId,
                        'quantity'         => $qty,
                        'warehouse_id'     => (int)$order['warehouse_id'],
                        'reference_number' => $bpNumber,
                        'notes'            => $movRemarks
                    ]);

                    $results[] = [
                        'bad_product_id'     => $badProductId,
                        'bad_product_number' => $bpNumber,
                        'item_id'            => $itemId,
                        'quantity'           => $qty,
                        'status'             => 'confirmed'
                    ];
                }

                if ($order['source_type'] === 'PURCHASE_ORDER') {
                    $this->syncProcurementGoodsReceipt($order['order_number'], $itemsToStock, 'damaged', $remarks);
                } elseif ($order['source_type'] === 'PRODUCTION_RECEIPT') {
                    $cleanNote = $remarks ? trim($remarks) : 'Damaged / defective goods reported by Inventory';
                    $stmtUpdPr = $this->pdo->prepare("
                        UPDATE production_receipts
                        SET remarks = TRIM(BOTH '\n' FROM CONCAT(COALESCE(remarks, ''), '\n[Inventory Damaged Report] ', ?))
                        WHERE receipt_number = ?
                    ");
                    $stmtUpdPr->execute([$cleanNote, $order['order_number']]);
                }

                $this->pdo->commit();

                return [
                    'success'            => true,
                    'order_number'       => $order['order_number'],
                    'new_status'         => $newStatus,
                    'condition'          => 'damaged',
                    'routed_to'          => 'bad_products',
                    'transaction_number' => $firstBpNumber ?? $order['order_number'],
                    'items_received'     => $itemsToStock,
                    'bad_product_ids'    => array_column($results, 'bad_product_id')
                ];
            } else {

                $stockInRes = $this->recordStockIn(
                    (int)$order['warehouse_id'],
                    $order['source_type'],
                    $order['order_number'],
                    $itemsToStock,
                    $userId,
                    $remarks ?: "Received from {$order['entity_name']} ({$order['order_number']})",
                    $currentUser
                );

                if ($order['source_type'] === 'PURCHASE_ORDER') {
                    $this->syncProcurementGoodsReceipt($order['order_number'], $itemsToStock, 'salable', $remarks);
                }

                $this->pdo->commit();

                return [
                    'success'            => true,
                    'order_number'       => $order['order_number'],
                    'new_status'         => $newStatus,
                    'condition'          => 'salable',
                    'transaction_number' => $stockInRes['transaction_number'],
                    'items_received'     => $itemsToStock
                ];
            }
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function syncProcurementGoodsReceipt(string $grNumber, array $items, string $condition, ?string $remarks): void {
        $stmtGr = $this->pdo->prepare("SELECT id, remarks FROM goods_receipts WHERE gr_number = ? FOR UPDATE");
        $stmtGr->execute([$grNumber]);
        $gr = $stmtGr->fetch(PDO::FETCH_ASSOC);
        if (!$gr) {
            return;
        }
        $grId = (int)$gr['id'];
        $cleanNote = $remarks ? trim($remarks) : null;

        if ($condition === 'damaged') {
            $stmtUpdItem = $this->pdo->prepare("
                UPDATE goods_receipt_items
                SET quantity_rejected = ?,
                    quantity_accepted = GREATEST(0, quantity_received - ?),
                    remarks = CASE WHEN ? IS NOT NULL AND ? != '' THEN ? ELSE remarks END
                WHERE gr_id = ? AND item_id = ?
            ");
            foreach ($items as $it) {
                $qty = (float)$it['quantity'];
                $stmtUpdItem->execute([$qty, $qty, $cleanNote, $cleanNote, $cleanNote, $grId, (int)$it['item_id']]);
            }
        } else {
            $stmtUpdItem = $this->pdo->prepare("
                UPDATE goods_receipt_items
                SET quantity_accepted = ?,
                    quantity_rejected = 0.00,
                    remarks = CASE WHEN ? IS NOT NULL AND ? != '' THEN ? ELSE remarks END
                WHERE gr_id = ? AND item_id = ?
            ");
            foreach ($items as $it) {
                $qty = (float)$it['quantity'];
                $stmtUpdItem->execute([$qty, $cleanNote, $cleanNote, $cleanNote, $grId, (int)$it['item_id']]);
            }
        }

        $stmtTotals = $this->pdo->prepare("
            SELECT COALESCE(SUM(quantity_accepted), 0) AS tot_acc,
                   COALESCE(SUM(quantity_rejected), 0) AS tot_rej,
                   COALESCE(SUM(quantity_received), 0) AS tot_rec
            FROM goods_receipt_items
            WHERE gr_id = ?
        ");
        $stmtTotals->execute([$grId]);
        $tot = $stmtTotals->fetch(PDO::FETCH_ASSOC);
        $totAcc = (float)($tot['tot_acc'] ?? 0);
        $totRej = (float)($tot['tot_rej'] ?? 0);
        $totRec = (float)($tot['tot_rec'] ?? 0);

        if ($totRej > 0 && $totAcc > 0) {
            $grStatus = 'Partially Accepted';
        } elseif ($totRej > 0 && $totAcc <= 0) {
            $grStatus = 'Rejected';
        } elseif ($totAcc + 0.0001 >= $totRec) {
            $grStatus = 'Completed';
        } else {
            $grStatus = 'Partially Accepted';
        }

        $newRemarks = $gr['remarks'];
        if ($cleanNote !== null && $cleanNote !== '') {
            $prefix = $condition === 'damaged' ? '[Inventory Damaged Report] ' : '[Inventory Receiving] ';
            $newRemarks = trim(($newRemarks ? $newRemarks . "\n" : '') . $prefix . $cleanNote);
        }

        $stmtUpdGr = $this->pdo->prepare("UPDATE goods_receipts SET status = ?, remarks = ?, updated_at = NOW() WHERE id = ?");
        $stmtUpdGr->execute([$grStatus, $newRemarks, $grId]);
    }

    public function dispatchOutboundOrder(int $orderId, array $dispatchedQuantities, int $userId, ?string $remarks = null, ?array $currentUser = null): array {
        $stmtOrder = $this->pdo->prepare("SELECT * FROM outbound_orders WHERE outbound_order_id = ? FOR UPDATE");
        $stmtItems = $this->pdo->prepare("SELECT * FROM outbound_order_items WHERE outbound_order_id = ?");

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmtOrder->execute([$orderId]);
            $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                throw new InvalidArgumentException("Outbound order #{$orderId} not found.");
            }
            if (!$currentUser && $userId > 0) {
                $stmtU = $this->pdo->prepare("SELECT user_id, role, team, warehouse_id FROM users WHERE user_id = ?");
                $stmtU->execute([$userId]);
                $currentUser = $stmtU->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if ($currentUser && ($currentUser['role'] ?? '') !== 'super_admin') {
                if ($this->isExternalDepartmentUser($currentUser)) {
                    throw new DomainException("Access Denied: External department accounts cannot execute physical outbound dispatch.");
                }
                $userWhId = (int)($currentUser['warehouse_id'] ?? 0);
                if ($userWhId <= 0 || $userWhId !== (int)$order['warehouse_id']) {
                    throw new DomainException("Access Denied: You cannot dispatch outbound orders assigned to another warehouse facility.");
                }
            }
            if ($order['status'] === 'dispatched') {
                throw new DomainException("Order '{$order['order_number']}' has already been fully dispatched.");
            }
            if ($order['status'] === 'cancelled') {
                throw new DomainException("Order '{$order['order_number']}' is cancelled.");
            }

            $stmtItems->execute([$orderId]);
            $orderItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
            if (empty($orderItems)) {
                throw new DomainException("Order '{$order['order_number']}' has no line items.");
            }

            $itemsToDispatch = [];
            $allCompleted = true;
            $anyDispatched = false;

            $stmtUpdItem = $this->pdo->prepare("UPDATE outbound_order_items SET dispatched_quantity = dispatched_quantity + ? WHERE item_entry_id = ?");

            foreach ($orderItems as $oi) {
                $itemId = (int)$oi['item_id'];
                $qtyToDispatch = (float)($dispatchedQuantities[$itemId] ?? 0);
                if ($qtyToDispatch <= 0) {
                    $remaining = (float)$oi['requested_quantity'] - (float)$oi['dispatched_quantity'];
                    if ($remaining > 0.0001) {
                        $allCompleted = false;
                    }
                    continue;
                }

                $remaining = (float)$oi['requested_quantity'] - (float)$oi['dispatched_quantity'];
                if ($qtyToDispatch > ($remaining + 0.0001)) {
                    throw new InvalidArgumentException("Dispatched quantity ({$qtyToDispatch}) exceeds remaining requested quantity ({$remaining}) for item ID {$itemId}.");
                }

                $stmtUpdItem->execute([$qtyToDispatch, $oi['item_entry_id']]);
                $itemsToDispatch[] = ['item_id' => $itemId, 'quantity' => $qtyToDispatch];
                $anyDispatched = true;

                if (($remaining - $qtyToDispatch) > 0.0001) {
                    $allCompleted = false;
                }
            }

            if (!$anyDispatched) {
                throw new InvalidArgumentException("No valid quantities were specified to dispatch.");
            }

            $newStatus = $allCompleted ? 'dispatched' : 'partially_dispatched';
            $stmtUpdOrder = $this->pdo->prepare("UPDATE outbound_orders SET status = ?, updated_at = NOW() WHERE outbound_order_id = ?");
            $stmtUpdOrder->execute([$newStatus, $orderId]);

            $stockOutRes = $this->recordStockOut(
                (int)$order['warehouse_id'],
                $order['source_type'],
                $order['order_number'],
                $itemsToDispatch,
                $userId,
                $remarks ?: "Dispatched to {$order['entity_name']} ({$order['order_number']})",
                $currentUser,
                true
            );

            if ($order['source_type'] === 'MATERIAL_REQUEST') {
                $stmtProdOrder = $this->pdo->prepare("SELECT id FROM production_orders WHERE order_number = ? LIMIT 1");
                $stmtProdOrder->execute([$order['order_number']]);
                $prodOrderId = (int)($stmtProdOrder->fetchColumn() ?: 0);
                if ($prodOrderId > 0) {
                    $stmtUpdProdMat = $this->pdo->prepare("
                        UPDATE production_materials
                        SET issued_qty = LEAST(required_qty, issued_qty + ?),
                            status = CASE
                                WHEN LEAST(required_qty, issued_qty + ?) >= required_qty THEN 'Fully Issued'
                                WHEN LEAST(required_qty, issued_qty + ?) > 0 THEN 'Partially Issued'
                                ELSE status
                            END
                        WHERE prod_order_id = ? AND item_id = ?
                    ");
                    foreach ($itemsToDispatch as $itd) {
                        $qtyVal = (float)$itd['quantity'];
                        $stmtUpdProdMat->execute([$qtyVal, $qtyVal, $qtyVal, $prodOrderId, (int)$itd['item_id']]);
                    }
                    $stmtUpdProdOrd = $this->pdo->prepare("
                        UPDATE production_orders
                        SET status = CASE WHEN status IN ('Draft', 'Planned', 'Approved') THEN 'In Progress' ELSE status END,
                            actual_start = COALESCE(actual_start, NOW()),
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmtUpdProdOrd->execute([$prodOrderId]);
                }
            }

            if ($order['source_type'] === 'SALES_DELIVERY') {
                $stmtSalesOrder = $this->pdo->prepare("SELECT id FROM orders WHERE order_number = ? LIMIT 1");
                $stmtSalesOrder->execute([$order['order_number']]);
                $salesOrderId = (int)($stmtSalesOrder->fetchColumn() ?: 0);
                if ($salesOrderId > 0) {
                    $stmtUpdSalesItem = $this->pdo->prepare("
                        UPDATE order_items 
                        SET quantity_fulfilled = LEAST(quantity, quantity_fulfilled + ?) 
                        WHERE order_id = ? AND item_id = ?
                    ");
                    foreach ($itemsToDispatch as $itd) {
                        $stmtUpdSalesItem->execute([(float)$itd['quantity'], $salesOrderId, (int)$itd['item_id']]);
                    }
                    if ($allCompleted) {
                        $stmtUpdSalesOrd = $this->pdo->prepare("UPDATE orders SET fulfillment_status = 'Completed', updated_at = NOW() WHERE id = ?");
                        $stmtUpdSalesOrd->execute([$salesOrderId]);
                    }
                }
            }

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'success'            => true,
                'order_number'       => $order['order_number'],
                'new_status'         => $newStatus,
                'transaction_number' => $stockOutRes['transaction_number'],
                'items_dispatched'   => $itemsToDispatch
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getInventory(?int $itemId = null, ?string $itemCode = null, ?int $warehouseId = null, ?string $itemType = null): array {
        $sql = "
            SELECT 
                s.stock_id AS inventory_id,
                i.item_id,
                i.code AS item_code,
                i.name AS item_name,
                i.type AS item_type,
                'General' AS category_name,
                i.unit,
                w.warehouse_id,
                w.code AS warehouse_code,
                w.name AS warehouse_name,
                COALESCE(s.qty_on_hand, 0.000) AS current_quantity,
                COALESCE(s.qty_reserved, 0.000) AS reserved_quantity,
                GREATEST(0.000, COALESCE(s.qty_on_hand, 0.000) - COALESCE(s.qty_reserved, 0.000)) AS available_quantity,
                COALESCE(NULLIF(s.reorder_level, 0), i.reorder_level, 0.00) AS effective_reorder_level,
                s.updated_at
            FROM items i
            CROSS JOIN warehouses w ON w.status = 'active'
            LEFT JOIN stock s ON s.item_id = i.item_id AND s.warehouse_id = w.warehouse_id
            WHERE i.status = 'active'
        ";

        $params = [];
        if ($itemId !== null) {
            $sql .= " AND i.item_id = ?";
            $params[] = $itemId;
        }
        if ($itemCode !== null) {
            $sql .= " AND i.code = ?";
            $params[] = $itemCode;
        }
        if ($warehouseId !== null) {
            $sql .= " AND w.warehouse_id = ?";
            $params[] = $warehouseId;
        }
        if ($itemType !== null && in_array($itemType, ['raw_material', 'finished_good'], true)) {
            $sql .= " AND i.type = ?";
            $params[] = $itemType;
        }

        $sql .= " ORDER BY i.name ASC, w.name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getFinishedGoods(
        ?int $warehouseId = null,
        ?string $itemCode = null,
        ?int $itemId = null,
        bool $inStockOnly = false
    ): array {
        $params = [];

        $sql = "
            SELECT 
                i.item_id,
                i.code AS item_code,
                i.name AS item_name,
                'Finished Goods' AS category_name,
                i.unit,
                COALESCE(s.qty_on_hand, 0.000) AS total_quantity,
                COALESCE(s.qty_reserved, 0.000) AS reserved_quantity,
                GREATEST(0.000, COALESCE(s.qty_on_hand, 0.000) - COALESCE(s.qty_reserved, 0.000)) AS available_quantity,
                w.warehouse_id,
                w.code AS warehouse_code,
                w.name AS warehouse_name,
                COALESCE(w.location, '') AS warehouse_location,
                i.status,
                s.updated_at AS last_updated_at
            FROM items i
            CROSS JOIN warehouses w ON w.status = 'active'
            LEFT JOIN stock s ON s.item_id = i.item_id AND s.warehouse_id = w.warehouse_id
            WHERE i.type = 'finished_good'
              AND i.status = 'active'
        ";

        if ($warehouseId !== null) {
            $sql .= " AND w.warehouse_id = ?";
            $params[] = $warehouseId;
        }
        if ($itemCode !== null) {
            $sql .= " AND i.code = ?";
            $params[] = $itemCode;
        }
        if ($itemId !== null) {
            $sql .= " AND i.item_id = ?";
            $params[] = $itemId;
        }
        if ($inStockOnly) {
            $sql .= " AND GREATEST(0.000, COALESCE(s.qty_on_hand, 0) - COALESCE(s.qty_reserved, 0)) > 0";
        }

        $sql .= " ORDER BY i.name ASC, w.name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function reserveStock(int $itemId, int $warehouseId, float $quantity, ?string $reference = null, ?int $userId = null): array {
        if ($itemId <= 0 || $warehouseId <= 0) {
            throw new InvalidArgumentException("Valid itemId and warehouseId are required.");
        }
        $quantity = self::validatePositiveQuantity($quantity, null, 'quantity');

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare("SELECT qty_on_hand, qty_reserved FROM stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE");
            $stmt->execute([$itemId, $warehouseId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $onHand = $row ? (float)$row['qty_on_hand'] : 0.000;
            $reserved = $row ? (float)$row['qty_reserved'] : 0.000;
            $unreserved = max(0.000, $onHand - $reserved);

            if ($quantity > $unreserved) {
                throw new DomainException("Insufficient unreserved stock to place reservation. Available: {$unreserved}, Requested reservation: {$quantity}.");
            }

            $upd = $this->pdo->prepare("
                INSERT INTO stock (item_id, warehouse_id, qty_on_hand, qty_reserved, reorder_level)
                VALUES (?, ?, 0.00, ?, 0.00)
                ON DUPLICATE KEY UPDATE qty_reserved = qty_reserved + VALUES(qty_reserved)
            ");
            $upd->execute([$itemId, $warehouseId, $quantity]);

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'success'           => true,
                'item_id'           => $itemId,
                'warehouse_id'      => $warehouseId,
                'quantity_reserved' => $quantity,
                'new_reserved_qty'  => $reserved + $quantity,
                'reference'         => $reference
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function releaseStockReservation(int $itemId, int $warehouseId, float $quantity, ?string $reference = null, ?int $userId = null): array {
        if ($itemId <= 0 || $warehouseId <= 0) {
            throw new InvalidArgumentException("Valid itemId and warehouseId are required.");
        }
        $quantity = self::validatePositiveQuantity($quantity, null, 'quantity');

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare("SELECT qty_reserved FROM stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE");
            $stmt->execute([$itemId, $warehouseId]);
            $currentReserved = (float)($stmt->fetchColumn() ?: 0.000);

            $newReserved = max(0.000, $currentReserved - $quantity);

            $upd = $this->pdo->prepare("UPDATE stock SET qty_reserved = ? WHERE item_id = ? AND warehouse_id = ?");
            $upd->execute([$newReserved, $itemId, $warehouseId]);

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'success'           => true,
                'item_id'           => $itemId,
                'warehouse_id'      => $warehouseId,
                'quantity_released' => $quantity,
                'new_reserved_qty'  => $newReserved,
                'reference'         => $reference
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getLowStockAlerts(?int $warehouseId = null, ?string $itemType = null): array {
        $sql = "
            SELECT 
                w.warehouse_id,
                w.code AS warehouse_code,
                w.name AS warehouse_name,
                i.item_id,
                i.code AS item_code,
                i.name AS item_name,
                i.type AS item_type,
                NULL AS category_id,
                'General' AS category_name,
                i.unit,
                COALESCE(s.qty_on_hand, 0.000) AS current_quantity,
                COALESCE(NULLIF(s.reorder_level, 0), i.reorder_level, 0.00) AS effective_reorder_level,
                (COALESCE(NULLIF(s.reorder_level, 0), i.reorder_level, 0.00) - COALESCE(s.qty_on_hand, 0.000)) AS deficit_quantity,
                1 AS is_low_stock
            FROM items i
            CROSS JOIN warehouses w ON w.status = 'active'
            LEFT JOIN stock s ON s.item_id = i.item_id AND s.warehouse_id = w.warehouse_id
            WHERE i.status = 'active'
              AND COALESCE(NULLIF(s.reorder_level, 0), i.reorder_level, 0.00) > 0
              AND COALESCE(s.qty_on_hand, 0.000) <= COALESCE(NULLIF(s.reorder_level, 0), i.reorder_level, 0.00)
        ";

        $params = [];
        if ($warehouseId !== null) {
            $sql .= " AND w.warehouse_id = ?";
            $params[] = $warehouseId;
        }
        if ($itemType !== null) {
            $sql .= " AND i.type = ?";
            $params[] = $itemType;
        }

        $sql .= " ORDER BY deficit_quantity DESC, w.name ASC, i.name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function cancelStockIn(int $stockInId, string $cancellationReason, int $cancelledBy, ?array $authUser = null): array {
        if ($stockInId <= 0) {
            throw new InvalidArgumentException("Invalid stock_in_id.");
        }
        $cancellationReason = trim($cancellationReason);
        if (empty($cancellationReason)) {
            throw new InvalidArgumentException("Cancellation reason is required.");
        }
        if (mb_strlen($cancellationReason) > 255) {
            throw new InvalidArgumentException("Cancellation reason cannot exceed 255 characters.");
        }

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare("
                SELECT sm.movement_id, sm.item_id, sm.warehouse_id, sm.quantity, sm.remarks, sm.created_by,
                       sm.batch_uuid, sm.reference_number,
                       i.code AS item_code, i.name AS item_name, i.unit, i.type AS item_type
                FROM stock_movements sm
                JOIN items i ON sm.item_id = i.item_id
                WHERE sm.movement_id = ? AND sm.movement_type = 'STOCK_IN'
                FOR UPDATE
            ");
            $stmt->execute([$stockInId]);
            $txn = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$txn) {
                throw new InvalidArgumentException("Stock IN transaction #{$stockInId} not found.");
            }

            if (str_starts_with($txn['remarks'] ?? '', 'Cancelled Stock')) {
                throw new DomainException("Cannot cancel a cancellation reversal transaction.");
            }

            $batchMovements = [];
            if (!empty($txn['batch_uuid'])) {
                $stmtBatch = $this->pdo->prepare("
                    SELECT sm.movement_id, sm.item_id, sm.warehouse_id, sm.quantity, sm.remarks, sm.created_by,
                           sm.batch_uuid, sm.reference_number,
                           i.code AS item_code, i.name AS item_name, i.unit, i.type AS item_type
                    FROM stock_movements sm
                    JOIN items i ON sm.item_id = i.item_id
                    WHERE sm.batch_uuid = ? AND sm.movement_type = 'STOCK_IN'
                    ORDER BY sm.movement_id ASC
                    FOR UPDATE
                ");
                $stmtBatch->execute([$txn['batch_uuid']]);
                $batchMovements = $stmtBatch->fetchAll(PDO::FETCH_ASSOC);
            }
            if (empty($batchMovements)) {
                $batchMovements = [$txn];
            }

            if ($authUser === null && $cancelledBy > 0) {
                $stmtU = $this->pdo->prepare("SELECT user_id, role, team, warehouse_id FROM users WHERE user_id = ?");
                $stmtU->execute([$cancelledBy]);
                $authUser = $stmtU->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if ($authUser !== null && ($authUser['role'] ?? '') !== 'super_admin') {
                $authUid = (int)($authUser['user_id'] ?? ($authUser['id'] ?? $cancelledBy));
                $userWhId = (int)($authUser['warehouse_id'] ?? 0);
                $isCreator = ($authUid > 0 && (int)$txn['created_by'] === $authUid);
                $isSameWh = ($userWhId > 0 && $userWhId === (int)$txn['warehouse_id']);
                if ($this->isExternalDepartmentUser($authUser)) {
                    if (!$isCreator || !$isSameWh) {
                        throw new DomainException(
                            "Access Denied: Department account #{$authUid} can only cancel Stock IN transactions it created within its assigned warehouse."
                        );
                    }
                } elseif (!$isCreator && !$isSameWh) {
                    throw new DomainException(
                        "Access Denied: Administrator #{$authUid} is not authorized to cancel transactions created for warehouse #{$txn['warehouse_id']}."
                    );
                }
            }

            $stmtChkRev = $this->pdo->prepare("
                SELECT movement_id FROM stock_movements 
                WHERE reference_id = ? AND (movement_type = 'CANCELLED_INBOUND' OR (movement_type = 'STOCK_OUT' AND remarks LIKE 'Cancelled Stock IN %'))
                LIMIT 1
            ");
            foreach ($batchMovements as $bm) {
                $stmtChkRev->execute([$bm['movement_id']]);
                if ($stmtChkRev->fetch()) {
                    throw new DomainException("Stock IN transaction #{$bm['movement_id']} in this batch has already been cancelled.");
                }
            }

            $stmtBal = $this->pdo->prepare("SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE");
            foreach ($batchMovements as $bm) {
                $stmtBal->execute([$bm['item_id'], $bm['warehouse_id']]);
                $currentBal = (float)($stmtBal->fetchColumn() ?: 0.00);
                if ($currentBal < (float)$bm['quantity']) {
                    throw new DomainException(
                        "Cannot cancel Stock IN: Remaining stock on hand ({$currentBal}) for item '{$bm['item_code']}' is less than quantity being reversed ({$bm['quantity']})."
                    );
                }
            }

            $stmtUpd = $this->pdo->prepare("UPDATE stock SET qty_on_hand = qty_on_hand - ? WHERE item_id = ? AND warehouse_id = ?");
            $stmtRev = $this->pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, warehouse_id, movement_type, quantity, quantity_delta, balance_after,
                    reference_id, reference_number, batch_uuid, remarks, created_by, created_at
                ) VALUES (
                    ?, ?, 'CANCELLED_INBOUND', ?, ?, ?,
                    ?, ?, ?, ?, ?, NOW()
                )
            ");

            $cancelledItems = [];
            foreach ($batchMovements as $bm) {
                $itemId = (int)$bm['item_id'];
                $whId   = (int)$bm['warehouse_id'];
                $qty    = (float)$bm['quantity'];
                $bmId   = (int)$bm['movement_id'];

                $stmtUpd->execute([$qty, $itemId, $whId]);

                $stmtBal->execute([$itemId, $whId]);
                $newBal = (float)($stmtBal->fetchColumn() ?: 0.00);

                $revRemarks = "Cancelled Stock IN #{$bmId}: {$cancellationReason}";
                $stmtRev->execute([
                    $itemId, $whId, $qty, -$qty, $newBal,
                    $bmId, ($bm['reference_number'] ?: "IN-{$bmId}"), $bm['batch_uuid'],
                    $revRemarks, $cancelledBy
                ]);

                AccountabilityService::log([
                    'user_id'          => $cancelledBy,
                    'team'             => 'Inventory',
                    'action_type'      => 'STOCK_IN_CANCEL',
                    'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                    'item_id'          => $itemId,
                    'quantity'         => $qty,
                    'warehouse_id'     => $whId,
                    'reference_number' => $bm['reference_number'] ?: "IN-{$bmId}",
                    'notes'            => "Cancelled Stock IN: " . $cancellationReason
                ]);

                $inOrderNum = $bm['reference_number'] ?? '';
                if (!empty($inOrderNum)) {
                    $stmtFindInOrder = $this->pdo->prepare("SELECT inbound_order_id, status FROM inbound_orders WHERE order_number = ? LIMIT 1");
                    $stmtFindInOrder->execute([$inOrderNum]);
                    $inOrd = $stmtFindInOrder->fetch(PDO::FETCH_ASSOC);
                    if ($inOrd) {
                        $ordId = (int)$inOrd['inbound_order_id'];
                        $stmtDecItem = $this->pdo->prepare("
                            UPDATE inbound_order_items 
                            SET received_quantity = GREATEST(0.0000, received_quantity - ?) 
                            WHERE inbound_order_id = ? AND item_id = ?
                        ");
                        $stmtDecItem->execute([$qty, $ordId, $itemId]);

                        $stmtTot = $this->pdo->prepare("SELECT SUM(received_quantity) AS tot_rec, SUM(expected_quantity) AS tot_exp FROM inbound_order_items WHERE inbound_order_id = ?");
                        $stmtTot->execute([$ordId]);
                        $totals = $stmtTot->fetch(PDO::FETCH_ASSOC);
                        $totRec = (float)($totals['tot_rec'] ?? 0);
                        $newOrdStatus = ($totRec <= 0.0001) ? 'pending' : 'partially_received';

                        $stmtUpdOrd = $this->pdo->prepare("UPDATE inbound_orders SET status = ?, updated_at = NOW() WHERE inbound_order_id = ?");
                        $stmtUpdOrd->execute([$newOrdStatus, $ordId]);
                    }
                }

                $cancelledItems[] = [
                    'item_id'            => $itemId,
                    'item_code'          => $bm['item_code'],
                    'item_name'          => $bm['item_name'],
                    'quantity_cancelled' => $qty,
                    'unit'               => $bm['unit'],
                    'balance_after'      => $newBal
                ];
            }

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'success'             => true,
                'stock_in_id'         => $stockInId,
                'transaction_number'  => $txn['reference_number'] ?: "IN-{$stockInId}",
                'batch_uuid'          => $txn['batch_uuid'] ?? null,
                'batch_count'         => count($cancelledItems),
                'status'              => 'cancelled',
                'cancellation_reason' => $cancellationReason,
                'cancelled_by'        => $cancelledBy,
                'items'               => $cancelledItems
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function cancelStockOut(int $stockOutId, string $cancellationReason, int $cancelledBy, ?array $authUser = null): array {
        if ($stockOutId <= 0) {
            throw new InvalidArgumentException("Invalid stock_out_id.");
        }
        $cancellationReason = trim($cancellationReason);
        if (empty($cancellationReason)) {
            throw new InvalidArgumentException("Cancellation reason is required.");
        }
        if (mb_strlen($cancellationReason) > 255) {
            throw new InvalidArgumentException("Cancellation reason cannot exceed 255 characters.");
        }

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare("
                SELECT sm.movement_id, sm.item_id, sm.warehouse_id, sm.quantity, sm.remarks, sm.created_by,
                       sm.batch_uuid, sm.reference_number,
                       i.code AS item_code, i.name AS item_name, i.unit, i.type AS item_type
                FROM stock_movements sm
                JOIN items i ON sm.item_id = i.item_id
                WHERE sm.movement_id = ? AND sm.movement_type = 'STOCK_OUT'
                FOR UPDATE
            ");
            $stmt->execute([$stockOutId]);
            $txn = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$txn) {
                throw new InvalidArgumentException("Stock OUT transaction #{$stockOutId} not found.");
            }

            if (str_starts_with($txn['remarks'] ?? '', 'Cancelled Stock')) {
                throw new DomainException("Cannot cancel a cancellation reversal transaction.");
            }

            $batchMovements = [];
            if (!empty($txn['batch_uuid'])) {
                $stmtBatch = $this->pdo->prepare("
                    SELECT sm.movement_id, sm.item_id, sm.warehouse_id, sm.quantity, sm.remarks, sm.created_by,
                           sm.batch_uuid, sm.reference_number,
                           i.code AS item_code, i.name AS item_name, i.unit, i.type AS item_type
                    FROM stock_movements sm
                    JOIN items i ON sm.item_id = i.item_id
                    WHERE sm.batch_uuid = ? AND sm.movement_type = 'STOCK_OUT'
                    ORDER BY sm.movement_id ASC
                    FOR UPDATE
                ");
                $stmtBatch->execute([$txn['batch_uuid']]);
                $batchMovements = $stmtBatch->fetchAll(PDO::FETCH_ASSOC);
            }
            if (empty($batchMovements)) {
                $batchMovements = [$txn];
            }

            if ($authUser === null && $cancelledBy > 0) {
                $stmtU = $this->pdo->prepare("SELECT user_id, role, team, warehouse_id FROM users WHERE user_id = ?");
                $stmtU->execute([$cancelledBy]);
                $authUser = $stmtU->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if ($authUser !== null && ($authUser['role'] ?? '') !== 'super_admin') {
                $authUid = (int)($authUser['user_id'] ?? ($authUser['id'] ?? $cancelledBy));
                $userWhId = (int)($authUser['warehouse_id'] ?? 0);
                $isCreator = ($authUid > 0 && (int)$txn['created_by'] === $authUid);
                $isSameWh = ($userWhId > 0 && $userWhId === (int)$txn['warehouse_id']);
                if ($this->isExternalDepartmentUser($authUser)) {
                    if (!$isCreator || !$isSameWh) {
                        throw new DomainException(
                            "Access Denied: Department account #{$authUid} can only cancel Stock OUT transactions it created within its assigned warehouse."
                        );
                    }
                } elseif (!$isCreator && !$isSameWh) {
                    throw new DomainException(
                        "Access Denied: Administrator #{$authUid} is not authorized to cancel transactions created for warehouse #{$txn['warehouse_id']}."
                    );
                }
            }

            $stmtChkRev = $this->pdo->prepare("
                SELECT movement_id FROM stock_movements 
                WHERE reference_id = ? AND (movement_type = 'CANCELLED_OUTBOUND' OR (movement_type = 'STOCK_IN' AND remarks LIKE 'Cancelled Stock OUT %'))
                LIMIT 1
            ");
            foreach ($batchMovements as $bm) {
                $stmtChkRev->execute([$bm['movement_id']]);
                if ($stmtChkRev->fetch()) {
                    throw new DomainException("Stock OUT transaction #{$bm['movement_id']} in this batch has already been cancelled.");
                }
            }

            $stmtUpd = $this->pdo->prepare("
                INSERT INTO stock (item_id, warehouse_id, qty_on_hand, reorder_level)
                VALUES (?, ?, ?, 0.00)
                ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand)
            ");
            $stmtRev = $this->pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, warehouse_id, movement_type, quantity, quantity_delta, balance_after,
                    reference_id, reference_number, batch_uuid, remarks, created_by, created_at
                ) VALUES (
                    ?, ?, 'CANCELLED_OUTBOUND', ?, ?, ?,
                    ?, ?, ?, ?, ?, NOW()
                )
            ");
            $stmtBal = $this->pdo->prepare("SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ?");

            $restoredItems = [];
            foreach ($batchMovements as $bm) {
                $itemId = (int)$bm['item_id'];
                $whId   = (int)$bm['warehouse_id'];
                $qty    = (float)$bm['quantity'];
                $bmId   = (int)$bm['movement_id'];

                $stmtUpd->execute([$itemId, $whId, $qty]);

                $stmtBal->execute([$itemId, $whId]);
                $newBal = (float)($stmtBal->fetchColumn() ?: 0.00);

                $revRemarks = "Cancelled Stock OUT #{$bmId}: {$cancellationReason}";
                $stmtRev->execute([
                    $itemId, $whId, $qty, $qty, $newBal,
                    $bmId, ($bm['reference_number'] ?: "OUT-{$bmId}"), $bm['batch_uuid'],
                    $revRemarks, $cancelledBy
                ]);

                AccountabilityService::log([
                    'user_id'          => $cancelledBy,
                    'team'             => 'Inventory',
                    'action_type'      => 'STOCK_OUT_CANCEL',
                    'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                    'item_id'          => $itemId,
                    'quantity'         => $qty,
                    'warehouse_id'     => $whId,
                    'reference_number' => $bm['reference_number'] ?: "OUT-{$bmId}",
                    'notes'            => "Cancelled Stock OUT: " . $cancellationReason
                ]);

                $outOrderNum = $bm['reference_number'] ?? '';
                if (!empty($outOrderNum)) {
                    $stmtFindOutOrder = $this->pdo->prepare("SELECT outbound_order_id, status, warehouse_id FROM outbound_orders WHERE order_number = ? LIMIT 1");
                    $stmtFindOutOrder->execute([$outOrderNum]);
                    $outOrd = $stmtFindOutOrder->fetch(PDO::FETCH_ASSOC);
                    if ($outOrd) {
                        $ordId = (int)$outOrd['outbound_order_id'];
                        $stmtDecItem = $this->pdo->prepare("
                            UPDATE outbound_order_items 
                            SET dispatched_quantity = GREATEST(0.0000, dispatched_quantity - ?) 
                            WHERE outbound_order_id = ? AND item_id = ?
                        ");
                        $stmtDecItem->execute([$qty, $ordId, $itemId]);

                        $stmtTot = $this->pdo->prepare("SELECT SUM(dispatched_quantity) AS tot_disp, SUM(requested_quantity) AS tot_req FROM outbound_order_items WHERE outbound_order_id = ?");
                        $stmtTot->execute([$ordId]);
                        $totals = $stmtTot->fetch(PDO::FETCH_ASSOC);
                        $totDisp = (float)($totals['tot_disp'] ?? 0);
                        $newOrdStatus = ($totDisp <= 0.0001) ? 'pending' : 'partially_dispatched';

                        $stmtUpdOrd = $this->pdo->prepare("UPDATE outbound_orders SET status = ?, updated_at = NOW() WHERE outbound_order_id = ?");
                        $stmtUpdOrd->execute([$newOrdStatus, $ordId]);

                        if (preg_match('/\[ResReleased:([0-9]+(?:\.[0-9]+)?)\]/', (string)($bm['remarks'] ?? ''), $resMatch)) {
                            $restoreQty = min($qty, (float)$resMatch[1]);
                            if ($restoreQty > 0) {
                                $stmtRestoreRes = $this->pdo->prepare("UPDATE stock SET qty_reserved = qty_reserved + ? WHERE item_id = ? AND warehouse_id = ?");
                                $stmtRestoreRes->execute([$restoreQty, $itemId, (int)$outOrd['warehouse_id']]);
                            }
                        }
                    }
                }

                $restoredItems[] = [
                    'item_id'            => $itemId,
                    'item_code'          => $bm['item_code'],
                    'item_name'          => $bm['item_name'],
                    'quantity_restored'  => $qty,
                    'unit'               => $bm['unit'],
                    'balance_after'      => $newBal
                ];
            }

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'success'             => true,
                'stock_out_id'        => $stockOutId,
                'transaction_number'  => $txn['reference_number'] ?: "OUT-{$stockOutId}",
                'batch_uuid'          => $txn['batch_uuid'] ?? null,
                'batch_count'         => count($restoredItems),
                'status'              => 'cancelled',
                'cancellation_reason' => $cancellationReason,
                'cancelled_by'        => $cancelledBy,
                'items'               => $restoredItems
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function recordStockTransfer(
        int $sourceWhId,
        int $destWhId,
        array $items,
        int $userId,
        ?string $remarks = null,
        ?array $authUser = null
    ): array {
        if ($sourceWhId <= 0 || $destWhId <= 0) {
            throw new InvalidArgumentException("Valid source_warehouse_id and destination_warehouse_id are required.");
        }
        if ($sourceWhId === $destWhId) {
            throw new InvalidArgumentException("Source and Destination warehouses must be different.");
        }
        if (empty($items)) {
            throw new InvalidArgumentException("At least one item is required for transfer.");
        }

        if (!$authUser && $userId > 0) {
            $stmtU = $this->pdo->prepare("SELECT role, team, warehouse_id FROM users WHERE user_id = ?");
            $stmtU->execute([$userId]);
            $uRow = $stmtU->fetch(PDO::FETCH_ASSOC);
            if ($uRow) {
                $authUser = $uRow;
            }
        }
        if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
            $userWhId = (int)($authUser['warehouse_id'] ?? 0);
            if ($userWhId > 0 && $userWhId !== $sourceWhId) {
                throw new DomainException("Access Denied: You cannot transfer stock out of a facility other than your assigned warehouse.");
            }
        }

        $stmtWh = $this->pdo->prepare("SELECT warehouse_id, code AS warehouse_code, name AS warehouse_name FROM warehouses WHERE warehouse_id IN (?, ?) AND status = 'active'");
        $stmtWh->execute([$sourceWhId, $destWhId]);
        $whRows = $stmtWh->fetchAll(PDO::FETCH_ASSOC);
        if (count($whRows) < 2) {
            throw new InvalidArgumentException("One or both specified warehouses are invalid or inactive.");
        }

        $whMap = [];
        foreach ($whRows as $w) {
            $whMap[(int)$w['warehouse_id']] = $w;
        }

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            $items = self::sortItemsById($items); 
            $txnNumber = 'TRF-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            $stmtLock = $this->pdo->prepare("
                SELECT qty_on_hand, COALESCE(qty_reserved, 0.000) AS qty_reserved FROM stock 
                WHERE item_id = ? AND warehouse_id = ? 
                FOR UPDATE
            ");

            $stmtCheckItem = $this->pdo->prepare("
                SELECT item_id, code AS item_code, name AS item_name, unit, status 
                FROM items WHERE item_id = ?
            ");

            $stmtTransfer = $this->pdo->prepare("
                INSERT INTO stock_transfers (
                    item_id, source_warehouse_id, destination_warehouse_id,
                    quantity, status, requested_by, requested_at
                ) VALUES (
                    ?, ?, ?,
                    ?, 'in_transit', ?, NOW()
                )
            ");

            $stmtMoveOut = $this->pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, warehouse_id, movement_type, quantity, quantity_delta, balance_after,
                    reference_id, reference_number, remarks, created_by, created_at
                ) VALUES (
                    ?, ?, 'STOCK_TRANSFER_OUT', ?, ?, ?,
                    ?, ?, ?, ?, NOW()
                )
            ");

            $stmtDeductStock = $this->pdo->prepare("
                UPDATE stock 
                SET qty_on_hand = qty_on_hand - ?
                WHERE item_id = ? AND warehouse_id = ?
            ");

            $stmtBal = $this->pdo->prepare("
                SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ?
            ");

            $processedItems = [];
            $seenItemIds = [];
            $firstTransferId = 0;

            foreach ($items as $entry) {
                $rawItemId = $entry['item_id'] ?? $entry['material_id'] ?? $entry['product_id'] ?? null;
                $rawQty = $entry['quantity'] ?? null;

                $itemId = (is_numeric($rawItemId) && (int)$rawItemId == $rawItemId) ? (int)$rawItemId : 0;
                $qty = self::validatePositiveQuantity($rawQty, null, 'quantity');

                if ($itemId <= 0) {
                    throw new InvalidArgumentException("Invalid item ID or quantity (must be > 0).");
                }

                if (isset($seenItemIds[$itemId])) {
                    throw new InvalidArgumentException(
                        "Duplicate item ID {$itemId} in Stock Transfer request. Combine quantities into a single line item."
                    );
                }
                $seenItemIds[$itemId] = true;

                $stmtCheckItem->execute([$itemId]);
                $itemInfo = $stmtCheckItem->fetch(PDO::FETCH_ASSOC);
                if (!$itemInfo) {
                    throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
                }
                if ($itemInfo['status'] === 'pending') {
                    throw new DomainException("Item '{$itemInfo['item_code']}' is pending Super Admin approval and cannot be used for inventory transactions.");
                }
                if (strtolower($itemInfo['status'] ?? '') !== 'active') {
                    throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
                }

                $qty = self::validatePositiveQuantity($rawQty, $itemInfo['unit'], "quantity for item '{$itemInfo['item_code']}'");

                $stmtLock->execute([$itemId, $sourceWhId]);
                $stockRow = $stmtLock->fetch(PDO::FETCH_ASSOC);
                $currentOnHand = $stockRow ? (float)$stockRow['qty_on_hand'] : 0.00;
                $currentReserved = $stockRow ? (float)$stockRow['qty_reserved'] : 0.00;
                $available = max(0.000, $currentOnHand - $currentReserved);

                if ($available < $qty) {
                    $resNote = ($currentReserved > 0) ? " (Reserved: {$currentReserved} {$itemInfo['unit']})" : "";
                    throw new InvalidArgumentException(
                        "Insufficient available (unreserved) inventory in source warehouse '{$whMap[$sourceWhId]['warehouse_name']}' for item '{$itemInfo['item_code']}'. Available: {$available} {$itemInfo['unit']}, Requested: {$qty} {$itemInfo['unit']}.{$resNote}"
                    );
                }

                $stmtTransfer->execute([$itemId, $sourceWhId, $destWhId, $qty, $userId]);
                $transferId = (int)$this->pdo->lastInsertId();
                if ($firstTransferId === 0) {
                    $firstTransferId = $transferId;
                }

                $stmtDeductStock->execute([$qty, $itemId, $sourceWhId]);

                $stmtBal->execute([$itemId, $sourceWhId]);
                $sourceBal = (float)($stmtBal->fetchColumn() ?: 0.00);

                $movRemarks = "Transfer to {$whMap[$destWhId]['warehouse_name']}" . ($remarks ? " - {$remarks}" : "");
                $stmtMoveOut->execute([$itemId, $sourceWhId, $qty, -$qty, $sourceBal, $transferId, "TRF-{$transferId}", $movRemarks, $userId]);

                $processedItems[] = [
                    'transfer_id'            => $transferId,
                    'item_id'                => $itemId,
                    'item_code'              => $itemInfo['item_code'],
                    'item_name'              => $itemInfo['item_name'],
                    'quantity_transferred'   => $qty,
                    'unit'                   => $itemInfo['unit'],
                    'source_balance_after'   => $sourceBal
                ];

                AccountabilityService::log([
                    'user_id'                  => $userId,
                    'team'                     => 'Inventory',
                    'action_type'              => 'TRANSFER_INITIATED',
                    'channel'                  => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                    'item_id'                  => $itemId,
                    'quantity'                 => $qty,
                    'warehouse_id'             => $sourceWhId,
                    'destination_warehouse_id' => $destWhId,
                    'reference_number'         => "TRF-{$transferId}",
                    'notes'                    => $remarks ?: "Transfer initiated from {$whMap[$sourceWhId]['warehouse_name']} to {$whMap[$destWhId]['warehouse_name']}"
                ]);
            }

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'stock_transfer_id'        => $firstTransferId,
                'transaction_number'       => "TRF-{$firstTransferId}",
                'status'                   => 'in_transit',
                'source_warehouse'         => $whMap[$sourceWhId],
                'destination_warehouse'    => $whMap[$destWhId],
                'items_count'              => count($processedItems),
                'items'                    => $processedItems
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function confirmStockTransferReceipt(int $stockTransferId, int $userId, ?array $authUser = null): array {
        if ($stockTransferId <= 0) {
            throw new InvalidArgumentException("Invalid stock_transfer_id.");
        }
        if ($userId <= 0) {
            throw new InvalidArgumentException("Invalid user ID.");
        }

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare("
                SELECT st.*, 
                       sw.name AS source_warehouse_name, sw.code AS source_warehouse_code,
                       dw.name AS dest_warehouse_name, dw.code AS dest_warehouse_code,
                       i.code AS item_code, i.name AS item_name, i.unit, i.type AS item_type
                FROM stock_transfers st
                JOIN warehouses sw ON sw.warehouse_id = st.source_warehouse_id
                JOIN warehouses dw ON dw.warehouse_id = st.destination_warehouse_id
                JOIN items i ON i.item_id = st.item_id
                WHERE st.transfer_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$stockTransferId]);
            $txn = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$txn) {
                throw new InvalidArgumentException("Stock transfer #{$stockTransferId} does not exist.");
            }

            if ($txn['status'] === 'received') {
                throw new DomainException("This stock transfer (TRF-{$stockTransferId}) has already been confirmed and received.");
            }
            if ($txn['status'] === 'cancelled') {
                throw new DomainException("Cannot receive cancelled stock transfer (TRF-{$stockTransferId}).");
            }
            if (!in_array($txn['status'], ['pending', 'in_transit'], true)) {
                throw new DomainException("Stock transfer TRF-{$stockTransferId} is not in transit.");
            }

            if ($authUser !== null && ($authUser['role'] ?? '') !== 'super_admin') {
                $userWhId = (int)($authUser['warehouse_id'] ?? 0);
                if ((int)$txn['destination_warehouse_id'] !== $userWhId) {
                    throw new DomainException(
                        "Access Denied: Only administrators assigned to the destination warehouse ('{$txn['dest_warehouse_name']}') can confirm receipt of this stock transfer."
                    );
                }
            }

            $stmtUser = $this->pdo->prepare("SELECT name FROM users WHERE user_id = ?");
            $stmtUser->execute([$userId]);
            $receiverName = $stmtUser->fetchColumn() ?: 'Warehouse Operator';

            $itemId = (int)$txn['item_id'];
            $destWhId = (int)$txn['destination_warehouse_id'];
            $qty = (float)$txn['quantity'];

            $stmtUpdStock = $this->pdo->prepare("
                INSERT INTO stock (item_id, warehouse_id, qty_on_hand, reorder_level)
                VALUES (?, ?, ?, 0.00)
                ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand)
            ");
            $stmtUpdStock->execute([$itemId, $destWhId, $qty]);

            $stmtBal = $this->pdo->prepare("SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ?");
            $stmtBal->execute([$itemId, $destWhId]);
            $destBal = (float)($stmtBal->fetchColumn() ?: 0.00);

            $movRemarks = "Received transfer from {$txn['source_warehouse_name']}";
            $stmtMoveIn = $this->pdo->prepare("
                INSERT INTO stock_movements (item_id, warehouse_id, movement_type, quantity, quantity_delta, balance_after, reference_id, reference_number, remarks, created_by, created_at)
                VALUES (?, ?, 'STOCK_TRANSFER_IN', ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmtMoveIn->execute([$itemId, $destWhId, $qty, $qty, $destBal, $stockTransferId, "TRF-{$stockTransferId}", $movRemarks, $userId]);

            $stmtUpdate = $this->pdo->prepare("
                UPDATE stock_transfers
                SET status = 'received',
                    received_by = ?,
                    received_at = NOW()
                WHERE transfer_id = ?
            ");
            $stmtUpdate->execute([$userId, $stockTransferId]);

            AccountabilityService::log([
                'user_id'                  => $userId,
                'team'                     => 'Inventory',
                'action_type'              => 'TRANSFER_RECEIVED',
                'channel'                  => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'item_id'                  => $itemId,
                'quantity'                 => $qty,
                'warehouse_id'             => $destWhId,
                'destination_warehouse_id' => (int)$txn['source_warehouse_id'],
                'reference_number'         => "TRF-{$stockTransferId}",
                'notes'                    => "Stock transfer delivery confirmed and received into {$txn['dest_warehouse_name']} from {$txn['source_warehouse_name']}"
            ]);

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'stock_transfer_id'     => $stockTransferId,
                'transaction_number'    => "TRF-{$stockTransferId}",
                'status'                => 'received',
                'source_warehouse'      => $txn['source_warehouse_name'],
                'destination_warehouse' => $txn['dest_warehouse_name'],
                'receiver_name'         => $receiverName,
                'items'                 => [[
                    'item_id'            => $itemId,
                    'item_code'          => $txn['item_code'],
                    'item_name'          => $txn['item_name'],
                    'quantity_received'  => $qty,
                    'unit'               => $txn['unit'],
                    'dest_balance_after' => $destBal
                ]]
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function cancelStockTransfer(int $stockTransferId, string $cancellationReason, int $cancelledBy, ?array $authUser = null): array {
        if ($stockTransferId <= 0) {
            throw new InvalidArgumentException("Invalid stock_transfer_id.");
        }
        $cancellationReason = trim($cancellationReason);
        if (empty($cancellationReason)) {
            throw new InvalidArgumentException("Cancellation reason is required.");
        }
        if (mb_strlen($cancellationReason) > 255) {
            throw new InvalidArgumentException("Cancellation reason cannot exceed 255 characters.");
        }

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare("
                SELECT st.*, 
                       sw.name AS source_warehouse_name,
                       dw.name AS dest_warehouse_name,
                       i.code AS item_code, i.name AS item_name, i.unit
                FROM stock_transfers st
                JOIN warehouses sw ON sw.warehouse_id = st.source_warehouse_id
                JOIN warehouses dw ON dw.warehouse_id = st.destination_warehouse_id
                JOIN items i ON i.item_id = st.item_id
                WHERE st.transfer_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$stockTransferId]);
            $txn = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$txn) {
                throw new InvalidArgumentException("Stock transfer #{$stockTransferId} does not exist.");
            }

            if ($txn['status'] === 'cancelled') {
                throw new DomainException("Stock transfer TRF-{$stockTransferId} is already cancelled.");
            }
            if ($txn['status'] === 'received') {
                throw new DomainException("Cannot cancel stock transfer TRF-{$stockTransferId}: it has already been received at the destination facility.");
            }

            if ($authUser !== null && ($authUser['role'] ?? '') !== 'super_admin') {
                $userWhId = (int)($authUser['warehouse_id'] ?? 0);
                if ((int)$txn['source_warehouse_id'] !== $userWhId && (int)$txn['requested_by'] !== (int)$authUser['user_id']) {
                    throw new DomainException("Access Denied: You are not authorized to cancel this transfer.");
                }
            }

            $itemId   = (int)$txn['item_id'];
            $sourceWhId = (int)$txn['source_warehouse_id'];
            $qty      = (float)$txn['quantity'];

            $stmtUpd = $this->pdo->prepare("UPDATE stock_transfers SET status = 'cancelled' WHERE transfer_id = ?");
            $stmtUpd->execute([$stockTransferId]);

            $stmtRestore = $this->pdo->prepare("
                INSERT INTO stock (item_id, warehouse_id, qty_on_hand, reorder_level)
                VALUES (?, ?, ?, 0.00)
                ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand)
            ");
            $stmtRestore->execute([$itemId, $sourceWhId, $qty]);

            $stmtSrcBal = $this->pdo->prepare("SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ?");
            $stmtSrcBal->execute([$itemId, $sourceWhId]);
            $sourceBalAfter = (float)($stmtSrcBal->fetchColumn() ?: 0.00);

            $revRemarks = "Cancelled Transfer TRF-{$stockTransferId}: {$cancellationReason}";
            $stmtRev = $this->pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, warehouse_id, movement_type, quantity, quantity_delta, balance_after,
                    reference_id, reference_number, remarks, created_by, created_at
                ) VALUES (
                    ?, ?, 'CANCELLED_TRANSFER', ?, ?, ?,
                    ?, ?, ?, ?, NOW()
                )
            ");
            $stmtRev->execute([$itemId, $sourceWhId, $qty, $qty, $sourceBalAfter, $stockTransferId, "TRF-{$stockTransferId}", $revRemarks, $cancelledBy]);

            AccountabilityService::log([
                'user_id'          => $cancelledBy,
                'team'             => 'Inventory',
                'action_type'      => 'TRANSFER_CANCELLED',
                'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'item_id'          => $itemId,
                'quantity'         => $qty,
                'warehouse_id'     => $sourceWhId,
                'reference_number' => "TRF-{$stockTransferId}",
                'notes'            => "Transfer cancelled: " . $cancellationReason
            ]);

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'stock_transfer_id'  => $stockTransferId,
                'transaction_number' => "TRF-{$stockTransferId}",
                'status'             => 'cancelled',
                'cancellation_reason'=> $cancellationReason
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getStockInDetails(int $stockInId, array $authUser): array {
        $stmt = $this->pdo->prepare("
            SELECT sm.movement_id AS stock_in_id,
                   CONCAT('IN-', LPAD(sm.movement_id, 6, '0')) AS transaction_number,
                   sm.warehouse_id, w.code AS warehouse_code, w.name AS warehouse_name,
                   DATE(sm.created_at) AS transaction_date, 'completed' AS status,
                   sm.created_by, u.name AS creator_name, sm.created_at, sm.remarks,
                   i.item_id, i.code AS item_code, i.name AS item_name, i.unit, i.type AS item_type,
                   sm.quantity
            FROM stock_movements sm
            JOIN warehouses w ON w.warehouse_id = sm.warehouse_id
            LEFT JOIN users u ON u.user_id = sm.created_by
            JOIN items i ON i.item_id = sm.item_id
            WHERE sm.movement_id = ? AND sm.movement_type = 'STOCK_IN'
        ");
        $stmt->execute([$stockInId]);
        $txn = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$txn) {
            throw new InvalidArgumentException("Stock IN transaction #{$stockInId} not found.");
        }

        if (($authUser['role'] ?? '') !== 'super_admin') {
            $userWhId = (int)($authUser['warehouse_id'] ?? 0);
            $authUid  = (int)($authUser['user_id'] ?? ($authUser['id'] ?? 0));
            $isSameWarehouse = ($userWhId > 0 && $userWhId === (int)$txn['warehouse_id']);
            $isCreator = ($authUid > 0 && $authUid === (int)$txn['created_by']);

            if ($this->isExternalDepartmentUser($authUser)) {
                if (!$isCreator || !$isSameWarehouse) {
                    throw new DomainException(
                        "Access Denied: Department account #{$authUid} can only view Stock IN transactions it created within its assigned warehouse."
                    );
                }
            } elseif (!$isSameWarehouse && !$isCreator) {
                throw new DomainException(
                    "Access Denied (Facility Scoping): Administrator #{$authUid} is not authorized to view transactions for warehouse facility #{$txn['warehouse_id']}."
                );
            }
        }

        $txn['items'] = [[
            'item_id'   => $txn['item_id'],
            'item_code' => $txn['item_code'],
            'item_name' => $txn['item_name'],
            'unit'      => $txn['unit'],
            'item_type' => $txn['item_type'],
            'quantity'  => $txn['quantity']
        ]];

        return $txn;
    }

    public function getStockOutDetails(int $stockOutId, array $authUser): array {
        $stmt = $this->pdo->prepare("
            SELECT sm.movement_id AS stock_out_id,
                   CONCAT('OUT-', LPAD(sm.movement_id, 6, '0')) AS transaction_number,
                   sm.warehouse_id, w.code AS warehouse_code, w.name AS warehouse_name,
                   DATE(sm.created_at) AS transaction_date, 'completed' AS status,
                   sm.created_by, u.name AS creator_name, sm.created_at, sm.remarks,
                   i.item_id, i.code AS item_code, i.name AS item_name, i.unit, i.type AS item_type,
                   sm.quantity
            FROM stock_movements sm
            JOIN warehouses w ON w.warehouse_id = sm.warehouse_id
            LEFT JOIN users u ON u.user_id = sm.created_by
            JOIN items i ON i.item_id = sm.item_id
            WHERE sm.movement_id = ? AND sm.movement_type = 'STOCK_OUT'
        ");
        $stmt->execute([$stockOutId]);
        $txn = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$txn) {
            throw new InvalidArgumentException("Stock OUT transaction #{$stockOutId} not found.");
        }

        if (($authUser['role'] ?? '') !== 'super_admin') {
            $userWhId = (int)($authUser['warehouse_id'] ?? 0);
            $authUid  = (int)($authUser['user_id'] ?? ($authUser['id'] ?? 0));
            $isSameWarehouse = ($userWhId > 0 && $userWhId === (int)$txn['warehouse_id']);
            $isCreator = ($authUid > 0 && $authUid === (int)$txn['created_by']);

            if ($this->isExternalDepartmentUser($authUser)) {
                if (!$isCreator || !$isSameWarehouse) {
                    throw new DomainException(
                        "Access Denied: Department account #{$authUid} can only view Stock OUT transactions it created within its assigned warehouse."
                    );
                }
            } elseif (!$isSameWarehouse && !$isCreator) {
                throw new DomainException(
                    "Access Denied (Facility Scoping): Administrator #{$authUid} is not authorized to view transactions for warehouse facility #{$txn['warehouse_id']}."
                );
            }
        }

        $txn['items'] = [[
            'item_id'   => $txn['item_id'],
            'item_code' => $txn['item_code'],
            'item_name' => $txn['item_name'],
            'unit'      => $txn['unit'],
            'item_type' => $txn['item_type'],
            'quantity'  => $txn['quantity']
        ]];

        return $txn;
    }

    public function getStockTransferDetails(int $stockTransferId, array $authUser): array {
        $stmt = $this->pdo->prepare("
            SELECT st.transfer_id AS stock_transfer_id,
                   CONCAT('TRF-', LPAD(st.transfer_id, 6, '0')) AS transaction_number,
                   st.source_warehouse_id, sw.code AS source_warehouse_code, sw.name AS source_warehouse_name,
                   st.destination_warehouse_id, dw.code AS dest_warehouse_code, dw.name AS dest_warehouse_name,
                   st.quantity, st.status, st.requested_by, ur.name AS creator_name,
                   st.received_by, rc.name AS receiver_name,
                   st.requested_at AS transaction_date, st.requested_at AS created_at, st.received_at,
                   i.item_id, i.code AS item_code, i.name AS item_name, i.unit, i.type AS item_type
            FROM stock_transfers st
            JOIN warehouses sw ON sw.warehouse_id = st.source_warehouse_id
            JOIN warehouses dw ON dw.warehouse_id = st.destination_warehouse_id
            LEFT JOIN users ur ON ur.user_id = st.requested_by
            LEFT JOIN users rc ON rc.user_id = st.received_by
            JOIN items i ON i.item_id = st.item_id
            WHERE st.transfer_id = ?
        ");
        $stmt->execute([$stockTransferId]);
        $txn = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$txn) {
            throw new InvalidArgumentException("Stock transfer transaction #{$stockTransferId} not found.");
        }

        if (($authUser['role'] ?? '') !== 'super_admin') {
            $userWhId = (int)($authUser['warehouse_id'] ?? 0);
            if ((int)$txn['source_warehouse_id'] !== $userWhId && (int)$txn['destination_warehouse_id'] !== $userWhId) {
                throw new DomainException(
                    "Access Denied: Administrator #{$authUser['user_id']} is not authorized to view transfers unrelated to their assigned warehouse."
                );
            }
        }

        $txn['items'] = [[
            'item_id'   => $txn['item_id'],
            'item_code' => $txn['item_code'],
            'item_name' => $txn['item_name'],
            'unit'      => $txn['unit'],
            'item_type' => $txn['item_type'],
            'quantity'  => $txn['quantity']
        ]];

        return $txn;
    }

    public function listStockIns(array $authUser, int $limit = 50): array {
        $limit = max(1, min(200, $limit));

        $sql = "
            SELECT sm.movement_id AS stock_in_id,
                   COALESCE(sm.reference_number, CONCAT('IN-', LPAD(sm.movement_id, 6, '0'))) AS transaction_number,
                   sm.warehouse_id, w.name AS warehouse_name,
                   DATE(sm.created_at) AS transaction_date, 'completed' AS status,
                   sm.created_by, u.name AS creator_name, sm.created_at, sm.remarks,
                   sm.quantity, i.code AS item_code, i.name AS item_name, i.unit
            FROM stock_movements sm
            JOIN warehouses w ON w.warehouse_id = sm.warehouse_id
            LEFT JOIN users u ON u.user_id = sm.created_by
            JOIN items i ON i.item_id = sm.item_id
            WHERE sm.movement_type = 'STOCK_IN'
        ";

        $params = [];
        if (($authUser['role'] ?? '') !== 'super_admin') {
            $whId = (int)($authUser['warehouse_id'] ?? 0);
            $authUid = (int)($authUser['user_id'] ?? ($authUser['id'] ?? 0));
            if ($this->isExternalDepartmentUser($authUser)) {
                $sql .= " AND sm.created_by = ? AND sm.warehouse_id = ?";
                $params[] = $authUid;
                $params[] = $whId;
            } else {
                $sql .= " AND (sm.warehouse_id = ? OR sm.created_by = ?)";
                $params[] = $whId;
                $params[] = $authUid;
            }
        }

        $sql .= " ORDER BY sm.movement_id DESC LIMIT " . (int)$limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listStockOuts(array $authUser, int $limit = 50): array {
        $limit = max(1, min(200, $limit));

        $sql = "
            SELECT sm.movement_id AS stock_out_id,
                   COALESCE(sm.reference_number, CONCAT('OUT-', LPAD(sm.movement_id, 6, '0'))) AS transaction_number,
                   sm.warehouse_id, w.name AS warehouse_name,
                   DATE(sm.created_at) AS transaction_date, 'completed' AS status,
                   sm.created_by, u.name AS creator_name, sm.created_at, sm.remarks,
                   sm.quantity, i.code AS item_code, i.name AS item_name, i.unit
            FROM stock_movements sm
            JOIN warehouses w ON w.warehouse_id = sm.warehouse_id
            LEFT JOIN users u ON u.user_id = sm.created_by
            JOIN items i ON i.item_id = sm.item_id
            WHERE sm.movement_type = 'STOCK_OUT'
        ";

        $params = [];
        if (($authUser['role'] ?? '') !== 'super_admin') {
            $whId = (int)($authUser['warehouse_id'] ?? 0);
            $authUid = (int)($authUser['user_id'] ?? ($authUser['id'] ?? 0));
            if ($this->isExternalDepartmentUser($authUser)) {
                $sql .= " AND sm.created_by = ? AND sm.warehouse_id = ?";
                $params[] = $authUid;
                $params[] = $whId;
            } else {
                $sql .= " AND (sm.warehouse_id = ? OR sm.created_by = ?)";
                $params[] = $whId;
                $params[] = $authUid;
            }
        }

        $sql .= " ORDER BY sm.movement_id DESC LIMIT " . (int)$limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listStockTransfers(array $authUser, int $limit = 50): array {
        $limit = max(1, min(200, $limit));

        $sql = "
            SELECT st.transfer_id AS stock_transfer_id,
                   CONCAT('TRF-', LPAD(st.transfer_id, 6, '0')) AS transaction_number,
                   st.source_warehouse_id, sw.name AS source_warehouse_name,
                   st.destination_warehouse_id, dw.name AS destination_warehouse_name,
                   DATE(st.requested_at) AS transaction_date, st.status,
                   st.requested_by AS created_by, u.name AS creator_name,
                   st.requested_at AS created_at, st.quantity,
                   i.code AS item_code, i.name AS item_name, i.unit
            FROM stock_transfers st
            JOIN warehouses sw ON sw.warehouse_id = st.source_warehouse_id
            JOIN warehouses dw ON dw.warehouse_id = st.destination_warehouse_id
            LEFT JOIN users u ON u.user_id = st.requested_by
            JOIN items i ON i.item_id = st.item_id
        ";

        if (($authUser['role'] ?? '') !== 'super_admin') {
            $whId = (int)($authUser['warehouse_id'] ?? 0);
            $sql .= " WHERE (st.source_warehouse_id = {$whId} OR st.destination_warehouse_id = {$whId})";
        }

        $sql .= " ORDER BY st.transfer_id DESC LIMIT " . (int)$limit;

        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function recordStockAdjustment(
        int $warehouseId,
        string $adjustmentDate,
        string $reason,
        array $items,
        int $userId,
        ?array $authUser = null
    ): array {
        if (empty($items)) {
            throw new InvalidArgumentException("At least one item is required for Stock Adjustment.");
        }
        $reason = trim($reason);
        if (empty($reason)) {
            throw new InvalidArgumentException("A valid reason or reconciliation note is required.");
        }
        if (mb_strlen($reason) > 255) {
            throw new InvalidArgumentException("Adjustment reason cannot exceed 255 characters.");
        }

        $adjustmentDate = self::validateDateNotFuture($adjustmentDate, 'adjustment_date');

        if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
            $userWhId = (int)($authUser['warehouse_id'] ?? 0);
            if ($userWhId > 0 && $userWhId !== $warehouseId) {
                throw new DomainException("Access Denied: You cannot create stock adjustments for a facility other than your assigned warehouse.");
            }
        }

        $stmtWh = $this->pdo->prepare("SELECT name AS warehouse_name FROM warehouses WHERE warehouse_id = ? AND status = 'active'");
        $stmtWh->execute([$warehouseId]);
        $wh = $stmtWh->fetch(PDO::FETCH_ASSOC);
        if (!$wh) {
            throw new InvalidArgumentException("Active warehouse with ID {$warehouseId} not found.");
        }

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            $items = self::sortItemsById($items); 
            $txnNumber = 'ADJ-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            $stmtCheckItem = $this->pdo->prepare("
                SELECT item_id, code AS item_code, name AS item_name, unit, status FROM items WHERE item_id = ?
            ");
            $stmtEnsureStock = $this->pdo->prepare("
                INSERT IGNORE INTO stock (item_id, warehouse_id, qty_on_hand, reorder_level)
                VALUES (?, ?, 0.0000, 0.0000)
            ");
            $stmtLockStock = $this->pdo->prepare("
                SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE
            ");
            $stmtAdj = $this->pdo->prepare("
                INSERT INTO stock_adjustments (
                    item_id, warehouse_id, previous_quantity, adjusted_quantity,
                    difference, reason, status, requested_by, requested_at, approved_by, approved_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'approved', ?, NOW(), ?, NOW())
            ");
            $stmtUpdStock = $this->pdo->prepare("
                UPDATE stock SET qty_on_hand = ? WHERE item_id = ? AND warehouse_id = ?
            ");
            $stmtMov = $this->pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, warehouse_id, movement_type, quantity, quantity_delta, balance_after,
                    reference_id, reference_number, remarks, created_by, created_at
                ) VALUES (?, ?, 'STOCK_ADJUSTMENT', ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $processedItems = [];
            $seenItemIds = [];
            $firstAdjId = 0;

            foreach ($items as $entry) {
                $rawItemId = $entry['item_id'] ?? null;
                $itemId = (is_numeric($rawItemId) && (int)$rawItemId == $rawItemId) ? (int)$rawItemId : 0;

                if ($itemId <= 0) {
                    throw new InvalidArgumentException("Invalid item ID provided in adjustment.");
                }

                if (isset($seenItemIds[$itemId])) {
                    throw new InvalidArgumentException(
                        "Duplicate item ID {$itemId} in Stock Adjustment request. Each item may only appear once per adjustment."
                    );
                }
                $seenItemIds[$itemId] = true;

                $rawAdjustedQty = $entry['adjusted_quantity'] ?? $entry['quantity'] ?? null;
                $adjustedQty = self::validateNonNegativeQuantity($rawAdjustedQty, null, 'adjusted_quantity');

                $stmtCheckItem->execute([$itemId]);
                $itemInfo = $stmtCheckItem->fetch(PDO::FETCH_ASSOC);
                if (!$itemInfo) {
                    throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
                }
                if ($itemInfo['status'] === 'pending') {
                    throw new DomainException("Item '{$itemInfo['item_code']}' is pending Super Admin approval and cannot be used for inventory transactions.");
                }
                if (strtolower($itemInfo['status'] ?? '') !== 'active') {
                    throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
                }

                $adjustedQty = self::validateNonNegativeQuantity(
                    $rawAdjustedQty,
                    $itemInfo['unit'],
                    "adjusted_quantity for item '{$itemInfo['item_code']}'"
                );

                $stmtEnsureStock->execute([$itemId, $warehouseId]);
                $stmtLockStock->execute([$itemId, $warehouseId]);
                $currentStock = $stmtLockStock->fetchColumn();
                $previousQty = ($currentStock !== false) ? (float)$currentStock : 0.00;

                if (abs($adjustedQty - $previousQty) < 0.0001) {
                    throw new InvalidArgumentException(
                        "No-op stock adjustment rejected for item '{$itemInfo['item_code']}': physical adjusted count ({$adjustedQty}) is identical to current system stock ({$previousQty})."
                    );
                }

                $adjustedQty = round($adjustedQty, 4);
                $diff = round($adjustedQty - $previousQty, 4);

                $stmtAdj->execute([$itemId, $warehouseId, $previousQty, $adjustedQty, $diff, $reason, $userId, $userId]);
                $adjId = (int)$this->pdo->lastInsertId();
                if ($firstAdjId === 0) {
                    $firstAdjId = $adjId;
                }

                $stmtUpdStock->execute([$adjustedQty, $itemId, $warehouseId]);

                $movRemarks = "Adjustment applied: " . $reason;
                $stmtMov->execute([
                    $itemId,
                    $warehouseId,
                    abs($diff),
                    $diff,
                    $adjustedQty,
                    $adjId,
                    "ADJ-{$adjId}",
                    $movRemarks,
                    $userId
                ]);

                $processedItems[] = [
                    'adjustment_id'     => $adjId,
                    'item_id'           => $itemId,
                    'item_code'         => $itemInfo['item_code'],
                    'item_name'         => $itemInfo['item_name'],
                    'previous_quantity' => $previousQty,
                    'adjusted_quantity' => $adjustedQty,
                    'difference'        => $diff,
                    'unit'              => $itemInfo['unit']
                ];

                AccountabilityService::log([
                    'user_id'          => $userId,
                    'team'             => 'Inventory',
                    'action_type'      => 'ADJUSTMENT_APPROVED',
                    'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                    'item_id'          => $itemId,
                    'quantity'         => $diff,
                    'warehouse_id'     => $warehouseId,
                    'reference_number' => "ADJ-{$adjId}",
                    'notes'            => "Adjustment applied: " . $reason . " (Diff: " . ($diff >= 0 ? "+{$diff}" : $diff) . ")"
                ]);
            }

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'stock_adjustment_id' => $firstAdjId,
                'transaction_number'  => "ADJ-{$firstAdjId}",
                'warehouse_id'        => $warehouseId,
                'warehouse_name'      => $wh['warehouse_name'],
                'status'              => 'approved',
                'adjustment_date'     => $adjustmentDate,
                'reason'              => $reason,
                'items_count'         => count($processedItems),
                'items'               => $processedItems
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function approveStockAdjustment(int $adjustmentId, int $userId, ?array $authUser = null): array {
        if ($adjustmentId <= 0) {
            throw new InvalidArgumentException("Invalid adjustment ID.");
        }

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare("
                SELECT sa.*, i.code AS item_code, i.name AS item_name, i.unit, w.name AS warehouse_name
                FROM stock_adjustments sa
                JOIN items i ON i.item_id = sa.item_id
                JOIN warehouses w ON w.warehouse_id = sa.warehouse_id
                WHERE sa.adjustment_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$adjustmentId]);
            $adj = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$adj) {
                throw new InvalidArgumentException("Stock adjustment #{$adjustmentId} not found.");
            }

            if ($adj['status'] !== 'pending') {
                throw new DomainException("Cannot approve adjustment #{$adjustmentId}: current status is '{$adj['status']}'.");
            }

            if (!$authUser && $userId > 0) {
                $stmtU = $this->pdo->prepare("SELECT role, team, warehouse_id FROM users WHERE user_id = ?");
                $stmtU->execute([$userId]);
                $uRow = $stmtU->fetch(PDO::FETCH_ASSOC);
                if ($uRow) {
                    $authUser = $uRow;
                }
            }
            if (($authUser['role'] ?? '') !== 'super_admin') {
                throw new DomainException("Access Denied: Only super_admin can approve stock adjustments.");
            }

            $stmtEnsureStock = $this->pdo->prepare("
                INSERT IGNORE INTO stock (item_id, warehouse_id, qty_on_hand, reorder_level)
                VALUES (?, ?, 0.0000, 0.0000)
            ");
            $stmtEnsureStock->execute([(int)$adj['item_id'], (int)$adj['warehouse_id']]);

            $stmtLockStock = $this->pdo->prepare("
                SELECT qty_on_hand FROM stock
                WHERE item_id = ? AND warehouse_id = ?
                FOR UPDATE
            ");
            $stmtLockStock->execute([(int)$adj['item_id'], (int)$adj['warehouse_id']]);
            $currentOnHand = (float)($stmtLockStock->fetchColumn() ?: 0.0000);
            $expectedPrev = (float)$adj['previous_quantity'];

            if (abs($currentOnHand - $expectedPrev) > 0.0001) {
                throw new DomainException(
                    "Stale adjustment conflict: On-hand stock for '{$adj['item_code']}' changed from {$expectedPrev} to {$currentOnHand} while adjustment #{$adjustmentId} was pending. Reject or cancel this stale request and submit a fresh physical count."
                );
            }

            $adjustedQty = round((float)$adj['adjusted_quantity'], 4);
            $diff = round($adjustedQty - $currentOnHand, 4);

            $stmtAppr = $this->pdo->prepare("
                UPDATE stock_adjustments
                SET status = 'approved', approved_by = ?, approved_at = NOW(), difference = ?
                WHERE adjustment_id = ?
            ");
            $stmtAppr->execute([$userId, $diff, $adjustmentId]);

            $stmtUpdStock = $this->pdo->prepare("
                UPDATE stock SET qty_on_hand = ? WHERE item_id = ? AND warehouse_id = ?
            ");
            $stmtUpdStock->execute([$adjustedQty, (int)$adj['item_id'], (int)$adj['warehouse_id']]);

            $movRemarks = "Adjustment approved: " . $adj['reason'];
            $stmtMov = $this->pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, warehouse_id, movement_type, quantity, quantity_delta, balance_after,
                    reference_id, reference_number, remarks, created_by, created_at
                ) VALUES (?, ?, 'STOCK_ADJUSTMENT', ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmtMov->execute([
                (int)$adj['item_id'],
                (int)$adj['warehouse_id'],
                abs($diff),
                $diff,
                $adjustedQty,
                $adjustmentId,
                "ADJ-{$adjustmentId}",
                $movRemarks,
                $userId
            ]);

            AccountabilityService::log([
                'user_id'          => $userId,
                'team'             => 'Inventory',
                'action_type'      => 'ADJUSTMENT_APPROVED',
                'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'item_id'          => (int)$adj['item_id'],
                'quantity'         => $diff,
                'warehouse_id'     => (int)$adj['warehouse_id'],
                'reference_number' => "ADJ-{$adjustmentId}",
                'notes'            => "Adjustment approved: " . $adj['reason']
            ]);

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'stock_adjustment_id' => $adjustmentId,
                'transaction_number'  => "ADJ-{$adjustmentId}",
                'status'              => 'approved',
                'difference'          => $diff
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function rejectStockAdjustment(int $adjustmentId, int $userId, string $reason, ?array $authUser = null): array {
        if ($adjustmentId <= 0) {
            throw new InvalidArgumentException("Invalid adjustment ID.");
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException("A rejection reason is required.");
        }

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            if (!$authUser && $userId > 0) {
                $stmtU = $this->pdo->prepare("SELECT role, team, warehouse_id FROM users WHERE user_id = ?");
                $stmtU->execute([$userId]);
                $authUser = $stmtU->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (($authUser['role'] ?? '') !== 'super_admin') {
                throw new DomainException("Access Denied: Only super_admin can reject stock adjustments.");
            }

            $stmt = $this->pdo->prepare("SELECT * FROM stock_adjustments WHERE adjustment_id = ? FOR UPDATE");
            $stmt->execute([$adjustmentId]);
            $adj = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$adj) {
                throw new InvalidArgumentException("Stock adjustment #{$adjustmentId} not found.");
            }
            if ($adj['status'] !== 'pending') {
                throw new DomainException("Cannot reject adjustment #{$adjustmentId}: current status is '{$adj['status']}'.");
            }

            $stmtUpd = $this->pdo->prepare("
                UPDATE stock_adjustments
                SET status = 'rejected', approved_by = ?, approved_at = NOW()
                WHERE adjustment_id = ?
            ");
            $stmtUpd->execute([$userId, $adjustmentId]);

            AccountabilityService::log([
                'user_id'          => $userId,
                'team'             => 'Inventory',
                'action_type'      => 'ADJUSTMENT_REJECTED',
                'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'item_id'          => (int)$adj['item_id'],
                'quantity'         => (float)$adj['difference'],
                'warehouse_id'     => (int)$adj['warehouse_id'],
                'reference_number' => "ADJ-{$adjustmentId}",
                'notes'            => "Adjustment rejected: " . $reason
            ]);

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'stock_adjustment_id' => $adjustmentId,
                'transaction_number'  => "ADJ-{$adjustmentId}",
                'status'              => 'rejected'
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function cancelStockAdjustment(int $adjustmentId, string $reason, int $userId, ?array $authUser = null): array {
        if ($adjustmentId <= 0) {
            throw new InvalidArgumentException("Invalid adjustment ID.");
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException("A cancellation reason is required.");
        }
        if (mb_strlen($reason) > 255) {
            throw new InvalidArgumentException("Cancellation reason cannot exceed 255 characters.");
        }

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            if (!$authUser && $userId > 0) {
                $stmtU = $this->pdo->prepare("SELECT role, team, warehouse_id FROM users WHERE user_id = ?");
                $stmtU->execute([$userId]);
                $authUser = $stmtU->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (($authUser['role'] ?? '') !== 'super_admin') {
                throw new DomainException("Access Denied: Only super_admin can cancel stock adjustments.");
            }

            $stmt = $this->pdo->prepare("SELECT * FROM stock_adjustments WHERE adjustment_id = ? FOR UPDATE");
            $stmt->execute([$adjustmentId]);
            $adj = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$adj) {
                throw new InvalidArgumentException("Stock adjustment #{$adjustmentId} not found.");
            }
            if (!in_array($adj['status'], ['pending', 'approved'], true)) {
                throw new DomainException("Cannot cancel adjustment #{$adjustmentId}: current status is '{$adj['status']}'.");
            }

            $priorStatus = $adj['status'];
            $reverseDelta = 0.0;
            $newBalance = null;

            if ($priorStatus === 'approved') {
                $itemId = (int)$adj['item_id'];
                $whId = (int)$adj['warehouse_id'];

                $stmtLockStock = $this->pdo->prepare("
                    SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE
                ");
                $stmtLockStock->execute([$itemId, $whId]);
                $currentOnHand = (float)($stmtLockStock->fetchColumn() ?: 0.0000);

                $reverseDelta = round(-1 * (float)$adj['difference'], 4);
                $computedBal = round($currentOnHand + $reverseDelta, 4);

                if ($computedBal < -0.0001) {
                    throw new DomainException(
                        "Cannot cancel approved adjustment #{$adjustmentId}: reversing adjustment delta ({$adj['difference']}) would result in negative stock ({$computedBal}). Current on-hand is {$currentOnHand}."
                    );
                }
                $newBalance = max(0.0, $computedBal);

                $stmtUpdStock = $this->pdo->prepare("
                    INSERT INTO stock (item_id, warehouse_id, qty_on_hand, reorder_level)
                    VALUES (?, ?, ?, 0.0000)
                    ON DUPLICATE KEY UPDATE qty_on_hand = VALUES(qty_on_hand)
                ");
                $stmtUpdStock->execute([$itemId, $whId, $newBalance]);

                $movRemarks = "Adjustment ADJ-{$adjustmentId} cancelled: " . $reason;
                $stmtMov = $this->pdo->prepare("
                    INSERT INTO stock_movements (
                        item_id, warehouse_id, movement_type, quantity, quantity_delta, balance_after,
                        reference_id, reference_number, remarks, created_by, created_at
                    ) VALUES (?, ?, 'CANCELLED_ADJUSTMENT', ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmtMov->execute([
                    $itemId,
                    $whId,
                    abs($reverseDelta),
                    $reverseDelta,
                    $newBalance,
                    $adjustmentId,
                    "ADJ-{$adjustmentId}",
                    $movRemarks,
                    $userId
                ]);
            }

            $stmtUpd = $this->pdo->prepare("UPDATE stock_adjustments SET status = 'cancelled' WHERE adjustment_id = ?");
            $stmtUpd->execute([$adjustmentId]);

            AccountabilityService::log([
                'user_id'          => $userId,
                'team'             => 'Inventory',
                'action_type'      => 'ADJUSTMENT_CANCELLED',
                'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'item_id'          => (int)$adj['item_id'],
                'quantity'         => $priorStatus === 'approved' ? $reverseDelta : (float)$adj['difference'],
                'warehouse_id'     => (int)$adj['warehouse_id'],
                'reference_number' => "ADJ-{$adjustmentId}",
                'notes'            => "Adjustment ({$priorStatus}) cancelled: " . $reason
            ]);

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'stock_adjustment_id' => $adjustmentId,
                'transaction_number'  => "ADJ-{$adjustmentId}",
                'status'              => 'cancelled',
                'prior_status'        => $priorStatus,
                'reversed_delta'      => $reverseDelta,
                'balance_after'       => $newBalance
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getStockAdjustmentDetails(int $adjustmentId, ?array $authUser = null): array {
        $stmt = $this->pdo->prepare("
            SELECT sa.adjustment_id AS stock_adjustment_id,
                   CONCAT('ADJ-', LPAD(sa.adjustment_id, 6, '0')) AS transaction_number,
                   sa.warehouse_id, w.code AS warehouse_code, w.name AS warehouse_name,
                   DATE(sa.requested_at) AS adjustment_date, sa.reason, sa.status,
                   sa.requested_by, ur.name AS creator_name,
                   sa.approved_by, ua.name AS approved_by_name,
                   sa.requested_at AS created_at,
                   sa.previous_quantity, sa.adjusted_quantity, sa.difference,
                   i.item_id, i.code AS item_code, i.name AS item_name, i.unit, i.type AS item_type
            FROM stock_adjustments sa
            JOIN warehouses w ON w.warehouse_id = sa.warehouse_id
            LEFT JOIN users ur ON ur.user_id = sa.requested_by
            LEFT JOIN users ua ON ua.user_id = sa.approved_by
            JOIN items i ON i.item_id = sa.item_id
            WHERE sa.adjustment_id = ?
        ");
        $stmt->execute([$adjustmentId]);
        $adj = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$adj) {
            throw new InvalidArgumentException("Stock adjustment #{$adjustmentId} not found.");
        }

        if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
            $userWhId = (int)($authUser['warehouse_id'] ?? 0);
            if ($userWhId > 0 && $userWhId !== (int)$adj['warehouse_id']) {
                throw new DomainException("Access Denied: You are not authorized to view adjustments for other warehouses.");
            }
        }

        $adj['items'] = [[
            'item_id'           => $adj['item_id'],
            'item_code'         => $adj['item_code'],
            'item_name'         => $adj['item_name'],
            'previous_quantity' => $adj['previous_quantity'],
            'adjusted_quantity' => $adj['adjusted_quantity'],
            'difference'        => $adj['difference'],
            'unit'              => $adj['unit']
        ]];

        return $adj;
    }

    public function recordBadProduct(
        int $warehouseId,
        int $itemId,
        string $conditionType,
        float $quantity,
        string $reason,
        int $userId,
        ?array $authUser = null
    ): array {
        if ($warehouseId <= 0 || $itemId <= 0) {
            throw new InvalidArgumentException("Valid warehouse_id and item_id are required.");
        }
        if ($quantity <= 0) {
            throw new InvalidArgumentException("Quantity of damaged goods must be greater than zero.");
        }
        $quantity = self::validatePositiveQuantity($quantity, null, 'quantity');

        $reason = trim($reason);
        if (empty($reason)) {
            throw new InvalidArgumentException("A detailed reason or defect note is required.");
        }
        if (mb_strlen($reason) > 255) {
            throw new InvalidArgumentException("Defect reason cannot exceed 255 characters.");
        }

        if (!$authUser && $userId > 0) {
            $stmtU = $this->pdo->prepare("SELECT role, team, warehouse_id FROM users WHERE user_id = ?");
            $stmtU->execute([$userId]);
            $authUser = $stmtU->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
            $userWhId = (int)($authUser['warehouse_id'] ?? 0);
            if ($userWhId > 0 && $userWhId !== $warehouseId) {
                throw new DomainException("Access Denied: You cannot report damaged goods for another warehouse.");
            }
        }

        $stmtWh = $this->pdo->prepare("SELECT name AS warehouse_name FROM warehouses WHERE warehouse_id = ? AND status = 'active'");
        $stmtWh->execute([$warehouseId]);
        if (!$stmtWh->fetch(PDO::FETCH_ASSOC)) {
            throw new InvalidArgumentException("Active warehouse with ID {$warehouseId} not found.");
        }

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmtItem = $this->pdo->prepare("SELECT code AS item_code, name AS item_name, unit, status FROM items WHERE item_id = ?");
            $stmtItem->execute([$itemId]);
            $item = $stmtItem->fetch(PDO::FETCH_ASSOC);
            if (!$item || strtolower($item['status'] ?? '') !== 'active') {
                throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
            }

            $quantity = self::validatePositiveQuantity($quantity, $item['unit'], "quantity for item '{$item['item_code']}'");

            $stmtLock = $this->pdo->prepare("SELECT qty_on_hand, COALESCE(qty_reserved, 0.000) AS qty_reserved FROM stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE");
            $stmtLock->execute([$itemId, $warehouseId]);
            $stockRow = $stmtLock->fetch(PDO::FETCH_ASSOC);
            $currentOnHand = $stockRow ? (float)$stockRow['qty_on_hand'] : 0.00;
            $currentReserved = $stockRow ? (float)$stockRow['qty_reserved'] : 0.00;
            $available = max(0.000, $currentOnHand - $currentReserved);

            if ($quantity > $available) {
                $resNote = ($currentReserved > 0) ? " (Reserved: {$currentReserved} {$item['unit']})" : "";
                throw new DomainException(
                    "Insufficient available (unreserved) stock: Cannot write off {$quantity} units of '{$item['item_name']}'. Only {$available} units currently available in this warehouse.{$resNote}"
                );
            }

            $stmtBp = $this->pdo->prepare("
                INSERT INTO bad_products (
                    item_id, warehouse_id, quantity, reason, status, reported_by, reported_at, confirmed_by, confirmed_at
                ) VALUES (?, ?, ?, ?, 'confirmed', ?, NOW(), ?, NOW())
            ");
            $stmtBp->execute([$itemId, $warehouseId, $quantity, $reason, $userId, $userId]);
            $badProductId = (int)$this->pdo->lastInsertId();

            $stmtDeduct = $this->pdo->prepare("UPDATE stock SET qty_on_hand = qty_on_hand - ? WHERE item_id = ? AND warehouse_id = ?");
            $stmtDeduct->execute([$quantity, $itemId, $warehouseId]);
            $newStockBal = round($currentOnHand - $quantity, 4);

            $movRemarks = "Defect write-off: [{$conditionType}] " . $reason;
            $stmtMov = $this->pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, warehouse_id, movement_type, quantity, quantity_delta, balance_after,
                    reference_id, reference_number, remarks, created_by, created_at
                ) VALUES (?, ?, 'BAD_PRODUCT_DISCARD', ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmtMov->execute([$itemId, $warehouseId, $quantity, -$quantity, $newStockBal, $badProductId, "BP-{$badProductId}", $movRemarks, $userId]);

            AccountabilityService::log([
                'user_id'          => $userId,
                'team'             => 'Inventory',
                'action_type'      => 'BAD_PRODUCT',
                'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'item_id'          => $itemId,
                'quantity'         => $quantity,
                'warehouse_id'     => $warehouseId,
                'reference_number' => "BP-{$badProductId}",
                'notes'            => "Defect write-off: [{$conditionType}] " . $reason
            ]);

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'bad_product_id'     => $badProductId,
                'bad_product_number' => "BP-{$badProductId}",
                'item_id'            => $itemId,
                'item_code'          => $item['item_code'],
                'item_name'          => $item['item_name'],
                'warehouse_id'       => $warehouseId,
                'condition_type'     => strtolower($conditionType),
                'quantity'           => $quantity,
                'unit'               => $item['unit'],
                'status'             => 'confirmed'
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function cancelBadProduct(int $badProductId, string $reason, int $userId, ?array $authUser = null): array {
        if ($badProductId <= 0) {
            throw new InvalidArgumentException("Invalid bad_product_id.");
        }
        $reason = trim($reason);
        if (empty($reason)) {
            throw new InvalidArgumentException("A reason is required to cancel a damaged product write-off.");
        }
        if (mb_strlen($reason) > 255) {
            throw new InvalidArgumentException("Cancellation reason cannot exceed 255 characters.");
        }

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM bad_products WHERE bad_product_id = ? FOR UPDATE");
            $stmt->execute([$badProductId]);
            $bp = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$bp) {
                throw new InvalidArgumentException("Bad product record #{$badProductId} not found.");
            }
            if ($bp['status'] === 'cancelled') {
                throw new DomainException("Bad product write-off BP-{$badProductId} is already cancelled.");
            }
            if ($bp['status'] === 'confirmed' || $bp['status'] === 'disposed') {
                throw new DomainException("Cannot cancel bad product record: Record has already been {$bp['status']}.");
            }
            if (stripos($bp['reason'] ?? '', 'Damaged Customer Return') !== false || stripos($bp['reason'] ?? '', 'Customer RMA') !== false) {
                throw new DomainException("Cannot cancel bad product record: Damaged customer returns cannot be restored to salable inventory.");
            }

            if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
                $userWhId = (int)($authUser['warehouse_id'] ?? 0);
                if ($userWhId > 0 && $userWhId !== (int)$bp['warehouse_id']) {
                    throw new DomainException("Access Denied: You are not authorized to cancel bad product records for other warehouses.");
                }
            }

            $stmtCancel = $this->pdo->prepare("UPDATE bad_products SET status = 'cancelled' WHERE bad_product_id = ?");
            $stmtCancel->execute([$badProductId]);

            $bpItemId = (int)$bp['item_id'];
            $bpWhId   = (int)$bp['warehouse_id'];
            $bpQty    = (float)$bp['quantity'];
            $stmtRestore = $this->pdo->prepare("
                INSERT INTO stock (item_id, warehouse_id, qty_on_hand, reorder_level)
                VALUES (?, ?, ?, 0.00)
                ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand)
            ");
            $stmtRestore->execute([$bpItemId, $bpWhId, $bpQty]);

            $stmtBpBal = $this->pdo->prepare("SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ?");
            $stmtBpBal->execute([$bpItemId, $bpWhId]);
            $bpNewBal = (float)($stmtBpBal->fetchColumn() ?: 0.00);

            $movRemarks = "Defect write-off cancelled: " . $reason;
            $stmtMov = $this->pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, warehouse_id, movement_type, quantity, quantity_delta, balance_after,
                    reference_id, reference_number, remarks, created_by, created_at
                ) VALUES (?, ?, 'CANCELLED_BAD_PRODUCT', ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmtMov->execute([$bpItemId, $bpWhId, $bpQty, $bpQty, $bpNewBal, $badProductId, "BP-{$badProductId}", $movRemarks, $userId]);

            AccountabilityService::log([
                'user_id'          => $userId,
                'team'             => 'Inventory',
                'action_type'      => 'BAD_PRODUCT_CANCEL',
                'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'item_id'          => (int)$bp['item_id'],
                'quantity'         => (float)$bp['quantity'],
                'warehouse_id'     => (int)$bp['warehouse_id'],
                'reference_number' => "BP-{$badProductId}",
                'notes'            => "Defect write-off cancelled: " . $reason
            ]);

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'bad_product_id'     => $badProductId,
                'bad_product_number' => "BP-{$badProductId}",
                'status'             => 'cancelled'
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getBadProductDetails(int $badProductId, ?array $authUser = null): array {
        $stmt = $this->pdo->prepare("
            SELECT bp.bad_product_id,
                   CONCAT('BP-', LPAD(bp.bad_product_id, 6, '0')) AS bad_product_number,
                   'damaged' AS condition_type,
                   bp.warehouse_id, w.code AS warehouse_code, w.name AS warehouse_name,
                   bp.item_id, i.code AS item_code, i.name AS item_name, i.type AS item_type, i.unit,
                   bp.quantity, bp.reason, bp.status,
                   bp.reported_by, ur.name AS reported_by_name,
                   bp.reported_at AS created_at
            FROM bad_products bp
            JOIN warehouses w ON bp.warehouse_id = w.warehouse_id
            JOIN items i ON bp.item_id = i.item_id
            LEFT JOIN users ur ON bp.reported_by = ur.user_id
            WHERE bp.bad_product_id = ?
        ");
        $stmt->execute([$badProductId]);
        $bp = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bp) {
            throw new InvalidArgumentException("Damaged product record #{$badProductId} not found.");
        }

        if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
            $userWhId = (int)($authUser['warehouse_id'] ?? 0);
            if ($userWhId > 0 && $userWhId !== (int)$bp['warehouse_id']) {
                throw new DomainException("Access Denied: You are not authorized to view records for other warehouses.");
            }
        }

        return $bp;
    }

    public function confirmBadProduct(int $badProductId, int $userId, ?array $authUser = null): array {
        if ($badProductId <= 0) {
            throw new InvalidArgumentException("Invalid bad_product_id.");
        }

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM bad_products WHERE bad_product_id = ? FOR UPDATE");
            $stmt->execute([$badProductId]);
            $bp = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$bp) {
                throw new InvalidArgumentException("Bad product record #{$badProductId} not found.");
            }
            if ($bp['status'] !== 'reported') {
                throw new DomainException("Cannot confirm bad product #{$badProductId}: status is already '{$bp['status']}'.");
            }

            if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
                if (!in_array($authUser['role'] ?? '', ['super_admin', 'admin'], true)) {
                    throw new DomainException("Access Denied: Only warehouse admins or super admins can confirm bad product write-offs.");
                }
                $userWhId = (int)($authUser['warehouse_id'] ?? 0);
                if ($userWhId > 0 && $userWhId !== (int)$bp['warehouse_id']) {
                    throw new DomainException("Access Denied: You cannot confirm bad product records for another warehouse.");
                }
            }
            if (($authUser['role'] ?? '') !== 'super_admin' && (int)($bp['reported_by'] ?? 0) === $userId) {
                throw new DomainException("Segregation of Duties: You cannot confirm a bad product record that you reported yourself.");
            }

            $stmtUpd = $this->pdo->prepare("
                UPDATE bad_products 
                SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW() 
                WHERE bad_product_id = ?
            ");
            $stmtUpd->execute([$userId, $badProductId]);

            AccountabilityService::log([
                'user_id'          => $userId,
                'team'             => 'Inventory',
                'action_type'      => 'BAD_PRODUCT_CONFIRMED',
                'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'item_id'          => (int)$bp['item_id'],
                'quantity'         => (float)$bp['quantity'],
                'warehouse_id'     => (int)$bp['warehouse_id'],
                'reference_number' => "BP-{$badProductId}",
                'notes'            => "Defect write-off confirmed by QC/Supervisor"
            ]);

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'bad_product_id'     => $badProductId,
                'bad_product_number' => "BP-{$badProductId}",
                'status'             => 'confirmed',
                'confirmed_by'       => $userId
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function disposeBadProduct(int $badProductId, string $disposalMethod, int $userId, ?array $authUser = null): array {
        if ($badProductId <= 0) {
            throw new InvalidArgumentException("Invalid bad_product_id.");
        }
        $disposalMethod = trim($disposalMethod);
        if (empty($disposalMethod)) {
            throw new InvalidArgumentException("Disposal method is required.");
        }

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM bad_products WHERE bad_product_id = ? FOR UPDATE");
            $stmt->execute([$badProductId]);
            $bp = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$bp) {
                throw new InvalidArgumentException("Bad product record #{$badProductId} not found.");
            }
            if ($bp['status'] === 'disposed') {
                throw new DomainException("Bad product record #{$badProductId} has already been disposed.");
            }
            if ($bp['status'] === 'cancelled') {
                throw new DomainException("Cannot dispose cancelled bad product record #{$badProductId}.");
            }
            if ($bp['status'] !== 'confirmed') {
                throw new DomainException("Cannot dispose bad product record #{$badProductId}: Current status is '{$bp['status']}'. It must be inspected and confirmed by a QC Supervisor before disposal.");
            }

            if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
                $userWhId = (int)($authUser['warehouse_id'] ?? 0);
                if ($userWhId > 0 && $userWhId !== (int)$bp['warehouse_id']) {
                    throw new DomainException("Access Denied: You cannot dispose bad product records for another warehouse.");
                }
            }

            $cleanMethod = trim($disposalMethod);
            if (mb_strlen($cleanMethod, 'UTF-8') > 100) {
                $cleanMethod = mb_substr($cleanMethod, 0, 97, 'UTF-8') . '...';
            }
            $disposalSuffix = " [Disposed: {$cleanMethod}]";
            $maxBaseLen = max(0, 255 - mb_strlen($disposalSuffix, 'UTF-8'));
            $baseReason = (string)($bp['reason'] ?? '');
            if (mb_strlen($baseReason, 'UTF-8') > $maxBaseLen) {
                $baseReason = $maxBaseLen >= 3
                    ? mb_substr($baseReason, 0, $maxBaseLen - 3, 'UTF-8') . '...'
                    : mb_substr($baseReason, 0, $maxBaseLen, 'UTF-8');
            }
            $updatedReason = $baseReason . $disposalSuffix;

            $stmtUpd = $this->pdo->prepare("
                UPDATE bad_products 
                SET status = 'disposed', reason = ?
                WHERE bad_product_id = ?
            ");
            $stmtUpd->execute([$updatedReason, $badProductId]);

            AccountabilityService::log([
                'user_id'          => $userId,
                'team'             => 'Inventory',
                'action_type'      => 'BAD_PRODUCT_DISPOSED',
                'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'item_id'          => (int)$bp['item_id'],
                'quantity'         => (float)$bp['quantity'],
                'warehouse_id'     => (int)$bp['warehouse_id'],
                'reference_number' => "BP-{$badProductId}",
                'notes'            => "Defective inventory physically disposed: " . $disposalMethod
            ]);

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'bad_product_id'     => $badProductId,
                'bad_product_number' => "BP-{$badProductId}",
                'status'             => 'disposed',
                'disposal_method'    => $disposalMethod
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
