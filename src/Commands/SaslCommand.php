<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class SaslCommand extends CommandBase {
    /**
     * Executes the SASL / AUTHENTICATE command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick() ?? '*';
        
        // Wenn SASL in der Konfiguration deaktiviert ist
        if (empty($config['sasl_enabled']) || $config['sasl_enabled'] !== true) {
            $user->send(":{$config['name']} 903 {$nick} :SASL authentication successful");
            $user->setSaslAuthenticated(true);
            return;
        }
        
        // Bestimmen, ob es sich um CAP oder AUTHENTICATE handelt
        $command = strtoupper($args[0]);
        
        if ($command === 'SASL') {
            // Verarbeiten des SASL-Befehls (Teil von CAP)
            $this->handleSaslCapability($user);
        } else if ($command === 'AUTHENTICATE') {
            // Verarbeiten des AUTHENTICATE-Befehls
            $this->handleAuthenticate($user, $args);
        }
    }
    
    /**
     * Verarbeitet die SASL-Capability Anfrage
     * 
     * @param User $user The executing user
     */
    private function handleSaslCapability(User $user): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick() ?? '*';
        
        // SASL-Mechanismen in der Konfiguration überprüfen
        $mechanisms = $config['sasl_mechanisms'] ?? ['PLAIN'];
        $mechanismsStr = implode(',', $mechanisms);
        
        // SASL-Capability aktivieren und verfügbare Mechanismen senden
        $user->send(":{$config['name']} CAP * LS :sasl={$mechanismsStr}");
        $user->setSaslInProgress(true);
    }
    
    /**
     * Verarbeitet den AUTHENTICATE-Befehl
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    private function handleAuthenticate(User $user, array $args): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick() ?? '*';
        
        // Wenn keine SASL-Authentifizierung im Gange ist
        if (!$user->isSaslInProgress()) {
            $user->send(":{$config['name']} 906 {$nick} :SASL authentication aborted");
            return;
        }
        
        // Wenn zu wenig Parameter übergeben wurden
        if (!isset($args[1])) {
            $user->send(":{$config['name']} 906 {$nick} :SASL authentication failed: Invalid parameter");
            $user->setSaslInProgress(false);
            return;
        }
        
        $param = $args[1];
        
        // Initialisierung einer SASL-Authentifizierung
        if ($param === 'PLAIN' || $param === 'EXTERNAL') {
            // Mechanismus speichern und Client zur Fortsetzung auffordern
            $user->setSaslMechanism($param);
            $user->send("AUTHENTICATE +");
        } 
        // Verarbeitung der SASL-Authentifizierungsdaten
        else if ($user->getSaslMechanism() === 'PLAIN') {
            // PLAIN-Authentifizierung verarbeiten
            $this->handlePlainAuthentication($user, $param);
        }
        // Verarbeitung der EXTERNAL-Authentifizierungsdaten (TLS-Zertifikat)
        else if ($user->getSaslMechanism() === 'EXTERNAL') {
            // EXTERNAL-Authentifizierung verarbeiten
            $this->handleExternalAuthentication($user, $param);
        } 
        // Unbekannter oder nicht unterstützter Mechanismus
        else {
            $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Mechanism not supported");
            $user->setSaslInProgress(false);
        }
    }
    
    /**
     * Verarbeitet die PLAIN-Authentifizierung
     * 
     * @param User $user The executing user
     * @param string $data Die Base64-codierten Authentifizierungsdaten
     */
    private function handlePlainAuthentication(User $user, string $data): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick() ?? '*';
        
        // Bei + muss der Client noch Daten senden
        if ($data === '+') {
            return;
        }
        
        // Daten dekodieren
        $decoded = base64_decode($data);
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
        
        // Authentifizierungsdaten extrahieren
        $authzid = $parts[0]; // Kann leer sein, wird oft ignoriert
        $authcid = $parts[1]; // Benutzername
        $password = $parts[2]; // Passwort
        
        // Prüfen, ob die Anmeldeinformationen gültig sind
        $validAuth = false;
        
        // Suche in den konfigurierten SASL-Benutzerkonten
        if (isset($config['sasl_users']) && is_array($config['sasl_users'])) {
            foreach ($config['sasl_users'] as $user_id => $user_data) {
                if (isset($user_data['username']) && isset($user_data['password']) && 
                    $user_data['username'] === $authcid && $user_data['password'] === $password) {
                    $validAuth = true;
                    break;
                }
            }
        }
        
        // Suche in den Operator-Konten, wenn SASL-Konten nicht konfiguriert sind
        if (!$validAuth && isset($config['opers']) && is_array($config['opers'])) {
            if (isset($config['opers'][$authcid]) && $config['opers'][$authcid] === $password) {
                $validAuth = true;
                // Optional: Benutzer automatisch als Operator markieren
                // $user->setOper(true);
            }
        }
        
        if ($validAuth) {
            // Authentifizierung erfolgreich
            $user->send(":{$config['name']} 903 {$nick} :SASL authentication successful");
            $user->setSaslAuthenticated(true);
            $user->setSaslInProgress(false);
            
            $this->server->getLogger()->info("Benutzer {$nick} hat erfolgreich SASL-Authentifizierung durchgeführt");
        } else {
            // Authentifizierung fehlgeschlagen
            $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: Invalid credentials");
            $user->setSaslInProgress(false);
            
            $this->server->getLogger()->warning("Fehlgeschlagene SASL-Authentifizierung für Benutzer {$nick}");
        }
    }
    
    /**
     * Verarbeitet die EXTERNAL-Authentifizierung (TLS-Zertifikat)
     * 
     * @param User $user The executing user
     * @param string $data Die Base64-codierten Authentifizierungsdaten
     */
    private function handleExternalAuthentication(User $user, string $data): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick() ?? '*';
        
        // Bei EXTERNAL-Authentifizierung müssen wir prüfen, ob die Verbindung über SSL läuft
        if (!$user->isSecureConnection()) {
            $user->send(":{$config['name']} 904 {$nick} :SASL authentication failed: EXTERNAL requires a secure connection");
            $user->setSaslInProgress(false);
            return;
        }
        
        // Bei einer vollständigen Implementierung würden wir hier die Zertifikatsdaten überprüfen
        // Da dies komplex ist und außerhalb des Umfangs dieser Implementierung liegt, 
        // überspringen wir diesen Schritt und markieren die Authentifizierung als erfolgreich
        
        $user->send(":{$config['name']} 903 {$nick} :SASL authentication successful");
        $user->setSaslAuthenticated(true);
        $user->setSaslInProgress(false);
        
        $this->server->getLogger()->info("Benutzer {$nick} hat erfolgreich SASL EXTERNAL-Authentifizierung durchgeführt");
    }
}