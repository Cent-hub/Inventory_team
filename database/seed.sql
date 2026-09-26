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
(3, 'WH-BOTT', 'Bottling Area (Bulacan)', 'Bulacan, Philippines', 'Bottling, blending, and finished packaging facility in Bulacan', 'active'),
(4, 'WH-DELV', 'Delivery & Distribution Center (Cavite)', 'Cavite, Philippines', 'Dispatch, logistics staging, and customer delivery fulfillment center in Cavite', 'active')
ON DUPLICATE KEY UPDATE `warehouse_name` = VALUES(`warehouse_name`), `warehouse_code` = VALUES(`warehouse_code`), `location` = VALUES(`location`), `description` = VALUES(`description`), `status` = VALUES(`status`);

-- 3. Seed Categories
INSERT INTO `categories` (`category_id`, `category_code`, `category_name`, `description`, `status`) VALUES
(1, 'CAT-RAW', 'Raw Materials', 'Raw components and materials used in the manufacturing process', 'active'),
(2, 'CAT-FG', 'Finished Goods', 'Completed products ready for customer delivery and sales distribution', 'active')
ON DUPLICATE KEY UPDATE `category_name` = VALUES(`category_name`);

-- 4. Seed Items (Optional Sample Items - Commented out for clean manual testing)
-- To reload sample distillery items, uncomment the INSERT statement below:
/*
INSERT INTO `items` (`item_id`, `item_code`, `item_name`, `description`, `item_type`, `category_id`, `unit`, `default_reorder_level`, `status`) VALUES
(1, 'RM-WHISKEY-BASE', 'Bulk Aged Whiskey Base', 'Aged oak-matured distilled whiskey base spirit (65% ABV)', 'raw_material', 1, 'liter', 200.000, 'active'),
(2, 'RM-VODKA-BASE', 'Neutral Vodka Grain Spirit', 'High-purity neutral grain spirit 96% ABV for vodka blending', 'raw_material', 1, 'liter', 200.000, 'active'),
(3, 'RM-RUM-BASE', 'Sugarcane Rum Base', 'Aged dark molasses rum distillate for blending and aging', 'raw_material', 1, 'liter', 150.000, 'active'),
(4, 'RM-LAMBANOG-BASE', 'Distilled Coconut Spirit Base', 'Pure traditional coconut nectar distillate base', 'raw_material', 1, 'liter', 150.000, 'active'),
(5, 'RM-GIN-BOTANICALS', 'Gin Botanicals Blend', 'Blend of Tuscan juniper, coriander seed, angelica, and citrus peel', 'raw_material', 1, 'kg', 20.000, 'active'),
(6, 'RM-BOTTLE-750', 'Flint Glass Bottles 750ml', 'Heavy-base flint glass spirit bottles for 750ml bottling line', 'raw_material', 1, 'pcs', 500.000, 'active'),
(7, 'RM-BOTTLE-1000', 'Standard Glass Bottles 1L', 'Clear glass 1.0L standard spirits bottles', 'raw_material', 1, 'pcs', 300.000, 'active'),
(8, 'RM-BOTTLE-500', 'Flask Glass Bottles 500ml', 'Pocket-style glass flask bottles for Lambanog line', 'raw_material', 1, 'pcs', 300.000, 'active'),
(9, 'RM-CORK-CAPS', 'Wooden Top Cork Closures', 'Synthetic micro-agglomerated cork with stained natural wood head', 'raw_material', 1, 'pcs', 500.000, 'active'),
(10, 'RM-SCREW-CAPS', 'Aluminum Screw Caps 28mm', 'Tamper-evident aluminum ROPP caps for high-speed line', 'raw_material', 1, 'pcs', 500.000, 'active'),
(11, 'RM-LABEL-WHISKEY', 'Front & Back Labels - House Whiskey', 'Embossed gold-foil adhesive labels on waterproof vinyl', 'raw_material', 1, 'pcs', 500.000, 'active'),
(12, 'RM-LABEL-GIN', 'Waterproof Labels - House Gin', 'Matte polypropylene labels with botanical illustrations', 'raw_material', 1, 'pcs', 300.000, 'active'),
(13, 'RM-LABEL-LAMB', 'Traditional Kraft Labels - Lambanog', 'Textured kraft paper adhesive labels', 'raw_material', 1, 'pcs', 300.000, 'active'),
(14, 'RM-CARTON-12', '12-Bottle Shipping Cartons', 'Double-wall corrugated shipping boxes with bottle dividers', 'raw_material', 1, 'box', 50.000, 'active'),
(15, 'RM-GIFT-BOX', 'Luxury Gift Presentation Packaging', 'Rigid cardboard embossed magnetic-clasp gift boxes', 'raw_material', 1, 'box', 50.000, 'active'),
(16, 'FG-WHISKEY-750', 'House Reserve Whiskey 750ml', 'Signature 43% ABV oak-aged blended whiskey in 750ml glass bottle', 'finished_good', 2, 'pcs', 50.000, 'active'),
(17, 'FG-GIN-1000', 'Craft Dry Gin 1L', 'Artisanal vapor-infused botanical dry gin in 1L bottle (45% ABV)', 'finished_good', 2, 'pcs', 40.000, 'active'),
(18, 'FG-LAMBANOG-500', 'Traditional Lambanog 500ml', 'Authentic coconut spirits 40% ABV in 500ml flask presentation', 'finished_good', 2, 'pcs', 40.000, 'active'),
(19, 'FG-PARTY-PACK', 'Distillery Sampler Party Pack', 'Pack of 4 assorted 250ml tasting bottles (Whiskey, Gin, Rum, Lambanog)', 'finished_good', 2, 'box', 20.000, 'active'),
(20, 'FG-GIFT-SET', 'Master Distillers Gift Set', 'House Reserve Whiskey 750ml bottle with 2 crystal rocks glasses', 'finished_good', 2, 'box', 15.000, 'active')
ON DUPLICATE KEY UPDATE `item_name` = VALUES(`item_name`), `item_code` = VALUES(`item_code`), `unit` = VALUES(`unit`), `default_reorder_level` = VALUES(`default_reorder_level`);
*/

SET FOREIGN_KEY_CHECKS = 1;
