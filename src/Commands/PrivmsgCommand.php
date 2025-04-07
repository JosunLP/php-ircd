<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class PrivmsgCommand extends CommandBase {
    /**
     * Führt den PRIVMSG-Befehl aus
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
        if (!isset($args[1])) {
            $this->sendError($user, 'PRIVMSG', 'No recipient given', 411);
            return;
        }
        
        if (!isset($args[2])) {
            $this->sendError($user, 'PRIVMSG', 'No text to send', 412);
            return;
        }
        
        // Ziele extrahieren
        $targets = explode(',', $args[1]);
        $message = $this->getMessagePart($args, 2);
        
        // Nachricht an alle Ziele senden
        foreach ($targets as $target) {
            $this->sendMessage($user, $target, $message);
        }
    }
    
    /**
     * Sendet eine Nachricht an ein Ziel
     * 
     * @param User $user Der sendende Benutzer
     * @param string $target Das Ziel (Benutzer oder Kanal)
     * @param string $message Die Nachricht
     */
    private function sendMessage(User $user, string $target, string $message): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // Kanalname beginnt mit #
        if ($target[0] === '#') {
            $this->sendChannelMessage($user, $target, $message);
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
        
        // Wenn Benutzer nicht gefunden wurde, Fehler senden
        if ($targetUser === null) {
            $user->send(":{$config['name']} 401 {$nick} {$target} :No such nick/channel");
            return;
        }
        
        // Wenn der Zielbenutzer away ist, eine Meldung senden
        if ($targetUser->isAway()) {
            $awayMessage = $targetUser->getAwayMessage();
            $user->send(":{$config['name']} 301 {$nick} {$target} :{$awayMessage}");
        }
        
        // Nachricht an den Zielbenutzer senden
        $targetUser->send(":{$nick}!{$user->getIdent()}@{$user->getCloak()} PRIVMSG {$target} :{$message}");
    }
    
    /**
     * Sendet eine Nachricht an einen Kanal
     * 
     * @param User $user Der sendende Benutzer
     * @param string $channelName Der Kanalname
     * @param string $message Die Nachricht
     */
    private function sendChannelMessage(User $user, string $channelName, string $message): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // Kanal suchen
        $channel = $this->server->getChannel($channelName);
        
        // Wenn Kanal nicht gefunden wurde, Fehler senden
        if ($channel === null) {
            $user->send(":{$config['name']} 401 {$nick} {$channelName} :No such nick/channel");
            return;
        }
        
        // Prüfen, ob der Benutzer im Kanal ist
        if (!$channel->hasUser($user)) {
            $user->send(":{$config['name']} 404 {$nick} {$channelName} :Cannot send to channel");
            return;
        }
        
        // Prüfen, ob der Kanal im no-external-messages Modus ist
        if ($channel->hasMode('n') && !$channel->hasUser($user)) {
            $user->send(":{$config['name']} 404 {$nick} {$channelName} :Cannot send to channel");
            return;
        }
        
        // Prüfen, ob der Kanal im moderated Modus ist und der Benutzer keine Voice hat
        if ($channel->hasMode('m') && !$channel->isVoiced($user) && !$channel->isOperator($user)) {
            $user->send(":{$config['name']} 404 {$nick} {$channelName} :Cannot send to channel");
            return;
        }
        
        // Nachricht an alle Benutzer im Kanal senden (außer dem Sender)
        $message = ":{$nick}!{$user->getIdent()}@{$user->getCloak()} PRIVMSG {$channelName} :{$message}";
        foreach ($channel->getUsers() as $channelUser) {
            if ($channelUser !== $user) {
                $channelUser->send($message);
            }
        }
    }
}