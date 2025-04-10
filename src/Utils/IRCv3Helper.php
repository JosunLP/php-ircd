<?php

namespace PhpIrcd\Utils;

/**
 * Helper class for supporting IRCv3 features
 */
class IRCv3Helper {
    /**
     * Generates an ISO 8601 compliant timestamp for the server-time capability
     * 
     * @param int|null $time Unix timestamp or null for the current time
     * @return string The formatted timestamp
     */
    public static function formatServerTime(?int $time = null): string {
        if ($time === null) {
            $time = time();
        }
        
        // Format: YYYY-MM-DDThh:mm:ss.sssZ (ISO 8601 with milliseconds)
        $microseconds = microtime(true);
        $milliseconds = sprintf('.%03d', ($microseconds - floor($microseconds)) * 1000);
        
        return date('Y-m-d\TH:i:s', $time) . $milliseconds . 'Z';
    }
    
    /**
     * Adds IRCv3 tags to a message
     * 
     * @param string $message The original message
     * @param array $tags The tags to add
     * @return string The message with tags
     */
    public static function addMessageTags(string $message, array $tags): string {
        if (empty($tags)) {
            return $message;
        }
        
        // Format tags
        $tagsString = '';
        foreach ($tags as $key => $value) {
            if ($tagsString !== '') {
                $tagsString .= ';';
            }
            
            if ($value === true) {
                // Tag without value
                $tagsString .= $key;
            } else {
                // Tag with value
                // Escape special characters according to IRCv3 spec
                $escapedValue = str_replace([';', ' ', '\\', "\r", "\n"], ['\\:', '\\s', '\\\\', '\\r', '\\n'], $value);
                $tagsString .= $key . '=' . $escapedValue;
            }
        }
        
        return '@' . $tagsString . ' ' . $message;
    }
    
    /**
     * Adds the server-time tag to a message if the user has the capability
     * 
     * @param string $message The original message
     * @param \PhpIrcd\Models\User $user The user receiving the message
     * @param int|null $time The timestamp or null for the current time
     * @return string The modified message
     */
    public static function addServerTimeIfSupported(string $message, \PhpIrcd\Models\User $user, ?int $time = null): string {
        // Only if the user has the server-time capability
        if (!$user->hasCapability('server-time')) {
            return $message;
        }
        
        $serverTime = self::formatServerTime($time);
        return self::addMessageTags($message, ['time' => $serverTime]);
    }

    /**
     * Manages active batch sessions per user
     * Format: ['user_id' => ['batch_id' => ['type' => string, 'tags' => array]]]
     * @var array
     */
    private static $activeBatches = [];
    
    /**
     * Generates a unique batch ID
     * 
     * @return string The generated batch ID
     */
    public static function generateBatchId(): string {
        return bin2hex(random_bytes(4));
    }
    
    /**
     * Starts a new batch session for a user
     * 
     * @param \PhpIrcd\Models\User $user The user
     * @param string $type The type of the batch (e.g., 'chathistory', 'netsplit')
     * @param array $tags Additional tags for the batch
     * @return string The batch ID of the new session
     */
    public static function startBatch(\PhpIrcd\Models\User $user, string $type, array $tags = []): string {
        if (!$user->hasCapability('batch')) {
            return '';
        }
        
        $batchId = self::generateBatchId();
        $userId = $user->getId();
        
        if (!isset(self::$activeBatches[$userId])) {
            self::$activeBatches[$userId] = [];
        }
        
        self::$activeBatches[$userId][$batchId] = [
            'type' => $type,
            'tags' => $tags
        ];
        
        // Send the BATCH start command to the user
        $batchCommand = "BATCH +{$batchId} {$type}";
        
        // Add additional parameters to the batch command
        foreach ($tags as $key => $value) {
            if (is_numeric($key)) {
                // Pure parameter without key
                $batchCommand .= " {$value}";
            }
        }
        
        $user->send($batchCommand);
        
        return $batchId;
    }
    
    /**
     * Ends an active batch session for a user
     * 
     * @param \PhpIrcd\Models\User $user The user
     * @param string $batchId The ID of the batch to end
     * @return bool True if successful, otherwise false
     */
    public static function endBatch(\PhpIrcd\Models\User $user, string $batchId): bool {
        $userId = $user->getId();
        
        if (!isset(self::$activeBatches[$userId][$batchId])) {
            return false;
        }
        
        // Send the BATCH end command to the user
        $user->send("BATCH -{$batchId}");
        
        // Remove the batch from the list of active batches
        unset(self::$activeBatches[$userId][$batchId]);
        
        // Remove the user from the list if no active batches remain
        if (empty(self::$activeBatches[$userId])) {
            unset(self::$activeBatches[$userId]);
        }
        
        return true;
    }
    
    /**
     * Adds a message to a batch
     * 
     * @param \PhpIrcd\Models\User $user The user
     * @param string $batchId The ID of the batch
     * @param string $message The message
     * @return bool True if successful, otherwise false
     */
    public static function addMessageToBatch(\PhpIrcd\Models\User $user, string $batchId, string $message): bool {
        $userId = $user->getId();
        
        if (!isset(self::$activeBatches[$userId][$batchId])) {
            return false;
        }
        
        // Add batch tag to the message
        $taggedMessage = self::addMessageTags($message, ['batch' => $batchId]);
        
        // Send the tagged message to the user
        $user->send($taggedMessage);
        
        return true;
    }
    
    /**
     * Checks if a user supports IRCv3 message tags
     * 
     * @param \PhpIrcd\Models\User $user The user to check
     * @return bool True if the user supports message tags
     */
    public static function supportsMessageTags(\PhpIrcd\Models\User $user): bool {
        return $user->hasCapability('message-tags');
    }
    
    /**
     * Implements the CHATHISTORY functionality (according to IRCv3)
     * 
     * @param \PhpIrcd\Models\User $user The user requesting the history
     * @param \PhpIrcd\Models\Channel $channel The channel whose history is requested
     * @param int $limit Maximum number of messages
     * @return bool True if the history was successfully sent
     */
    public static function sendChannelHistory(\PhpIrcd\Models\User $user, \PhpIrcd\Models\Channel $channel, int $limit = 50): bool {
        if (!$user->hasCapability('batch') || !$user->hasCapability('chathistory')) {
            return false;
        }
        
        // In a proper implementation, we would load the actual
        // message history from a database or a ring buffer here
        $history = $channel->getMessageHistory($limit);
        
        if (empty($history)) {
            return false;
        }
        
        // Start batch
        $batchId = self::startBatch($user, 'chathistory', [$channel->getName()]);
        
        if (empty($batchId)) {
            return false;
        }
        
        // Send messages in the batch
        foreach ($history as $historyItem) {
            $message = $historyItem['message'];
            $timestamp = $historyItem['timestamp'] ?? null;
            
            // Add timestamp to the message if available
            if ($timestamp !== null && $user->hasCapability('server-time')) {
                $message = self::addMessageTags($message, ['time' => self::formatServerTime($timestamp)]);
            }
            
            self::addMessageToBatch($user, $batchId, $message);
        }
        
        // End batch
        self::endBatch($user, $batchId);
        
        return true;
    }
    
    /**
     * Generates and sends a standardized IRCv3 error message
     * 
     * @param \PhpIrcd\Models\User $user The user receiving the error message
     * @param string $command The command that caused the error
     * @param string $code The error code (e.g., 'INVALID_PARAMS')
     * @param string $description A human-readable description of the error
     */
    public static function sendErrorMessage(\PhpIrcd\Models\User $user, string $command, string $code, string $description): void {
        $serverName = $user->getServer()->getConfig()['name'] ?? 'server';
        $message = ":{$serverName} FAIL {$command} {$code} :{$description}";
        
        if ($user->hasCapability('message-tags')) {
            $message = self::addServerTimeIfSupported($message, $user);
        }
        
        $user->send($message);
    }
    
    /**
     * Processes an incoming ECHO command according to IRCv3 echo-message
     * 
     * @param \PhpIrcd\Models\User $user The user sending the command
     * @param string $originalMessage The original message
     */
    public static function handleEchoMessage(\PhpIrcd\Models\User $user, string $originalMessage): void {
        if (!$user->hasCapability('echo-message')) {
            return;
        }
        
        // Use the actual user mask for the echo
        $nick = $user->getNick() ?: '*'; // Use empty string instead of null with the Elvis operator
        $ident = $user->getIdent() ?: '*';
        $host = $user->getHost() ?: '*'; // Use the host instead of the cloak for the echo
        
        $echoPrefixed = ":{$nick}!{$ident}@{$host} {$originalMessage}";
        
        // Add server-time if supported
        $echoMessage = self::addServerTimeIfSupported($echoPrefixed, $user);
        
        $user->send($echoMessage);
    }
}