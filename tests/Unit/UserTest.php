<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpIrcd\Models\User;

class UserTest extends TestCase
{
    private $socket;
    private $user;

    protected function setUp(): void
    {
        // Create a temporary file stream instead of a network socket for testing
        $this->socket = fopen('php://temp', 'r+');
        $this->user = new User($this->socket, '127.0.0.1', true); // Use stream socket
    }

    protected function tearDown(): void
    {
        if ($this->socket && is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    public function testUserInitialization()
    {
        $this->assertInstanceOf(User::class, $this->user);
        $this->assertEquals('127.0.0.1', $this->user->getIp());
        $this->assertFalse($this->user->isRegistered());
        $this->assertFalse($this->user->isOper());
        $this->assertNull($this->user->getNick());
        $this->assertNull($this->user->getIdent());
        $this->assertNull($this->user->getRealname());
    }

    public function testSetAndGetNick()
    {
        $this->user->setNick('testuser');
        $this->assertEquals('testuser', $this->user->getNick());
    }

    public function testSetAndGetIdent()
    {
        $this->user->setIdent('testident');
        $this->assertEquals('testident', $this->user->getIdent());
    }

    public function testSetAndGetRealname()
    {
        $this->user->setRealname('Test User');
        $this->assertEquals('Test User', $this->user->getRealname());
    }

    public function testSetAndGetHost()
    {
        $this->user->setHost('testhost.local');
        $this->assertEquals('testhost.local', $this->user->getHost());
    }

    public function testSetAndGetCloak()
    {
        $this->user->setCloak('cloaked.host');
        $this->assertEquals('cloaked.host', $this->user->getCloak());
    }

    public function testSetAndGetOper()
    {
        $this->user->setOper(true);
        $this->assertTrue($this->user->isOper());

        $this->user->setOper(false);
        $this->assertFalse($this->user->isOper());
    }

    public function testUserRegistration()
    {
        $this->user->setNick('testuser');
        $this->user->setIdent('test');
        $this->user->setRealname('Test User');

        // User should be registered after setting all required fields
        $this->assertTrue($this->user->isRegistered());
    }

    public function testUserRegistrationWithInvalidData()
    {
        $this->user->setNick(''); // Empty nick
        $this->user->setIdent('test');
        $this->user->setRealname('Test User');

        $this->assertFalse($this->user->isRegistered());
    }

    public function testSetAndGetAway()
    {
        $this->user->setAway('I am away');
        $this->assertTrue($this->user->isAway());
        $this->assertEquals('I am away', $this->user->getAwayMessage());

        $this->user->setAway(null);
        $this->assertFalse($this->user->isAway());
        $this->assertNull($this->user->getAwayMessage());
    }

    public function testSetAndGetModes()
    {
        $this->user->setMode('i', true);
        $this->user->setMode('w', true);

        $this->assertTrue($this->user->hasMode('i'));
        $this->assertTrue($this->user->hasMode('w'));
        $this->assertEquals('iw', $this->user->getModes());

        $this->user->setMode('i', false);
        $this->assertFalse($this->user->hasMode('i'));
        $this->assertEquals('w', $this->user->getModes());
    }

    public function testSetAndGetPassword()
    {
        $this->user->setPassword('testpass');
        $this->assertEquals('testpass', $this->user->getPassword());
    }

    public function testSetAndGetSaslStatus()
    {
        $this->user->setSaslInProgress(true);
        $this->assertTrue($this->user->isSaslInProgress());

        $this->user->setSaslAuthenticated(true);
        $this->assertTrue($this->user->isSaslAuthenticated());
    }

    public function testCapabilities()
    {
        $this->user->addCapability('server-time');
        $this->user->addCapability('echo-message');

        $this->assertTrue($this->user->hasCapability('server-time'));
        $this->assertTrue($this->user->hasCapability('echo-message'));
        $this->assertFalse($this->user->hasCapability('nonexistent'));

        $capabilities = $this->user->getCapabilities();
        $this->assertContains('server-time', $capabilities);
        $this->assertContains('echo-message', $capabilities);

        $this->user->removeCapability('server-time');
        $this->assertFalse($this->user->hasCapability('server-time'));
    }

    public function testClearCapabilities()
    {
        $this->user->addCapability('server-time');
        $this->user->addCapability('echo-message');

        $this->user->clearCapabilities();

        $this->assertFalse($this->user->hasCapability('server-time'));
        $this->assertFalse($this->user->hasCapability('echo-message'));
        $this->assertEmpty($this->user->getCapabilities());
    }

    public function testSilenceMasks()
    {
        $this->user->addSilencedMask('*!*@*.example.com');
        $this->user->addSilencedMask('spammer!*@*');

        $masks = $this->user->getSilencedMasks();
        $this->assertContains('*!*@*.example.com', $masks);
        $this->assertContains('spammer!*@*', $masks);

        $this->user->removeSilencedMask('*!*@*.example.com');
        $masks = $this->user->getSilencedMasks();
        $this->assertNotContains('*!*@*.example.com', $masks);
        $this->assertContains('spammer!*@*', $masks);
    }

    public function testWatchList()
    {
        $this->user->addToWatchList('user1');
        $this->user->addToWatchList('user2');

        $this->assertTrue($this->user->isWatching('user1'));
        $this->assertTrue($this->user->isWatching('user2'));
        $this->assertFalse($this->user->isWatching('user3'));

        $watchList = $this->user->getWatchList();
        $this->assertContains('user1', $watchList);
        $this->assertContains('user2', $watchList);

        $this->user->removeFromWatchList('user1');
        $this->assertFalse($this->user->isWatching('user1'));
        $this->assertTrue($this->user->isWatching('user2'));
    }

    public function testClearWatchList()
    {
        $this->user->addToWatchList('user1');
        $this->user->addToWatchList('user2');

        $this->user->clearWatchList();

        $this->assertFalse($this->user->isWatching('user1'));
        $this->assertFalse($this->user->isWatching('user2'));
        $this->assertEmpty($this->user->getWatchList());
    }

    public function testSetAndGetSaslMechanism()
    {
        $this->user->setSaslMechanism('PLAIN');
        $this->assertEquals('PLAIN', $this->user->getSaslMechanism());
    }

    public function testSetAndGetRemoteUser()
    {
        $this->user->setRemoteUser(true);
        $this->assertTrue($this->user->isRemoteUser());

        $this->user->setRemoteServer('remoteserver');
        $this->assertEquals('remoteserver', $this->user->getRemoteServer());
    }

    public function testGetMask()
    {
        $this->user->setNick('testuser');
        $this->user->setIdent('test');
        $this->user->setCloak('testhost.local');

        $mask = $this->user->getMask();
        $this->assertEquals('testuser!test@testhost.local', $mask);
    }

    public function testGetId()
    {
        $this->user->setNick('testuser');
        $this->user->setIdent('test');
        $this->user->setCloak('testhost.local');

        $id = $this->user->getId();
        // The ID should be a hash of the user's information
        $this->assertIsString($id);
        $this->assertNotEmpty($id);
    }

    public function testUpdateActivity()
    {
        $initialActivity = $this->user->getLastActivity();

        // Wait a moment to ensure timestamp changes
        sleep(1);

        $this->user->updateActivity();
        $newActivity = $this->user->getLastActivity();

        $this->assertGreaterThan($initialActivity, $newActivity);
    }

    public function testIsInactive()
    {
        // Set last activity to now
        $this->user->updateActivity();

        // Should not be inactive with a 10-minute timeout
        $this->assertFalse($this->user->isInactive(600));

        // Should be inactive with a 1-second timeout after waiting
        sleep(2);
        $this->assertTrue($this->user->isInactive(1));
    }

    public function testGetConnectTime()
    {
        $connectTime = $this->user->getConnectTime();
        $this->assertIsInt($connectTime);
        $this->assertGreaterThan(0, $connectTime);
        $this->assertLessThanOrEqual(time(), $connectTime);
    }

    public function testCapabilityNegotiation()
    {
        $this->user->setCapabilityNegotiationInProgress(true);
        $this->assertTrue($this->user->isCapabilityNegotiationInProgress());

        $this->user->setCapabilityNegotiationInProgress(false);
        $this->assertFalse($this->user->isCapabilityNegotiationInProgress());
    }

    public function testUndergoing302Negotiation()
    {
        $this->user->setUndergoing302Negotiation(true);
        $this->assertTrue($this->user->isUndergoing302Negotiation());

        $this->user->setUndergoing302Negotiation(false);
        $this->assertFalse($this->user->isUndergoing302Negotiation());
    }

    public function testIsSecureConnection()
    {
        // For stream sockets, we can't easily test SSL without setting up a real SSL context
        // So we'll just test that the method returns a boolean
        $isSecure = $this->user->isSecureConnection();
        $this->assertIsBool($isSecure);
    }

    public function testSetSocketTimeout()
    {
        // File streams don't support socket timeouts, so this should return false
        $result = $this->user->setSocketTimeout(5);
        $this->assertFalse($result);
    }

    public function testGetSocket()
    {
        $socket = $this->user->getSocket();
        $this->assertSame($this->socket, $socket);
    }
}
