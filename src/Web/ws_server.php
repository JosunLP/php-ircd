<?php
// Beispiel: WebSocket-Server mit JWT-Auth (Ratchet-Style)
require __DIR__ . '/../../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class IRCWebSocket implements MessageComponentInterface {
    private $clients;
    private $config;
    private $server;
    public function __construct($server, $config) {
        $this->clients = new \SplObjectStorage;
        $this->config = $config;
        $this->server = $server;
    }
    public function onOpen(ConnectionInterface $conn) {
        // JWT aus Query-String prüfen
        $query = $conn->httpRequest->getUri()->getQuery();
        parse_str($query, $params);
        $token = $params['token'] ?? null;
        if (!$token) {
            $conn->close();
            return;
        }
        try {
            $payload = JWT::decode($token, new Key($this->config['jwt_secret'] ?? 'php-ircd-super-secret-key', 'HS256'));
            $nick = $payload->sub ?? null;
            $user = null;
            foreach ($this->server->getUsers() as $u) {
                if ($u->getNick() === $nick) $user = $u;
            }
            if (!$user) throw new \Exception('User not found');
            $conn->user = $user;
            $this->clients->attach($conn);
        } catch (\Exception $e) {
            $conn->close();
        }
    }
    public function onMessage(ConnectionInterface $from, $msg) {
        // Broadcast an alle im gleichen Channel
        $data = json_decode($msg, true);
        if (!isset($data['type'])) return;
        if ($data['type'] === 'message' && isset($data['channel'], $data['message'])) {
            $channel = $this->server->getChannel($data['channel']);
            if ($channel && $channel->hasUser($from->user)) {
                $channel->addMessageToHistory($data['message'], $from->user->getNick());
                foreach ($this->clients as $client) {
                    if ($client !== $from && isset($client->user) && $channel->hasUser($client->user)) {
                        $client->send(json_encode([
                            'type' => 'message',
                            'channel' => $data['channel'],
                            'from' => $from->user->getNick(),
                            'message' => $data['message'],
                            'timestamp' => time()
                        ]));
                    }
                }
            }
        }
    }
    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
    }
    public function onError(ConnectionInterface $conn, \Exception $e) {
        $conn->close();
    }
}

// Starten mit: php src/Web/ws_server.php
if (php_sapi_name() === 'cli') {
    $config = require __DIR__ . '/../../config.php';
    $server = new \PhpIrcd\Core\Server($config, true);
    $ws = new IRCWebSocket($server, $config);
    $loop = \React\EventLoop\Factory::create();
    $webSock = new \React\Socket\Server('0.0.0.0:8081', $loop);
    $webServer = new \Ratchet\Server\IoServer(
        new \Ratchet\Http\HttpServer(
            new \Ratchet\WebSocket\WsServer($ws)
        ),
        $webSock
    );
    echo "WebSocket-Server läuft auf ws://localhost:8081\n";
    $loop->run();
}
