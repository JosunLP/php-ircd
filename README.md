# PHP-IRCd

A modern IRC-Server in PHP 8.0+ with comprehensive IRCv3-Support, Web-Interface and extended features.

## 🚀 Features

### Core IRC Features

- ✅ Full IRC-Protocol-Support (RFC 1459, RFC 2812)
- ✅ User-Registration and -Management
- ✅ Channel-Management with Mods and Permissions
- ✅ Operator-Commands and -Rights
- ✅ Ban/Invite-System
- ✅ Topic-Management
- ✅ Private Messages
- ✅ CTCP-Support

### IRCv3 Features

- ✅ **server-time** - Timestamp in Messages
- ✅ **echo-message** - Echo of own Messages
- ✅ **extended-join** - Extended JOIN-Commands
- ✅ **chathistory** - Channel-MessageHistory
- ✅ **batch** - MessageBundling
- ✅ **message-tags** - IRCv3-Message-Tags
- ✅ **account-notify** - Account-ChangeNotifications
- ✅ **away-notify** - Away-Status-Notifications
- ✅ **cap-notify** - Capability-ChangeNotifications
- ✅ **chghost** - Hostname-ChangeNotifications
- ✅ **multi-prefix** - Multiple Prefixes in NAMES
- ✅ **userhost-in-names** - Full Hostmasks in NAMES
- ✅ **invite-notify** - InvitationNotifications
- ✅ **account-tag** - Account-Tags in Messages
- ✅ **SASL-Authentication** - PLAIN, EXTERNAL, SCRAM-SHA-1, SCRAM-SHA-256

### Extended Features

- ✅ **Web-Interface** - Browser-based IRC-Interface
- ✅ **SSL/TLS-Support** - Secure Connections
- ✅ **IP-Filtering** - Whitelist/Blacklist-System
- ✅ **Hostname-Cloaking** - Hostname-Masking
- ✅ **Persistent Channels** - Channels survive Server-Restarts
- ✅ **WATCH-System** - User-Monitoring
- ✅ **SILENCE-System** - User-Mute
- ✅ **WHOWAS-History** - User-History
- ✅ **Server-to-Server Connections** - Network-Support
- ✅ **Comprehensive Logging** - Detailed Logs
- ✅ **Configuration Validation** - Automatic Configuration Check

### Security Features

- ✅ **Input Validation** - Comprehensive Input Validation
- ✅ **Input Sanitization** - Input Cleaning
- ✅ **Permission System** - Granular Permissions
- ✅ **Error Handling** - Central Error Handling
- ✅ **Rate Limiting** - Protection against Spam
- ✅ **Reserved Nicknames** - Protection against Abuse

## 📋 Prerequisites

- **PHP 8.0 or higher**
- **Composer** for Dependency Management
- **OpenSSL-Extension** (for SSL/TLS)
- **Socket-Extension** (standard in PHP)

## 🛠️ Installation

### 1. Repository clone

```bash
git clone https://github.com/your-repo/php-ircd.git
cd php-ircd
```

### 2. Dependencies install

```bash
composer install
```

### 3. Configuration adjust

The configuration file `config.php` to your needs:

```php
$config = [
    'name' => 'MeinIRC-Server',
    'port' => 6667,
    'bind_ip' => '0.0.0.0',  // For external connections
    'max_users' => 100,
    'ssl_enabled' => false,   // Set to true for SSL
    'cap_enabled' => true,    // Activate IRCv3-Features
    // ... other settings
];
```

### 4. Server start

#### Windows

```bash
server.bat
```

#### Linux/macOS

```bash
php index.php
```

#### As Daemon (Linux)

```bash
nohup php index.php > ircd.log 2>&1 &
```

## 🌐 Web-Interface

The Web-Interface is available under `http://localhost/index.php` and offers:

- **Live-Chat** - Real-Time Messages
- **Userlist** - Current Users
- **Channel-Management** - Create and Manage Channels
- **Server-Status** - Server Information
- **Simple Operation** - No IRC-Client required

## 🧪 Tests

### Run Tests

```bash
# All Tests
./vendor/bin/phpunit

# Specific Tests
./vendor/bin/phpunit tests/Unit/ServerTest.php

# With Code-Coverage
./vendor/bin/phpunit --coverage-html coverage/html
```

### Test Coverage

- **Server class** - Full server functionality
- **User model** - User management
- **Channel model** - Channel management
- **Commands** - IRC commands
- **Error handling** - Error handling

## 📖 Documentation

### API Documentation

Full API documentation can be found in [`docs/API.md`](docs/API.md).

### Configuration

Detailed configuration options:

```php
$config = [
    // Server basics
    'name' => 'localhost',              // Server name
    'net' => 'Local-IRC',               // Network name
    'port' => 6667,                     // IRC port
    'bind_ip' => '127.0.0.1',           // Bind IP
    'max_users' => 50,                  // Maximum users

    // SSL/TLS
    'ssl_enabled' => false,             // Enable SSL
    'ssl_cert' => 'path/to/cert.pem',   // SSL certificate
    'ssl_key' => 'path/to/key.pem',     // SSL key

    // IRCv3 features
    'cap_enabled' => true,              // Enable IRCv3
    'ircv3_features' => [               // IRCv3 features
        'server-time' => true,
        'echo-message' => true,
        'extended-join' => true,
        'chathistory' => true,
        // ... more features
    ],

    // SASL
    'sasl_enabled' => true,             // Enable SASL
    'sasl_mechanisms' => ['PLAIN', 'EXTERNAL'],

    // Security
    'ip_filtering_enabled' => false,    // IP filtering
    'ip_whitelist' => [],               // IP whitelist
    'ip_blacklist' => [],               // IP blacklist

    // Logging
    'log_level' => 2,                   // Log level (0-3)
    'log_file' => 'ircd.log',           // Log file
    'log_to_console' => true,           // Console logging

    // Operators
    'opers' => [                        // IRC operators
        'admin' => 'password'
    ],

    // Storage
    'storage_dir' => 'storage',         // Storage directory
    'chathistory_max_messages' => 100,  // Chat history limit
];
```

## 🔧 Advanced Configuration

### Enable SSL/TLS

```php
$config['ssl_enabled'] = true;
$config['ssl_cert'] = '/path/to/certificate.pem';
$config['ssl_key'] = '/path/to/private.key';
```

### IP Filtering

```php
$config['ip_filtering_enabled'] = true;
$config['ip_filter_mode'] = 'blacklist'; // or 'whitelist'
$config['ip_blacklist'] = ['192.168.1.100', '10.0.0.0/8'];
```

### Server-to-server connections

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

## 🚨 Security Notes

### Production Environment

1. **Enable SSL/TLS** for secure connections
2. **Use strong passwords** for operators
3. **IP filtering** for unwanted connections
4. **Set log level** to 1 or 2
5. **Configure firewall**
6. **Perform regular updates**

### Development Environment

1. **Enable debug mode** for detailed logs
2. **Set log level** to 3
3. **Use test data**
4. **Run unit tests before deployment**

## 🐛 Troubleshooting

### Common Issues

#### Server does not start

```bash
# Check port
netstat -an | grep 6667

# Check firewall
sudo ufw status

# Check logs
tail -f ircd.log
```

#### SSL errors

```bash
# Check certificate
openssl x509 -in cert.pem -text -noout

# Check permissions
ls -la cert.pem key.pem
```

#### Connection issues

```bash
# Check socket status
php -m | grep socket

# Check OpenSSL status
php -m | grep openssl
```

### Debug mode

```php
$config['debug_mode'] = true;
$config['log_level'] = 3;
```

## 🤝 Contributing

We welcome contributions! Please note:

1. **Code style** - Follow PSR-12
2. **Tests** - Test new features
3. **Documentation** - Update API docs
4. **Error handling** - Use ErrorHandler
5. **Validation** - Validate input
6. **IRC standards** - Follow RFCs

### Setting up development environment

```bash
# Clone repository
git clone https://github.com/your-repo/php-ircd.git
cd php-ircd

# Install dependencies
composer install

# Run tests
./vendor/bin/phpunit

# Generate code coverage
./vendor/bin/phpunit --coverage-html coverage/html
```

## 📄 License

This project is licensed under the MIT License. See [LICENSE](LICENSE) for details.

## 🙏 Acknowledgements

- **Daniel Danopia** - Original author (2008)
- **Zhaofeng Li** - Contributions
- **Easton Elliott** - Refactoring
- **Avram Lyon** - Improvements
- **henrikhjelm** - PHP 7.4 support
- **Jonas Pfalzgraf (JosunLP)** - PHP 8 support

## 📞 Support

- **Issues**: [GitHub Issues](https://github.com/your-repo/php-ircd/issues)
- **Discussions**: [GitHub Discussions](https://github.com/your-repo/php-ircd/discussions)
- **Wiki**: [GitHub Wiki](https://github.com/your-repo/php-ircd/wiki)

## 🔄 Changelog

### Version 2.0.0 (2025)

- ✅ Full IRCv3 support
- ✅ Web interface added
- ✅ Comprehensive tests implemented
- ✅ Improved error handling
- ✅ Extended security features
- ✅ Completed documentation
- ✅ Improved code quality

### Version 1.0.0 (2008-2024)

- ✅ Basic IRC functionality
- ✅ User and channel management
- ✅ Operator commands
- ✅ Various PHP versions supported

---

**PHP-IRCd** - A modern, secure, and extensible IRC server in PHP! 🚀
