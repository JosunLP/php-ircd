<?php

/**
 * PHP-IRCd - Ein IRC-Server in PHP
 * 
 * Ursprünglich geschrieben von Daniel Danopia (2008)
 * Modernisiert 2025
 */

// Prüfen, ob wir als CLI oder über Webserver ausgeführt werden
$isCliMode = (php_sapi_name() === 'cli');

// Autoloader initialisieren
require_once __DIR__ . '/vendor/autoload.php';

use PhpIrcd\Core\Config;
use PhpIrcd\Core\Server;
use PhpIrcd\Utils\Logger;
use PhpIrcd\Web\WebInterface;

// Fehlerbehandlung
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $logger = new Logger('error.log', 0, true);
    $logger->error("PHP-Fehler ($errno): $errstr in $errfile on line $errline");
    
    if (in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        die("Ein kritischer Fehler ist aufgetreten. Siehe error.log für Details.");
    }
    
    return false; // Standard-Fehlerbehandlung fortsetzen
});

// Konfiguration laden
$configPath = __DIR__ . '/config.php';
$config = new Config($configPath);

// Logger initialisieren
$logger = new Logger(
    $config->get('log_file', 'ircd.log'),
    $config->get('log_level', 1),
    true
);

if ($isCliMode) {
    // Wenn als CLI gestartet, den Server-Daemon ausführen
    $logger->info("PHP-IRCd startet im CLI-Modus...");
    $logger->info("Konfiguration geladen aus: " . $configPath);

    // Server-Instanz erstellen und starten
    try {
        $server = new Server($config->getAll());
        $server->start();
    } catch (Exception $e) {
        $logger->error("Serverfehler: " . $e->getMessage());
        $logger->error("Stacktrace: " . $e->getTraceAsString());
        die("Serverfehler: " . $e->getMessage());
    }
} else {
    // Wenn über Webserver gestartet, die Web-Schnittstelle anzeigen
    $logger->info("PHP-IRCd wird über Webserver aufgerufen...");
    
    // Web-Interface initialisieren
    $webInterface = new WebInterface($config);
    $webInterface->handleRequest();
}