<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class WatchCommand extends CommandBase {
    /**
     * Executes the WATCH command
     * According to IRC standard extension
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Ensure the user is registered
        if (!$this->ensureRegistered($user)) {
            return;
        }
        
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // If no parameters are provided, show the watch list
        if (!isset($args[1])) {
            $this->showWatchList($user);
            return;
        }
        
        // Process each watch parameter
        foreach (array_slice($args, 1) as $param) {
            if (empty($param)) {
                continue;
            }
            
            // Process commands starting with + or -
            $firstChar = substr($param, 0, 1);
            $targetNick = substr($param, 1);
            
            switch ($firstChar) {
                case '+': // Add to watch list
                    $this->addToWatchList($user, $targetNick);
                    break;
                    
                case '-': // Remove from watch list
                    $this->removeFromWatchList($user, $targetNick);
                    break;
                    
                case 'C': // Clear watch list
                case 'c':
                    $this->clearWatchList($user);
                    break;
                    
                case 'L': // List watch entries
                case 'l':
                    $this->showWatchList($user);
                    break;
                    
                case 'S': // Status of watched nicknames
                case 's':
                    $this->showWatchStatus($user);
                    break;
                    
                default:
                    // If no command specified, treat as +nick
                    $this->addToWatchList($user, $param);
                    break;
            }
        }
    }
    
    /**
     * Add a nickname to the user's watch list
     * 
     * @param User $user The watching user
     * @param string $targetNick The nickname to watch
     */
    private function addToWatchList(User $user, string $targetNick): void {
        // Check if already at maximum watch list size
        $watchList = $user->getWatchList();
        $maxWatch = 128; // From ISUPPORT WATCH=128
        
        if (count($watchList) >= $maxWatch) {
            // Maximum watch list size reached
            $user->send(":{$this->server->getConfig()['name']} 512 {$user->getNick()} {$targetNick} :Too many WATCH entries");
            return;
        }
        
        // Prepare nickname (convert to lowercase for case-insensitive matching)
        $targetNick = strtolower($targetNick);
        
        // Add to watch list if not already there
        if (!in_array($targetNick, $watchList)) {
            $user->addToWatchList($targetNick);
        }
        
        // Check if the target nick is currently online
        $targetUser = null;
        foreach ($this->server->getUsers() as $serverUser) {
            if ($serverUser->getNick() !== null && strtolower($serverUser->getNick()) === $targetNick) {
                $targetUser = $serverUser;
                break;
            }
        }
        
        // Send online notification if target is online
        if ($targetUser !== null) {
            $user->send(":{$this->server->getConfig()['name']} 604 {$user->getNick()} {$targetUser->getNick()} {$targetUser->getIdent()} {$targetUser->getHost()} {$targetUser->getLastActivity()} :is online");
        } else {
            // Send offline notification
            $user->send(":{$this->server->getConfig()['name']} 605 {$user->getNick()} {$targetNick} :is offline");
        }
    }
    
    /**
     * Remove a nickname from the user's watch list
     * 
     * @param User $user The watching user
     * @param string $targetNick The nickname to stop watching
     */
    private function removeFromWatchList(User $user, string $targetNick): void {
        // Prepare nickname (convert to lowercase for case-insensitive matching)
        $targetNick = strtolower($targetNick);
        
        // Remove from watch list
        $user->removeFromWatchList($targetNick);
        
        // Send confirmation
        $user->send(":{$this->server->getConfig()['name']} 602 {$user->getNick()} {$targetNick} :stopped watching");
    }
    
    /**
     * Clear the user's watch list
     * 
     * @param User $user The user
     */
    private function clearWatchList(User $user): void {
        $user->clearWatchList();
        $user->send(":{$this->server->getConfig()['name']} 603 {$user->getNick()} :Watch list cleared");
    }
    
    /**
     * Show the user's watch list
     * 
     * @param User $user The user
     */
    private function showWatchList(User $user): void {
        $watchList = $user->getWatchList();
        
        // Send list header
        $user->send(":{$this->server->getConfig()['name']} 606 {$user->getNick()} :Begin of WATCH list");
        
        // Send each entry in the list
        foreach ($watchList as $watchedNick) {
            $user->send(":{$this->server->getConfig()['name']} 607 {$user->getNick()} {$watchedNick}");
        }
        
        // Send list footer
        $user->send(":{$this->server->getConfig()['name']} 608 {$user->getNick()} :End of WATCH list");
    }
    
    /**
     * Show status of all watched nicknames
     * 
     * @param User $user The user
     */
    private function showWatchStatus(User $user): void {
        $watchList = $user->getWatchList();
        
        foreach ($watchList as $watchedNick) {
            // Check if the watched nick is currently online
            $targetUser = null;
            foreach ($this->server->getUsers() as $serverUser) {
                if ($serverUser->getNick() !== null && strtolower($serverUser->getNick()) === $watchedNick) {
                    $targetUser = $serverUser;
                    break;
                }
            }
            
            // Send online or offline notification
            if ($targetUser !== null) {
                $user->send(":{$this->server->getConfig()['name']} 604 {$user->getNick()} {$targetUser->getNick()} {$targetUser->getIdent()} {$targetUser->getHost()} {$targetUser->getLastActivity()} :is online");
            } else {
                $user->send(":{$this->server->getConfig()['name']} 605 {$user->getNick()} {$watchedNick} :is offline");
            }
        }
    }
}