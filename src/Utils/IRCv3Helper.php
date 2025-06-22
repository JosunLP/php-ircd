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

        $tagString = '';
        foreach ($tags as $key => $value) {
            if ($tagString !== '') {
                $tagString .= ';';
            }
            $tagString .= $key;
            if ($value !== null) {
                $tagString .= "={$value}";
            }
        }

        return "@{$tagString} {$message}";
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

        if (substr($message, 0, 1) === '@') {
            $spacePos = strpos($message, ' ');
            if ($spacePos !== false) {
                $tagString = substr($message, 1, $spacePos - 1);
                $cleanMessage = substr($message, $spacePos + 1);

                $tagPairs = explode(';', $tagString);
                foreach ($tagPairs as $pair) {
                    $equalPos = strpos($pair, '=');
                    if ($equalPos !== false) {
                        $key = substr($pair, 0, $equalPos);
                        $value = substr($pair, $equalPos + 1);
                        $tags[$key] = $value;
                    } else {
                        $tags[$pair] = null;
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
     * Adds the server-time tag to a message if the user has the capability
     *
     * @param string $message The original message
     * @param User $user The user receiving the message
     * @return string The modified message
     */
    public static function addServerTimeIfSupported(string $message, User $user): string {
        if ($user->hasCapability('server-time')) {
            $timestamp = gmdate('Y-m-d\TH:i:s.v\Z');
            return "@time={$timestamp} {$message}";
        }

        return $message;
    }

    /**
     * Erstellt einen Batch-Header für eine Gruppe von Nachrichten
     *
     * @param string $type Der Batch-Typ
     * @param array $params Zusätzliche Parameter für den Batch
     * @return string Die Batch-ID
     */
    public static function createBatch(string $type, array $params = []): string {
        // Eindeutige Batch-ID generieren
        $batchId = $type . '-' . uniqid();

        // Parameter zum Batch hinzufügen
        $paramString = implode(' ', $params);
        if (!empty($paramString)) {
            $paramString = ' ' . $paramString;
        }

        return $batchId;
    }

    /**
     * Fügt ein Batch-Tag zu einer Nachricht hinzu
     *
     * @param string $message Die Nachricht
     * @param string $batchId Die Batch-ID
     * @return string Die modifizierte Nachricht
     */
    public static function addBatchTag(string $message, string $batchId): string {
        // Wenn die Nachricht bereits Tags hat, füge batch als weiteres Tag hinzu
        if (substr($message, 0, 1) === '@') {
            // Tags von der Nachricht trennen
            $parts = explode(' ', $message, 2);
            $tags = $parts[0];
            $remainingMessage = $parts[1];

            // batch-Tag hinzufügen, wenn noch nicht vorhanden
            if (strpos($tags, 'batch=') === false) {
                return "{$tags};batch={$batchId} {$remainingMessage}";
            }

            // Ansonsten Nachricht unverändert zurückgeben
            return $message;
        }

        // Wenn keine Tags vorhanden sind, füge batch als neues Tag hinzu
        return "@batch={$batchId} {$message}";
    }

    /**
     * Fügt account-Tag zu einer Nachricht hinzu, wenn der Benutzer authentifiziert ist
     *
     * @param string $message Die Nachricht
     * @param User $user Der Benutzer, von dem die Nachricht stammt
     * @return string Die möglicherweise modifizierte Nachricht mit account-Tag
     */
    public static function addAccountTagIfSupported(string $message, User $user): string {
        if ($user->hasCapability('account-tag') && $user->isSaslAuthenticated()) {
            $account = $user->getNick();
            return "@account={$account} {$message}";
        }

        return $message;
    }

    /**
     * Sanitizes a message by handling backspace characters correctly
     *
     * @param string $message The message to sanitize
     * @return string The sanitized message
     */
    public static function sanitizeMessage(string $message): string {
        // Handle backspace characters (0x08 or \b) properly
        $result = '';
        $length = strlen($message);

        for ($i = 0; $i < $length; $i++) {
            $char = $message[$i];

            // If it's a backspace and we have characters to delete
            if ($char === "\x08" && strlen($result) > 0) {
                // Remove the last character
                $result = substr($result, 0, -1);
            }
            // Otherwise add the character (unless it's a backspace with nothing to delete)
            else if ($char !== "\x08") {
                $result .= $char;
            }
        }

        return $result;
    }
}
