<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;
use PhpIrcd\Utils\IRCv3Helper;

class NoticeCommand extends CommandBase {
    /**
     * Executes the NOTICE command
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
        if (!isset($args[1]) || !isset($args[2])) {
            // NOTICE does not send error messages
            return;
        }

        // Extract targets
        $targets = explode(',', $args[1]);
        $message = $this->getMessagePart($args, 2);

        // Send message to all targets
        foreach ($targets as $target) {
            $this->sendNotice($user, $target, $message);
        }
    }

    /**
     * Sends a notice to a target
     *
     * @param User $user The sending user
     * @param string $target The target (user or channel)
     * @param string $message The message
     */
    private function sendNotice(User $user, string $target, string $message): void {
        // Channel name starts with #
        if ($target[0] === '#') {
            $this->sendToChannel($user, $target, $message);
            return;
        }

        // Otherwise send to a user
        $targetUser = null;

        // Search for the user
        foreach ($this->server->getUsers() as $serverUser) {
            if ($serverUser->getNick() !== null && strtolower($serverUser->getNick()) === strtolower($target)) {
                $targetUser = $serverUser;
                break;
            }
        }

        // If user not found, do nothing (NOTICE does not send errors)
        if ($targetUser === null) {
            return;
        }

        // Send message to the target user
        $targetUser->send(":{$user->getNick()}!{$user->getIdent()}@{$user->getCloak()} NOTICE {$target} :{$message}");
    }

    /**
     * Sendet eine Nachricht an einen Kanal
     *
     * @param User $user Der Absender der Nachricht
     * @param string $channelName Der Name des Zielkanals
     * @param string $message Der Inhalt der Nachricht
     */
    private function sendToChannel(User $user, string $channelName, string $message): void {
        $channel = $this->server->getChannel($channelName);

        if ($channel === null) {
            return; // Do not return errors for NOTICE
        }

        // If the channel is moderated and the user has no special rights, block message
        if (
            $channel->hasMode('m') &&
            !$channel->isOperator($user) &&
            !$channel->isVoiced($user) &&
            !$channel->isHalfop($user)
        ) {
            return;
        }

        // If the user is not in the channel and the channel does not allow external messages (mode n)
        if (!$channel->hasUser($user) && $channel->hasMode('n')) {
            return;
        }

        // If the user is banned and does not have voice or higher
        if (
            $channel->isBanned($user) &&
            !$channel->isVoiced($user) &&
            !$channel->isOperator($user) &&
            !$channel->isHalfop($user)
        ) {
            return;
        }

        // Nachricht zusammenstellen
        $senderInfo = "{$user->getNick()}!{$user->getIdent()}@{$user->getHost()}";
        $fullCommand = ":{$senderInfo} NOTICE {$channelName} :{$message}";

        // Speichere die Nachricht in der Kanalhistorie für CHATHISTORY
        $channel->addMessageToHistory($fullCommand, $user->getNick());

        // Mit IRCv3-Features erweitern (z.B. server-time)
        foreach ($channel->getUsers() as $channelUser) {
            if ($channelUser !== $user && !$channelUser->isSilenced($user)) {
                $enhancedMessage = IRCv3Helper::addServerTimeIfSupported($fullCommand, $channelUser);
                $channelUser->send($enhancedMessage);
            }
        }

        // Wenn der Absender echo-message aktiviert hat, Kopie an ihn selbst senden
        if ($user->hasCapability('echo-message')) {
            $enhancedMessage = IRCv3Helper::addServerTimeIfSupported($fullCommand, $user);
            $user->send($enhancedMessage);
        }
    }
}
