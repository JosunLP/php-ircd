<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class NickCommand extends CommandBase {
    /**
     * Executes the NICK command
     *
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Check if a nickname was provided
        if (!isset($args[1])) {
            $this->sendError($user, 'NICK', 'No nickname given', 431);
            return;
        }

        $newNick = $args[1];

        // Sometimes clients send :nick instead of nick
        if (strpos($newNick, ':') === 0) {
            $newNick = substr($newNick, 1);
        }

        // Validate the nickname
        if (!$this->validateNick($newNick)) {
            $this->sendError($user, $newNick, 'Erroneous Nickname: You fail.', 432);
            return;
        }

        // Check if the nickname is already in use
        if ($this->isNickInUse($newNick, $user)) {
            $currentNick = $user->getNick() ?? '*';
            $user->send(":{$this->server->getConfig()['name']} 433 {$currentNick} {$newNick} :Nickname is already in use.");
            return;
        }

        // Check if the nickname is reserved (for services, etc.)
        if ($this->isReservedNick($newNick)) {
            $currentNick = $user->getNick() ?? '*';
            $user->send(":{$this->server->getConfig()['name']} 433 {$currentNick} {$newNick} :Nickname is reserved.");
            return;
        }

        $oldNick = $user->getNick();

        // If this is the first NICK command (registration)
        if ($oldNick === null) {
            $user->setNick($newNick);

            // If the user is fully registered, send a PING request
            if ($user->isRegistered()) {
                $user->send("PING :{$this->server->getConfig()['name']}");
            }
        } else {
            // Notify all relevant channels about the nickname change
            $user->setNick($newNick);

            $notifiedUsers = [$user]; // Users already notified

            // Iterate through all channels the user is in
            foreach ($this->server->getChannels() as $channel) {
                if ($channel->hasUser($user)) {
                    // Notify all users in the channel
                    foreach ($channel->getUsers() as $channelUser) {
                        if (!in_array($channelUser, $notifiedUsers, true)) {
                            $nickChangeMessage = ":{$oldNick}!{$user->getIdent()}@{$user->getCloak()} NICK {$newNick}";

                            // Add IRCv3 tags if supported
                            if ($channelUser->hasCapability('server-time')) {
                                $nickChangeMessage = $this->addServerTimeTag($nickChangeMessage);
                            }

                            $channelUser->send($nickChangeMessage);
                            $notifiedUsers[] = $channelUser;
                        }
                    }
                }
            }

            // Send WATCH notifications about the nickname change
            $this->server->broadcastWatchNotifications($user, true, $oldNick);

            // Log the nickname change
            $this->server->getLogger()->info("User {$oldNick} changed nickname to {$newNick}");
        }
    }

    /**
     * Validates a nickname according to IRC rules
     *
     * @param string $nick The nickname to validate
     * @return bool Whether the nickname is valid
     */
    private function validateNick(string $nick): bool {
        // Check length (1-30 characters)
        if (strlen($nick) < 1 || strlen($nick) > 30) {
            return false;
        }

        // IRC nickname rules: letters, numbers, special characters, max. 30 characters
        // First character must be a letter or special character
        // Subsequent characters can be letters, numbers, or special characters
        return preg_match('/^[a-zA-Z\[\]_|`^{][a-zA-Z0-9\[\]_|`^{}-]*$/', $nick) === 1;
    }

    /**
     * Checks if a nickname is already in use
     *
     * @param string $nick The nickname to check
     * @param User $excludeUser User to exclude from the check
     * @return bool Whether the nickname is in use
     */
    private function isNickInUse(string $nick, User $excludeUser): bool {
        $users = $this->server->getUsers();
        foreach ($users as $existingUser) {
            if ($existingUser !== $excludeUser &&
                $existingUser->getNick() !== null &&
                strtolower($existingUser->getNick()) === strtolower($nick)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks if a nickname is reserved
     *
     * @param string $nick The nickname to check
     * @return bool Whether the nickname is reserved
     */
    private function isReservedNick(string $nick): bool {
        $reservedNicks = [
            'nickserv', 'chanserv', 'memoserv', 'operserv', 'helpserv',
            'authserv', 'hostserv', 'gameserv', 'statsserv', 'infoserv',
            'admin', 'administrator', 'root', 'system', 'server',
            'irc', 'ircd', 'network', 'service', 'bot', 'robot'
        ];

        return in_array(strtolower($nick), array_map('strtolower', $reservedNicks));
    }

    /**
     * Adds server-time tag to a message
     *
     * @param string $message The message
     * @return string The message with server-time tag
     */
    private function addServerTimeTag(string $message): string {
        $timestamp = gmdate('Y-m-d\TH:i:s.v\Z');
        return "@time={$timestamp} {$message}";
    }
}
