<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpIrcd\Models\Channel;
use PhpIrcd\Models\User;

class ChannelTest extends TestCase
{
    private $channel;
    private $user1;
    private $user2;
    private $user3;

    protected function setUp(): void
    {
        $this->channel = new Channel('#test');

        // Create temporary file streams instead of network sockets
        $socket1 = fopen('php://temp', 'r+');
        $socket2 = fopen('php://temp', 'r+');
        $socket3 = fopen('php://temp', 'r+');

        $this->user1 = new User($socket1, '127.0.0.1', true);
        $this->user1->setNick('user1');
        $this->user1->setIdent('ident1');
        $this->user1->setRealname('User One');

        $this->user2 = new User($socket2, '127.0.0.2', true);
        $this->user2->setNick('user2');
        $this->user2->setIdent('ident2');
        $this->user2->setRealname('User Two');

        $this->user3 = new User($socket3, '127.0.0.3', true);
        $this->user3->setNick('user3');
        $this->user3->setIdent('ident3');
        $this->user3->setRealname('User Three');
    }

    protected function tearDown(): void
    {
        // Clean up sockets
        if (isset($this->user1)) {
            $socket = $this->user1->getSocket();
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        if (isset($this->user2)) {
            $socket = $this->user2->getSocket();
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        if (isset($this->user3)) {
            $socket = $this->user3->getSocket();
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    public function testChannelInitialization()
    {
        $this->assertInstanceOf(Channel::class, $this->channel);
        $this->assertEquals('#test', $this->channel->getName());
        $this->assertNull($this->channel->getTopic());
        $this->assertEmpty($this->channel->getUsers());
        $this->assertTrue($this->channel->hasMode('n')); // Default mode
        $this->assertTrue($this->channel->hasMode('t')); // Default mode
    }

    public function testAddAndRemoveUser()
    {
        $this->channel->addUser($this->user1);

        $this->assertTrue($this->channel->hasUser($this->user1));
        $this->assertCount(1, $this->channel->getUsers());
        $this->assertSame($this->user1, $this->channel->getUsers()[0]);

        // First user should be operator
        $this->assertTrue($this->channel->isOperator($this->user1));

        $this->channel->removeUser($this->user1);

        $this->assertFalse($this->channel->hasUser($this->user1));
        $this->assertCount(0, $this->channel->getUsers());
        $this->assertFalse($this->channel->isOperator($this->user1));
    }

    public function testAddUserAsOperator()
    {
        $this->channel->addUser($this->user1, true);

        $this->assertTrue($this->channel->hasUser($this->user1));
        $this->assertTrue($this->channel->isOperator($this->user1));
    }

    public function testSetAndGetTopic()
    {
        $this->channel->setTopic('Test topic', 'user1');

        $this->assertEquals('Test topic', $this->channel->getTopic());
        $this->assertEquals('user1', $this->channel->getTopicSetBy());
        $this->assertGreaterThan(0, $this->channel->getTopicSetTime());
    }

    public function testSetAndGetModes()
    {
        $this->channel->setMode('i', true); // Invite only
        $this->channel->setMode('k', true, 'password'); // Key
        $this->channel->setMode('l', true, 10); // Limit

        $this->assertTrue($this->channel->hasMode('i'));
        $this->assertTrue($this->channel->hasMode('k'));
        $this->assertTrue($this->channel->hasMode('l'));
        $this->assertEquals('ntikl', $this->channel->getModeString());

        $modeParams = $this->channel->getModeParams();
        $this->assertEquals('password', $modeParams['k']);
        $this->assertEquals(10, $modeParams['l']);

        // Remove modes
        $this->channel->setMode('i', false);
        $this->channel->setMode('k', false);
        $this->channel->setMode('l', false);

        $this->assertFalse($this->channel->hasMode('i'));
        $this->assertFalse($this->channel->hasMode('k'));
        $this->assertFalse($this->channel->hasMode('l'));
    }

    public function testUserModes()
    {
        $this->channel->addUser($this->user1);
        $this->channel->addUser($this->user2);

        // Test operator
        $this->channel->setOperator($this->user1, true);
        $this->assertTrue($this->channel->isOperator($this->user1));

        // Test voice
        $this->channel->setVoiced($this->user2, true);
        $this->assertTrue($this->channel->isVoiced($this->user2));

        // Test owner
        $this->channel->setOwner($this->user1, true);
        $this->assertTrue($this->channel->isOwner($this->user1));

        // Test protected
        $this->channel->setProtected($this->user2, true);
        $this->assertTrue($this->channel->isProtected($this->user2));

        // Test halfop
        $this->channel->setHalfop($this->user3, true);
        $this->assertTrue($this->channel->isHalfop($this->user3));

        // Remove modes
        $this->channel->setOperator($this->user1, false);
        $this->channel->setVoiced($this->user2, false);
        $this->channel->setOwner($this->user1, false);
        $this->channel->setProtected($this->user2, false);
        $this->channel->setHalfop($this->user3, false);

        $this->assertFalse($this->channel->isOperator($this->user1));
        $this->assertFalse($this->channel->isVoiced($this->user2));
        $this->assertFalse($this->channel->isOwner($this->user1));
        $this->assertFalse($this->channel->isProtected($this->user2));
        $this->assertFalse($this->channel->isHalfop($this->user3));
    }

    public function testBans()
    {
        $this->channel->addBan('*!*@*.example.com', 'user1');
        $this->channel->addBan('spammer!*@*', 'user2');

        $bans = $this->channel->getBans();
        $this->assertCount(2, $bans);
        $this->assertContains('*!*@*.example.com', $bans);
        $this->assertContains('spammer!*@*', $bans);

        // Test ban matching - create a real user instead of mocking
        $bannedSocket = fopen('php://temp', 'r+');
        $bannedUser = new User($bannedSocket, '127.0.0.1', true);
        $bannedUser->setNick('spammer');
        $bannedUser->setIdent('spammer');
        $bannedUser->setHost('example.com');
        $bannedUser->setCloak('example.com');

        $this->assertTrue($this->channel->isBanned($bannedUser));

        // Remove ban
        $this->channel->removeBan('*!*@*.example.com');
        $bans = $this->channel->getBans();
        $this->assertCount(1, $bans);
        $this->assertNotContains('*!*@*.example.com', $bans);

        // Clean up
        fclose($bannedSocket);
    }

    public function testBanExceptions()
    {
        $this->channel->addBanException('*!*@trusted.example.com', 'user1');

        $exceptions = $this->channel->getBanExceptions();
        $this->assertCount(1, $exceptions);
        $this->assertContains('*!*@trusted.example.com', $exceptions);

        // Test exception matching - create a real user instead of mocking
        $trustedSocket = fopen('php://temp', 'r+');
        $trustedUser = new User($trustedSocket, '127.0.0.1', true);
        $trustedUser->setNick('trusted');
        $trustedUser->setIdent('trusted');
        $trustedUser->setHost('trusted.example.com');
        $trustedUser->setCloak('trusted.example.com');

        $this->assertTrue($this->channel->hasExceptionFor($trustedUser));

        // Remove exception
        $this->channel->removeBanException('*!*@trusted.example.com');
        $exceptions = $this->channel->getBanExceptions();
        $this->assertCount(0, $exceptions);

        // Clean up
        fclose($trustedSocket);
    }

    public function testInviteOnly()
    {
        $this->channel->setMode('i', true);

        $this->assertTrue($this->channel->hasMode('i'));
        $this->assertTrue($this->channel->isInviteOnly());

        // Test invite - use a mask that matches the user's hostmask
        $this->channel->invite('user1!*@*', 'user1');
        $this->assertTrue($this->channel->isInvited($this->user1));

        // Remove invite
        $this->channel->removeInviteException('user1!*@*');
        $this->assertFalse($this->channel->isInvited($this->user1));
    }

    public function testCanJoin()
    {
        // Test normal join
        $this->assertTrue($this->channel->canJoin($this->user1));

        // Test invite-only
        $this->channel->setMode('i', true);
        $this->assertFalse($this->channel->canJoin($this->user1));

        // Add invite
        $this->channel->invite('user1!*@*', 'user1');
        $this->assertTrue($this->channel->canJoin($this->user1));

        // Test with key
        $this->channel->setMode('k', true, 'secret');
        $this->assertFalse($this->channel->canJoin($this->user1, 'wrong'));
        $this->assertTrue($this->channel->canJoin($this->user1, 'secret'));

        // Test with limit
        $this->channel->setMode('l', true, 1);
        $this->channel->addUser($this->user2);
        $this->assertFalse($this->channel->canJoin($this->user1, 'secret'));
    }

    public function testChannelNameValidation()
    {
        $this->assertTrue(Channel::isValidChannelName('#test'));
        $this->assertTrue(Channel::isValidChannelName('&test'));
        $this->assertTrue(Channel::isValidChannelName('+test'));
        $this->assertTrue(Channel::isValidChannelName('!test'));
        $this->assertFalse(Channel::isValidChannelName('test')); // No prefix
        $this->assertFalse(Channel::isValidChannelName('')); // Empty
        $this->assertFalse(Channel::isValidChannelName('#')); // Just prefix
    }

    public function testPermanentChannel()
    {
        $this->assertFalse($this->channel->isPermanent());

        $this->channel->setPermanent(true);
        $this->assertTrue($this->channel->isPermanent());

        $this->channel->setPermanent(false);
        $this->assertFalse($this->channel->isPermanent());
    }

    public function testGetKeyAndLimit()
    {
        $this->channel->setMode('k', true, 'secret');
        $this->channel->setMode('l', true, 10);

        $this->assertEquals('secret', $this->channel->getKey());
        $this->assertEquals(10, $this->channel->getLimit());
    }

    public function testMessageHistory()
    {
        $this->channel->addMessageToHistory('Hello world!', 'user1');
        $this->channel->addMessageToHistory('How are you?', 'user2');

        $history = $this->channel->getMessageHistory();
        $this->assertCount(2, $history);
        $this->assertEquals('Hello world!', $history[0]['message']);
        $this->assertEquals('user1', $history[0]['sender']);
        $this->assertEquals('How are you?', $history[1]['message']);
        $this->assertEquals('user2', $history[1]['sender']);

        // Test limit
        $limitedHistory = $this->channel->getMessageHistory(1);
        $this->assertCount(1, $limitedHistory);
    }

    public function testIsEmpty()
    {
        $this->assertTrue($this->channel->isEmpty());

        $this->channel->addUser($this->user1);
        $this->assertFalse($this->channel->isEmpty());

        $this->channel->removeUser($this->user1);
        $this->assertTrue($this->channel->isEmpty());
    }

    public function testGetCreationTime()
    {
        $creationTime = $this->channel->getCreationTime();
        $this->assertIsInt($creationTime);
        $this->assertGreaterThan(0, $creationTime);
        $this->assertLessThanOrEqual(time(), $creationTime);
    }

    public function testRemoveUserFromModlists()
    {
        $this->channel->addUser($this->user1);
        $this->channel->setOperator($this->user1, true);
        $this->channel->setVoiced($this->user1, true);
        $this->channel->setOwner($this->user1, true);

        $this->assertTrue($this->channel->isOperator($this->user1));
        $this->assertTrue($this->channel->isVoiced($this->user1));
        $this->assertTrue($this->channel->isOwner($this->user1));

        $this->channel->removeUser($this->user1);

        $this->assertFalse($this->channel->isOperator($this->user1));
        $this->assertFalse($this->channel->isVoiced($this->user1));
        $this->assertFalse($this->channel->isOwner($this->user1));
    }
}
