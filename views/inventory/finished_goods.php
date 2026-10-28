<?php
/**
 * View: Finished Goods (Production Output Monitoring)
 * InventoryTeam — Liquor Business Inventory Management System
 */

$categoryConfig = [
    'page_title'         => 'Finished Goods — InventoryTeam',
    'active_page'        => 'finished_goods',
    'item_type'          => 'finished_good',
    'category_name'      => 'Finished Goods',
    'page_heading'       => 'Finished Goods Inventory',
    'page_subheading'    => 'Monitor finished product stock levels, batch readiness, and distribution availability',
    'stat_card_class'    => 'stat-card',
    'stat_icon'          => 'package',
    'volume_label'       => 'Total Units in Stock',
    'alert_meta_active'  => 'Alert production to schedule bottling batch',
    'alert_meta_idle'    => 'Finished stock within safety margin',
    'card_title'         => 'Production Finished Goods Ledger',
    'card_desc'          => 'Goods produced and ready for quality control, warehouse staging, or sales distribution',
    'table_id'           => 'finishedGoodsTable',
    'search_id'          => 'fgSearch',
    'search_placeholder' => 'Filter product name or code...',
    'code_col_header'    => 'Product Code',
    'name_col_header'    => 'Product Name',
    'source_col_header'  => 'Latest Production / Batch Ref',
    'ref_prefix'         => 'FG #',
    'empty_message'      => 'No finished goods records found in database.'
];

require __DIR__ . '/_inventory_category_view.php';
