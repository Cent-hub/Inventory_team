-- =============================================================================
-- Database Seed Data: team_inventory
-- =============================================================================

USE `team_inventory`;

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Seed Users (Admin and Service Accounts for the 3 teams)
-- Note: api_token stores SHA-256 hashes for defense-in-depth:
-- User 1: 'adm_live_sec_991823'  -> SHA-256: d2c89d5e330ef2178ce2c7f275963f2b8563030d0e399ecac4d875107fc20cbb
-- User 2: 'proc_live_sec_884121' -> SHA-256: 8cacfcb41fb35a319472555807be7da7ad2cf0079f2e1ad05e0e85c723dc36f0
-- User 3: 'prod_live_sec_773910' -> SHA-256: 13c169097c8071234a9584fdbe0ed3d41c37f4215d9b3c4cb91760800ea1e786
-- User 4: 'sales_live_sec_662845'-> SHA-256: 1394786dea8f134e70c77e84497bbbd48125e8b5bae7855ccb2d4f8ef5b0f74a
-- User 5: 'proc2_live_sec_112233'-> SHA-256: f41a58e788485883635f7ace4f8dbe67aa1802a638ab4dcb0628ed03f9124be9
-- User 6: 'sales2_live_sec_445566'-> SHA-256: 7af8a22ee365f996796e1853ea5d69c97ee006d7987c9ca94e04572d4e58366a
-- Password for all seeded users is: 'admin123'
INSERT INTO `users` (`user_id`, `name`, `email`, `password`, `role`, `warehouse_id`, `api_token`, `status`) VALUES
(1, 'System Super Admin', 'admin@inventory.local', '$2y$10$UH5MIbyhTYTOEiTyOPpgyu54KbT/eNL9SVLktkQFmYxNhPW8/sN8K', 'super_admin', 1, 'd2c89d5e330ef2178ce2c7f275963f2b8563030d0e399ecac4d875107fc20cbb', 'active'),
(2, 'Procurement Service API', 'procurement@inventory.local', '$2y$10$UH5MIbyhTYTOEiTyOPpgyu54KbT/eNL9SVLktkQFmYxNhPW8/sN8K', 'admin', 1, '8cacfcb41fb35a319472555807be7da7ad2cf0079f2e1ad05e0e85c723dc36f0', 'active'),
(3, 'Production Service API', 'production@inventory.local', '$2y$10$UH5MIbyhTYTOEiTyOPpgyu54KbT/eNL9SVLktkQFmYxNhPW8/sN8K', 'admin', 3, '13c169097c8071234a9584fdbe0ed3d41c37f4215d9b3c4cb91760800ea1e786', 'active'),
(4, 'Sales Service API', 'sales@inventory.local', '$2y$10$UH5MIbyhTYTOEiTyOPpgyu54KbT/eNL9SVLktkQFmYxNhPW8/sN8K', 'admin', 1, '1394786dea8f134e70c77e84497bbbd48125e8b5bae7855ccb2d4f8ef5b0f74a', 'active'),
(5, 'Procurement Secondary Admin', 'proc2@inventory.local', '$2y$10$UH5MIbyhTYTOEiTyOPpgyu54KbT/eNL9SVLktkQFmYxNhPW8/sN8K', 'admin', 2, 'f41a58e788485883635f7ace4f8dbe67aa1802a638ab4dcb0628ed03f9124be9', 'active'),
(6, 'Sales Secondary Admin', 'sales2@inventory.local', '$2y$10$UH5MIbyhTYTOEiTyOPpgyu54KbT/eNL9SVLktkQFmYxNhPW8/sN8K', 'admin', 2, '7af8a22ee365f996796e1853ea5d69c97ee006d7987c9ca94e04572d4e58366a', 'active')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `password` = VALUES(`password`), `role` = VALUES(`role`), `warehouse_id` = VALUES(`warehouse_id`), `api_token` = VALUES(`api_token`);

-- 2. Seed Warehouses
INSERT INTO `warehouses` (`warehouse_id`, `warehouse_code`, `warehouse_name`, `location`, `description`, `status`) VALUES
(1, 'WH-MAIN', 'Main Warehouse (Laguna)', 'Laguna, Philippines', 'Central storage, raw ingredient staging, and distribution hub in Laguna', 'active'),
(2, 'WH-BOND', 'Bonded Warehouse (Manila)', 'Port Area, Manila, Philippines', 'Customs-bonded warehouse facility for imported bulk spirits and tax-exempt storage in Manila', 'active'),
(3, 'WH-BOTT', 'Bottling Area (Bulacan)', 'Bulacan, Philippines', 'Bottling, blending, and finished packaging facility in Bulacan', 'active')
ON DUPLICATE KEY UPDATE `warehouse_name` = VALUES(`warehouse_name`), `warehouse_code` = VALUES(`warehouse_code`), `location` = VALUES(`location`), `description` = VALUES(`description`), `status` = VALUES(`status`);

-- 3. Seed Categories
INSERT INTO `categories` (`category_id`, `category_code`, `category_name`, `description`, `status`) VALUES
(1, 'CAT-RAW', 'Raw Materials', 'Raw components and materials used in the manufacturing process', 'active'),
(2, 'CAT-FG', 'Finished Goods', 'Completed products ready for customer delivery and sales distribution', 'active')
ON DUPLICATE KEY UPDATE `category_name` = VALUES(`category_name`);

-- 4. Seed Items (Raw Material and Finished Good)
INSERT INTO `items` (`item_id`, `item_code`, `item_name`, `description`, `item_type`, `category_id`, `unit`, `default_reorder_level`, `status`) VALUES
(1, 'RM-STEEL-01', 'Cold Rolled Steel Sheet', 'Standard 2mm cold-rolled steel sheet for production', 'raw_material', 1, 'kg', 50.000, 'active'),
(2, 'FG-WIDGET-01', 'Industrial Helical Gear', 'High-precision hardened steel helical gear for mechanical drives', 'finished_good', 2, 'pcs', 10.000, 'active')
ON DUPLICATE KEY UPDATE `item_name` = VALUES(`item_name`);

SET FOREIGN_KEY_CHECKS = 1;
