<?php

/**
 * PHP-IRCd - An IRC server in PHP
 *
 * Originally written by Daniel Danopia (2008)
 * Modernized 2025
 */

// Check if executed as CLI or via web server
$isCliMode = (php_sapi_name() === 'cli');

// Initialize autoloader
require_once __DIR__ . '/vendor/autoload.php';

use PhpIrcd\Core\Config;
use PhpIrcd\Core\Server;
use PhpIrcd\Utils\Logger;

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('UTC');

// Load configuration
$configPath = __DIR__ . '/config.php';

if (!file_exists($configPath)) {
    die("Configuration file not found: {$configPath}\n");
}

$config = require $configPath;

// Initialize logger
$logger = new Logger($config['log_level'] ?? 'info');

// Create and start server
$server = new Server($config, false);

$server->start();

if (!$isCliMode) {
    // Einfache API-Router-Logik
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    if ($requestMethod === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    if (strpos($requestUri, '/api/') === 0) {
        $apiPath = substr($requestUri, 5); // nach /api/
        require_once __DIR__ . '/src/Web/api_router.php';
        handle_api_request($apiPath, $requestMethod, $server, $config);
        exit;
    } else {
        // No web interface available anymore
        header('Content-Type: text/plain');
        echo "PHP-IRCd: Web interface removed. Please use the API at /api/.";
        exit;
    }
}
