<?php
/**
 * View: Raw Materials (Procurement Inbound Monitoring)
 * InventoryTeam — Liquor Business Inventory Management System
 */

$categoryConfig = [
    'page_title'         => 'Raw Materials — InventoryTeam',
    'active_page'        => 'raw_materials',
    'item_type'          => 'raw_material',
    'category_name'      => 'Raw Materials',
    'page_heading'       => 'Raw Materials Inventory',
    'page_subheading'    => 'Track raw material stock balances, supplier inputs, and reorder thresholds',
    'stat_card_class'    => 'stat-card stat-gold',
    'stat_icon'          => 'polygon',
    'volume_label'       => 'Total Raw Volume',
    'alert_meta_active'  => 'Send PO requisition to Procurement',
    'alert_meta_idle'    => 'Sufficient raw stock on hand',
    'card_title'         => 'Procurement Raw Materials Ledger',
    'card_desc'          => 'Materials received and ready to be issued to the Production distillation / packaging line',
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
