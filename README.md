# PHP-IRCd

An IRC server written in sloppy PHP.

Various basic features are implemented
such as mode and membership tracking and some /OPER commands.

The latest commits here require PHP 8.
See below table for older PHP versions.

## Codebase Documentation

PHP-IRCd is an IRC server written in PHP with the following characteristics:

### Structure

- `src/Commands/`: IRC commands (JOIN, NICK, PRIVMSG etc.)
- `src/Core/`: Core functionality (Server, Configuration)
- `src/Handlers/`: Connection management
- `src/Models/`: Data models (Users, Channels)
- `src/Utils/`: Helper functions (Logger)
- `src/Web/`: Web interface for the IRC server

### Installation and Startup

1. PHP 8.0 or higher is required
2. Install dependencies: `composer install`
3. Start server: `php index.php` or `server.bat` (Windows)

### Configuration

Configuration can be customized through the `config.legacy.php` file or by creating your own configuration file. The most important settings are:

- Server name and network
- Port (default: 6667)
- Binding IP (default: 0.0.0.0)
- Maximum number of users
- IRC operators

### Features

- Basic IRC commands
- Channel management (create, join, leave)
- User management (registration, nickname change)
- Operator commands
- Web interface for easy usage
- Configurable Message of the Day (MOTD)

## History

This codebase was created during my high school career
and first uploaded to SourceForge in April 2008
(when I was ~15 years old).
Originally, the code targetted PHP 5.2.0 on Windows.

It was not good code.

I don't support this project, but
since then several people have made contributions
to work on some of the bugs or support newer PHP versions.

Here are some permalinks into the different snapshots:

| Date          | Description          | Contributor                                                  | Tree                                                                                         |
|---------------|----------------------|--------------------------------------------------------------|----------------------------------------------------------------------------------------------|
| April 2008    | Initial commit       | @danopia                                                     | [5a03029](https://github.com/danopia/php-ircd/tree/5a03029e20240ef5c8abfa3595de48caa59f8dd6) |
| June 2008     | Final feature commit | @danopia                                                     | [137458a](https://github.com/danopia/php-ircd/tree/137458aeaea5a25cf3b7b65c55e1c046594a84cd) |
| December 2012 | Typo/formatting      | [@zhaofengli](https://github.com/danopia/php-ircd/pull/1)    | [49160cb](https://github.com/danopia/php-ircd/tree/49160cbe56b25401c907f7cd30f1027a7e480940) |
| March 2014    | Refactoring          | [@snacsnoc](https://github.com/danopia/php-ircd/pull/3)      | [e64cd3f](https://github.com/danopia/php-ircd/tree/e64cd3fb55ee76299cdfcb1915212e022f2f4038) |
| January 2019  | Typo                 | [@avram](https://github.com/danopia/php-ircd/pull/4)         | [b9db16e](https://github.com/danopia/php-ircd/tree/b9db16e83476cffc0ed5fec60010f99fd7ca119a) |
| July 2020     | Support PHP 7.4      | [@henrikhjelm](https://github.com/danopia/php-ircd/issues/5) | [e9cbb9a](https://github.com/danopia/php-ircd/tree/e9cbb9a6ed84451db725916bf46103b610729d26) |
| July 2021     | **Require** PHP 8    | [@JosunLP](https://github.com/danopia/php-ircd/pull/8)       | [b100861](https://github.com/danopia/php-ircd/tree/b100861dfca311c6e2996cbc1f317c15121006ab) |
