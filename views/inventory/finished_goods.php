<?php
/*
 * Finished Goods inventory page. Configures the page labels and loads the shared
 * inventory category view for finished products.
 */

$categoryConfig = [
    'page_title'         => 'Finished Goods — InventoryTeam',
    'active_page'        => 'finished_goods',
    'item_type'          => 'finished_good',
    'category_name'      => 'Finished Goods',
    'page_heading'       => 'Finished Goods Inventory',
    'page_subheading'    => 'Track finished product stock levels and availability',
    'stat_card_class'    => 'stat-card',
    'stat_icon'          => 'package',
    'volume_label'       => 'Total Stock',
    'alert_meta_active'  => 'Items running low on stock',
    'alert_meta_idle'    => 'All stock levels are good',
    'card_title'         => 'Finished Goods List',
    'card_desc'          => 'Current stock levels and latest batches for finished goods',
    'table_id'           => 'finishedGoodsTable',
    'search_id'          => 'fgSearch',
    'search_placeholder' => 'Filter product name or code...',
    'code_col_header'    => 'Product Code',
    'name_col_header'    => 'Product Name',
    'source_col_header'  => 'Latest Batch Ref',
    'ref_prefix'         => 'FG #',
    'empty_message'      => 'No finished goods records found in database.'
];

require __DIR__ . '/_inventory_category_view.php';
