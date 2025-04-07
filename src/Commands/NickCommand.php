<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class NickCommand extends CommandBase {
    /**
     * Führt den NICK-Befehl aus
     * 
     * @param User $user Der ausführende Benutzer
     * @param array $args Die Befehlsargumente
     */
    public function execute(User $user, array $args): void {
        // Prüfen, ob ein Nickname angegeben wurde
        if (!isset($args[1])) {
            $this->sendError($user, 'NICK', 'No nickname given', 431);
            return;
        }
        
        $newNick = $args[1];
        
        // Manchmal senden Clients :nick statt nick
        if (strpos($newNick, ':') === 0) {
            $newNick = substr($newNick, 1);
        }
        
        // Validierung des Nicknames
        if (!$this->validateNick($newNick)) {
            $this->sendError($user, $newNick, 'Erroneous Nickname: You fail.', 432);
            return;
        }
        
        // Prüfen, ob der Nickname bereits in Benutzung ist
        $users = $this->server->getUsers();
        foreach ($users as $existingUser) {
            if ($existingUser !== $user && 
                $existingUser->getNick() !== null && 
                strtolower($existingUser->getNick()) === strtolower($newNick)) {
                $currentNick = $user->getNick() ?? '*';
                $user->send(":{$this->server->getConfig()['name']} 433 {$currentNick} {$newNick} :Nickname is already in use.");
                return;
            }
        }
        
        $oldNick = $user->getNick();
        
        // Wenn dies der erste NICK-Befehl ist (Registrierung)
        if ($oldNick === null) {
            $user->setNick($newNick);
            
            // Falls der User sich vollständig registriert hat, eine PING-Anfrage senden
            if ($user->isRegistered()) {
                $user->send("PING :{$this->server->getConfig()['name']}");
            }
        } else {
            // Bei Nickname-Änderung alle relevanten Kanäle benachrichtigen
            $user->setNick($newNick);
            
            $notifiedUsers = [$user]; // Bereits benachrichtigte Benutzer
            
            // Alle Kanäle durchlaufen, in denen der Benutzer ist
            foreach ($this->server->getChannels() as $channel) {
                if ($channel->hasUser($user)) {
                    // Alle Benutzer im Kanal benachrichtigen
                    foreach ($channel->getUsers() as $channelUser) {
                        if (!in_array($channelUser, $notifiedUsers, true)) {
                            $channelUser->send(":{$oldNick}!{$user->getIdent()}@{$user->getCloak()} NICK {$newNick}");
                            $notifiedUsers[] = $channelUser;
                        }
                    }
                }
            }
        }
    }
    
    /**
     * Validiert einen Nickname anhand der IRC-Regeln
     * 
     * @param string $nick Der zu prüfende Nickname
     * @return bool Ob der Nickname gültig ist
     */
    private function validateNick(string $nick): bool {
        // IRC-Nickname-Regeln: Buchstaben, Zahlen, Sonderzeichen, max. 30 Zeichen
        return preg_match('/^[a-zA-Z\[\]_|`^][a-zA-Z0-9\[\]_|`^]{0,29}$/', $nick) === 1;
    }
}