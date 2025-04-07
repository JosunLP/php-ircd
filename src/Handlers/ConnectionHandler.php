<?php

namespace PhpIrcd\Handlers;

use PhpIrcd\Core\Server;
use PhpIrcd\Models\User;
use PhpIrcd\Models\Channel;

class ConnectionHandler {
    private $server;
    private $commandHandlers = [];
    private $inactivityTimeout = 240; // 4 Minuten Inaktivitätstimeout
    
    /**
     * Konstruktor
     * 
     * @param Server $server Die Server-Instanz
     */
    public function __construct(Server $server) {
        $this->server = $server;
        $this->initCommandHandlers();
    }
    
    /**
     * Initialisiert die Befehlshandler
     */
    private function initCommandHandlers(): void {
        // Befehlshandler registrieren
        $this->registerCommandHandler('NICK', new \PhpIrcd\Commands\NickCommand($this->server));
        $this->registerCommandHandler('USER', new \PhpIrcd\Commands\UserCommand($this->server));
        $this->registerCommandHandler('QUIT', new \PhpIrcd\Commands\QuitCommand($this->server));
        $this->registerCommandHandler('JOIN', new \PhpIrcd\Commands\JoinCommand($this->server));
        $this->registerCommandHandler('PART', new \PhpIrcd\Commands\PartCommand($this->server));
        $this->registerCommandHandler('PRIVMSG', new \PhpIrcd\Commands\PrivmsgCommand($this->server));
        $this->registerCommandHandler('NOTICE', new \PhpIrcd\Commands\NoticeCommand($this->server));
        $this->registerCommandHandler('MODE', new \PhpIrcd\Commands\ModeCommand($this->server));
        $this->registerCommandHandler('TOPIC', new \PhpIrcd\Commands\TopicCommand($this->server));
        $this->registerCommandHandler('INVITE', new \PhpIrcd\Commands\InviteCommand($this->server));
        $this->registerCommandHandler('KICK', new \PhpIrcd\Commands\KickCommand($this->server));
        $this->registerCommandHandler('WHOIS', new \PhpIrcd\Commands\WhoisCommand($this->server));
        $this->registerCommandHandler('PING', new \PhpIrcd\Commands\PingCommand($this->server));
        $this->registerCommandHandler('PONG', new \PhpIrcd\Commands\PongCommand($this->server));
        $this->registerCommandHandler('OPER', new \PhpIrcd\Commands\OperCommand($this->server));
        $this->registerCommandHandler('AWAY', new \PhpIrcd\Commands\AwayCommand($this->server));
        $this->registerCommandHandler('LIST', new \PhpIrcd\Commands\ListCommand($this->server));
        $this->registerCommandHandler('NAMES', new \PhpIrcd\Commands\NamesCommand($this->server));
        $this->registerCommandHandler('WHO', new \PhpIrcd\Commands\WhoCommand($this->server));
        $this->registerCommandHandler('MOTD', new \PhpIrcd\Commands\MotdCommand($this->server));
    }
    
    /**
     * Registriert einen Befehlshandler
     * 
     * @param string $command Der Befehlsname
     * @param object $handler Der Handler
     */
    public function registerCommandHandler(string $command, $handler): void {
        $this->commandHandlers[strtoupper($command)] = $handler;
    }
    
    /**
     * Akzeptiert neue Verbindungen
     * 
     * @param resource $serverSocket Der Server-Socket
     */
    public function acceptNewConnections($serverSocket): void {
        $newSocket = @socket_accept($serverSocket);
        if ($newSocket !== false) {
            // IP-Adresse des neuen Benutzers ermitteln
            socket_getpeername($newSocket, $ip);
            
            // Neuen Benutzer erstellen
            $user = new User($newSocket, $ip);
            
            // Willkommensnachrichten senden
            $config = $this->server->getConfig();
            $user->send(":" . $config['name'] . " NOTICE AUTH :*** Looking up your hostname...");
            $user->send(":" . $config['name'] . " NOTICE AUTH :*** Found your hostname");
            
            // Benutzer zum Server hinzufügen
            $this->server->addUser($user);
        }
    }
    
    /**
     * Behandelt bestehende Verbindungen
     */
    public function handleExistingConnections(): void {
        $users = $this->server->getUsers();
        
        foreach ($users as $user) {
            // Zeitschrift des letzten Aktivitäts-Checks
            $lastActivityCheck = time();
            
            // Inaktive Verbindungen prüfen und trennen
            if ($user->isInactive($this->inactivityTimeout)) {
                $this->disconnectUser($user, "Ping timeout: {$this->inactivityTimeout} seconds");
                continue;
            }
            
            // Daten vom Benutzer lesen
            $data = $user->read();
            
            // Verbindung geschlossen oder Fehler
            if ($data === false) {
                $this->disconnectUser($user, "Connection closed");
                continue;
            }
            
            // Kein vollständiger Befehl verfügbar
            if ($data === '') {
                continue;
            }
            
            // Aktivität registrieren
            $user->updateActivity();
            
            // Befehl verarbeiten
            $this->processCommand($user, $data);
        }
        
        // Ping an Benutzer senden, die es brauchen
        $this->pingUsers();
    }
    
    /**
     * Verarbeitet einen empfangenen Befehl
     * 
     * @param User $user Der sendende Benutzer
     * @param string $data Die empfangenen Daten
     */
    private function processCommand(User $user, string $data): void {
        // Befehl parsen
        $parts = explode(' ', $data);
        $command = strtoupper($parts[0]);
        
        // Befehl behandeln
        if (isset($this->commandHandlers[$command])) {
            $this->commandHandlers[$command]->execute($user, $parts);
        } else {
            // Unbekannter Befehl
            $config = $this->server->getConfig();
            $nick = $user->getNick() ?? '*';
            $user->send(":{$config['name']} 421 {$nick} {$command} :Unknown command");
        }
    }
    
    /**
     * Sendet Pings an Benutzer, die es benötigen
     */
    private function pingUsers(): void {
        $users = $this->server->getUsers();
        $config = $this->server->getConfig();
        $currentTime = time();
        
        foreach ($users as $user) {
            // Wenn der Benutzer seit 90 Sekunden inaktiv ist, einen Ping senden
            if ($currentTime - $user->getLastActivity() > 90) {
                $user->send(":{$config['name']} PING :{$config['name']}");
            }
        }
    }
    
    /**
     * Trennt einen Benutzer
     * 
     * @param User $user Der zu trennende Benutzer
     * @param string $reason Der Grund für die Trennung
     */
    public function disconnectUser(User $user, string $reason): void {
        // Alle Kanäle benachrichtigen, in denen der Benutzer ist
        $channels = $this->server->getChannels();
        foreach ($channels as $channel) {
            if ($channel->hasUser($user)) {
                // Nachricht an alle anderen Benutzer im Kanal senden
                $quitMessage = ":{$user->getNick()}!{$user->getIdent()}@{$user->getCloak()} QUIT :{$reason}";
                foreach ($channel->getUsers() as $channelUser) {
                    if ($channelUser !== $user) {
                        $channelUser->send($quitMessage);
                    }
                }
                
                // Benutzer aus dem Kanal entfernen
                $channel->removeUser($user);
                
                // Wenn der Kanal leer ist, ihn entfernen
                if (count($channel->getUsers()) === 0) {
                    $this->server->removeChannel($channel->getName());
                }
            }
        }
        
        // Benutzer-Socket schließen
        $user->disconnect();
        
        // Benutzer vom Server entfernen
        $this->server->removeUser($user);
    }
}