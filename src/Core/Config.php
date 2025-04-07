<?php

namespace PhpIrcd\Core;

/**
 * Konfigurationsklasse für den IRC-Server
 */
class Config {
    private $config = [];
    private $defaultConfig = [
        'name' => 'irc.example.org',         // Servername
        'net' => 'ExampleNet',               // Netzwerkname
        'max_len' => 512,                    // Maximale Paketlänge
        'max_users' => 100,                  // Maximale Benutzerzahl
        'port' => 6667,                      // Standard-IRC-Port
        'version' => '1.0.0',                // Serverversion
        'bind_ip' => '0.0.0.0',              // IP-Adresse für den Bind
        'line_ending' => "\n",               // Zeilentrenner für Socket-Kommunikation
        'line_ending_conf' => "\n",          // Zeilentrenner für MOTD usw.
        'ping_interval' => 90,               // Ping-Intervall in Sekunden
        'ping_timeout' => 240,               // Ping-Timeout in Sekunden
        'ssl_enabled' => false,              // SSL-Unterstützung
        'ssl_cert' => '',                    // SSL-Zertifikat
        'ssl_key' => '',                     // SSL-Schlüssel
        'debug_mode' => false,               // Debug-Modus
        'log_level' => 1,                    // 0=Debug, 1=Info, 2=Warn, 3=Error
        'log_file' => 'ircd.log',            // Pfad zur Logdatei
        'motd' => "Willkommen beim PHP-IRCd Server!\nDieser Server läuft auf PHP-IRCd v1.0.0",
    ];
    
    /**
     * Konstruktor
     * 
     * @param string|array $config Optional: Pfad zur Konfigurationsdatei oder Konfigurationsarray
     */
    public function __construct($config = null) {
        // Standardkonfiguration setzen
        $this->config = $this->defaultConfig;
        
        // Konfiguration laden, wenn angegeben
        if (is_string($config) && file_exists($config)) {
            $this->loadFromFile($config);
        } elseif (is_array($config)) {
            $this->loadFromArray($config);
        }
    }
    
    /**
     * Lädt Konfiguration aus einer Datei
     * 
     * @param string $filePath Der Pfad zur Konfigurationsdatei
     * @return bool Erfolg des Ladens
     */
    public function loadFromFile(string $filePath): bool {
        if (!file_exists($filePath)) {
            return false;
        }
        
        // PHP-Datei mit $config-Array einbinden
        $config = [];
        include $filePath;
        
        if (!is_array($config)) {
            return false;
        }
        
        $this->loadFromArray($config);
        return true;
    }
    
    /**
     * Lädt Konfiguration aus einem Array
     * 
     * @param array $config Das Konfigurationsarray
     */
    public function loadFromArray(array $config): void {
        $this->config = array_merge($this->config, $config);
    }
    
    /**
     * Setzt einen Konfigurationswert
     * 
     * @param string $key Der Schlüssel
     * @param mixed $value Der Wert
     */
    public function set(string $key, $value): void {
        $this->config[$key] = $value;
    }
    
    /**
     * Gibt einen Konfigurationswert zurück
     * 
     * @param string $key Der Schlüssel
     * @param mixed $default Optional: Standardwert, wenn Schlüssel nicht existiert
     * @return mixed Der Konfigurationswert oder Standardwert
     */
    public function get(string $key, $default = null) {
        return $this->config[$key] ?? $default;
    }
    
    /**
     * Prüft, ob ein Konfigurationsschlüssel existiert
     * 
     * @param string $key Der Schlüssel
     * @return bool Ob der Schlüssel existiert
     */
    public function has(string $key): bool {
        return isset($this->config[$key]);
    }
    
    /**
     * Gibt alle Konfigurationswerte zurück
     * 
     * @return array Alle Konfigurationswerte
     */
    public function getAll(): array {
        return $this->config;
    }
    
    /**
     * Speichert die Konfiguration in eine Datei
     * 
     * @param string $filePath Der Pfad zur Konfigurationsdatei
     * @return bool Erfolg des Speicherns
     */
    public function saveToFile(string $filePath): bool {
        $configContent = "<?php\n\n";
        $configContent .= "//\n";
        $configContent .= "// PHP-IRCd Konfigurationsdatei\n";
        $configContent .= "// Automatisch generiert von der Config-Klasse\n";
        $configContent .= "//\n\n";
        $configContent .= "\$config = " . var_export($this->config, true) . ";\n";
        
        return file_put_contents($filePath, $configContent) !== false;
    }
    
    /**
     * Magische Getter-Methode für einfachen Zugriff auf Konfigurationswerte
     */
    public function __get(string $name) {
        return $this->get($name);
    }
    
    /**
     * Magische Setter-Methode für einfachen Zugriff auf Konfigurationswerte
     */
    public function __set(string $name, $value): void {
        $this->set($name, $value);
    }
    
    /**
     * Magische Isset-Methode für einfachen Zugriff auf Konfigurationswerte
     */
    public function __isset(string $name): bool {
        return $this->has($name);
    }
}