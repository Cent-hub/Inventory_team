-- =============================================================================
-- Modernized Database Seed Data: team_inventory_local
-- Aligned with the centralized team_inventory_local relational schema.
-- =============================================================================

USE `team_inventory_local`;

SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 1. Warehouses (4 Active Facilities)
-- -----------------------------------------------------------------------------
INSERT INTO `warehouses` (`warehouse_id`, `code`, `name`, `location`, `description`, `status`) VALUES
(1, 'WH-MAIN', 'Main Warehouse (Laguna)', 'Laguna, Philippines', 'Central storage, raw ingredient staging, and primary distribution hub in Laguna', 'active'),
(2, 'WH-BOND', 'Bonded Warehouse (Manila)', 'Port Area, Manila, Philippines', 'Customs-bonded warehouse facility for imported bulk spirits and tax-exempt storage', 'active'),
(3, 'WH-BOTT', 'Bottling Area (Bulacan)', 'Bulacan, Philippines', 'Bottling, blending, and finished packaging facility in Bulacan', 'active'),
(4, 'WH-DELV', 'Delivery & Distribution Center (Cavite)', 'Cavite, Philippines', 'Dispatch, logistics staging, and customer delivery fulfillment center', 'active')
ON DUPLICATE KEY UPDATE 
  `name` = VALUES(`name`),
  `code` = VALUES(`code`),
  `location` = VALUES(`location`),
  `description` = VALUES(`description`),
  `status` = VALUES(`status`);

-- -----------------------------------------------------------------------------
-- 2. Users (Super Admin & Team Service Accounts)
-- Default Password: 'admin123'
-- -----------------------------------------------------------------------------
INSERT INTO `users` (`id`, `user_id`, `name`, `email`, `password`, `role`, `team`, `warehouse_id`, `api_token`, `status`) VALUES
(1, 1, 'System Super Admin', 'admin@inventory.local', '$2y$10$UH5MIbyhTYTOEiTyOPpgyu54KbT/eNL9SVLktkQFmYxNhPW8/sN8K', 'super_admin', 'Administration', 1, 'd2c89d5e330ef2178ce2c7f275963f2b8563030d0e399ecac4d875107fc20cbb', 'active'),
(2, 2, 'Procurement Service API', 'procurement@inventory.local', '$2y$10$UH5MIbyhTYTOEiTyOPpgyu54KbT/eNL9SVLktkQFmYxNhPW8/sN8K', 'admin', 'Procurement', 1, '8cacfcb41fb35a319472555807be7da7ad2cf0079f2e1ad05e0e85c723dc36f0', 'active'),
(3, 3, 'Production Service API', 'production@inventory.local', '$2y$10$UH5MIbyhTYTOEiTyOPpgyu54KbT/eNL9SVLktkQFmYxNhPW8/sN8K', 'admin', 'Production', 3, '13c169097c8071234a9584fdbe0ed3d41c37f4215d9b3c4cb91760800ea1e786', 'active'),
(4, 4, 'Sales Service API', 'sales@inventory.local', '$2y$10$UH5MIbyhTYTOEiTyOPpgyu54KbT/eNL9SVLktkQFmYxNhPW8/sN8K', 'admin', 'Sales', 1, '1394786dea8f134e70c77e84497bbbd48125e8b5bae7855ccb2d4f8ef5b0f74a', 'active'),
(5, 5, 'Procurement Secondary Admin', 'proc2@inventory.local', '$2y$10$UH5MIbyhTYTOEiTyOPpgyu54KbT/eNL9SVLktkQFmYxNhPW8/sN8K', 'admin', 'Procurement', 2, 'f41a58e788485883635f7ace4f8dbe67aa1802a638ab4dcb0628ed03f9124be9', 'active'),
(6, 6, 'Sales Secondary Admin', 'sales2@inventory.local', '$2y$10$UH5MIbyhTYTOEiTyOPpgyu54KbT/eNL9SVLktkQFmYxNhPW8/sN8K', 'admin', 'Sales', 2, '7af8a22ee365f996796e1853ea5d69c97ee006d7987c9ca94e04572d4e58366a', 'active')
ON DUPLICATE KEY UPDATE 
  `name` = VALUES(`name`),
  `password` = VALUES(`password`),
  `role` = VALUES(`role`),
  `team` = VALUES(`team`),
  `warehouse_id` = VALUES(`warehouse_id`),
  `api_token` = VALUES(`api_token`),
  `status` = VALUES(`status`);

-- -----------------------------------------------------------------------------
-- 3. Employees (employees.user_id -> users.user_id)
-- -----------------------------------------------------------------------------
INSERT INTO `employees` (`employee_id`, `user_id`, `employee_code`, `first_name`, `last_name`, `email`, `phone`, `department`, `position`, `status`, `hire_date`) VALUES
(1, 1, 'EMP-001', 'System', 'Admin', 'admin@inventory.local', '+63 917 111 0001', 'Executive', 'Super Administrator', 'active', '2024-01-01'),
(2, 2, 'EMP-002', 'Peter', 'Procurement', 'procurement@inventory.local', '+63 917 111 0002', 'Procurement', 'Purchasing Specialist', 'active', '2024-01-15'),
(3, 3, 'EMP-003', 'Paul', 'Production', 'production@inventory.local', '+63 917 111 0003', 'Production', 'Master Distiller', 'active', '2024-02-01'),
(4, 4, 'EMP-004', 'Sarah', 'Sales', 'sales@inventory.local', '+63 917 111 0004', 'Sales', 'Accounts Executive', 'active', '2024-02-15')
ON DUPLICATE KEY UPDATE
  `first_name` = VALUES(`first_name`),
  `last_name` = VALUES(`last_name`),
  `department` = VALUES(`department`),
  `position` = VALUES(`position`),
  `status` = VALUES(`status`);

-- -----------------------------------------------------------------------------
-- 4. Items (Raw Materials & Finished Goods)
-- -----------------------------------------------------------------------------
INSERT INTO `items` (`item_id`, `code`, `name`, `description`, `type`, `unit`, `reorder_level`, `status`) VALUES
(1, 'RM-WHISKEY-BASE', 'Bulk Aged Whiskey Base', 'Aged oak-matured distilled whiskey base spirit (65% ABV)', 'raw_material', 'liter', 200.00, 'active'),
(2, 'RM-VODKA-BASE', 'Neutral Vodka Grain Spirit', 'High-purity neutral grain spirit 96% ABV for vodka blending', 'raw_material', 'liter', 200.00, 'active'),
(3, 'RM-RUM-BASE', 'Sugarcane Rum Base', 'Aged dark molasses rum distillate for blending and aging', 'raw_material', 'liter', 150.00, 'active'),
(4, 'RM-GIN-BOTANICALS', 'Gin Botanicals Blend', 'Blend of Tuscan juniper, coriander seed, angelica, and citrus peel', 'raw_material', 'kg', 20.00, 'active'),
(5, 'RM-BOTTLE-750', 'Flint Glass Bottles 750ml', 'Heavy-base flint glass spirit bottles for 750ml bottling line', 'raw_material', 'pcs', 500.00, 'active'),
(6, 'RM-CORK-CAPS', 'Wooden Top Cork Closures', 'Synthetic micro-agglomerated cork with stained natural wood head', 'raw_material', 'pcs', 500.00, 'active'),
(7, 'RM-CARTON-12', '12-Bottle Shipping Cartons', 'Double-wall corrugated shipping boxes with bottle dividers', 'raw_material', 'box', 50.00, 'active'),
(8, 'FG-WHISKEY-750', 'House Reserve Whiskey 750ml', 'Signature 43% ABV oak-aged blended whiskey in 750ml glass bottle', 'finished_good', 'pcs', 50.00, 'active'),
(9, 'FG-VODKA-750', 'Pure Artesian Vodka 750ml', 'Quadruple-filtered neutral grain vodka 40% ABV', 'finished_good', 'pcs', 40.00, 'active'),
(10, 'FG-GIN-750', 'Small Batch Botanical Gin 750ml', 'Handcrafted copper-pot distilled botanical dry gin 45% ABV', 'finished_good', 'pcs', 30.00, 'active')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`),
  `type` = VALUES(`type`),
  `unit` = VALUES(`unit`),
  `reorder_level` = VALUES(`reorder_level`),
  `status` = VALUES(`status`);

-- -----------------------------------------------------------------------------
-- 5. Recipes / Bill of Materials (BOM)
-- -----------------------------------------------------------------------------
INSERT INTO `recipes` (`recipe_id`, `finished_item_id`, `raw_item_id`, `quantity_required`, `notes`) VALUES
(1, 8, 1, 0.7500, '0.75 L Bulk Aged Whiskey Base per 750ml bottle'),
(2, 8, 5, 1.0000, '1 Flint Glass Bottle 750ml per finished bottle'),
(3, 8, 6, 1.0000, '1 Wooden Cork Closure per finished bottle'),
(4, 9, 2, 0.7500, '0.75 L Vodka Spirit Base per 750ml bottle'),
(5, 9, 5, 1.0000, '1 Flint Glass Bottle 750ml per finished bottle'),
(6, 9, 6, 1.0000, '1 Wooden Cork Closure per finished bottle'),
(7, 10, 2, 0.7500, '0.75 L Neutral Spirit Base per 750ml bottle'),
(8, 10, 4, 0.0500, '0.05 kg Gin Botanicals per 750ml bottle'),
(9, 10, 5, 1.0000, '1 Flint Glass Bottle 750ml per finished bottle'),
(10, 10, 6, 1.0000, '1 Wooden Cork Closure per finished bottle')
ON DUPLICATE KEY UPDATE
  `quantity_required` = VALUES(`quantity_required`),
  `notes` = VALUES(`notes`);

-- -----------------------------------------------------------------------------
-- 6. Stock (Inventory per Warehouse)
-- -----------------------------------------------------------------------------
INSERT INTO `stock` (`item_id`, `warehouse_id`, `qty_on_hand`, `reorder_level`) VALUES
(1, 1, 1500.00, 200.00),
(2, 1, 1200.00, 200.00),
(3, 1, 800.00, 150.00),
(4, 1, 100.00, 20.00),
(5, 1, 3000.00, 500.00),
(6, 1, 2800.00, 500.00),
(7, 1, 350.00, 50.00),
(8, 1, 250.00, 50.00),
(9, 1, 180.00, 40.00),
(10, 1, 120.00, 30.00),
-- Bonded Warehouse (Manila)
(1, 2, 800.00, 200.00),
(2, 2, 600.00, 200.00),
(5, 2, 1200.00, 300.00),
-- Bottling Area (Bulacan)
(5, 3, 2000.00, 500.00),
(6, 3, 2000.00, 500.00),
(8, 3, 500.00, 100.00),
-- Delivery Center (Cavite)
(8, 4, 150.00, 50.00),
(9, 4, 100.00, 40.00),
(10, 4, 80.00, 30.00)
ON DUPLICATE KEY UPDATE
  `qty_on_hand` = VALUES(`qty_on_hand`),
  `reorder_level` = VALUES(`reorder_level`);

SET FOREIGN_KEY_CHECKS = 1;
