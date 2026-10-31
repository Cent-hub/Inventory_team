<?php
/**
 * OrderService.php
 * Handles creation and business validation for Inbound and Outbound Orders.
 * Connects ERP procurement, production, sales, and warehouse dock operations.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/StockService.php';
require_once __DIR__ . '/AccountabilityService.php';
require_once __DIR__ . '/auth.php';

class OrderService {
    private PDO $pdo;

    public const INBOUND_SOURCES = [
        'PURCHASE_ORDER',
        'PRODUCTION_RECEIPT',
        'CUSTOMER_RETURN',
        'PRODUCTION_RETURN'
    ];

    public const OUTBOUND_SOURCES = [
        'SALES_DELIVERY',
        'MATERIAL_REQUEST',
        'SUPPLIER_RETURN'
    ];

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * Create Inbound Order (Purchase Order, Production Receipt, or RMA Return)
     */
    public function createInboundOrder(array $payload, array $authUser): array {
        $orderNumber = strtoupper(trim((string)($payload['order_number'] ?? '')));
        if ($orderNumber === '' || mb_strlen($orderNumber) > 50 || !preg_match('/^[A-Z0-9.\-_]+$/i', $orderNumber)) {
            throw new InvalidArgumentException("Order number is required (1–50 alphanumeric characters, hyphens, underscores).");
        }

        $sourceType = trim((string)($payload['source_type'] ?? ''));
        if (!in_array($sourceType, self::INBOUND_SOURCES, true)) {
            throw new InvalidArgumentException("Invalid source_type '{$sourceType}'. Allowed: " . implode(', ', self::INBOUND_SOURCES));
        }

        $entityName = trim((string)($payload['entity_name'] ?? ''));
        if ($entityName === '' || mb_strlen($entityName) > 150) {
            throw new InvalidArgumentException("Entity/vendor/customer name is required (1–150 characters).");
        }

        $warehouseId = (int)($payload['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            $warehouseId = (int)($authUser['warehouse_id'] ?? 1);
        }

        // Warehouse facility scoping (F-07)
        if (($authUser['role'] ?? '') !== 'super_admin') {
            $assignedWh = (int)($authUser['warehouse_id'] ?? 0);
            if ($assignedWh <= 0 || $assignedWh !== $warehouseId) {
                throw new DomainException("Access Denied: You cannot create orders for warehouse #{$warehouseId}. Your facility is #{$assignedWh}.");
            }
        }

        // Department role/team enforcement
        $isWhAdmin = isWarehouseAdmin($authUser);
        $userTeam = strtolower(trim((string)($authUser['team'] ?? '')));
        if (!$isWhAdmin) {
            if ($userTeam === 'procurement' && $sourceType !== 'PURCHASE_ORDER') {
                throw new DomainException("Procurement team accounts can only register 'PURCHASE_ORDER' inbound orders.");
            }
            if ($userTeam === 'production' && !in_array($sourceType, ['PRODUCTION_RECEIPT', 'PRODUCTION_RETURN'], true)) {
                throw new DomainException("Production team accounts can only register 'PRODUCTION_RECEIPT' or 'PRODUCTION_RETURN' inbound orders.");
            }
            if ($userTeam === 'sales' && $sourceType !== 'CUSTOMER_RETURN') {
                throw new DomainException("Sales team accounts can only register 'CUSTOMER_RETURN' inbound orders.");
            }
        }

        // Validate warehouse exists
        $stmtWh = $this->pdo->prepare("SELECT name FROM warehouses WHERE warehouse_id = ? AND (LOWER(status) = 'active')");
        $stmtWh->execute([$warehouseId]);
        if (!$stmtWh->fetch()) {
            throw new InvalidArgumentException("Active warehouse #{$warehouseId} not found.");
        }

        $expectedDate = !empty($payload['expected_date']) ? trim((string)$payload['expected_date']) : null;
        if ($expectedDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expectedDate)) {
            throw new InvalidArgumentException("Invalid expected_date format (expected YYYY-MM-DD).");
        }

        $notes = !empty($payload['notes']) ? trim((string)$payload['notes']) : null;

        $rawItems = $payload['items'] ?? [];
        if (empty($rawItems) || !is_array($rawItems)) {
            throw new InvalidArgumentException("At least one line item is required for order creation.");
        }

        // Validate line items
        $stmtItem = $this->pdo->prepare("SELECT item_id, code, name, type, unit, status FROM items WHERE item_id = ?");
        $validatedItems = [];
        $seen = [];

        foreach ($rawItems as $entry) {
            $itemId = (int)($entry['item_id'] ?? 0);
            $rawQty = $entry['quantity'] ?? $entry['expected_quantity'] ?? null;
            if ($itemId <= 0) {
                throw new InvalidArgumentException("Invalid item_id in line items.");
            }
            if (isset($seen[$itemId])) {
                throw new InvalidArgumentException("Duplicate item ID {$itemId} in line items. Please combine quantities.");
            }
            $seen[$itemId] = true;

            $stmtItem->execute([$itemId]);
            $item = $stmtItem->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
            }
            if (($item['status'] ?? '') === 'pending') {
                throw new DomainException("Item '{$item['code']}' is pending Super Admin approval and cannot be ordered.");
            }
            if (strtolower($item['status'] ?? '') !== 'active') {
                throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
            }

            $qty = StockService::validatePositiveQuantity($rawQty, $item['unit'], "quantity for item '{$item['code']}'");

            // Classification check
            if ($sourceType === 'PURCHASE_ORDER' && !in_array(strtolower(str_replace(' ', '_', $item['type'] ?? '')), ['raw_material', 'raw'], true)) {
                throw new InvalidArgumentException("Procurement PO violation: Item '{$item['code']}' is a finished good. Purchase orders can only receive raw materials.");
            }
            if (($sourceType === 'PRODUCTION_RECEIPT' || $sourceType === 'PRODUCTION_RETURN') && !in_array(strtolower(str_replace(' ', '_', $item['type'] ?? '')), ['finished_good', 'finished'], true)) {
                throw new InvalidArgumentException("Production Receipt violation: Item '{$item['code']}' is a raw material. Production orders can only receive finished goods.");
            }
            if ($sourceType === 'CUSTOMER_RETURN' && !in_array(strtolower(str_replace(' ', '_', $item['type'] ?? '')), ['finished_good', 'finished'], true)) {
                throw new InvalidArgumentException("Customer Return violation: Item '{$item['code']}' is a raw material. Customer returns only apply to finished goods.");
            }

            $validatedItems[] = [
                'item_id'           => $itemId,
                'item_code'         => $item['code'],
                'item_name'         => $item['name'],
                'expected_quantity' => $qty,
                'unit'              => $item['unit'],
            ];
        }

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }

        try {
            // Check uniqueness under transaction
            $stmtDup = $this->pdo->prepare("SELECT inbound_order_id FROM inbound_orders WHERE order_number = ? LIMIT 1");
            $stmtDup->execute([$orderNumber]);
            if ($stmtDup->fetch()) {
                throw new DomainException("Inbound order '{$orderNumber}' already exists.");
            }

            $userId = (int)($authUser['user_id'] ?? ($authUser['id'] ?? 1));

            $stmtIns = $this->pdo->prepare("
                INSERT INTO inbound_orders (
                    order_number, source_type, entity_name, warehouse_id,
                    expected_date, status, notes, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, NOW())
            ");
            $stmtIns->execute([
                $orderNumber,
                $sourceType,
                $entityName,
                $warehouseId,
                $expectedDate,
                $notes,
                $userId
            ]);
            $orderId = (int)$this->pdo->lastInsertId();

            $stmtInsItem = $this->pdo->prepare("
                INSERT INTO inbound_order_items (
                    inbound_order_id, item_id, expected_quantity, received_quantity
                ) VALUES (?, ?, ?, 0.0000)
            ");
            foreach ($validatedItems as $vi) {
                $stmtInsItem->execute([$orderId, $vi['item_id'], $vi['expected_quantity']]);
            }

            AccountabilityService::log([
                'user_id'          => $userId,
                'team'             => $authUser['team'] ?? 'Inventory',
                'action_type'      => 'INBOUND_ORDER_CREATED',
                'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'warehouse_id'     => $warehouseId,
                'reference_number' => $orderNumber,
                'notes'            => "Created Inbound Order {$orderNumber} ({$sourceType}) from {$entityName} with " . count($validatedItems) . " line item(s)."
            ]);

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'success'          => true,
                'inbound_order_id' => $orderId,
                'order_number'     => $orderNumber,
                'source_type'      => $sourceType,
                'entity_name'      => $entityName,
                'warehouse_id'     => $warehouseId,
                'expected_date'    => $expectedDate,
                'status'           => 'pending',
                'items_count'      => count($validatedItems),
                'items'            => $validatedItems,
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Create Outbound Order (Sales Delivery, Material Request, or Supplier Return)
     */
    public function createOutboundOrder(array $payload, array $authUser): array {
        $orderNumber = strtoupper(trim((string)($payload['order_number'] ?? '')));
        if ($orderNumber === '' || mb_strlen($orderNumber) > 50 || !preg_match('/^[A-Z0-9.\-_]+$/i', $orderNumber)) {
            throw new InvalidArgumentException("Order number is required (1–50 alphanumeric characters, hyphens, underscores).");
        }

        $sourceType = trim((string)($payload['source_type'] ?? ''));
        if (!in_array($sourceType, self::OUTBOUND_SOURCES, true)) {
            throw new InvalidArgumentException("Invalid source_type '{$sourceType}'. Allowed: " . implode(', ', self::OUTBOUND_SOURCES));
        }

        $entityName = trim((string)($payload['entity_name'] ?? ''));
        if ($entityName === '' || mb_strlen($entityName) > 150) {
            throw new InvalidArgumentException("Entity/recipient/customer name is required (1–150 characters).");
        }

        $warehouseId = (int)($payload['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            $warehouseId = (int)($authUser['warehouse_id'] ?? 1);
        }

        // Warehouse facility scoping (F-07)
        if (($authUser['role'] ?? '') !== 'super_admin') {
            $assignedWh = (int)($authUser['warehouse_id'] ?? 0);
            if ($assignedWh <= 0 || $assignedWh !== $warehouseId) {
                throw new DomainException("Access Denied: You cannot create orders for warehouse #{$warehouseId}. Your facility is #{$assignedWh}.");
            }
        }

        // Department role/team enforcement
        $isWhAdmin = isWarehouseAdmin($authUser);
        $userTeam = strtolower(trim((string)($authUser['team'] ?? '')));
        if (!$isWhAdmin) {
            if ($userTeam === 'procurement' && $sourceType !== 'SUPPLIER_RETURN') {
                throw new DomainException("Procurement team accounts can only register 'SUPPLIER_RETURN' outbound orders.");
            }
            if ($userTeam === 'production' && $sourceType !== 'MATERIAL_REQUEST') {
                throw new DomainException("Production team accounts can only register 'MATERIAL_REQUEST' outbound orders.");
            }
            if ($userTeam === 'sales' && $sourceType !== 'SALES_DELIVERY') {
                throw new DomainException("Sales team accounts can only register 'SALES_DELIVERY' outbound orders.");
            }
        }

        // Validate warehouse exists
        $stmtWh = $this->pdo->prepare("SELECT name FROM warehouses WHERE warehouse_id = ? AND (LOWER(status) = 'active')");
        $stmtWh->execute([$warehouseId]);
        if (!$stmtWh->fetch()) {
            throw new InvalidArgumentException("Active warehouse #{$warehouseId} not found.");
        }

        $requiredDate = !empty($payload['required_date']) ? trim((string)$payload['required_date']) : null;
        if ($requiredDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $requiredDate)) {
            throw new InvalidArgumentException("Invalid required_date format (expected YYYY-MM-DD).");
        }

        $notes = !empty($payload['notes']) ? trim((string)$payload['notes']) : null;

        $rawItems = $payload['items'] ?? [];
        if (empty($rawItems) || !is_array($rawItems)) {
            throw new InvalidArgumentException("At least one line item is required for order creation.");
        }

        // Validate line items
        $stmtItem = $this->pdo->prepare("SELECT item_id, code, name, type, unit, status FROM items WHERE item_id = ?");
        $validatedItems = [];
        $seen = [];

        foreach ($rawItems as $entry) {
            $itemId = (int)($entry['item_id'] ?? 0);
            $rawQty = $entry['quantity'] ?? $entry['requested_quantity'] ?? null;
            if ($itemId <= 0) {
                throw new InvalidArgumentException("Invalid item_id in line items.");
            }
            if (isset($seen[$itemId])) {
                throw new InvalidArgumentException("Duplicate item ID {$itemId} in line items. Please combine quantities.");
            }
            $seen[$itemId] = true;

            $stmtItem->execute([$itemId]);
            $item = $stmtItem->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
            }
            if (($item['status'] ?? '') === 'pending') {
                throw new DomainException("Item '{$item['code']}' is pending Super Admin approval and cannot be ordered.");
            }
            if (strtolower($item['status'] ?? '') !== 'active') {
                throw new InvalidArgumentException("Item ID {$itemId} is invalid or inactive.");
            }

            $qty = StockService::validatePositiveQuantity($rawQty, $item['unit'], "quantity for item '{$item['code']}'");

            // Classification check
            if ($sourceType === 'MATERIAL_REQUEST' && !in_array(strtolower(str_replace(' ', '_', $item['type'] ?? '')), ['raw_material', 'raw'], true)) {
                throw new InvalidArgumentException("Production Material Request violation: Item '{$item['code']}' is a finished good. Material requests can only dispatch raw materials.");
            }
            if ($sourceType === 'SUPPLIER_RETURN' && !in_array(strtolower(str_replace(' ', '_', $item['type'] ?? '')), ['raw_material', 'raw'], true)) {
                throw new InvalidArgumentException("Supplier Return violation: Item '{$item['code']}' is a finished good. Supplier returns only apply to raw materials.");
            }
            if ($sourceType === 'SALES_DELIVERY' && !in_array(strtolower(str_replace(' ', '_', $item['type'] ?? '')), ['finished_good', 'finished'], true)) {
                throw new InvalidArgumentException("Sales Delivery violation: Item '{$item['code']}' is a raw material. Sales deliveries can only dispatch finished goods.");
            }

            $validatedItems[] = [
                'item_id'            => $itemId,
                'item_code'          => $item['code'],
                'item_name'          => $item['name'],
                'requested_quantity' => $qty,
                'unit'               => $item['unit'],
            ];
        }

        $isRootTxn = !$this->pdo->inTransaction();
        if ($isRootTxn) {
            $this->pdo->beginTransaction();
        }

        try {
            // Check uniqueness under transaction
            $stmtDup = $this->pdo->prepare("SELECT outbound_order_id FROM outbound_orders WHERE order_number = ? LIMIT 1");
            $stmtDup->execute([$orderNumber]);
            if ($stmtDup->fetch()) {
                throw new DomainException("Outbound order '{$orderNumber}' already exists.");
            }

            $userId = (int)($authUser['user_id'] ?? ($authUser['id'] ?? 1));

            $stmtIns = $this->pdo->prepare("
                INSERT INTO outbound_orders (
                    order_number, source_type, entity_name, warehouse_id,
                    required_date, status, notes, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, NOW())
            ");
            $stmtIns->execute([
                $orderNumber,
                $sourceType,
                $entityName,
                $warehouseId,
                $requiredDate,
                $notes,
                $userId
            ]);
            $orderId = (int)$this->pdo->lastInsertId();

            $stmtInsItem = $this->pdo->prepare("
                INSERT INTO outbound_order_items (
                    outbound_order_id, item_id, requested_quantity, dispatched_quantity
                ) VALUES (?, ?, ?, 0.0000)
            ");
            $stockService = new StockService($this->pdo);
            foreach ($validatedItems as $vi) {
                $stmtInsItem->execute([$orderId, $vi['item_id'], $vi['requested_quantity']]);
                // CRIT-01: Soft-allocate / reserve inventory for pending outbound order
                $stockService->reserveStock((int)$vi['item_id'], $warehouseId, (float)$vi['requested_quantity'], $orderNumber, $userId);
            }

            AccountabilityService::log([
                'user_id'          => $userId,
                'team'             => $authUser['team'] ?? 'Inventory',
                'action_type'      => 'OUTBOUND_ORDER_CREATED',
                'channel'          => (defined('API_REQUEST') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) ? 'API' : 'UI',
                'warehouse_id'     => $warehouseId,
                'reference_number' => $orderNumber,
                'notes'            => "Created Outbound Order {$orderNumber} ({$sourceType}) for {$entityName} with " . count($validatedItems) . " line item(s)."
            ]);

            if ($isRootTxn) {
                $this->pdo->commit();
            }

            return [
                'success'           => true,
                'outbound_order_id' => $orderId,
                'order_number'      => $orderNumber,
                'source_type'       => $sourceType,
                'entity_name'       => $entityName,
                'warehouse_id'      => $warehouseId,
                'required_date'     => $requiredDate,
                'status'            => 'pending',
                'items_count'       => count($validatedItems),
                'items'             => $validatedItems,
            ];
        } catch (Throwable $e) {
            if ($isRootTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
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
            $stmtItems = $this->pdo->prepare("SELECT * FROM outbound_order_items WHERE outbound_order_id = ?");
            $stmtItems->execute([$orderId]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

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
