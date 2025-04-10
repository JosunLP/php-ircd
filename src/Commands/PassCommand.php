<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Core\Server;
use PhpIrcd\Models\User;

/**
 * PASS command handler
 * 
 * Processes password authentication before registration
 */
class PassCommand extends CommandBase {
    private $validPasswords = [];
    
    /**
     * Constructor
     * 
     * @param Server $server The server instance
     */
    public function __construct(Server $server) {
        parent::__construct($server);
        
        // Passwörter aus der Konfiguration laden
        $config = $server->getConfig();
        if (isset($config['operator_passwords']) && is_array($config['operator_passwords'])) {
            $this->validPasswords = $config['operator_passwords'];
        }
        
        // Standardpasswort für Tests
        if (empty($this->validPasswords)) {
            $this->validPasswords['admin'] = 'test123';
        }
    }
    
    /**
     * Executes the PASS command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Befehl ignorieren, wenn keine Argumente vorhanden sind
        if (count($args) < 2) {
            $this->sendError($user, 'PASS', 'Not enough parameters', 461);
            return;
        }

        // Passwort extrahieren (ohne Doppelpunkte am Anfang entfernen)
        $password = $args[1];
        if (substr($password, 0, 1) === ':') {
            $password = substr($password, 1);
        }
        
        // Passwort speichern für spätere Verwendung (z.B. bei OPER Befehl)
        $user->setPassword($password);
        
        // Passwort-Bereitstellung loggen
        $this->server->getLogger()->debug("User {$user->getIp()} provided password");
    }
}