<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class PartCommand extends CommandBase {
    /**
     * Führt den PART-Befehl aus
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
            $this->sendError($user, 'PART', 'Not enough parameters', 461);
            return;
        }
        
        // Kanalnamen extrahieren
        $channelNames = explode(',', $args[1]);
        
        // Partgrund extrahieren (optional)
        $reason = $this->getMessagePart($args, 2);
        if (empty($reason)) {
            $reason = "Leaving";
        }
        
        foreach ($channelNames as $channelName) {
            $this->partChannel($user, $channelName, $reason);
        }
    }
    
    /**
     * Lässt einen Benutzer einen Kanal verlassen
     * 
     * @param User $user Der Benutzer
     * @param string $channelName Der Kanalname
     * @param string $reason Der Grund für das Verlassen
     */
    private function partChannel(User $user, string $channelName, string $reason): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // Prüfen, ob der Kanal existiert
        $channel = $this->server->getChannel($channelName);
        if ($channel === null) {
            $user->send(":{$config['name']} 403 {$nick} {$channelName} :No such channel");
            return;
        }
        
        // Prüfen, ob der Benutzer im Kanal ist
        if (!$channel->hasUser($user)) {
            $user->send(":{$config['name']} 442 {$nick} {$channelName} :You're not on that channel");
            return;
        }
        
        // PART-Nachricht an alle Benutzer im Kanal senden
        $partMessage = ":{$nick}!{$user->getIdent()}@{$user->getCloak()} PART {$channelName} :{$reason}";
        foreach ($channel->getUsers() as $channelUser) {
            $channelUser->send($partMessage);
        }
        
        // Benutzer aus dem Kanal entfernen
        $channel->removeUser($user);
        
        // Wenn der Kanal leer ist, ihn entfernen
        if (count($channel->getUsers()) === 0) {
            $this->server->removeChannel($channelName);
        }
    }
}