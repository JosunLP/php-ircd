<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Core\Server;
use PhpIrcd\Models\User;

abstract class CommandBase {
    protected $server;
    
    /**
     * Konstruktor
     * 
     * @param Server $server Die Server-Instanz
     */
    public function __construct(Server $server) {
        $this->server = $server;
    }
    
    /**
     * Führt den Befehl aus
     * 
     * @param User $user Der ausführende Benutzer
     * @param array $args Die Befehlsargumente
     */
    abstract public function execute(User $user, array $args): void;
    
    /**
     * Sendet eine Fehlermeldung an den Benutzer
     * 
     * @param User $user Der Benutzer
     * @param string $command Der Befehl
     * @param string $message Die Fehlermeldung
     * @param int $code Der Fehlercode
     */
    protected function sendError(User $user, string $command, string $message, int $code): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick() ?? '*';
        $user->send(":{$config['name']} {$code} {$nick} {$command} :{$message}");
    }
    
    /**
     * Prüft, ob der Benutzer registriert ist
     * 
     * @param User $user Der zu prüfende Benutzer
     * @return bool Ob der Benutzer registriert ist
     */
    protected function ensureRegistered(User $user): bool {
        if (!$user->isRegistered()) {
            $config = $this->server->getConfig();
            $nick = $user->getNick() ?? '*';
            $user->send(":{$config['name']} 451 {$nick} :You have not registered");
            return false;
        }
        return true;
    }
    
    /**
     * Prüft, ob der Benutzer Operator ist
     * 
     * @param User $user Der zu prüfende Benutzer
     * @return bool Ob der Benutzer Operator ist
     */
    protected function ensureOper(User $user): bool {
        if (!$user->isOper()) {
            $config = $this->server->getConfig();
            $nick = $user->getNick();
            $user->send(":{$config['name']} 481 {$nick} :Permission Denied- You do not have the correct IRC operator privileges");
            return false;
        }
        return true;
    }
    
    /**
     * Hilfsfunktion zum Parsen des Nachrichtenteils mit dem ':'-Präfix
     * 
     * @param array $args Die Befehlsargumente
     * @param int $startIndex Der Startindex für den Nachrichtenteil
     * @return string Die zusammengesetzte Nachricht
     */
    protected function getMessagePart(array $args, int $startIndex): string {
        // Wenn Nachrichtenteil nicht existiert oder kein ':' enthält
        if (!isset($args[$startIndex]) || strpos($args[$startIndex], ':') !== 0) {
            return '';
        }
        
        // ':' am Anfang entfernen
        $args[$startIndex] = substr($args[$startIndex], 1);
        
        // Alle Argumente ab $startIndex zusammenfügen
        return implode(' ', array_slice($args, $startIndex));
    }
}