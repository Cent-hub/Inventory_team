<?php
/*
 * Raw Materials inventory page. Configures the page labels and loads the shared
 * inventory category view for raw materials.
 */

$categoryConfig = [
    'page_title'         => 'Raw Materials — InventoryTeam',
    'active_page'        => 'raw_materials',
    'item_type'          => 'raw_material',
    'category_name'      => 'Raw Materials',
    'page_heading'       => 'Raw Materials Inventory',
    'page_subheading'    => 'Track raw material stock levels and reorder alerts',
    'stat_card_class'    => 'stat-card stat-gold',
    'stat_icon'          => 'polygon',
    'volume_label'       => 'Total Stock',
    'alert_meta_active'  => 'Items running low on stock',
    'alert_meta_idle'    => 'All stock levels are good',
    'card_title'         => 'Raw Materials List',
    'card_desc'          => 'Current stock levels and latest movements for raw materials',
    'table_id'           => 'rawMaterialsTable',
    'search_id'          => 'rawSearch',
    'search_placeholder' => 'Filter material name or code...',
    'code_col_header'    => 'Material Code',
    'name_col_header'    => 'Material Name',
    'source_col_header'  => 'Latest PO / Source Ref',
    'ref_prefix'         => 'IN #',
    'empty_message'      => 'No raw material records found in database.'
];

require __DIR__ . '/_inventory_category_view.php';
