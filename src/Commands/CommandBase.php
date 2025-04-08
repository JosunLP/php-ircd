<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Core\Server;
use PhpIrcd\Models\User;

abstract class CommandBase {
    protected $server;
    
    /**
     * Constructor
     * 
     * @param Server $server The server instance
     */
    public function __construct(Server $server) {
        $this->server = $server;
    }
    
    /**
     * Executes the command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    abstract public function execute(User $user, array $args): void;
    
    /**
     * Sends an error message to the user
     * 
     * @param User $user The user
     * @param string $command The command
     * @param string $message The error message
     * @param int $code The error code
     */
    protected function sendError(User $user, string $command, string $message, int $code): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick() ?? '*';
        $user->send(":{$config['name']} {$code} {$nick} {$command} :{$message}");
    }
    
    /**
     * Checks if the user is registered
     * 
     * @param User $user The user to check
     * @return bool Whether the user is registered
     */
    protected function ensureRegistered(User $user): bool {
        if (!$user->isRegistered()) {
            $config = $this->server->getConfig();
            $nick = $user->getNick() ?? '*';
            $user->send(":{$config['name']} 451 {$nick} :You have not registered");
            return false;
        }
        return true;
    }
    
    /**
     * Checks if the user is an operator
     * 
     * @param User $user The user to check
     * @return bool Whether the user is an operator
     */
    protected function ensureOper(User $user): bool {
        if (!$user->isOper()) {
            $config = $this->server->getConfig();
            $nick = $user->getNick();
            $user->send(":{$config['name']} 481 {$nick} :Permission Denied- You do not have the correct IRC operator privileges");
            return false;
        }
        return true;
    }
    
    /**
     * Helper function to parse the message part with the ':' prefix
     * 
     * @param array $args The command arguments
     * @param int $startIndex The start index for the message part
     * @return string The combined message
     */
    protected function getMessagePart(array $args, int $startIndex): string {
        // If the message part does not exist or does not contain ':'
        if (!isset($args[$startIndex]) || strpos($args[$startIndex], ':') !== 0) {
            return '';
        }
        
        // Remove ':' at the beginning
        $args[$startIndex] = substr($args[$startIndex], 1);
        
        // Combine all arguments starting from $startIndex
        return implode(' ', array_slice($args, $startIndex));
    }
}