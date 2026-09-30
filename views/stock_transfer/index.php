<?php
/**
 * Legacy Route Redirect: Stock Transfer Ledger
 * Redirects unconditionally to the unified Stock Operations Hub (Transfer tab).
 */
header('Location: ../stock_operations/index.php?tab=transfer');
exit;
