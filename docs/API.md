# PHP-IRCd API Documentation

## Overview

PHP-IRCd is a modern IRC server implementation written in PHP 8.0+. This document provides comprehensive API documentation for developers who want to extend or modify the server.

## Core Classes

### Server Class (`src/Core/Server.php`)

The main server class that manages the IRC server instance.

#### Key Methods

- `__construct(array $config, bool $webMode = false)` - Initialize server with configuration
- `start()` - Start the server and begin accepting connections
- `shutdown()` - Gracefully shutdown the server
- `addUser(User $user)` - Add a user to the server
- `removeUser(User $user)` - Remove a user from the server
- `addChannel(Channel $channel)` - Add a channel to the server
- `getChannel(string $name)` - Get a channel by name
- `getUsers()` - Get all connected users
- `getChannels()` - Get all channels
- `getSupportedCapabilities()` - Get supported IRCv3 capabilities
- `isCapabilitySupported(string $capability)` - Check if a capability is supported

#### Configuration

The server accepts an array of configuration options:

```php
$config = [
    'name' => 'localhost',              // Server name
    'port' => 6667,                     // IRC port
    'bind_ip' => '127.0.0.1',          // Bind IP
    'max_users' => 50,                  // Maximum users
    'ssl_enabled' => false,             // Enable SSL
    'cap_enabled' => true,              // Enable IRCv3 capabilities
    'ircv3_features' => [               // IRCv3 features
        'server-time' => true,
        'echo-message' => true,
        // ... more features
    ]
];
```

### User Class (`src/Models/User.php`)

Represents a connected IRC user.

#### Key Methods

- `__construct($socket, string $ip, bool $isStreamSocket = false)` - Create user
- `setNick(string $nick)` - Set user nickname
- `setIdent(string $ident)` - Set user ident
- `setRealname(string $realname)` - Set user realname
- `isRegistered()` - Check if user is registered
- `send(string $data)` - Send data to user
- `read(int $maxLen = 512)` - Read data from user
- `disconnect()` - Disconnect user
- `addCapability(string $capability)` - Add IRCv3 capability
- `hasCapability(string $capability)` - Check if user has capability

#### User States

- **Unregistered**: User connected but not yet registered
- **Registered**: User has completed registration (NICK + USER)
- **Authenticated**: User has completed SASL authentication (if enabled)

### Channel Class (`src/Models/Channel.php`)

Represents an IRC channel.

#### Key Methods

- `__construct(string $name)` - Create channel
- `addUser(User $user, bool $asOperator = false)` - Add user to channel
- `removeUser(User $user)` - Remove user from channel
- `setMode(string $mode, bool $value, $param = null)` - Set channel mode
- `hasMode(string $mode)` - Check if mode is set
- `setTopic(string $topic, string $setBy)` - Set channel topic
- `addBan(string $mask, string $by)` - Add ban mask
- `isBanned(User $user)` - Check if user is banned
- `canJoin(User $user, ?string $key = null)` - Check if user can join

#### Channel Modes

- `i` - Invite only
- `k` - Key (password) protected
- `l` - User limit
- `m` - Moderated
- `n` - No external messages
- `t` - Topic protection
- `s` - Secret
- `p` - Private

#### User Modes in Channel

- `~` - Channel owner
- `&` - Channel protected
- `@` - Channel operator
- `%` - Channel half-operator
- `+` - Channel voice

## Command System

### CommandBase Class (`src/Commands/CommandBase.php`)

Base class for all IRC commands.

#### Key Methods

- `execute(User $user, array $args)` - Execute the command (abstract)
- `sendError(User $user, string $command, string $message, int $code)` - Send error
- `ensureRegistered(User $user)` - Ensure user is registered
- `ensureOper(User $user)` - Ensure user is operator
- `getMessagePart(array $args, int $startIndex)` - Get message part from args

### Creating Custom Commands

To create a custom command, extend `CommandBase`:

```php
<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class CustomCommand extends CommandBase {
    public function execute(User $user, array $args): void {
        // Ensure user is registered
        if (!$this->ensureRegistered($user)) {
            return;
        }

        // Check parameters
        if (!isset($args[1])) {
            $this->sendError($user, 'CUSTOM', 'Not enough parameters', 461);
            return;
        }

        // Execute command logic
        $param = $args[1];
        $user->send(":{$this->server->getConfig()['name']} NOTICE {$user->getNick()} :Custom command executed with parameter: {$param}");
    }
}
```

### Registering Commands

Commands are registered in `ConnectionHandler::initCommandHandlers()`:

```php
$this->registerCommandHandler('CUSTOM', new CustomCommand($this->server));
```

## IRCv3 Support

### Capabilities

The server supports various IRCv3 capabilities:

- `server-time` - Add timestamps to messages
- `echo-message` - Echo messages back to sender
- `extended-join` - Extended JOIN with account info
- `chathistory` - Channel message history
- `batch` - Message batching
- `message-tags` - IRCv3 message tags
- `account-notify` - Account change notifications
- `away-notify` - Away status notifications
- `cap-notify` - Capability change notifications
- `chghost` - Hostname change notifications
- `multi-prefix` - Multiple prefixes in NAMES
- `userhost-in-names` - Full hostmasks in NAMES
- `invite-notify` - Invite notifications
- `account-tag` - Account tags in messages

### IRCv3Helper Class (`src/Utils/IRCv3Helper.php`)

Utility class for IRCv3 features:

- `addServerTimeIfSupported(string $message, User $user)` - Add server-time tag
- `addAccountTagIfSupported(string $message, User $user)` - Add account tag
- `addMessageTags(string $message, array $tags)` - Add message tags
- `parseMessageTags(string $message)` - Parse message tags
- `createBatch(string $type, array $params = [])` - Create batch
- `addBatchTag(string $message, string $batchId)` - Add batch tag

## Error Handling

### ErrorHandler Class (`src/Utils/ErrorHandler.php`)

Centralized error handling:

- `handleError(string $type, string $message, array $context = [], ?\Throwable $exception = null)` - Handle errors
- `sendErrorToUser(User $user, string $command, string $message, int $code, string $serverName)` - Send error to user
- `validateInput(string $input, string $type)` - Validate user input
- `hasPermission(User $user, string $action, array $context = [])` - Check permissions
- `sanitizeInput(string $input)` - Sanitize user input

### Error Types

- `ERROR_SOCKET` - Socket-related errors
- `ERROR_CONFIG` - Configuration errors
- `ERROR_COMMAND` - Command execution errors
- `ERROR_USER` - User-related errors
- `ERROR_CHANNEL` - Channel-related errors
- `ERROR_PERMISSION` - Permission errors
- `ERROR_VALIDATION` - Validation errors
- `ERROR_SYSTEM` - System errors

## Logging

### Logger Class (`src/Utils/Logger.php`)

Logging system with different levels:

- `error(string $message)` - Log error
- `warning(string $message)` - Log warning
- `info(string $message)` - Log info
- `debug(string $message)` - Log debug

### Log Levels

- `0` - Error only
- `1` - Warning and above
- `2` - Info and above
- `3` - Debug and above

## Web Interface

### WebInterface Class (`src/Web/WebInterface.php`)

Web-based interface for the IRC server:

- `handleRequest()` - Handle web requests
- `showInterface()` - Display web interface
- `handleConnect()` - Handle connection requests
- `handleSend()` - Handle message sending
- `handleReceive()` - Handle message receiving

## Testing

### Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run specific test class
./vendor/bin/phpunit tests/Unit/ServerTest.php

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage/html
```

### Test Structure

- `tests/Unit/` - Unit tests
  - `ServerTest.php` - Server functionality tests
  - `UserTest.php` - User model tests
  - `ChannelTest.php` - Channel model tests

## Configuration

### Configuration File (`config.php`)

The server uses a PHP configuration file:

```php
<?php

$config = [
    'name' => 'localhost',
    'port' => 6667,
    'bind_ip' => '127.0.0.1',
    'max_users' => 50,
    'ssl_enabled' => false,
    'cap_enabled' => true,
    'ircv3_features' => [
        'server-time' => true,
        'echo-message' => true,
        // ... more features
    ],
    'opers' => [
        'admin' => 'password'
    ],
    'storage_dir' => __DIR__ . '/storage',
    'log_file' => 'ircd.log',
    'log_level' => 2
];
```

### Configuration Validation

The `ErrorHandler::validateConfig()` method validates configuration:

```php
$validation = ErrorHandler::validateConfig($config);
if (!$validation['valid']) {
    foreach ($validation['errors'] as $error) {
        echo "Configuration error: {$error}\n";
    }
}
```

## Security Features

### Input Validation

All user input is validated using `ErrorHandler::validateInput()`:

```php
$validation = ErrorHandler::validateInput($nickname, 'nickname');
if (!$validation['valid']) {
    // Handle invalid input
}
```

### Input Sanitization

User input is sanitized using `ErrorHandler::sanitizeInput()`:

```php
$cleanInput = ErrorHandler::sanitizeInput($userInput);
```

### Permission System

Permissions are checked using `ErrorHandler::hasPermission()`:

```php
if (!ErrorHandler::hasPermission($user, 'channel_operator', ['channel' => $channel])) {
    // Handle insufficient permissions
}
```

## Extension Points

### Custom Commands

Create custom commands by extending `CommandBase` and registering them in `ConnectionHandler`.

### Custom Capabilities

Add custom IRCv3 capabilities by modifying the `$supportedCapabilities` array in the `Server` class.

### Custom Models

Extend the existing models or create new ones for additional functionality.

### Custom Handlers

Create custom handlers for specific functionality by extending existing handler classes.

## Best Practices

1. **Always validate user input** before processing
2. **Use the ErrorHandler** for consistent error handling
3. **Log important events** using the Logger class
4. **Check permissions** before performing actions
5. **Use IRCv3 features** when appropriate
6. **Write tests** for new functionality
7. **Follow IRC standards** for command responses
8. **Handle exceptions** gracefully
9. **Use type hints** and return types
10. **Document your code** with PHPDoc comments

## Troubleshooting

### Common Issues

1. **Socket errors**: Check port availability and firewall settings
2. **SSL errors**: Verify certificate and key files exist and are readable
3. **Permission errors**: Check file permissions for logs and storage
4. **Configuration errors**: Validate configuration using `ErrorHandler::validateConfig()`

### Debug Mode

Enable debug mode in configuration:

```php
'debug_mode' => true,
'log_level' => 3, // Debug level
```

### Log Files

Check log files for detailed error information:

- `ircd.log` - Main server log
- `error.log` - Error log (if configured)

## Contributing

When contributing to PHP-IRCd:

1. Follow the existing code style
2. Add tests for new functionality
3. Update documentation
4. Use the ErrorHandler for error handling
5. Validate all user input
6. Follow IRC standards
7. Test thoroughly before submitting
