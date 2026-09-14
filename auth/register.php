<?php
/**
 * Registration UI Disabled
 * Public self-registration is closed.
 * User and operator accounts must be provisioned directly by a System Super Administrator.
 */

require_once __DIR__ . '/../controllers/AuthController.php';

$auth = new AuthController();
header('Location: ' . $auth->getLoginRedirectUrl());
exit;
