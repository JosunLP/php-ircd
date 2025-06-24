# PHP-IRCd

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.0%2B-blue.svg" alt="PHP 8.0+">
  <img src="https://img.shields.io/badge/License-MIT-green.svg" alt="MIT License">
  <img src="https://img.shields.io/badge/Status-Active-brightgreen.svg" alt="Project Status">
  <img src="https://img.shields.io/badge/IRCv3-Supported-blueviolet.svg" alt="IRCv3 Support">
</p>

<p align="center">
  <b>A modern, secure, and extensible IRC server in PHP!</b>
</p>

---

## 📑 Table of Contents

- [PHP-IRCd](#php-ircd)
  - [📑 Table of Contents](#-table-of-contents)
  - [🚀 Features](#-features)
    - [Core IRC](#core-irc)
    - [IRCv3](#ircv3)
    - [Extended](#extended)
    - [Security](#security)
  - [📋 Prerequisites](#-prerequisites)
  - [🛠️ Installation](#️-installation)
  - [⚙️ Configuration](#️-configuration)
  - [▶️ Starting the Server](#️-starting-the-server)
  - [🌐 Web Interface](#-web-interface)
  - [🧪 Testing](#-testing)
  - [📖 Documentation](#-documentation)
  - [🔧 Advanced Configuration](#-advanced-configuration)
  - [🚨 Security Notes](#-security-notes)
  - [🐛 Troubleshooting](#-troubleshooting)
  - [🤝 Contributing](#-contributing)
  - [📄 License](#-license)
  - [🙏 Acknowledgements](#-acknowledgements)

---

## 🚀 Features

### Core IRC

- Full IRC protocol support (RFC 1459, RFC 2812)
- User registration & management
- Channel management with permissions
- Operator commands & rights
- Ban/invite system
- Topic management
- Private messages
- CTCP support

### IRCv3

- `server-time`, `echo-message`, `extended-join`, `chathistory`, `batch`, `message-tags`
- `account-notify`, `away-notify`, `cap-notify`, `chghost`, `multi-prefix`, `userhost-in-names`
- `invite-notify`, `account-tag`, `SASL` (PLAIN, EXTERNAL, SCRAM-SHA-1, SCRAM-SHA-256)

### Extended

- 🌐 **Web Interface** (REST API + WebSocket)
- 🔒 SSL/TLS support
- 🛡️ IP filtering (whitelist/blacklist)
- 🕵️ Hostname cloaking
- 💾 Persistent channels
- 👁️ WATCH & SILENCE systems
- 🕰️ WHOWAS history
- 🌐 Server-to-server connections (optional)
- 📝 Logging (file & console)
- 🧪 Configuration validation

### Security

- Input validation & sanitization
- Granular permission system
- Centralized error handling
- Rate limiting
- Reserved nicknames

---

## 📋 Prerequisites

- **PHP 8.0+**
- **Composer**
- **OpenSSL extension** (for SSL/TLS)
- **Socket extension** (standard in PHP)

---

## 🛠️ Installation

```bash
# 1. Clone the repository
$ git clone https://github.com/your-repo/php-ircd.git
$ cd php-ircd

# 2. Install dependencies
$ composer install
```

---

## ⚙️ Configuration

Edit `config.php` to fit your needs. Example:

```php
$config = [
    'name' => 'localhost',
    'port' => 6667,
    'bind_ip' => '0.0.0.0',
    'max_users' => 100,
    'ssl_enabled' => false,
    'cap_enabled' => true,
    // ... other settings
];
```

See `config.php` for all available options and documentation inline.

---

## ▶️ Starting the Server

- **Windows:**

  ```bash
  server.bat
  ```

- **Linux/macOS:**

  ```bash
  php index.php
  ```

- **As Daemon (Linux):**

  ```bash
  nohup php index.php > ircd.log 2>&1 &
  ```

---

## 🌐 Web Interface

- **REST API:** See [`src/Web/api_router.php`](src/Web/api_router.php)
- **WebSocket server:**

  ```bash
  php src/Web/ws_server.php
  ```

  (Requires Ratchet and JWT via Composer)

**Features:**

- Live chat
- User list
- Channel management
- Server status

---

## 🧪 Testing

- **Run all tests:**

  ```bash
  ./vendor/bin/phpunit
  ```

- **Run specific test:**

  ```bash
  ./vendor/bin/phpunit tests/Unit/ServerTest.php
  ```

- **With code coverage:**

  ```bash
  ./vendor/bin/phpunit --coverage-html coverage/html
  ```

---

## 📖 Documentation

- **API:** See [`docs/API.md`](docs/API.md)
- **Configuration:** See `config.php` for all options and defaults.

---

## 🔧 Advanced Configuration

- **SSL/TLS:**

  ```php
  $config['ssl_enabled'] = true;
  $config['ssl_cert'] = '/path/to/certificate.pem';
  $config['ssl_key'] = '/path/to/private.key';
  ```

- **IP Filtering:**

  ```php
  $config['ip_filtering_enabled'] = true;
  $config['ip_filter_mode'] = 'blacklist'; // or 'whitelist'
  $config['ip_blacklist'] = ['192.168.1.100', '10.0.0.0/8'];
  ```

- **Server-to-server:**

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

---

## 🚨 Security Notes

- Enable SSL/TLS for production
- Use strong operator passwords
- Configure IP filtering and firewall
- Set appropriate log level
- Regularly update dependencies

---

## 🐛 Troubleshooting

- **Check logs:** `ircd.log`
- **SSL errors:** Verify certificate and permissions
- **Connection issues:** Check PHP extensions and firewall
- **Debug mode:**

  ```php
  $config['debug_mode'] = true;
  $config['log_level'] = 0; // 0=Debug, 1=Info, 2=Warn, 3=Error
  ```

---

## 🤝 Contributing

We welcome contributions! Please:

- Follow **PSR-12** code style
- Add tests for new features
- Update documentation
- Use centralized error handling (`ErrorHandler`)
- Validate all input
- Follow IRC RFCs

---

## 📄 License

MIT License. See [LICENSE](LICENSE).

---

## 🙏 Acknowledgements

- **Daniel Danopia** - Original author (2008)
- **Zhaofeng Li** - Contributions
- **Easton Elliott** - Refactoring
- **Avram Lyon** - Improvements
- **henrikhjelm** - PHP 7.4 support
- **Jonas Pfalzgraf (JosunLP)** - PHP 8 support

---

<p align="center"><b>PHP-IRCd</b> - A modern, secure, and extensible IRC server in PHP! 🚀</p>
