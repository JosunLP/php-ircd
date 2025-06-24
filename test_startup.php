<?php

/**
 * Test script to demonstrate server startup information
 */

// Initialize autoloader
require_once __DIR__ . '/vendor/autoload.php';

use PhpIrcd\Core\Config;
use PhpIrcd\Core\Server;
use PhpIrcd\Utils\Logger;

// Load configuration
$configPath = __DIR__ . '/config.php';
$config = new Config($configPath);

// Initialize logger with console output
$logger = new Logger('test_startup.log', 0, true);

echo "Testing PHP-IRCd Server Startup Information\n";
echo "============================================\n\n";

// Create server instance
try {
    $server = new Server($config->getAll());

    echo "Server instance created successfully!\n\n";

    // Test the displayStartupInfo method using reflection
    $reflection = new ReflectionClass($server);
    $method = $reflection->getMethod('displayStartupInfo');
    $method->setAccessible(true);

    echo "Displaying startup information:\n";
    echo "--------------------------------\n";
    $method->invoke($server);

    echo "\n\nTesting status display:\n";
    echo "----------------------\n";
    $server->displayStatus();

    echo "\n\nTesting server statistics:\n";
    echo "-------------------------\n";
    $stats = $server->getServerStats();
    echo "Server Statistics:\n";
    echo "  Name: " . $stats['server_info']['name'] . "\n";
    echo "  Network: " . $stats['server_info']['network'] . "\n";
    echo "  Version: " . $stats['server_info']['version'] . "\n";
    echo "  Running: " . ($stats['server_info']['running'] ? 'Yes' : 'No') . "\n";
    echo "  Uptime: " . $stats['server_info']['uptime_formatted'] . "\n";
    echo "  Connected Users: " . $stats['connections']['connected_users'] . "\n";
    echo "  Active Channels: " . $stats['connections']['active_channels'] . "\n";
    echo "  Memory Usage: " . $stats['memory']['current'] . " bytes\n";
    echo "  Memory Peak: " . $stats['memory']['peak'] . " bytes\n";

    echo "\nTest completed successfully!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
