<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class UserCommand extends CommandBase {
    /**
     * Führt den USER-Befehl aus
     * 
     * @param User $user Der ausführende Benutzer
     * @param array $args Die Befehlsargumente
     */
    public function execute(User $user, array $args): void {
        // Prüfen, ob der Benutzer bereits registriert ist
        if ($user->getIdent() !== null) {
            $config = $this->server->getConfig();
            $nick = $user->getNick() ?? '*';
            $user->send(":{$config['name']} 462 {$nick} :You may not reregister");
            return;
        }
        
        // Prüfen, ob genügend Parameter vorhanden sind
        if (count($args) < 5) {
            $this->sendError($user, 'USER', 'Not enough parameters', 461);
            return;
        }
        
        // Parameter extrahieren
        $ident = $args[1];
        $realname = $this->getMessagePart($args, 4);
        
        // Wenn kein ':' im Realname enthalten ist, die Parameter zusammenfügen
        if ($realname === '') {
            $realname = $args[4];
        }
        
        // Daten im Benutzer setzen
        $user->setIdent($ident);
        $user->setRealname($realname);
        
        // Wenn der Benutzer jetzt registriert ist, die Willkommensnachricht senden
        if ($user->isRegistered()) {
            $this->sendWelcomeMessage($user);
        }
    }
    
    /**
     * Sendet die Willkommensnachricht an den Benutzer
     * 
     * @param User $user Der Benutzer
     */
    private function sendWelcomeMessage(User $user): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // Numerische Antworten 001-004
        $user->send(":{$config['name']} 001 {$nick} :Welcome to the {$config['net']} IRC Network {$nick}!{$user->getIdent()}@{$user->getHost()}");
        $user->send(":{$config['name']} 002 {$nick} :Your host is {$config['name']}, running version Danoserv {$config['version']}");
        $user->send(":{$config['name']} 003 {$nick} :This server was created " . date('D M d H:i:s Y'));
        $user->send(":{$config['name']} 004 {$nick} {$config['name']} Danoserv {$config['version']} iowghraAsORTVSxNCWqBzvdHtGp lvhopsmntikrRcaqOALQbSeIKVfMCuzNTGj");
        
        // ISUPPORT (005) Nachrichten
        $user->send(":{$config['name']} 005 {$nick} CMDS=KNOCK,MAP,DCCALLOW,USERIP NAMESX SAFELIST HCN MAXCHANNELS=10 CHANLIMIT=#:10 MAXLIST=b:60,e:60,I:60 NICKLEN=30 CHANNELLEN=32 TOPICLEN=307 KICKLEN=307 AWAYLEN=307 MAXTARGETS=20 :are supported by this server");
        $user->send(":{$config['name']} 005 {$nick} WALLCHOPS WATCH=128 SILENCE=15 MODES=12 CHANTYPES=# PREFIX=(qaohv)~&@%+ CHANMODES=beI,kfL,lj,psmntirRcOAQKVCuzNSMTG NETWORK={$config['net']} CASEMAPPING=ascii EXTBAN=~,cqnr ELIST=MNUCT STATUSMSG=~&@%+ EXCEPTS :are supported by this server");
        $user->send(":{$config['name']} 005 {$nick} INVEX :are supported by this server");
        
        // Statistiken
        $userCount = count($this->server->getUsers());
        $channelCount = count($this->server->getChannels());
        
        $user->send(":{$config['name']} 251 {$nick} :There are {$userCount} users and 0 invisible on 1 servers");
        $user->send(":{$config['name']} 252 {$nick} 1 :operator(s) online");
        $user->send(":{$config['name']} 254 {$nick} {$channelCount} :channels formed");
        $user->send(":{$config['name']} 255 {$nick} :I have {$userCount} clients and 0 servers");
        $user->send(":{$config['name']} 265 {$nick} :Current Local Users: {$userCount}  Max: {$userCount}");
        $user->send(":{$config['name']} 266 {$nick} :Current Global Users: {$userCount}  Max: {$userCount}");
        
        // MOTD senden
        $this->sendMotd($user);
    }
    
    /**
     * Sendet die MOTD (Message of the Day) an den Benutzer
     * 
     * @param User $user Der Benutzer
     */
    private function sendMotd(User $user): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        $user->send(":{$config['name']} 375 {$nick} :- {$config['name']} Message of the Day -");
        
        // MOTD in Zeilen aufteilen und versenden
        $motdLines = explode($config['line_ending_conf'], $config['motd']);
        foreach ($motdLines as $line) {
            $user->send(":{$config['name']} 372 {$nick} :- {$line}");
        }
        
        $user->send(":{$config['name']} 376 {$nick} :End of /MOTD command.");
    }
}