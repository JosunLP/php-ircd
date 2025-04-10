<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class KnockCommand extends CommandBase {
    /**
     * Executes the KNOCK command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Ensure the user is registered
        if (!$this->ensureRegistered($user)) {
            return;
        }
        
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // Überprüfen, ob ein Kanalname angegeben wurde
        if (count($args) < 2) {
            $this->sendError($user, 'KNOCK', 'Not enough parameters', 461);
            return;
        }
        
        $channelName = $args[1];
        $reason = isset($args[2]) ? $this->getMessagePart($args, 2) : "";
        
        // Überprüfen, ob der Kanal existiert
        $channel = $this->server->getChannel($channelName);
        if ($channel === null) {
            $user->send(":{$config['name']} 401 {$nick} {$channelName} :No such channel");
            return;
        }
        
        // Überprüfen, ob der Benutzer bereits im Kanal ist
        if ($channel->hasUser($user)) {
            $user->send(":{$config['name']} 480 {$nick} {$channelName} :Cannot knock on channel you are already on");
            return;
        }
        
        // Überprüfen, ob der Kanal privat oder geheimnisvoll ist
        if (!$channel->hasMode('i') && !$channel->hasMode('k') && !$channel->hasMode('l')) {
            $user->send(":{$config['name']} 480 {$nick} {$channelName} :Cannot knock on public channels");
            return;
        }
        
        // Meldung an den Benutzer senden
        $user->send(":{$config['name']} 291 {$nick} {$channelName} :Knocked on channel");
        
        // KNOCK-Nachricht an alle Channel-Operatoren senden
        $hostmask = "{$nick}!{$user->getIdent()}@{$user->getHost()}";
        $message = ":{$config['name']} NOTICE @{$channelName} :[Knock] by {$hostmask}";
        
        if (!empty($reason)) {
            $message .= " ({$reason})";
        }
        
        foreach ($channel->getUsers() as $channelUser) {
            if ($channel->isOperator($channelUser)) {
                $channelUser->send($message);
            }
        }
    }
}