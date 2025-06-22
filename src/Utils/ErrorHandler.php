<?php

namespace PhpIrcd\Utils;

use PhpIrcd\Models\User;

/**
 * Centralized error handling for the IRC server
 */
class ErrorHandler {
    private $logger;

    /**
     * Constructor
     *
     * @param Logger $logger The logger instance
     */
    public function __construct(Logger $logger) {
        $this->logger = $logger;
    }

    /**
     * Handle IRC protocol errors
     *
     * @param User $user The user to send the error to
     * @param string $command The command that caused the error
     * @param string $message The error message
     * @param int $code The IRC error code
     * @param array $config Server configuration
     */
    public function sendIrcError(User $user, string $command, string $message, int $code, array $config): void {
        $nick = $user->getNick() ?? '*';
        $errorMessage = ":{$config['name']} {$code} {$nick} {$command} :{$message}";

        $user->send($errorMessage);
        $this->logger->warning("IRC Error {$code} for user {$nick}: {$message}");
    }

    /**
     * Handle connection errors
     *
     * @param User $user The user with the connection error
     * @param string $error The error message
     */
    public function handleConnectionError(User $user, string $error): void {
        $nick = $user->getNick() ?? 'unknown';
        $ip = $user->getIp();

        $this->logger->error("Connection error for user {$nick} ({$ip}): {$error}");

        // Try to send a disconnect message if possible
        try {
            $user->send("ERROR :Connection lost: {$error}");
        } catch (\Exception $e) {
            // Ignore errors when trying to send error messages
        }
    }

    /**
     * Handle authentication errors
     *
     * @param User $user The user with the auth error
     * @param string $error The error message
     * @param array $config Server configuration
     */
    public function handleAuthError(User $user, string $error, array $config): void {
        $nick = $user->getNick() ?? '*';
        $ip = $user->getIp();

        $this->logger->warning("Authentication error for user {$nick} ({$ip}): {$error}");

        $this->sendIrcError($user, 'AUTH', $error, 491, $config);
    }

    /**
     * Handle SASL authentication errors
     *
     * @param User $user The user with the SASL error
     * @param string $error The error message
     * @param array $config Server configuration
     */
    public function handleSaslError(User $user, string $error, array $config): void {
        $nick = $user->getNick() ?? '*';
        $ip = $user->getIp();

        $this->logger->warning("SASL error for user {$nick} ({$ip}): {$error}");

        $this->sendIrcError($user, 'SASL', $error, 904, $config);
    }

    /**
     * Handle channel-related errors
     *
     * @param User $user The user with the channel error
     * @param string $channel The channel name
     * @param string $error The error message
     * @param int $code The IRC error code
     * @param array $config Server configuration
     */
    public function handleChannelError(User $user, string $channel, string $error, int $code, array $config): void {
        $nick = $user->getNick() ?? '*';

        $this->logger->warning("Channel error for user {$nick} in {$channel}: {$error}");

        $this->sendIrcError($user, $channel, $error, $code, $config);
    }

    /**
     * Handle permission errors
     *
     * @param User $user The user with the permission error
     * @param string $command The command that was denied
     * @param array $config Server configuration
     */
    public function handlePermissionError(User $user, string $command, array $config): void {
        $nick = $user->getNick() ?? '*';

        $this->logger->warning("Permission denied for user {$nick} on command {$command}");

        $this->sendIrcError($user, $command, 'Permission Denied- You do not have the correct IRC operator privileges', 481, $config);
    }

    /**
     * Handle rate limiting errors
     *
     * @param User $user The user being rate limited
     * @param string $command The command that was rate limited
     * @param array $config Server configuration
     */
    public function handleRateLimitError(User $user, string $command, array $config): void {
        $nick = $user->getNick() ?? '*';
        $ip = $user->getIp();

        $this->logger->warning("Rate limit exceeded for user {$nick} ({$ip}) on command {$command}");

        $this->sendIrcError($user, $command, 'Rate limit exceeded. Please wait before trying again.', 429, $config);
    }

    /**
     * Handle server errors
     *
     * @param string $error The error message
     * @param \Throwable|null $exception The exception if any
     */
    public function handleServerError(string $error, ?\Throwable $exception = null): void {
        $this->logger->error("Server error: {$error}");

        if ($exception) {
            $this->logger->error("Exception: " . $exception->getMessage());
            $this->logger->error("Stack trace: " . $exception->getTraceAsString());
        }
    }

    /**
     * Handle configuration errors
     *
     * @param string $error The configuration error message
     */
    public function handleConfigError(string $error): void {
        $this->logger->error("Configuration error: {$error}");
    }

    /**
     * Handle SSL/TLS errors
     *
     * @param string $error The SSL error message
     * @param User|null $user The user if applicable
     */
    public function handleSslError(string $error, ?User $user = null): void {
        if ($user) {
            $nick = $user->getNick() ?? '*';
            $ip = $user->getIp();
            $this->logger->error("SSL error for user {$nick} ({$ip}): {$error}");
        } else {
            $this->logger->error("SSL error: {$error}");
        }
    }

    /**
     * Handle memory errors
     *
     * @param string $error The memory error message
     */
    public function handleMemoryError(string $error): void {
        $this->logger->error("Memory error: {$error}");
        $this->logger->error("Memory usage: " . memory_get_usage(true) . " bytes");
        $this->logger->error("Peak memory usage: " . memory_get_peak_usage(true) . " bytes");
    }

    /**
     * Handle database/storage errors
     *
     * @param string $error The storage error message
     * @param string $operation The operation that failed
     */
    public function handleStorageError(string $error, string $operation): void {
        $this->logger->error("Storage error during {$operation}: {$error}");
    }

    /**
     * Handle network errors
     *
     * @param string $error The network error message
     * @param string $operation The operation that failed
     */
    public function handleNetworkError(string $error, string $operation): void {
        $this->logger->error("Network error during {$operation}: {$error}");
    }

    /**
     * Handle IRCv3 capability errors
     *
     * @param User $user The user with the capability error
     * @param string $capability The capability that failed
     * @param string $error The error message
     * @param array $config Server configuration
     */
    public function handleCapabilityError(User $user, string $capability, string $error, array $config): void {
        $nick = $user->getNick() ?? '*';

        $this->logger->warning("Capability error for user {$nick}: {$capability} - {$error}");

        $this->sendIrcError($user, 'CAP', $error, 410, $config);
    }

    /**
     * Handle general exceptions
     *
     * @param \Throwable $exception The exception to handle
     * @param User|null $user The user if applicable
     * @param array $config Server configuration
     */
    public function handleException(\Throwable $exception, ?User $user = null, array $config = []): void {
        $errorMessage = $exception->getMessage();
        $errorCode = $exception->getCode();

        $this->logger->error("Exception: {$errorMessage} (Code: {$errorCode})");
        $this->logger->error("File: " . $exception->getFile() . ":" . $exception->getLine());
        $this->logger->error("Stack trace: " . $exception->getTraceAsString());

        if ($user && !empty($config)) {
            $this->sendIrcError($user, 'ERROR', 'Internal server error', 500, $config);
        }
    }

    /**
     * Handle fatal errors
     *
     * @param array $error The error array from error_get_last()
     */
    public function handleFatalError(array $error): void {
        $this->logger->error("Fatal error: " . $error['message']);
        $this->logger->error("File: " . $error['file'] . ":" . $error['line']);
        $this->logger->error("Type: " . $error['type']);
    }

    /**
     * Set up global error handlers
     *
     * @param ErrorHandler $handler The error handler instance
     */
    public static function setupGlobalHandlers(ErrorHandler $handler): void {
        // Set error handler
        set_error_handler(function($errno, $errstr, $errfile, $errline) use ($handler) {
            $handler->logger->error("PHP Error ({$errno}): {$errstr} in {$errfile} on line {$errline}");
            return false; // Continue with default error handling
        });

        // Set exception handler
        set_exception_handler(function(\Throwable $exception) use ($handler) {
            $handler->handleException($exception);
        });

        // Set shutdown function to catch fatal errors
        register_shutdown_function(function() use ($handler) {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $handler->handleFatalError($error);
            }
        });
    }
}
