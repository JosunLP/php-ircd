<?php

//
// PHP-IRCd configuration file
// Created on April 21, 2025
//

$config = [
    'name' => 'localhost',                   // Server name
    'net' => 'Local-IRC',                    // Network name
    'network_name' => 'Local-IRC',           // Network name (for ISUPPORT)
    'max_len' => 512,                        // Maximum packet length
    'max_users' => 50,                       // Maximum number of users
    'port' => 6667,                          // Default IRC port
    'version' => 1.0,                        // Server version
    'bind_ip' => '127.0.0.1',                // IP address for binding
    'line_ending' => "\r\n",                 // Line ending for socket communication
    'line_ending_conf' => "\n",              // Line ending for MOTD, etc.
    'ping_interval' => 90,                   // Ping interval in seconds
    'ping_timeout' => 240,                   // Ping timeout in seconds
    'ssl_enabled' => false,                  // SSL support
    'ssl_cert' => '',                        // SSL certificate
    'ssl_key' => '',                         // SSL key
    'debug_mode' => true,                    // Debug mode
    'log_level' => 0,                        // 0=Debug, 1=Info, 2=Warn, 3=Error
    'log_file' => 'ircd.log',                // Path to log file
    'motd' => "Welcome to your local IRC test server!\n\nThis server runs on localhost and is intended for testing.\n\nYou can become an IRC operator with the following command:\n/OPER admin test123\n\nHave fun testing!",
    'description' => 'PHP-IRCd test server',  // Server description for LINKS command
    'opers' => [
        'admin' => 'test123',                // Default operator credentials
    ],
    'operator_passwords' => [                // Passwords for authentication
        'admin' => 'test123',                // As in the server.bat file
    ],
    'storage_dir' => __DIR__ . '/storage',   // Directory for data storage
    'log_to_console' => true,                // Show log in console

    // Admin information for the ADMIN command
    'admin_name' => 'PHP-IRCd Administrator',  // Administrator name
    'admin_email' => 'admin@example.com',      // Administrator email
    'admin_location' => 'Local',               // Server location

    // Server information for the INFO command
    'server_info' => [
        'PHP-IRCd Server based on Danoserv',
        'Running on PHP 8.0+',
        'Created in April 2025',
        'Originally created by Daniel Danopia (2008)',
        'With web interface for easy usage'
    ],

    // Server-to-server communication
    'enable_server_links' => false,           // Enable server-to-server connections
    'server_password' => '',                  // Password for server connections
    'hub_mode' => false,                      // Run server as hub (mediates between servers)
    'auto_connect_servers' => [],             // Automatically connect to these servers

    // IRCv3 features
    'cap_enabled' => true,                    // Enable IRCv3 capability negotiation
    'sasl_enabled' => true,                   // Enable SASL authentication
    'sasl_mechanisms' => ['PLAIN', 'EXTERNAL', 'SCRAM-SHA-1', 'SCRAM-SHA-256'], // Supported SASL mechanisms
    'sasl_users' => [                         // SASL user accounts
        // Example: 'id' => ['username' => 'user', 'password' => 'pass']
    ],

    // IRCv3 extended features
    'ircv3_features' => [                     // IRCv3 Feature-Set
        'multi-prefix' => true,               // Multiple prefixes for users in channel
        'away-notify' => true,                // Notification when user away-status changes
        'server-time' => true,                // Timestamp for messages
        'batch' => true,                      // Message bundling
        'message-tags' => true,               // Tags in messages
        'echo-message' => true,               // Echo of own messages
        'invite-notify' => true,              // Notifications about invitations
        'extended-join' => true,              // Extended JOIN commands with Realname
        'userhost-in-names' => true,          // Full hostmasks in NAMES list
        'chathistory' => true,                // Retrieval of channel history
        'account-notify' => true,             // Account authentication changes
        'account-tag' => true,                // Account-Tags in messages
        'cap-notify' => true,                 // Notifications about CAP changes
        'chghost' => true,                    // Host change notifications
    ],

    'chathistory_max_messages' => 100,        // Maximum number of messages in channel history

    // IP filtering settings
    'ip_filtering_enabled' => false,          // Enable/disable IP filtering
    'ip_whitelist' => [],                     // Whitelist of allowed IP addresses
    'ip_blacklist' => [],                     // Blacklist of disallowed IP addresses
    'ip_filter_mode' => 'blacklist',          // Filter mode: 'blacklist' or 'whitelist'

    // Extended functions
    'cloak_hostnames' => true,                // Hide hostnames
    'max_watch_entries' => 128,               // Maximum number of WATCH entries
    'max_silence_entries' => 15,              // Maximum number of SILENCE entries
    'default_user_modes' => '',               // Default user modes
    'default_channel_modes' => 'nt',          // Default channel modes
    'max_channels_per_user' => 10,            // Maximum number of channels per user
];

return $config;
