<?php

//
// PHP-IRCd Konfigurationsdatei
// Erstellt am 21. April 2025
//

$config = [
    'name' => 'localhost',                   // Server Name
    'net' => 'Lokaler-IRC',                  // Netzwerk Name
    'network_name' => 'Lokaler-IRC',         // Network Name (for ISUPPORT)
    'max_len' => 512,                        // Maximale Paketlänge
    'max_users' => 50,                       // Maximale Anzahl von Benutzern
    'port' => 6667,                          // Standard IRC-Port
    'version' => 1.0,                        // Server-Version
    'bind_ip' => '127.0.0.1',                // IP-Adresse für Binding
    'line_ending' => "\r\n",                 // Zeilenumbruch für Socket-Kommunikation
    'line_ending_conf' => "\n",              // Zeilenumbruch für MOTD, usw.
    'ping_interval' => 90,                   // Ping-Intervall in Sekunden
    'ping_timeout' => 240,                   // Ping-Timeout in Sekunden
    'ssl_enabled' => false,                  // SSL-Unterstützung
    'ssl_cert' => '',                        // SSL-Zertifikat
    'ssl_key' => '',                         // SSL-Schlüssel
    'debug_mode' => true,                    // Debug-Modus
    'log_level' => 0,                        // 0=Debug, 1=Info, 2=Warn, 3=Error
    'log_file' => 'ircd.log',                // Pfad zur Log-Datei
    'motd' => "Willkommen bei deinem lokalen IRC-Testserver!\n\nDieser Server läuft auf localhost und ist zum Testen gedacht.\n\nDu kannst IRC-Operator werden mit folgendem Befehl:\n/OPER admin test123\n\nViel Spaß beim Testen!",
    'description' => 'PHP-IRCd Testserver',  // Server-Beschreibung für LINKS-Befehl
    'opers' => [
        'admin' => 'test123',                // Standard-Operator-Anmeldedaten
    ],
    'operator_passwords' => [                // Passwörter für Authentifizierung
        'admin' => 'test123',                // Entsprechend der server.bat Datei
    ],
    'storage_dir' => __DIR__ . '/storage',   // Verzeichnis für Datenspeicherung
    'log_to_console' => true,                // Log in Konsole anzeigen

    // Admin-Informationen für den ADMIN-Befehl
    'admin_name' => 'PHP-IRCd Administrator',  // Administrator-Name
    'admin_email' => 'admin@example.com',      // Administrator-E-Mail
    'admin_location' => 'Local',               // Server-Standort

    // Server-Informationen für den INFO-Befehl
    'server_info' => [
        'PHP-IRCd Server based on Danoserv',
        'Running on PHP 8.0+',
        'Created in April 2025',
        'Originally created by Daniel Danopia (2008)',
        'With web interface for easy usage'
    ],

    // Server-zu-Server-Kommunikation
    'enable_server_links' => false,           // Server-zu-Server-Verbindungen aktivieren
    'server_password' => '',                  // Passwort für Server-Verbindungen
    'hub_mode' => false,                      // Server als Hub betreiben (vermittelt zwischen Servern)
    'auto_connect_servers' => [],             // Automatisch mit diesen Servern verbinden

    // IRCv3-Features
    'cap_enabled' => true,                    // IRCv3 Capability Negotiation aktivieren
    'sasl_enabled' => true,                   // SASL-Authentifizierung aktivieren
    'sasl_mechanisms' => ['PLAIN', 'EXTERNAL', 'SCRAM-SHA-1', 'SCRAM-SHA-256'], // Unterstützte SASL-Mechanismen
    'sasl_users' => [                         // SASL-Benutzerkonten
        // Beispiel: 'id' => ['username' => 'user', 'password' => 'pass']
    ],

    // IRCv3 erweiterte Features
    'ircv3_features' => [                     // IRCv3 Feature-Set
        'multi-prefix' => true,               // Mehrere Präfixe für Benutzer im Kanal
        'away-notify' => true,                // Benachrichtigung wenn Benutzer away-Status ändert
        'server-time' => true,                // Zeitstempel für Nachrichten
        'batch' => true,                      // Nachrichtenbündelung
        'message-tags' => true,               // Tags in Nachrichten
        'echo-message' => true,               // Echo der eigenen Nachrichten
        'invite-notify' => true,              // Benachrichtigungen über Einladungen
        'extended-join' => true,              // Erweiterte JOIN-Befehle mit Realname
        'userhost-in-names' => true,          // Vollständige Hostmasken in NAMES-Liste
        'chathistory' => true,                // Abruf der Kanalhistorie
        'account-notify' => true,             // Kontoauthentifizierungsänderungen
        'account-tag' => true,                // Account-Tags in Nachrichten
        'cap-notify' => true,                 // Benachrichtigungen über CAP-Änderungen
        'chghost' => true,                    // Host-Änderungsbenachrichtigungen
    ],

    'chathistory_max_messages' => 100,        // Maximale Anzahl von Nachrichten in der Chathistorie

    // IP-Filtering-Einstellungen
    'ip_filtering_enabled' => false,          // IP-Filterung aktivieren/deaktivieren
    'ip_whitelist' => [],                     // Whitelist von erlaubten IP-Adressen
    'ip_blacklist' => [],                     // Blacklist von verbotenen IP-Adressen
    'ip_filter_mode' => 'blacklist',          // Filtermodus: 'blacklist' oder 'whitelist'

    // Erweiterte Funktionen
    'cloak_hostnames' => true,                // Hostnames verschleiern
    'max_watch_entries' => 128,               // Maximale Anzahl von WATCH-Einträgen
    'max_silence_entries' => 15,              // Maximale Anzahl von SILENCE-Einträgen
    'default_user_modes' => '',               // Standard-Benutzermodi
    'default_channel_modes' => 'nt',          // Standard-Kanalmodi
    'max_channels_per_user' => 10,            // Maximale Anzahl von Kanälen pro Benutzer
];

return $config;
