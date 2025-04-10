<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class SaslCommand extends CommandBase {
    /**
     * Executes the SASL command for IRCv3 authentication
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // SASL requires at least a subcommand
        if (!isset($args[1])) {
            $this->sendError($user, 'SASL', 'Not enough parameters', 461);
            return;
        }
        
        $config = $this->server->getConfig();
        $nick = $user->getNick() ?? '*';
        $subcommand = strtoupper($args[1]);
        
        switch ($subcommand) {
            case 'PLAIN':
                // Check if SASL auth is in progress
                if (!$user->isSaslInProgress()) {
                    $user->send(":{$config['name']} 906 {$nick} :SASL authentication failed: Invalid authentication state");
                    $user->setSaslInProgress(false);
                    return;
                }
                
                // Check if there's authentication data
                if (!isset($args[2])) {
                    $user->send(":{$config['name']} 906 {$nick} :SASL authentication failed: No authentication data provided");
                    $user->setSaslInProgress(false);
                    return;
                }
                
                // Decode base64 data: \0username\0password
                $auth_data = base64_decode($args[2]);
                if ($auth_data === false) {
                    $user->send(":{$config['name']} 906 {$nick} :SASL authentication failed: Invalid base64 encoding");
                    $user->setSaslInProgress(false);
                    return;
                }
                
                // Format is \0username\0password
                $parts = explode("\0", $auth_data);
                if (count($parts) < 3) {
                    $user->send(":{$config['name']} 906 {$nick} :SASL authentication failed: Invalid authentication format");
                    $user->setSaslInProgress(false);
                    return;
                }
                
                $username = $parts[1];
                $password = $parts[2];
                
                // Verify against operator credentials
                if (!$this->verifySaslCredentials($username, $password)) {
                    $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Invalid credentials");
                    $user->setSaslInProgress(false);
                    return;
                }
                
                // Authentication successful
                $user->send(":{$config['name']} 903 {$nick} :SASL authentication successful");
                $user->setSaslAuthenticated(true);
                $user->setSaslInProgress(false);
                $user->setOper(true);
                $user->setMode('o', true);
                $this->server->getLogger()->info("User {$username} ({$user->getIp()}) authenticated via SASL");
                break;
                
            case 'EXTERNAL':
                // Für zertifikatsbasierte Authentifizierung
                // Da dies nur eine Basisimplementierung ist, lehnen wir dies ab
                $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: EXTERNAL mechanism not supported");
                $user->setSaslInProgress(false);
                break;
                
            case 'SCRAM-SHA-1':
                // SCRAM-SHA-1 ist ein sichererer Mechanismus als PLAIN
                // Da diese Implementierung einfach gehalten wird, lehnen wir dies ab
                $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: SCRAM-SHA-1 mechanism not supported");
                $user->setSaslInProgress(false);
                break;
                
            case 'SCRAM-SHA-256':
                // Für erhöhte Sicherheit, ebenfalls abgelehnt für diese einfache Implementierung
                $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: SCRAM-SHA-256 mechanism not supported");
                $user->setSaslInProgress(false);
                break;
                
            case 'AUTHENTICATE':
                // Starting SASL authentication
                if (!isset($args[2])) {
                    $user->setSaslInProgress(true);
                    $user->send("AUTHENTICATE +");
                } else {
                    // This would handle multi-line auth data if implemented
                    if ($args[2] === '*') {
                        $user->send(":{$config['name']} 906 {$nick} :SASL authentication aborted");
                        $user->setSaslInProgress(false);
                    }
                }
                break;
                
            default:
                $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Unknown SASL mechanism");
                $user->setSaslInProgress(false);
                break;
        }
    }
    
    /**
     * Verifies SASL credentials against operator accounts
     * 
     * @param string $username The username
     * @param string $password The password
     * @return bool Whether the credentials are valid
     */
    private function verifySaslCredentials(string $username, string $password): bool {
        $config = $this->server->getConfig();
        
        // Check against operator credentials
        if (isset($config['opers'][$username]) && $config['opers'][$username] === $password) {
            return true;
        }
        
        return false;
    }
}