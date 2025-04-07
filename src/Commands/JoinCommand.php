<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;
use PhpIrcd\Models\Channel;

class JoinCommand extends CommandBase {
    /**
     * Führt den JOIN-Befehl aus
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
            $this->sendError($user, 'JOIN', 'Not enough parameters', 461);
            return;
        }
        
        // Kanäle und Keys extrahieren
        $channelNames = explode(',', $args[1]);
        $keys = isset($args[2]) ? explode(',', $args[2]) : [];
        
        foreach ($channelNames as $index => $channelName) {
            // Kanalname validieren
            if (!$this->validateChannelName($channelName)) {
                $user->send(":{$this->server->getConfig()['name']} 403 {$user->getNick()} {$channelName} :No such channel");
                continue;
            }
            
            // Schlüssel für den Kanal ermitteln
            $key = isset($keys[$index]) ? $keys[$index] : null;
            
            // Channel beitreten
            $this->joinChannel($user, $channelName, $key);
        }
    }
    
    /**
     * Lässt einen Benutzer einem Kanal beitreten
     * 
     * @param User $user Der Benutzer
     * @param string $channelName Der Kanalname
     * @param string|null $key Der Schlüssel für den Kanal
     */
    private function joinChannel(User $user, string $channelName, ?string $key): void {
        $config = $this->server->getConfig();
        
        // Channel holen oder erstellen
        $channel = $this->server->getChannel($channelName);
        $isNewChannel = $channel === null;
        
        if ($isNewChannel) {
            $channel = new Channel($channelName);
            $this->server->addChannel($channel);
            
            // Performance-Optimierung für Webserver:
            // Beim Erstellen eines Channels die Daten in einer Datei oder Datenbank speichern,
            // damit der Zustand zwischen Webserver-Anfragen erhalten bleibt
            $this->server->saveChannelState($channel);
        }
        
        // Prüfen, ob der Benutzer dem Kanal beitreten kann
        if (!$isNewChannel && !$channel->canJoin($user, $key)) {
            // Fehlermeldungen je nach Grund
            if ($channel->isBanned($user)) {
                $user->send(":{$config['name']} 474 {$user->getNick()} {$channelName} :Cannot join channel (+b)");
            } else if ($channel->hasMode('i') && !$channel->isInvited($user)) {
                $user->send(":{$config['name']} 473 {$user->getNick()} {$channelName} :Cannot join channel (+i)");
            } else if ($channel->hasMode('k') && $key !== $channel->getKey()) {
                $user->send(":{$config['name']} 475 {$user->getNick()} {$channelName} :Cannot join channel (+k)");
            } else if ($channel->hasMode('l') && count($channel->getUsers()) >= $channel->getLimit()) {
                $user->send(":{$config['name']} 471 {$user->getNick()} {$channelName} :Cannot join channel (+l)");
            } else {
                $user->send(":{$config['name']} 471 {$user->getNick()} {$channelName} :Cannot join channel");
            }
            return;
        }
        
        // Benutzer zum Kanal hinzufügen
        $channel->addUser($user, $isNewChannel);
        
        // Channel-Zustand speichern nach Benutzerbeitritt
        $this->server->saveChannelState($channel);
        
        // JOIN-Nachricht an alle Benutzer im Kanal senden
        $joinMessage = ":{$user->getNick()}!{$user->getIdent()}@{$user->getCloak()} JOIN :{$channelName}";
        foreach ($channel->getUsers() as $channelUser) {
            $channelUser->send($joinMessage);
        }
        
        // Topic senden, wenn vorhanden
        $topic = $channel->getTopic();
        if ($topic !== null) {
            $user->send(":{$config['name']} 332 {$user->getNick()} {$channelName} :{$topic}");
            $user->send(":{$config['name']} 333 {$user->getNick()} {$channelName} {$channel->getTopicSetBy()} {$channel->getTopicSetTime()}");
        } else {
            $user->send(":{$config['name']} 331 {$user->getNick()} {$channelName} :No topic is set");
        }
        
        // Benutzerliste senden
        $this->sendNamesList($user, $channel);
    }
    
    /**
     * Sendet die NAMES-Liste an einen Benutzer
     * 
     * @param User $user Der Benutzer
     * @param Channel $channel Der Kanal
     */
    private function sendNamesList(User $user, Channel $channel): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        $channelName = $channel->getName();
        
        // Benutzerliste erstellen
        $userNames = [];
        foreach ($channel->getUsers() as $channelUser) {
            $prefix = '';
            
            // Präfixe hinzufügen
            if ($channel->isOwner($channelUser)) {
                $prefix = '~';
            } else if ($channel->isProtected($channelUser)) {
                $prefix = '&';
            } else if ($channel->isOperator($channelUser)) {
                $prefix = '@';
            } else if ($channel->isHalfop($channelUser)) {
                $prefix = '%';
            } else if ($channel->isVoiced($channelUser)) {
                $prefix = '+';
            }
            
            $userNames[] = $prefix . $channelUser->getNick();
        }
        
        // Benutzerliste in Teile aufteilen (max. 512 Bytes pro Nachricht)
        $maxNamesPerLine = 30; // Ungefährer Wert
        $nameChunks = array_chunk($userNames, $maxNamesPerLine);
        
        foreach ($nameChunks as $nameChunk) {
            $names = implode(' ', $nameChunk);
            $user->send(":{$config['name']} 353 {$nick} = {$channelName} :{$names}");
        }
        
        $user->send(":{$config['name']} 366 {$nick} {$channelName} :End of /NAMES list");
    }
    
    /**
     * Validiert einen Kanalnamen anhand der IRC-Regeln
     * 
     * @param string $channelName Der zu prüfende Kanalname
     * @return bool Ob der Kanalname gültig ist
     */
    private function validateChannelName(string $channelName): bool {
        // Kanalname muss mit # beginnen und darf keine Leerzeichen enthalten
        return preg_match('/^#[^\s,]+$/', $channelName) === 1;
    }
}