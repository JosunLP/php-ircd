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

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $logger = new Logger('error.log', 0, true);
    $logger->error("PHP error ($errno): $errstr in $errfile on line $errline");
    
    if (in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        die("A critical error has occurred. See error.log for details.");
    }
    
    return false; // Continue with default error handling
});

// Load configuration
$configPath = __DIR__ . '/config.php';
$config = new Config($configPath);

// Initialize logger
$logger = new Logger(
    $config->get('log_file', 'ircd.log'),
    $config->get('log_level', 1),
    true
);

if ($isCliMode) {
    // If started as CLI, run the server daemon
    $logger->info("PHP-IRCd starting in CLI mode...");
    $logger->info("Configuration loaded from: " . $configPath);

    // Create and start server instance
    try {
        $server = new Server($config->getAll());
        $server->start();
    } catch (Exception $e) {
        $logger->error("Server error: " . $e->getMessage());
        $logger->error("Stacktrace: " . $e->getTraceAsString());
        die("Server error: " . $e->getMessage());
    }
} else {
    // If started via web server, display the web interface
    $logger->info("PHP-IRCd called via web server...");
    
    // Initialize web interface
    $webInterface = new WebInterface($config);
    $webInterface->handleRequest();
}