<?php

namespace PhpIrcd\Utils;

/**
 * Logger-Klasse für das Protokollieren von Ereignissen
 */
class Logger {
    private $logFile;
    private $logLevel;
    private $logToConsole;
    
    // Log-Level-Konstanten
    const ERROR = 0;
    const WARNING = 1;
    const INFO = 2;
    const DEBUG = 3;
    
    /**
     * Konstruktor
     * 
     * @param string $logFile Der Pfad zur Log-Datei
     * @param int $logLevel Das minimale Log-Level (0=Fehler, 1=Warnung, 2=Info, 3=Debug)
     * @param bool $logToConsole Ob auch in die Konsole geloggt werden soll
     */
    public function __construct(string $logFile = 'ircd.log', int $logLevel = 2, bool $logToConsole = false) {
        $this->logFile = $logFile;
        $this->logLevel = $logLevel;
        $this->logToConsole = $logToConsole;
        
        // Sicherstellen, dass das Log-Verzeichnis existiert
        $logDir = dirname($logFile);
        if (!is_dir($logDir) && $logDir !== '.' && !file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Loggt eine Fehlermeldung
     * 
     * @param string $message Die zu loggende Nachricht
     */
    public function error(string $message): void {
        $this->log($message, self::ERROR);
    }
    
    /**
     * Loggt eine Warnmeldung
     * 
     * @param string $message Die zu loggende Nachricht
     */
    public function warning(string $message): void {
        $this->log($message, self::WARNING);
    }
    
    /**
     * Loggt eine Infomeldung
     * 
     * @param string $message Die zu loggende Nachricht
     */
    public function info(string $message): void {
        $this->log($message, self::INFO);
    }
    
    /**
     * Loggt eine Debugmeldung
     * 
     * @param string $message Die zu loggende Nachricht
     */
    public function debug(string $message): void {
        $this->log($message, self::DEBUG);
    }
    
    /**
     * Loggt eine Nachricht mit dem angegebenen Log-Level
     * 
     * @param string $message Die zu loggende Nachricht
     * @param int $level Das Log-Level der Nachricht
     */
    private function log(string $message, int $level): void {
        // Nur loggen, wenn das Level kleiner oder gleich dem konfigurierten Level ist
        if ($level > $this->logLevel) {
            return;
        }
        
        // Level-String bestimmen
        $levelStr = 'UNKNOWN';
        switch ($level) {
            case self::ERROR:
                $levelStr = 'ERROR';
                break;
            case self::WARNING:
                $levelStr = 'WARNING';
                break;
            case self::INFO:
                $levelStr = 'INFO';
                break;
            case self::DEBUG:
                $levelStr = 'DEBUG';
                break;
        }
        
        // Zeitstempel erzeugen
        $timestamp = date('Y-m-d H:i:s');
        
        // Formatierte Nachricht erstellen
        $logMessage = "[{$timestamp}] [{$levelStr}] {$message}" . PHP_EOL;
        
        // In Datei schreiben
        try {
            file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        } catch (\Exception $e) {
            // Wenn das Schreiben in die Datei fehlschlägt, auf Konsole ausgeben
            if (php_sapi_name() === 'cli') {
                echo "Logger-Fehler: Konnte nicht in {$this->logFile} schreiben: " . $e->getMessage() . PHP_EOL;
            }
        }
        
        // Auf Konsole ausgeben, wenn gewünscht und im CLI-Modus
        if ($this->logToConsole && php_sapi_name() === 'cli') {
            // Farbcodes für verschiedene Log-Level
            $colorCode = '';
            switch ($level) {
                case self::ERROR:
                    $colorCode = "\033[31m"; // Rot
                    break;
                case self::WARNING:
                    $colorCode = "\033[33m"; // Gelb
                    break;
                case self::INFO:
                    $colorCode = "\033[32m"; // Grün
                    break;
                case self::DEBUG:
                    $colorCode = "\033[36m"; // Cyan
                    break;
            }
            
            // Nachricht mit Farbe ausgeben
            echo $colorCode . $logMessage . "\033[0m";
        }
    }
}