<?php
/**
 * Transactional Stock Service
 * Orchestrates stock, stock_movements, stock_transfers, stock_adjustments, bad_products,
 * and accountability_logs for team_inventory_local.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AccountabilityService.php';

class StockService {
    public const MAX_DECIMAL_QUANTITY = 99999999999.999;
    public const DISCRETE_UNITS = [
        'pcs', 'pc', 'piece', 'pieces',
        'bottle', 'bottles',
        'box', 'boxes',
        'case', 'cases',
        'pack', 'packs',
        'can', 'cans'
    ];
    public const VALID_STOCK_IN_SOURCES = ['PURCHASE_ORDER', 'PRODUCTION_RETURN', 'MANUAL'];
    public const VALID_STOCK_OUT_SOURCES = ['MATERIAL_REQUEST', 'SALES_DELIVERY', 'MANUAL'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * Strictly validate a positive quantity (> 0), enforcing numeric format,
     * bounds, max 3 decimal places, and whole numbers for discrete units.
     */
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
            throw new InvalidArgumentException("Invalid {$fieldLabel}: exceeds maximum database limit (99,999,999,999.999).");
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

    /**
     * Strictly validate a non-negative quantity (>= 0) for physical stock counts.
     */
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
            throw new InvalidArgumentException("Invalid {$fieldLabel}: exceeds maximum database limit (99,999,999,999.999).");
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

    /**
     * Strictly validate a YYYY-MM-DD date string and ensure it is not in the future.
     */
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

    /**
     * Inbound Stock Receiving (e.g. Team 1 Procurement or Team 3 Production FG receipt)
     */
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

        // Warehouse authorization check
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

        // Validate warehouse exists and is active
        $stmtWh = $this->pdo->prepare("SELECT name AS warehouse_name FROM warehouses WHERE warehouse_id = ? AND status = 'active'");
        $stmtWh->execute([$warehouseId]);
        $wh = $stmtWh->fetch(PDO::FETCH_ASSOC);
        if (!$wh) {
            throw new InvalidArgumentException("Active warehouse with ID {$warehouseId} not found.");
        }

        $this->pdo->beginTransaction();
        try {
            $txnNumber = 'IN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            $stmtCheckItem = $this->pdo->prepare("
                SELECT item_id, code AS item_code, name AS item_name, type AS item_type, unit, status
                FROM items WHERE item_id = ?
            ");

            $stmtMove = $this->pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, warehouse_id, movement_type, quantity,
                    reference_id, remarks, created_by, created_at
                ) VALUES (
                    ?, ?, 'STOCK_IN', ?,
                    NULL, ?, ?, NOW()
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
                if (!$itemInfo || $itemInfo['status'] !== 'active') {
                    throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
                }

                $qty = self::validatePositiveQuantity($rawQty, $itemInfo['unit'], "quantity for item '{$itemInfo['item_code']}'");

                // ERP Business rules
                if ($sourceType === 'PURCHASE_ORDER' && $itemInfo['item_type'] !== 'raw_material') {
                    throw new InvalidArgumentException(
                        "Procurement PO violation: Item '{$itemInfo['item_code']}' ({$itemInfo['item_name']}) is a finished good. Purchase orders from Procurement can only receive raw materials."
                    );
                }
                if ($sourceType === 'PRODUCTION_RETURN' && $itemInfo['item_type'] !== 'finished_good') {
                    throw new InvalidArgumentException(
                        "Production Receipt violation: Item '{$itemInfo['item_code']}' ({$itemInfo['item_name']}) is a raw material. Production receipts can only receive finished goods."
                    );
                }

                // Construct remarks string containing tracking info
                $movRemarks = "[{$sourceType}: " . ($sourceReferenceNo ?: $txnNumber) . "]" . ($remarks ? " {$remarks}" : "");

                // Insert into stock_movements
                $stmtMove->execute([$itemId, $warehouseId, $qty, $movRemarks, $userId]);
                $movementId = (int)$this->pdo->lastInsertId();
                if ($firstMovementId === 0) {
                    $firstMovementId = $movementId;
                }

                // Increment stock
                $stmtUpdStock->execute([$itemId, $warehouseId, $qty]);

                // Query new balance
                $stmtBal->execute([$itemId, $warehouseId]);
                $newQty = (float)($stmtBal->fetchColumn() ?: 0.00);

                $processedItems[] = [
                    'item_id'       => $itemId,
                    'item_code'     => $itemInfo['item_code'],
                    'item_name'     => $itemInfo['item_name'],
                    'quantity_in'   => $qty,
                    'unit'          => $itemInfo['unit'],
                    'balance_after' => $newQty
                ];

                AccountabilityService::log([
                    'user_id'          => $userId,
                    'team'             => ($sourceType === 'PURCHASE_ORDER' ? 'Procurement' : ($sourceType === 'PRODUCTION_RETURN' ? 'Production' : 'Inventory')),
                    'action_type'      => 'STOCK_IN',
                    'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                    'item_id'          => $itemId,
                    'quantity'         => $qty,
                    'warehouse_id'     => $warehouseId,
                    'reference_number' => $sourceReferenceNo ?: $txnNumber,
                    'notes'            => $remarks ?: "Inbound stock received ({$sourceType})"
                ]);
            }

            $this->pdo->commit();

            return [
                'stock_in_id'         => $firstMovementId,
                'transaction_number'  => $txnNumber,
                'source_type'         => $sourceType,
                'source_reference_no' => $sourceReferenceNo,
                'warehouse_id'        => $warehouseId,
                'warehouse_name'      => $wh['warehouse_name'],
                'items_count'         => count($processedItems),
                'items'               => $processedItems
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Outbound Stock Deduction (Team 3 Production Material Request OR Team 4 Sales Delivery)
     */
    public function recordStockOut(
        int $warehouseId,
        string $sourceType,
        ?string $sourceReferenceNo,
        array $items,
        int $userId,
        ?string $remarks = null,
        ?array $authUser = null
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

        // Warehouse authorization check
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

        // Validate warehouse
        $stmtWh = $this->pdo->prepare("SELECT name AS warehouse_name FROM warehouses WHERE warehouse_id = ? AND status = 'active'");
        $stmtWh->execute([$warehouseId]);
        $wh = $stmtWh->fetch(PDO::FETCH_ASSOC);
        if (!$wh) {
            throw new InvalidArgumentException("Active warehouse with ID {$warehouseId} not found.");
        }

        $this->pdo->beginTransaction();
        try {
            $txnNumber = 'OUT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            // 1. Lock and check balance in stock table
            $stmtLock = $this->pdo->prepare("
                SELECT qty_on_hand FROM stock 
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
                if (!$itemInfo || $itemInfo['status'] !== 'active') {
                    throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
                }

                $qty = self::validatePositiveQuantity($rawQty, $itemInfo['unit'], "quantity for item '{$itemInfo['item_code']}'");

                // ERP Business rules
                if ($sourceType === 'MATERIAL_REQUEST' && $itemInfo['item_type'] !== 'raw_material') {
                    throw new InvalidArgumentException(
                        "Material Request violation: Item '{$itemInfo['item_code']}' is a finished good. Production requests can only consume raw materials."
                    );
                }
                if ($sourceType === 'SALES_DELIVERY' && $itemInfo['item_type'] !== 'finished_good') {
                    throw new InvalidArgumentException(
                        "Sales Delivery violation: Item '{$itemInfo['item_code']}' is a raw material. Sales can only deliver finished goods."
                    );
                }

                // Check current stock balance
                $stmtLock->execute([$itemId, $warehouseId]);
                $currentQty = $stmtLock->fetchColumn();
                $available = ($currentQty !== false) ? (float)$currentQty : 0.00;

                if ($available < $qty) {
                    throw new InvalidArgumentException(
                        "Insufficient inventory for item '{$itemInfo['item_code']}' ({$itemInfo['item_name']}). Available: {$available} {$itemInfo['unit']}, Requested: {$qty} {$itemInfo['unit']}."
                    );
                }

                $validatedEntries[] = [
                    'item'          => $itemInfo,
                    'quantity'      => $qty,
                    'current_stock' => $available
                ];
            }

            // 2. Perform deductions and movements
            $stmtMove = $this->pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, warehouse_id, movement_type, quantity,
                    reference_id, remarks, created_by, created_at
                ) VALUES (
                    ?, ?, 'STOCK_OUT', ?,
                    NULL, ?, ?, NOW()
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
            $firstMovementId = 0;

            foreach ($validatedEntries as $entry) {
                $itemInfo = $entry['item'];
                $itemId = (int)$itemInfo['item_id'];
                $qty = (float)$entry['quantity'];

                $movRemarks = "[{$sourceType}: " . ($sourceReferenceNo ?: $txnNumber) . "]" . ($remarks ? " {$remarks}" : "");

                $stmtMove->execute([$itemId, $warehouseId, $qty, $movRemarks, $userId]);
                $movementId = (int)$this->pdo->lastInsertId();
                if ($firstMovementId === 0) {
                    $firstMovementId = $movementId;
                }

                $stmtDeductStock->execute([$qty, $itemId, $warehouseId]);

                $stmtBal->execute([$itemId, $warehouseId]);
                $newQty = (float)($stmtBal->fetchColumn() ?: 0.00);

                $processedItems[] = [
                    'item_id'       => $itemId,
                    'item_code'     => $itemInfo['item_code'],
                    'item_name'     => $itemInfo['item_name'],
                    'quantity_out'  => $qty,
                    'unit'          => $itemInfo['unit'],
                    'balance_after' => $newQty
                ];

                AccountabilityService::log([
                    'user_id'          => $userId,
                    'team'             => ($sourceType === 'MATERIAL_REQUEST' ? 'Production' : ($sourceType === 'SALES_DELIVERY' ? 'Sales' : 'Inventory')),
                    'action_type'      => 'STOCK_OUT',
                    'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                    'item_id'          => $itemId,
                    'quantity'         => $qty,
                    'warehouse_id'     => $warehouseId,
                    'reference_number' => $sourceReferenceNo ?: $txnNumber,
                    'notes'            => $remarks ?: "Outbound stock dispatched ({$sourceType})"
                ]);
            }

            $this->pdo->commit();

            return [
                'stock_out_id'        => $firstMovementId,
                'transaction_number'  => $txnNumber,
                'source_type'         => $sourceType,
                'source_reference_no' => $sourceReferenceNo,
                'warehouse_id'        => $warehouseId,
                'warehouse_name'      => $wh['warehouse_name'],
                'items_count'         => count($processedItems),
                'items'               => $processedItems
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Real-time Inventory Query
     */
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
                COALESCE(s.reorder_level, i.reorder_level, 0.00) AS effective_reorder_level,
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

    /**
     * Dedicated Finished Goods Query for Sales Team
     */
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
                COALESCE(s.qty_on_hand, 0.000) AS available_quantity,
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
            $sql .= " AND COALESCE(s.qty_on_hand, 0) > 0";
        }

        $sql .= " ORDER BY i.name ASC, w.name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Low stock alerts query
     */
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
                COALESCE(s.reorder_level, i.reorder_level, 0.00) AS effective_reorder_level,
                (COALESCE(s.reorder_level, i.reorder_level, 0.00) - COALESCE(s.qty_on_hand, 0.000)) AS deficit_quantity,
                1 AS is_low_stock
            FROM items i
            CROSS JOIN warehouses w ON w.status = 'active'
            LEFT JOIN stock s ON s.item_id = i.item_id AND s.warehouse_id = w.warehouse_id
            WHERE i.status = 'active'
              AND COALESCE(s.qty_on_hand, 0.000) <= COALESCE(s.reorder_level, i.reorder_level, 0.00)
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

    /**
     * Cancel a Stock IN transaction
     */
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

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                SELECT sm.movement_id, sm.item_id, sm.warehouse_id, sm.quantity, sm.remarks, sm.created_by,
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

            // Check if already cancelled
            $stmtChkRev = $this->pdo->prepare("
                SELECT movement_id FROM stock_movements 
                WHERE reference_id = ? AND movement_type = 'STOCK_OUT' AND remarks LIKE 'Cancelled Stock IN %'
                LIMIT 1
            ");
            $stmtChkRev->execute([$stockInId]);
            if ($stmtChkRev->fetch()) {
                throw new DomainException("Stock IN transaction #{$stockInId} has already been cancelled.");
            }

            // IDOR Protection
            if ($authUser !== null && ($authUser['role'] ?? '') !== 'super_admin') {
                $authUid = (int)($authUser['user_id'] ?? ($authUser['id'] ?? 0));
                if ((int)$txn['created_by'] !== $authUid) {
                    throw new DomainException(
                        "Access Denied (IDOR Protection): Administrator #{$authUid} is not authorized to cancel transactions created by administrator #{$txn['created_by']}."
                    );
                }
            }

            $itemId = (int)$txn['item_id'];
            $whId   = (int)$txn['warehouse_id'];
            $qty    = (float)$txn['quantity'];

            // Check stock available to reverse
            $stmtBal = $this->pdo->prepare("SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE");
            $stmtBal->execute([$itemId, $whId]);
            $currentBal = (float)($stmtBal->fetchColumn() ?: 0.00);

            if ($currentBal < $qty) {
                throw new DomainException("Cannot cancel Stock IN: Remaining stock on hand ({$currentBal}) is less than quantity being reversed ({$qty}).");
            }

            // Decrement stock
            $stmtUpd = $this->pdo->prepare("UPDATE stock SET qty_on_hand = qty_on_hand - ? WHERE item_id = ? AND warehouse_id = ?");
            $stmtUpd->execute([$qty, $itemId, $whId]);

            // Insert reversing movement
            $revRemarks = "Cancelled Stock IN #{$stockInId}: {$cancellationReason}";
            $stmtRev = $this->pdo->prepare("
                INSERT INTO stock_movements (item_id, warehouse_id, movement_type, quantity, reference_id, remarks, created_by, created_at)
                VALUES (?, ?, 'STOCK_OUT', ?, ?, ?, ?, NOW())
            ");
            $stmtRev->execute([$itemId, $whId, $qty, $stockInId, $revRemarks, $cancelledBy]);

            $stmtBal->execute([$itemId, $whId]);
            $newBal = (float)($stmtBal->fetchColumn() ?: 0.00);

            AccountabilityService::log([
                'user_id'          => $cancelledBy,
                'team'             => 'Inventory',
                'action_type'      => 'STOCK_IN_CANCEL',
                'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'item_id'          => $itemId,
                'quantity'         => $qty,
                'warehouse_id'     => $whId,
                'reference_number' => "IN-{$stockInId}",
                'notes'            => "Cancelled Stock IN: " . $cancellationReason
            ]);

            $this->pdo->commit();

            return [
                'stock_in_id'         => $stockInId,
                'transaction_number'  => "IN-{$stockInId}",
                'status'              => 'cancelled',
                'cancellation_reason' => $cancellationReason,
                'cancelled_by'        => $cancelledBy,
                'items'               => [[
                    'item_id'            => $itemId,
                    'item_code'          => $txn['item_code'],
                    'item_name'          => $txn['item_name'],
                    'quantity_cancelled' => $qty,
                    'unit'               => $txn['unit'],
                    'balance_after'      => $newBal
                ]]
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cancel a Stock OUT transaction
     */
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

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                SELECT sm.movement_id, sm.item_id, sm.warehouse_id, sm.quantity, sm.remarks, sm.created_by,
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

            // Check if already cancelled
            $stmtChkRev = $this->pdo->prepare("
                SELECT movement_id FROM stock_movements 
                WHERE reference_id = ? AND movement_type = 'STOCK_IN' AND remarks LIKE 'Cancelled Stock OUT %'
                LIMIT 1
            ");
            $stmtChkRev->execute([$stockOutId]);
            if ($stmtChkRev->fetch()) {
                throw new DomainException("Stock OUT transaction #{$stockOutId} has already been cancelled.");
            }

            // IDOR Protection
            if ($authUser !== null && ($authUser['role'] ?? '') !== 'super_admin') {
                $authUid = (int)($authUser['user_id'] ?? ($authUser['id'] ?? 0));
                if ((int)$txn['created_by'] !== $authUid) {
                    throw new DomainException(
                        "Access Denied (IDOR Protection): Administrator #{$authUid} is not authorized to cancel transactions created by administrator #{$txn['created_by']}."
                    );
                }
            }

            $itemId = (int)$txn['item_id'];
            $whId   = (int)$txn['warehouse_id'];
            $qty    = (float)$txn['quantity'];

            // Increment stock
            $stmtUpd = $this->pdo->prepare("
                INSERT INTO stock (item_id, warehouse_id, qty_on_hand, reorder_level)
                VALUES (?, ?, ?, 0.00)
                ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand)
            ");
            $stmtUpd->execute([$itemId, $whId, $qty]);

            // Insert reversing movement
            $revRemarks = "Cancelled Stock OUT #{$stockOutId}: {$cancellationReason}";
            $stmtRev = $this->pdo->prepare("
                INSERT INTO stock_movements (item_id, warehouse_id, movement_type, quantity, reference_id, remarks, created_by, created_at)
                VALUES (?, ?, 'STOCK_IN', ?, ?, ?, ?, NOW())
            ");
            $stmtRev->execute([$itemId, $whId, $qty, $stockOutId, $revRemarks, $cancelledBy]);

            $stmtBal = $this->pdo->prepare("SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ?");
            $stmtBal->execute([$itemId, $whId]);
            $newBal = (float)($stmtBal->fetchColumn() ?: 0.00);

            AccountabilityService::log([
                'user_id'          => $cancelledBy,
                'team'             => 'Inventory',
                'action_type'      => 'STOCK_OUT_CANCEL',
                'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'item_id'          => $itemId,
                'quantity'         => $qty,
                'warehouse_id'     => $whId,
                'reference_number' => "OUT-{$stockOutId}",
                'notes'            => "Cancelled Stock OUT: " . $cancellationReason
            ]);

            $this->pdo->commit();

            return [
                'stock_out_id'        => $stockOutId,
                'transaction_number'  => "OUT-{$stockOutId}",
                'status'              => 'cancelled',
                'cancellation_reason' => $cancellationReason,
                'cancelled_by'        => $cancelledBy,
                'items'               => [[
                    'item_id'            => $itemId,
                    'item_code'          => $txn['item_code'],
                    'item_name'          => $txn['item_name'],
                    'quantity_restored'  => $qty,
                    'unit'               => $txn['unit'],
                    'balance_after'      => $newBal
                ]]
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Inter-Facility Stock Transfer Initialization
     */
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

        // Warehouse authorization check
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

        $this->pdo->beginTransaction();
        try {
            $txnNumber = 'TRF-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            $stmtLock = $this->pdo->prepare("
                SELECT qty_on_hand FROM stock 
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
                    item_id, warehouse_id, movement_type, quantity,
                    reference_id, remarks, created_by, created_at
                ) VALUES (
                    ?, ?, 'STOCK_TRANSFER_OUT', ?,
                    ?, ?, ?, NOW()
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
                if (!$itemInfo || $itemInfo['status'] !== 'active') {
                    throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
                }

                $qty = self::validatePositiveQuantity($rawQty, $itemInfo['unit'], "quantity for item '{$itemInfo['item_code']}'");

                $stmtLock->execute([$itemId, $sourceWhId]);
                $currentStock = $stmtLock->fetchColumn();
                $available = ($currentStock !== false) ? (float)$currentStock : 0.00;

                if ($available < $qty) {
                    throw new InvalidArgumentException(
                        "Insufficient inventory in source warehouse '{$whMap[$sourceWhId]['warehouse_name']}' for item '{$itemInfo['item_code']}'. Available: {$available} {$itemInfo['unit']}, Requested: {$qty} {$itemInfo['unit']}."
                    );
                }

                // Insert into stock_transfers
                $stmtTransfer->execute([$itemId, $sourceWhId, $destWhId, $qty, $userId]);
                $transferId = (int)$this->pdo->lastInsertId();
                if ($firstTransferId === 0) {
                    $firstTransferId = $transferId;
                }

                // Deduct source stock
                $stmtDeductStock->execute([$qty, $itemId, $sourceWhId]);

                // Insert movement OUT
                $movRemarks = "Transfer to {$whMap[$destWhId]['warehouse_name']}" . ($remarks ? " - {$remarks}" : "");
                $stmtMoveOut->execute([$itemId, $sourceWhId, $qty, $transferId, $movRemarks, $userId]);

                // Query new balance
                $stmtBal->execute([$itemId, $sourceWhId]);
                $sourceBal = (float)($stmtBal->fetchColumn() ?: 0.00);

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

            $this->pdo->commit();

            return [
                'stock_transfer_id'        => $firstTransferId,
                'transaction_number'       => "TRF-{$firstTransferId}",
                'status'                   => 'in_transit',
                'source_warehouse'         => $whMap[$sourceWhId],
                'destination_warehouse'    => $whMap[$destWhId],
                'items_count'              => count($processedItems),
                'items'                    => $processedItems
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Destination Warehouse Receiving Confirmation
     */
    public function confirmStockTransferReceipt(int $stockTransferId, int $userId, ?array $authUser = null): array {
        if ($stockTransferId <= 0) {
            throw new InvalidArgumentException("Invalid stock_transfer_id.");
        }
        if ($userId <= 0) {
            throw new InvalidArgumentException("Invalid user ID.");
        }

        $this->pdo->beginTransaction();
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

            // Authorization
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

            // Increment destination stock
            $stmtUpdStock = $this->pdo->prepare("
                INSERT INTO stock (item_id, warehouse_id, qty_on_hand, reorder_level)
                VALUES (?, ?, ?, 0.00)
                ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand)
            ");
            $stmtUpdStock->execute([$itemId, $destWhId, $qty]);

            // Insert movement IN
            $movRemarks = "Received transfer from {$txn['source_warehouse_name']}";
            $stmtMoveIn = $this->pdo->prepare("
                INSERT INTO stock_movements (item_id, warehouse_id, movement_type, quantity, reference_id, remarks, created_by, created_at)
                VALUES (?, ?, 'STOCK_TRANSFER_IN', ?, ?, ?, ?, NOW())
            ");
            $stmtMoveIn->execute([$itemId, $destWhId, $qty, $stockTransferId, $movRemarks, $userId]);

            // Update transfer status
            $stmtUpdate = $this->pdo->prepare("
                UPDATE stock_transfers
                SET status = 'received',
                    received_by = ?,
                    received_at = NOW()
                WHERE transfer_id = ?
            ");
            $stmtUpdate->execute([$userId, $stockTransferId]);

            // Query destination balance
            $stmtBal = $this->pdo->prepare("SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ?");
            $stmtBal->execute([$itemId, $destWhId]);
            $destBal = (float)($stmtBal->fetchColumn() ?: 0.00);

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

            $this->pdo->commit();

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
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cancel an Inter-Warehouse Transfer
     */
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

        $this->pdo->beginTransaction();
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

            // Authorization
            if ($authUser !== null && ($authUser['role'] ?? '') !== 'super_admin') {
                $userWhId = (int)($authUser['warehouse_id'] ?? 0);
                if ((int)$txn['source_warehouse_id'] !== $userWhId && (int)$txn['requested_by'] !== (int)$authUser['user_id']) {
                    throw new DomainException("Access Denied: You are not authorized to cancel this transfer.");
                }
            }

            $itemId   = (int)$txn['item_id'];
            $sourceWhId = (int)$txn['source_warehouse_id'];
            $qty      = (float)$txn['quantity'];

            // Update status to cancelled
            $stmtUpd = $this->pdo->prepare("UPDATE stock_transfers SET status = 'cancelled' WHERE transfer_id = ?");
            $stmtUpd->execute([$stockTransferId]);

            // Restore source inventory
            $stmtRestore = $this->pdo->prepare("
                INSERT INTO stock (item_id, warehouse_id, qty_on_hand, reorder_level)
                VALUES (?, ?, ?, 0.00)
                ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand)
            ");
            $stmtRestore->execute([$itemId, $sourceWhId, $qty]);

            // Insert reversing movement
            $revRemarks = "Cancelled Transfer TRF-{$stockTransferId}: {$cancellationReason}";
            $stmtRev = $this->pdo->prepare("
                INSERT INTO stock_movements (item_id, warehouse_id, movement_type, quantity, reference_id, remarks, created_by, created_at)
                VALUES (?, ?, 'STOCK_TRANSFER_IN', ?, ?, ?, ?, NOW())
            ");
            $stmtRev->execute([$itemId, $sourceWhId, $qty, $stockTransferId, $revRemarks, $cancelledBy]);

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

            $this->pdo->commit();

            return [
                'stock_transfer_id'  => $stockTransferId,
                'transaction_number' => "TRF-{$stockTransferId}",
                'status'             => 'cancelled',
                'cancellation_reason'=> $cancellationReason
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Get Stock IN Details (Protected against IDOR)
     */
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

        if (($authUser['role'] ?? '') !== 'super_admin' && (int)$txn['created_by'] !== (int)$authUser['user_id']) {
            throw new DomainException(
                "Access Denied (IDOR Protection): Administrator #{$authUser['user_id']} is not authorized to view transactions created by administrator #{$txn['created_by']}."
            );
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

    /**
     * Get Stock OUT Details (Protected against IDOR)
     */
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

        if (($authUser['role'] ?? '') !== 'super_admin' && (int)$txn['created_by'] !== (int)$authUser['user_id']) {
            throw new DomainException(
                "Access Denied (IDOR Protection): Administrator #{$authUser['user_id']} is not authorized to view transactions created by administrator #{$txn['created_by']}."
            );
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

    /**
     * Get Stock Transfer Details (Protected against IDOR)
     */
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

    /**
     * List Stock INs (Scoped by IDOR privacy)
     */
    public function listStockIns(array $authUser, int $limit = 50): array {
        $limit = max(1, min(200, $limit));

        $sql = "
            SELECT sm.movement_id AS stock_in_id,
                   CONCAT('IN-', LPAD(sm.movement_id, 6, '0')) AS transaction_number,
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

        if (($authUser['role'] ?? '') !== 'super_admin') {
            $sql .= " AND sm.created_by = " . (int)$authUser['user_id'];
        }

        $sql .= " ORDER BY sm.movement_id DESC LIMIT " . (int)$limit;

        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * List Stock OUTs (Scoped by IDOR privacy)
     */
    public function listStockOuts(array $authUser, int $limit = 50): array {
        $limit = max(1, min(200, $limit));

        $sql = "
            SELECT sm.movement_id AS stock_out_id,
                   CONCAT('OUT-', LPAD(sm.movement_id, 6, '0')) AS transaction_number,
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

        if (($authUser['role'] ?? '') !== 'super_admin') {
            $sql .= " AND sm.created_by = " . (int)$authUser['user_id'];
        }

        $sql .= " ORDER BY sm.movement_id DESC LIMIT " . (int)$limit;

        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * List Stock Transfers
     */
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

    /**
     * Record a new Stock Adjustment (Initiated as 'pending' for discrepancy review)
     */
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

        // Warehouse authorization check
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

        $this->pdo->beginTransaction();
        try {
            $txnNumber = 'ADJ-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            $stmtCheckItem = $this->pdo->prepare("
                SELECT item_id, code AS item_code, name AS item_name, unit, status FROM items WHERE item_id = ?
            ");
            $stmtLockStock = $this->pdo->prepare("
                SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE
            ");
            $stmtAdj = $this->pdo->prepare("
                INSERT INTO stock_adjustments (
                    item_id, warehouse_id, previous_quantity, adjusted_quantity,
                    difference, reason, status, requested_by, requested_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
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
                if (!$itemInfo || $itemInfo['status'] !== 'active') {
                    throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
                }

                $adjustedQty = self::validateNonNegativeQuantity(
                    $rawAdjustedQty,
                    $itemInfo['unit'],
                    "adjusted_quantity for item '{$itemInfo['item_code']}'"
                );

                $stmtLockStock->execute([$itemId, $warehouseId]);
                $currentStock = $stmtLockStock->fetchColumn();
                $previousQty = ($currentStock !== false) ? (float)$currentStock : 0.00;

                if (abs($adjustedQty - $previousQty) < 0.0001) {
                    throw new InvalidArgumentException(
                        "No-op stock adjustment rejected for item '{$itemInfo['item_code']}': physical adjusted count ({$adjustedQty}) is identical to current system stock ({$previousQty})."
                    );
                }

                $diff = round($adjustedQty - $previousQty, 2);

                $stmtAdj->execute([$itemId, $warehouseId, $previousQty, $adjustedQty, $diff, $reason, $userId]);
                $adjId = (int)$this->pdo->lastInsertId();
                if ($firstAdjId === 0) {
                    $firstAdjId = $adjId;
                }

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
                    'action_type'      => 'ADJUSTMENT_REQUESTED',
                    'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                    'item_id'          => $itemId,
                    'quantity'         => $diff,
                    'warehouse_id'     => $warehouseId,
                    'reference_number' => "ADJ-{$adjId}",
                    'notes'            => "Adjustment requested: " . $reason . " (Diff: " . ($diff >= 0 ? "+{$diff}" : $diff) . ")"
                ]);
            }

            $this->pdo->commit();

            return [
                'stock_adjustment_id' => $firstAdjId,
                'transaction_number'  => "ADJ-{$firstAdjId}",
                'warehouse_id'        => $warehouseId,
                'warehouse_name'      => $wh['warehouse_name'],
                'status'              => 'pending',
                'adjustment_date'     => $adjustmentDate,
                'reason'              => $reason,
                'items_count'         => count($processedItems),
                'items'               => $processedItems
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Approve Stock Adjustment
     */
    public function approveStockAdjustment(int $adjustmentId, int $userId, ?array $authUser = null): array {
        if ($adjustmentId <= 0) {
            throw new InvalidArgumentException("Invalid adjustment ID.");
        }

        $this->pdo->beginTransaction();
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

            // Authorization: regular admin cannot approve adjustments for other warehouses
            if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
                $userWhId = (int)($authUser['warehouse_id'] ?? 0);
                if ($userWhId > 0 && $userWhId !== (int)$adj['warehouse_id']) {
                    throw new DomainException("Access Denied: You cannot approve adjustments for other warehouses.");
                }
            }

            // Update status to approved
            $stmtAppr = $this->pdo->prepare("
                UPDATE stock_adjustments
                SET status = 'approved', approved_by = ?, approved_at = NOW()
                WHERE adjustment_id = ?
            ");
            $stmtAppr->execute([$userId, $adjustmentId]);

            // Update stock quantity
            $stmtUpdStock = $this->pdo->prepare("
                INSERT INTO stock (item_id, warehouse_id, qty_on_hand, reorder_level)
                VALUES (?, ?, ?, 0.00)
                ON DUPLICATE KEY UPDATE qty_on_hand = ?
            ");
            $stmtUpdStock->execute([$adj['item_id'], $adj['warehouse_id'], $adj['adjusted_quantity'], $adj['adjusted_quantity']]);

            // Record movement
            $diff = (float)$adj['difference'];
            $movRemarks = "Adjustment approved: " . $adj['reason'];
            $stmtMov = $this->pdo->prepare("
                INSERT INTO stock_movements (item_id, warehouse_id, movement_type, quantity, reference_id, remarks, created_by, created_at)
                VALUES (?, ?, 'STOCK_ADJUSTMENT', ?, ?, ?, ?, NOW())
            ");
            $stmtMov->execute([$adj['item_id'], $adj['warehouse_id'], abs($diff), $adjustmentId, $movRemarks, $userId]);

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

            $this->pdo->commit();

            return [
                'stock_adjustment_id' => $adjustmentId,
                'transaction_number'  => "ADJ-{$adjustmentId}",
                'status'              => 'approved',
                'difference'          => $diff
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reject Stock Adjustment
     */
    public function rejectStockAdjustment(int $adjustmentId, int $userId, string $reason, ?array $authUser = null): array {
        if ($adjustmentId <= 0) {
            throw new InvalidArgumentException("Invalid adjustment ID.");
        }

        $this->pdo->beginTransaction();
        try {
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

            $this->pdo->commit();

            return [
                'stock_adjustment_id' => $adjustmentId,
                'transaction_number'  => "ADJ-{$adjustmentId}",
                'status'              => 'rejected'
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cancel Stock Adjustment
     */
    public function cancelStockAdjustment(int $adjustmentId, string $reason, int $userId, ?array $authUser = null): array {
        if ($adjustmentId <= 0) {
            throw new InvalidArgumentException("Invalid adjustment ID.");
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM stock_adjustments WHERE adjustment_id = ? FOR UPDATE");
            $stmt->execute([$adjustmentId]);
            $adj = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$adj) {
                throw new InvalidArgumentException("Stock adjustment #{$adjustmentId} not found.");
            }
            if ($adj['status'] !== 'pending') {
                throw new DomainException("Cannot cancel adjustment #{$adjustmentId}: only pending adjustments can be cancelled.");
            }

            $stmtUpd = $this->pdo->prepare("UPDATE stock_adjustments SET status = 'cancelled' WHERE adjustment_id = ?");
            $stmtUpd->execute([$adjustmentId]);

            AccountabilityService::log([
                'user_id'          => $userId,
                'team'             => 'Inventory',
                'action_type'      => 'ADJUSTMENT_CANCELLED',
                'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'item_id'          => (int)$adj['item_id'],
                'quantity'         => (float)$adj['difference'],
                'warehouse_id'     => (int)$adj['warehouse_id'],
                'reference_number' => "ADJ-{$adjustmentId}",
                'notes'            => "Adjustment cancelled: " . $reason
            ]);

            $this->pdo->commit();

            return [
                'stock_adjustment_id' => $adjustmentId,
                'transaction_number'  => "ADJ-{$adjustmentId}",
                'status'              => 'cancelled'
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Get Stock Adjustment Details
     */
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

    /**
     * Record Bad Product / Damaged Goods Write-off
     */
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

        // Warehouse authorization check
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

        $this->pdo->beginTransaction();
        try {
            $stmtItem = $this->pdo->prepare("SELECT code AS item_code, name AS item_name, unit, status FROM items WHERE item_id = ?");
            $stmtItem->execute([$itemId]);
            $item = $stmtItem->fetch(PDO::FETCH_ASSOC);
            if (!$item || $item['status'] !== 'active') {
                throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
            }

            $quantity = self::validatePositiveQuantity($quantity, $item['unit'], "quantity for item '{$item['item_code']}'");

            // Lock stock and verify balance
            $stmtLock = $this->pdo->prepare("SELECT qty_on_hand FROM stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE");
            $stmtLock->execute([$itemId, $warehouseId]);
            $currentStock = (float)($stmtLock->fetchColumn() ?: 0.00);

            if ($quantity > $currentStock) {
                throw new DomainException(
                    "Insufficient stock: Cannot write off {$quantity} units of '{$item['item_name']}'. Only {$currentStock} units currently available in this warehouse."
                );
            }

            // Insert into bad_products
            $stmtBp = $this->pdo->prepare("
                INSERT INTO bad_products (
                    item_id, warehouse_id, quantity, reason, status, reported_by, reported_at
                ) VALUES (?, ?, ?, ?, 'reported', ?, NOW())
            ");
            $stmtBp->execute([$itemId, $warehouseId, $quantity, $reason, $userId]);
            $badProductId = (int)$this->pdo->lastInsertId();

            // Deduct from stock
            $stmtDeduct = $this->pdo->prepare("UPDATE stock SET qty_on_hand = qty_on_hand - ? WHERE item_id = ? AND warehouse_id = ?");
            $stmtDeduct->execute([$quantity, $itemId, $warehouseId]);

            // Insert movement OUT
            $movRemarks = "Defect write-off: [{$conditionType}] " . $reason;
            $stmtMov = $this->pdo->prepare("
                INSERT INTO stock_movements (item_id, warehouse_id, movement_type, quantity, reference_id, remarks, created_by, created_at)
                VALUES (?, ?, 'STOCK_OUT', ?, ?, ?, ?, NOW())
            ");
            $stmtMov->execute([$itemId, $warehouseId, $quantity, $badProductId, $movRemarks, $userId]);

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

            $this->pdo->commit();

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
                'status'             => 'reported'
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cancel a Bad Product report (Restores deducted stock)
     */
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

        $this->pdo->beginTransaction();
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

            if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
                $userWhId = (int)($authUser['warehouse_id'] ?? 0);
                if ($userWhId > 0 && $userWhId !== (int)$bp['warehouse_id']) {
                    throw new DomainException("Access Denied: You are not authorized to cancel bad product records for other warehouses.");
                }
            }

            // Update status
            $stmtCancel = $this->pdo->prepare("UPDATE bad_products SET status = 'cancelled' WHERE bad_product_id = ?");
            $stmtCancel->execute([$badProductId]);

            // Restore stock
            $stmtRestore = $this->pdo->prepare("
                INSERT INTO stock (item_id, warehouse_id, qty_on_hand, reorder_level)
                VALUES (?, ?, ?, 0.00)
                ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand)
            ");
            $stmtRestore->execute([(int)$bp['item_id'], (int)$bp['warehouse_id'], (float)$bp['quantity']]);

            // Insert movement IN
            $movRemarks = "Defect write-off cancelled: " . $reason;
            $stmtMov = $this->pdo->prepare("
                INSERT INTO stock_movements (item_id, warehouse_id, movement_type, quantity, reference_id, remarks, created_by, created_at)
                VALUES (?, ?, 'STOCK_IN', ?, ?, ?, ?, NOW())
            ");
            $stmtMov->execute([(int)$bp['item_id'], (int)$bp['warehouse_id'], (float)$bp['quantity'], $badProductId, $movRemarks, $userId]);

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

            $this->pdo->commit();

            return [
                'bad_product_id'     => $badProductId,
                'bad_product_number' => "BP-{$badProductId}",
                'status'             => 'cancelled'
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Get Bad Product Details
     */
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
}

