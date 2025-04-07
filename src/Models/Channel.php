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
    
    // Channel-Benutzer-Modi
    private $operators = [];      // @
    private $voiced = [];         // +
    private $halfops = [];        // %
    private $owners = [];         // ~
    private $protected = [];      // &
    
    // Channel-Schutz
    private $bans = [];
    private $inviteExceptions = [];
    private $banExceptions = [];
    private $inviteOnly = false;
    private $key = null;
    private $limit = 0;
    
    /**
     * Konstruktor
     * 
     * @param string $name Der Kanalname
     */
    public function __construct(string $name) {
        $this->name = $name;
        $this->created = time();
        
        // Standardmäßig nt-Modus setzen (kein Thema durch Benutzer, kein /NOTICE)
        $this->modes['n'] = true;
        $this->modes['t'] = true;
    }
    
    /**
     * Fügt einen Benutzer zum Kanal hinzu
     * 
     * @param User $user Der hinzuzufügende Benutzer
     * @param bool $asOperator Optional: Ob der Benutzer als Operator hinzugefügt werden soll
     * @return bool Erfolg der Operation
     */
    public function addUser(User $user, bool $asOperator = false): bool {
        if ($this->hasUser($user)) {
            return false;
        }
        
        $this->users[] = $user;
        
        // Wenn erster Benutzer oder als Operator hinzugefügt, als OP markieren
        if ($asOperator || count($this->users) === 1) {
            $this->operators[] = $user;
        }
        
        return true;
    }
    
    /**
     * Entfernt einen Benutzer aus dem Kanal
     * 
     * @param User $user Der zu entfernende Benutzer
     * @return bool Erfolg der Operation
     */
    public function removeUser(User $user): bool {
        $key = array_search($user, $this->users, true);
        if ($key === false) {
            return false;
        }
        
        unset($this->users[$key]);
        $this->users = array_values($this->users); // Array reindexieren
        
        // Benutzer aus allen Modlisten entfernen
        $this->removeUserFromModlists($user);
        
        return true;
    }
    
    /**
     * Entfernt einen Benutzer aus allen Modlisten
     * 
     * @param User $user Der zu entfernende Benutzer
     */
    private function removeUserFromModlists(User $user): void {
        $lists = [
            'operators', 'voiced', 'halfops', 'owners', 'protected'
        ];
        
        foreach ($lists as $list) {
            $key = array_search($user, $this->{$list}, true);
            if ($key !== false) {
                unset($this->{$list}[$key]);
                $this->{$list} = array_values($this->{$list}); // Array reindexieren
            }
        }
    }
    
    /**
     * Prüft, ob ein Benutzer im Kanal ist
     * 
     * @param User $user Der zu prüfende Benutzer
     * @return bool Ob der Benutzer im Kanal ist
     */
    public function hasUser(User $user): bool {
        return in_array($user, $this->users, true);
    }
    
    /**
     * Gibt alle Benutzer im Kanal zurück
     * 
     * @return array Die Benutzer im Kanal
     */
    public function getUsers(): array {
        return $this->users;
    }
    
    /**
     * Setzt oder entfernt einen Kanal-Mode
     * 
     * @param string $mode Der Mode-Buchstabe
     * @param bool $value True zum Setzen, False zum Entfernen
     * @param mixed $param Optional: Parameter für den Mode (z.B. Limit-Zahl, Key)
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
                
            default: // Andere Modes
                if ($value) {
                    $this->modes[$mode] = true;
                } else {
                    unset($this->modes[$mode]);
                }
                break;
        }
    }
    
    /**
     * Prüft, ob ein bestimmter Mode gesetzt ist
     * 
     * @param string $mode Der zu prüfende Mode-Buchstabe
     * @return bool Ob der Mode gesetzt ist
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
     * Gibt alle einfachen Modes als String zurück
     * 
     * @return string Die Modes als String
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
     * Gibt alle Mode-Parameter als Array zurück
     * 
     * @return array Die Mode-Parameter
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
     * Gibt den Kanalnamen zurück
     * 
     * @return string Der Kanalname
     */
    public function getName(): string {
        return $this->name;
    }
    
    /**
     * Setzt das Topic
     * 
     * @param string $topic Das neue Topic
     * @param string $setBy Wer das Topic gesetzt hat
     */
    public function setTopic(string $topic, string $setBy): void {
        $this->topic = $topic;
        $this->topicSetBy = $setBy;
        $this->topicSetTime = time();
    }
    
    /**
     * Gibt das Topic zurück
     * 
     * @return string|null Das Topic oder null, wenn keines gesetzt ist
     */
    public function getTopic(): ?string {
        return $this->topic;
    }
    
    /**
     * Gibt zurück, wer das Topic gesetzt hat
     * 
     * @return string|null Der Nickname oder null, wenn kein Topic gesetzt ist
     */
    public function getTopicSetBy(): ?string {
        return $this->topicSetBy;
    }
    
    /**
     * Gibt zurück, wann das Topic gesetzt wurde
     * 
     * @return int Der Unix-Timestamp oder 0, wenn kein Topic gesetzt ist
     */
    public function getTopicSetTime(): int {
        return $this->topicSetTime;
    }
    
    /**
     * Prüft, ob ein Benutzer Operator-Status hat
     * 
     * @param User $user Der zu prüfende Benutzer
     * @return bool Ob der Benutzer Operator ist
     */
    public function isOperator(User $user): bool {
        return in_array($user, $this->operators, true);
    }
    
    /**
     * Setzt oder entfernt den Operator-Status eines Benutzers
     * 
     * @param User $user Der Benutzer
     * @param bool $value True zum Setzen, False zum Entfernen
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
     * Prüft, ob ein Benutzer Voice-Status hat
     * 
     * @param User $user Der zu prüfende Benutzer
     * @return bool Ob der Benutzer Voice hat
     */
    public function isVoiced(User $user): bool {
        return in_array($user, $this->voiced, true);
    }
    
    /**
     * Setzt oder entfernt den Voice-Status eines Benutzers
     * 
     * @param User $user Der Benutzer
     * @param bool $value True zum Setzen, False zum Entfernen
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
     * Fügt einen Ban hinzu
     * 
     * @param string $mask Die Ban-Maske (z.B. *!*@*.example.com)
     * @param string $by Wer den Ban gesetzt hat
     * @return bool Erfolg der Operation
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
     * Entfernt einen Ban
     * 
     * @param string $mask Die zu entfernende Ban-Maske
     * @return bool Erfolg der Operation
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
     * Gibt alle Bans zurück
     * 
     * @return array Die Bans
     */
    public function getBans(): array {
        return $this->bans;
    }
    
    /**
     * Prüft, ob ein Benutzer gebannt ist
     * 
     * @param User $user Der zu prüfende Benutzer
     * @return bool Ob der Benutzer gebannt ist
     */
    public function isBanned(User $user): bool {
        $hostmask = $user->getNick() . '!' . $user->getIdent() . '@' . $user->getCloak();
        
        foreach ($this->bans as $ban) {
            if ($this->matchesMask($hostmask, $ban['mask'])) {
                // Prüfen, ob es eine Ban-Exception gibt
                if ($this->hasExceptionFor($user)) {
                    return false;
                }
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Prüft, ob ein Benutzer eine Ban-Exception hat
     * 
     * @param User $user Der zu prüfende Benutzer
     * @return bool Ob der Benutzer eine Exception hat
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
     * Prüft, ob ein Hostmask einer Maske entspricht
     * 
     * @param string $hostmask Der zu prüfende Hostmask
     * @param string $mask Die Maske
     * @return bool Ob der Hostmask der Maske entspricht
     */
    private function matchesMask(string $hostmask, string $mask): bool {
        $pattern = str_replace(
            ['*', '?', '.'],
            ['.*', '.', '\\.'],
            $mask
        );
        
        return (bool)preg_match('/^' . $pattern . '$/i', $hostmask);
    }
    
    /**
     * Fügt eine Ban-Exception hinzu
     * 
     * @param string $mask Die Exception-Maske
     * @param string $by Wer die Exception gesetzt hat
     * @return bool Erfolg der Operation
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
     * Entfernt eine Ban-Exception
     * 
     * @param string $mask Die zu entfernende Exception-Maske
     * @return bool Erfolg der Operation
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
     * Gibt alle Ban-Exceptions zurück
     * 
     * @return array Die Ban-Exceptions
     */
    public function getBanExceptions(): array {
        return $this->banExceptions;
    }
    
    /**
     * Prüft, ob ein Benutzer dem Kanal beitreten kann
     * 
     * @param User $user Der zu prüfende Benutzer
     * @param string|null $key Optional: Der vom Benutzer angegebene Key
     * @return bool Ob der Benutzer beitreten kann
     */
    public function canJoin(User $user, ?string $key = null): bool {
        // Prüfen, ob der Benutzer gebannt ist
        if ($this->isBanned($user)) {
            return false;
        }
        
        // Prüfen, ob der Kanal voll ist
        if ($this->limit > 0 && count($this->users) >= $this->limit) {
            return false;
        }
        
        // Prüfen, ob der Kanal invite-only ist
        if ($this->inviteOnly && !$this->isInvited($user)) {
            return false;
        }
        
        // Prüfen, ob ein Key erforderlich ist
        if ($this->key !== null && $key !== $this->key) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Lädt einen Benutzer in den Kanal ein
     * 
     * @param string $mask Die Einladungs-Maske
     * @param string $by Wer die Einladung ausgesprochen hat
     * @return bool Erfolg der Operation
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
     * Prüft, ob ein Benutzer eingeladen ist
     * 
     * @param User $user Der zu prüfende Benutzer
     * @return bool Ob der Benutzer eingeladen ist
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
     * Gibt zurück, wann der Kanal erstellt wurde
     * 
     * @return int Der Unix-Timestamp
     */
    public function getCreationTime(): int {
        return $this->created;
    }
}