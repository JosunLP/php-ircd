<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class ListCommand extends CommandBase {
    /**
     * Executes the LIST command
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
        
        // Filter parameters
        $filter = [];
        if (isset($args[1])) {
            $filter = explode(',', $args[1]);
        }
        
        // Send LIST header
        $user->send(":{$config['name']} 321 {$nick} Channel :Users  Name");
        
        // Get all channels
        $channels = $this->server->getChannels();
        
        foreach ($channels as $channel) {
            $channelName = $channel->getName();
            
            // Apply filter if specified
            if (!empty($filter) && !in_array($channelName, $filter)) {
                continue;
            }
            
            // Skip secret channels user is not in
            if ($channel->hasMode('s') && !$channel->hasUser($user)) {
                continue;
            }
            
            // Get topic, user count
            $topic = $channel->getTopic() ?? "";
            $userCount = count($channel->getUsers());
            
            // Send channel info
            $user->send(":{$config['name']} 322 {$nick} {$channelName} {$userCount} :{$topic}");
        }
        
        // Send LIST footer
        $user->send(":{$config['name']} 323 {$nick} :End of /LIST");
    }
}