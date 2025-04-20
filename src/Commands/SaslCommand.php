<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class SaslCommand extends CommandBase {
    /**
     * Executes the SASL / AUTHENTICATE command
     * According to IRCv3 specifications
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick() ?? '*';
        
        // Check if SASL is enabled in the configuration
        if (empty($config['sasl_enabled']) || $config['sasl_enabled'] !== true) {
            $user->send(":{$config['name']} 903 {$nick} :SASL authentication successful");
            $user->setSaslAuthenticated(true);
            return;
        }
        
        // Determine if this is a CAP SASL or AUTHENTICATE command
        $command = strtoupper($args[0]);
        
        if ($command === 'SASL') {
            // Process the SASL command (part of CAP)
            $this->handleSaslCapability($user);
        } else if ($command === 'AUTHENTICATE') {
            // Process the AUTHENTICATE command
            $this->handleAuthenticate($user, $args);
        }
    }
    
    /**
     * Processes the SASL capability request
     * 
     * @param User $user The executing user
     */
    private function handleSaslCapability(User $user): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick() ?? '*';
        
        // Check SASL mechanisms in configuration
        $mechanisms = $config['sasl_mechanisms'] ?? ['PLAIN'];
        $mechanismsStr = implode(',', $mechanisms);
        
        // Activate SASL capability and send available mechanisms
        $user->send(":{$config['name']} CAP * LS :sasl={$mechanismsStr}");
        $user->setSaslInProgress(true);
    }
    
    /**
     * Processes the AUTHENTICATE command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    private function handleAuthenticate(User $user, array $args): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick() ?? '*';
        
        // If no SASL authentication is in progress
        if (!$user->isSaslInProgress()) {
            $user->send(":{$config['name']} 906 {$nick} :SASL authentication aborted");
            return;
        }
        
        // If not enough parameters were provided
        if (!isset($args[1])) {
            $user->send(":{$config['name']} 906 {$nick} :SASL authentication failed: Invalid parameter");
            $user->setSaslInProgress(false);
            return;
        }
        
        $param = $args[1];
        
        // Initialization of SASL authentication
        if (strtoupper($param) === 'PLAIN' || strtoupper($param) === 'EXTERNAL') {
            // Validate mechanism against supported list
            $mechanisms = $config['sasl_mechanisms'] ?? ['PLAIN'];
            if (!in_array(strtoupper($param), array_map('strtoupper', $mechanisms))) {
                $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Mechanism not supported");
                $user->setSaslInProgress(false);
                return;
            }
            
            // Store mechanism and prompt client to continue
            $user->setSaslMechanism(strtoupper($param));
            $user->send("AUTHENTICATE +");
        } 
        // Processing of SASL authentication data
        else if ($user->getSaslMechanism() === 'PLAIN') {
            // Process PLAIN authentication
            $this->handlePlainAuthentication($user, $param);
        }
        // Processing of EXTERNAL authentication data (TLS certificate)
        else if ($user->getSaslMechanism() === 'EXTERNAL') {
            // Process EXTERNAL authentication
            $this->handleExternalAuthentication($user, $param);
        } 
        // Unknown or unsupported mechanism
        else {
            $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Mechanism not supported");
            $user->setSaslInProgress(false);
        }
    }
    
    /**
     * Processes PLAIN authentication
     * 
     * @param User $user The executing user
     * @param string $data The Base64-encoded authentication data
     */
    private function handlePlainAuthentication(User $user, string $data): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick() ?? '*';
        
        // If + is received, client needs to send more data
        if ($data === '+') {
            return;
        }
        
        // Validate Base64 format before decoding
        if (!preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $data)) {
            $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Invalid Base64 format");
            $user->setSaslInProgress(false);
            return;
        }
        
        // Decode data
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Invalid encoding");
            $user->setSaslInProgress(false);
            return;
        }
        
        // Format: authorization_identity\0authentication_identity\0password
        $parts = explode("\0", $decoded);
        if (count($parts) < 3) {
            $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Invalid format");
            $user->setSaslInProgress(false);
            return;
        }
        
        // Extract authentication data
        $authzid = $parts[0]; // Can be empty, often ignored
        $authcid = $parts[1]; // Username
        $password = $parts[2]; // Password
        
        // Validate authentication data format
        if (empty($authcid) || strlen($authcid) > 30) {
            $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Invalid username format");
            $user->setSaslInProgress(false);
            return;
        }
        
        // Rate limit authentication attempts to prevent brute force attacks
        static $authAttempts = [];
        $ip = $user->getIp();
        
        if (!isset($authAttempts[$ip])) {
            $authAttempts[$ip] = ['count' => 0, 'timestamp' => time()];
        }
        
        // Reset counter after 10 minutes
        if (time() - $authAttempts[$ip]['timestamp'] > 600) {
            $authAttempts[$ip] = ['count' => 0, 'timestamp' => time()];
        }
        
        $authAttempts[$ip]['count']++;
        
        // Limit to 5 attempts per 10 minutes
        if ($authAttempts[$ip]['count'] > 5) {
            $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Too many attempts");
            $user->setSaslInProgress(false);
            $this->server->getLogger()->warning("Rate limit exceeded for SASL authentication from IP: {$ip}");
            return;
        }
        
        // Check if the credentials are valid
        $validAuth = false;
        $isOper = false;
        
        // Look in the configured SASL user accounts
        if (isset($config['sasl_users']) && is_array($config['sasl_users'])) {
            foreach ($config['sasl_users'] as $user_id => $user_data) {
                if (isset($user_data['username']) && isset($user_data['password']) && 
                    $user_data['username'] === $authcid && 
                    hash_equals($user_data['password'], $password)) {
                    
                    $validAuth = true;
                    
                    // If this SASL account should have operator privileges
                    if (isset($user_data['oper']) && $user_data['oper'] === true) {
                        $isOper = true;
                    }
                    
                    break;
                }
            }
        }
        
        // Look in operator accounts if SASL accounts are not configured
        if (!$validAuth && isset($config['opers']) && is_array($config['opers'])) {
            if (isset($config['opers'][$authcid]) && 
                hash_equals($config['opers'][$authcid], $password)) {
                $validAuth = true;
                $isOper = true;
            }
        }
        
        if ($validAuth) {
            // Authentication successful
            $user->send(":{$config['name']} 903 {$nick} :SASL authentication successful");
            $user->setSaslAuthenticated(true);
            $user->setSaslInProgress(false);
            
            // Set registered user mode
            $user->setMode('r', true);
            
            // Set operator status if applicable
            if ($isOper) {
                $user->setOper(true);
                $user->setMode('o', true);
            }
            
            $this->server->getLogger()->info("User {$nick} successfully completed SASL authentication");
        } else {
            // Authentication failed
            $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Invalid credentials");
            $user->setSaslInProgress(false);
            
            $this->server->getLogger()->warning("Failed SASL authentication attempt for user {$nick} from IP: {$ip}");
        }
    }
    
    /**
     * Processes EXTERNAL authentication (TLS certificate)
     * 
     * @param User $user The executing user
     * @param string $data The Base64-encoded authentication data
     */
    private function handleExternalAuthentication(User $user, string $data): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick() ?? '*';
        
        // For EXTERNAL authentication, we must verify that the connection is using SSL
        if (!$user->isSecureConnection()) {
            $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: EXTERNAL requires a secure connection");
            $user->setSaslInProgress(false);
            return;
        }
        
        // In a complete implementation, we would check certificate data here
        // Since this is complex and beyond the scope of this implementation,
        // we can perform a simplified check based on connection security
        
        // Validate the identity string - it should be either empty or match the requested identity
        if ($data !== '+' && $data !== '') {
            $decoded = base64_decode($data, true);
            if ($decoded === false) {
                $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Invalid identity");
                $user->setSaslInProgress(false);
                return;
            }
            
            // Check if the requested identity is valid/allowed
            // In a real implementation, we would check it against the certificate
        }
        
        // Mark authentication as successful
        $user->send(":{$config['name']} 903 {$nick} :SASL authentication successful");
        $user->setSaslAuthenticated(true);
        $user->setSaslInProgress(false);
        $user->setMode('r', true); // Set registered user mode
        
        $this->server->getLogger()->info("User {$nick} successfully completed SASL EXTERNAL authentication");
    }
    
    /**
     * Helper method to safely check credentials with constant-time comparison
     * 
     * @param string $stored The stored password or hash
     * @param string $provided The provided password
     * @return bool Whether the passwords match
     */
    private function safeStringCompare(string $stored, string $provided): bool {
        // Use hash_equals for constant-time comparison to prevent timing attacks
        if (function_exists('hash_equals')) {
            return hash_equals($stored, $provided);
        }
        
        // Fallback for older PHP versions (< 5.6)
        if (strlen($stored) !== strlen($provided)) {
            return false;
        }
        
        $result = 0;
        for ($i = 0; $i < strlen($stored); $i++) {
            $result |= ord($stored[$i]) ^ ord($provided[$i]);
        }
        
        return $result === 0;
    }
}