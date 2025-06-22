<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpIrcd\Core\Server;
use PhpIrcd\Models\User;
use PhpIrcd\Models\Channel;

class ServerTest extends TestCase
{
    private $config;
    private $server;

    protected function setUp(): void
    {
        $this->config = [
            'name' => 'testserver',
            'net' => 'TestNet',
            'max_len' => 512,
            'max_users' => 10,
            'port' => 6667,
            'version' => 1.0,
            'bind_ip' => '127.0.0.1',
            'line_ending' => "\r\n",
            'line_ending_conf' => "\n",
            'ping_interval' => 90,
            'ping_timeout' => 240,
            'ssl_enabled' => false,
            'debug_mode' => true,
            'log_level' => 3,
            'log_file' => 'test.log',
            'motd' => 'Test MOTD',
            'description' => 'Test Server',
            'opers' => ['admin' => 'test123'],
            'operator_passwords' => ['admin' => 'test123'],
            'storage_dir' => sys_get_temp_dir() . '/php-ircd-test',
            'log_to_console' => false,
            'cap_enabled' => true,
            'sasl_enabled' => true,
            'ircv3_features' => [
                'multi-prefix' => true,
                'away-notify' => true,
                'server-time' => true,
                'batch' => true,
                'message-tags' => true,
                'echo-message' => true,
                'invite-notify' => true,
                'extended-join' => true,
                'userhost-in-names' => true,
                'chathistory' => true,
                'account-notify' => true,
                'account-tag' => true,
                'cap-notify' => true,
                'chghost' => true,
            ],
            'chathistory_max_messages' => 100,
            'ip_filtering_enabled' => false,
            'cloak_hostnames' => true,
            'max_watch_entries' => 128,
            'max_silence_entries' => 15,
            'default_user_modes' => '',
            'default_channel_modes' => 'nt',
            'max_channels_per_user' => 10,
        ];

        $this->server = new Server($this->config, true); // Web mode for testing
    }

    protected function tearDown(): void
    {
        // Clean up test storage directory
        $storageDir = $this->config['storage_dir'];
        if (is_dir($storageDir)) {
            $this->removeDirectory($storageDir);
        }
    }

    private function removeDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testServerInitialization()
    {
        $this->assertInstanceOf(Server::class, $this->server);
        $this->assertEquals($this->config, $this->server->getConfig());
        $this->assertTrue($this->server->isWebMode());
    }

    public function testAddAndRemoveUser()
    {
        // Create a temporary file stream instead of a network socket
        $socket = fopen('php://temp', 'r+');

        $user = new User($socket, '127.0.0.1', true); // Use stream socket
        $user->setNick('testuser');
        $user->setIdent('test');
        $user->setRealname('Test User');

        $this->server->addUser($user);

        $users = $this->server->getUsers();
        $this->assertCount(1, $users);
        $this->assertSame($user, $users[0]);

        $this->server->removeUser($user);

        $users = $this->server->getUsers();
        $this->assertCount(0, $users);

        // Clean up socket
        fclose($socket);
    }

    public function testAddAndRemoveChannel()
    {
        $channel = new Channel('#test');

        $this->server->addChannel($channel);

        $retrievedChannel = $this->server->getChannel('#test');
        $this->assertSame($channel, $retrievedChannel);

        $channels = $this->server->getChannels();
        $this->assertCount(1, $channels);
        $this->assertSame($channel, $channels['#test']);

        $this->server->removeChannel('#test');

        $retrievedChannel = $this->server->getChannel('#test');
        $this->assertNull($retrievedChannel);

        $channels = $this->server->getChannels();
        $this->assertCount(0, $channels);
    }

    public function testGetChannelWithNonExistentChannel()
    {
        $channel = $this->server->getChannel('#nonexistent');
        $this->assertNull($channel);
    }

    public function testServerCapabilities()
    {
        $capabilities = $this->server->getSupportedCapabilities();

        $this->assertIsArray($capabilities);
        $this->assertTrue($capabilities['multi-prefix']);
        $this->assertTrue($capabilities['away-notify']);
        $this->assertTrue($capabilities['server-time']);
        $this->assertTrue($capabilities['batch']);
        $this->assertTrue($capabilities['message-tags']);
        $this->assertTrue($capabilities['echo-message']);
        $this->assertTrue($capabilities['invite-notify']);
        $this->assertTrue($capabilities['extended-join']);
        $this->assertTrue($capabilities['userhost-in-names']);
        $this->assertTrue($capabilities['chathistory']);
        $this->assertTrue($capabilities['account-notify']);
        $this->assertTrue($capabilities['account-tag']);
        $this->assertTrue($capabilities['cap-notify']);
        $this->assertTrue($capabilities['chghost']);
        $this->assertTrue($capabilities['sasl']);
    }

    public function testIsCapabilitySupported()
    {
        $this->assertTrue($this->server->isCapabilitySupported('multi-prefix'));
        $this->assertTrue($this->server->isCapabilitySupported('server-time'));
        $this->assertFalse($this->server->isCapabilitySupported('nonexistent-capability'));
    }

    public function testServerHost()
    {
        $host = $this->server->getHost();
        $this->assertEquals('testserver', $host);
    }

    public function testServerStartTime()
    {
        $startTime = $this->server->getStartTime();
        $this->assertIsInt($startTime);
        $this->assertGreaterThan(0, $startTime);
        $this->assertLessThanOrEqual(time(), $startTime);
    }

    public function testUpdateConfig()
    {
        $newConfig = ['name' => 'newserver', 'port' => 6668];
        $this->server->updateConfig($newConfig);

        $updatedConfig = $this->server->getConfig();
        $this->assertEquals('newserver', $updatedConfig['name']);
        $this->assertEquals(6668, $updatedConfig['port']);
    }

    public function testRegisterPermanentChannel()
    {
        // Create a temporary file stream
        $socket = fopen('php://temp', 'r+');
        $user = new User($socket, '127.0.0.1', true);
        $user->setNick('testuser');
        $user->setIdent('test');
        $user->setRealname('Test User');

        $result = $this->server->registerPermanentChannel('#test', $user);
        $this->assertTrue($result);

        // Clean up
        fclose($socket);
    }

    public function testUnregisterPermanentChannel()
    {
        // Create a temporary file stream
        $socket = fopen('php://temp', 'r+');
        $user = new User($socket, '127.0.0.1', true);
        $user->setNick('testuser');
        $user->setIdent('test');
        $user->setRealname('Test User');

        // First register
        $this->server->registerPermanentChannel('#test', $user);

        // Then unregister
        $result = $this->server->unregisterPermanentChannel('#test', $user);
        $this->assertTrue($result);

        // Clean up
        fclose($socket);
    }

    public function testWhowasHistory()
    {
        // Create a temporary file stream
        $socket = fopen('php://temp', 'r+');
        $user = new User($socket, '127.0.0.1', true);
        $user->setNick('testuser');
        $user->setIdent('test');
        $user->setRealname('Test User');

        $this->server->addToWhowasHistory($user);

        $entries = $this->server->getWhowasEntries('testuser', 10);
        $this->assertCount(1, $entries);
        $this->assertEquals('testuser', $entries[0]['nick']);

        // Clean up
        fclose($socket);
    }

    public function testGetConnectionHandler()
    {
        $handler = $this->server->getConnectionHandler();
        $this->assertInstanceOf(\PhpIrcd\Handlers\ConnectionHandler::class, $handler);
    }

    public function testGetLogger()
    {
        $logger = $this->server->getLogger();
        $this->assertInstanceOf(\PhpIrcd\Utils\Logger::class, $logger);
    }
}
