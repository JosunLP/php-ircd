<?php

namespace PhpIrcd\Utils;

use PhpIrcd\Models\User;
use PhpIrcd\Models\Channel;

/**
 * Helper class for supporting IRCv3 features
 * 
 * Implements functionality for various IRCv3 extensions:
 * - server-time: Adds timestamps to messages
 * - message-tags: Supports IRCv3 message tags
 * - batch: Supports message batching
 * - chathistory: Supports retrieving channel message history
 * - echo-message: Echoes messages back to the sender
 * - labeled-response: Supports associating responses with client labels
 * - away-notify: Sends notifications when users go away or return
 * - account-notify: Sends notifications when users log in/out of accounts
 * - extended-join: Includes account name and realname in JOIN messages
 */
class IRCv3Helper {
    /**
     * Manages active batch sessions per user
     * Format: ['user_id' => ['batch_id' => ['type' => string, 'tags' => array]]]
     * @var array
     */
    private static $activeBatches = [];
    
    /**
     * Constant defining the format for IRCv3 server timestamps
     */
    const TIME_FORMAT = 'Y-m-d\TH:i:s';
    
    /**
     * Generates an ISO 8601 compliant timestamp for the server-time capability
     * 
     * @param int|null $time Unix timestamp or null for the current time
     * @return string The formatted timestamp
     */
    public static function formatServerTime(?int $time = null): string {
        $time = $time ?? time();
        $microtime = microtime(true);
        
        // Format: YYYY-MM-DDThh:mm:ss.sssZ (ISO 8601 with milliseconds)
        $milliseconds = sprintf('.%03d', (int)(($microtime - floor($microtime)) * 1000));
        
        return date(self::TIME_FORMAT, $time) . $milliseconds . 'Z';
    }
    
    /**
     * Parse an IRCv3 server-time timestamp to a UNIX timestamp
     * 
     * @param string $timeString The IRCv3 timestamp
     * @return int|null The UNIX timestamp or null if invalid
     */
    public static function parseServerTime(string $timeString): ?int {
        // Remove 'Z' suffix
        if (substr($timeString, -1) === 'Z') {
            $timeString = substr($timeString, 0, -1);
        }
        
        // Split into time part and milliseconds
        $parts = explode('.', $timeString);
        $timePart = $parts[0];
        
        // Try to parse the time string
        $timestamp = strtotime($timePart);
        if ($timestamp === false) {
            return null;
        }
        
        // Add milliseconds if present
        if (isset($parts[1])) {
            $milliseconds = (int)$parts[1];
            if ($milliseconds > 0) {
                $timestamp += ($milliseconds / 1000);
            }
        }
        
        return $timestamp;
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
                $escapedValue = self::escapeTagValue((string)$value);
                $tagsString .= $key . '=' . $escapedValue;
            }
        }
        
        return '@' . $tagsString . ' ' . $message;
    }
    
    /**
     * Parse IRCv3 message tags from a message
     * 
     * @param string $message The message containing tags
     * @return array An array with 'tags' and 'message'
     */
    public static function parseMessageTags(string $message): array {
        $tags = [];
        $cleanMessage = $message;
        
        // Check if message has tags
        if (substr($message, 0, 1) === '@') {
            $spacePos = strpos($message, ' ');
            if ($spacePos !== false) {
                // Extract the tags part
                $tagsStr = substr($message, 1, $spacePos - 1);
                $cleanMessage = substr($message, $spacePos + 1);
                
                // Parse individual tags
                $tagPairs = explode(';', $tagsStr);
                foreach ($tagPairs as $pair) {
                    $equalsPos = strpos($pair, '=');
                    if ($equalsPos === false) {
                        // Valueless tag
                        $tags[trim($pair)] = true;
                    } else {
                        $key = trim(substr($pair, 0, $equalsPos));
                        $value = self::unescapeTagValue(substr($pair, $equalsPos + 1));
                        $tags[$key] = $value;
                    }
                }
            }
        }
        
        return [
            'tags' => $tags,
            'message' => $cleanMessage
        ];
    }
    
    /**
     * Escape special characters in tag values according to IRCv3 spec
     * 
     * @param string $value The tag value to escape
     * @return string The escaped value
     */
    private static function escapeTagValue(string $value): string {
        return str_replace(
            [';', ' ', '\\', "\r", "\n"],
            ['\\:', '\\s', '\\\\', '\\r', '\\n'],
            $value
        );
    }
    
    /**
     * Unescape special characters in tag values according to IRCv3 spec
     * 
     * @param string $value The escaped tag value
     * @return string The unescaped value
     */
    private static function unescapeTagValue(string $value): string {
        $result = '';
        $length = strlen($value);
        
        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] === '\\' && $i + 1 < $length) {
                $nextChar = $value[++$i];
                switch ($nextChar) {
                    case ':': $result .= ';'; break;
                    case 's': $result .= ' '; break;
                    case '\\': $result .= '\\'; break;
                    case 'r': $result .= "\r"; break;
                    case 'n': $result .= "\n"; break;
                    default: $result .= $nextChar; break;
                }
            } else {
                $result .= $value[$i];
            }
        }
        
        return $result;
    }
    
    /**
     * Adds the server-time tag to a message if the user has the capability
     * 
     * @param string $message The original message
     * @param User $user The user receiving the message
     * @param int|null $time The timestamp or null for the current time
     * @return string The modified message
     */
    public static function addServerTimeIfSupported(string $message, User $user, ?int $time = null): string {
        // Only if the user has the server-time capability
        if (!$user->hasCapability('server-time')) {
            return $message;
        }
        
        $serverTime = self::formatServerTime($time);
        return self::addMessageTags($message, ['time' => $serverTime]);
    }
    
    /**
     * Generates a unique batch ID
     * 
     * @return string The generated batch ID
     */
    public static function generateBatchId(): string {
        try {
            // Use secure random bytes for stronger uniqueness
            return bin2hex(random_bytes(4));
        } catch (\Exception $e) {
            // Fallback if random_bytes is not available
            return bin2hex(pack('N', mt_rand(0, 0xffffffff)));
        }
    }
    
    /**
     * Starts a new batch session for a user
     * 
     * @param User $user The user
     * @param string $type The type of the batch (e.g., 'chathistory', 'netsplit')
     * @param array $tags Additional tags for the batch
     * @return string The batch ID of the new session
     */
    public static function startBatch(User $user, string $type, array $tags = []): string {
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
            'tags' => $tags,
            'start_time' => time()
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
        
        // Add server-time if supported
        if ($user->hasCapability('server-time')) {
            $batchCommand = self::addServerTimeIfSupported($batchCommand, $user);
        }
        
        $user->send($batchCommand);
        
        return $batchId;
    }
    
    /**
     * Ends an active batch session for a user
     * 
     * @param User $user The user
     * @param string $batchId The ID of the batch to end
     * @return bool True if successful, otherwise false
     */
    public static function endBatch(User $user, string $batchId): bool {
        $userId = $user->getId();
        
        if (!isset(self::$activeBatches[$userId][$batchId])) {
            return false;
        }
        
        // Send the BATCH end command to the user
        $endCommand = "BATCH -{$batchId}";
        
        // Add server-time if supported
        if ($user->hasCapability('server-time')) {
            $endCommand = self::addServerTimeIfSupported($endCommand, $user);
        }
        
        $user->send($endCommand);
        
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
     * @param User $user The user
     * @param string $batchId The ID of the batch
     * @param string $message The message
     * @param array $additionalTags Additional message tags
     * @return bool True if successful, otherwise false
     */
    public static function addMessageToBatch(User $user, string $batchId, string $message, array $additionalTags = []): bool {
        $userId = $user->getId();
        
        if (!isset(self::$activeBatches[$userId][$batchId])) {
            return false;
        }
        
        $tags = ['batch' => $batchId];
        
        // Add any additional tags
        if (!empty($additionalTags)) {
            $tags = array_merge($tags, $additionalTags);
        }
        
        // Add server-time if supported
        if ($user->hasCapability('server-time') && !isset($tags['time'])) {
            $tags['time'] = self::formatServerTime();
        }
        
        // Add batch tag to the message
        $taggedMessage = self::addMessageTags($message, $tags);
        
        // Send the tagged message to the user
        $user->send($taggedMessage);
        
        return true;
    }
    
    /**
     * Checks if a user supports IRCv3 message tags
     * 
     * @param User $user The user to check
     * @return bool True if the user supports message tags
     */
    public static function supportsMessageTags(User $user): bool {
        return $user->hasCapability('message-tags');
    }
    
    /**
     * Implements the CHATHISTORY functionality (according to IRCv3)
     * 
     * @param User $user The user requesting the history
     * @param Channel $channel The channel whose history is requested
     * @param int $limit Maximum number of messages
     * @param int|null $before Messages before this timestamp
     * @param int|null $after Messages after this timestamp
     * @return bool True if the history was successfully sent
     */
    public static function sendChannelHistory(User $user, Channel $channel, int $limit = 50, ?int $before = null, ?int $after = null): bool {
        if (!$user->hasCapability('batch') || !$user->hasCapability('chathistory')) {
            return false;
        }
        
        // Get channel history with timestamp filtering
        $history = $channel->getMessageHistory($limit);
        
        // Apply time filters if specified
        if ($before !== null || $after !== null) {
            $filteredHistory = [];
            foreach ($history as $item) {
                $msgTime = $item['timestamp'] ?? 0;
                
                if (($before === null || $msgTime < $before) && 
                    ($after === null || $msgTime > $after)) {
                    $filteredHistory[] = $item;
                }
            }
            $history = $filteredHistory;
        }
        
        if (empty($history)) {
            return false;
        }
        
        // Start batch
        $batchId = self::startBatch($user, 'chathistory', [$channel->getName()]);
        
        if (empty($batchId)) {
            return false;
        }
        
        // Sort messages by timestamp (oldest first)
        usort($history, function ($a, $b) {
            return ($a['timestamp'] ?? 0) - ($b['timestamp'] ?? 0);
        });
        
        // Send messages in the batch
        foreach ($history as $historyItem) {
            $message = $historyItem['message'];
            $timestamp = $historyItem['timestamp'] ?? null;
            $sender = $historyItem['sender'] ?? null;
            
            $tags = [];
            
            // Add timestamp to the message if available
            if ($timestamp !== null) {
                $tags['time'] = self::formatServerTime($timestamp);
            }
            
            self::addMessageToBatch($user, $batchId, $message, $tags);
        }
        
        // End batch
        self::endBatch($user, $batchId);
        
        return true;
    }
    
    /**
     * Send message history for direct messages between users
     * 
     * @param User $requestingUser The user requesting the history
     * @param User $targetUser The user whose private messages should be retrieved
     * @param int $limit Maximum number of messages
     * @param int|null $before Messages before this timestamp
     * @param int|null $after Messages after this timestamp
     * @return bool True if the history was successfully sent
     */
    public static function sendPrivateMessageHistory(User $requestingUser, User $targetUser, int $limit = 50, ?int $before = null, ?int $after = null): bool {
        if (!$requestingUser->hasCapability('batch') || !$requestingUser->hasCapability('chathistory')) {
            return false;
        }
        
        // In a real implementation, this would load private message history from storage
        // For now, return false to indicate no history available
        return false;
    }
    
    /**
     * Generates and sends a standardized IRCv3 error message
     * 
     * @param User $user The user receiving the error message
     * @param string $command The command that caused the error
     * @param string $code The error code (e.g., 'INVALID_PARAMS')
     * @param string $description A human-readable description of the error
     * @param string|null $label Message label for labeled-response capability
     */
    public static function sendErrorMessage(User $user, string $command, string $code, string $description, ?string $label = null): void {
        $serverName = $user->getServer()->getConfig()['name'] ?? 'server';
        $message = ":{$serverName} FAIL {$command} {$code} :{$description}";
        
        $tags = [];
        
        // Add label if labeled-response is supported
        if ($label !== null && $user->hasCapability('labeled-response')) {
            $tags['label'] = $label;
        }
        
        // Add server-time if supported
        if ($user->hasCapability('server-time')) {
            $tags['time'] = self::formatServerTime();
        }
        
        // Add tags if any
        if (!empty($tags)) {
            $message = self::addMessageTags($message, $tags);
        }
        
        $user->send($message);
    }
    
    /**
     * Processes an incoming ECHO command according to IRCv3 echo-message
     * 
     * @param User $user The user sending the command
     * @param string $originalMessage The original message
     */
    public static function handleEchoMessage(User $user, string $originalMessage): void {
        if (!$user->hasCapability('echo-message')) {
            return;
        }
        
        // Parse message tags if present
        $parsed = self::parseMessageTags($originalMessage);
        $cleanMessage = $parsed['message'];
        
        // Use the actual user mask for the echo
        $nick = $user->getNick() ?? '*';
        $ident = $user->getIdent() ?? '*';
        $host = $user->getCloak() ?? $user->getHost() ?? '*';
        
        $echoPrefixed = ":{$nick}!{$ident}@{$host} {$cleanMessage}";
        
        $tags = $parsed['tags'];
        
        // Add server-time if supported and not already present
        if ($user->hasCapability('server-time') && !isset($tags['time'])) {
            $tags['time'] = self::formatServerTime();
        }
        
        // Add tags if any
        if (!empty($tags)) {
            $echoPrefixed = self::addMessageTags($echoPrefixed, $tags);
        }
        
        $user->send($echoPrefixed);
    }
    
    /**
     * Sends a notification for away status changes (IRCv3 away-notify)
     * 
     * @param User $user The user who changed away status
     * @param string|null $awayMessage The away message or null if returning from away
     * @param Channel|null $channel If specified, notification is sent only to this channel
     */
    public static function sendAwayNotification(User $user, ?string $awayMessage, ?Channel $channel = null): void {
        $server = $user->getServer();
        $nick = $user->getNick() ?? '*';
        $userMask = "{$nick}!{$user->getIdent()}@{$user->getCloak()}";
        
        // Determine which users should receive the notification
        $recipients = [];
        
        if ($channel !== null) {
            // Only users in the specified channel
            $recipients = $channel->getUsers();
        } else {
            // All users on the server
            $recipients = $server->getUsers();
        }
        
        foreach ($recipients as $recipient) {
            // Skip if it's the same user or the recipient doesn't support away-notify
            if ($recipient === $user || !$recipient->hasCapability('away-notify')) {
                continue;
            }
            
            if ($awayMessage !== null) {
                // User is going away
                $message = ":{$userMask} AWAY :{$awayMessage}";
            } else {
                // User is returning from away
                $message = ":{$userMask} AWAY";
            }
            
            // Add server-time if supported
            $message = self::addServerTimeIfSupported($message, $recipient);
            
            $recipient->send($message);
        }
    }
    
    /**
     * Sends an extended JOIN notification (IRCv3 extended-join)
     * 
     * @param User $user The user who joined
     * @param Channel $channel The channel joined
     * @param string|null $accountName Account name or * if not logged in
     */
    public static function sendExtendedJoinNotification(User $user, Channel $channel, ?string $accountName = null): void {
        $server = $user->getServer();
        $nick = $user->getNick() ?? '*';
        $userMask = "{$nick}!{$user->getIdent()}@{$user->getCloak()}";
        $account = $accountName ?? '*'; // '*' means not logged in
        $realname = $user->getRealname() ?? '';
        
        foreach ($channel->getUsers() as $recipient) {
            // Skip if it's the same user or the recipient doesn't support extended-join
            if ($recipient === $user || !$recipient->hasCapability('extended-join')) {
                continue;
            }
            
            $message = ":{$userMask} JOIN {$channel->getName()} {$account} :{$realname}";
            
            // Add server-time if supported
            $message = self::addServerTimeIfSupported($message, $recipient);
            
            $recipient->send($message);
        }
    }
    
    /**
     * Handles an inbound message with labeled-response capability
     * 
     * @param User $user The user who sent the message
     * @param array $tags Message tags
     * @param string $response The response to send
     */
    public static function handleLabeledResponse(User $user, array $tags, string $response): void {
        if (!$user->hasCapability('labeled-response') || !isset($tags['label'])) {
            // Just send the response without label
            $user->send($response);
            return;
        }
        
        // Include the label in the response
        $label = $tags['label'];
        $response = self::addMessageTags($response, ['label' => $label]);
        
        // Add server-time if supported
        if ($user->hasCapability('server-time')) {
            $response = self::addMessageTags($response, ['time' => self::formatServerTime()]);
        }
        
        $user->send($response);
    }
    
    /**
     * Registers a user capability and notifies clients using cap-notify
     * 
     * @param string $capability The capability being added
     * @param \PhpIrcd\Core\Server $server The server instance
     */
    public static function registerCapability(string $capability, \PhpIrcd\Core\Server $server): void {
        $serverName = $server->getConfig()['name'] ?? 'server';
        
        foreach ($server->getUsers() as $user) {
            if ($user->hasCapability('cap-notify')) {
                $message = ":{$serverName} CAP * NEW :{$capability}";
                $message = self::addServerTimeIfSupported($message, $user);
                $user->send($message);
            }
        }
    }
    
    /**
     * Unregisters a user capability and notifies clients using cap-notify
     * 
     * @param string $capability The capability being removed
     * @param \PhpIrcd\Core\Server $server The server instance
     */
    public static function unregisterCapability(string $capability, \PhpIrcd\Core\Server $server): void {
        $serverName = $server->getConfig()['name'] ?? 'server';
        
        foreach ($server->getUsers() as $user) {
            if ($user->hasCapability('cap-notify')) {
                $message = ":{$serverName} CAP * DEL :{$capability}";
                $message = self::addServerTimeIfSupported($message, $user);
                $user->send($message);
            }
        }
    }
    
    /**
     * Cleanup stale batches (orphaned batch sessions)
     * To be called periodically
     * 
     * @param int $maxAge Maximum age in seconds before a batch is considered stale
     */
    public static function cleanupStaleBatches(int $maxAge = 300): void {
        $now = time();
        
        foreach (self::$activeBatches as $userId => $userBatches) {
            foreach ($userBatches as $batchId => $batch) {
                $age = $now - ($batch['start_time'] ?? $now);
                
                if ($age > $maxAge) {
                    // Remove the stale batch
                    unset(self::$activeBatches[$userId][$batchId]);
                }
            }
            
            // Remove the user if no active batches remain
            if (empty(self::$activeBatches[$userId])) {
                unset(self::$activeBatches[$userId]);
            }
        }
    }
}