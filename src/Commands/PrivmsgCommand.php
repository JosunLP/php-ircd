<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;
use PhpIrcd\Utils\IRCv3Helper;

class PrivmsgCommand extends CommandBase {
    /**
     * Executes the PRIVMSG command
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
            $this->sendError($user, 'PRIVMSG', 'No recipient given', 411);
            return;
        }

        if (!isset($args[2])) {
            $this->sendError($user, 'PRIVMSG', 'No text to send', 412);
            return;
        }

        // Extract targets
        $targets = explode(',', $args[1]);
        $message = $this->getMessagePart($args, 2);

        // Send message to all targets
        foreach ($targets as $target) {
            $this->sendMessage($user, $target, $message);
        }
    }

    /**
     * Sends a message to a target
     *
     * @param User $user The sending user
     * @param string $target The target (user or channel)
     * @param string $message The message
     */
    private function sendMessage(User $user, string $target, string $message): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();

        // Check for CTCP messages (enclosed in \x01)
        if (substr($message, 0, 1) === "\x01" && substr($message, -1) === "\x01") {
            $this->handleCtcpRequest($user, $target, $message);
            return;
        }

        // Channel name starts with #
        if ($target[0] === '#') {
            $this->sendToChannel($user, $target, $message);
            return;
        }

        // Otherwise send to a user
        $targetUser = null;

        // Search for user
        foreach ($this->server->getUsers() as $serverUser) {
            if ($serverUser->getNick() !== null && strtolower($serverUser->getNick()) === strtolower($target)) {
                $targetUser = $serverUser;
                break;
            }
        }

        // If user not found, send error
        if ($targetUser === null) {
            $user->send(":{$config['name']} 401 {$nick} {$target} :No such nick/channel");
            return;
        }

        // If the target user is away, send a message
        if ($targetUser->isAway()) {
            $awayMessage = $targetUser->getAwayMessage();
            $user->send(":{$config['name']} 301 {$nick} {$target} :{$awayMessage}");
        }

        // If the target user has silenced the sender, discard the message
        if ($targetUser->isSilenced($user)) {
            return;
        }

        // Send message to the target user
        $formattedMessage = ":{$nick}!{$user->getIdent()}@{$user->getCloak()} PRIVMSG {$target} :{$message}";

        // IRCv3 ECHO-MESSAGE: Wenn der Sender echo-message unterstützt, sende die Nachricht auch an ihn
        if ($user->hasCapability('echo-message')) {
            $echoMessage = IRCv3Helper::addServerTimeIfSupported($formattedMessage, $user);
            $user->send($echoMessage);
        }

        // Mit IRCv3-Features erweitern (z.B. server-time)
        $enhancedMessage = IRCv3Helper::addServerTimeIfSupported($formattedMessage, $targetUser);

        $targetUser->send($enhancedMessage);
    }

    /**
     * Sends a message to a channel
     *
     * @param User $user The sender of the message
     * @param string $channelName The name of the target channel
     * @param string $message The content of the message
     */
    private function sendToChannel(User $user, string $channelName, string $message): void {
        $channel = $this->server->getChannel($channelName);

        if ($channel === null) {
            $this->sendError($user, $channelName, 'No such channel', 401);
            return;
        }

        // If the channel is moderated and the user has no special rights, block message
        if (
            $channel->hasMode('m') &&
            !$channel->isOperator($user) &&
            !$channel->isVoiced($user) &&
            !$channel->isHalfop($user)
        ) {
            $this->sendError($user, $channelName, 'Cannot send to channel (moderated)', 404);
            return;
        }

        // If the user is not in the channel and the channel does not allow external messages (mode n)
        if (!$channel->hasUser($user) && $channel->hasMode('n')) {
            $this->sendError($user, $channelName, 'Cannot send to channel (no external messages)', 404);
            return;
        }

        // If the user is banned and does not have voice or higher
        if (
            $channel->isBanned($user) &&
            !$channel->isVoiced($user) &&
            !$channel->isOperator($user) &&
            !$channel->isHalfop($user)
        ) {
            $this->sendError($user, $channelName, 'Cannot send to channel (banned)', 404);
            return;
        }

        // Erstelle den vollständigen Befehl zur Weiterleitung
        $senderInfo = "{$user->getNick()}!{$user->getIdent()}@{$user->getHost()}";
        $fullCommand = ":{$senderInfo} PRIVMSG {$channelName} :{$message}";

        // Speichere die Nachricht in der Kanalhistorie für CHATHISTORY
        $channel->addMessageToHistory($fullCommand, $user->getNick());

        // Sende an alle Benutzer im Kanal außer dem Absender selbst
        foreach ($channel->getUsers() as $recipient) {
            // Überprüfe, ob der Benutzer den Absender stummgeschaltet hat
            if ($recipient !== $user && !$recipient->isSilenced($user)) {
                $this->deliverMessage($recipient, $user, $fullCommand, $message, true);
            }
        }

        // Wenn der Absender echo-message aktiviert hat, Kopie an ihn selbst senden
        if ($user->hasCapability('echo-message')) {
            $this->deliverMessage($user, $user, $fullCommand, $message, true);
        }
    }

    /**
     * Handles a CTCP request
     *
     * @param User $user The sending user
     * @param string $target The target (user or channel)
     * @param string $message The CTCP message
     */
    private function handleCtcpRequest(User $user, string $target, string $message): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();

        // Remove \x01 characters and extract the CTCP command
        $ctcpMessage = trim(substr($message, 1, -1));
        $parts = explode(' ', $ctcpMessage, 2);
        $ctcpCommand = strtoupper($parts[0]);
        $ctcpParams = isset($parts[1]) ? $parts[1] : '';

        // Channel target
        if ($target[0] === '#') {
            // Handle channel CTCP messages
            switch ($ctcpCommand) {
                case 'ACTION': // /me command
                    $this->sendChannelAction($user, $target, $ctcpParams);
                    break;
                default:
                    // Other CTCP commands are not forwarded to channels
                    break;
            }
        } else {
            // Handle direct user CTCP messages
            $targetUser = null;

            // Search for user
            foreach ($this->server->getUsers() as $serverUser) {
                if ($serverUser->getNick() !== null && strtolower($serverUser->getNick()) === strtolower($target)) {
                    $targetUser = $serverUser;
                    break;
                }
            }

            // If user not found, send error
            if ($targetUser === null) {
                $user->send(":{$config['name']} 401 {$nick} {$target} :No such nick/channel");
                return;
            }

            // Process CTCP command
            switch ($ctcpCommand) {
                case 'VERSION':
                    // Send VERSION reply via NOTICE
                    $versionReply = "\x01VERSION PHP-IRCd Danoserv-{$config['version']} running on PHP " . phpversion() . "\x01";
                    $targetUser->send(":{$config['name']} NOTICE {$nick} :{$versionReply}");
                    break;

                case 'PING':
                    // Echo back the PING parameter
                    $pingReply = "\x01PING {$ctcpParams}\x01";
                    $targetUser->send(":{$config['name']} NOTICE {$nick} :{$pingReply}");
                    break;

                case 'TIME':
                    // Send current server time
                    $timeReply = "\x01TIME " . date('r') . "\x01";
                    $targetUser->send(":{$config['name']} NOTICE {$nick} :{$timeReply}");
                    break;

                case 'ACTION':
                    // Forward the action to the user
                    $targetUser->send(":{$nick}!{$user->getIdent()}@{$user->getCloak()} PRIVMSG {$target} :{$message}");
                    break;

                case 'CLIENTINFO':
                    // Information about supported CTCP commands
                    $clientinfoReply = "\x01CLIENTINFO ACTION CLIENTINFO FINGER PING SOURCE TIME USERINFO VERSION\x01";
                    $targetUser->send(":{$config['name']} NOTICE {$nick} :{$clientinfoReply}");
                    break;

                case 'FINGER':
                    // User info (traditionally includes idle time)
                    $idleTime = time() - $user->getLastActivity();
                    $fingerReply = "\x01FINGER {$user->getRealname()} (idle {$idleTime} seconds)\x01";
                    $targetUser->send(":{$config['name']} NOTICE {$nick} :{$fingerReply}");
                    break;

                case 'SOURCE':
                    // Information about source code of the client
                    $sourceReply = "\x01SOURCE https://github.com/danopia/php-ircd\x01";
                    $targetUser->send(":{$config['name']} NOTICE {$nick} :{$sourceReply}");
                    break;

                case 'USERINFO':
                    // User information
                    $userinfoReply = "\x01USERINFO {$user->getRealname()}\x01";
                    $targetUser->send(":{$config['name']} NOTICE {$nick} :{$userinfoReply}");
                    break;

                default:
                    // Unknown CTCP command, reply with an error
                    $errorReply = "\x01ERRMSG {$ctcpCommand}: Unknown CTCP command\x01";
                    $targetUser->send(":{$config['name']} NOTICE {$nick} :{$errorReply}");
                    break;
            }
        }
    }

    /**
     * Sends a CTCP ACTION to a channel
     *
     * @param User $user The sending user
     * @param string $channelName The channel name
     * @param string $action The action text
     */
    private function sendChannelAction(User $user, string $channelName, string $action): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();

        // Search for channel
        $channel = $this->server->getChannel($channelName);

        // If channel not found, send error
        if ($channel === null) {
            $user->send(":{$config['name']} 401 {$nick} {$channelName} :No such nick/channel");
            return;
        }

        // Check if the user is in the channel
        if (!$channel->hasUser($user)) {
            $user->send(":{$config['name']} 404 {$nick} {$channelName} :Cannot send to channel");
            return;
        }

        // Check if the channel is in moderated mode and the user has no voice
        if ($channel->hasMode('m') && !$channel->isVoiced($user) && !$channel->isOperator($user)) {
            $user->send(":{$config['name']} 404 {$nick} {$channelName} :Cannot send to channel");
            return;
        }

        // Send ACTION to all users in the channel (except the sender)
        $formattedMessage = ":{$nick}!{$user->getIdent()}@{$user->getCloak()} PRIVMSG {$channelName} :\x01ACTION {$action}\x01";
        foreach ($channel->getUsers() as $channelUser) {
            if ($channelUser !== $user) {
                // Mit IRCv3-Features erweitern (z.B. server-time)
                $enhancedMessage = IRCv3Helper::addServerTimeIfSupported($formattedMessage, $channelUser);
                $channelUser->send($enhancedMessage);
            }
        }
    }
}
