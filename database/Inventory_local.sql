-- Local standalone schema for ERP team testing
-- UOM is intentionally limited to pcs and box.
-- This database is independent from the central ERP database.


-- ============================================================
-- MASTER TABLES
-- Shared by this team's local database
-- ============================================================

CREATE TABLE `units_of_measure` (
  `uom_code` VARCHAR(10) NOT NULL,
  `uom_name` VARCHAR(50) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`uom_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `units_of_measure` (`uom_code`, `uom_name`) VALUES
  ('pcs', 'Pieces'),
  ('box', 'Boxes');

CREATE TABLE `warehouses` (
  `warehouse_id` INT(11) NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `location` VARCHAR(255) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`warehouse_id`),
  UNIQUE KEY `uq_warehouses_code` (`code`),
  UNIQUE KEY `uq_warehouses_name` (`name`),
  KEY `idx_warehouses_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `user_id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('super_admin','admin') NOT NULL DEFAULT 'admin',
  `team` VARCHAR(50) DEFAULT NULL,
  `warehouse_id` INT(11) DEFAULT NULL,
  `api_token` VARCHAR(64) DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_api_token` (`api_token`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_warehouse` (`warehouse_id`),
  KEY `idx_users_status` (`status`),
  CONSTRAINT `fk_users_warehouse`
    FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `employees` (
  `employee_id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) DEFAULT NULL,
  `employee_code` VARCHAR(20) NOT NULL,
  `first_name` VARCHAR(50) NOT NULL,
  `last_name` VARCHAR(50) NOT NULL,
  `position` VARCHAR(50) NOT NULL,
  `department` ENUM(
    'Procurement','Inventory','Production','Sales',
    'Finance','HR','Admin','Delivery'
  ) NOT NULL,
  `hire_date` DATE NOT NULL,
  `birthdate` DATE DEFAULT NULL,
  `sss_no` VARCHAR(20) DEFAULT NULL,
  `philhealth_no` VARCHAR(20) DEFAULT NULL,
  `pagibig_no` VARCHAR(20) DEFAULT NULL,
  `basic_salary` DECIMAL(12,2) DEFAULT 0.00,
  `status` ENUM('Active','Inactive','Resigned') DEFAULT 'Active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`employee_id`),
  UNIQUE KEY `uq_employees_code` (`employee_code`),
  KEY `idx_employees_user` (`user_id`),
  KEY `idx_employees_department` (`department`),
  CONSTRAINT `fk_emp_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `items` (
  `item_id` INT(11) NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `type` ENUM('raw_material','finished_good') NOT NULL,
  `unit` VARCHAR(10) NOT NULL,
  `reorder_level` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`),
  UNIQUE KEY `uq_items_code` (`code`),
  KEY `idx_items_type` (`type`),
  KEY `idx_items_unit` (`unit`),
  KEY `idx_items_status` (`status`),
  CONSTRAINT `fk_items_unit`
    FOREIGN KEY (`unit`) REFERENCES `units_of_measure` (`uom_code`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `accountability_logs` (
  `log_id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) DEFAULT NULL,
  `team` VARCHAR(50) DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `item_id` INT(11) DEFAULT NULL,
  `quantity` DECIMAL(12,4) DEFAULT NULL,
  `warehouse_id` INT(11) DEFAULT NULL,
  `details` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_logs_user` (`user_id`),
  KEY `idx_logs_item` (`item_id`),
  KEY `idx_logs_warehouse` (`warehouse_id`),
  KEY `idx_logs_created_at` (`created_at`),
  CONSTRAINT `fk_logs_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_logs_item`
    FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_logs_warehouse`
    FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- INVENTORY MODULE
-- ============================================================

CREATE TABLE `stock` (
  `stock_id` INT(11) NOT NULL AUTO_INCREMENT,
  `item_id` INT(11) NOT NULL,
  `warehouse_id` INT(11) NOT NULL,
  `qty_on_hand` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  `reorder_level` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`stock_id`),
  UNIQUE KEY `uq_stock_item_wh` (`item_id`,`warehouse_id`),
  KEY `idx_stock_item` (`item_id`),
  KEY `idx_stock_warehouse` (`warehouse_id`),
  CONSTRAINT `fk_stock_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_stock_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stock_movements` (
  `movement_id` INT(11) NOT NULL AUTO_INCREMENT,
  `item_id` INT(11) NOT NULL,
  `warehouse_id` INT(11) NOT NULL,
  `movement_type` ENUM('STOCK_IN','STOCK_OUT','STOCK_TRANSFER_IN','STOCK_TRANSFER_OUT','STOCK_ADJUSTMENT') NOT NULL,
  `quantity` DECIMAL(12,4) NOT NULL,
  `lot_no` VARCHAR(50) DEFAULT NULL,
  `reference_id` INT(11) DEFAULT NULL,
  `remarks` VARCHAR(255) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`movement_id`),
  KEY `idx_movements_item` (`item_id`),
  KEY `idx_movements_warehouse` (`warehouse_id`),
  KEY `idx_movements_type` (`movement_type`),
  KEY `idx_movements_created_by` (`created_by`),
  KEY `idx_movements_lot` (`lot_no`),
  CONSTRAINT `fk_movements_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_movements_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_movements_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stock_transfers` (
  `transfer_id` INT(11) NOT NULL AUTO_INCREMENT,
  `item_id` INT(11) NOT NULL,
  `source_warehouse_id` INT(11) NOT NULL,
  `destination_warehouse_id` INT(11) NOT NULL,
  `quantity` DECIMAL(12,4) NOT NULL,
  `status` ENUM('pending','in_transit','received','cancelled') NOT NULL DEFAULT 'pending',
  `requested_by` INT(11) DEFAULT NULL,
  `received_by` INT(11) DEFAULT NULL,
  `requested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `received_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`transfer_id`),
  KEY `idx_transfer_item` (`item_id`),
  KEY `idx_transfer_source` (`source_warehouse_id`),
  KEY `idx_transfer_destination` (`destination_warehouse_id`),
  CONSTRAINT `fk_transfer_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_transfer_source` FOREIGN KEY (`source_warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_transfer_destination` FOREIGN KEY (`destination_warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_transfer_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_transfer_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `chk_transfer_different_warehouses` CHECK (`source_warehouse_id` <> `destination_warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stock_adjustments` (
  `adjustment_id` INT(11) NOT NULL AUTO_INCREMENT,
  `item_id` INT(11) NOT NULL,
  `warehouse_id` INT(11) NOT NULL,
  `previous_quantity` DECIMAL(12,4) NOT NULL,
  `adjusted_quantity` DECIMAL(12,4) NOT NULL,
  `difference` DECIMAL(12,4) NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `status` ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `requested_by` INT(11) DEFAULT NULL,
  `approved_by` INT(11) DEFAULT NULL,
  `requested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`adjustment_id`),
  CONSTRAINT `fk_adjustment_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_adjustment_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_adjustment_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_adjustment_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bad_products` (
  `bad_product_id` INT(11) NOT NULL AUTO_INCREMENT,
  `item_id` INT(11) NOT NULL,
  `warehouse_id` INT(11) NOT NULL,
  `quantity` DECIMAL(12,4) NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `status` ENUM('reported','confirmed','disposed','cancelled') NOT NULL DEFAULT 'reported',
  `reported_by` INT(11) DEFAULT NULL,
  `confirmed_by` INT(11) DEFAULT NULL,
  `reported_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `confirmed_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`bad_product_id`),
  CONSTRAINT `fk_bad_product_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_bad_product_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_bad_product_reported_by` FOREIGN KEY (`reported_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_bad_product_confirmed_by` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SYSTEM & SECURITY
-- ============================================================

CREATE TABLE IF NOT EXISTS `api_rate_limits` (
  `rate_limit_id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_key` VARCHAR(100) NOT NULL COMMENT 'Client IP or API Token Hash',
  `endpoint` VARCHAR(100) NOT NULL,
  `request_count` INT(10) UNSIGNED NOT NULL DEFAULT 1,
  `window_start` INT(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`rate_limit_id`),
  UNIQUE KEY `uq_client_endpoint_window` (`client_key`,`endpoint`,`window_start`),
  KEY `idx_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

