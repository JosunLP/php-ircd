<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class VersionCommand extends CommandBase {
    /**
     * Executes the VERSION command
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
        
        // Server version response
        $user->send(":{$config['name']} 351 {$nick} Danoserv-{$config['version']} {$config['name']} :PHP-IRCd Server");
        
        // Additional version information
        $phpVersion = phpversion();
        $osInfo = php_uname();
        $user->send(":{$config['name']} 371 {$nick} :Server running on PHP {$phpVersion}");
        $user->send(":{$config['name']} 371 {$nick} :Operating System: {$osInfo}");
        $user->send(":{$config['name']} 371 {$nick} :Server uptime: " . $this->getUptime());
        
        // End of VERSION
        $user->send(":{$config['name']} 374 {$nick} :End of VERSION");
    }
    
    /**
     * Gets the server uptime
     * 
     * @return string The formatted uptime
     */
    private function getUptime(): string {
        $startTime = $this->server->getStartTime();
        $uptime = time() - $startTime;
        
        $days = floor($uptime / 86400);
        $uptime %= 86400;
        $hours = floor($uptime / 3600);
        $uptime %= 3600;
        $minutes = floor($uptime / 60);
        $seconds = $uptime % 60;
        
        $result = [];
        if ($days > 0) {
            $result[] = $days . ' day' . ($days > 1 ? 's' : '');
        }
        if ($hours > 0) {
            $result[] = $hours . ' hour' . ($hours > 1 ? 's' : '');
        }
        if ($minutes > 0) {
            $result[] = $minutes . ' minute' . ($minutes > 1 ? 's' : '');
        }
        if ($seconds > 0 || count($result) === 0) {
            $result[] = $seconds . ' second' . ($seconds != 1 ? 's' : '');
        }
        
        return implode(', ', $result);
    }
}