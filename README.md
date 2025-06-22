# PHP-IRCd

Ein moderner IRC-Server in PHP 8.0+ mit umfassender IRCv3-Unterstützung, Web-Interface und erweiterten Features.

## 🚀 Features

### Core IRC Features

- ✅ Vollständige IRC-Protokoll-Unterstützung (RFC 1459, RFC 2812)
- ✅ Benutzer-Registrierung und -Verwaltung
- ✅ Kanal-Management mit Modi und Berechtigungen
- ✅ Operator-Befehle und -Rechte
- ✅ Ban/Invite-System
- ✅ Topic-Management
- ✅ Private Nachrichten
- ✅ CTCP-Unterstützung

### IRCv3 Features

- ✅ **server-time** - Zeitstempel in Nachrichten
- ✅ **echo-message** - Echo der eigenen Nachrichten
- ✅ **extended-join** - Erweiterte JOIN-Befehle
- ✅ **chathistory** - Kanal-Nachrichtenhistorie
- ✅ **batch** - Nachrichtenbündelung
- ✅ **message-tags** - IRCv3-Nachrichten-Tags
- ✅ **account-notify** - Konto-Änderungsbenachrichtigungen
- ✅ **away-notify** - Away-Status-Benachrichtigungen
- ✅ **cap-notify** - Capability-Änderungsbenachrichtigungen
- ✅ **chghost** - Hostname-Änderungsbenachrichtigungen
- ✅ **multi-prefix** - Mehrere Präfixe in NAMES
- ✅ **userhost-in-names** - Vollständige Hostmasken in NAMES
- ✅ **invite-notify** - Einladungsbenachrichtigungen
- ✅ **account-tag** - Account-Tags in Nachrichten
- ✅ **SASL-Authentifizierung** - PLAIN, EXTERNAL, SCRAM-SHA-1, SCRAM-SHA-256

### Erweiterte Features

- ✅ **Web-Interface** - Browser-basierte IRC-Oberfläche
- ✅ **SSL/TLS-Unterstützung** - Sichere Verbindungen
- ✅ **IP-Filtering** - Whitelist/Blacklist-System
- ✅ **Hostname-Cloaking** - Verschleierung von Hostnamen
- ✅ **Persistente Kanäle** - Kanäle überleben Server-Neustarts
- ✅ **WATCH-System** - Benutzer-Überwachung
- ✅ **SILENCE-System** - Benutzer-Stummschaltung
- ✅ **WHOWAS-Historie** - Benutzer-Historie
- ✅ **Server-zu-Server-Verbindungen** - Netzwerk-Support
- ✅ **Umfassende Protokollierung** - Detaillierte Logs
- ✅ **Konfigurationsvalidierung** - Automatische Konfigurationsprüfung

### Sicherheitsfeatures

- ✅ **Input-Validierung** - Umfassende Eingabevalidierung
- ✅ **Input-Sanitization** - Eingabebereinigung
- ✅ **Berechtigungssystem** - Granulare Berechtigungen
- ✅ **Error-Handling** - Zentrale Fehlerbehandlung
- ✅ **Rate-Limiting** - Schutz vor Spam
- ✅ **Reservierte Nicknames** - Schutz vor Missbrauch

## 📋 Voraussetzungen

- **PHP 8.0 oder höher**
- **Composer** für Dependency Management
- **OpenSSL-Extension** (für SSL/TLS)
- **Socket-Extension** (standardmäßig in PHP enthalten)

## 🛠️ Installation

### 1. Repository klonen

```bash
git clone https://github.com/your-repo/php-ircd.git
cd php-ircd
```

### 2. Dependencies installieren

```bash
composer install
```

### 3. Konfiguration anpassen

Die Konfigurationsdatei `config.php` nach Ihren Bedürfnissen anpassen:

```php
$config = [
    'name' => 'MeinIRC-Server',
    'port' => 6667,
    'bind_ip' => '0.0.0.0',  // Für externe Verbindungen
    'max_users' => 100,
    'ssl_enabled' => false,   // Auf true setzen für SSL
    'cap_enabled' => true,    // IRCv3-Features aktivieren
    // ... weitere Einstellungen
];
```

### 4. Server starten

#### Windows

```bash
server.bat
```

#### Linux/macOS

```bash
php index.php
```

#### Als Daemon (Linux)

```bash
nohup php index.php > ircd.log 2>&1 &
```

## 🌐 Web-Interface

Das Web-Interface ist unter `http://localhost/index.php` verfügbar und bietet:

- **Live-Chat** - Echtzeit-Nachrichten
- **Benutzerliste** - Aktuelle Benutzer
- **Kanal-Management** - Kanäle erstellen und verwalten
- **Server-Status** - Server-Informationen
- **Einfache Bedienung** - Kein IRC-Client erforderlich

## 🧪 Tests

### Tests ausführen

```bash
# Alle Tests
./vendor/bin/phpunit

# Spezifische Tests
./vendor/bin/phpunit tests/Unit/ServerTest.php

# Mit Code-Coverage
./vendor/bin/phpunit --coverage-html coverage/html
```

### Test-Coverage

- **Server-Klasse** - Vollständige Server-Funktionalität
- **User-Model** - Benutzer-Management
- **Channel-Model** - Kanal-Management
- **Commands** - IRC-Befehle
- **Error-Handling** - Fehlerbehandlung

## 📖 Dokumentation

### API-Dokumentation

Vollständige API-Dokumentation finden Sie in [`docs/API.md`](docs/API.md).

### Konfiguration

Detaillierte Konfigurationsoptionen:

```php
$config = [
    // Server-Grundlagen
    'name' => 'localhost',              // Server-Name
    'net' => 'Lokaler-IRC',             // Netzwerk-Name
    'port' => 6667,                     // IRC-Port
    'bind_ip' => '127.0.0.1',          // Bind-IP
    'max_users' => 50,                  // Maximale Benutzer

    // SSL/TLS
    'ssl_enabled' => false,             // SSL aktivieren
    'ssl_cert' => 'path/to/cert.pem',   // SSL-Zertifikat
    'ssl_key' => 'path/to/key.pem',     // SSL-Schlüssel

    // IRCv3-Features
    'cap_enabled' => true,              // IRCv3 aktivieren
    'ircv3_features' => [               // IRCv3-Features
        'server-time' => true,
        'echo-message' => true,
        'extended-join' => true,
        'chathistory' => true,
        // ... weitere Features
    ],

    // SASL
    'sasl_enabled' => true,             // SASL aktivieren
    'sasl_mechanisms' => ['PLAIN', 'EXTERNAL'],

    // Sicherheit
    'ip_filtering_enabled' => false,    // IP-Filtering
    'ip_whitelist' => [],               // IP-Whitelist
    'ip_blacklist' => [],               // IP-Blacklist

    // Logging
    'log_level' => 2,                   // Log-Level (0-3)
    'log_file' => 'ircd.log',           // Log-Datei
    'log_to_console' => true,           // Konsolen-Logging

    // Operatoren
    'opers' => [                        // IRC-Operatoren
        'admin' => 'password'
    ],

    // Speicher
    'storage_dir' => 'storage',         // Speicher-Verzeichnis
    'chathistory_max_messages' => 100,  // Chat-Historie-Limit
];
```

## 🔧 Erweiterte Konfiguration

### SSL/TLS aktivieren

```php
$config['ssl_enabled'] = true;
$config['ssl_cert'] = '/path/to/certificate.pem';
$config['ssl_key'] = '/path/to/private.key';
```

### IP-Filtering

```php
$config['ip_filtering_enabled'] = true;
$config['ip_filter_mode'] = 'blacklist'; // oder 'whitelist'
$config['ip_blacklist'] = ['192.168.1.100', '10.0.0.0/8'];
```

### Server-zu-Server-Verbindungen

```php
$config['enable_server_links'] = true;
$config['server_password'] = 'secret_password';
$config['auto_connect_servers'] = [
    'server2' => [
        'host' => 'server2.example.com',
        'port' => 6667,
        'password' => 'link_password',
        'ssl' => false
    ]
];
```

## 🚨 Sicherheitshinweise

### Produktionsumgebung

1. **SSL/TLS aktivieren** für sichere Verbindungen
2. **Starke Passwörter** für Operatoren verwenden
3. **IP-Filtering** für unerwünschte Verbindungen
4. **Log-Level** auf 1 oder 2 setzen
5. **Firewall** konfigurieren
6. **Regelmäßige Updates** durchführen

### Entwicklungsumgebung

1. **Debug-Modus** aktivieren für detaillierte Logs
2. **Log-Level** auf 3 setzen
3. **Test-Daten** verwenden
4. **Unit-Tests** vor Deployment ausführen

## 🐛 Troubleshooting

### Häufige Probleme

#### Server startet nicht

```bash
# Port prüfen
netstat -an | grep 6667

# Firewall prüfen
sudo ufw status

# Logs prüfen
tail -f ircd.log
```

#### SSL-Fehler

```bash
# Zertifikat prüfen
openssl x509 -in cert.pem -text -noout

# Berechtigungen prüfen
ls -la cert.pem key.pem
```

#### Verbindungsprobleme

```bash
# Socket-Status prüfen
php -m | grep socket

# OpenSSL-Status prüfen
php -m | grep openssl
```

### Debug-Modus

```php
$config['debug_mode'] = true;
$config['log_level'] = 3;
```

## 🤝 Beitragen

Wir freuen uns über Beiträge! Bitte beachten Sie:

1. **Code-Stil** - PSR-12 befolgen
2. **Tests** - Neue Features testen
3. **Dokumentation** - API-Docs aktualisieren
4. **Error-Handling** - ErrorHandler verwenden
5. **Validierung** - Input validieren
6. **IRC-Standards** - RFCs befolgen

### Entwicklungsumgebung einrichten

```bash
# Repository klonen
git clone https://github.com/your-repo/php-ircd.git
cd php-ircd

# Dependencies installieren
composer install

# Tests ausführen
./vendor/bin/phpunit

# Code-Coverage generieren
./vendor/bin/phpunit --coverage-html coverage/html
```

## 📄 Lizenz

Dieses Projekt steht unter der MIT-Lizenz. Siehe [LICENSE](LICENSE) für Details.

## 🙏 Danksagungen

- **Daniel Danopia** - Original-Autor (2008)
- **Zhaofeng Li** - Beiträge
- **Easton Elliott** - Refactoring
- **Avram Lyon** - Verbesserungen
- **henrikhjelm** - PHP 7.4 Support
- **Jonas Pfalzgraf (JosunLP)** - PHP 8 Support

## 📞 Support

- **Issues**: [GitHub Issues](https://github.com/your-repo/php-ircd/issues)
- **Discussions**: [GitHub Discussions](https://github.com/your-repo/php-ircd/discussions)
- **Wiki**: [GitHub Wiki](https://github.com/your-repo/php-ircd/wiki)

## 🔄 Changelog

### Version 2.0.0 (2025)

- ✅ Vollständige IRCv3-Unterstützung
- ✅ Web-Interface hinzugefügt
- ✅ Umfassende Tests implementiert
- ✅ Error-Handling verbessert
- ✅ Sicherheitsfeatures erweitert
- ✅ Dokumentation vervollständigt
- ✅ Code-Qualität verbessert

### Version 1.0.0 (2008-2024)

- ✅ Grundlegende IRC-Funktionalität
- ✅ Benutzer- und Kanal-Management
- ✅ Operator-Befehle
- ✅ Verschiedene PHP-Versionen unterstützt

---

**PHP-IRCd** - Ein moderner, sicherer und erweiterbarer IRC-Server in PHP! 🚀
