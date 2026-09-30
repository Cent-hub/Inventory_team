<?php
/**
 * Legacy Route Redirect: Stock In Receiving Ledger
 * Redirects unconditionally to the unified Inbound & Outbound Hub (Inbound tab).
 */
header('Location: ../inbound_outbound/index.php?tab=inbound');
exit;
