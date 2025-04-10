<?php

namespace PhpIrcd\Handlers;

use PhpIrcd\Core\Server;
use PhpIrcd\Models\ServerLink;

class ServerLinkHandler {
    private $server;
    private $links = [];
    private $pingTimeout = 240; // 4 Minuten Timeout für Server-Links
    
    /**
     * Constructor
     * 
     * @param Server $server Die Server-Instanz
     */
    public function __construct(Server $server) {
        $this->server = $server;
    }
    
    /**
     * Akzeptiert neue Server-Verbindungen
     * 
     * @param mixed $serverSocket Der Server-Socket
     */
    public function acceptServerConnections($serverSocket): void {
        // Überprüfen, ob Server-zu-Server-Verbindungen aktiviert sind
        $config = $this->server->getConfig();
        if (empty($config['enable_server_links']) || $config['enable_server_links'] !== true) {
            return;
        }
        
        // Prüfen, ob es sich um einen Stream-Socket (SSL) oder normalen Socket handelt
        $isStreamSocket = !($serverSocket instanceof \Socket);
        
        if ($isStreamSocket) {
            // Stream-Socket (SSL) verarbeiten
            $newSocket = @stream_socket_accept($serverSocket, 0); // Non-blocking accept
            
            if ($newSocket !== false) {
                try {
                    // Peer-Namen für den Stream-Socket ermitteln
                    $peerName = stream_socket_get_name($newSocket, true);
                    $ip = parse_url($peerName, PHP_URL_HOST) ?: explode(':', $peerName)[0];
                    
                    // Neuen Server-Link erstellen
                    $serverLink = new ServerLink($newSocket, 'unknown.server', '', true);
                    
                    // Link zum Server hinzufügen
                    $this->addServerLink($serverLink);
                    
                    // Begrüßungsnachricht senden
                    $this->sendWelcomeToServer($serverLink);
                } catch (\Exception $e) {
                    $this->server->getLogger()->error("Fehler beim Akzeptieren der Stream-Socket-Verbindung: " . $e->getMessage());
                    if (is_resource($newSocket)) {
                        fclose($newSocket);
                    }
                }
            }
        } else {
            // Normalen Socket verarbeiten
            $newSocket = socket_accept($serverSocket);
            if ($newSocket !== false) {
                try {
                    // IP-Adresse des neuen Servers ermitteln
                    socket_getpeername($newSocket, $ip);
                    
                    // Neuen Server-Link erstellen
                    $serverLink = new ServerLink($newSocket, 'unknown.server', '');
                    
                    // Link zum Server hinzufügen
                    $this->addServerLink($serverLink);
                    
                    // Begrüßungsnachricht senden
                    $this->sendWelcomeToServer($serverLink);
                } catch (\Exception $e) {
                    $this->server->getLogger()->error("Fehler beim Akzeptieren der Socket-Verbindung: " . $e->getMessage());
                    if ($newSocket instanceof \Socket) {
                        socket_close($newSocket);
                    }
                }
            }
        }
    }
    
    /**
     * Sendet eine Begrüßungsnachricht an einen neuen Server
     * 
     * @param ServerLink $serverLink Der neue Server-Link
     */
    private function sendWelcomeToServer(ServerLink $serverLink): void {
        $config = $this->server->getConfig();
        $serverLink->send("NOTICE AUTH :*** Server-zu-Server-Verbindung initiiert");
        
        // In einem produktiven Umfeld würden wir hier den PASS und SERVER handshake durchführen
    }
    
    /**
     * Fügt einen neuen Server-Link hinzu
     * 
     * @param ServerLink $serverLink Der neue Server-Link
     */
    public function addServerLink(ServerLink $serverLink): void {
        $this->links[] = $serverLink;
        $this->server->getLogger()->info("Neue Server-zu-Server-Verbindung hergestellt: " . $serverLink->getName());
    }
    
    /**
     * Verarbeitet bestehende Server-Links
     */
    public function handleExistingServerLinks(): void {
        $currentTime = time();
        
        foreach ($this->links as $key => $serverLink) {
            // Auf Timeout prüfen und inaktive Verbindungen trennen
            if ($serverLink->isInactive($this->pingTimeout)) {
                $this->disconnectServerLink($serverLink, "Ping timeout: {$this->pingTimeout} seconds");
                unset($this->links[$key]);
                continue;
            }
            
            // Daten vom Server lesen
            $command = $serverLink->readCommand();
            
            // Verbindungsverlust erkennen
            if ($command === false) {
                $this->disconnectServerLink($serverLink, "Connection closed");
                unset($this->links[$key]);
                continue;
            }
            
            // Wenn ein vollständiger Befehl empfangen wurde, verarbeiten
            if (!empty($command)) {
                $this->processServerCommand($serverLink, $command);
            }
        }
        
        // Array neu indizieren
        $this->links = array_values($this->links);
        
        // Pings an Server senden
        static $lastPingTime = 0;
        if ($currentTime - $lastPingTime > 90) { // Alle 90 Sekunden
            $this->pingServers();
            $lastPingTime = $currentTime;
        }
    }
    
    /**
     * Verarbeitet einen Befehl von einem Server
     * 
     * @param ServerLink $serverLink Der Server-Link
     * @param string $command Der empfangene Befehl
     */
    private function processServerCommand(ServerLink $serverLink, string $command): void {
        // Aktivitätszeitstempel aktualisieren
        $serverLink->updateActivity();
        
        // Befehl in seine Bestandteile zerlegen
        $parts = explode(' ', $command);
        $prefix = '';
        
        // Wenn der Befehl mit einem Präfix beginnt, dieses extrahieren
        if ($parts[0][0] === ':') {
            $prefix = substr(array_shift($parts), 1);
        }
        
        if (empty($parts)) {
            return; // Leerer Befehl
        }
        
        $commandType = strtoupper($parts[0]);
        
        // Server-Befehle verarbeiten
        switch ($commandType) {
            case 'PING':
                // Mit PONG antworten
                if (isset($parts[1])) {
                    $target = $parts[1];
                    $serverLink->send("PONG {$this->server->getConfig()['name']} {$target}");
                }
                break;
                
            case 'PONG':
                // PONG wurde empfangen, nichts zu tun
                break;
                
            case 'PASS':
                // Passwort-Authentifizierung für Server
                $this->handlePassCommand($serverLink, $parts);
                break;
                
            case 'SERVER':
                // SERVER-Befehl zur Server-Registrierung
                $this->handleServerCommand($serverLink, $parts);
                break;
                
            case 'SQUIT':
                // Server-Trennung
                $this->handleSquitCommand($serverLink, $prefix, $parts);
                break;
                
            case 'NICK':
                // Nickname-Änderung oder -Registrierung
                $this->handleNickCommand($serverLink, $prefix, $parts);
                break;
                
            case 'JOIN':
                // Channel-Beitritt
                $this->handleJoinCommand($serverLink, $prefix, $parts);
                break;
                
            case 'PART':
                // Channel-Verlassen
                $this->handlePartCommand($serverLink, $prefix, $parts);
                break;
                
            case 'QUIT':
                // Benutzer-Trennung
                $this->handleQuitCommand($serverLink, $prefix, $parts);
                break;
                
            case 'MODE':
                // Modus-Änderung
                $this->handleModeCommand($serverLink, $prefix, $parts);
                break;
                
            case 'TOPIC':
                // Topic-Änderung
                $this->handleTopicCommand($serverLink, $prefix, $parts);
                break;
                
            case 'PRIVMSG':
            case 'NOTICE':
                // Private Nachricht oder Notiz
                $this->handleMessageCommand($serverLink, $prefix, $commandType, $parts);
                break;
                
            default:
                // Unbekannter oder nicht implementierter Befehl
                $this->server->getLogger()->debug("Unbekannter Server-Befehl empfangen: {$commandType}");
                break;
        }
    }
    
    /**
     * Verarbeitet den PASS-Befehl eines Servers
     * 
     * @param ServerLink $serverLink Der Server-Link
     * @param array $parts Die Befehlsteile
     */
    private function handlePassCommand(ServerLink $serverLink, array $parts): void {
        // Überprüfen, ob genügend Parameter vorhanden sind
        if (!isset($parts[1])) {
            return;
        }
        
        // Passwort extrahieren
        $password = $parts[1];
        
        // Passwort im ServerLink speichern
        $serverLink->setPassword($password);
    }
    
    /**
     * Verarbeitet den SERVER-Befehl eines Servers
     * 
     * @param ServerLink $serverLink Der Server-Link
     * @param array $parts Die Befehlsteile
     */
    private function handleServerCommand(ServerLink $serverLink, array $parts): void {
        // Überprüfen, ob genügend Parameter vorhanden sind
        if (!isset($parts[1]) || !isset($parts[2]) || !isset($parts[3])) {
            return;
        }
        
        // Server-Parameter extrahieren
        $name = $parts[1];
        $hopCount = (int)$parts[2];
        
        // Info aus dem Rest des Befehls extrahieren (beginnt mit :)
        $info = '';
        for ($i = 3; $i < count($parts); $i++) {
            if ($parts[$i][0] === ':') {
                $info = substr(implode(' ', array_slice($parts, $i)), 1);
                break;
            }
        }
        
        // Authentifizierung überprüfen
        $config = $this->server->getConfig();
        $expectedPassword = $config['server_password'] ?? '';
        
        if ($serverLink->getPassword() !== $expectedPassword) {
            // Authentifizierung fehlgeschlagen
            $this->server->getLogger()->warning("Server-Authentifizierung fehlgeschlagen für {$name}");
            $this->disconnectServerLink($serverLink, "Authentication failed");
            return;
        }
        
        // Server-Link aktualisieren
        $serverLink->setName($name);
        $serverLink->setDescription($info);
        $serverLink->setHopCount($hopCount);
        $serverLink->setConnected(true);
        
        // Bestätigung senden
        $serverLink->send(":{$config['name']} NOTICE {$name} :Server authenticaton successful");
        
        // Loggen
        $this->server->getLogger()->info("Server {$name} erfolgreich authentifiziert");
    }
    
    /**
     * Verarbeitet den SQUIT-Befehl eines Servers
     * 
     * @param ServerLink $serverLink Der Server-Link
     * @param string $prefix Das Befehlspräfix
     * @param array $parts Die Befehlsteile
     */
    private function handleSquitCommand(ServerLink $serverLink, string $prefix, array $parts): void {
        // Überprüfen, ob genügend Parameter vorhanden sind
        if (!isset($parts[1])) {
            return;
        }
        
        // Server-Name extrahieren
        $targetServer = $parts[1];
        
        // Grund aus dem Rest des Befehls extrahieren (beginnt mit :)
        $reason = 'No reason';
        for ($i = 2; $i < count($parts); $i++) {
            if ($parts[$i][0] === ':') {
                $reason = substr(implode(' ', array_slice($parts, $i)), 1);
                break;
            }
        }
        
        // Wenn der Zielserver dieser Server ist, die Verbindung trennen
        $config = $this->server->getConfig();
        if ($targetServer === $config['name']) {
            $this->disconnectServerLink($serverLink, "Remote SQUIT: {$reason}");
        }
    }
    
    /**
     * Verarbeitet den NICK-Befehl eines Servers
     * 
     * @param ServerLink $serverLink Der Server-Link
     * @param string $prefix Das Befehlspräfix
     * @param array $parts Die Befehlsteile
     */
    private function handleNickCommand(ServerLink $serverLink, string $prefix, array $parts): void {
        // NICK-Befehl vom Server (User-Registrierung oder Namensänderung)
        // Würde in einer vollständigen Implementierung die Synchronisation von Benutzern zwischen Servern durchführen
    }
    
    /**
     * Verarbeitet den JOIN-Befehl eines Servers
     * 
     * @param ServerLink $serverLink Der Server-Link
     * @param string $prefix Das Befehlspräfix
     * @param array $parts Die Befehlsteile
     */
    private function handleJoinCommand(ServerLink $serverLink, string $prefix, array $parts): void {
        // Überprüfen, ob genügend Parameter vorhanden sind
        if (!isset($parts[1])) {
            return;
        }
        
        // Wenn kein Prefix vorhanden ist, können wir nichts tun
        if (empty($prefix)) {
            return;
        }
        
        // Benutzerinformationen aus dem Prefix extrahieren (nick!user@host)
        $nickInfo = explode('!', $prefix, 2);
        $nick = $nickInfo[0];
        
        // Ident und Host extrahieren (wenn verfügbar)
        $identHost = '';
        if (isset($nickInfo[1])) {
            $identHost = $nickInfo[1];
        }
        
        // Kanalname(n) extrahieren
        $channels = explode(',', $parts[1]);
        
        // Server-Konfiguration holen
        $config = $this->server->getConfig();
        
        // Für jeden Kanal die Join-Nachricht an lokale Benutzer weiterleiten
        foreach ($channels as $channelName) {
            // Prüfen, ob der Kanal gültig ist
            if (!Channel::isValidChannelName($channelName)) {
                continue;
            }
            
            // Kanal erstellen oder holen
            $channel = $this->server->getChannel($channelName);
            if ($channel === null) {
                $channel = new Channel($channelName);
                $this->server->addChannel($channel);
            }
            
            // Remote-Benutzer zum Kanal hinzufügen
            $remoteUser = $this->getOrCreateRemoteUser($nick, $identHost, $serverLink);
            if ($remoteUser !== null) {
                $channel->addUser($remoteUser);
                
                // JOIN-Benachrichtigung an alle Benutzer im Kanal senden
                $joinMessage = ":{$prefix} JOIN {$channelName}";
                foreach ($channel->getUsers() as $user) {
                    // Nicht an den Remote-Benutzer selbst senden
                    if ($user !== $remoteUser) {
                        $user->send($joinMessage);
                    }
                }
            }
            
            // JOIN-Nachricht an alle anderen Server weiterleiten (außer Ursprungsserver)
            $this->server->propagateToServers(":{$prefix} JOIN {$channelName}", $serverLink->getName());
        }
    }
    
    /**
     * Holt oder erstellt einen Remote-Benutzer
     * 
     * @param string $nick Der Nickname des Benutzers
     * @param string $identHost Die Ident/Host-Information (user@host)
     * @param ServerLink $serverLink Der Server-Link, von dem der Benutzer kommt
     * @return User|null Der Remote-Benutzer oder null bei Fehler
     */
    private function getOrCreateRemoteUser(string $nick, string $identHost, ServerLink $serverLink): ?User {
        // Suchen, ob der Benutzer bereits lokal bekannt ist
        foreach ($this->server->getUsers() as $user) {
            if ($user->getNick() === $nick) {
                return $user;
            }
        }
        
        // Wenn nicht, einen neuen Remote-Benutzer erstellen
        $identHostParts = explode('@', $identHost, 2);
        $ident = $identHostParts[0] ?? 'unknown';
        $host = $identHostParts[1] ?? 'unknown.host';
        
        // Neuen Remote-Benutzer mit Dummy-Socket erstellen
        $user = new User(null, $serverLink->getName() . ".remote");
        $user->setNick($nick);
        $user->setIdent($ident);
        $user->setHost($host);
        $user->setRealname("Remote user from " . $serverLink->getName());
        $user->setRemoteUser(true);
        $user->setRemoteServer($serverLink->getName());
        
        // Benutzer zum Server hinzufügen
        $this->server->addUser($user);
        
        return $user;
    }
    
    /**
     * Verarbeitet den PART-Befehl eines Servers
     * 
     * @param ServerLink $serverLink Der Server-Link
     * @param string $prefix Das Befehlspräfix
     * @param array $parts Die Befehlsteile
     */
    private function handlePartCommand(ServerLink $serverLink, string $prefix, array $parts): void {
        // Überprüfen, ob genügend Parameter vorhanden sind
        if (!isset($parts[1])) {
            return;
        }
        
        // Wenn kein Prefix vorhanden ist, können wir nichts tun
        if (empty($prefix)) {
            return;
        }
        
        // Benutzerinformationen aus dem Prefix extrahieren (nick!user@host)
        $nickInfo = explode('!', $prefix, 2);
        $nick = $nickInfo[0];
        
        // Kanalname(n) extrahieren
        $channels = explode(',', $parts[1]);
        
        // Part-Nachricht extrahieren, falls vorhanden
        $partMessage = '';
        for ($i = 2; $i < count($parts); $i++) {
            if ($parts[$i][0] === ':') {
                $partMessage = substr(implode(' ', array_slice($parts, $i)), 1);
                break;
            }
        }
        
        // Für jeden Kanal die Part-Nachricht verarbeiten
        foreach ($channels as $channelName) {
            // Kanal holen
            $channel = $this->server->getChannel($channelName);
            if ($channel === null) {
                continue; // Kanal existiert nicht
            }
            
            // Benutzer im Kanal finden
            $userFound = false;
            foreach ($channel->getUsers() as $user) {
                if ($user->getNick() === $nick) {
                    // Benutzer aus dem Kanal entfernen
                    $channel->removeUser($user);
                    $userFound = true;
                    
                    // Part-Benachrichtigung an alle verbleibenden Benutzer im Kanal senden
                    $partCommand = ":{$prefix} PART {$channelName}";
                    if (!empty($partMessage)) {
                        $partCommand .= " :{$partMessage}";
                    }
                    
                    foreach ($channel->getUsers() as $remainingUser) {
                        $remainingUser->send($partCommand);
                    }
                    
                    break;
                }
            }
            
            // Kanal löschen, wenn er leer ist und nicht permanent
            if ($channel->isEmpty() && !$channel->isPermanent()) {
                $this->server->removeChannel($channelName);
            }
            
            // Nur wenn wir den Benutzer gefunden haben, die Nachricht an andere Server weiterleiten
            if ($userFound) {
                // Part-Nachricht an alle anderen Server weiterleiten (außer Ursprungsserver)
                $partCommand = ":{$prefix} PART {$channelName}";
                if (!empty($partMessage)) {
                    $partCommand .= " :{$partMessage}";
                }
                $this->server->propagateToServers($partCommand, $serverLink->getName());
            }
        }
    }
    
    /**
     * Verarbeitet den QUIT-Befehl eines Servers
     * 
     * @param ServerLink $serverLink Der Server-Link
     * @param string $prefix Das Befehlspräfix
     * @param array $parts Die Befehlsteile
     */
    private function handleQuitCommand(ServerLink $serverLink, string $prefix, array $parts): void {
        // Wenn kein Prefix vorhanden ist, können wir nichts tun
        if (empty($prefix)) {
            return;
        }
        
        // Benutzerinformationen aus dem Prefix extrahieren (nick!user@host)
        $nickInfo = explode('!', $prefix, 2);
        $nick = $nickInfo[0];
        
        // Quit-Nachricht extrahieren, falls vorhanden
        $quitMessage = '';
        for ($i = 1; $i < count($parts); $i++) {
            if ($parts[$i][0] === ':') {
                $quitMessage = substr(implode(' ', array_slice($parts, $i)), 1);
                break;
            }
        }
        
        // Remote-Benutzer finden
        $remoteUser = null;
        foreach ($this->server->getUsers() as $user) {
            if ($user->getNick() === $nick && $user->isRemoteUser()) {
                $remoteUser = $user;
                break;
            }
        }
        
        if ($remoteUser === null) {
            return; // Benutzer nicht gefunden
        }
        
        // Benutzer aus allen Kanälen entfernen und Quit-Nachricht verteilen
        $channels = [];
        foreach ($this->server->getChannels() as $channel) {
            if ($channel->hasUser($remoteUser)) {
                $channels[] = $channel;
            }
        }
        
        // Quit-Nachricht erstellen
        $quitCommand = ":{$prefix} QUIT";
        if (!empty($quitMessage)) {
            $quitCommand .= " :{$quitMessage}";
        }
        
        // Benutzer aus jedem Kanal entfernen und Nachricht an Mitglieder senden
        foreach ($channels as $channel) {
            // Benachrichtigung an alle Benutzer im Kanal senden (außer dem, der geht)
            foreach ($channel->getUsers() as $user) {
                if ($user !== $remoteUser) {
                    $user->send($quitCommand);
                }
            }
            
            // Benutzer aus dem Kanal entfernen
            $channel->removeUser($remoteUser);
            
            // Kanal löschen, wenn er leer ist und nicht permanent
            if ($channel->isEmpty() && !$channel->isPermanent()) {
                $this->server->removeChannel($channel->getName());
            }
        }
        
        // Benutzer aus der Benutzerliste des Servers entfernen
        $this->server->removeUser($remoteUser);
        
        // Quit-Nachricht an alle anderen Server weiterleiten (außer Ursprungsserver)
        $this->server->propagateToServers($quitCommand, $serverLink->getName());
    }
    
    /**
     * Verarbeitet den MODE-Befehl eines Servers
     * 
     * @param ServerLink $serverLink Der Server-Link
     * @param string $prefix Das Befehlspräfix
     * @param array $parts Die Befehlsteile
     */
    private function handleModeCommand(ServerLink $serverLink, string $prefix, array $parts): void {
        // Überprüfen, ob genügend Parameter vorhanden sind
        if (!isset($parts[1]) || !isset($parts[2])) {
            return;
        }
        
        // Wenn kein Prefix vorhanden ist, können wir nichts tun
        if (empty($prefix)) {
            return;
        }
        
        // Ziel der Mode-Änderung extrahieren (Kanal oder Benutzer)
        $target = $parts[1];
        $modes = $parts[2];
        
        // Erstelle den vollständigen MODE-Befehl für die Weiterleitung
        $modeCommand = ":{$prefix} MODE {$target} {$modes}";
        
        // Parameter für die Modus-Änderung hinzufügen, falls vorhanden
        for ($i = 3; $i < count($parts); $i++) {
            $modeCommand .= " " . $parts[$i];
        }
        
        // Überprüfe, ob das Ziel ein Kanal ist
        if ($target[0] === '#' || $target[0] === '&') {
            // Modus-Änderung für einen Kanal
            $channel = $this->server->getChannel($target);
            
            if ($channel !== null) {
                // Benutzerinformationen aus dem Prefix extrahieren (nick!user@host)
                $nickInfo = explode('!', $prefix, 2);
                $nick = $nickInfo[0];
                
                // Remote-Benutzer im Kanal suchen
                $sourceUser = null;
                foreach ($channel->getUsers() as $user) {
                    if ($user->getNick() === $nick) {
                        $sourceUser = $user;
                        break;
                    }
                }
                
                // Verarbeite die Modus-Änderung
                if ($sourceUser !== null) {
                    // Erstelle ein Array für die Parameter (nach dem Modus)
                    $modeParams = array_slice($parts, 3);
                    
                    // Verarbeite die Modus-Änderung lokal
                    $this->processChannelModeChange($channel, $sourceUser, $modes, $modeParams);
                    
                    // Modus-Änderung an alle lokalen Benutzer im Kanal senden
                    foreach ($channel->getUsers() as $user) {
                        if (!$user->isRemoteUser()) {
                            $user->send($modeCommand);
                        }
                    }
                }
                
                // MODE-Befehl an alle anderen Server weiterleiten (außer dem Ursprungsserver)
                $this->server->propagateToServers($modeCommand, $serverLink->getName());
            }
        } else {
            // Modus-Änderung für einen Benutzer
            foreach ($this->server->getUsers() as $user) {
                if ($user->getNick() === $target && !$user->isRemoteUser()) {
                    // Benutzerinformationen aus dem Prefix extrahieren (nick!user@host)
                    $nickInfo = explode('!', $prefix, 2);
                    $sourceNick = $nickInfo[0];
                    
                    // Nur IRC-Operatoren dürfen Benutzermodi ändern
                    $sourceUser = null;
                    foreach ($this->server->getUsers() as $u) {
                        if ($u->getNick() === $sourceNick && ($u->isOper() || $sourceNick === $target)) {
                            $sourceUser = $u;
                            break;
                        }
                    }
                    
                    if ($sourceUser !== null) {
                        // Verarbeite die Benutzermodus-Änderung lokal
                        $this->processUserModeChange($user, $modes);
                        
                        // Modus-Änderung an den betroffenen Benutzer senden
                        $user->send($modeCommand);
                    }
                    
                    // MODE-Befehl an alle anderen Server weiterleiten (außer dem Ursprungsserver)
                    $this->server->propagateToServers($modeCommand, $serverLink->getName());
                    return;
                }
            }
            
            // Benutzer ist nicht lokal, an andere Server weiterleiten
            $this->server->propagateToServers($modeCommand, $serverLink->getName());
        }
    }
    
    /**
     * Verarbeitet eine Kanalmodus-Änderung lokal
     * 
     * @param \PhpIrcd\Models\Channel $channel Der betroffene Kanal
     * @param \PhpIrcd\Models\User $sourceUser Der Benutzer, der die Änderung durchführt
     * @param string $modes Die zu ändernden Modi
     * @param array $params Parameter für die Modus-Änderung
     */
    private function processChannelModeChange(\PhpIrcd\Models\Channel $channel, \PhpIrcd\Models\User $sourceUser, string $modes, array $params): void {
        $paramIndex = 0;
        $addMode = true;  // Standardmäßig Modi hinzufügen
        
        for ($i = 0; $i < strlen($modes); $i++) {
            $mode = $modes[$i];
            
            if ($mode === '+') {
                $addMode = true;
                continue;
            } elseif ($mode === '-') {
                $addMode = false;
                continue;
            }
            
            switch ($mode) {
                case 'o': // Operator-Status
                    if (isset($params[$paramIndex])) {
                        $targetNick = $params[$paramIndex++];
                        foreach ($channel->getUsers() as $user) {
                            if ($user->getNick() === $targetNick) {
                                if ($addMode) {
                                    $channel->addOperator($user);
                                } else {
                                    $channel->removeOperator($user);
                                }
                                break;
                            }
                        }
                    }
                    break;
                    
                case 'v': // Voice-Status
                    if (isset($params[$paramIndex])) {
                        $targetNick = $params[$paramIndex++];
                        foreach ($channel->getUsers() as $user) {
                            if ($user->getNick() === $targetNick) {
                                if ($addMode) {
                                    $channel->addVoiced($user);
                                } else {
                                    $channel->removeVoiced($user);
                                }
                                break;
                            }
                        }
                    }
                    break;
                    
                case 'i': // Invite-Only
                    if ($addMode) {
                        $channel->setInviteOnly(true);
                    } else {
                        $channel->setInviteOnly(false);
                    }
                    break;
                    
                case 'm': // Moderated
                    if ($addMode) {
                        $channel->setModerated(true);
                    } else {
                        $channel->setModerated(false);
                    }
                    break;
                    
                case 's': // Secret
                    if ($addMode) {
                        $channel->setSecret(true);
                    } else {
                        $channel->setSecret(false);
                    }
                    break;
                    
                case 't': // Topic-Protection
                    if ($addMode) {
                        $channel->setTopicProtection(true);
                    } else {
                        $channel->setTopicProtection(false);
                    }
                    break;
                    
                case 'k': // Key (Password)
                    if ($addMode) {
                        if (isset($params[$paramIndex])) {
                            $channel->setKey($params[$paramIndex++]);
                        }
                    } else {
                        $channel->setKey('');
                        $paramIndex++; // Auch bei Entfernung wird ein Parameter verbraucht
                    }
                    break;
                    
                case 'l': // Limit
                    if ($addMode) {
                        if (isset($params[$paramIndex])) {
                            $channel->setLimit((int)$params[$paramIndex++]);
                        }
                    } else {
                        $channel->setLimit(0);
                    }
                    break;
                    
                case 'b': // Ban
                    if (isset($params[$paramIndex])) {
                        $banMask = $params[$paramIndex++];
                        if ($addMode) {
                            $channel->addBan($banMask);
                        } else {
                            $channel->removeBan($banMask);
                        }
                    }
                    break;
                    
                case 'e': // Ban-Exception
                    if (isset($params[$paramIndex])) {
                        $exceptionMask = $params[$paramIndex++];
                        if ($addMode) {
                            $channel->addExempt($exceptionMask);
                        } else {
                            $channel->removeExempt($exceptionMask);
                        }
                    }
                    break;
                    
                case 'I': // Invite-Exception
                    if (isset($params[$paramIndex])) {
                        $inviteExceptionMask = $params[$paramIndex++];
                        if ($addMode) {
                            $channel->addInviteExempt($inviteExceptionMask);
                        } else {
                            $channel->removeInviteExempt($inviteExceptionMask);
                        }
                    }
                    break;
            }
        }
    }
    
    /**
     * Verarbeitet eine Benutzermodus-Änderung lokal
     * 
     * @param \PhpIrcd\Models\User $user Der betroffene Benutzer
     * @param string $modes Die zu ändernden Modi
     */
    private function processUserModeChange(\PhpIrcd\Models\User $user, string $modes): void {
        $addMode = true;  // Standardmäßig Modi hinzufügen
        
        for ($i = 0; $i < strlen($modes); $i++) {
            $mode = $modes[$i];
            
            if ($mode === '+') {
                $addMode = true;
                continue;
            } elseif ($mode === '-') {
                $addMode = false;
                continue;
            }
            
            switch ($mode) {
                case 'i': // Invisible
                    $user->setInvisible($addMode);
                    break;
                    
                case 'w': // Wallops
                    $user->setWallops($addMode);
                    break;
                    
                case 'o': // Operator (kann nur entfernt werden)
                    if (!$addMode) {
                        $user->setOper(false);
                    }
                    break;
                    
                case 'r': // Registered nick (kann nur hinzugefügt werden)
                    if ($addMode) {
                        $user->setRegistered(true);
                    }
                    break;
            }
        }
    }
    
    /**
     * Verarbeitet den TOPIC-Befehl eines Servers
     * 
     * @param ServerLink $serverLink Der Server-Link
     * @param string $prefix Das Befehlspräfix
     * @param array $parts Die Befehlsteile
     */
    private function handleTopicCommand(ServerLink $serverLink, string $prefix, array $parts): void {
        // Überprüfen, ob genügend Parameter vorhanden sind
        if (!isset($parts[1])) {
            return;
        }
        
        // Wenn kein Prefix vorhanden ist, können wir nichts tun
        if (empty($prefix)) {
            return;
        }
        
        // Kanalname extrahieren
        $channelName = $parts[1];
        
        // Kanal finden
        $channel = $this->server->getChannel($channelName);
        if ($channel === null) {
            return; // Kanal existiert nicht
        }
        
        // Benutzerinformationen aus dem Prefix extrahieren (nick!user@host)
        $nickInfo = explode('!', $prefix, 2);
        $nick = $nickInfo[0];
        
        // Überprüfen, ob der Befehl ein Topic setzt oder abfragt
        if (count($parts) > 2) {
            // Topic setzen
            $topic = '';
            for ($i = 2; $i < count($parts); $i++) {
                if ($parts[$i][0] === ':') {
                    $topic = substr(implode(' ', array_slice($parts, $i)), 1);
                    break;
                }
            }
            
            // Topic im Kanal setzen
            $channel->setTopic($topic);
            $channel->setTopicSetBy($nick);
            $channel->setTopicTime(time());
            
            // TOPIC-Befehl erstellen
            $topicCommand = ":{$prefix} TOPIC {$channelName} :{$topic}";
            
            // Topic-Änderung an alle lokalen Benutzer im Kanal senden
            foreach ($channel->getUsers() as $user) {
                if (!$user->isRemoteUser()) {
                    $user->send($topicCommand);
                }
            }
            
            // Topic-Änderung an alle anderen Server weiterleiten (außer dem Ursprungsserver)
            $this->server->propagateToServers($topicCommand, $serverLink->getName());
        } else {
            // Topic abfragen - wird normalerweise vom Server direkt beantwortet, nicht weitergeleitet
        }
    }
    
    /**
     * Verarbeitet PRIVMSG und NOTICE-Befehle eines Servers
     * 
     * @param ServerLink $serverLink Der Server-Link
     * @param string $prefix Das Befehlspräfix
     * @param string $commandType Der Befehlstyp (PRIVMSG oder NOTICE)
     * @param array $parts Die Befehlsteile
     */
    private function handleMessageCommand(ServerLink $serverLink, string $prefix, string $commandType, array $parts): void {
        // Überprüfen, ob genügend Parameter vorhanden sind
        if (!isset($parts[1]) || !isset($parts[2])) {
            return;
        }
        
        // Wenn kein Prefix vorhanden ist, können wir nichts tun
        if (empty($prefix)) {
            return;
        }
        
        // Ziel der Nachricht extrahieren
        $target = $parts[1];
        
        // Nachrichteninhalt extrahieren (beginnt mit :)
        $message = '';
        for ($i = 2; $i < count($parts); $i++) {
            if ($parts[$i][0] === ':') {
                $message = substr(implode(' ', array_slice($parts, $i)), 1);
                break;
            }
        }
        
        if (empty($message)) {
            return;
        }
        
        // Benutzerinformationen aus dem Prefix extrahieren (nick!user@host)
        $nickInfo = explode('!', $prefix, 2);
        $nick = $nickInfo[0];
        
        // Erstelle die vollständige Nachricht für die Weiterleitung
        $fullCommand = ":{$prefix} {$commandType} {$target} :{$message}";
        
        // Überprüfe, ob das Ziel ein Kanal ist
        if ($target[0] === '#' || $target[0] === '&') {
            // Nachricht an einen Kanal
            $channel = $this->server->getChannel($target);
            
            if ($channel !== null) {
                // Nachricht an alle lokalen Benutzer im Kanal senden
                foreach ($channel->getUsers() as $user) {
                    // Nicht an Remote-Benutzer oder den Sender selbst
                    if (!$user->isRemoteUser() && $user->getNick() !== $nick) {
                        $user->send($fullCommand);
                    }
                }
                
                // Nachricht an alle anderen Server weiterleiten (außer dem Ursprungsserver)
                $this->server->propagateToServers($fullCommand, $serverLink->getName());
            }
        } else {
            // Nachricht an einen Benutzer
            foreach ($this->server->getUsers() as $user) {
                if ($user->getNick() === $target && !$user->isRemoteUser()) {
                    // Wenn der Benutzer lokal ist, Nachricht zustellen
                    $user->send($fullCommand);
                    return;
                }
            }
            
            // Benutzer ist nicht lokal, an andere Server weiterleiten (außer dem Ursprungsserver)
            $this->server->propagateToServers($fullCommand, $serverLink->getName());
        }
    }
    
    /**
     * Trennt einen Server-Link
     * 
     * @param ServerLink $serverLink Der zu trennende Server-Link
     * @param string $reason Der Grund für die Trennung
     */
    private function disconnectServerLink(ServerLink $serverLink, string $reason): void {
        // Log-Eintrag erstellen
        $this->server->getLogger()->info("Server-Link zu {$serverLink->getName()} getrennt: {$reason}");
        
        // Verbindung schließen
        $serverLink->disconnect();
    }
    
    /**
     * Trennt die Verbindung zu einem Server (öffentliche Methode für SQUIT)
     * 
     * @param \PhpIrcd\Models\ServerLink $serverLink Der zu trennende Server-Link
     * @param string $reason Der Grund für die Trennung
     */
    public function disconnectServer(\PhpIrcd\Models\ServerLink $serverLink, string $reason = "Server disconnected"): void {
        $this->disconnectServerLink($serverLink, $reason);
        
        // Server-Link aus der internen Liste entfernen
        $key = array_search($serverLink, $this->links, true);
        if ($key !== false) {
            unset($this->links[$key]);
            $this->links = array_values($this->links); // Array neu indizieren
        }
    }
    
    /**
     * Sendet Pings an alle verbundenen Server
     */
    private function pingServers(): void {
        $config = $this->server->getConfig();
        
        foreach ($this->links as $serverLink) {
            if ($serverLink->isConnected()) {
                $serverLink->send("PING :{$config['name']}");
            }
        }
    }
    
    /**
     * Gibt alle Server-Links zurück
     * 
     * @return array Alle Server-Links
     */
    public function getServerLinks(): array {
        return $this->links;
    }
    
    /**
     * Stellt eine ausgehende Verbindung zu einem anderen Server her
     * 
     * @param string $host Der Hostname oder die IP-Adresse des Zielservers
     * @param int $port Der Port des Zielservers
     * @param string $password Das Passwort für die Verbindung
     * @param bool $useSSL Ob SSL für die Verbindung verwendet werden soll
     * @return bool Ob die Verbindung erfolgreich hergestellt wurde
     */
    public function connectToServer(string $host, int $port, string $password, bool $useSSL = false): bool {
        $config = $this->server->getConfig();
        
        try {
            if ($useSSL) {
                // SSL-Kontext erstellen
                $context = stream_context_create([
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    ]
                ]);
                
                // Sichere Verbindung herstellen
                $socket = stream_socket_client(
                    "ssl://{$host}:{$port}", 
                    $errno, 
                    $errstr, 
                    30, 
                    STREAM_CLIENT_CONNECT,
                    $context
                );
                
                if (!$socket) {
                    $this->server->getLogger()->error("Fehler beim Verbinden zu Server {$host}:{$port}: {$errstr} ({$errno})");
                    return false;
                }
                
                $serverLink = new ServerLink($socket, $host, $password, true);
            } else {
                // Normalen Socket erstellen
                $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                if (!$socket) {
                    $errorCode = socket_last_error();
                    $errorMsg = socket_strerror($errorCode);
                    $this->server->getLogger()->error("Fehler beim Erstellen des Socket: {$errorCode} - {$errorMsg}");
                    return false;
                }
                
                // Verbindung herstellen
                $result = socket_connect($socket, $host, $port);
                if (!$result) {
                    $errorCode = socket_last_error($socket);
                    $errorMsg = socket_strerror($errorCode);
                    $this->server->getLogger()->error("Fehler beim Verbinden zu Server {$host}:{$port}: {$errorCode} - {$errorMsg}");
                    socket_close($socket);
                    return false;
                }
                
                $serverLink = new ServerLink($socket, $host, $password);
            }
            
            // Server-Link hinzufügen
            $this->addServerLink($serverLink);
            
            // PASS und SERVER-Befehle senden
            $serverLink->send("PASS {$password}");
            $serverLink->send("SERVER {$config['name']} 1 :{$config['description']}");
            
            return true;
        } catch (\Exception $e) {
            $this->server->getLogger()->error("Ausnahme beim Verbinden zu Server {$host}:{$port}: " . $e->getMessage());
            return false;
        }
    }
}