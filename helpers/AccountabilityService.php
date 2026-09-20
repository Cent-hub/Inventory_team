<?php
/**
 * AccountabilityService.php
 * Centralized audit logging service for StockPilot Inventory ERP.
 * Records every user, team, and API transaction affecting inventory.
 */

require_once __DIR__ . '/../config/database.php';

class AccountabilityService {
    private static ?PDO $pdo = null;

    private static function getPdo(): PDO {
        if (self::$pdo === null) {
            self::$pdo = Database::getConnection();
        }
        return self::$pdo;
    }

    /**
     * Records an accountability audit log entry
     *
     * @param array $params [
     *   'user_id'                 => int|null,
     *   'user_name'               => string|null,
     *   'team'                    => string|null,
     *   'action_type'             => string, (e.g. 'STOCK_IN', 'STOCK_OUT', 'TRANSFER_INITIATED', 'TRANSFER_RECEIVED', etc.)
     *   'channel'                 => string, ('UI'|'API')
     *   'item_id'                 => int|null,
     *   'quantity'                => float|null,
     *   'warehouse_id'            => int,
     *   'destination_warehouse_id'=> int|null,
     *   'reference_number'        => string|null,
     *   'notes'                   => string|null
     * ]
     * @return int Inserted log ID
     */
    public static function log(array $params): int {
        $pdo = self::getPdo();

        $userId      = !empty($params['user_id']) ? (int)$params['user_id'] : null;
        $userName    = trim($params['user_name'] ?? '');
        $userRole    = 'admin';
        $team        = trim($params['team'] ?? '');
        $actionType  = strtoupper(trim($params['action_type'] ?? 'GENERAL'));
        $channel     = in_array(strtoupper(trim($params['channel'] ?? 'UI')), ['UI', 'API'], true) ? strtoupper(trim($params['channel'] ?? 'UI')) : 'UI';
        $itemId      = !empty($params['item_id']) ? (int)$params['item_id'] : null;
        $itemCode    = null;
        $itemName    = null;
        $unit        = null;
        $quantity    = isset($params['quantity']) ? (float)$params['quantity'] : 0.000;
        $warehouseId = (int)($params['warehouse_id'] ?? 1);
        $destWhId    = !empty($params['destination_warehouse_id']) ? (int)$params['destination_warehouse_id'] : null;
        $refNo       = trim($params['reference_number'] ?? '');
        $notes       = trim($params['notes'] ?? '');

        // Resolve user metadata if userId is given
        if ($userId > 0) {
            $stmtU = $pdo->prepare("SELECT name, role, team, email FROM users WHERE user_id = ? LIMIT 1");
            $stmtU->execute([$userId]);
            $u = $stmtU->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                if (empty($userName)) {
                    $userName = $u['name'];
                }
                $userRole = $u['role'] ?? 'admin';
                if (empty($team)) {
                    $team = !empty($u['team']) ? ucfirst(strtolower($u['team'])) : self::inferTeamFromEmail($u['email']);
                }
            }
        }

        if (empty($userName)) {
            $userName = 'System Service';
        }

        if (empty($team)) {
            $team = self::inferTeamFromAction($actionType);
        }

        // Resolve item snapshot if itemId is given
        if ($itemId > 0) {
            $stmtI = $pdo->prepare("SELECT item_code, item_name, unit FROM items WHERE item_id = ? LIMIT 1");
            $stmtI->execute([$itemId]);
            $item = $stmtI->fetch(PDO::FETCH_ASSOC);
            if ($item) {
                $itemCode = $item['item_code'];
                $itemName = $item['item_name'];
                $unit     = $item['unit'];
            }
        }

        $stmtIns = $pdo->prepare("
            INSERT INTO accountability_logs (
                created_at, user_id, user_name, user_role, team,
                action_type, channel, item_id, item_code, item_name,
                quantity, unit, warehouse_id, destination_warehouse_id,
                reference_number, notes
            ) VALUES (
                NOW(), ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?
            )
        ");

        $stmtIns->execute([
            $userId,
            $userName,
            $userRole,
            $team,
            $actionType,
            $channel,
            $itemId,
            $itemCode,
            $itemName,
            $quantity,
            $unit,
            $warehouseId,
            $destWhId,
            $refNo ?: null,
            $notes ?: null
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Helper to infer team from user email
     */
    private static function inferTeamFromEmail(?string $email): string {
        $e = strtolower(trim((string)$email));
        if (str_contains($e, 'procure')) return 'Procurement';
        if (str_contains($e, 'prod') || str_contains($e, 'brew') || str_contains($e, 'distill')) return 'Production';
        if (str_contains($e, 'sale') || str_contains($e, 'order')) return 'Sales';
        return 'Inventory';
    }

    /**
     * Helper to infer team from action
     */
    private static function inferTeamFromAction(string $action): string {
        if (str_contains($action, 'PURCHASE') || str_contains($action, 'PROCURE')) return 'Procurement';
        if (str_contains($action, 'PROD') || str_contains($action, 'MATERIAL_REQUEST')) return 'Production';
        if (str_contains($action, 'SALE') || str_contains($action, 'DISPATCH')) return 'Sales';
        return 'Inventory';
    }

    /**
     * Fetch logs with strict warehouse scoping and optional filtering
     */
    public static function getLogs(int $warehouseId, array $filters = []): array {
        $pdo = self::getPdo();

        $sql = "
            SELECT 
                al.log_id,
                al.created_at,
                al.user_id,
                al.user_name,
                al.user_role,
                al.team,
                al.action_type,
                al.channel,
                al.item_id,
                al.item_code,
                al.item_name,
                al.quantity,
                al.unit,
                al.warehouse_id,
                al.destination_warehouse_id,
                al.reference_number,
                al.notes,
                w.warehouse_code,
                w.warehouse_name,
                dw.warehouse_code AS dest_code,
                dw.warehouse_name AS dest_name
            FROM accountability_logs al
            JOIN warehouses w ON al.warehouse_id = w.warehouse_id
            LEFT JOIN warehouses dw ON al.destination_warehouse_id = dw.warehouse_id
            WHERE 1=1
        ";
        $params = [];

        // Warehouse scoping: logged-in user's assigned warehouse
        if ($warehouseId > 0) {
            $sql .= " AND (al.warehouse_id = ? OR al.destination_warehouse_id = ?)";
            $params[] = $warehouseId;
            $params[] = $warehouseId;
        }

        // Team filter
        if (!empty($filters['team']) && $filters['team'] !== 'all') {
            $sql .= " AND al.team = ?";
            $params[] = $filters['team'];
        }

        // Action filter
        if (!empty($filters['action']) && $filters['action'] !== 'all') {
            $sql .= " AND al.action_type = ?";
            $params[] = $filters['action'];
        }

        // Search filter
        if (!empty($filters['search'])) {
            $s = "%" . trim($filters['search']) . "%";
            $sql .= " AND (
                al.user_name LIKE ? 
                OR al.item_name LIKE ? 
                OR al.item_code LIKE ? 
                OR al.reference_number LIKE ? 
                OR al.notes LIKE ?
                OR w.warehouse_name LIKE ?
            )";
            $params = array_merge($params, [$s, $s, $s, $s, $s, $s]);
        }

        // Date range
        if (!empty($filters['start_date'])) {
            $sql .= " AND DATE(al.created_at) >= ?";
            $params[] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $sql .= " AND DATE(al.created_at) <= ?";
            $params[] = $filters['end_date'];
        }

        $sql .= " ORDER BY al.created_at DESC, al.log_id DESC";

        if (!empty($filters['limit'])) {
            $limit = (int)$filters['limit'];
            $offset = (int)($filters['offset'] ?? 0);
            $sql .= " LIMIT {$offset}, {$limit}";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Compute live KPI counts for the warehouse
     */
    public static function getKpis(int $warehouseId): array {
        $pdo = self::getPdo();

        $where = "1=1";
        $params = [];
        if ($warehouseId > 0) {
            $where = "(warehouse_id = ? OR destination_warehouse_id = ?)";
            $params = [$warehouseId, $warehouseId];
        }

        $sql = "
            SELECT 
                COUNT(*) AS total_events,
                SUM(CASE WHEN team = 'Procurement' THEN 1 ELSE 0 END) AS procurement_ops,
                SUM(CASE WHEN team = 'Production' THEN 1 ELSE 0 END) AS production_ops,
                SUM(CASE WHEN team = 'Sales' THEN 1 ELSE 0 END) AS sales_ops,
                SUM(CASE WHEN team = 'Inventory' THEN 1 ELSE 0 END) AS inventory_ops
            FROM accountability_logs
            WHERE {$where}
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total_events'    => (int)($row['total_events'] ?? 0),
            'procurement_ops' => (int)($row['procurement_ops'] ?? 0),
            'production_ops'  => (int)($row['production_ops'] ?? 0),
            'sales_ops'       => (int)($row['sales_ops'] ?? 0),
            'inventory_ops'   => (int)($row['inventory_ops'] ?? 0)
        ];
    }

    /**
     * Return initials for avatar (e.g. "JD")
     */
    public static function getUserInitials(?string $name): string {
        $n = trim((string)$name);
        if ($n === '') return 'SY';
        $words = preg_split('/\s+/', $n);
        $initials = '';
        foreach ($words as $w) {
            $initials .= strtoupper(substr($w, 0, 1));
            if (strlen($initials) >= 2) break;
        }
        return $initials ?: 'US';
    }

    /**
     * Format action badge HTML
     */
    public static function formatActionBadge(string $actionType, string $channel = 'UI'): string {
        $action = strtoupper(trim($actionType));
        $badgeClass = 'adjustment';
        $label = ucwords(strtolower(str_replace('_', ' ', $action)));
        $iconSvg = '';

        switch ($action) {
            case 'STOCK_IN':
                $badgeClass = 'inbound';
                $label = 'Stock In';
                $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>';
                break;
            case 'STOCK_IN_CANCEL':
                $badgeClass = 'issue';
                $label = 'Stock In Cancelled';
                $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
                break;
            case 'STOCK_OUT':
                $badgeClass = 'outbound';
                $label = 'Stock Out';
                $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>';
                break;
            case 'STOCK_OUT_CANCEL':
                $badgeClass = 'issue';
                $label = 'Stock Out Cancelled';
                $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
                break;
            case 'TRANSFER_INITIATED':
                $badgeClass = 'transfer';
                $label = 'Transfer Dispatched';
                $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/></svg>';
                break;
            case 'TRANSFER_RECEIVED':
                $badgeClass = 'transfer';
                $label = 'Transfer Received';
                $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
                break;
            case 'TRANSFER_CANCELLED':
                $badgeClass = 'issue';
                $label = 'Transfer Cancelled';
                $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
                break;
            case 'ADJUSTMENT_REQUESTED':
                $badgeClass = 'adjustment';
                $label = 'Adjustment Pending';
                $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>';
                break;
            case 'ADJUSTMENT_APPROVED':
                $badgeClass = 'adjustment';
                $label = 'Adjustment Approved';
                $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
                break;
            case 'ADJUSTMENT_REJECTED':
                $badgeClass = 'issue';
                $label = 'Adjustment Rejected';
                $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
                break;
            case 'ADJUSTMENT_CANCELLED':
                $badgeClass = 'issue';
                $label = 'Adjustment Cancelled';
                $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
                break;
            case 'BAD_PRODUCT':
                $badgeClass = 'issue';
                $label = 'Damaged / Bad Stock';
                $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
                break;
            case 'BAD_PRODUCT_CANCEL':
                $badgeClass = 'issue';
                $label = 'Bad Stock Cancelled';
                $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
                break;
            case 'ITEM_CREATED':
                $badgeClass = 'inbound';
                $label = 'Item Master Created';
                $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>';
                break;
            default:
                $badgeClass = 'adjustment';
                $label = ucwords(strtolower(str_replace('_', ' ', $action)));
                $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/></svg>';
                break;
        }

        $apiPill = '';
        if (strtoupper($channel) === 'API') {
            $apiPill = ' <span style="background: #E2E8F0; color: #334155; font-size: 10px; padding: 1px 5px; border-radius: 4px; font-weight: 700; margin-left: 4px; letter-spacing: 0.5px;">API</span>';
        }

        return '<span class="badge-action ' . htmlspecialchars($badgeClass) . '">' . $iconSvg . ' ' . htmlspecialchars($label) . $apiPill . '</span>';
    }

    /**
     * Format team badge HTML
     */
    public static function formatTeamBadge(?string $team): string {
        $t = trim((string)$team);
        $cls = 'inventory';
        $style = '';
        switch (strtolower($t)) {
            case 'procurement':
                $cls = 'procurement';
                break;
            case 'production':
                $cls = 'production';
                break;
            case 'sales':
                $cls = 'sales';
                break;
            case 'administration':
            case 'admin':
                $cls = 'admin';
                $style = 'style="background: #EDE9FE; color: #5B21B6; border: 1px solid #DDD6FE;"';
                break;
            default:
                $cls = 'inventory';
                break;
        }
        return '<span class="badge-team ' . htmlspecialchars($cls) . '" ' . $style . '>' . htmlspecialchars($t ?: 'Inventory') . '</span>';
    }

    /**
     * Stream CSV export of filtered logs
     */
    public static function exportCsv(int $warehouseId, array $filters = []): void {
        // Remove limit to export all matching records
        unset($filters['limit'], $filters['offset']);
        $logs = self::getLogs($warehouseId, $filters);

        $filename = 'accountability_log_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // Add UTF-8 BOM for Excel compatibility
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($out, [
            'Log ID',
            'Date & Time',
            'User Name',
            'User Role',
            'Team',
            'Channel',
            'Action Type',
            'Item Code',
            'Item Name',
            'Quantity',
            'Unit',
            'Origin Warehouse',
            'Destination Warehouse',
            'Reference #',
            'Notes'
        ]);

        foreach ($logs as $row) {
            fputcsv($out, [
                $row['log_id'],
                $row['created_at'],
                $row['user_name'],
                $row['user_role'],
                $row['team'],
                $row['channel'],
                $row['action_type'],
                $row['item_code'] ?? '',
                $row['item_name'] ?? '',
                $row['quantity'] != 0 ? $row['quantity'] : '',
                $row['unit'] ?? '',
                $row['warehouse_name'] ?? '',
                $row['dest_name'] ?? '',
                $row['reference_number'] ?? '',
                $row['notes'] ?? ''
            ]);
        }

        fclose($out);
        exit;
    }
}
