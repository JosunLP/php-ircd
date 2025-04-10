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
}