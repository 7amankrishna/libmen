<?php
/**
 * Common bootstrap: session + helpers + DB. Include this first on every page.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');   // never leak errors to the browser on shared hosting
ini_set('log_errors', '1');

date_default_timezone_set('Asia/Kolkata');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
