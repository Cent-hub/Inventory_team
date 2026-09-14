<?php
/**
 * API: Authentication - Register Endpoint (Disabled)
 * Public registration is disabled. Operator and administrator account provisioning
 * is restricted to System Super Administrators.
 */

header('Content-Type: application/json; charset=UTF-8');
http_response_code(403);
echo json_encode([
    'success' => false,
    'error'   => 'Forbidden',
    'message' => 'Public registration is disabled. User accounts must be created by a System Super Administrator.'
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
