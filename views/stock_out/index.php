<?php
/**
 * Legacy Route Redirect: Stock Out Dispatch Ledger
 * Redirects unconditionally to the unified Inbound & Outbound Hub (Outbound tab).
 */
header('Location: ../inbound_outbound/index.php?tab=outbound');
exit;