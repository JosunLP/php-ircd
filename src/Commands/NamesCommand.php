<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class NamesCommand extends CommandBase {
    /**
     * Executes the NAMES command
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
        
        // If channel is specified, only show names for that channel
        if (isset($args[1])) {
            $channelNames = explode(',', $args[1]);
            
            foreach ($channelNames as $channelName) {
                $channel = $this->server->getChannel($channelName);
                if ($channel !== null && (!$channel->hasMode('s') || $channel->hasUser($user))) {
                    $this->sendChannelNames($user, $channel);
                }
            }
        } else {
            // No channel specified, show all channels
            $channels = $this->server->getChannels();
            foreach ($channels as $channel) {
                // Skip secret channels the user is not in
                if ($channel->hasMode('s') && !$channel->hasUser($user)) {
                    continue;
                }
                
                $this->sendChannelNames($user, $channel);
            }
            
            // Also list users not in a channel
            $usersWithoutChannel = [];
            foreach ($this->server->getUsers() as $serverUser) {
                $inChannel = false;
                foreach ($channels as $channel) {
                    if ($channel->hasUser($serverUser)) {
                        $inChannel = true;
                        break;
                    }
                }
                
                if (!$inChannel) {
                    $usersWithoutChannel[] = $serverUser->getNick();
                }
            }
            
            if (!empty($usersWithoutChannel)) {
                $names = implode(' ', $usersWithoutChannel);
                $user->send(":{$config['name']} 353 {$nick} = * :{$names}");
                $user->send(":{$config['name']} 366 {$nick} * :End of /NAMES list");
            }
        }
    }
    
    /**
     * Sends names list for a specific channel
     * 
     * @param User $user The requesting user
     * @param Channel $channel The channel
     */
    private function sendChannelNames(User $user, $channel): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        $channelName = $channel->getName();
        
        // Create user list with prefixes
        $userNames = [];
        foreach ($channel->getUsers() as $channelUser) {
            $prefix = '';
            
            // Add prefixes based on user status
            if ($channel->isOwner($channelUser)) {
                $prefix = '~';
            } else if ($channel->isProtected($channelUser)) {
                $prefix = '&';
            } else if ($channel->isOperator($channelUser)) {
                $prefix = '@';
            } else if ($channel->isHalfop($channelUser)) {
                $prefix = '%';
            } else if ($channel->isVoiced($channelUser)) {
                $prefix = '+';
            }
            
            $userNames[] = $prefix . $channelUser->getNick();
        }
        
        // Send names in chunks to avoid exceeding message length
        $maxNamesPerLine = 30; // Approximate value
        $nameChunks = array_chunk($userNames, $maxNamesPerLine);
        
        // Channel symbol: @ for secret, * for private, = for public
        $symbol = '=';
        if ($channel->hasMode('s')) {
            $symbol = '@';
        } else if ($channel->hasMode('p')) {
            $symbol = '*';
        }
        
        foreach ($nameChunks as $nameChunk) {
            $names = implode(' ', $nameChunk);
            $user->send(":{$config['name']} 353 {$nick} {$symbol} {$channelName} :{$names}");
        }
        
        $user->send(":{$config['name']} 366 {$nick} {$channelName} :End of /NAMES list");
    }
}