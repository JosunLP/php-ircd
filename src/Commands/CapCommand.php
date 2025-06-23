<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class CapCommand extends CommandBase {
    // Liste der unterstützten Capabilities
    private $supportedCapabilities = [
        'multi-prefix' => true,      // Mehrere Präfixe für Benutzer im Kanal
        'away-notify' => true,       // Benachrichtigung wenn Benutzer away-Status ändert
        'server-time' => true,       // Zeitstempel für Nachrichten
        'batch' => true,             // Nachrichtenbündelung
        'message-tags' => true,      // Tags in Nachrichten
        'echo-message' => true,      // Echo der eigenen Nachrichten
        'invite-notify' => true,     // Benachrichtigungen über Einladungen
        'extended-join' => true,     // Erweiterte JOIN-Befehle mit Realname
        'userhost-in-names' => true, // Vollständige Hostmasken in NAMES-Liste
        'chathistory' => true,       // Abruf der Kanalhistorie
        'account-notify' => true,    // Kontoauthentifizierungsänderungen
        'account-tag' => true,       // Account-Tags in Nachrichten
        'cap-notify' => true,        // Benachrichtigungen über CAP-Änderungen
        'chghost' => true,           // Host-Änderungsbenachrichtigungen
        'sasl' => true               // SASL-Authentifizierung
    ];

    /**
     * Executes the CAP command (IRCv3 capability negotiation)
     *
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        if (count($args) < 2) {
            $user->send(":{$this->server->getConfig()['name']} 461 " . ($user->getNick() ?? '*') . " CAP :Not enough parameters");
            return;
        }

        $subcommand = strtoupper($args[1]);

        switch ($subcommand) {
            case 'LS':
                $this->handleLS($user);
                break;
            case 'LIST':
                $this->handleLIST($user);
                break;
            case 'REQ':
                $this->handleREQ($user, $args);
                break;
            case 'END':
                $this->handleEND($user);
                break;
            case 'CLEAR':
                $this->handleCLEAR($user);
                break;
            default:
                $user->send(":{$this->server->getConfig()['name']} 410 " . ($user->getNick() ?? '*') . " {$subcommand} :Invalid CAP subcommand");
                break;
        }
    }

    /**
     * Processes the CAP LS command
     *
     * @param User $user The executing user
     */
    private function handleLS(User $user): void {
        try {
            $nick = $user->getNick() ?? '*';
            $config = $this->server->getConfig();
            $version = isset($args[1]) ? (int)$args[1] : 301;

            // Get available capabilities directly from server
            $serverCaps = $this->server->getSupportedCapabilities();
            $capabilities = [];
            foreach ($serverCaps as $cap => $enabled) {
                if ($enabled) {
                    // For SASL, add mechanisms if version >= 302
                    if ($cap === 'sasl' && $version >= 302 && !empty($config['sasl_mechanisms'])) {
                        $capabilities[] = 'sasl=' . implode(',', $config['sasl_mechanisms']);
                    } else {
                        $capabilities[] = $cap;
                    }
                }
            }

            if ($version >= 302) {
                // IRCv3.2 behavior: Send multi-line CAP LS if needed
                $maxLineLength = 450; // Leave room for command prefix
                $currentLine = '';

                foreach ($capabilities as $cap) {
                    // Check if adding this cap would exceed the line length
                    if (strlen($currentLine) + strlen($cap) + 1 > $maxLineLength) {
                        // Send current line and start a new one
                        $user->send(":{$config['name']} CAP {$nick} LS * :{$currentLine}");
                        $currentLine = $cap;
                    } else {
                        // Append to current line
                        if ($currentLine !== '') {
                            $currentLine .= ' ' . $cap;
                        } else {
                            $currentLine = $cap;
                        }
                    }
                }

                // Send final line, with * replaced by empty string to indicate end of list
                $user->send(":{$config['name']} CAP {$nick} LS :{$currentLine}");
            } else {
                // IRCv3.1 behavior: Send single line
                $capsString = implode(' ', $capabilities);
                $user->send(":{$config['name']} CAP {$nick} LS :{$capsString}");
            }

            // Mark that capability negotiation is in progress
            $user->setCapabilityNegotiationInProgress(true);
        } catch (\Exception $e) {
            $this->server->getLogger()->error("Error in CAP LS command: " . $e->getMessage());
            $config = $this->server->getConfig();
            $user->send(":{$config['name']} CAP {$nick} LS :error");
        }
    }

    /**
     * Processes the CAP LIST command
     *
     * @param User $user The executing user
     */
    private function handleLIST(User $user): void {
        try {
            $nick = $user->getNick() ?? '*';
            $config = $this->server->getConfig();

            // Get user's active capabilities
            $activeCaps = $user->getCapabilities();
            $capsString = implode(' ', $activeCaps);

            // Send list of active capabilities
            $user->send(":{$config['name']} CAP {$nick} LIST :{$capsString}");
        } catch (\Exception $e) {
            $this->server->getLogger()->error("Error in CAP LIST command: " . $e->getMessage());
            $config = $this->server->getConfig();
            $user->send(":{$config['name']} CAP {$nick} LIST :error");
        }
    }

    /**
     * Processes the CAP REQ command
     *
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    private function handleREQ(User $user, array $args): void {
        try {
            $nick = $user->getNick() ?? '*';
            $config = $this->server->getConfig();

            // CAP REQ must have parameters
            if (!isset($args[2])) {
                $user->send(":{$config['name']} CAP {$nick} NAK :No capabilities requested");
                return;
            }

            // Extract requested capabilities
            $requestedCapsStr = substr(implode(' ', array_slice($args, 2)), 1); // Remove leading colon
            $requestedCaps = explode(' ', $requestedCapsStr);

            // Check if all requested capabilities are available
            $serverCaps = $this->server->getSupportedCapabilities();
            $availableCaps = [];
            foreach ($serverCaps as $cap => $enabled) {
                if ($enabled) {
                    $availableCaps[] = $cap;
                }
            }
            $allAvailable = true;

            foreach ($requestedCaps as $cap) {
                // Ignore empty entries
                if (empty($cap)) continue;

                // Remove -/+ prefix for checking availability
                $cleanCap = $cap;
                if ($cap[0] === '-' || $cap[0] === '+') {
                    $cleanCap = substr($cap, 1);
                }

                // Check if capability is available
                if (!in_array($cleanCap, array_map(function($c) {
                    // If a cap contains '=', just check the part before =
                    $parts = explode('=', $c);
                    return $parts[0];
                }, $availableCaps))) {
                    $allAvailable = false;
                    break;
                }
            }

            // If all capabilities are available, add/remove them and acknowledge
            if ($allAvailable) {
                foreach ($requestedCaps as $cap) {
                    // Skip empty entries
                    if (empty($cap)) continue;

                    $remove = false;
                    $add = true;

                    // Check for remove/add prefix
                    if ($cap[0] === '-') {
                        $remove = true;
                        $add = false;
                        $cap = substr($cap, 1);
                    } else if ($cap[0] === '+') {
                        $cap = substr($cap, 1);
                    }

                    // Extract base capability without value part
                    $baseCap = explode('=', $cap)[0];

                    // Add or remove capability
                    if ($add) {
                        $user->addCapability($baseCap);
                    } else if ($remove) {
                        $user->removeCapability($baseCap);
                    }
                }

                $user->send(":{$config['name']} CAP {$nick} ACK :{$requestedCapsStr}");

                // If SASL requested, inform client and set the flag
                if (in_array('sasl', $requestedCaps)) {
                    $this->server->getLogger()->info("User {$nick} ({$user->getIp()}) requested SASL authentication");

                    // Markiere, dass SASL-Authentifizierung möglich ist
                    if (!$user->isSaslAuthenticated()) {
                        // Der User kann nun AUTHENTICATE verwenden
                        $user->setUndergoing302Negotiation(true);
                    }
                }
            } else {
                // Some capabilities are not supported
                $user->send(":{$config['name']} CAP {$nick} NAK :{$requestedCapsStr}");
            }
        } catch (\Exception $e) {
            $this->server->getLogger()->error("Error in CAP REQ command: " . $e->getMessage());
            $config = $this->server->getConfig();
            $user->send(":{$config['name']} CAP {$nick} NAK :{$requestedCapsStr}");
        }
    }

    /**
     * Processes the CAP END command
     *
     * @param User $user The executing user
     */
    private function handleEND(User $user): void {
        try {
            $nick = $user->getNick() ?? '*';
            $config = $this->server->getConfig();

            // End capability negotiation
            $user->setCapabilityNegotiationInProgress(false);

            // Send acknowledgment that capability negotiation has ended
            $user->send(":{$config['name']} CAP {$nick} END");

            // If the user has registered with the server, send welcome messages now
            if ($user->isRegistered()) {
                // Send welcome messages directly
                $this->sendWelcomeMessages($user, $config);
            }
        } catch (\Exception $e) {
            $this->server->getLogger()->error("Error in CAP END command: " . $e->getMessage());
        }
    }

    /**
     * Sends welcome messages to the user
     *
     * @param User $user The user
     * @param array $config The server configuration
     */
    private function sendWelcomeMessages(User $user, array $config): void {
        $nick = $user->getNick();

        // Send hostname lookup messages
        $user->send(":{$config['name']} NOTICE AUTH :*** Looking up your hostname...");
        $user->send(":{$config['name']} NOTICE AUTH :*** Found your hostname");

        // Numeric replies 001-004
        $user->send(":{$config['name']} 001 {$nick} :Welcome to the {$config['net']} IRC Network {$nick}!{$user->getIdent()}@{$user->getHost()}");
        $user->send(":{$config['name']} 002 {$nick} :Your host is {$config['name']}, running version Danoserv {$config['version']}");
        $user->send(":{$config['name']} 003 {$nick} :This server was created " . date('D M d H:i:s Y', $this->server->getStartTime()));
        $user->send(":{$config['name']} 004 {$nick} {$config['name']} Danoserv {$config['version']} iowghraAsORTVSxNCWqBzvdHtGp lvhopsmntikrRcaqOALQbSeIKVfMCuzNTGj");

        // ISUPPORT (005) messages - Improved version with all supported features
        $user->send(":{$config['name']} 005 {$nick} CHANTYPES=# EXCEPTS INVEX CHANMODES=eIbq,k,flj,CFLMPQSTcgimnprstz CHANLIMIT=#:100 PREFIX=(ov)@+ MAXLIST=beI:100 MODES=4 NETWORK={$config['network_name']} STATUSMSG=@+ CALLERID=g CASEMAPPING=rfc1459 :are supported by this server");
        $user->send(":{$config['name']} 005 {$nick} CHARSET=UTF-8 FNC NICKLEN=30 CHANNELLEN=50 TOPICLEN=390 DEAF=D TARGMAX=NAMES:1,LIST:1,KICK:1,WHOIS:1,PRIVMSG:4,NOTICE:4,ACCEPT:,MONITOR: EXTBAN=$,ajrxz :are supported by this server");
        $user->send(":{$config['name']} 005 {$nick} SAFELIST ELIST=CTU CPRIVMSG CNOTICE KNOCK MONITOR=100 WHOX ETRACE HELP :are supported by this server");

        // Statistiken senden
        $userCount = count($this->server->getUsers());
        $channelCount = count($this->server->getChannels());
        $operCount = 0;

        foreach ($this->server->getUsers() as $serverUser) {
            if ($serverUser->isOper()) {
                $operCount++;
            }
        }

        $user->send(":{$config['name']} 251 {$nick} :There are {$userCount} users and 0 invisible on 1 servers");
        $user->send(":{$config['name']} 252 {$nick} {$operCount} :operator(s) online");
        $user->send(":{$config['name']} 254 {$nick} {$channelCount} :channels formed");
        $user->send(":{$config['name']} 255 {$nick} :I have {$userCount} clients and 0 servers");
        $user->send(":{$config['name']} 265 {$nick} :Current Local Users: {$userCount}  Max: {$userCount}");
        $user->send(":{$config['name']} 266 {$nick} :Current Global Users: {$userCount}  Max: {$userCount}");

        // Send WATCH notifications that this user is now online
        $this->server->broadcastWatchNotifications($user, true);

        // Send MOTD
        $this->sendMotd($user, $config);
    }

    /**
     * Sends the MOTD (Message of the Day) to the user
     *
     * @param User $user The user
     * @param array $config The server configuration
     */
    private function sendMotd(User $user, array $config): void {
        $nick = $user->getNick();

        $user->send(":{$config['name']} 375 {$nick} :- {$config['name']} Message of the Day -");

        // Split MOTD into lines and send
        $motdLines = explode($config['line_ending_conf'], $config['motd']);
        foreach ($motdLines as $line) {
            $user->send(":{$config['name']} 372 {$nick} :- {$line}");
        }

        $user->send(":{$config['name']} 376 {$nick} :End of /MOTD command.");
    }

    /**
     * Teilt eine Liste von Capabilities in Blöcke auf, die nicht länger als 400 Zeichen sind
     * Dies ist notwendig, um IRC-Protokoll-Limits zu respektieren
     *
     * @param array $capabilities Die zu teilende Capability-Liste
     * @return array Ein Array von Capability-Listen-Strings
     */
    private function splitCapabilityList(array $capabilities): array {
        $blocks = [];
        $currentBlock = '';

        foreach ($capabilities as $cap) {
            if (strlen($currentBlock . ' ' . $cap) > 400) {
                $blocks[] = trim($currentBlock);
                $currentBlock = $cap;
            } else {
                $currentBlock .= (empty($currentBlock) ? '' : ' ') . $cap;
            }
        }

        if (!empty($currentBlock)) {
            $blocks[] = trim($currentBlock);
        }

        return $blocks;
    }

    /**
     * Processes the CAP CLEAR command
     *
     * @param User $user The executing user
     */
    private function handleCLEAR(User $user): void {
        try {
            $nick = $user->getNick() ?? '*';
            $config = $this->server->getConfig();

            // Clear all active capabilities
            $oldCaps = $user->getCapabilities();
            $user->clearCapabilities();
            $capsString = implode(' ', $oldCaps);

            if (!empty($capsString)) {
                $user->send(":{$config['name']} CAP {$nick} ACK :-{$capsString}");
            } else {
                $user->send(":{$config['name']} CAP {$nick} ACK :*");
            }
        } catch (\Exception $e) {
            $this->server->getLogger()->error("Error in CAP CLEAR command: " . $e->getMessage());
            $config = $this->server->getConfig();
            $user->send(":{$config['name']} CAP {$nick} ACK :*");
        }
    }
}
