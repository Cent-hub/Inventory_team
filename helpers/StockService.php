<?php
/**
 * Transactional Stock Service
 * Orchestrates stock_ins, stock_outs, and ledger movements with concurrency locking.
 */

require_once __DIR__ . '/../config/database.php';

class StockService {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getConnection();
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

            foreach ($items as $entry) {
                $itemId = (int)($entry['item_id'] ?? $entry['material_id'] ?? $entry['product_id'] ?? 0);
                $qty = (float)($entry['quantity'] ?? 0);

                if ($itemId <= 0 || $qty <= 0) {
                    throw new InvalidArgumentException("Invalid item ID or quantity (must be > 0).");
                }

                $stmtCheckItem->execute([$itemId]);
                $itemInfo = $stmtCheckItem->fetch();
                if (!$itemInfo || $itemInfo['status'] !== 'active') {
                    throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
                }

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

            foreach ($items as $entry) {
                $itemId = (int)($entry['item_id'] ?? $entry['material_id'] ?? $entry['product_id'] ?? 0);
                $qty = (float)($entry['quantity'] ?? 0);

                if ($itemId <= 0 || $qty <= 0) {
                    throw new InvalidArgumentException("Invalid item ID or quantity (must be > 0).");
                }

                $stmtCheckItem->execute([$itemId]);
                $itemInfo = $stmtCheckItem->fetch();
                if (!$itemInfo || $itemInfo['status'] !== 'active') {
                    throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
                }

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
        if (empty(trim($cancellationReason))) {
            throw new InvalidArgumentException("Cancellation reason is required.");
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
        if (empty(trim($cancellationReason))) {
            throw new InvalidArgumentException("Cancellation reason is required.");
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

            foreach ($items as $entry) {
                $itemId = (int)($entry['item_id'] ?? $entry['material_id'] ?? $entry['product_id'] ?? 0);
                $qty = (float)($entry['quantity'] ?? 0);

                if ($itemId <= 0 || $qty <= 0) {
                    throw new InvalidArgumentException("Invalid item ID or quantity (must be > 0).");
                }

                $stmtCheckItem->execute([$itemId]);
                $itemInfo = $stmtCheckItem->fetch();
                if (!$itemInfo || $itemInfo['status'] !== 'active') {
                    throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
                }

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

            // Insert stock_transfers header
            $stmtHeader = $this->pdo->prepare("
                INSERT INTO stock_transfers (
                    transaction_number, source_warehouse_id, destination_warehouse_id,
                    transaction_date, status, remarks, created_by
                ) VALUES (
                    ?, ?, ?,
                    CURDATE(), 'completed', ?, ?
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

            // Insert line items and dual movements (OUT from source, IN to destination)
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

            foreach ($validatedEntries as $entry) {
                $itemInfo = $entry['item'];
                $itemId = (int)$itemInfo['item_id'];
                $qty = (float)$entry['quantity'];

                $stmtLine->execute([$stockTransferId, $itemId, $qty]);
                $stmtMoveOut->execute([$itemId, $sourceWhId, $stockTransferId, $txnNumber . '-SRC', $qty]);
                $stmtMoveIn->execute([$itemId, $destWhId, $stockTransferId, $txnNumber . '-DEST', $qty]);

                // Query new balances
                $stmtBal->execute([$itemId, $sourceWhId]);
                $sourceBal = (float)$stmtBal->fetchColumn();

                $stmtBal->execute([$itemId, $destWhId]);
                $destBal = (float)$stmtBal->fetchColumn();

                $processedItems[] = [
                    'item_id'                => $itemId,
                    'item_code'              => $itemInfo['item_code'],
                    'item_name'              => $itemInfo['item_name'],
                    'quantity_transferred'   => $qty,
                    'unit'                   => $itemInfo['unit'],
                    'source_balance_after'   => $sourceBal,
                    'dest_balance_after'     => $destBal
                ];
            }

            $this->pdo->commit();

            return [
                'stock_transfer_id'        => $stockTransferId,
                'transaction_number'       => $txnNumber,
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
     * Cancel an Inter-Warehouse Transfer
     * Triggers MySQL trg_stock_transfers_after_update which automatically:
     * 1. Deducts quantity from destination warehouse (STOCK_TRANSFER_CANCEL).
     * 2. Restores quantity to source warehouse (STOCK_TRANSFER_CANCEL).
     */
    public function cancelStockTransfer(int $stockTransferId, string $cancellationReason, int $cancelledBy, ?array $authUser = null): array {
        if ($stockTransferId <= 0) {
            throw new InvalidArgumentException("Invalid stock_transfer_id.");
        }
        if (empty(trim($cancellationReason))) {
            throw new InvalidArgumentException("Cancellation reason is required.");
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

            // IDOR Protection: Restrict regular admins to only cancel their own transfers
            if ($authUser !== null && ($authUser['role'] ?? '') !== 'super_admin') {
                if ((int)$txn['created_by'] !== (int)$authUser['user_id']) {
                    throw new DomainException(
                        "Access Denied (IDOR Protection): Administrator #{$authUser['user_id']} ('{$authUser['name']}') is not authorized to cancel transfers created by administrator #{$txn['created_by']}."
                    );
                }
            }

            if ($txn['status'] === 'cancelled') {
                throw new InvalidArgumentException("Stock transfer {$txn['transaction_number']} is already cancelled.");
            }
            if ($txn['status'] !== 'completed') {
                throw new InvalidArgumentException("Only completed transfers can be cancelled.");
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

        // IDOR Protection: An admin cannot inspect transactions created by another admin unless they are super_admin
        if (($authUser['role'] ?? '') !== 'super_admin' && (int)$txn['created_by'] !== (int)$authUser['user_id']) {
            throw new DomainException(
                "Access Denied (IDOR Protection): Administrator #{$authUser['user_id']} ('{$authUser['name']}') is not authorized to view transactions created by administrator #{$txn['created_by']}."
            );
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
            $stmt = $this->pdo->prepare("
                SELECT st.stock_transfer_id, st.transaction_number, 
                       st.source_warehouse_id, sw.warehouse_name AS source_warehouse_name,
                       st.destination_warehouse_id, dw.warehouse_name AS dest_warehouse_name,
                       st.transaction_date, st.status, st.created_by, u.name AS creator_name, st.created_at
                FROM stock_transfers st
                JOIN warehouses sw ON sw.warehouse_id = st.source_warehouse_id
                JOIN warehouses dw ON dw.warehouse_id = st.destination_warehouse_id
                JOIN users u ON u.user_id = st.created_by
                WHERE st.created_by = ?
                ORDER BY st.stock_transfer_id DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, (int)$authUser['user_id'], PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
        }

        return $stmt->fetchAll();
    }
}
