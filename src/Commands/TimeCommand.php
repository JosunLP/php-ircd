<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class TimeCommand extends CommandBase {
    /**
     * Executes the TIME command
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
        
        // Current time in RFC 2822 format
        $date = date('r');
        
        // Send the time reply - format: <server name> :<time string>
        $user->send(":{$config['name']} 391 {$nick} {$config['name']} :{$date}");
        
        // Additional time information
        $timezone = date_default_timezone_get();
        $user->send(":{$config['name']} 371 {$nick} :Server timezone: {$timezone}");
        $user->send(":{$config['name']} 371 {$nick} :Server uptime: " . $this->getUptime());
        
        // End of TIME
        $user->send(":{$config['name']} 374 {$nick} :End of TIME information");
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
            $result[] = $days . ' Tag' . ($days > 1 ? 'e' : '');
        }
        if ($hours > 0) {
            $result[] = $hours . ' Stunde' . ($hours > 1 ? 'n' : '');
        }
        if ($minutes > 0) {
            $result[] = $minutes . ' Minute' . ($minutes > 1 ? 'n' : '');
        }
        if ($seconds > 0 || count($result) === 0) {
            $result[] = $seconds . ' Sekunde' . ($seconds != 1 ? 'n' : '');
        }
        
        return implode(', ', $result);
    }
}