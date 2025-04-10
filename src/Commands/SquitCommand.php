<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class SquitCommand extends CommandBase {
    /**
     * Executes the SQUIT command (disconnects a server from the network)
     * According to RFC 2812 Section 3.1.8
     * 
     * @param User $user The executing user (must be an operator)
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Ensure the user is registered
        if (!$this->ensureRegistered($user)) {
            return;
        }
        
        // Only IRC operators can use the SQUIT command
        if (!$this->ensureOper($user)) {
            return;
        }
        
        // Check if enough parameters are provided
        if (!isset($args[1])) {
            $this->sendError($user, 'SQUIT', 'Not enough parameters', 461);
            return;
        }
        
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        $targetServer = $args[1];
        
        // Extract reason/comment
        $reason = isset($args[2]) ? $this->getMessagePart($args, 2) : "No reason given";
        
        // Prüfen, ob Server-zu-Server-Kommunikation aktiviert ist
        if (empty($config['enable_server_links']) || $config['enable_server_links'] !== true) {
            $user->send(":{$config['name']} NOTICE {$nick} :SQUIT command recognized, but server linking is disabled");
            return;
        }
        
        // Überprüfen, ob es sich um unseren eigenen Server handelt
        if (strtolower($targetServer) === strtolower($config['name'])) {
            $user->send(":{$config['name']} NOTICE {$nick} :Cannot SQUIT own server");
            return;
        }
        
        // Suche nach dem angegebenen Server in den Server-Links
        $serverLink = $this->server->getServerLink($targetServer);
        
        if ($serverLink === null) {
            $user->send(":{$config['name']} 402 {$nick} {$targetServer} :No such server");
            return;
        }
        
        // Server-Trennung ankündigen
        $this->server->getLogger()->info("SQUIT executed by operator {$nick}: {$targetServer} ({$reason})");
        
        // Benachrichtigung an alle Operatoren senden
        foreach ($this->server->getUsers() as $serverUser) {
            if ($serverUser->isOper()) {
                $serverUser->send(":{$config['name']} NOTICE {$serverUser->getNick()} :*** Notice -- Received SQUIT {$targetServer} from {$nick} ({$reason})");
            }
        }
        
        // SQUIT-Nachricht an den zu trennenden Server senden, bevor die Verbindung getrennt wird
        $squitMessage = ":{$nick}!{$user->getIdent()}@{$user->getHost()} SQUIT {$targetServer} :{$reason}";
        $serverLink->send($squitMessage);
        
        // SQUIT-Nachricht an alle anderen verbundenen Server propagieren
        $this->server->propagateToServers($squitMessage, $targetServer);
        
        // Verbindung zum Server trennen
        $this->server->getServerLinkHandler()->disconnectServer($serverLink);
        
        // Server aus der Liste der verbundenen Server entfernen
        $this->server->removeServerLink($serverLink);
        
        $user->send(":{$config['name']} NOTICE {$nick} :Server {$targetServer} has been disconnected");
    }
}