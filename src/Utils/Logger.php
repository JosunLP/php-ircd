<?php

namespace PhpIrcd\Utils;

/**
 * Logger class for logging events
 */
class Logger {
    private $logFile;
    private $logLevel;
    private $logToConsole;
    
    // Log level constants
    const ERROR = 0;
    const WARNING = 1;
    const INFO = 2;
    const DEBUG = 3;
    
    /**
     * Constructor
     * 
     * @param string $logFile The path to the log file
     * @param int $logLevel The minimum log level (0=Error, 1=Warning, 2=Info, 3=Debug)
     * @param bool $logToConsole Whether to also log to the console
     */
    public function __construct(string $logFile = 'ircd.log', int $logLevel = 2, bool $logToConsole = false) {
        $this->logFile = $logFile;
        $this->logLevel = $logLevel;
        $this->logToConsole = $logToConsole;
        
        // Ensure that the log directory exists
        $logDir = dirname($logFile);
        if (!is_dir($logDir) && $logDir !== '.' && !file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Logs an error message
     * 
     * @param string $message The message to log
     */
    public function error(string $message): void {
        $this->log($message, self::ERROR);
    }
    
    /**
     * Logs a warning message
     * 
     * @param string $message The message to log
     */
    public function warning(string $message): void {
        $this->log($message, self::WARNING);
    }
    
    /**
     * Logs an info message
     * 
     * @param string $message The message to log
     */
    public function info(string $message): void {
        $this->log($message, self::INFO);
    }
    
    /**
     * Logs a debug message
     * 
     * @param string $message The message to log
     */
    public function debug(string $message): void {
        $this->log($message, self::DEBUG);
    }
    
    /**
     * Logs a message with the specified log level
     * 
     * @param string $message The message to log
     * @param int $level The log level of the message
     */
    private function log(string $message, int $level): void {
        // Only log if the level is less than or equal to the configured level
        if ($level > $this->logLevel) {
            return;
        }
        
        // Determine level string
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
        
        // Generate timestamp
        $timestamp = date('Y-m-d H:i:s');
        
        // Create formatted message
        $logMessage = "[{$timestamp}] [{$levelStr}] {$message}" . PHP_EOL;
        
        // Write to file
        try {
            file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        } catch (\Exception $e) {
            // If writing to the file fails, output to the console
            if (php_sapi_name() === 'cli') {
                echo "Logger error: Could not write to {$this->logFile}: " . $e->getMessage() . PHP_EOL;
            }
        }
        
        // Output to the console if desired and in CLI mode
        if ($this->logToConsole && php_sapi_name() === 'cli') {
            // Color codes for different log levels
            $colorCode = '';
            switch ($level) {
                case self::ERROR:
                    $colorCode = "\033[31m"; // Red
                    break;
                case self::WARNING:
                    $colorCode = "\033[33m"; // Yellow
                    break;
                case self::INFO:
                    $colorCode = "\033[32m"; // Green
                    break;
                case self::DEBUG:
                    $colorCode = "\033[36m"; // Cyan
                    break;
            }
            
            // Output message with color
            echo $colorCode . $logMessage . "\033[0m";
        }
    }
}