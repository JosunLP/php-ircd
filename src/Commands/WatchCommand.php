<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class WatchCommand extends CommandBase {
    /**
     * Executes the WATCH command
     *
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Ensure the user is registered
        if (!$this->ensureRegistered($user)) {
            return;
        }

        // If no arguments are given, show the complete watch list
        if (count($args) < 2) {
            $this->sendWatchList($user);
            return;
        }

        // Process the arguments
        $targets = explode(',', $args[1]);

        foreach ($targets as $target) {
            // Check if it is an add or remove command
            // Format: [+|-]nickname
            if (empty($target)) {
                continue;
            }

            $operation = substr($target, 0, 1);
            $nickname = substr($target, 1);

            if ($operation === '+') {
                // Add nickname to watch list
                $this->addToWatchList($user, $nickname);
            } elseif ($operation === '-') {
                // Remove nickname from watch list
                $this->removeFromWatchList($user, $nickname);
            } elseif ($target === 'C') {
                // Clear the watch list
                $this->clearWatchList($user);
            } elseif ($target === 'S') {
                // Show status of all watched users
                $this->showAllStatus($user);
            } elseif ($target === 'L') {
                // Just show the watch list
                $this->sendWatchList($user);
            } else {
                // If no operator is specified, default to adding
                $this->addToWatchList($user, $target);
            }
        }
    }

    /**
     * Fügt einen Nickname zur Watch-Liste eines Benutzers hinzu
     *
     * @param User $user Der Benutzer, dessen Liste aktualisiert wird
     * @param string $nickname Der zu überwachende Nickname
     */
    private function addToWatchList(User $user, string $nickname): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();

        // Prüfen, ob der Nickname gültig ist
        if (empty($nickname)) {
            return;
        }

        // Hinzufügen zur Watch-Liste
        if ($user->addToWatchList($nickname)) {
            // Nach Benutzern suchen, die diesen Nickname haben und online sind
            $found = false;
            foreach ($this->server->getUsers() as $targetUser) {
                // Nicht registrierte Benutzer überspringen
                if (!$targetUser->isRegistered()) {
                    continue;
                }

                if (strtolower($targetUser->getNick()) === strtolower($nickname)) {
                    // Benutzer gefunden, senden Sie eine Online-Benachrichtigung
                    $userInfo = $targetUser->getIdent() . '@' . $targetUser->getHost();
                    $user->send(":{$config['name']} 604 {$nick} {$nickname} {$userInfo} " . time() . " :is online");
                    $found = true;
                    break;
                }
            }

            // Wenn der Benutzer nicht gefunden wurde, senden Sie eine Offline-Benachrichtigung
            if (!$found) {
                $user->send(":{$config['name']} 605 {$nick} {$nickname} " . time() . " :is offline");
            }
        } else {
            // Benutzer konnte nicht zur Watch-Liste hinzugefügt werden (Liste voll)
            $user->send(":{$config['name']} 601 {$nick} {$nickname} :Watch list is full");
        }
    }

    /**
     * Entfernt einen Nickname aus der Watch-Liste eines Benutzers
     *
     * @param User $user Der Benutzer, dessen Liste aktualisiert wird
     * @param string $nickname Der nicht mehr zu überwachende Nickname
     */
    private function removeFromWatchList(User $user, string $nickname): void {
        $user->removeFromWatchList($nickname);
    }

    /**
     * Leert die Watch-Liste eines Benutzers
     *
     * @param User $user Der Benutzer, dessen Liste geleert wird
     */
    private function clearWatchList(User $user): void {
        $user->clearWatchList();
        $config = $this->server->getConfig();
        $nick = $user->getNick();

        // Bestätigung senden
        $user->send(":{$config['name']} 602 {$nick} :Watch list cleared");
    }

    /**
     * Zeigt den Status aller überwachten Benutzer an
     *
     * @param User $user Der Benutzer, der den Status angefordert hat
     */
    private function showAllStatus(User $user): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();

        // Holen Sie sich die Watch-Liste des Benutzers
        $watchList = $user->getWatchList();

        // Für jeden Eintrag in der Watch-Liste, suchen Sie nach Benutzern
        foreach ($watchList as $watchedNick) {
            $found = false;
            foreach ($this->server->getUsers() as $targetUser) {
                // Nicht registrierte Benutzer überspringen
                if (!$targetUser->isRegistered()) {
                    continue;
                }

                if (strtolower($targetUser->getNick()) === strtolower($watchedNick)) {
                    // Benutzer gefunden, senden Sie eine Online-Benachrichtigung
                    $userInfo = $targetUser->getIdent() . '@' . $targetUser->getHost();
                    $user->send(":{$config['name']} 604 {$nick} {$watchedNick} {$userInfo} " . time() . " :is online");
                    $found = true;
                    break;
                }
            }

            // Wenn der Benutzer nicht gefunden wurde, senden Sie eine Offline-Benachrichtigung
            if (!$found) {
                $user->send(":{$config['name']} 605 {$nick} {$watchedNick} " . time() . " :is offline");
            }
        }

        // Ende der Liste
        $user->send(":{$config['name']} 607 {$nick} :End of WATCH S");
    }

    /**
     * Sendet die Watch-Liste an den Benutzer
     *
     * @param User $user Der Benutzer, dem die Liste gesendet werden soll
     */
    private function sendWatchList(User $user): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();

        // Holen Sie sich die Watch-Liste des Benutzers
        $watchList = $user->getWatchList();

        // Senden Sie jeden Eintrag der Watch-Liste
        foreach ($watchList as $watchedNick) {
            $user->send(":{$config['name']} 606 {$nick} {$watchedNick} :is on your watch list");
        }

        // Ende der Liste
        $user->send(":{$config['name']} 607 {$nick} :End of WATCH L");
    }
}
