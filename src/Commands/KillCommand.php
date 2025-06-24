<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class KillCommand extends CommandBase {
    /**
     * Executes the KILL command (disconnects a user from the server)
     * According to RFC 2812
     * 
     * @param User $user The executing user (must be an operator)
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Ensure the user is registered
        if (!$this->ensureRegistered($user)) {
            return;
        }
        
        // Only IRC operators can use the KILL command
        if (!$this->ensureOper($user)) {
            return;
        }
        
        // Check if enough parameters are provided
        if (!isset($args[1])) {
            $this->sendError($user, 'KILL', 'Not enough parameters', 461);
            return;
        }
        
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        $targetNick = $args[1];
        
        // Extract kill reason/comment
        $reason = isset($args[2]) ? $this->getMessagePart($args, 2) : "No reason given";
        
        // Search for target user
        $targetUser = null;
        foreach ($this->server->getUsers() as $serverUser) {
            if ($serverUser->getNick() !== null && strtolower($serverUser->getNick()) === strtolower($targetNick)) {
                $targetUser = $serverUser;
                break;
            }
        }
        
        // If user not found, send error
        if ($targetUser === null) {
            $user->send(":{$config['name']} 401 {$nick} {$targetNick} :No such nick/channel");
            return;
        }
        
        // Prevent operators from killing themselves
        if ($targetUser === $user) {
            $user->send(":{$config['name']} 483 {$nick} :You can't KILL yourself");
            return;
        }
        
        // Notify the target user that they are being killed
        $operHost = $user->getIdent() . '@' . $user->getHost();
        $killMsg = ":{$config['name']} KILL {$targetUser->getNick()} :{$config['name']} ({$nick}!{$operHost}) Killed: {$reason}";
        $targetUser->send($killMsg);
        
        // Format the quit message for other users
        $quitMsg = "Killed by {$nick}: {$reason}";
        
        // Log the kill action
        $this->server->getLogger()->info("User {$targetUser->getNick()} killed by operator {$nick}: {$reason}");
        
        // Notify all users with server notice mode about the kill
        foreach ($this->server->getUsers() as $serverUser) {
            if ($serverUser->hasMode('s') && $serverUser !== $user && $serverUser !== $targetUser) {
                $serverUser->send(":{$config['name']} NOTICE {$serverUser->getNick()} :*** Notice -- {$targetUser->getNick()} killed by {$nick}: {$reason}");
            }
        }
        
        // Disconnect the target user with the quit message
        $this->server->getConnectionHandler()->disconnectUser($targetUser, $quitMsg);
        
        // Send confirmation to the operator
        $user->send(":{$config['name']} NOTICE {$nick} :KILL message sent to {$targetNick}");
    }
}