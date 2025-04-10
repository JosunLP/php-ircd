<?php

namespace PhpIrcd\Utils;

/**
 * Hilfsklasse für die Unterstützung von IRCv3-Features
 */
class IRCv3Helper {
    /**
     * Generiert einen ISO 8601 konformen Zeitstempel für die server-time Capability
     * 
     * @param int|null $time Unix-Zeitstempel oder null für die aktuelle Zeit
     * @return string Der formatierte Zeitstempel
     */
    public static function formatServerTime(?int $time = null): string {
        if ($time === null) {
            $time = time();
        }
        
        // Format: YYYY-MM-DDThh:mm:ss.sssZ (ISO 8601 mit Millisekunden)
        $microseconds = microtime(true);
        $milliseconds = sprintf('.%03d', ($microseconds - floor($microseconds)) * 1000);
        
        return date('Y-m-d\TH:i:s', $time) . $milliseconds . 'Z';
    }
    
    /**
     * Fügt IRCv3-Tags zu einer Nachricht hinzu
     * 
     * @param string $message Die ursprüngliche Nachricht
     * @param array $tags Die hinzuzufügenden Tags
     * @return string Die Nachricht mit Tags
     */
    public static function addMessageTags(string $message, array $tags): string {
        if (empty($tags)) {
            return $message;
        }
        
        // Tags formatieren
        $tagsString = '';
        foreach ($tags as $key => $value) {
            if ($tagsString !== '') {
                $tagsString .= ';';
            }
            
            if ($value === true) {
                // Tag ohne Wert
                $tagsString .= $key;
            } else {
                // Tag mit Wert
                // Escapen von Sonderzeichen nach IRCv3-Spec
                $escapedValue = str_replace([';', ' ', '\\', "\r", "\n"], ['\\:', '\\s', '\\\\', '\\r', '\\n'], $value);
                $tagsString .= $key . '=' . $escapedValue;
            }
        }
        
        return '@' . $tagsString . ' ' . $message;
    }
    
    /**
     * Fügt den server-time Tag zu einer Nachricht hinzu, wenn der Benutzer die Capability hat
     * 
     * @param string $message Die ursprüngliche Nachricht
     * @param \PhpIrcd\Models\User $user Der Benutzer, der die Nachricht empfängt
     * @param int|null $time Der Zeitstempel oder null für aktuelle Zeit
     * @return string Die modifizierte Nachricht
     */
    public static function addServerTimeIfSupported(string $message, \PhpIrcd\Models\User $user, ?int $time = null): string {
        // Nur wenn der Benutzer die server-time Capability hat
        if (!$user->hasCapability('server-time')) {
            return $message;
        }
        
        $serverTime = self::formatServerTime($time);
        return self::addMessageTags($message, ['time' => $serverTime]);
    }

    /**
     * Verwaltet die aktiven Batch-Sitzungen pro Benutzer
     * Format: ['user_id' => ['batch_id' => ['type' => string, 'tags' => array]]]
     * @var array
     */
    private static $activeBatches = [];
    
    /**
     * Generiert eine eindeutige Batch-ID
     * 
     * @return string Die erzeugte Batch-ID
     */
    public static function generateBatchId(): string {
        return bin2hex(random_bytes(4));
    }
    
    /**
     * Startet eine neue Batch-Sitzung für einen Benutzer
     * 
     * @param \PhpIrcd\Models\User $user Der Benutzer
     * @param string $type Der Typ des Batches (z.B. 'chathistory', 'netsplit')
     * @param array $tags Zusätzliche Tags für den Batch
     * @return string Die Batch-ID der neuen Sitzung
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
        
        // Sende den BATCH-Start-Befehl an den Benutzer
        $batchCommand = "BATCH +{$batchId} {$type}";
        
        // Füge zusätzliche Parameter zum Batch-Befehl hinzu
        foreach ($tags as $key => $value) {
            if (is_numeric($key)) {
                // Reiner Parameter ohne Schlüssel
                $batchCommand .= " {$value}";
            }
        }
        
        $user->send($batchCommand);
        
        return $batchId;
    }
    
    /**
     * Beendet eine aktive Batch-Sitzung für einen Benutzer
     * 
     * @param \PhpIrcd\Models\User $user Der Benutzer
     * @param string $batchId Die ID des zu beendenden Batches
     * @return bool True wenn erfolgreich, sonst false
     */
    public static function endBatch(\PhpIrcd\Models\User $user, string $batchId): bool {
        $userId = $user->getId();
        
        if (!isset(self::$activeBatches[$userId][$batchId])) {
            return false;
        }
        
        // Sende den BATCH-End-Befehl an den Benutzer
        $user->send("BATCH -{$batchId}");
        
        // Entferne den Batch aus der Liste der aktiven Batches
        unset(self::$activeBatches[$userId][$batchId]);
        
        // Entferne den Benutzer aus der Liste, wenn keine aktiven Batches mehr vorhanden sind
        if (empty(self::$activeBatches[$userId])) {
            unset(self::$activeBatches[$userId]);
        }
        
        return true;
    }
    
    /**
     * Fügt eine Nachricht zu einem Batch hinzu
     * 
     * @param \PhpIrcd\Models\User $user Der Benutzer
     * @param string $batchId Die ID des Batches
     * @param string $message Die Nachricht
     * @return bool True wenn erfolgreich, sonst false
     */
    public static function addMessageToBatch(\PhpIrcd\Models\User $user, string $batchId, string $message): bool {
        $userId = $user->getId();
        
        if (!isset(self::$activeBatches[$userId][$batchId])) {
            return false;
        }
        
        // Füge Batch-Tag zur Nachricht hinzu
        $taggedMessage = self::addMessageTags($message, ['batch' => $batchId]);
        
        // Sende die markierte Nachricht an den Benutzer
        $user->send($taggedMessage);
        
        return true;
    }
    
    /**
     * Überprüft, ob ein Benutzer IRCv3 Message-Tags unterstützt
     * 
     * @param \PhpIrcd\Models\User $user Der zu prüfende Benutzer
     * @return bool True wenn der Benutzer Message-Tags unterstützt
     */
    public static function supportsMessageTags(\PhpIrcd\Models\User $user): bool {
        return $user->hasCapability('message-tags');
    }
    
    /**
     * Implementiert die CHATHISTORY-Funktionalität (gemäß IRCv3)
     * 
     * @param \PhpIrcd\Models\User $user Der Benutzer, der die Historie anfordert
     * @param \PhpIrcd\Models\Channel $channel Der Kanal, dessen Historie angefordert wird
     * @param int $limit Maximale Anzahl an Nachrichten
     * @return bool True wenn die Historie erfolgreich gesendet wurde
     */
    public static function sendChannelHistory(\PhpIrcd\Models\User $user, \PhpIrcd\Models\Channel $channel, int $limit = 50): bool {
        if (!$user->hasCapability('batch') || !$user->hasCapability('chathistory')) {
            return false;
        }
        
        // In einer richtigen Implementierung würden wir hier die tatsächliche 
        // Nachrichtenhistorie aus einer Datenbank oder einem Ringpuffer laden
        $history = $channel->getMessageHistory($limit);
        
        if (empty($history)) {
            return false;
        }
        
        // Batch starten
        $batchId = self::startBatch($user, 'chathistory', [$channel->getName()]);
        
        if (empty($batchId)) {
            return false;
        }
        
        // Nachrichten im Batch senden
        foreach ($history as $historyItem) {
            $message = $historyItem['message'];
            $timestamp = $historyItem['timestamp'] ?? null;
            
            // Nachricht mit Zeitstempel versehen, wenn verfügbar
            if ($timestamp !== null && $user->hasCapability('server-time')) {
                $message = self::addMessageTags($message, ['time' => self::formatServerTime($timestamp)]);
            }
            
            self::addMessageToBatch($user, $batchId, $message);
        }
        
        // Batch beenden
        self::endBatch($user, $batchId);
        
        return true;
    }
    
    /**
     * Generiert und sendet eine standardisierte IRCv3-Fehlermeldung
     * 
     * @param \PhpIrcd\Models\User $user Der Benutzer, der die Fehlermeldung erhält
     * @param string $command Der Befehl, der den Fehler verursacht hat
     * @param string $code Der Fehlercode (z.B. 'INVALID_PARAMS')
     * @param string $description Eine menschenlesbare Beschreibung des Fehlers
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
     * Verarbeitet einen eingehenden ECHO-Befehl gemäß IRCv3 echo-message
     * 
     * @param \PhpIrcd\Models\User $user Der Benutzer, der den Befehl sendet
     * @param string $originalMessage Die Originalnachricht
     */
    public static function handleEchoMessage(\PhpIrcd\Models\User $user, string $originalMessage): void {
        if (!$user->hasCapability('echo-message')) {
            return;
        }
        
        // Verwende die tatsächliche Benutzermaske für das Echo
        $nick = $user->getNick() ?? '*';
        $ident = $user->getIdent() ?? '*';
        $host = $user->getHost() ?? '*'; // Verwende den Host statt des Cloaks für das Echo
        
        $echoPrefixed = ":{$nick}!{$ident}@{$host} {$originalMessage}";
        
        // Füge server-time hinzu, wenn unterstützt
        $echoMessage = self::addServerTimeIfSupported($echoPrefixed, $user);
        
        $user->send($echoMessage);
    }
}