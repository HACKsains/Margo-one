<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · ROOT ENTRYPOINT
 * ==============================================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/gateway');
    exit;
}

header('Location: ' . BASE_URL . '/login');
exit;
