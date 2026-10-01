<?php
/**
 * Migration: Create accountability_logs table and schema enhancements
 */
require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = Database::getConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "--- Starting Accountability Logs Migration ---\n";

    // 1. Create accountability_logs table
    $sqlCreate = "
    CREATE TABLE IF NOT EXISTS `accountability_logs` (
        `log_id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `user_id` INT UNSIGNED NULL,
        `user_name` VARCHAR(150) NOT NULL,
        `user_role` VARCHAR(50) NOT NULL DEFAULT 'admin',
        `team` VARCHAR(50) NOT NULL DEFAULT 'Inventory',
        `action_type` VARCHAR(60) NOT NULL,
        `channel` ENUM('UI', 'API') NOT NULL DEFAULT 'UI',
        `item_id` INT UNSIGNED NULL,
        `item_code` VARCHAR(50) NULL,
        `item_name` VARCHAR(150) NULL,
        `quantity` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
        `unit` VARCHAR(20) NULL,
        `warehouse_id` INT UNSIGNED NOT NULL,
        `destination_warehouse_id` INT UNSIGNED NULL,
        `reference_number` VARCHAR(100) NULL,
        `notes` TEXT NULL,
        INDEX `idx_acc_warehouse` (`warehouse_id`),
        INDEX `idx_acc_user` (`user_id`),
        INDEX `idx_acc_team` (`team`),
        INDEX `idx_acc_action` (`action_type`),
        INDEX `idx_acc_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlCreate);
    echo "✓ accountability_logs table created or verified.\n";

    // Helper to check column existence
    $columnExists = function($table, $col) use ($pdo) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $col]);
        return (int)$stmt->fetchColumn() > 0;
    };

    // 2. Add received_by and received_at to stock_transfers
    if (!$columnExists('stock_transfers', 'received_by')) {
        $pdo->exec("ALTER TABLE stock_transfers ADD COLUMN received_by INT UNSIGNED NULL AFTER created_by");
        echo "✓ Added received_by column to stock_transfers.\n";
    } else {
        echo "- received_by already exists on stock_transfers.\n";
    }

    if (!$columnExists('stock_transfers', 'received_at')) {
        $pdo->exec("ALTER TABLE stock_transfers ADD COLUMN received_at DATETIME NULL AFTER received_by");
        echo "✓ Added received_at column to stock_transfers.\n";
    } else {
        echo "- received_at already exists on stock_transfers.\n";
    }

    // 3. Add team column to users
    if (!$columnExists('users', 'team')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN team VARCHAR(50) NOT NULL DEFAULT 'Inventory' AFTER role");
        echo "✓ Added team column to users.\n";
    } else {
        echo "- team column already exists on users.\n";
    }

    // Backfill user teams based on known roles/emails
    $pdo->exec("UPDATE users SET team = 'Procurement' WHERE email LIKE '%procure%' OR user_id IN (2, 5)");
    $pdo->exec("UPDATE users SET team = 'Production' WHERE email LIKE '%prod%' OR user_id = 3");
    $pdo->exec("UPDATE users SET team = 'Sales' WHERE email LIKE '%sale%' OR user_id IN (4)");
    $pdo->exec("UPDATE users SET team = 'Administration' WHERE role = 'super_admin' OR user_id = 1");
    $pdo->exec("UPDATE users SET team = 'Inventory' WHERE team = '' OR team IS NULL");
    echo "✓ Backfilled user team classifications.\n";

    // 4. Add created_by to items
    if (!$columnExists('items', 'created_by')) {
        $pdo->exec("ALTER TABLE items ADD COLUMN created_by INT UNSIGNED NULL AFTER status");
        echo "✓ Added created_by column to items.\n";
    } else {
        echo "- created_by already exists on items.\n";
    }

    // 5. Backfill historical records into accountability_logs from existing transaction tables
    $count = (int)$pdo->query("SELECT COUNT(*) FROM accountability_logs")->fetchColumn();
    if ($count === 0) {
        echo "Backfilling historical logs from existing transactions...\n";

        // 5a. Stock In
        $sqlBackfillIn = "
            INSERT INTO accountability_logs (
                created_at, user_id, user_name, user_role, team,
                action_type, channel, item_id, item_code, item_name,
                quantity, unit, warehouse_id, reference_number, notes
            )
            SELECT 
                si.created_at,
                si.created_by,
                COALESCE(u.name, 'System User'),
                COALESCE(u.role, 'admin'),
                CASE 
                    WHEN si.source_type = 'PURCHASE_ORDER' THEN 'Procurement'
                    WHEN si.source_type = 'PRODUCTION_RETURN' THEN 'Production'
                    ELSE 'Inventory'
                END,
                'STOCK_IN',
                'UI',
                sii.item_id,
                i.item_code,
                i.item_name,
                sii.quantity,
                i.unit,
                si.warehouse_id,
                COALESCE(si.source_reference_no, si.transaction_number),
                si.remarks
            FROM stock_ins si
            JOIN stock_in_items sii ON si.stock_in_id = sii.stock_in_id
            JOIN items i ON sii.item_id = i.item_id
            LEFT JOIN users u ON si.created_by = u.user_id
            WHERE si.status != 'cancelled'
        ";
        $inRows = $pdo->exec($sqlBackfillIn);
        echo "  - Backfilled {$inRows} Stock In records.\n";

        // 5b. Stock Out
        $sqlBackfillOut = "
            INSERT INTO accountability_logs (
                created_at, user_id, user_name, user_role, team,
                action_type, channel, item_id, item_code, item_name,
                quantity, unit, warehouse_id, reference_number, notes
            )
            SELECT 
                so.created_at,
                so.created_by,
                COALESCE(u.name, 'System User'),
                COALESCE(u.role, 'admin'),
                CASE 
                    WHEN so.source_type = 'MATERIAL_REQUEST' THEN 'Production'
                    WHEN so.source_type = 'SALES_DELIVERY' THEN 'Sales'
                    ELSE 'Inventory'
                END,
                'STOCK_OUT',
                'UI',
                soi.item_id,
                i.item_code,
                i.item_name,
                soi.quantity,
                i.unit,
                so.warehouse_id,
                COALESCE(so.source_reference_no, so.transaction_number),
                so.remarks
            FROM stock_outs so
            JOIN stock_out_items soi ON so.stock_out_id = soi.stock_out_id
            JOIN items i ON soi.item_id = i.item_id
            LEFT JOIN users u ON so.created_by = u.user_id
            WHERE so.status != 'cancelled'
        ";
        $outRows = $pdo->exec($sqlBackfillOut);
        echo "  - Backfilled {$outRows} Stock Out records.\n";

        // 5c. Stock Transfers (Initiated)
        $sqlBackfillXferInit = "
            INSERT INTO accountability_logs (
                created_at, user_id, user_name, user_role, team,
                action_type, channel, item_id, item_code, item_name,
                quantity, unit, warehouse_id, destination_warehouse_id, reference_number, notes
            )
            SELECT 
                st.created_at,
                st.created_by,
                COALESCE(u.name, 'System User'),
                COALESCE(u.role, 'admin'),
                'Inventory',
                'TRANSFER_INITIATED',
                'UI',
                sti.item_id,
                i.item_code,
                i.item_name,
                sti.quantity,
                i.unit,
                st.source_warehouse_id,
                st.destination_warehouse_id,
                st.transaction_number,
                st.remarks
            FROM stock_transfers st
            JOIN stock_transfer_items sti ON st.stock_transfer_id = sti.stock_transfer_id
            JOIN items i ON sti.item_id = i.item_id
            LEFT JOIN users u ON st.created_by = u.user_id
        ";
        $xferInitRows = $pdo->exec($sqlBackfillXferInit);
        echo "  - Backfilled {$xferInitRows} Transfer Initiated records.\n";

        // 5d. Stock Transfers (Completed / Received)
        $sqlBackfillXferRecv = "
            INSERT INTO accountability_logs (
                created_at, user_id, user_name, user_role, team,
                action_type, channel, item_id, item_code, item_name,
                quantity, unit, warehouse_id, destination_warehouse_id, reference_number, notes
            )
            SELECT 
                st.updated_at,
                COALESCE(st.received_by, st.created_by),
                COALESCE(u.name, 'Warehouse Receiver'),
                COALESCE(u.role, 'admin'),
                'Inventory',
                'TRANSFER_RECEIVED',
                'UI',
                sti.item_id,
                i.item_code,
                i.item_name,
                sti.quantity,
                i.unit,
                st.destination_warehouse_id,
                st.source_warehouse_id,
                st.transaction_number,
                'Stock transfer delivery confirmed and received into facility inventory.'
            FROM stock_transfers st
            JOIN stock_transfer_items sti ON st.stock_transfer_id = sti.stock_transfer_id
            JOIN items i ON sti.item_id = i.item_id
            LEFT JOIN users u ON COALESCE(st.received_by, st.created_by) = u.user_id
            WHERE st.status = 'completed'
        ";
        $xferRecvRows = $pdo->exec($sqlBackfillXferRecv);
        echo "  - Backfilled {$xferRecvRows} Transfer Received records.\n";

        // 5e. Stock Adjustments (Approved)
        $sqlBackfillAdj = "
            INSERT INTO accountability_logs (
                created_at, user_id, user_name, user_role, team,
                action_type, channel, item_id, item_code, item_name,
                quantity, unit, warehouse_id, reference_number, notes
            )
            SELECT 
                COALESCE(sa.approved_at, sa.created_at),
                COALESCE(sa.approved_by, sa.created_by),
                COALESCE(u.name, 'Inventory Auditor'),
                COALESCE(u.role, 'admin'),
                'Inventory',
                'ADJUSTMENT_APPROVED',
                'UI',
                sai.item_id,
                i.item_code,
                i.item_name,
                sai.difference,
                i.unit,
                sa.warehouse_id,
                sa.transaction_number,
                sa.reason
            FROM stock_adjustments sa
            JOIN stock_adjustment_items sai ON sa.stock_adjustment_id = sai.stock_adjustment_id
            JOIN items i ON sai.item_id = i.item_id
            LEFT JOIN users u ON COALESCE(sa.approved_by, sa.created_by) = u.user_id
            WHERE sa.status = 'approved'
        ";
        $adjRows = $pdo->exec($sqlBackfillAdj);
        echo "  - Backfilled {$adjRows} Stock Adjustment records.\n";

        // 5f. Bad Products
        $sqlBackfillBad = "
            INSERT INTO accountability_logs (
                created_at, user_id, user_name, user_role, team,
                action_type, channel, item_id, item_code, item_name,
                quantity, unit, warehouse_id, reference_number, notes
            )
            SELECT 
                bp.created_at,
                bp.reported_by,
                COALESCE(u.name, 'Quality Inspector'),
                COALESCE(u.role, 'admin'),
                'Inventory',
                'BAD_PRODUCT',
                'UI',
                bp.item_id,
                i.item_code,
                i.item_name,
                bp.quantity,
                i.unit,
                bp.warehouse_id,
                bp.bad_product_number,
                CONCAT('Condition: ', bp.condition_type, ' - Reason: ', bp.reason)
            FROM bad_products bp
            JOIN items i ON bp.item_id = i.item_id
            LEFT JOIN users u ON bp.reported_by = u.user_id
            WHERE bp.status = 'completed'
        ";
        $badRows = $pdo->exec($sqlBackfillBad);
        echo "  - Backfilled {$badRows} Bad Product records.\n";
    } else {
        echo "- accountability_logs already contains {$count} records. Skipping historical backfill.\n";
    }

    echo "--- Migration Completed Successfully! ---\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
