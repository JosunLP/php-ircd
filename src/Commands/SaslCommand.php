<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class SaslCommand extends CommandBase {
    // Speichert temporäre SCRAM-Daten für Benutzer während der Authentifizierung
    private $scramData = [];

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
        $userId = spl_object_id($user); // Unique ID for the user

        // Initialization of SASL authentication
        if (in_array(strtoupper($param), ['PLAIN', 'EXTERNAL', 'SCRAM-SHA-1', 'SCRAM-SHA-256'])) {
            // Validate mechanism against supported list
            $mechanisms = $config['sasl_mechanisms'] ?? ['PLAIN'];
            if (!in_array(strtoupper($param), array_map('strtoupper', $mechanisms))) {
                $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Mechanism not supported");
                $user->setSaslInProgress(false);
                return;
            }

            // Store mechanism and prompt client to continue
            $user->setSaslMechanism(strtoupper($param));

            // Initialize SCRAM data if using SCRAM
            if (strpos(strtoupper($param), 'SCRAM-') === 0) {
                $this->scramData[$userId] = [
                    'state' => 'init',
                    'mechanism' => strtoupper($param),
                    'nonce' => $this->generateNonce(32)
                ];
            }

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
        // Processing of SCRAM-SHA-1 authentication
        else if ($user->getSaslMechanism() === 'SCRAM-SHA-1') {
            $this->handleScramAuthentication($user, $param, 'sha1');
        }
        // Processing of SCRAM-SHA-256 authentication
        else if ($user->getSaslMechanism() === 'SCRAM-SHA-256') {
            $this->handleScramAuthentication($user, $param, 'sha256');
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
     * Processes SCRAM authentication (SHA-1 or SHA-256)
     *
     * @param User $user The executing user
     * @param string $data The Base64-encoded authentication data
     * @param string $hash Der zu verwendende Hash-Algorithmus ('sha1' oder 'sha256')
     */
    private function handleScramAuthentication(User $user, string $data, string $hash): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick() ?? '*';
        $userId = spl_object_id($user);

        // Verify that the hash algorithm is supported
        if (!in_array($hash, ['sha1', 'sha256'])) {
            $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Unsupported hash algorithm");
            $user->setSaslInProgress(false);
            return;
        }

        // If SCRAM data doesn't exist for this user, abort
        if (!isset($this->scramData[$userId])) {
            $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: SCRAM state invalid");
            $user->setSaslInProgress(false);
            return;
        }

        // If + is received and we're still in init state, abort - we expected data
        if ($data === '+' && $this->scramData[$userId]['state'] === 'init') {
            $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Expected client-first-message");
            $user->setSaslInProgress(false);
            unset($this->scramData[$userId]);
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

        // Client-first-message state
        if ($this->scramData[$userId]['state'] === 'init') {
            // Decode data
            $decoded = base64_decode($data);
            if ($decoded === false) {
                $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Invalid encoding");
                $user->setSaslInProgress(false);
                unset($this->scramData[$userId]);
                return;
            }

            // Parse client-first-message
            // Format: n,a=authzid,n=authcid,r=cnonce
            if (!preg_match('/^n,(?:a=([^,]*),)?n=([^,]*),r=([^,]*)(?:,.+)?$/', $decoded, $matches)) {
                $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Invalid client-first-message format");
                $user->setSaslInProgress(false);
                unset($this->scramData[$userId]);
                return;
            }

            $authzid = isset($matches[1]) ? $matches[1] : '';
            $authcid = $matches[2];
            $clientNonce = $matches[3];

            // Check if username is valid
            if (empty($authcid)) {
                $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Invalid username");
                $user->setSaslInProgress(false);
                unset($this->scramData[$userId]);
                return;
            }

            // Check if client nonce is valid
            if (empty($clientNonce) || strlen($clientNonce) < 8) {
                $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Invalid client nonce");
                $user->setSaslInProgress(false);
                unset($this->scramData[$userId]);
                return;
            }

            // Store authentication data
            $this->scramData[$userId]['username'] = $authcid;
            $this->scramData[$userId]['auth_message'] = "n=". $authcid . ",r=" . $clientNonce;
            $this->scramData[$userId]['client_nonce'] = $clientNonce;
            $this->scramData[$userId]['state'] = 'challenge';

            // Create server-first-message
            $combinedNonce = $clientNonce . $this->scramData[$userId]['nonce'];
            $salt = random_bytes(16);
            $iterations = 4096;

            // Find user credentials
            $saslUsers = $config['sasl_users'] ?? [];
            $saltedPassword = null;
            $storedKey = null;
            $serverKey = null;
            $saslUserId = null;  // Renamed from userId to avoid conflict

            foreach ($saslUsers as $id => $userData) {
                if (isset($userData['username']) && $userData['username'] === $authcid) {
                    $saslUserId = $id;  // Using saslUserId instead of userId
                    // Wenn der Benutzer SCRAM-Daten hat, verwende diese
                    if (isset($userData['scram'][$hash])) {
                        $saltedPassword = $userData['scram'][$hash]['salted_password'] ?? null;
                        $storedKey = $userData['scram'][$hash]['stored_key'] ?? null;
                        $serverKey = $userData['scram'][$hash]['server_key'] ?? null;
                        $salt = $userData['scram'][$hash]['salt'] ?? $salt;
                        $iterations = $userData['scram'][$hash]['iterations'] ?? $iterations;
                    }
                    // Sonst berechne aus dem Passwort neue SCRAM-Daten
                    else if (isset($userData['password'])) {
                        $password = $userData['password'];
                        $saltedPassword = hash_pbkdf2($hash, $password, $salt, $iterations, 0, true);
                        $clientKey = hash_hmac($hash, "Client Key", $saltedPassword, true);
                        $storedKey = hash($hash, $clientKey, true);
                        $serverKey = hash_hmac($hash, "Server Key", $saltedPassword, true);
                    }
                    break;
                }
            }

            // Store for verification
            $this->scramData[$userId]['sasl_user_id'] = $saslUserId;
            $this->scramData[$userId]['salt'] = $salt;
            $this->scramData[$userId]['iterations'] = $iterations;
            $this->scramData[$userId]['salted_password'] = $saltedPassword;
            $this->scramData[$userId]['stored_key'] = $storedKey;
            $this->scramData[$userId]['server_key'] = $serverKey;
            $this->scramData[$userId]['combined_nonce'] = $combinedNonce;

            // Create server-first-message: r=combined_nonce,s=salt,i=iterations
            $message = "r=" . $combinedNonce . ",s=" . base64_encode($salt) . ",i=" . $iterations;
            $this->scramData[$userId]['auth_message'] .= "," . $message;

            // Send challenge to client
            $challenge = base64_encode($message);
            $user->send("AUTHENTICATE " . $challenge);

            $authAttempts[$ip]['count']++;
        }
        // Client-final-message state
        else if ($this->scramData[$userId]['state'] === 'challenge') {
            // Decode data
            $decoded = base64_decode($data);
            if ($decoded === false) {
                $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Invalid encoding");
                $user->setSaslInProgress(false);
                unset($this->scramData[$userId]);
                return;
            }

            // Parse client-final-message
            // Format: c=biws,r=combined_nonce,p=client_proof
            if (!preg_match('/^c=([^,]*),r=([^,]*),(?:.+,)*p=([^,]*)(?:,.+)?$/', $decoded, $matches)) {
                $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Invalid client-final-message format");
                $user->setSaslInProgress(false);
                unset($this->scramData[$userId]);
                return;
            }

            $channelBinding = $matches[1];
            $receivedNonce = $matches[2];
            $clientProof = base64_decode($matches[3]);

            // Verify combined nonce
            if ($receivedNonce !== $this->scramData[$userId]['combined_nonce']) {
                $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Invalid nonce");
                $user->setSaslInProgress(false);
                unset($this->scramData[$userId]);
                return;
            }

            // Complete authentication message
            $messageWithoutProof = preg_replace('/,p=[^,]*$/', '', $decoded);
            $this->scramData[$userId]['auth_message'] .= "," . $messageWithoutProof;

            // Check if the stored hash values are present
            if (!isset($this->scramData[$userId]['stored_key']) || !isset($this->scramData[$userId]['server_key'])) {
                $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: User not found or no password");
                $user->setSaslInProgress(false);
                unset($this->scramData[$userId]);
                return;
            }

            // Verify client proof
            $clientSignature = hash_hmac($hash, $this->scramData[$userId]['auth_message'], $this->scramData[$userId]['stored_key'], true);
            $clientKey = $clientProof ^ $clientSignature;
            $computedStoredKey = hash($hash, $clientKey, true);

            // Compare computed stored key with actual stored key
            if (!hash_equals($this->scramData[$userId]['stored_key'], $computedStoredKey)) {
                $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Invalid credentials");

                // Securely store the username for logging
                $username = $this->scramData[$userId]['username'] ?? 'unknown';
                $this->server->getLogger()->warning("Failed SCRAM SASL authentication attempt for user {$username} from IP: {$ip}");

                // Clean up resources
                $user->setSaslInProgress(false);
                unset($this->scramData[$userId]);
                return;
            }

            // Authentication successful, compute server signature
            $serverSignature = hash_hmac($hash, $this->scramData[$userId]['auth_message'], $this->scramData[$userId]['server_key'], true);
            $serverFinal = "v=" . base64_encode($serverSignature);

            // Send server final message
            $response = base64_encode($serverFinal);
            $user->send("AUTHENTICATE " . $response);

            // Complete authentication
            $user->send(":{$config['name']} 903 {$nick} :SASL authentication successful");
            $user->setSaslAuthenticated(true);
            $user->setSaslInProgress(false);
            $user->setMode('r', true); // Set registered user mode

            // Check if this user should be an operator
            $saslUserId = $this->scramData[$userId]['sasl_user_id'] ?? null;
            if ($saslUserId !== null &&
                isset($config['sasl_users'][$saslUserId]) &&
                isset($config['sasl_users'][$saslUserId]['oper']) &&
                $config['sasl_users'][$saslUserId]['oper'] === true) {

                $user->setOper(true);
                $user->setMode('o', true);
            }

            $this->server->getLogger()->info("User {$nick} successfully completed SCRAM-{$hash} authentication");

            // Clean up
            unset($this->scramData[$userId]);
        }
    }

    /**
     * Generates a secure random nonce
     *
     * @param int $length Length of the nonce
     * @return string The generated nonce
     */
    private function generateNonce(int $length = 32): string {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($length / 2));
        } else if (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes($length / 2));
        } else {
            $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $result = '';
            for ($i = 0; $i < $length; $i++) {
                $result .= $chars[mt_rand(0, strlen($chars) - 1)];
            }
            return $result;
        }
    }
}
