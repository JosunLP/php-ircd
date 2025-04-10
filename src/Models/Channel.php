<?php

namespace PhpIrcd\Models;

class Channel {
    private $name;
    private $topic = null;
    private $topicSetBy = null;
    private $topicSetTime = 0;
    private $users = [];
    private $modes = [];
    private $created;
    
    // Channel user modes
    private $operators = [];      // @
    private $voiced = [];         // +
    private $halfops = [];        // %
    private $owners = [];         // ~
    private $protected = [];      // &
    
    // Channel protection
    private $bans = [];
    private $inviteExceptions = [];
    private $banExceptions = [];
    private $inviteOnly = false;
    private $key = null;
    private $limit = 0;
    
    // Channel persistence
    private $permanent = false;   // Neu: Flag für permanente Kanäle
    
    /**
     * Constructor
     * 
     * @param string $name The channel name
     */
    public function __construct(string $name) {
        $this->name = $name;
        $this->created = time();
        
        // Set default nt mode (no topic by users, no /NOTICE)
        $this->modes['n'] = true;
        $this->modes['t'] = true;
    }
    
    /**
     * Adds a user to the channel
     * 
     * @param User $user The user to be added
     * @param bool $asOperator Optional: Whether the user should be added as an operator
     * @return bool Success of the operation
     */
    public function addUser(User $user, bool $asOperator = false): bool {
        if ($this->hasUser($user)) {
            return false;
        }
        
        $this->users[] = $user;
        
        // If first user or added as operator, mark as OP
        if ($asOperator || count($this->users) === 1) {
            $this->operators[] = $user;
        }
        
        return true;
    }
    
    /**
     * Removes a user from the channel
     * 
     * @param User $user The user to be removed
     * @return bool Success of the operation
     */
    public function removeUser(User $user): bool {
        $key = array_search($user, $this->users, true);
        if ($key === false) {
            return false;
        }
        
        unset($this->users[$key]);
        $this->users = array_values($this->users); // Reindex array
        
        // Remove user from all mod lists
        $this->removeUserFromModlists($user);
        
        return true;
    }
    
    /**
     * Removes a user from all mod lists
     * 
     * @param User $user The user to be removed
     */
    private function removeUserFromModlists(User $user): void {
        $lists = [
            'operators', 'voiced', 'halfops', 'owners', 'protected'
        ];
        
        foreach ($lists as $list) {
            $key = array_search($user, $this->{$list}, true);
            if ($key !== false) {
                unset($this->{$list}[$key]);
                $this->{$list} = array_values($this->{$list}); // Reindex array
            }
        }
    }
    
    /**
     * Checks if a user is in the channel
     * 
     * @param User $user The user to be checked
     * @return bool Whether the user is in the channel
     */
    public function hasUser(User $user): bool {
        return in_array($user, $this->users, true);
    }
    
    /**
     * Returns all users in the channel
     * 
     * @return array The users in the channel
     */
    public function getUsers(): array {
        return $this->users;
    }
    
    /**
     * Sets or removes a channel mode
     * 
     * @param string $mode The mode letter
     * @param bool $value True to set, False to remove
     * @param mixed $param Optional: Parameter for the mode (e.g., limit number, key)
     */
    public function setMode(string $mode, bool $value, $param = null): void {
        switch ($mode) {
            case 'i': // Invite-Only
                $this->inviteOnly = $value;
                break;
                
            case 'k': // Key (Password)
                if ($value && $param !== null) {
                    $this->key = $param;
                } else if (!$value) {
                    $this->key = null;
                }
                break;
                
            case 'l': // User Limit
                if ($value && is_numeric($param)) {
                    $this->limit = (int)$param;
                } else if (!$value) {
                    $this->limit = 0;
                }
                break;
                
            default: // Other modes
                if ($value) {
                    $this->modes[$mode] = true;
                } else {
                    unset($this->modes[$mode]);
                }
                break;
        }
    }
    
    /**
     * Checks if a specific mode is set
     * 
     * @param string $mode The mode letter to be checked
     * @return bool Whether the mode is set
     */
    public function hasMode(string $mode): bool {
        switch ($mode) {
            case 'i':
                return $this->inviteOnly;
            case 'k':
                return $this->key !== null;
            case 'l':
                return $this->limit > 0;
            default:
                return isset($this->modes[$mode]);
        }
    }
    
    /**
     * Returns all simple modes as a string
     * 
     * @return string The modes as a string
     */
    public function getModeString(): string {
        $modeStr = implode('', array_keys($this->modes));
        
        if ($this->inviteOnly) {
            $modeStr .= 'i';
        }
        
        if ($this->key !== null) {
            $modeStr .= 'k';
        }
        
        if ($this->limit > 0) {
            $modeStr .= 'l';
        }
        
        return $modeStr;
    }
    
    /**
     * Returns all mode parameters as an array
     * 
     * @return array The mode parameters
     */
    public function getModeParams(): array {
        $params = [];
        
        if ($this->key !== null) {
            $params[] = $this->key;
        }
        
        if ($this->limit > 0) {
            $params[] = (string)$this->limit;
        }
        
        return $params;
    }
    
    /**
     * Returns the channel name
     * 
     * @return string The channel name
     */
    public function getName(): string {
        return $this->name;
    }
    
    /**
     * Sets the topic
     * 
     * @param string $topic The new topic
     * @param string $setBy Who set the topic
     */
    public function setTopic(string $topic, string $setBy): void {
        $this->topic = $topic;
        $this->topicSetBy = $setBy;
        $this->topicSetTime = time();
    }
    
    /**
     * Returns the topic
     * 
     * @return string|null The topic or null if none is set
     */
    public function getTopic(): ?string {
        return $this->topic;
    }
    
    /**
     * Returns who set the topic
     * 
     * @return string|null The nickname or null if no topic is set
     */
    public function getTopicSetBy(): ?string {
        return $this->topicSetBy;
    }
    
    /**
     * Returns when the topic was set
     * 
     * @return int The Unix timestamp or 0 if no topic is set
     */
    public function getTopicSetTime(): int {
        return $this->topicSetTime;
    }
    
    /**
     * Checks if a user has operator status
     * 
     * @param User $user The user to be checked
     * @return bool Whether the user is an operator
     */
    public function isOperator(User $user): bool {
        return in_array($user, $this->operators, true);
    }
    
    /**
     * Sets or removes the operator status of a user
     * 
     * @param User $user The user
     * @param bool $value True to set, False to remove
     */
    public function setOperator(User $user, bool $value): void {
        if ($value && !$this->isOperator($user)) {
            $this->operators[] = $user;
        } else if (!$value && $this->isOperator($user)) {
            $key = array_search($user, $this->operators, true);
            unset($this->operators[$key]);
            $this->operators = array_values($this->operators);
        }
    }
    
    /**
     * Checks if a user has voice status
     * 
     * @param User $user The user to be checked
     * @return bool Whether the user has voice
     */
    public function isVoiced(User $user): bool {
        return in_array($user, $this->voiced, true);
    }
    
    /**
     * Sets or removes the voice status of a user
     * 
     * @param User $user The user
     * @param bool $value True to set, False to remove
     */
    public function setVoiced(User $user, bool $value): void {
        if ($value && !$this->isVoiced($user)) {
            $this->voiced[] = $user;
        } else if (!$value && $this->isVoiced($user)) {
            $key = array_search($user, $this->voiced, true);
            unset($this->voiced[$key]);
            $this->voiced = array_values($this->voiced);
        }
    }
    
    /**
     * Checks if a user has owner status (~)
     * 
     * @param User $user The user to be checked
     * @return bool Whether the user is an owner
     */
    public function isOwner(User $user): bool {
        return in_array($user, $this->owners, true);
    }
    
    /**
     * Sets or removes the owner status of a user
     * 
     * @param User $user The user
     * @param bool $value True to set, False to remove
     */
    public function setOwner(User $user, bool $value): void {
        if ($value && !$this->isOwner($user)) {
            $this->owners[] = $user;
        } else if (!$value && $this->isOwner($user)) {
            $key = array_search($user, $this->owners, true);
            unset($this->owners[$key]);
            $this->owners = array_values($this->owners);
        }
    }
    
    /**
     * Checks if a user has protected status (&)
     * 
     * @param User $user The user to be checked
     * @return bool Whether the user is protected
     */
    public function isProtected(User $user): bool {
        return in_array($user, $this->protected, true);
    }
    
    /**
     * Sets or removes the protected status of a user
     * 
     * @param User $user The user
     * @param bool $value True to set, False to remove
     */
    public function setProtected(User $user, bool $value): void {
        if ($value && !$this->isProtected($user)) {
            $this->protected[] = $user;
        } else if (!$value && $this->isProtected($user)) {
            $key = array_search($user, $this->protected, true);
            unset($this->protected[$key]);
            $this->protected = array_values($this->protected);
        }
    }
    
    /**
     * Checks if a user has halfop status (%)
     * 
     * @param User $user The user to be checked
     * @return bool Whether the user is a halfop
     */
    public function isHalfop(User $user): bool {
        return in_array($user, $this->halfops, true);
    }
    
    /**
     * Sets or removes the halfop status of a user
     * 
     * @param User $user The user
     * @param bool $value True to set, False to remove
     */
    public function setHalfop(User $user, bool $value): void {
        if ($value && !$this->isHalfop($user)) {
            $this->halfops[] = $user;
        } else if (!$value && $this->isHalfop($user)) {
            $key = array_search($user, $this->halfops, true);
            unset($this->halfops[$key]);
            $this->halfops = array_values($this->halfops);
        }
    }
    
    /**
     * Adds a ban
     * 
     * @param string $mask The ban mask (e.g., *!*@*.example.com)
     * @param string $by Who set the ban
     * @return bool Success of the operation
     */
    public function addBan(string $mask, string $by): bool {
        if (in_array($mask, array_column($this->bans, 'mask'))) {
            return false;
        }
        
        $this->bans[] = [
            'mask' => $mask,
            'by' => $by,
            'time' => time()
        ];
        
        return true;
    }
    
    /**
     * Removes a ban
     * 
     * @param string $mask The ban mask to be removed
     * @return bool Success of the operation
     */
    public function removeBan(string $mask): bool {
        foreach ($this->bans as $key => $ban) {
            if ($ban['mask'] === $mask) {
                unset($this->bans[$key]);
                $this->bans = array_values($this->bans);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Returns all bans
     * 
     * @return array The bans
     */
    public function getBans(): array {
        return $this->bans;
    }
    
    /**
     * Checks if a user is banned
     * 
     * @param User $user The user to be checked
     * @return bool Whether the user is banned
     */
    public function isBanned(User $user): bool {
        $hostmask = $user->getNick() . '!' . $user->getIdent() . '@' . $user->getCloak();
        
        foreach ($this->bans as $ban) {
            if ($this->matchesMask($hostmask, $ban['mask'])) {
                // Check if there is a ban exception
                if ($this->hasExceptionFor($user)) {
                    return false;
                }
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Checks if a user has a ban exception
     * 
     * @param User $user The user to be checked
     * @return bool Whether the user has an exception
     */
    public function hasExceptionFor(User $user): bool {
        $hostmask = $user->getNick() . '!' . $user->getIdent() . '@' . $user->getCloak();
        
        foreach ($this->banExceptions as $exception) {
            if ($this->matchesMask($hostmask, $exception['mask'])) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Checks if a hostmask matches a mask
     * 
     * @param string $hostmask The hostmask to be checked
     * @param string $mask The mask
     * @return bool Whether the hostmask matches the mask
     */
    private function matchesMask(string $hostmask, string $mask): bool {
        $regex = '/^' . str_replace(['*', '?'], ['.*', '.'], preg_quote($mask, '/')) . '$/';
        return (bool)preg_match($regex, $hostmask);
    }
    
    /**
     * Adds a ban exception
     * 
     * @param string $mask The exception mask
     * @param string $by Who set the exception
     * @return bool Success of the operation
     */
    public function addBanException(string $mask, string $by): bool {
        if (in_array($mask, array_column($this->banExceptions, 'mask'))) {
            return false;
        }
        
        $this->banExceptions[] = [
            'mask' => $mask,
            'by' => $by,
            'time' => time()
        ];
        
        return true;
    }
    
    /**
     * Removes a ban exception
     * 
     * @param string $mask The exception mask to be removed
     * @return bool Success of the operation
     */
    public function removeBanException(string $mask): bool {
        foreach ($this->banExceptions as $key => $exception) {
            if ($exception['mask'] === $mask) {
                unset($this->banExceptions[$key]);
                $this->banExceptions = array_values($this->banExceptions);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Returns all ban exceptions
     * 
     * @return array The ban exceptions
     */
    public function getBanExceptions(): array {
        return $this->banExceptions;
    }
    
    /**
     * Checks if a user can join the channel
     * 
     * @param User $user The user to be checked
     * @param string|null $key Optional: The key provided by the user
     * @return bool Whether the user can join
     */
    public function canJoin(User $user, ?string $key = null): bool {
        // Check if the user is banned
        if ($this->isBanned($user)) {
            return false;
        }
        
        // Check if the channel is full
        if ($this->limit > 0 && count($this->users) >= $this->limit) {
            return false;
        }
        
        // Check if the channel is invite-only
        if ($this->inviteOnly && !$this->isInvited($user)) {
            return false;
        }
        
        // Check if a key is required
        if ($this->key !== null && $key !== $this->key) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Invites a user to the channel
     * 
     * @param string $mask The invitation mask
     * @param string $by Who issued the invitation
     * @return bool Success of the operation
     */
    public function invite(string $mask, string $by): bool {
        if (in_array($mask, array_column($this->inviteExceptions, 'mask'))) {
            return false;
        }
        
        $this->inviteExceptions[] = [
            'mask' => $mask,
            'by' => $by,
            'time' => time()
        ];
        
        return true;
    }
    
    /**
     * Checks if a user is invited
     * 
     * @param User $user The user to be checked
     * @return bool Whether the user is invited
     */
    public function isInvited(User $user): bool {
        $hostmask = $user->getNick() . '!' . $user->getIdent() . '@' . $user->getCloak();
        
        foreach ($this->inviteExceptions as $invite) {
            if ($this->matchesMask($hostmask, $invite['mask'])) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Returns when the channel was created
     * 
     * @return int The Unix timestamp
     */
    public function getCreationTime(): int {
        return $this->created;
    }
    
    /**
     * Setzt den Permanenz-Status des Kanals
     * 
     * @param bool $permanent True, wenn der Kanal permanent sein soll
     */
    public function setPermanent(bool $permanent): void {
        $this->permanent = $permanent;
    }
    
    /**
     * Prüft, ob der Kanal permanent ist
     * 
     * @return bool Ob der Kanal permanent ist
     */
    public function isPermanent(): bool {
        return $this->permanent;
    }
    
    /**
     * Returns the key of the channel if set
     * 
     * @return string|null The key or null if none is set
     */
    public function getKey(): ?string {
        return $this->key;
    }
    
    /**
     * Returns the user limit of the channel if set
     * 
     * @return int The user limit or 0 if none is set
     */
    public function getLimit(): int {
        return $this->limit;
    }
}