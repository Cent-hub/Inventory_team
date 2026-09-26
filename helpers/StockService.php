<?php
/**
 * Transactional Stock Service
 * Orchestrates stock_ins, stock_outs, and ledger movements with concurrency locking.
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

    public function __construct() {
        $this->pdo = Database::getConnection();
    }

    /**
     * Strictly validate a positive quantity (> 0), enforcing numeric format,
     * DECIMAL(14,3) bounds, max 3 decimal places, and whole numbers for discrete units.
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
     * Strictly validate a non-negative quantity (>= 0) for physical stock counts,
     * enforcing explicit numeric input, DECIMAL(14,3) bounds, and discrete units.
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
        ?string $remarks = null
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

        // Validate warehouse exists and is active
        $stmtWh = $this->pdo->prepare("SELECT warehouse_name FROM warehouses WHERE warehouse_id = ? AND status = 'active'");
        $stmtWh->execute([$warehouseId]);
        $wh = $stmtWh->fetch();
        if (!$wh) {
            throw new InvalidArgumentException("Active warehouse with ID {$warehouseId} not found.");
        }

        $this->pdo->beginTransaction();
        try {
            // Duplicate Transaction Protection
            if (!empty($sourceReferenceNo)) {
                $stmtDup = $this->pdo->prepare("
                    SELECT stock_in_id, transaction_number 
                    FROM stock_ins 
                    WHERE source_reference_no = ? AND source_type = ? AND status != 'cancelled'
                    LIMIT 1
                ");
                $stmtDup->execute([$sourceReferenceNo, $sourceType]);
                $existing = $stmtDup->fetch();
                if ($existing) {
                    throw new DomainException(
                        "Duplicate transaction error: A completed Stock IN ({$existing['transaction_number']}) with reference '{$sourceReferenceNo}' has already been processed."
                    );
                }
            }

            $txnNumber = 'IN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            // 1. Insert header
            $stmtHeader = $this->pdo->prepare("
                INSERT INTO stock_ins (
                    transaction_number, source_type, source_reference_no,
                    warehouse_id, transaction_date, status, remarks, created_by
                ) VALUES (
                    ?, ?, ?,
                    ?, CURDATE(), 'completed', ?, ?
                )
            ");
            $stmtHeader->execute([
                $txnNumber,
                $sourceType,
                $sourceReferenceNo,
                $warehouseId,
                $remarks,
                $userId
            ]);
            $stockInId = (int)$this->pdo->lastInsertId();

            $processedItems = [];

            // 2. Insert line items & ledger movements
            $stmtLine = $this->pdo->prepare("
                INSERT INTO stock_in_items (stock_in_id, item_id, quantity)
                VALUES (?, ?, ?)
            ");

            $stmtMove = $this->pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, warehouse_id, movement_type, stock_in_id,
                    reference_number, quantity_in, quantity_out
                ) VALUES (
                    ?, ?, 'STOCK_IN', ?,
                    ?, ?, 0.000
                )
            ");

            $stmtCheckItem = $this->pdo->prepare("
                SELECT item_id, item_code, item_name, item_type, unit, status
                FROM items WHERE item_id = ?
            ");

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
                        "Duplicate item ID {$itemId} in Stock IN request. Combine quantities into a single line item."
                    );
                }
                $seenItemIds[$itemId] = true;

                $stmtCheckItem->execute([$itemId]);
                $itemInfo = $stmtCheckItem->fetch();
                if (!$itemInfo || $itemInfo['status'] !== 'active') {
                    throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
                }

                // Validate discrete unit constraint against the item's unit
                $qty = self::validatePositiveQuantity($rawQty, $itemInfo['unit'], "quantity for item '{$itemInfo['item_code']}'");

                // ERP Business rule: Team 1 Procurement Purchase Orders can ONLY receive raw materials
                if ($sourceType === 'PURCHASE_ORDER' && $itemInfo['item_type'] !== 'raw_material') {
                    throw new InvalidArgumentException(
                        "Procurement PO violation: Item '{$itemInfo['item_code']}' ({$itemInfo['item_name']}) is a finished good. Purchase orders from Procurement can only receive raw materials."
                    );
                }

                // ERP Business rule: Team 3 Production Returns can ONLY receive finished goods
                if ($sourceType === 'PRODUCTION_RETURN' && $itemInfo['item_type'] !== 'finished_good') {
                    throw new InvalidArgumentException(
                        "Production Receipt violation: Item '{$itemInfo['item_code']}' ({$itemInfo['item_name']}) is a raw material. Production receipts can only receive finished goods."
                    );
                }

                // Insert line item
                $stmtLine->execute([$stockInId, $itemId, $qty]);

                // Insert movement (trigger will verify balance & update inventory)
                $stmtMove->execute([$itemId, $warehouseId, $stockInId, $txnNumber, $qty]);

                // Fetch new balance from inventory
                $stmtBal = $this->pdo->prepare("
                    SELECT quantity FROM inventory WHERE item_id = ? AND warehouse_id = ?
                ");
                $stmtBal->execute([$itemId, $warehouseId]);
                $newQty = $stmtBal->fetchColumn() ?: 0.000;

                $processedItems[] = [
                    'item_id'       => $itemId,
                    'item_code'     => $itemInfo['item_code'],
                    'item_name'     => $itemInfo['item_name'],
                    'quantity_in'   => $qty,
                    'unit'          => $itemInfo['unit'],
                    'balance_after' => (float)$newQty
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
                'stock_in_id'         => $stockInId,
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
        ?string $remarks = null
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

        // Validate warehouse
        $stmtWh = $this->pdo->prepare("SELECT warehouse_name FROM warehouses WHERE warehouse_id = ? AND status = 'active'");
        $stmtWh->execute([$warehouseId]);
        $wh = $stmtWh->fetch();
        if (!$wh) {
            throw new InvalidArgumentException("Active warehouse with ID {$warehouseId} not found.");
        }

        $this->pdo->beginTransaction();
        try {
            // Duplicate Transaction Protection
            if (!empty($sourceReferenceNo)) {
                $stmtDup = $this->pdo->prepare("
                    SELECT stock_out_id, transaction_number 
                    FROM stock_outs 
                    WHERE source_reference_no = ? AND source_type = ? AND status != 'cancelled'
                    LIMIT 1
                ");
                $stmtDup->execute([$sourceReferenceNo, $sourceType]);
                $existing = $stmtDup->fetch();
                if ($existing) {
                    throw new DomainException(
                        "Duplicate transaction error: A completed Stock OUT ({$existing['transaction_number']}) with reference '{$sourceReferenceNo}' has already been processed."
                    );
                }
            }

            $txnNumber = 'OUT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            // 1. Pre-validation & Pessimistic Concurrency Locking
            $stmtLock = $this->pdo->prepare("
                SELECT quantity FROM inventory 
                WHERE item_id = ? AND warehouse_id = ? 
                FOR UPDATE
            ");

            $stmtCheckItem = $this->pdo->prepare("
                SELECT item_id, item_code, item_name, item_type, unit, status
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
                $itemInfo = $stmtCheckItem->fetch();
                if (!$itemInfo || $itemInfo['status'] !== 'active') {
                    throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
                }

                // Validate discrete unit constraint against the item's unit
                $qty = self::validatePositiveQuantity($rawQty, $itemInfo['unit'], "quantity for item '{$itemInfo['item_code']}'");

                // ERP Business rule: Team 3 Material Request must consume raw materials
                if ($sourceType === 'MATERIAL_REQUEST' && $itemInfo['item_type'] !== 'raw_material') {
                    throw new InvalidArgumentException(
                        "Material Request violation: Item '{$itemInfo['item_code']}' is a finished good. Production requests can only consume raw materials."
                    );
                }

                // ERP Business rule: Team 4 Sales Delivery must deliver finished goods
                if ($sourceType === 'SALES_DELIVERY' && $itemInfo['item_type'] !== 'finished_good') {
                    throw new InvalidArgumentException(
                        "Sales Delivery violation: Item '{$itemInfo['item_code']}' is a raw material. Sales can only deliver finished goods."
                    );
                }

                // Acquire row lock and verify current balance
                $stmtLock->execute([$itemId, $warehouseId]);
                $currentQty = $stmtLock->fetchColumn();
                $available = $currentQty !== false ? (float)$currentQty : 0.000;

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

            // 2. Insert stock_outs header
            $stmtHeader = $this->pdo->prepare("
                INSERT INTO stock_outs (
                    transaction_number, source_type, source_reference_no,
                    warehouse_id, transaction_date, status, remarks, created_by
                ) VALUES (
                    ?, ?, ?,
                    ?, CURDATE(), 'completed', ?, ?
                )
            ");
            $stmtHeader->execute([
                $txnNumber,
                $sourceType,
                $sourceReferenceNo,
                $warehouseId,
                $remarks,
                $userId
            ]);
            $stockOutId = (int)$this->pdo->lastInsertId();

            // 3. Insert line items & stock_movements
            $stmtLine = $this->pdo->prepare("
                INSERT INTO stock_out_items (stock_out_id, item_id, quantity)
                VALUES (?, ?, ?)
            ");

            $stmtMove = $this->pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, warehouse_id, movement_type, stock_out_id,
                    reference_number, quantity_in, quantity_out
                ) VALUES (
                    ?, ?, 'STOCK_OUT', ?,
                    ?, 0.000, ?
                )
            ");

            $processedItems = [];

            foreach ($validatedEntries as $entry) {
                $itemInfo = $entry['item'];
                $itemId = (int)$itemInfo['item_id'];
                $qty = (float)$entry['quantity'];

                $stmtLine->execute([$stockOutId, $itemId, $qty]);
                $stmtMove->execute([$itemId, $warehouseId, $stockOutId, $txnNumber, $qty]);

                // Query updated balance
                $stmtBal = $this->pdo->prepare("
                    SELECT quantity FROM inventory WHERE item_id = ? AND warehouse_id = ?
                ");
                $stmtBal->execute([$itemId, $warehouseId]);
                $newQty = (float)$stmtBal->fetchColumn();

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
                'stock_out_id'        => $stockOutId,
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
                inv.inventory_id,
                i.item_id,
                i.item_code,
                i.item_name,
                i.item_type,
                c.category_name,
                i.unit,
                w.warehouse_id,
                w.warehouse_code,
                w.warehouse_name,
                inv.quantity AS current_quantity,
                CASE WHEN inv.reorder_level > 0 THEN inv.reorder_level ELSE i.default_reorder_level END AS effective_reorder_level,
                inv.updated_at
            FROM inventory inv
            JOIN items i ON i.item_id = inv.item_id
            JOIN warehouses w ON w.warehouse_id = inv.warehouse_id
            LEFT JOIN categories c ON c.category_id = i.category_id
            WHERE i.status = 'active' AND w.status = 'active'
        ";

        $params = [];
        if ($itemId !== null) {
            $sql .= " AND i.item_id = ?";
            $params[] = $itemId;
        }
        if ($itemCode !== null) {
            $sql .= " AND i.item_code = ?";
            $params[] = $itemCode;
        }
        if ($warehouseId !== null) {
            $sql .= " AND w.warehouse_id = ?";
            $params[] = $warehouseId;
        }
        if ($itemType !== null && in_array($itemType, ['raw_material', 'finished_good'], true)) {
            $sql .= " AND i.item_type = ?";
            $params[] = $itemType;
        }

        $sql .= " ORDER BY i.item_name, w.warehouse_name";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Dedicated Finished Goods Query for Sales Team
     * Returns available finished goods with warehouse, location, unit, status, and quantity.
     * Excludes internal purchasing parameters and strictly filters to active finished goods.
     *
     * @param int|null $warehouseId Filter by specific warehouse
     * @param string|null $itemCode Filter by exact item code
     * @param int|null $itemId Filter by specific item ID
     * @param bool $inStockOnly Only return records with positive available stock
     * @return array
     */
    public function getFinishedGoods(
        ?int $warehouseId = null,
        ?string $itemCode = null,
        ?int $itemId = null,
        bool $inStockOnly = false
    ): array {
        $params = [];

        if ($warehouseId !== null) {
            $sql = "
                SELECT 
                    i.item_id,
                    i.item_code,
                    i.item_name,
                    COALESCE(c.category_name, 'Finished Goods') AS category_name,
                    i.unit,
                    COALESCE(inv.quantity, 0.000) AS available_quantity,
                    w.warehouse_id,
                    w.warehouse_code,
                    w.warehouse_name,
                    COALESCE(w.location, '') AS warehouse_location,
                    i.status,
                    inv.updated_at AS last_updated_at
                FROM items i
                LEFT JOIN categories c ON c.category_id = i.category_id
                CROSS JOIN warehouses w ON w.warehouse_id = ? AND w.status = 'active'
                LEFT JOIN inventory inv ON inv.item_id = i.item_id AND inv.warehouse_id = w.warehouse_id
                WHERE i.item_type = 'finished_good'
                  AND i.status = 'active'
            ";
            $params[] = $warehouseId;
        } else {
            $sql = "
                SELECT 
                    i.item_id,
                    i.item_code,
                    i.item_name,
                    COALESCE(c.category_name, 'Finished Goods') AS category_name,
                    i.unit,
                    COALESCE(inv.quantity, 0.000) AS available_quantity,
                    w.warehouse_id,
                    w.warehouse_code,
                    w.warehouse_name,
                    COALESCE(w.location, '') AS warehouse_location,
                    i.status,
                    inv.updated_at AS last_updated_at
                FROM items i
                LEFT JOIN categories c ON c.category_id = i.category_id
                JOIN inventory inv ON inv.item_id = i.item_id
                JOIN warehouses w ON w.warehouse_id = inv.warehouse_id AND w.status = 'active'
                WHERE i.item_type = 'finished_good'
                  AND i.status = 'active'
            ";
        }

        if ($itemCode !== null) {
            $sql .= " AND i.item_code = ?";
            $params[] = $itemCode;
        }
        if ($itemId !== null) {
            $sql .= " AND i.item_id = ?";
            $params[] = $itemId;
        }
        if ($inStockOnly) {
            $sql .= " AND COALESCE(inv.quantity, 0) > 0";
        }

        $sql .= " ORDER BY i.item_name ASC, w.warehouse_name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // If a specific item was requested but has no inventory record yet across any warehouse
        if (empty($rows) && ($itemCode !== null || $itemId !== null) && !$inStockOnly && $warehouseId === null) {
            $itemSql = "
                SELECT 
                    i.item_id,
                    i.item_code,
                    i.item_name,
                    COALESCE(c.category_name, 'Finished Goods') AS category_name,
                    i.unit,
                    0.000 AS available_quantity,
                    NULL AS warehouse_id,
                    NULL AS warehouse_code,
                    NULL AS warehouse_name,
                    NULL AS warehouse_location,
                    i.status,
                    i.updated_at AS last_updated_at
                FROM items i
                LEFT JOIN categories c ON c.category_id = i.category_id
                WHERE i.item_type = 'finished_good' AND i.status = 'active'
            ";
            $itemParams = [];
            if ($itemCode !== null) {
                $itemSql .= " AND i.item_code = ?";
                $itemParams[] = $itemCode;
            }
            if ($itemId !== null) {
                $itemSql .= " AND i.item_id = ?";
                $itemParams[] = $itemId;
            }
            $stmtItem = $this->pdo->prepare($itemSql);
            $stmtItem->execute($itemParams);
            $rows = $stmtItem->fetchAll();
        }

        return array_map(function ($row) {
            return [
                'item_id'            => (int)$row['item_id'],
                'item_code'          => (string)$row['item_code'],
                'item_name'          => (string)$row['item_name'],
                'category_name'      => (string)$row['category_name'],
                'available_quantity' => (float)$row['available_quantity'],
                'unit'               => (string)$row['unit'],
                'warehouse_id'       => $row['warehouse_id'] !== null ? (int)$row['warehouse_id'] : null,
                'warehouse_code'     => $row['warehouse_code'] ?? null,
                'warehouse_name'     => $row['warehouse_name'] ?? null,
                'warehouse_location' => $row['warehouse_location'] ?? null,
                'status'             => (string)$row['status'],
                'last_updated_at'    => (string)$row['last_updated_at']
            ];
        }, $rows);
    }

    /**
     * Get Active Low Stock Alerts
     * Primary Consumer: Team 1 (Procurement - Reorder replenishment)
     */
    public function getLowStockAlerts(?int $warehouseId = null, ?string $itemType = null): array {
        $sql = "
            SELECT 
                warehouse_id,
                warehouse_code,
                warehouse_name,
                item_id,
                item_code,
                item_name,
                item_type,
                category_id,
                category_name,
                unit,
                current_quantity,
                effective_reorder_level,
                deficit_quantity,
                is_low_stock
            FROM v_active_low_stock_alerts
            WHERE 1=1
        ";

        $params = [];
        if ($warehouseId !== null) {
            $sql .= " AND warehouse_id = ?";
            $params[] = $warehouseId;
        }
        if ($itemType !== null) {
            $sql .= " AND item_type = ?";
            $params[] = $itemType;
        }

        $sql .= " ORDER BY deficit_quantity DESC, warehouse_name, item_name";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Cancel a Stock IN transaction
     * Triggers MySQL trg_stock_ins_after_update which generates STOCK_IN_CANCEL ledger movements
     * and decrements inventory automatically.
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
                SELECT stock_in_id, transaction_number, source_type, source_reference_no, warehouse_id, status, created_by 
                FROM stock_ins 
                WHERE stock_in_id = ? 
                FOR UPDATE
            ");
            $stmt->execute([$stockInId]);
            $txn = $stmt->fetch();

            if (!$txn) {
                throw new InvalidArgumentException("Stock IN transaction #{$stockInId} does not exist.");
            }

            // IDOR Protection: Restrict regular admins to only cancel their own transactions
            if ($authUser !== null && ($authUser['role'] ?? '') !== 'super_admin') {
                if ((int)$txn['created_by'] !== (int)$authUser['user_id']) {
                    throw new DomainException(
                        "Access Denied (IDOR Protection): Administrator #{$authUser['user_id']} ('{$authUser['name']}') is not authorized to cancel transactions created by administrator #{$txn['created_by']}."
                    );
                }
            }

            if ($txn['status'] === 'cancelled') {
                throw new InvalidArgumentException("Stock IN transaction {$txn['transaction_number']} is already cancelled.");
            }

            if ($txn['status'] !== 'completed') {
                throw new InvalidArgumentException("Only completed transactions can be cancelled.");
            }

            // Fetch affected items before cancellation to report updated balances
            $stmtItems = $this->pdo->prepare("
                SELECT sii.item_id, i.item_code, i.item_name, i.unit, sii.quantity
                FROM stock_in_items sii
                JOIN items i ON i.item_id = sii.item_id
                WHERE sii.stock_in_id = ?
            ");
            $stmtItems->execute([$stockInId]);
            $affectedItems = $stmtItems->fetchAll();

            // Updating status to 'cancelled' fires trg_stock_ins_after_update in MySQL!
            $stmtUpdate = $this->pdo->prepare("
                UPDATE stock_ins
                SET status = 'cancelled',
                    cancelled_by = ?,
                    cancellation_reason = ?,
                    cancelled_at = NOW()
                WHERE stock_in_id = ?
            ");
            $stmtUpdate->execute([$cancelledBy, $cancellationReason, $stockInId]);

            // Query new balances after trigger executed
            $processedItems = [];
            $stmtBal = $this->pdo->prepare("
                SELECT quantity FROM inventory WHERE item_id = ? AND warehouse_id = ?
            ");
            foreach ($affectedItems as $it) {
                $stmtBal->execute([$it['item_id'], $txn['warehouse_id']]);
                $newBal = (float)$stmtBal->fetchColumn();
                $processedItems[] = [
                    'item_id'             => (int)$it['item_id'],
                    'item_code'           => $it['item_code'],
                    'item_name'           => $it['item_name'],
                    'quantity_cancelled'  => (float)$it['quantity'],
                    'unit'                => $it['unit'],
                    'balance_after'       => $newBal
                ];

                AccountabilityService::log([
                    'user_id'          => $cancelledBy,
                    'team'             => ($txn['source_type'] === 'PURCHASE_ORDER' ? 'Procurement' : ($txn['source_type'] === 'PRODUCTION_RETURN' ? 'Production' : 'Inventory')),
                    'action_type'      => 'STOCK_IN_CANCEL',
                    'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                    'item_id'          => (int)$it['item_id'],
                    'quantity'         => (float)$it['quantity'],
                    'warehouse_id'     => (int)$txn['warehouse_id'],
                    'reference_number' => $txn['transaction_number'],
                    'notes'            => "Cancelled Stock IN: " . $cancellationReason
                ]);
            }

            $this->pdo->commit();

            return [
                'stock_in_id'         => $stockInId,
                'transaction_number'  => $txn['transaction_number'],
                'source_type'         => $txn['source_type'],
                'source_reference_no' => $txn['source_reference_no'],
                'status'              => 'cancelled',
                'cancellation_reason' => $cancellationReason,
                'cancelled_by'        => $cancelledBy,
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
     * Cancel a Stock OUT transaction
     * Triggers MySQL trg_stock_outs_after_update which generates STOCK_OUT_CANCEL ledger movements
     * and restores inventory automatically.
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
                SELECT stock_out_id, transaction_number, source_type, source_reference_no, warehouse_id, status, created_by 
                FROM stock_outs 
                WHERE stock_out_id = ? 
                FOR UPDATE
            ");
            $stmt->execute([$stockOutId]);
            $txn = $stmt->fetch();

            if (!$txn) {
                throw new InvalidArgumentException("Stock OUT transaction #{$stockOutId} does not exist.");
            }

            // IDOR Protection: Restrict regular admins to only cancel their own transactions
            if ($authUser !== null && ($authUser['role'] ?? '') !== 'super_admin') {
                if ((int)$txn['created_by'] !== (int)$authUser['user_id']) {
                    throw new DomainException(
                        "Access Denied (IDOR Protection): Administrator #{$authUser['user_id']} ('{$authUser['name']}') is not authorized to cancel transactions created by administrator #{$txn['created_by']}."
                    );
                }
            }

            if ($txn['status'] === 'cancelled') {
                throw new InvalidArgumentException("Stock OUT transaction {$txn['transaction_number']} is already cancelled.");
            }

            if ($txn['status'] !== 'completed') {
                throw new InvalidArgumentException("Only completed transactions can be cancelled.");
            }

            $stmtItems = $this->pdo->prepare("
                SELECT soi.item_id, i.item_code, i.item_name, i.unit, soi.quantity
                FROM stock_out_items soi
                JOIN items i ON i.item_id = soi.item_id
                WHERE soi.stock_out_id = ?
            ");
            $stmtItems->execute([$stockOutId]);
            $affectedItems = $stmtItems->fetchAll();

            // Updating status to 'cancelled' fires trg_stock_outs_after_update in MySQL!
            $stmtUpdate = $this->pdo->prepare("
                UPDATE stock_outs
                SET status = 'cancelled',
                    cancelled_by = ?,
                    cancellation_reason = ?,
                    cancelled_at = NOW()
                WHERE stock_out_id = ?
            ");
            $stmtUpdate->execute([$cancelledBy, $cancellationReason, $stockOutId]);

            // Query new balances after trigger executed
            $processedItems = [];
            $stmtBal = $this->pdo->prepare("
                SELECT quantity FROM inventory WHERE item_id = ? AND warehouse_id = ?
            ");
            foreach ($affectedItems as $it) {
                $stmtBal->execute([$it['item_id'], $txn['warehouse_id']]);
                $newBal = (float)$stmtBal->fetchColumn();
                $processedItems[] = [
                    'item_id'            => (int)$it['item_id'],
                    'item_code'          => $it['item_code'],
                    'item_name'          => $it['item_name'],
                    'quantity_restored'  => (float)$it['quantity'],
                    'unit'               => $it['unit'],
                    'balance_after'      => $newBal
                ];

                AccountabilityService::log([
                    'user_id'          => $cancelledBy,
                    'team'             => ($txn['source_type'] === 'MATERIAL_REQUEST' ? 'Production' : ($txn['source_type'] === 'SALES_DELIVERY' ? 'Sales' : 'Inventory')),
                    'action_type'      => 'STOCK_OUT_CANCEL',
                    'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                    'item_id'          => (int)$it['item_id'],
                    'quantity'         => (float)$it['quantity'],
                    'warehouse_id'     => (int)$txn['warehouse_id'],
                    'reference_number' => $txn['transaction_number'],
                    'notes'            => "Cancelled Stock OUT: " . $cancellationReason
                ]);
            }

            $this->pdo->commit();

            return [
                'stock_out_id'        => $stockOutId,
                'transaction_number'  => $txn['transaction_number'],
                'source_type'         => $txn['source_type'],
                'source_reference_no' => $txn['source_reference_no'],
                'status'              => 'cancelled',
                'cancellation_reason' => $cancellationReason,
                'cancelled_by'        => $cancelledBy,
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
     * Inter-Warehouse Stock Transfer
     * Moves items from source warehouse to destination warehouse.
     * Generates two ledger entries: STOCK_TRANSFER_OUT and STOCK_TRANSFER_IN.
     */
    public function recordStockTransfer(
        int $sourceWhId,
        int $destWhId,
        array $items,
        int $userId,
        ?string $remarks = null
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

        $stmtWh = $this->pdo->prepare("SELECT warehouse_id, warehouse_code, warehouse_name FROM warehouses WHERE warehouse_id IN (?, ?) AND status = 'active'");
        $stmtWh->execute([$sourceWhId, $destWhId]);
        $whRows = $stmtWh->fetchAll();
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

            // Lock source items
            $stmtLock = $this->pdo->prepare("
                SELECT quantity FROM inventory 
                WHERE item_id = ? AND warehouse_id = ? 
                FOR UPDATE
            ");

            $stmtCheckItem = $this->pdo->prepare("
                SELECT item_id, item_code, item_name, unit, status 
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
                        "Duplicate item ID {$itemId} in Stock Transfer request. Combine quantities into a single line item."
                    );
                }
                $seenItemIds[$itemId] = true;

                $stmtCheckItem->execute([$itemId]);
                $itemInfo = $stmtCheckItem->fetch();
                if (!$itemInfo || $itemInfo['status'] !== 'active') {
                    throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
                }

                // Validate discrete unit constraint against the item's unit
                $qty = self::validatePositiveQuantity($rawQty, $itemInfo['unit'], "quantity for item '{$itemInfo['item_code']}'");

                $stmtLock->execute([$itemId, $sourceWhId]);
                $currentStock = $stmtLock->fetchColumn();
                $available = $currentStock !== false ? (float)$currentStock : 0.000;

                if ($available < $qty) {
                    throw new InvalidArgumentException(
                        "Insufficient inventory in source warehouse '{$whMap[$sourceWhId]['warehouse_name']}' for item '{$itemInfo['item_code']}'. Available: {$available} {$itemInfo['unit']}, Requested: {$qty} {$itemInfo['unit']}."
                    );
                }

                $validatedEntries[] = [
                    'item'     => $itemInfo,
                    'quantity' => $qty
                ];
            }

            // Insert stock_transfers header with status 'pending' (In Transit)
            $stmtHeader = $this->pdo->prepare("
                INSERT INTO stock_transfers (
                    transaction_number, source_warehouse_id, destination_warehouse_id,
                    transaction_date, status, remarks, created_by
                ) VALUES (
                    ?, ?, ?,
                    CURDATE(), 'pending', ?, ?
                )
            ");
            $stmtHeader->execute([
                $txnNumber,
                $sourceWhId,
                $destWhId,
                $remarks,
                $userId
            ]);
            $stockTransferId = (int)$this->pdo->lastInsertId();

            // Insert line items and movement OUT from source warehouse (deducts source inventory)
            $stmtLine = $this->pdo->prepare("
                INSERT INTO stock_transfer_items (stock_transfer_id, item_id, quantity)
                VALUES (?, ?, ?)
            ");

            $stmtMoveOut = $this->pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, warehouse_id, movement_type, stock_transfer_id,
                    reference_number, quantity_in, quantity_out
                ) VALUES (
                    ?, ?, 'STOCK_TRANSFER_OUT', ?,
                    ?, 0.000, ?
                )
            ");

            $stmtBal = $this->pdo->prepare("
                SELECT quantity FROM inventory WHERE item_id = ? AND warehouse_id = ?
            ");

            $processedItems = [];

            foreach ($validatedEntries as $entry) {
                $itemInfo = $entry['item'];
                $itemId = (int)$itemInfo['item_id'];
                $qty = (float)$entry['quantity'];

                $stmtLine->execute([$stockTransferId, $itemId, $qty]);
                $stmtMoveOut->execute([$itemId, $sourceWhId, $stockTransferId, $txnNumber . '-SRC', $qty]);

                // Query new balance at source
                $stmtBal->execute([$itemId, $sourceWhId]);
                $sourceBal = (float)$stmtBal->fetchColumn();

                $processedItems[] = [
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
                    'reference_number'         => $txnNumber,
                    'notes'                    => $remarks ?: "Transfer initiated from {$whMap[$sourceWhId]['warehouse_name']} to {$whMap[$destWhId]['warehouse_name']}"
                ]);
            }

            $this->pdo->commit();

            return [
                'stock_transfer_id'        => $stockTransferId,
                'transaction_number'       => $txnNumber,
                'status'                   => 'pending',
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
     * Transitions transfer from 'pending' (In Transit) to 'completed' (Received)
     * and credits destination warehouse inventory via STOCK_TRANSFER_IN movement.
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
            // Lock transfer row for atomic update
            $stmt = $this->pdo->prepare("
                SELECT st.*, 
                       sw.warehouse_name AS source_warehouse_name, sw.warehouse_code AS source_warehouse_code,
                       dw.warehouse_name AS dest_warehouse_name, dw.warehouse_code AS dest_warehouse_code
                FROM stock_transfers st
                JOIN warehouses sw ON sw.warehouse_id = st.source_warehouse_id
                JOIN warehouses dw ON dw.warehouse_id = st.destination_warehouse_id
                WHERE st.stock_transfer_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$stockTransferId]);
            $txn = $stmt->fetch();

            if (!$txn) {
                throw new InvalidArgumentException("Stock transfer #{$stockTransferId} does not exist.");
            }

            // Prevent double confirmation
            if ($txn['status'] === 'completed') {
                throw new DomainException(
                    "This stock transfer ({$txn['transaction_number']}) has already been confirmed and received."
                );
            }
            if ($txn['status'] === 'cancelled') {
                throw new DomainException(
                    "Cannot receive cancelled stock transfer ({$txn['transaction_number']})."
                );
            }
            if ($txn['status'] !== 'pending') {
                throw new DomainException(
                    "Stock transfer {$txn['transaction_number']} is not in pending / in-transit status."
                );
            }

            // Security: Strictly restrict receiving confirmation to destination warehouse (or super_admin)
            if ($authUser !== null && ($authUser['role'] ?? '') !== 'super_admin') {
                $userWhId = (int)($authUser['warehouse_id'] ?? 0);
                if ((int)$txn['destination_warehouse_id'] !== $userWhId) {
                    throw new DomainException(
                        "Access Denied: Only administrators assigned to the destination warehouse ('{$txn['dest_warehouse_name']}') can confirm receipt of this stock transfer."
                    );
                }
            }

            // Fetch line items
            $stmtItems = $this->pdo->prepare("
                SELECT sti.item_id, sti.quantity, i.item_code, i.item_name, i.unit, i.item_type
                FROM stock_transfer_items sti
                JOIN items i ON i.item_id = sti.item_id
                WHERE sti.stock_transfer_id = ?
            ");
            $stmtItems->execute([$stockTransferId]);
            $items = $stmtItems->fetchAll();

            if (empty($items)) {
                throw new DomainException("No items found in stock transfer #{$stockTransferId}.");
            }

            // Fetch receiver's name for audit trail
            $stmtUser = $this->pdo->prepare("SELECT name FROM users WHERE user_id = ?");
            $stmtUser->execute([$userId]);
            $receiverName = $stmtUser->fetchColumn() ?: 'Warehouse Operator';

            // Insert STOCK_TRANSFER_IN movement for destination warehouse
            $stmtMoveIn = $this->pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, warehouse_id, movement_type, stock_transfer_id,
                    reference_number, quantity_in, quantity_out
                ) VALUES (
                    ?, ?, 'STOCK_TRANSFER_IN', ?,
                    ?, ?, 0.000
                )
            ");

            $stmtBal = $this->pdo->prepare("
                SELECT quantity FROM inventory WHERE item_id = ? AND warehouse_id = ?
            ");

            $processedItems = [];
            foreach ($items as $it) {
                $itemId = (int)$it['item_id'];
                $qty = (float)$it['quantity'];

                $stmtMoveIn->execute([
                    $itemId, 
                    (int)$txn['destination_warehouse_id'], 
                    $stockTransferId, 
                    $txn['transaction_number'] . '-DEST', 
                    $qty
                ]);

                $stmtBal->execute([$itemId, (int)$txn['destination_warehouse_id']]);
                $destBal = (float)$stmtBal->fetchColumn();

                $processedItems[] = [
                    'item_id'            => $itemId,
                    'item_code'          => $it['item_code'],
                    'item_name'          => $it['item_name'],
                    'quantity_received'  => $qty,
                    'unit'               => $it['unit'],
                    'dest_balance_after' => $destBal
                ];

                AccountabilityService::log([
                    'user_id'                  => $userId,
                    'user_name'                => $receiverName,
                    'team'                     => 'Inventory',
                    'action_type'              => 'TRANSFER_RECEIVED',
                    'channel'                  => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                    'item_id'                  => $itemId,
                    'quantity'                 => $qty,
                    'warehouse_id'             => (int)$txn['destination_warehouse_id'],
                    'destination_warehouse_id' => (int)$txn['source_warehouse_id'],
                    'reference_number'         => $txn['transaction_number'],
                    'notes'                    => "Stock transfer delivery confirmed and received into {$txn['dest_warehouse_name']} from {$txn['source_warehouse_name']}"
                ]);
            }

            // Update transfer status to 'completed', record received_by/at, and append audit confirmation note
            $auditNote = "[Received by {$receiverName} on " . date('Y-m-d H:i:s') . "]";
            $updatedRemarks = trim(($txn['remarks'] ?? '') . "\n" . $auditNote);

            $stmtUpdate = $this->pdo->prepare("
                UPDATE stock_transfers
                SET status = 'completed',
                    received_by = ?,
                    received_at = NOW(),
                    remarks = ?
                WHERE stock_transfer_id = ? AND status = 'pending'
            ");
            $stmtUpdate->execute([$userId, $updatedRemarks, $stockTransferId]);

            $this->pdo->commit();

            return [
                'stock_transfer_id'     => $stockTransferId,
                'transaction_number'    => $txn['transaction_number'],
                'status'                => 'completed',
                'source_warehouse'      => $txn['source_warehouse_name'],
                'destination_warehouse' => $txn['dest_warehouse_name'],
                'receiver_name'         => $receiverName,
                'items'                 => $processedItems
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
     * Triggers MySQL trg_stock_transfers_after_update which automatically:
     * 1. Deducts quantity from destination warehouse (STOCK_TRANSFER_CANCEL).
     * 2. Restores quantity to source warehouse (STOCK_TRANSFER_CANCEL).
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
                SELECT stock_transfer_id, transaction_number, source_warehouse_id, destination_warehouse_id, status, created_by 
                FROM stock_transfers 
                WHERE stock_transfer_id = ? 
                FOR UPDATE
            ");
            $stmt->execute([$stockTransferId]);
            $txn = $stmt->fetch();

            if (!$txn) {
                throw new InvalidArgumentException("Stock transfer #{$stockTransferId} does not exist.");
            }

            // Restrict regular admins to only cancel transfers originating from their assigned warehouse
            if ($authUser !== null && ($authUser['role'] ?? '') !== 'super_admin') {
                $userWhId = (int)($authUser['warehouse_id'] ?? 0);
                if ((int)$txn['source_warehouse_id'] !== $userWhId) {
                    throw new DomainException(
                        "Access Denied: Only administrators from the source warehouse are authorized to cancel this transfer."
                    );
                }
            }

            if ($txn['status'] === 'cancelled') {
                throw new InvalidArgumentException("Stock transfer {$txn['transaction_number']} is already cancelled.");
            }
            if (!in_array($txn['status'], ['pending', 'completed'], true)) {
                throw new InvalidArgumentException("Only pending or completed transfers can be cancelled.");
            }

            $stmtItems = $this->pdo->prepare("
                SELECT sti.item_id, i.item_code, i.item_name, i.unit, sti.quantity
                FROM stock_transfer_items sti
                JOIN items i ON i.item_id = sti.item_id
                WHERE sti.stock_transfer_id = ?
            ");
            $stmtItems->execute([$stockTransferId]);
            $affectedItems = $stmtItems->fetchAll();

            // If pending, stock was deducted from source but never credited to destination.
            // MySQL trigger trg_stock_transfers_after_update only fires on completed -> cancelled,
            // so we restore stock to source warehouse explicitly.
            if ($txn['status'] === 'pending') {
                $stmtRestore = $this->pdo->prepare("
                    INSERT INTO stock_movements (
                        item_id, warehouse_id, movement_type, stock_transfer_id,
                        reference_number, quantity_in, quantity_out
                    ) VALUES (
                        ?, ?, 'STOCK_TRANSFER_CANCEL', ?,
                        ?, ?, 0.000
                    )
                ");
                foreach ($affectedItems as $it) {
                    $stmtRestore->execute([
                        $it['item_id'],
                        $txn['source_warehouse_id'],
                        $stockTransferId,
                        $txn['transaction_number'] . '-CAN-SRC',
                        $it['quantity']
                    ]);
                }
            }

            $stmtItems = $this->pdo->prepare("
                SELECT sti.item_id, i.item_code, i.item_name, i.unit, sti.quantity
                FROM stock_transfer_items sti
                JOIN items i ON i.item_id = sti.item_id
                WHERE sti.stock_transfer_id = ?
            ");
            $stmtItems->execute([$stockTransferId]);
            $affectedItems = $stmtItems->fetchAll();

            // Updating status to 'cancelled' fires trg_stock_transfers_after_update in MySQL!
            $stmtUpdate = $this->pdo->prepare("
                UPDATE stock_transfers
                SET status = 'cancelled',
                    cancelled_by = ?,
                    cancellation_reason = ?,
                    cancelled_at = NOW()
                WHERE stock_transfer_id = ?
            ");
            $stmtUpdate->execute([$cancelledBy, $cancellationReason, $stockTransferId]);

            $stmtBal = $this->pdo->prepare("
                SELECT quantity FROM inventory WHERE item_id = ? AND warehouse_id = ?
            ");

            $processedItems = [];
            foreach ($affectedItems as $it) {
                $stmtBal->execute([$it['item_id'], $txn['source_warehouse_id']]);
                $srcBal = (float)$stmtBal->fetchColumn();

                $stmtBal->execute([$it['item_id'], $txn['destination_warehouse_id']]);
                $destBal = (float)$stmtBal->fetchColumn();

                $processedItems[] = [
                    'item_id'              => (int)$it['item_id'],
                    'item_code'            => $it['item_code'],
                    'item_name'            => $it['item_name'],
                    'quantity_cancelled'   => (float)$it['quantity'],
                    'unit'                 => $it['unit'],
                    'source_balance_after' => $srcBal,
                    'dest_balance_after'   => $destBal
                ];

                AccountabilityService::log([
                    'user_id'                  => $cancelledBy,
                    'team'                     => 'Inventory',
                    'action_type'              => 'TRANSFER_CANCELLED',
                    'channel'                  => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                    'item_id'                  => (int)$it['item_id'],
                    'quantity'                 => (float)$it['quantity'],
                    'warehouse_id'             => (int)$txn['source_warehouse_id'],
                    'destination_warehouse_id' => (int)$txn['destination_warehouse_id'],
                    'reference_number'         => $txn['transaction_number'],
                    'notes'                    => "Transfer cancelled: " . $cancellationReason
                ]);
            }

            $this->pdo->commit();

            return [
                'stock_transfer_id'   => $stockTransferId,
                'transaction_number'  => $txn['transaction_number'],
                'status'              => 'cancelled',
                'cancellation_reason' => $cancellationReason,
                'cancelled_by'        => $cancelledBy,
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
     * Get Stock IN Details (Protected against IDOR)
     */
    public function getStockInDetails(int $stockInId, array $authUser): array {
        $stmt = $this->pdo->prepare("
            SELECT si.*, w.warehouse_code, w.warehouse_name, u.name AS creator_name, uc.name AS canceller_name
            FROM stock_ins si
            JOIN warehouses w ON w.warehouse_id = si.warehouse_id
            JOIN users u ON u.user_id = si.created_by
            LEFT JOIN users uc ON uc.user_id = si.cancelled_by
            WHERE si.stock_in_id = ?
        ");
        $stmt->execute([$stockInId]);
        $txn = $stmt->fetch();

        if (!$txn) {
            throw new InvalidArgumentException("Stock IN transaction #{$stockInId} not found.");
        }

        // IDOR Protection: An admin cannot inspect transactions created by another admin unless they are super_admin
        if (($authUser['role'] ?? '') !== 'super_admin' && (int)$txn['created_by'] !== (int)$authUser['user_id']) {
            throw new DomainException(
                "Access Denied (IDOR Protection): Administrator #{$authUser['user_id']} ('{$authUser['name']}') is not authorized to view transactions created by administrator #{$txn['created_by']}."
            );
        }

        $stmtItems = $this->pdo->prepare("
            SELECT sii.*, i.item_code, i.item_name, i.unit, i.item_type
            FROM stock_in_items sii
            JOIN items i ON i.item_id = sii.item_id
            WHERE sii.stock_in_id = ?
        ");
        $stmtItems->execute([$stockInId]);
        $txn['items'] = $stmtItems->fetchAll();

        return $txn;
    }

    /**
     * Get Stock OUT Details (Protected against IDOR)
     */
    public function getStockOutDetails(int $stockOutId, array $authUser): array {
        $stmt = $this->pdo->prepare("
            SELECT so.*, w.warehouse_code, w.warehouse_name, u.name AS creator_name, uc.name AS canceller_name
            FROM stock_outs so
            JOIN warehouses w ON w.warehouse_id = so.warehouse_id
            JOIN users u ON u.user_id = so.created_by
            LEFT JOIN users uc ON uc.user_id = so.cancelled_by
            WHERE so.stock_out_id = ?
        ");
        $stmt->execute([$stockOutId]);
        $txn = $stmt->fetch();

        if (!$txn) {
            throw new InvalidArgumentException("Stock OUT transaction #{$stockOutId} not found.");
        }

        // IDOR Protection: An admin cannot inspect transactions created by another admin unless they are super_admin
        if (($authUser['role'] ?? '') !== 'super_admin' && (int)$txn['created_by'] !== (int)$authUser['user_id']) {
            throw new DomainException(
                "Access Denied (IDOR Protection): Administrator #{$authUser['user_id']} ('{$authUser['name']}') is not authorized to view transactions created by administrator #{$txn['created_by']}."
            );
        }

        $stmtItems = $this->pdo->prepare("
            SELECT soi.*, i.item_code, i.item_name, i.unit, i.item_type
            FROM stock_out_items soi
            JOIN items i ON i.item_id = soi.item_id
            WHERE soi.stock_out_id = ?
        ");
        $stmtItems->execute([$stockOutId]);
        $txn['items'] = $stmtItems->fetchAll();

        return $txn;
    }

    /**
     * Get Stock Transfer Details (Protected against IDOR)
     */
    public function getStockTransferDetails(int $stockTransferId, array $authUser): array {
        $stmt = $this->pdo->prepare("
            SELECT st.*, 
                   sw.warehouse_code AS source_warehouse_code, sw.warehouse_name AS source_warehouse_name,
                   dw.warehouse_code AS dest_warehouse_code, dw.warehouse_name AS dest_warehouse_name,
                   u.name AS creator_name, uc.name AS canceller_name
            FROM stock_transfers st
            JOIN warehouses sw ON sw.warehouse_id = st.source_warehouse_id
            JOIN warehouses dw ON dw.warehouse_id = st.destination_warehouse_id
            JOIN users u ON u.user_id = st.created_by
            LEFT JOIN users uc ON uc.user_id = st.cancelled_by
            WHERE st.stock_transfer_id = ?
        ");
        $stmt->execute([$stockTransferId]);
        $txn = $stmt->fetch();

        if (!$txn) {
            throw new InvalidArgumentException("Stock transfer transaction #{$stockTransferId} not found.");
        }

        // Warehouse Isolation & Access Security: Allow involved warehouses (source or destination) or super_admin
        if (($authUser['role'] ?? '') !== 'super_admin') {
            $userWhId = (int)($authUser['warehouse_id'] ?? 0);
            if ((int)$txn['source_warehouse_id'] !== $userWhId && (int)$txn['destination_warehouse_id'] !== $userWhId) {
                throw new DomainException(
                    "Access Denied: Administrator #{$authUser['user_id']} ('{$authUser['name']}') is not authorized to view transfers unrelated to their assigned warehouse."
                );
            }
        }

        $stmtItems = $this->pdo->prepare("
            SELECT sti.*, i.item_code, i.item_name, i.unit, i.item_type
            FROM stock_transfer_items sti
            JOIN items i ON i.item_id = sti.item_id
            WHERE sti.stock_transfer_id = ?
        ");
        $stmtItems->execute([$stockTransferId]);
        $txn['items'] = $stmtItems->fetchAll();

        return $txn;
    }

    /**
     * List Stock INs (Scoped by IDOR privacy)
     */
    public function listStockIns(array $authUser, int $limit = 50): array {
        $limit = max(1, min(200, $limit));

        if (($authUser['role'] ?? '') === 'super_admin') {
            $stmt = $this->pdo->prepare("
                SELECT si.stock_in_id, si.transaction_number, si.source_type, si.source_reference_no,
                       si.warehouse_id, w.warehouse_name, si.transaction_date, si.status,
                       si.created_by, u.name AS creator_name, si.created_at
                FROM stock_ins si
                JOIN warehouses w ON w.warehouse_id = si.warehouse_id
                JOIN users u ON u.user_id = si.created_by
                ORDER BY si.stock_in_id DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $this->pdo->prepare("
                SELECT si.stock_in_id, si.transaction_number, si.source_type, si.source_reference_no,
                       si.warehouse_id, w.warehouse_name, si.transaction_date, si.status,
                       si.created_by, u.name AS creator_name, si.created_at
                FROM stock_ins si
                JOIN warehouses w ON w.warehouse_id = si.warehouse_id
                JOIN users u ON u.user_id = si.created_by
                WHERE si.created_by = ?
                ORDER BY si.stock_in_id DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, (int)$authUser['user_id'], PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
        }

        return $stmt->fetchAll();
    }

    /**
     * List Stock OUTs (Scoped by IDOR privacy)
     */
    public function listStockOuts(array $authUser, int $limit = 50): array {
        $limit = max(1, min(200, $limit));

        if (($authUser['role'] ?? '') === 'super_admin') {
            $stmt = $this->pdo->prepare("
                SELECT so.stock_out_id, so.transaction_number, so.source_type, so.source_reference_no,
                       so.warehouse_id, w.warehouse_name, so.transaction_date, so.status,
                       so.created_by, u.name AS creator_name, so.created_at
                FROM stock_outs so
                JOIN warehouses w ON w.warehouse_id = so.warehouse_id
                JOIN users u ON u.user_id = so.created_by
                ORDER BY so.stock_out_id DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $this->pdo->prepare("
                SELECT so.stock_out_id, so.transaction_number, so.source_type, so.source_reference_no,
                       so.warehouse_id, w.warehouse_name, so.transaction_date, so.status,
                       so.created_by, u.name AS creator_name, so.created_at
                FROM stock_outs so
                JOIN warehouses w ON w.warehouse_id = so.warehouse_id
                JOIN users u ON u.user_id = so.created_by
                WHERE so.created_by = ?
                ORDER BY so.stock_out_id DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, (int)$authUser['user_id'], PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
        }

        return $stmt->fetchAll();
    }

    /**
     * List Stock Transfers (Scoped by IDOR privacy)
     */
    public function listStockTransfers(array $authUser, int $limit = 50): array {
        $limit = max(1, min(200, $limit));

        if (($authUser['role'] ?? '') === 'super_admin') {
            $stmt = $this->pdo->prepare("
                SELECT st.stock_transfer_id, st.transaction_number, 
                       st.source_warehouse_id, sw.warehouse_name AS source_warehouse_name,
                       st.destination_warehouse_id, dw.warehouse_name AS dest_warehouse_name,
                       st.transaction_date, st.status, st.created_by, u.name AS creator_name, st.created_at
                FROM stock_transfers st
                JOIN warehouses sw ON sw.warehouse_id = st.source_warehouse_id
                JOIN warehouses dw ON dw.warehouse_id = st.destination_warehouse_id
                JOIN users u ON u.user_id = st.created_by
                ORDER BY st.stock_transfer_id DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $userWhId = (int)($authUser['warehouse_id'] ?? 0);
            $stmt = $this->pdo->prepare("
                SELECT st.stock_transfer_id, st.transaction_number, 
                       st.source_warehouse_id, sw.warehouse_name AS source_warehouse_name,
                       st.destination_warehouse_id, dw.warehouse_name AS dest_warehouse_name,
                       st.transaction_date, st.status, st.created_by, u.name AS creator_name, st.created_at
                FROM stock_transfers st
                JOIN warehouses sw ON sw.warehouse_id = st.source_warehouse_id
                JOIN warehouses dw ON dw.warehouse_id = st.destination_warehouse_id
                JOIN users u ON u.user_id = st.created_by
                WHERE st.source_warehouse_id = ? OR st.destination_warehouse_id = ?
                ORDER BY st.stock_transfer_id DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, $userWhId, PDO::PARAM_INT);
            $stmt->bindValue(2, $userWhId, PDO::PARAM_INT);
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
            $stmt->execute();
        }

        return $stmt->fetchAll();
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
            if ($userWhId !== $warehouseId) {
                throw new DomainException("Access Denied: You cannot create stock adjustments for a facility other than your assigned warehouse.");
            }
        }

        // Validate warehouse exists and is active
        $stmtWh = $this->pdo->prepare("SELECT warehouse_name FROM warehouses WHERE warehouse_id = ? AND status = 'active'");
        $stmtWh->execute([$warehouseId]);
        $wh = $stmtWh->fetch();
        if (!$wh) {
            throw new InvalidArgumentException("Active warehouse with ID {$warehouseId} not found.");
        }

        $this->pdo->beginTransaction();
        try {
            $txnNumber = 'ADJ-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            $stmtAdj = $this->pdo->prepare("
                INSERT INTO stock_adjustments (
                    transaction_number, warehouse_id, adjustment_date,
                    reason, status, created_by
                ) VALUES (?, ?, ?, ?, 'pending', ?)
            ");
            $stmtAdj->execute([$txnNumber, $warehouseId, $adjustmentDate, $reason, $userId]);
            $adjId = (int)$this->pdo->lastInsertId();

            $stmtCheckItem = $this->pdo->prepare("
                SELECT item_id, item_code, item_name, unit, status FROM items WHERE item_id = ?
            ");
            $stmtLockInv = $this->pdo->prepare("
                SELECT quantity FROM inventory WHERE item_id = ? AND warehouse_id = ? FOR UPDATE
            ");
            $stmtLine = $this->pdo->prepare("
                INSERT INTO stock_adjustment_items (
                    stock_adjustment_id, item_id, previous_quantity, adjusted_quantity
                ) VALUES (?, ?, ?, ?)
            ");

            $processedItems = [];
            $seenItemIds = [];

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
                $itemInfo = $stmtCheckItem->fetch();
                if (!$itemInfo || $itemInfo['status'] !== 'active') {
                    throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
                }

                // Validate discrete unit constraint against the item's unit
                $adjustedQty = self::validateNonNegativeQuantity(
                    $rawAdjustedQty,
                    $itemInfo['unit'],
                    "adjusted_quantity for item '{$itemInfo['item_code']}'"
                );

                // Lock inventory row and fetch current quantity
                $stmtLockInv->execute([$itemId, $warehouseId]);
                $currentStock = $stmtLockInv->fetchColumn();
                $previousQty = ($currentStock !== false) ? (float)$currentStock : 0.000;

                // Reject no-op adjustments where physical count equals current system balance
                if (abs($adjustedQty - $previousQty) < 0.0001) {
                    throw new InvalidArgumentException(
                        "No-op stock adjustment rejected for item '{$itemInfo['item_code']}': physical adjusted count ({$adjustedQty}) is identical to current system stock ({$previousQty})."
                    );
                }

                $stmtLine->execute([$adjId, $itemId, $previousQty, $adjustedQty]);
                $diff = round($adjustedQty - $previousQty, 3);

                $processedItems[] = [
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
                    'reference_number' => $txnNumber,
                    'notes'            => "Adjustment requested: " . $reason . " (Diff: " . ($diff >= 0 ? "+{$diff}" : $diff) . ")"
                ]);
            }

            $this->pdo->commit();

            return [
                'stock_adjustment_id' => $adjId,
                'transaction_number'  => $txnNumber,
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
     * Approve a pending Stock Adjustment and post ledger movements to adjust inventory
     */
    public function approveStockAdjustment(int $adjustmentId, int $userId, ?array $authUser = null): array {
        if ($adjustmentId <= 0) {
            throw new InvalidArgumentException("Invalid stock_adjustment_id.");
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM stock_adjustments WHERE stock_adjustment_id = ? FOR UPDATE
            ");
            $stmt->execute([$adjustmentId]);
            $adj = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$adj) {
                throw new InvalidArgumentException("Stock adjustment record #{$adjustmentId} not found.");
            }

            if ($adj['status'] !== 'pending') {
                throw new DomainException("Adjustment {$adj['transaction_number']} cannot be approved because its current status is '{$adj['status']}'.");
            }

            // Authorization check: Super admin or admin belonging to adjustment warehouse
            if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
                $userWhId = (int)($authUser['warehouse_id'] ?? 0);
                if ($userWhId !== (int)$adj['warehouse_id']) {
                    throw new DomainException("Access Denied: You are not authorized to approve adjustments for other warehouses.");
                }
            }

            // Update status to approved
            $stmtApprove = $this->pdo->prepare("
                UPDATE stock_adjustments
                SET status = 'approved', approved_by = ?, approved_at = NOW()
                WHERE stock_adjustment_id = ?
            ");
            $stmtApprove->execute([$userId, $adjustmentId]);

            // Fetch line items
            $stmtLines = $this->pdo->prepare("
                SELECT sai.*, i.item_code, i.item_name
                FROM stock_adjustment_items sai
                JOIN items i ON sai.item_id = i.item_id
                WHERE sai.stock_adjustment_id = ?
            ");
            $stmtLines->execute([$adjustmentId]);
            $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

            $stmtLockInv = $this->pdo->prepare("
                SELECT quantity FROM inventory WHERE item_id = ? AND warehouse_id = ? FOR UPDATE
            ");

            $stmtMove = $this->pdo->prepare("
                INSERT INTO stock_movements (
                    item_id, warehouse_id, movement_type, stock_adjustment_id,
                    reference_number, quantity_in, quantity_out
                ) VALUES (?, ?, 'STOCK_ADJUSTMENT', ?, ?, ?, ?)
            ");

            foreach ($lines as $line) {
                // Lock live inventory row and detect stale previous_quantity race condition
                $stmtLockInv->execute([$line['item_id'], $adj['warehouse_id']]);
                $liveStockRaw = $stmtLockInv->fetchColumn();
                $liveStock = ($liveStockRaw !== false) ? (float)$liveStockRaw : 0.000;
                $recordedPrev = (float)$line['previous_quantity'];
                $diff = (float)$line['difference'];

                if (abs($liveStock - $recordedPrev) >= 0.001) {
                    throw new DomainException(
                        "Stale stock adjustment error for item '{$line['item_code']}': System inventory changed since this adjustment was requested (Recorded snapshot: {$recordedPrev}, Current live stock: {$liveStock}). Please reject or cancel this adjustment and submit a new physical count."
                    );
                }

                if (($liveStock + $diff) < -0.0001) {
                    throw new DomainException(
                        "Insufficient inventory to approve shortage adjustment for item '{$line['item_code']}': Current stock is {$liveStock}, cannot deduct " . abs($diff) . "."
                    );
                }

                if ($diff > 0) {
                    // Surplus: quantity_in = diff, quantity_out = 0
                    $stmtMove->execute([
                        $line['item_id'],
                        $adj['warehouse_id'],
                        $adjustmentId,
                        $adj['transaction_number'],
                        $diff,
                        0.000
                    ]);
                } elseif ($diff < 0) {
                    // Shortage: quantity_in = 0, quantity_out = abs(diff)
                    $stmtMove->execute([
                        $line['item_id'],
                        $adj['warehouse_id'],
                        $adjustmentId,
                        $adj['transaction_number'],
                        0.000,
                        abs($diff)
                    ]);
                }

                AccountabilityService::log([
                    'user_id'          => $userId,
                    'team'             => 'Inventory',
                    'action_type'      => 'ADJUSTMENT_APPROVED',
                    'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                    'item_id'          => (int)$line['item_id'],
                    'quantity'         => (float)$line['difference'],
                    'warehouse_id'     => (int)$adj['warehouse_id'],
                    'reference_number' => $adj['transaction_number'],
                    'notes'            => "Adjustment approved: " . $adj['reason'] . " (Diff: " . ($diff >= 0 ? "+{$diff}" : $diff) . ")"
                ]);
            }

            $this->pdo->commit();

            return [
                'stock_adjustment_id' => $adjustmentId,
                'transaction_number'  => $adj['transaction_number'],
                'status'              => 'approved',
                'approved_by'         => $userId
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reject a pending Stock Adjustment (No stock movements generated)
     */
    public function rejectStockAdjustment(int $adjustmentId, int $userId, string $reason, ?array $authUser = null): array {
        if ($adjustmentId <= 0) {
            throw new InvalidArgumentException("Invalid stock_adjustment_id.");
        }
        $reason = trim($reason);
        if (empty($reason)) {
            throw new InvalidArgumentException("A reason is required when rejecting a stock adjustment.");
        }
        if (mb_strlen($reason) > 255) {
            throw new InvalidArgumentException("Rejection reason cannot exceed 255 characters.");
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM stock_adjustments WHERE stock_adjustment_id = ? FOR UPDATE
            ");
            $stmt->execute([$adjustmentId]);
            $adj = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$adj) {
                throw new InvalidArgumentException("Stock adjustment record #{$adjustmentId} not found.");
            }

            if ($adj['status'] !== 'pending') {
                throw new DomainException("Adjustment {$adj['transaction_number']} cannot be rejected because its current status is '{$adj['status']}'.");
            }

            // Authorization check
            if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
                $userWhId = (int)($authUser['warehouse_id'] ?? 0);
                if ($userWhId !== (int)$adj['warehouse_id']) {
                    throw new DomainException("Access Denied: You are not authorized to reject adjustments for other warehouses.");
                }
            }

            $stmtReject = $this->pdo->prepare("
                UPDATE stock_adjustments
                SET status = 'rejected', cancelled_by = ?, cancelled_at = NOW(), cancellation_reason = ?
                WHERE stock_adjustment_id = ?
            ");
            $stmtReject->execute([$userId, $reason, $adjustmentId]);

            AccountabilityService::log([
                'user_id'          => $userId,
                'team'             => 'Inventory',
                'action_type'      => 'ADJUSTMENT_REJECTED',
                'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'warehouse_id'     => (int)$adj['warehouse_id'],
                'reference_number' => $adj['transaction_number'],
                'notes'            => "Adjustment rejected: " . $reason
            ]);

            $this->pdo->commit();

            return [
                'stock_adjustment_id' => $adjustmentId,
                'transaction_number'  => $adj['transaction_number'],
                'status'              => 'rejected',
                'rejected_by'         => $userId
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cancel a Stock Adjustment (Pending is cancelled cleanly; Approved reverses movements via MySQL trigger)
     */
    public function cancelStockAdjustment(int $adjustmentId, string $reason, int $userId, ?array $authUser = null): array {
        if ($adjustmentId <= 0) {
            throw new InvalidArgumentException("Invalid stock_adjustment_id.");
        }
        $reason = trim($reason);
        if (empty($reason)) {
            throw new InvalidArgumentException("A reason is required to cancel a stock adjustment.");
        }
        if (mb_strlen($reason) > 255) {
            throw new InvalidArgumentException("Cancellation reason cannot exceed 255 characters.");
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM stock_adjustments WHERE stock_adjustment_id = ? FOR UPDATE
            ");
            $stmt->execute([$adjustmentId]);
            $adj = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$adj) {
                throw new InvalidArgumentException("Stock adjustment record #{$adjustmentId} not found.");
            }

            if ($adj['status'] === 'cancelled') {
                throw new DomainException("Stock adjustment {$adj['transaction_number']} is already cancelled.");
            }

            if ($adj['status'] === 'rejected') {
                throw new DomainException("Cannot cancel a rejected stock adjustment.");
            }

            // Authorization check
            if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
                $userWhId = (int)($authUser['warehouse_id'] ?? 0);
                if ($userWhId !== (int)$adj['warehouse_id']) {
                    throw new DomainException("Access Denied: You are not authorized to cancel adjustments for other warehouses.");
                }
            }

            // Updating status to 'cancelled' fires trg_stock_adjustments_after_update in MySQL if it was 'approved'
            $stmtCancel = $this->pdo->prepare("
                UPDATE stock_adjustments
                SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), cancellation_reason = ?
                WHERE stock_adjustment_id = ?
            ");
            $stmtCancel->execute([$userId, $reason, $adjustmentId]);

            AccountabilityService::log([
                'user_id'          => $userId,
                'team'             => 'Inventory',
                'action_type'      => 'ADJUSTMENT_CANCELLED',
                'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'warehouse_id'     => (int)$adj['warehouse_id'],
                'reference_number' => $adj['transaction_number'],
                'notes'            => "Adjustment cancelled: " . $reason
            ]);

            $this->pdo->commit();

            return [
                'stock_adjustment_id' => $adjustmentId,
                'transaction_number'  => $adj['transaction_number'],
                'previous_status'     => $adj['status'],
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
     * Get Stock Adjustment Details with line items
     */
    public function getStockAdjustmentDetails(int $adjustmentId, ?array $authUser = null): array {
        $stmt = $this->pdo->prepare("
            SELECT sa.*, w.warehouse_code, w.warehouse_name,
                   uc.name AS created_by_name,
                   ua.name AS approved_by_name,
                   ux.name AS cancelled_by_name
            FROM stock_adjustments sa
            JOIN warehouses w ON sa.warehouse_id = w.warehouse_id
            JOIN users uc ON sa.created_by = uc.user_id
            LEFT JOIN users ua ON sa.approved_by = ua.user_id
            LEFT JOIN users ux ON sa.cancelled_by = ux.user_id
            WHERE sa.stock_adjustment_id = ?
        ");
        $stmt->execute([$adjustmentId]);
        $adj = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$adj) {
            throw new InvalidArgumentException("Stock adjustment record #{$adjustmentId} not found.");
        }

        if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
            $userWhId = (int)($authUser['warehouse_id'] ?? 0);
            if ($userWhId !== (int)$adj['warehouse_id']) {
                throw new DomainException("Access Denied: You are not authorized to view adjustments for other warehouses.");
            }
        }

        $stmtItems = $this->pdo->prepare("
            SELECT sai.*, i.item_code, i.item_name, i.item_type, i.unit
            FROM stock_adjustment_items sai
            JOIN items i ON sai.item_id = i.item_id
            WHERE sai.stock_adjustment_id = ?
            ORDER BY sai.stock_adjustment_item_id ASC
        ");
        $stmtItems->execute([$adjustmentId]);
        $adj['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        return $adj;
    }

    /**
     * Record a Bad Product write-off (Damaged, Defective, Expired, Spoiled)
     * Automatically triggers MySQL trg_bad_products_after_insert to deduct available stock.
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

        $validConditions = ['damaged', 'defective', 'expired', 'spoiled', 'unusable', 'other'];
        if (!in_array(strtolower($conditionType), $validConditions, true)) {
            throw new InvalidArgumentException("Invalid condition type. Must be one of: " . implode(', ', $validConditions));
        }

        // Warehouse authorization check
        if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
            $userWhId = (int)($authUser['warehouse_id'] ?? 0);
            if ($userWhId !== $warehouseId) {
                throw new DomainException("Access Denied: You cannot report damaged goods for another warehouse.");
            }
        }

        // Validate warehouse exists and is active
        $stmtWh = $this->pdo->prepare("SELECT warehouse_name FROM warehouses WHERE warehouse_id = ? AND status = 'active'");
        $stmtWh->execute([$warehouseId]);
        if (!$stmtWh->fetch()) {
            throw new InvalidArgumentException("Active warehouse with ID {$warehouseId} not found.");
        }

        $this->pdo->beginTransaction();
        try {
            // Verify item
            $stmtItem = $this->pdo->prepare("SELECT item_code, item_name, unit, status FROM items WHERE item_id = ?");
            $stmtItem->execute([$itemId]);
            $item = $stmtItem->fetch();
            if (!$item || $item['status'] !== 'active') {
                throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
            }

            // Validate discrete unit constraint against the item's unit
            $quantity = self::validatePositiveQuantity($quantity, $item['unit'], "quantity for item '{$item['item_code']}'");

            // Lock inventory row and check current stock
            $stmtLock = $this->pdo->prepare("
                SELECT quantity FROM inventory WHERE item_id = ? AND warehouse_id = ? FOR UPDATE
            ");
            $stmtLock->execute([$itemId, $warehouseId]);
            $currentStock = (float)($stmtLock->fetchColumn() ?: 0.000);

            if ($quantity > $currentStock) {
                throw new DomainException(
                    "Insufficient stock: Cannot write off {$quantity} units of '{$item['item_name']}'. Only {$currentStock} units currently available in this warehouse."
                );
            }

            $bpNumber = 'BP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            // Inserting into bad_products with status='completed' fires trg_bad_products_after_insert
            $stmtBp = $this->pdo->prepare("
                INSERT INTO bad_products (
                    bad_product_number, item_id, warehouse_id, condition_type,
                    quantity, reason, status, reported_by
                ) VALUES (?, ?, ?, ?, ?, ?, 'completed', ?)
            ");
            $stmtBp->execute([
                $bpNumber,
                $itemId,
                $warehouseId,
                strtolower($conditionType),
                $quantity,
                $reason,
                $userId
            ]);
            $badProductId = (int)$this->pdo->lastInsertId();

            AccountabilityService::log([
                'user_id'          => $userId,
                'team'             => 'Inventory',
                'action_type'      => 'BAD_PRODUCT',
                'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'item_id'          => $itemId,
                'quantity'         => $quantity,
                'warehouse_id'     => $warehouseId,
                'reference_number' => $bpNumber,
                'notes'            => "Defect write-off: [{$conditionType}] " . $reason
            ]);

            $this->pdo->commit();

            return [
                'bad_product_id'     => $badProductId,
                'bad_product_number' => $bpNumber,
                'item_id'            => $itemId,
                'item_code'          => $item['item_code'],
                'item_name'          => $item['item_name'],
                'warehouse_id'       => $warehouseId,
                'condition_type'     => strtolower($conditionType),
                'quantity'           => $quantity,
                'unit'               => $item['unit'],
                'status'             => 'completed'
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cancel a Bad Product report (Restores deducted stock via MySQL trg_bad_products_after_update)
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
            $stmt = $this->pdo->prepare("
                SELECT * FROM bad_products WHERE bad_product_id = ? FOR UPDATE
            ");
            $stmt->execute([$badProductId]);
            $bp = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$bp) {
                throw new InvalidArgumentException("Bad product record #{$badProductId} not found.");
            }

            if ($bp['status'] === 'cancelled') {
                throw new DomainException("Bad product write-off {$bp['bad_product_number']} is already cancelled.");
            }

            // Authorization check
            if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
                $userWhId = (int)($authUser['warehouse_id'] ?? 0);
                if ($userWhId !== (int)$bp['warehouse_id']) {
                    throw new DomainException("Access Denied: You are not authorized to cancel bad product records for other warehouses.");
                }
            }

            // Updating status to 'cancelled' fires trg_bad_products_after_update in MySQL!
            $stmtCancel = $this->pdo->prepare("
                UPDATE bad_products
                SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), cancellation_reason = ?
                WHERE bad_product_id = ?
            ");
            $stmtCancel->execute([$userId, $reason, $badProductId]);

            AccountabilityService::log([
                'user_id'          => $userId,
                'team'             => 'Inventory',
                'action_type'      => 'BAD_PRODUCT_CANCEL',
                'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'item_id'          => (int)$bp['item_id'],
                'quantity'         => (float)$bp['quantity'],
                'warehouse_id'     => (int)$bp['warehouse_id'],
                'reference_number' => $bp['bad_product_number'],
                'notes'            => "Defect write-off cancelled: " . $reason
            ]);

            $this->pdo->commit();

            return [
                'bad_product_id'     => $badProductId,
                'bad_product_number' => $bp['bad_product_number'],
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
            SELECT bp.*, w.warehouse_code, w.warehouse_name,
                   i.item_code, i.item_name, i.item_type, i.unit,
                   ur.name AS reported_by_name,
                   ux.name AS cancelled_by_name
            FROM bad_products bp
            JOIN warehouses w ON bp.warehouse_id = w.warehouse_id
            JOIN items i ON bp.item_id = i.item_id
            JOIN users ur ON bp.reported_by = ur.user_id
            LEFT JOIN users ux ON bp.cancelled_by = ux.user_id
            WHERE bp.bad_product_id = ?
        ");
        $stmt->execute([$badProductId]);
        $bp = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bp) {
            throw new InvalidArgumentException("Damaged product record #{$badProductId} not found.");
        }

        if ($authUser && ($authUser['role'] ?? '') !== 'super_admin') {
            $userWhId = (int)($authUser['warehouse_id'] ?? 0);
            if ($userWhId !== (int)$bp['warehouse_id']) {
                throw new DomainException("Access Denied: You are not authorized to view records for other warehouses.");
            }
        }

        return $bp;
    }
}
