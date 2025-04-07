<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class NoticeCommand extends CommandBase {
    /**
     * Führt den NOTICE-Befehl aus
     * 
     * @param User $user Der ausführende Benutzer
     * @param array $args Die Befehlsargumente
     */
    public function execute(User $user, array $args): void {
        // Sicherstellen, dass der Benutzer registriert ist
        if (!$this->ensureRegistered($user)) {
            return;
        }
        
        // Prüfen, ob genügend Parameter vorhanden sind
        if (!isset($args[1]) || !isset($args[2])) {
            // Bei NOTICE werden keine Fehlermeldungen gesendet
            return;
        }
        
        // Ziele extrahieren
        $targets = explode(',', $args[1]);
        $message = $this->getMessagePart($args, 2);
        
        // Nachricht an alle Ziele senden
        foreach ($targets as $target) {
            $this->sendNotice($user, $target, $message);
        }
    }
    
    /**
     * Sendet eine Notice an ein Ziel
     * 
     * @param User $user Der sendende Benutzer
     * @param string $target Das Ziel (Benutzer oder Kanal)
     * @param string $message Die Nachricht
     */
    private function sendNotice(User $user, string $target, string $message): void {
        // Kanalname beginnt mit #
        if ($target[0] === '#') {
            $this->sendChannelNotice($user, $target, $message);
            return;
        }
        
        // Sonst an einen Benutzer senden
        $targetUser = null;
        
        // Benutzer suchen
        foreach ($this->server->getUsers() as $serverUser) {
            if ($serverUser->getNick() !== null && strtolower($serverUser->getNick()) === strtolower($target)) {
                $targetUser = $serverUser;
                break;
            }
        }
        
        // Wenn Benutzer nicht gefunden wurde, nichts tun (NOTICE sendet keine Fehler)
        if ($targetUser === null) {
            return;
        }
        
        // Nachricht an den Zielbenutzer senden
        $targetUser->send(":{$user->getNick()}!{$user->getIdent()}@{$user->getCloak()} NOTICE {$target} :{$message}");
    }
    
    /**
     * Sendet eine Notice an einen Kanal
     * 
     * @param User $user Der sendende Benutzer
     * @param string $channelName Der Kanalname
     * @param string $message Die Nachricht
     */
    private function sendChannelNotice(User $user, string $channelName, string $message): void {
        // Kanal suchen
        $channel = $this->server->getChannel($channelName);
        
        // Wenn Kanal nicht gefunden wurde, nichts tun (NOTICE sendet keine Fehler)
        if ($channel === null) {
            return;
        }
        
        // Prüfen, ob der Benutzer im Kanal ist
        if (!$channel->hasUser($user)) {
            return;
        }
        
        // Prüfen, ob der Kanal im no-external-messages Modus ist
        if ($channel->hasMode('n') && !$channel->hasUser($user)) {
            return;
        }
        
        // Prüfen, ob der Kanal im moderated Modus ist und der Benutzer keine Voice hat
        if ($channel->hasMode('m') && !$channel->isVoiced($user) && !$channel->isOperator($user)) {
            return;
        }
        
        // Nachricht an alle Benutzer im Kanal senden (außer dem Sender)
        $message = ":{$user->getNick()}!{$user->getIdent()}@{$user->getCloak()} NOTICE {$channelName} :{$message}";
        foreach ($channel->getUsers() as $channelUser) {
            if ($channelUser !== $user) {
                $channelUser->send($message);
            }
        }
    }
}