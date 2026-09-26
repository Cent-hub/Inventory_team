-- =============================================================================
-- Database: team_inventory
-- File: Inventory.sql
-- Description: Complete schema definition including all tables, constraints,
--              views, and consistency triggers for Team_Inventory.
-- Policy:      "Never Delete, Cancel Instead" - Transaction headers and line
--              items cannot be deleted. Rollbacks and corrections are handled
--              via explicit cancellation and reversing ledger entries.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `team_inventory`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `team_inventory`;

SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 1. Table: users
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `user_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `password` VARCHAR(255) NOT NULL COMMENT 'PHP password_hash() hash',
    `role` ENUM('super_admin', 'admin') NOT NULL DEFAULT 'admin',
    `warehouse_id` INT(10) UNSIGNED DEFAULT NULL,
    `api_token` VARCHAR(64) DEFAULT NULL,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    UNIQUE KEY `uq_users_email` (`email`),
    UNIQUE KEY `uq_users_api_token` (`api_token`),
    KEY `idx_users_role` (`role`),
    KEY `idx_users_warehouse` (`warehouse_id`),
    KEY `idx_users_status` (`status`),
    CONSTRAINT `fk_users_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. Table: warehouses
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `warehouses`;
CREATE TABLE `warehouses` (
    `warehouse_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `warehouse_code` VARCHAR(50) NOT NULL,
    `warehouse_name` VARCHAR(150) NOT NULL,
    `location` VARCHAR(255) DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`warehouse_id`),
    UNIQUE KEY `uq_warehouses_code` (`warehouse_code`),
    KEY `idx_warehouses_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. Table: categories (Product Taxonomy & Classification)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
    `category_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_code` VARCHAR(50) NOT NULL,
    `category_name` VARCHAR(100) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`category_id`),
    UNIQUE KEY `uq_categories_code` (`category_code`),
    KEY `idx_categories_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. Table: items (Master catalog for Raw Materials & Finished Goods)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `items`;
CREATE TABLE `items` (
    `item_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `item_code` VARCHAR(50) NOT NULL,
    `item_name` VARCHAR(150) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `item_type` ENUM('raw_material', 'finished_good') NOT NULL,
    `category_id` INT(10) UNSIGNED DEFAULT NULL,
    `unit` VARCHAR(20) NOT NULL COMMENT 'pcs, kg, box, liter',
    `default_reorder_level` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`item_id`),
    UNIQUE KEY `uq_items_code` (`item_code`),
    KEY `idx_items_type` (`item_type`),
    KEY `idx_items_category` (`category_id`),
    KEY `idx_items_status` (`status`),
    CONSTRAINT `fk_items_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_items_def_reorder` CHECK (`default_reorder_level` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. Table: inventory (Warehouse stock snapshots)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `inventory`;
CREATE TABLE `inventory` (
    `inventory_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `item_id` INT(10) UNSIGNED NOT NULL,
    `warehouse_id` INT(10) UNSIGNED NOT NULL,
    `quantity` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    `reorder_level` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`inventory_id`),
    UNIQUE KEY `uq_inventory_item_warehouse` (`item_id`, `warehouse_id`),
    KEY `idx_inventory_warehouse` (`warehouse_id`),
    CONSTRAINT `fk_inventory_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_inventory_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_inventory_quantity` CHECK (`quantity` >= 0),
    CONSTRAINT `chk_inventory_reorder` CHECK (`reorder_level` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. Table: stock_ins (Inbound transaction headers)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_ins`;
CREATE TABLE `stock_ins` (
    `stock_in_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `transaction_number` VARCHAR(50) NOT NULL,
    `source_type` ENUM('PURCHASE_ORDER', 'PRODUCTION_RETURN', 'MANUAL') NOT NULL DEFAULT 'PURCHASE_ORDER',
    `source_reference_no` VARCHAR(100) DEFAULT NULL COMMENT 'e.g. PO Number or Receipt No',
    `warehouse_id` INT(10) UNSIGNED NOT NULL,
    `transaction_date` DATE NOT NULL,
    `status` ENUM('pending', 'completed', 'cancelled') NOT NULL DEFAULT 'completed',
    `remarks` TEXT DEFAULT NULL,
    `created_by` INT(10) UNSIGNED NOT NULL,
    `cancelled_at` DATETIME DEFAULT NULL,
    `cancelled_by` INT(10) UNSIGNED DEFAULT NULL,
    `cancellation_reason` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`stock_in_id`),
    UNIQUE KEY `uq_stock_ins_txn` (`transaction_number`),
    KEY `idx_stock_ins_ref` (`source_reference_no`),
    KEY `fk_stock_ins_user` (`created_by`),
    KEY `fk_stock_ins_canceller` (`cancelled_by`),
    KEY `idx_stock_ins_warehouse` (`warehouse_id`),
    KEY `idx_stock_ins_date` (`transaction_date`),
    KEY `idx_stock_ins_status` (`status`),
    CONSTRAINT `fk_stock_ins_canceller` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_stock_ins_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_stock_ins_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. Table: stock_in_items (Inbound line items)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_in_items`;
CREATE TABLE `stock_in_items` (
    `stock_in_item_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `stock_in_id` INT(10) UNSIGNED NOT NULL,
    `item_id` INT(10) UNSIGNED NOT NULL,
    `quantity` DECIMAL(14,3) NOT NULL,
    PRIMARY KEY (`stock_in_item_id`),
    UNIQUE KEY `uq_sii_single_item` (`stock_in_id`, `item_id`),
    KEY `idx_sii_item` (`item_id`),
    CONSTRAINT `fk_sii_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_sii_stock_in` FOREIGN KEY (`stock_in_id`) REFERENCES `stock_ins` (`stock_in_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `chk_stock_in_items_qty` CHECK (`quantity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. Table: stock_outs (Outbound transaction headers)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_outs`;
CREATE TABLE `stock_outs` (
    `stock_out_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `transaction_number` VARCHAR(50) NOT NULL,
    `source_type` ENUM('MATERIAL_REQUEST', 'SALES_DELIVERY', 'MANUAL') NOT NULL DEFAULT 'MANUAL',
    `source_reference_no` VARCHAR(100) DEFAULT NULL COMMENT 'e.g. Material Request #, Production Order #, or Sales Order #',
    `warehouse_id` INT(10) UNSIGNED NOT NULL,
    `transaction_date` DATE NOT NULL,
    `status` ENUM('pending', 'completed', 'cancelled') NOT NULL DEFAULT 'completed',
    `remarks` TEXT DEFAULT NULL,
    `created_by` INT(10) UNSIGNED NOT NULL,
    `cancelled_at` DATETIME DEFAULT NULL,
    `cancelled_by` INT(10) UNSIGNED DEFAULT NULL,
    `cancellation_reason` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`stock_out_id`),
    UNIQUE KEY `uq_stock_outs_txn` (`transaction_number`),
    KEY `idx_stock_outs_ref` (`source_reference_no`),
    KEY `fk_stock_outs_user` (`created_by`),
    KEY `fk_stock_outs_canceller` (`cancelled_by`),
    KEY `idx_stock_outs_warehouse` (`warehouse_id`),
    KEY `idx_stock_outs_date` (`transaction_date`),
    KEY `idx_stock_outs_status` (`status`),
    CONSTRAINT `fk_stock_outs_canceller` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_stock_outs_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_stock_outs_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. Table: stock_out_items (Outbound line items)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_out_items`;
CREATE TABLE `stock_out_items` (
    `stock_out_item_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `stock_out_id` INT(10) UNSIGNED NOT NULL,
    `item_id` INT(10) UNSIGNED NOT NULL,
    `quantity` DECIMAL(14,3) NOT NULL,
    PRIMARY KEY (`stock_out_item_id`),
    UNIQUE KEY `uq_soi_single_item` (`stock_out_id`, `item_id`),
    KEY `idx_soi_item` (`item_id`),
    CONSTRAINT `fk_soi_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_soi_stock_out` FOREIGN KEY (`stock_out_id`) REFERENCES `stock_outs` (`stock_out_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `chk_stock_out_items_qty` CHECK (`quantity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 10. Table: stock_transfers (Direct Inter-warehouse transfers)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_transfers`;
CREATE TABLE `stock_transfers` (
    `stock_transfer_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `transaction_number` VARCHAR(50) NOT NULL,
    `source_warehouse_id` INT(10) UNSIGNED NOT NULL,
    `destination_warehouse_id` INT(10) UNSIGNED NOT NULL,
    `transaction_date` DATE NOT NULL,
    `status` ENUM('pending', 'completed', 'cancelled') NOT NULL DEFAULT 'completed',
    `remarks` TEXT DEFAULT NULL,
    `created_by` INT(10) UNSIGNED NOT NULL,
    `cancelled_at` DATETIME DEFAULT NULL,
    `cancelled_by` INT(10) UNSIGNED DEFAULT NULL,
    `cancellation_reason` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`stock_transfer_id`),
    UNIQUE KEY `uq_stock_transfers_txn` (`transaction_number`),
    KEY `fk_st_user` (`created_by`),
    KEY `fk_st_canceller` (`cancelled_by`),
    KEY `idx_st_source` (`source_warehouse_id`),
    KEY `idx_st_dest` (`destination_warehouse_id`),
    KEY `idx_st_date` (`transaction_date`),
    KEY `idx_st_status` (`status`),
    CONSTRAINT `fk_st_canceller` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_st_dest_wh` FOREIGN KEY (`destination_warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_st_source_wh` FOREIGN KEY (`source_warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_st_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_stock_transfers_diff_wh` CHECK (`source_warehouse_id` <> `destination_warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 11. Table: stock_transfer_items (Inter-warehouse line items)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_transfer_items`;
CREATE TABLE `stock_transfer_items` (
    `stock_transfer_item_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `stock_transfer_id` INT(10) UNSIGNED NOT NULL,
    `item_id` INT(10) UNSIGNED NOT NULL,
    `quantity` DECIMAL(14,3) NOT NULL,
    PRIMARY KEY (`stock_transfer_item_id`),
    UNIQUE KEY `uq_sti_single_item` (`stock_transfer_id`, `item_id`),
    KEY `idx_sti_item` (`item_id`),
    CONSTRAINT `fk_sti_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_sti_transfer` FOREIGN KEY (`stock_transfer_id`) REFERENCES `stock_transfers` (`stock_transfer_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `chk_sti_qty` CHECK (`quantity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 12. Table: stock_adjustments (Inventory adjustment headers)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_adjustments`;
CREATE TABLE `stock_adjustments` (
    `stock_adjustment_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `transaction_number` VARCHAR(50) NOT NULL,
    `warehouse_id` INT(10) UNSIGNED NOT NULL,
    `adjustment_date` DATE NOT NULL,
    `reason` VARCHAR(255) NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
    `created_by` INT(10) UNSIGNED NOT NULL,
    `approved_by` INT(10) UNSIGNED DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `cancelled_at` DATETIME DEFAULT NULL,
    `cancelled_by` INT(10) UNSIGNED DEFAULT NULL,
    `cancellation_reason` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`stock_adjustment_id`),
    UNIQUE KEY `uq_stock_adj_txn` (`transaction_number`),
    KEY `fk_sa_creator` (`created_by`),
    KEY `fk_sa_approver` (`approved_by`),
    KEY `fk_sa_canceller` (`cancelled_by`),
    KEY `idx_sa_warehouse` (`warehouse_id`),
    KEY `idx_sa_date` (`adjustment_date`),
    KEY `idx_sa_status` (`status`),
    CONSTRAINT `fk_sa_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_sa_canceller` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_sa_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_sa_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 13. Table: stock_adjustment_items (Inventory adjustment line items)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_adjustment_items`;
CREATE TABLE `stock_adjustment_items` (
    `stock_adjustment_item_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `stock_adjustment_id` INT(10) UNSIGNED NOT NULL,
    `item_id` INT(10) UNSIGNED NOT NULL,
    `previous_quantity` DECIMAL(14,3) NOT NULL,
    `adjusted_quantity` DECIMAL(14,3) NOT NULL,
    `difference` DECIMAL(14,3) GENERATED ALWAYS AS (`adjusted_quantity` - `previous_quantity`) STORED,
    PRIMARY KEY (`stock_adjustment_item_id`),
    UNIQUE KEY `uq_sai_single_item` (`stock_adjustment_id`, `item_id`),
    KEY `idx_sai_item` (`item_id`),
    CONSTRAINT `fk_sai_adjustment` FOREIGN KEY (`stock_adjustment_id`) REFERENCES `stock_adjustments` (`stock_adjustment_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sai_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_sai_prev_qty` CHECK (`previous_quantity` >= 0),
    CONSTRAINT `chk_sai_adj_qty` CHECK (`adjusted_quantity` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 14. Table: stock_movements (Audit Ledger / Source of Truth)
-- -----------------------------------------------------------------------------
-- 14. Table: bad_products (Damaged, Defective, Expired, Spoiled Products)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `bad_products`;
CREATE TABLE `bad_products` (
    `bad_product_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `bad_product_number` VARCHAR(50) NOT NULL,
    `item_id` INT(10) UNSIGNED NOT NULL,
    `warehouse_id` INT(10) UNSIGNED NOT NULL,
    `condition_type` ENUM('damaged', 'defective', 'expired', 'spoiled', 'unusable', 'other') NOT NULL DEFAULT 'damaged',
    `quantity` DECIMAL(14,3) NOT NULL,
    `reason` VARCHAR(255) NOT NULL COMMENT 'Detailed explanation or notes',
    `status` ENUM('completed', 'cancelled') NOT NULL DEFAULT 'completed',
    `reported_by` INT(10) UNSIGNED NOT NULL,
    `cancelled_at` DATETIME DEFAULT NULL,
    `cancelled_by` INT(10) UNSIGNED DEFAULT NULL,
    `cancellation_reason` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`bad_product_id`),
    UNIQUE KEY `uq_bad_products_number` (`bad_product_number`),
    KEY `idx_bad_products_item` (`item_id`),
    KEY `idx_bad_products_warehouse` (`warehouse_id`),
    KEY `idx_bad_products_condition` (`condition_type`),
    KEY `idx_bad_products_status` (`status`),
    KEY `fk_bad_products_reporter` (`reported_by`),
    KEY `fk_bad_products_canceller` (`cancelled_by`),
    KEY `idx_bad_products_created_at` (`created_at`),
    CONSTRAINT `fk_bad_products_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_bad_products_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_bad_products_reporter` FOREIGN KEY (`reported_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_bad_products_canceller` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_bad_products_qty` CHECK (`quantity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 15. Table: stock_movements (Immutable Double-Entry Style Stock Audit Ledger)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_movements`;
CREATE TABLE `stock_movements` (
    `movement_id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `item_id` INT(10) UNSIGNED NOT NULL,
    `warehouse_id` INT(10) UNSIGNED NOT NULL,
    `movement_type` ENUM(
        'STOCK_IN',
        'STOCK_OUT',
        'STOCK_TRANSFER_IN',
        'STOCK_TRANSFER_OUT',
        'STOCK_ADJUSTMENT',
        'BAD_PRODUCT',
        'STOCK_IN_CANCEL',
        'STOCK_OUT_CANCEL',
        'STOCK_TRANSFER_CANCEL',
        'STOCK_ADJUSTMENT_CANCEL',
        'BAD_PRODUCT_CANCEL'
    ) NOT NULL,
    `stock_in_id` INT(10) UNSIGNED DEFAULT NULL,
    `stock_out_id` INT(10) UNSIGNED DEFAULT NULL,
    `stock_transfer_id` INT(10) UNSIGNED DEFAULT NULL,
    `stock_adjustment_id` INT(10) UNSIGNED DEFAULT NULL,
    `bad_product_id` INT(10) UNSIGNED DEFAULT NULL,
    `reference_number` VARCHAR(100) NOT NULL,
    `quantity_in` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    `quantity_out` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    `balance_after` DECIMAL(14,3) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`movement_id`),
    KEY `fk_sm_stock_in` (`stock_in_id`),
    KEY `fk_sm_stock_out` (`stock_out_id`),
    KEY `fk_sm_stock_transfer` (`stock_transfer_id`),
    KEY `fk_sm_stock_adjustment` (`stock_adjustment_id`),
    KEY `fk_sm_bad_product` (`bad_product_id`),
    KEY `idx_sm_warehouse_date` (`warehouse_id`, `created_at`),
    KEY `idx_sm_item_wh_date` (`item_id`, `warehouse_id`, `created_at`),
    KEY `idx_sm_type` (`movement_type`),
    CONSTRAINT `fk_sm_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_sm_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_sm_stock_in` FOREIGN KEY (`stock_in_id`) REFERENCES `stock_ins` (`stock_in_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_sm_stock_out` FOREIGN KEY (`stock_out_id`) REFERENCES `stock_outs` (`stock_out_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_sm_stock_transfer` FOREIGN KEY (`stock_transfer_id`) REFERENCES `stock_transfers` (`stock_transfer_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_sm_stock_adjustment` FOREIGN KEY (`stock_adjustment_id`) REFERENCES `stock_adjustments` (`stock_adjustment_id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_sm_bad_product` FOREIGN KEY (`bad_product_id`) REFERENCES `bad_products` (`bad_product_id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_sm_single_reference` CHECK (
        (CASE WHEN `stock_in_id` IS NOT NULL THEN 1 ELSE 0 END) +
        (CASE WHEN `stock_out_id` IS NOT NULL THEN 1 ELSE 0 END) +
        (CASE WHEN `stock_transfer_id` IS NOT NULL THEN 1 ELSE 0 END) +
        (CASE WHEN `stock_adjustment_id` IS NOT NULL THEN 1 ELSE 0 END) +
        (CASE WHEN `bad_product_id` IS NOT NULL THEN 1 ELSE 0 END) = 1
    ),
    CONSTRAINT `chk_sm_qty_in` CHECK (`quantity_in` >= 0),
    CONSTRAINT `chk_sm_qty_out` CHECK (`quantity_out` >= 0),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 15b. Table: api_rate_limits (API Rate Limiting & DoS Protection)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `api_rate_limits`;
CREATE TABLE `api_rate_limits` (
    `rate_limit_id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_key` VARCHAR(100) NOT NULL COMMENT 'Client IP or API Token Hash',
    `endpoint` VARCHAR(100) NOT NULL,
    `request_count` INT(10) UNSIGNED NOT NULL DEFAULT 1,
    `window_start` INT(10) UNSIGNED NOT NULL,
    PRIMARY KEY (`rate_limit_id`),
    UNIQUE KEY `uq_client_endpoint_window` (`client_key`, `endpoint`, `window_start`),
    KEY `idx_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- 16. Views (Reporting & Analytical Projections)
-- -----------------------------------------------------------------------------

-- View 1: Real-time Inventory Health & Low Stock Indicator
DROP VIEW IF EXISTS `v_low_stock_items`;
CREATE VIEW `v_low_stock_items` AS
SELECT
    `w`.`warehouse_id`,
    `w`.`warehouse_code`,
    `w`.`warehouse_name`,
    `i`.`item_id`,
    `i`.`item_code`,
    `i`.`item_name`,
    `i`.`item_type`,
    `c`.`category_id`,
    `c`.`category_name`,
    `i`.`unit`,
    `inv`.`quantity` AS `current_quantity`,
    CASE
        WHEN `inv`.`reorder_level` > 0 THEN `inv`.`reorder_level`
        ELSE `i`.`default_reorder_level`
    END AS `effective_reorder_level`,
    (`inv`.`quantity` <= CASE
        WHEN `inv`.`reorder_level` > 0 THEN `inv`.`reorder_level`
        ELSE `i`.`default_reorder_level`
    END) AS `is_low_stock`
FROM `inventory` `inv`
JOIN `items` `i` ON `i`.`item_id` = `inv`.`item_id`
JOIN `warehouses` `w` ON `w`.`warehouse_id` = `inv`.`warehouse_id`
LEFT JOIN `categories` `c` ON `c`.`category_id` = `i`.`category_id`
WHERE `i`.`status` = 'active'
  AND `w`.`status` = 'active';

-- View 1b: Filtered Active Low Stock Alerts (Filtered for is_low_stock = 1)
DROP VIEW IF EXISTS `v_active_low_stock_alerts`;
CREATE VIEW `v_active_low_stock_alerts` AS
SELECT
    `warehouse_id`,
    `warehouse_code`,
    `warehouse_name`,
    `item_id`,
    `item_code`,
    `item_name`,
    `item_type`,
    `category_id`,
    `category_name`,
    `unit`,
    `current_quantity`,
    `effective_reorder_level`,
    (`effective_reorder_level` - `current_quantity`) AS `deficit_quantity`,
    `is_low_stock`
FROM `v_low_stock_items`
WHERE `is_low_stock` = 1;

-- View 2: Complete Chronological Stock Card (Audit Ledger Statement)
DROP VIEW IF EXISTS `v_stock_card`;
CREATE VIEW `v_stock_card` AS
SELECT
    `sm`.`movement_id`,
    `sm`.`created_at` AS `movement_date`,
    `sm`.`reference_number`,
    `sm`.`movement_type`,
    COALESCE(`si`.`source_type`, `so`.`source_type`) AS `source_type`,
    COALESCE(`si`.`source_reference_no`, `so`.`source_reference_no`) AS `source_reference_no`,
    `sm`.`item_id`,
    `it`.`item_code`,
    `it`.`item_name`,
    `it`.`item_type`,
    `c`.`category_id`,
    `c`.`category_name`,
    `sm`.`warehouse_id`,
    `w`.`warehouse_code`,
    `w`.`warehouse_name`,
    `sm`.`quantity_in`,
    `sm`.`quantity_out`,
    `sm`.`balance_after`,
    COALESCE(`si`.`created_by`, `so`.`created_by`, `st`.`created_by`, `sa`.`created_by`, `bp`.`reported_by`) AS `created_by`,
    `u`.`name` AS `created_by_name`,
    COALESCE(`si`.`cancelled_by`, `so`.`cancelled_by`, `st`.`cancelled_by`, `sa`.`cancelled_by`, `bp`.`cancelled_by`) AS `cancelled_by`,
    `uc`.`name` AS `cancelled_by_name`
FROM `stock_movements` `sm`
JOIN `items` `it` ON `it`.`item_id` = `sm`.`item_id`
JOIN `warehouses` `w` ON `w`.`warehouse_id` = `sm`.`warehouse_id`
LEFT JOIN `categories` `c` ON `c`.`category_id` = `it`.`category_id`
LEFT JOIN `stock_ins` `si` ON `si`.`stock_in_id` = `sm`.`stock_in_id`
LEFT JOIN `stock_outs` `so` ON `so`.`stock_out_id` = `sm`.`stock_out_id`
LEFT JOIN `stock_transfers` `st` ON `st`.`stock_transfer_id` = `sm`.`stock_transfer_id`
LEFT JOIN `stock_adjustments` `sa` ON `sa`.`stock_adjustment_id` = `sm`.`stock_adjustment_id`
LEFT JOIN `bad_products` `bp` ON `bp`.`bad_product_id` = `sm`.`bad_product_id`
LEFT JOIN `users` `u` ON `u`.`user_id` = COALESCE(`si`.`created_by`, `so`.`created_by`, `st`.`created_by`, `sa`.`created_by`, `bp`.`reported_by`)
LEFT JOIN `users` `uc` ON `uc`.`user_id` = COALESCE(`si`.`cancelled_by`, `so`.`cancelled_by`, `st`.`cancelled_by`, `sa`.`cancelled_by`, `bp`.`cancelled_by`);

-- View 3: Comprehensive Inventory Balance (Raw Materials & Finished Goods Overview)
DROP VIEW IF EXISTS `v_inventory_balance`;
CREATE VIEW `v_inventory_balance` AS
SELECT
    `inv`.`inventory_id`,
    `i`.`item_id`,
    `i`.`item_code`,
    `i`.`item_name`,
    `i`.`item_type`,
    `c`.`category_id`,
    `c`.`category_name`,
    `i`.`unit`,
    `w`.`warehouse_id`,
    `w`.`warehouse_code`,
    `w`.`warehouse_name`,
    `inv`.`quantity` AS `current_quantity`,
    CASE
        WHEN `inv`.`reorder_level` > 0 THEN `inv`.`reorder_level`
        ELSE `i`.`default_reorder_level`
    END AS `effective_reorder_level`,
    `inv`.`updated_at` AS `last_updated_at`
FROM `inventory` `inv`
JOIN `items` `i` ON `i`.`item_id` = `inv`.`item_id`
JOIN `warehouses` `w` ON `w`.`warehouse_id` = `inv`.`warehouse_id`
LEFT JOIN `categories` `c` ON `c`.`category_id` = `i`.`category_id`
WHERE `i`.`status` = 'active'
  AND `w`.`status` = 'active';

-- View 4: Daily Transaction Summary (Administrative Dashboard)
DROP VIEW IF EXISTS `v_daily_transaction_summary`;
CREATE VIEW `v_daily_transaction_summary` AS
SELECT
    DATE(`sm`.`created_at`) AS `transaction_date`,
    `w`.`warehouse_id`,
    `w`.`warehouse_code`,
    `w`.`warehouse_name`,
    
    -- Transaction Counts
    COUNT(CASE WHEN `sm`.`movement_type` = 'STOCK_IN' THEN 1 END) AS `stock_in_count`,
    COUNT(CASE WHEN `sm`.`movement_type` = 'STOCK_OUT' THEN 1 END) AS `stock_out_count`,
    COUNT(CASE WHEN `sm`.`movement_type` = 'STOCK_TRANSFER_IN' THEN 1 END) AS `transfer_in_count`,
    COUNT(CASE WHEN `sm`.`movement_type` = 'STOCK_TRANSFER_OUT' THEN 1 END) AS `transfer_out_count`,
    COUNT(CASE WHEN `sm`.`movement_type` = 'STOCK_ADJUSTMENT' THEN 1 END) AS `adjustment_count`,
    COUNT(CASE WHEN `sm`.`movement_type` = 'BAD_PRODUCT' THEN 1 END) AS `bad_product_count`,
    COUNT(CASE WHEN `sm`.`movement_type` LIKE '%_CANCEL' THEN 1 END) AS `cancellation_count`,
    COUNT(*) AS `total_movement_count`,
    COUNT(DISTINCT `sm`.`item_id`) AS `distinct_items_active`,

    -- Quantity Volumes
    COALESCE(SUM(CASE WHEN `sm`.`movement_type` = 'STOCK_IN' THEN `sm`.`quantity_in` ELSE 0 END), 0.000) AS `total_stock_in_qty`,
    COALESCE(SUM(CASE WHEN `sm`.`movement_type` = 'STOCK_OUT' THEN `sm`.`quantity_out` ELSE 0 END), 0.000) AS `total_stock_out_qty`,
    COALESCE(SUM(CASE WHEN `sm`.`movement_type` = 'STOCK_TRANSFER_IN' THEN `sm`.`quantity_in` ELSE 0 END), 0.000) AS `total_transfer_in_qty`,
    COALESCE(SUM(CASE WHEN `sm`.`movement_type` = 'STOCK_TRANSFER_OUT' THEN `sm`.`quantity_out` ELSE 0 END), 0.000) AS `total_transfer_out_qty`,
    COALESCE(SUM(CASE WHEN `sm`.`movement_type` = 'STOCK_ADJUSTMENT' THEN `sm`.`quantity_in` - `sm`.`quantity_out` ELSE 0 END), 0.000) AS `net_adjustment_qty`,
    COALESCE(SUM(CASE WHEN `sm`.`movement_type` = 'BAD_PRODUCT' THEN `sm`.`quantity_out` ELSE 0 END), 0.000) AS `total_bad_product_qty`,
    COALESCE(SUM(CASE WHEN `sm`.`movement_type` LIKE '%_CANCEL' THEN `sm`.`quantity_in` - `sm`.`quantity_out` ELSE 0 END), 0.000) AS `net_cancellation_qty`,

    -- Daily Inflow / Outflow / Net Change
    COALESCE(SUM(`sm`.`quantity_in`), 0.000) AS `daily_total_inflow_qty`,
    COALESCE(SUM(`sm`.`quantity_out`), 0.000) AS `daily_total_outflow_qty`,
    COALESCE(SUM(`sm`.`quantity_in` - `sm`.`quantity_out`), 0.000) AS `daily_net_change_qty`
FROM `stock_movements` `sm`
JOIN `warehouses` `w` ON `w`.`warehouse_id` = `sm`.`warehouse_id`
GROUP BY DATE(`sm`.`created_at`), `w`.`warehouse_id`, `w`.`warehouse_code`, `w`.`warehouse_name`;

-- -----------------------------------------------------------------------------
-- 17. Triggers for Bulletproof Ledger Consistency & Never-Delete Policy
-- -----------------------------------------------------------------------------

-- =============================================================================
-- 17.1 Core Ledger Immutability & Balance Verification (stock_movements)
-- =============================================================================

DROP TRIGGER IF EXISTS `trg_stock_movements_verify_balance`;
DELIMITER $$
CREATE TRIGGER `trg_stock_movements_verify_balance`
BEFORE INSERT ON `stock_movements`
FOR EACH ROW
BEGIN
    DECLARE current_stock DECIMAL(14,3) DEFAULT 0.000;
    DECLARE calculated_balance DECIMAL(14,3);

    -- Fetch current recorded quantity in target warehouse
    SELECT COALESCE(quantity, 0.000) INTO current_stock
    FROM inventory
    WHERE item_id = NEW.item_id AND warehouse_id = NEW.warehouse_id
    LIMIT 1;

    -- Derive expected post-movement balance
    SET calculated_balance = current_stock + NEW.quantity_in - NEW.quantity_out;

    -- Guard against negative inventory
    IF calculated_balance < 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integrity violation: Movement results in negative inventory';
    END IF;

    -- Auto-populate balance_after if NULL/default, or verify math if explicitly provided
    IF NEW.balance_after IS NULL OR NEW.balance_after = 0.000 THEN
        SET NEW.balance_after = calculated_balance;
    ELSEIF NEW.balance_after <> calculated_balance THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integrity violation: balance_after does not match current inventory + net movement';
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_stock_movements_after_insert`;
DELIMITER $$
CREATE TRIGGER `trg_stock_movements_after_insert`
AFTER INSERT ON `stock_movements`
FOR EACH ROW
BEGIN
    INSERT INTO inventory (item_id, warehouse_id, quantity)
    VALUES (NEW.item_id, NEW.warehouse_id, NEW.balance_after)
    ON DUPLICATE KEY UPDATE
        quantity = NEW.balance_after;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_stock_movements_prevent_update`;
DELIMITER $$
CREATE TRIGGER `trg_stock_movements_prevent_update`
BEFORE UPDATE ON `stock_movements`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Ledger violation: stock_movements records are immutable and cannot be updated';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_stock_movements_prevent_delete`;
DELIMITER $$
CREATE TRIGGER `trg_stock_movements_prevent_delete`
BEFORE DELETE ON `stock_movements`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Ledger violation: stock_movements records are immutable and cannot be deleted';
END$$
DELIMITER ;

-- =============================================================================
-- 17.2 Transaction Hard-Delete Prevention Triggers ("Never Delete, Cancel Instead")
-- =============================================================================

DROP TRIGGER IF EXISTS `trg_stock_ins_prevent_delete`;
DELIMITER $$
CREATE TRIGGER `trg_stock_ins_prevent_delete`
BEFORE DELETE ON `stock_ins`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Integrity violation: Transaction records cannot be deleted. Cancel the transaction instead.';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_stock_outs_prevent_delete`;
DELIMITER $$
CREATE TRIGGER `trg_stock_outs_prevent_delete`
BEFORE DELETE ON `stock_outs`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Integrity violation: Transaction records cannot be deleted. Cancel the transaction instead.';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_stock_transfers_prevent_delete`;
DELIMITER $$
CREATE TRIGGER `trg_stock_transfers_prevent_delete`
BEFORE DELETE ON `stock_transfers`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Integrity violation: Transaction records cannot be deleted. Cancel the transaction instead.';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_stock_adjustments_prevent_delete`;
DELIMITER $$
CREATE TRIGGER `trg_stock_adjustments_prevent_delete`
BEFORE DELETE ON `stock_adjustments`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Integrity violation: Transaction records cannot be deleted. Cancel the transaction instead.';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_bad_products_prevent_delete`;
DELIMITER $$
CREATE TRIGGER `trg_bad_products_prevent_delete`
BEFORE DELETE ON `bad_products`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Integrity violation: Bad product records cannot be deleted. Cancel the record instead.';
END$$
DELIMITER ;

-- =============================================================================
-- 17.3 Line Item Modification & Deletion Prevention Triggers
-- =============================================================================

DROP TRIGGER IF EXISTS `trg_stock_in_items_prevent_delete`;
DELIMITER $$
CREATE TRIGGER `trg_stock_in_items_prevent_delete`
BEFORE DELETE ON `stock_in_items`
FOR EACH ROW
BEGIN
    DECLARE parent_status VARCHAR(20);
    SELECT status INTO parent_status FROM stock_ins WHERE stock_in_id = OLD.stock_in_id;
    IF parent_status IN ('completed', 'cancelled') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integrity violation: Transaction line items cannot be deleted from completed or cancelled transactions.';
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_stock_in_items_prevent_update`;
DELIMITER $$
CREATE TRIGGER `trg_stock_in_items_prevent_update`
BEFORE UPDATE ON `stock_in_items`
FOR EACH ROW
BEGIN
    DECLARE parent_status VARCHAR(20);
    SELECT status INTO parent_status FROM stock_ins WHERE stock_in_id = OLD.stock_in_id;
    IF parent_status IN ('completed', 'cancelled') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integrity violation: Transaction line items cannot be modified for completed or cancelled transactions.';
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_stock_out_items_prevent_delete`;
DELIMITER $$
CREATE TRIGGER `trg_stock_out_items_prevent_delete`
BEFORE DELETE ON `stock_out_items`
FOR EACH ROW
BEGIN
    DECLARE parent_status VARCHAR(20);
    SELECT status INTO parent_status FROM stock_outs WHERE stock_out_id = OLD.stock_out_id;
    IF parent_status IN ('completed', 'cancelled') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integrity violation: Transaction line items cannot be deleted from completed or cancelled transactions.';
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_stock_out_items_prevent_update`;
DELIMITER $$
CREATE TRIGGER `trg_stock_out_items_prevent_update`
BEFORE UPDATE ON `stock_out_items`
FOR EACH ROW
BEGIN
    DECLARE parent_status VARCHAR(20);
    SELECT status INTO parent_status FROM stock_outs WHERE stock_out_id = OLD.stock_out_id;
    IF parent_status IN ('completed', 'cancelled') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integrity violation: Transaction line items cannot be modified for completed or cancelled transactions.';
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_stock_transfer_items_prevent_delete`;
DELIMITER $$
CREATE TRIGGER `trg_stock_transfer_items_prevent_delete`
BEFORE DELETE ON `stock_transfer_items`
FOR EACH ROW
BEGIN
    DECLARE parent_status VARCHAR(20);
    SELECT status INTO parent_status FROM stock_transfers WHERE stock_transfer_id = OLD.stock_transfer_id;
    IF parent_status IN ('completed', 'cancelled') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integrity violation: Transaction line items cannot be deleted from completed or cancelled transactions.';
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_stock_transfer_items_prevent_update`;
DELIMITER $$
CREATE TRIGGER `trg_stock_transfer_items_prevent_update`
BEFORE UPDATE ON `stock_transfer_items`
FOR EACH ROW
BEGIN
    DECLARE parent_status VARCHAR(20);
    SELECT status INTO parent_status FROM stock_transfers WHERE stock_transfer_id = OLD.stock_transfer_id;
    IF parent_status IN ('completed', 'cancelled') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integrity violation: Transaction line items cannot be modified for completed or cancelled transactions.';
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_stock_adjustment_items_prevent_delete`;
DELIMITER $$
CREATE TRIGGER `trg_stock_adjustment_items_prevent_delete`
BEFORE DELETE ON `stock_adjustment_items`
FOR EACH ROW
BEGIN
    DECLARE parent_status VARCHAR(20);
    SELECT status INTO parent_status FROM stock_adjustments WHERE stock_adjustment_id = OLD.stock_adjustment_id;
    IF parent_status IN ('approved', 'cancelled') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integrity violation: Transaction line items cannot be deleted from approved or cancelled adjustments.';
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_stock_adjustment_items_prevent_update`;
DELIMITER $$
CREATE TRIGGER `trg_stock_adjustment_items_prevent_update`
BEFORE UPDATE ON `stock_adjustment_items`
FOR EACH ROW
BEGIN
    DECLARE parent_status VARCHAR(20);
    SELECT status INTO parent_status FROM stock_adjustments WHERE stock_adjustment_id = OLD.stock_adjustment_id;
    IF parent_status IN ('approved', 'cancelled') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integrity violation: Transaction line items cannot be modified for approved or cancelled adjustments.';
    END IF;
END$$
DELIMITER ;

-- =============================================================================
-- 17.4 Transaction Cancellation & Automatic Reversal Triggers
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Stock In Cancellation
-- -----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_stock_ins_before_update`;
DELIMITER $$
CREATE TRIGGER `trg_stock_ins_before_update`
BEFORE UPDATE ON `stock_ins`
FOR EACH ROW
BEGIN
    IF OLD.status = 'cancelled' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integrity violation: Cancelled transactions cannot be modified.';
    END IF;

    IF OLD.status = 'completed' AND NEW.status NOT IN ('completed', 'cancelled') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integrity violation: Completed transactions can only be cancelled.';
    END IF;

    IF OLD.status <> 'cancelled' AND NEW.status = 'cancelled' THEN
        IF NEW.cancelled_at IS NULL THEN
            SET NEW.cancelled_at = CURRENT_TIMESTAMP;
        END IF;
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_stock_ins_after_update`;
DELIMITER $$
CREATE TRIGGER `trg_stock_ins_after_update`
AFTER UPDATE ON `stock_ins`
FOR EACH ROW
BEGIN
    IF OLD.status = 'completed' AND NEW.status = 'cancelled' THEN
        INSERT INTO stock_movements (
            item_id,
            warehouse_id,
            movement_type,
            stock_in_id,
            reference_number,
            quantity_in,
            quantity_out
        )
        SELECT
            sii.item_id,
            NEW.warehouse_id,
            'STOCK_IN_CANCEL',
            NEW.stock_in_id,
            CONCAT(NEW.transaction_number, '-CAN'),
            0.000,
            sii.quantity
        FROM stock_in_items sii
        WHERE sii.stock_in_id = NEW.stock_in_id;
    END IF;
END$$
DELIMITER ;

-- -----------------------------------------------------------------------------
-- Stock Out Cancellation
-- -----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_stock_outs_before_update`;
DELIMITER $$
CREATE TRIGGER `trg_stock_outs_before_update`
BEFORE UPDATE ON `stock_outs`
FOR EACH ROW
BEGIN
    IF OLD.status = 'cancelled' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integrity violation: Cancelled transactions cannot be modified.';
    END IF;

    IF OLD.status = 'completed' AND NEW.status NOT IN ('completed', 'cancelled') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integrity violation: Completed transactions can only be cancelled.';
    END IF;

    IF OLD.status <> 'cancelled' AND NEW.status = 'cancelled' THEN
        IF NEW.cancelled_at IS NULL THEN
            SET NEW.cancelled_at = CURRENT_TIMESTAMP;
        END IF;
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_stock_outs_after_update`;
DELIMITER $$
CREATE TRIGGER `trg_stock_outs_after_update`
AFTER UPDATE ON `stock_outs`
FOR EACH ROW
BEGIN
    IF OLD.status = 'completed' AND NEW.status = 'cancelled' THEN
        INSERT INTO stock_movements (
            item_id,
            warehouse_id,
            movement_type,
            stock_out_id,
            reference_number,
            quantity_in,
            quantity_out
        )
        SELECT
            soi.item_id,
            NEW.warehouse_id,
            'STOCK_OUT_CANCEL',
            NEW.stock_out_id,
            CONCAT(NEW.transaction_number, '-CAN'),
            soi.quantity,
            0.000
        FROM stock_out_items soi
        WHERE soi.stock_out_id = NEW.stock_out_id;
    END IF;
END$$
DELIMITER ;

-- -----------------------------------------------------------------------------
-- Stock Transfer Cancellation
-- -----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_stock_transfers_before_update`;
DELIMITER $$
CREATE TRIGGER `trg_stock_transfers_before_update`
BEFORE UPDATE ON `stock_transfers`
FOR EACH ROW
BEGIN
    IF OLD.status = 'cancelled' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integrity violation: Cancelled transactions cannot be modified.';
    END IF;

    IF OLD.status = 'completed' AND NEW.status NOT IN ('completed', 'cancelled') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integrity violation: Completed transactions can only be cancelled.';
    END IF;

    IF OLD.status <> 'cancelled' AND NEW.status = 'cancelled' THEN
        IF NEW.cancelled_at IS NULL THEN
            SET NEW.cancelled_at = CURRENT_TIMESTAMP;
        END IF;
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_stock_transfers_after_update`;
DELIMITER $$
CREATE TRIGGER `trg_stock_transfers_after_update`
AFTER UPDATE ON `stock_transfers`
FOR EACH ROW
BEGIN
    IF OLD.status = 'completed' AND NEW.status = 'cancelled' THEN
        -- 1. Deduct from destination warehouse (validates destination stock sufficiency)
        INSERT INTO stock_movements (
            item_id,
            warehouse_id,
            movement_type,
            stock_transfer_id,
            reference_number,
            quantity_in,
            quantity_out
        )
        SELECT
            sti.item_id,
            NEW.destination_warehouse_id,
            'STOCK_TRANSFER_CANCEL',
            NEW.stock_transfer_id,
            CONCAT(NEW.transaction_number, '-CAN-DEST'),
            0.000,
            sti.quantity
        FROM stock_transfer_items sti
        WHERE sti.stock_transfer_id = NEW.stock_transfer_id;

        -- 2. Restore to source warehouse
        INSERT INTO stock_movements (
            item_id,
            warehouse_id,
            movement_type,
            stock_transfer_id,
            reference_number,
            quantity_in,
            quantity_out
        )
        SELECT
            sti.item_id,
            NEW.source_warehouse_id,
            'STOCK_TRANSFER_CANCEL',
            NEW.stock_transfer_id,
            CONCAT(NEW.transaction_number, '-CAN-SRC'),
            sti.quantity,
            0.000
        FROM stock_transfer_items sti
        WHERE sti.stock_transfer_id = NEW.stock_transfer_id;
    END IF;
END$$
DELIMITER ;

-- -----------------------------------------------------------------------------
-- Stock Adjustment Cancellation
-- -----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_stock_adjustments_before_update`;
DELIMITER $$
CREATE TRIGGER `trg_stock_adjustments_before_update`
BEFORE UPDATE ON `stock_adjustments`
FOR EACH ROW
BEGIN
    IF OLD.status = 'cancelled' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integrity violation: Cancelled transactions cannot be modified.';
    END IF;

    IF OLD.status = 'approved' AND NEW.status NOT IN ('approved', 'cancelled') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integrity violation: Approved adjustments can only be cancelled.';
    END IF;

    IF OLD.status <> 'cancelled' AND NEW.status = 'cancelled' THEN
        IF NEW.cancelled_at IS NULL THEN
            SET NEW.cancelled_at = CURRENT_TIMESTAMP;
        END IF;
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_stock_adjustments_after_update`;
DELIMITER $$
CREATE TRIGGER `trg_stock_adjustments_after_update`
AFTER UPDATE ON `stock_adjustments`
FOR EACH ROW
BEGIN
    IF OLD.status = 'approved' AND NEW.status = 'cancelled' THEN
        INSERT INTO stock_movements (
            item_id,
            warehouse_id,
            movement_type,
            stock_adjustment_id,
            reference_number,
            quantity_in,
            quantity_out
        )
        SELECT
            sai.item_id,
            NEW.warehouse_id,
            'STOCK_ADJUSTMENT_CANCEL',
            NEW.stock_adjustment_id,
            CONCAT(NEW.transaction_number, '-CAN'),
            CASE WHEN sai.difference < 0 THEN ABS(sai.difference) ELSE 0.000 END,
            CASE WHEN sai.difference > 0 THEN sai.difference ELSE 0.000 END
        FROM stock_adjustment_items sai
        WHERE sai.stock_adjustment_id = NEW.stock_adjustment_id
          AND sai.difference <> 0;
    END IF;
END$$
DELIMITER ;

-- -----------------------------------------------------------------------------
-- Bad Products Automatic Ledger Deduction & Cancellation
-- -----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_bad_products_after_insert`;
DELIMITER $$
CREATE TRIGGER `trg_bad_products_after_insert`
AFTER INSERT ON `bad_products`
FOR EACH ROW
BEGIN
    IF NEW.status = 'completed' THEN
        INSERT INTO stock_movements (
            item_id,
            warehouse_id,
            movement_type,
            bad_product_id,
            reference_number,
            quantity_in,
            quantity_out
        ) VALUES (
            NEW.item_id,
            NEW.warehouse_id,
            'BAD_PRODUCT',
            NEW.bad_product_id,
            NEW.bad_product_number,
            0.000,
            NEW.quantity
        );
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_bad_products_before_update`;
DELIMITER $$
CREATE TRIGGER `trg_bad_products_before_update`
BEFORE UPDATE ON `bad_products`
FOR EACH ROW
BEGIN
    IF OLD.status = 'cancelled' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integrity violation: Cancelled bad product records cannot be modified.';
    END IF;

    IF OLD.status <> 'cancelled' AND NEW.status = 'cancelled' THEN
        IF NEW.cancelled_at IS NULL THEN
            SET NEW.cancelled_at = CURRENT_TIMESTAMP;
        END IF;
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_bad_products_after_update`;
DELIMITER $$
CREATE TRIGGER `trg_bad_products_after_update`
AFTER UPDATE ON `bad_products`
FOR EACH ROW
BEGIN
    IF OLD.status = 'completed' AND NEW.status = 'cancelled' THEN
        INSERT INTO stock_movements (
            item_id,
            warehouse_id,
            movement_type,
            bad_product_id,
            reference_number,
            quantity_in,
            quantity_out
        ) VALUES (
            NEW.item_id,
            NEW.warehouse_id,
            'BAD_PRODUCT_CANCEL',
            NEW.bad_product_id,
            CONCAT(NEW.bad_product_number, '-CAN'),
            NEW.quantity,
            0.000
        );
    END IF;
END$$
DELIMITER ;

