<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class UserCommand extends CommandBase {
    /**
     * Executes the USER command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Check if the user is already registered
        if ($user->getIdent() !== null) {
            $config = $this->server->getConfig();
            $nick = $user->getNick() ?? '*';
            $user->send(":{$config['name']} 462 {$nick} :You may not reregister");
            return;
        }
        
        // Check if enough parameters are provided
        if (count($args) < 5) {
            $this->sendError($user, 'USER', 'Not enough parameters', 461);
            return;
        }
        
        // Extract parameters
        $ident = $args[1];
        $realname = $this->getMessagePart($args, 4);
        
        // If no ':' is present in the realname, concatenate the parameters
        if ($realname === '') {
            $realname = $args[4];
        }
        
        // Set data in the user
        $user->setIdent($ident);
        $user->setRealname($realname);
        
        // If the user is now registered, send the welcome message
        if ($user->isRegistered()) {
            $this->sendWelcomeMessage($user);
        }
    }
    
    /**
     * Sends the welcome message to the user
     * 
     * @param User $user The user
     */
    private function sendWelcomeMessage(User $user): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // Numeric replies 001-004
        $user->send(":{$config['name']} 001 {$nick} :Welcome to the {$config['net']} IRC Network {$nick}!{$user->getIdent()}@{$user->getHost()}");
        $user->send(":{$config['name']} 002 {$nick} :Your host is {$config['name']}, running version Danoserv {$config['version']}");
        $user->send(":{$config['name']} 003 {$nick} :This server was created " . date('D M d H:i:s Y', $this->server->getStartTime()));
        $user->send(":{$config['name']} 004 {$nick} {$config['name']} Danoserv {$config['version']} iowghraAsORTVSxNCWqBzvdHtGp lvhopsmntikrRcaqOALQbSeIKVfMCuzNTGj");
        
        // ISUPPORT (005) Nachrichten - Verbessert und auf tatsächliche Server-Fähigkeiten abgestimmt
        // Erste Zeile der ISUPPORT-Nachrichten
        $user->send(":{$config['name']} 005 {$nick} CHANTYPES=# EXCEPTS INVEX CHANMODES=eIbq,k,flj,CFLMPQScgimnprstuz CHANLIMIT=#:20 PREFIX=(ov)@+ MAXLIST=bqeI:100 MODES=4 NETWORK={$config['net']} KNOCK :are supported by this server");
        
        // Zweite Zeile der ISUPPORT-Nachrichten
        $user->send(":{$config['name']} 005 {$nick} CALLERID=g SAFELIST ELIST=U STATUSMSG=@+ CHANNELLEN=32 NICKLEN=30 TOPICLEN=307 KICKLEN=307 AWAYLEN=307 SILENCE=15 :are supported by this server");
        
        // Dritte Zeile mit zusätzlichen IRCv3-Features und Unterstützung
        $user->send(":{$config['name']} 005 {$nick} CASEMAPPING=rfc1459 CHARSET=utf-8 MAXTARGETS=4 WATCH=128 NAMESX UHNAMES USERIP WALLCHOPS FNC CHATHISTORY=100 :are supported by this server");
        
        // Vierte Zeile für die erweiterten IRCv3 Capabilities
        $capsSupported = implode(' ', $this->server->getSupportedCapabilities());
        $user->send(":{$config['name']} 005 {$nick} CAP={$capsSupported} :are supported by this server");
        
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
        $this->sendMotd($user);
    }
    
    /**
     * Sends the MOTD (Message of the Day) to the user
     * 
     * @param User $user The user
     */
    private function sendMotd(User $user): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        $user->send(":{$config['name']} 375 {$nick} :- {$config['name']} Message of the Day -");
        
        // Split MOTD into lines and send
        $motdLines = explode($config['line_ending_conf'], $config['motd']);
        foreach ($motdLines as $line) {
            $user->send(":{$config['name']} 372 {$nick} :- {$line}");
        }
        
        $user->send(":{$config['name']} 376 {$nick} :End of /MOTD command.");
    }
}