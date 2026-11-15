<?php
/**
 * Team & Role Helper
 * Normalizes user department/team assignments across ERP modules.
 */

/**
 * Resolve user team with explicit normalization and heuristic fallback
 */
function resolveUserTeam(?string $team, ?string $email = ''): string {
    $cleanTeam = trim((string)$team);
    if ($cleanTeam !== '') {
        if (strcasecmp($cleanTeam, 'administration') === 0 || strcasecmp($cleanTeam, 'admin') === 0) {
            return 'Administration';
        }
        if (strcasecmp($cleanTeam, 'inventory') === 0) {
            return 'Inventory';
        }
        if (strcasecmp($cleanTeam, 'procurement') === 0) {
            return 'Procurement';
        }
        if (strcasecmp($cleanTeam, 'production') === 0) {
            return 'Production';
        }
        if (strcasecmp($cleanTeam, 'sales') === 0) {
            return 'Sales';
        }
        if (strcasecmp($cleanTeam, 'hr') === 0) {
            return 'HR';
        }
        if (strcasecmp($cleanTeam, 'finance') === 0) {
            return 'Finance';
        }
    }
    $emailLower = strtolower(trim((string)$email));
    if (str_contains($emailLower, 'procure')) {
        return 'Procurement';
    } elseif (str_contains($emailLower, 'prod') || str_contains($emailLower, 'brew') || str_contains($emailLower, 'distill')) {
        return 'Production';
    } elseif (str_contains($emailLower, 'sale') || str_contains($emailLower, 'order')) {
        return 'Sales';
    }
    return 'Inventory';
}
