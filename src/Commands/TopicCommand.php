<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class TopicCommand extends CommandBase {
    /**
     * Executes the TOPIC command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Ensure the user is registered
        if (!$this->ensureRegistered($user)) {
            return;
        }
        
        // Check if enough parameters are provided
        if (!isset($args[1])) {
            $this->sendError($user, 'TOPIC', 'Not enough parameters', 461);
            return;
        }
        
        $channelName = $args[1];
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // Search for channel
        $channel = $this->server->getChannel($channelName);
        
        // If channel not found, send error
        if ($channel === null) {
            $user->send(":{$config['name']} 403 {$nick} {$channelName} :No such channel");
            return;
        }
        
        // Check if the user is in the channel
        if (!$channel->hasUser($user)) {
            $user->send(":{$config['name']} 442 {$nick} {$channelName} :You're not on that channel");
            return;
        }
        
        // If no topic is provided, return the current topic
        if (!isset($args[2])) {
            $topic = $channel->getTopic();
            
            if ($topic === null) {
                $user->send(":{$config['name']} 331 {$nick} {$channelName} :No topic is set");
            } else {
                $user->send(":{$config['name']} 332 {$nick} {$channelName} :{$topic}");
                $user->send(":{$config['name']} 333 {$nick} {$channelName} {$channel->getTopicSetBy()} {$channel->getTopicSetTime()}");
            }
            return;
        }
        
        // Otherwise set the new topic
        
        // Check if the user has permission to change the topic
        if ($channel->hasMode('t') && !$channel->isOperator($user) && !$user->isOper()) {
            $user->send(":{$config['name']} 482 {$nick} {$channelName} :You're not channel operator");
            return;
        }
        
        // Set the new topic
        $topic = $this->getMessagePart($args, 2);
        $channel->setTopic($topic, $nick);
        
        // Send topic change to all users in the channel
        $topicMessage = ":{$nick}!{$user->getIdent()}@{$user->getCloak()} TOPIC {$channelName} :{$topic}";
        foreach ($channel->getUsers() as $channelUser) {
            $channelUser->send($topicMessage);
        }
    }
}