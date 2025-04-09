<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class StatsCommand extends CommandBase {
    /**
     * Executes the STATS command
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
        
        // Check if the user is an operator for complete access
        $isOper = $user->isOper();
        
        // Optional parameter specifies what stats to show (l = links, u = uptime, o = oper, etc.)
        $option = isset($args[1]) ? strtolower($args[1]) : '';
        
        switch ($option) {
            case 'u': // Uptime stats
                // Server uptime
                $startTime = $this->server->getStartTime();
                $uptime = time() - $startTime;
                $days = floor($uptime / 86400);
                $uptime %= 86400;
                $hours = floor($uptime / 3600);
                $uptime %= 3600;
                $minutes = floor($uptime / 60);
                $seconds = $uptime % 60;
                
                $uptimeStr = sprintf(
                    "%d days, %02d:%02d:%02d",
                    $days, $hours, $minutes, $seconds
                );
                
                $user->send(":{$config['name']} 242 {$nick} :Server Up {$uptimeStr}");
                break;
                
            case 'o': // Oper info
                // Only operators can see this information
                if ($isOper) {
                    foreach ($config['opers'] as $operName => $operPass) {
                        $user->send(":{$config['name']} 243 {$nick} O {$operName} * * 0");
                    }
                }
                break;
                
            case 'm': // Command usage stats
                // Command statistics
                $commandCounts = $this->server->getConnectionHandler()->getCommandCounts();
                foreach ($commandCounts as $command => $count) {
                    $user->send(":{$config['name']} 212 {$nick} {$command} {$count}");
                }
                break;
                
            default: // General server stats
                // Basic server information
                $user->send(":{$config['name']} 250 {$nick} :Highest connection count: " . count($this->server->getUsers()));
                
                // Current connections
                $userCount = count($this->server->getUsers());
                $channelCount = count($this->server->getChannels());
                $user->send(":{$config['name']} 251 {$nick} :There are {$userCount} users and 0 invisible on 1 servers");
                $user->send(":{$config['name']} 254 {$nick} {$channelCount} :channels formed");
                
                // Memory usage if operator
                if ($isOper) {
                    $memoryUsage = round(memory_get_usage() / 1024 / 1024, 2);
                    $peakMemory = round(memory_get_peak_usage() / 1024 / 1024, 2);
                    $user->send(":{$config['name']} 249 {$nick} :Memory Usage: {$memoryUsage}MB (Peak: {$peakMemory}MB)");
                }
                break;
        }
        
        // End of stats
        $user->send(":{$config['name']} 219 {$nick} {$option} :End of /STATS report");
    }
}