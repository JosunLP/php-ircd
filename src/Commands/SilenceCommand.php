<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class SilenceCommand extends CommandBase {
    /**
     * Executes the SILENCE command
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
        
        // Wenn kein Parameter angegeben wurde, Silenced-Liste anzeigen
        if (!isset($args[1])) {
            $silenced = $user->getSilencedMasks();
            if (empty($silenced)) {
                $user->send(":{$config['name']} 271 {$nick} :You have no masks in your silence list");
            } else {
                foreach ($silenced as $mask) {
                    $user->send(":{$config['name']} 271 {$nick} {$mask}");
                }
            }
            return;
        }
        
        // Parameter verarbeiten
        $mask = $args[1];
        
        // Wenn der Mask mit + oder - beginnt, führe entsprechende Aktion aus
        $addMode = true;
        if (substr($mask, 0, 1) === '+' || substr($mask, 0, 1) === '-') {
            $addMode = substr($mask, 0, 1) === '+';
            $mask = substr($mask, 1);
        }
        
        if ($addMode) {
            // Mask zur Silence-Liste hinzufügen
            if ($user->addSilencedMask($mask)) {
                $user->send(":{$config['name']} 271 {$nick} {$mask}");
            } else {
                $user->send(":{$config['name']} 512 {$nick} :Cannot add to silence list, already at maximum entries");
            }
        } else {
            // Mask von der Silence-Liste entfernen
            if ($user->removeSilencedMask($mask)) {
                $user->send(":{$config['name']} 950 {$nick} {$mask} :Removed from silence list");
            } else {
                $user->send(":{$config['name']} 951 {$nick} {$mask} :No such mask in silence list");
            }
        }
    }
}