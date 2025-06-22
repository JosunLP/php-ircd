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
use PhpIrcd\Web\WebInterface;

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
