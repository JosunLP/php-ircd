<?php

namespace PhpIrcd\Web;

use PhpIrcd\Core\Config;
use PhpIrcd\Utils\Logger;
use Exception;

/**
 * WebInterface class for providing a web interface to the IRC server
 */
class WebInterface {
    private $config;
    private $logger;
    private $serverSocketFile;
    private $sessionTimeout = 300; // 5 minutes timeout for sessions
    private static $activeSockets = []; // Static array to store active socket connections
    
    /**
     * Constructor
     * 
     * @param Config $config The configuration
     */
    public function __construct($config) {
        $this->config = $config;
        $this->logger = new Logger();
        $this->serverSocketFile = sys_get_temp_dir() . '/php-ircd-socket.sock';
    }
    
    /**
     * Processes the web request
     */
    public function handleRequest(): void {
        // Start session
        session_start();
        
        // Determine request type
        $action = $_GET['action'] ?? 'view';
        
        switch ($action) {
            case 'connect':
                $this->handleConnect();
                break;
            case 'send':
                $this->handleSend();
                break;
            case 'receive':
                $this->handleReceive();
                break;
            case 'disconnect':
                $this->handleDisconnect();
                break;
            case 'status':
                $this->handleStatus();
                break;
            default:
                $this->showInterface();
                break;
        }
    }
    
    /**
     * Displays the web user interface
     */
    private function showInterface(): void {
        // Check if the server is running
        $serverRunning = $this->isServerRunning();
        
        echo '<!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>PHP-IRCd Web Interface</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    margin: 0;
                    padding: 20px;
                    line-height: 1.6;
                    color: #333;
                }
                .container {
                    max-width: 1200px;
                    margin: 0 auto;
                    padding: 20px;
                    background-color: #f9f9f9;
                    border-radius: 5px;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                }
                h1, h2 {
                    color: #2c3e50;
                }
                .status {
                    padding: 10px;
                    margin: 10px 0;
                    border-radius: 3px;
                }
                .status.running {
                    background-color: #d4edda;
                    color: #155724;
                }
                .status.stopped {
                    background-color: #f8d7da;
                    color: #721c24;
                }
                .btn {
                    display: inline-block;
                    padding: 8px 16px;
                    margin: 5px 0;
                    background-color: #3498db;
                    color: white;
                    border: none;
                    border-radius: 4px;
                    cursor: pointer;
                    text-decoration: none;
                }
                .btn:hover {
                    background-color: #2980b9;
                }
                .btn.danger {
                    background-color: #e74c3c;
                }
                .btn.danger:hover {
                    background-color: #c0392b;
                }
                .grid {
                    display: grid;
                    grid-template-columns: 1fr 300px;
                    gap: 20px;
                    margin-top: 20px;
                }
                #chat {
                    height: 400px;
                    overflow-y: auto;
                    border: 1px solid #ddd;
                    padding: 10px;
                    background-color: white;
                }
                #userList {
                    height: 400px;
                    overflow-y: auto;
                    border: 1px solid #ddd;
                    padding: 10px;
                    background-color: white;
                }
                #messageForm {
                    margin-top: 10px;
                }
                #messageInput {
                    width: calc(100% - 100px);
                    padding: 8px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                }
                .message {
                    margin-bottom: 5px;
                    padding: 5px;
                    border-radius: 3px;
                }
                .message.system {
                    background-color: #f1f1f1;
                    color: #777;
                }
                .message.user {
                    background-color: #e8f4fd;
                }
                .hidden {
                    display: none;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>PHP-IRCd Web Interface</h1>
                <div class="status ' . ($serverRunning ? 'running' : 'stopped') . '">
                    Server Status: ' . ($serverRunning ? 'Running' : 'Stopped') . '
                </div>';
        
        if (!$serverRunning) {
            echo '<p>The IRC server is not started. Please start it via the command line with <code>php index.php</code>.</p>';
        } else {
            echo '<div id="clientInterface">
                    <div id="connectForm" ' . (isset($_SESSION['irc_connected']) ? 'class="hidden"' : '') . '>
                        <h2>Connect to IRC Server</h2>
                        <form id="connectionForm" action="?action=connect" method="post">
                            <div>
                                <label for="nickname">Nickname:</label>
                                <input type="text" id="nickname" name="nickname" required>
                            </div>
                            <div>
                                <label for="username">Username:</label>
                                <input type="text" id="username" name="username" required>
                            </div>
                            <div>
                                <label for="realname">Realname:</label>
                                <input type="text" id="realname" name="realname" required>
                            </div>
                            <button type="submit" class="btn">Connect</button>
                        </form>
                    </div>
                    
                    <div id="chatInterface" ' . (!isset($_SESSION['irc_connected']) ? 'class="hidden"' : '') . '>
                        <h2>IRC Chat</h2>
                        <div class="grid">
                            <div id="chat"></div>
                            <div id="userList">
                                <h3>Users</h3>
                                <ul id="users"></ul>
                            </div>
                        </div>
                        <form id="messageForm" action="?action=send" method="post">
                            <input type="text" id="messageInput" name="message" placeholder="Enter message..." required>
                            <button type="submit" class="btn">Send</button>
                        </form>
                        <div id="commands">
                            <h3>Commands</h3>
                            <p><code>/join #channel</code> - Join a channel</p>
                            <p><code>/part #channel</code> - Leave a channel</p>
                            <p><code>/nick newName</code> - Change nickname</p>
                            <p><code>/msg user message</code> - Send private message</p>
                            <p><code>/who #channel</code> - List users in a channel</p>
                            <p><code>/whois nickname</code> - Display information about a user</p>
                            <p><code>/list</code> - List all available channels</p>
                            <p><code>/topic #channel [new topic]</code> - View or set channel topic</code></p>
                            <p><code>/away [message]</code> - Set or remove away status</p>
                            <p><code>/mode #channel +/-modes</code> - Set or remove channel modes</p>
                            <p><code>/quit [reason]</code> - Disconnect</p>
                        </div>
                        <form id="disconnectForm" action="?action=disconnect" method="post">
                            <button type="submit" class="btn danger">Disconnect</button>
                        </form>
                    </div>
                </div>';
        }
        
        echo '</div>
            <script>
                // JavaScript for client logic
                document.addEventListener("DOMContentLoaded", function() {
                    const chatInterface = document.getElementById("chatInterface");
                    const connectForm = document.getElementById("connectForm");
                    const connectionForm = document.getElementById("connectionForm");
                    const messageForm = document.getElementById("messageForm");
                    const chatWindow = document.getElementById("chat");
                    const userList = document.getElementById("users");
                    
                    // Submit connection form
                    if (connectionForm) {
                        connectionForm.addEventListener("submit", function(e) {
                            e.preventDefault();
                            const formData = new FormData(connectionForm);
                            
                            fetch("?action=connect", {
                                method: "POST",
                                body: formData
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    connectForm.classList.add("hidden");
                                    chatInterface.classList.remove("hidden");
                                    startMessagePolling();
                                } else {
                                    alert("Connection error: " + data.message);
                                }
                            });
                        });
                    }
                    
                    // Submit message form
                    if (messageForm) {
                        messageForm.addEventListener("submit", function(e) {
                            e.preventDefault();
                            const message = document.getElementById("messageInput").value;
                            
                            fetch("?action=send", {
                                method: "POST",
                                headers: {
                                    "Content-Type": "application/x-www-form-urlencoded"
                                },
                                body: "message=" + encodeURIComponent(message)
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    document.getElementById("messageInput").value = "";
                                } else {
                                    alert("Error sending message: " + data.message);
                                }
                            });
                        });
                    }
                    
                    // Regularly fetch new messages
                    function startMessagePolling() {
                        setInterval(function() {
                            fetch("?action=receive")
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    // Display new messages
                                    data.messages.forEach(msg => {
                                        addMessage(msg.text, msg.type);
                                    });
                                    
                                    // Update user list
                                    if (data.users) {
                                        updateUserList(data.users);
                                    }
                                }
                            });
                        }, 1000); // Update every 1 second
                    }
                    
                    // Add message to chat display
                    function addMessage(text, type = "system") {
                        const msgElement = document.createElement("div");
                        msgElement.className = "message " + type;
                        msgElement.textContent = text;
                        chatWindow.appendChild(msgElement);
                        chatWindow.scrollTop = chatWindow.scrollHeight;
                    }
                    
                    // Update user list
                    function updateUserList(users) {
                        userList.innerHTML = "";
                        users.forEach(user => {
                            const li = document.createElement("li");
                            li.textContent = user;
                            userList.appendChild(li);
                        });
                    }
                    
                    // If already connected, start message polling
                    if (chatInterface && !chatInterface.classList.contains("hidden")) {
                        startMessagePolling();
                    }
                });
            </script>
        </body>
        </html>';
    }
    
    /**
     * Processes a connection request
     */
    private function handleConnect(): void {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed']);
            return;
        }
        
        $nickname = $_POST['nickname'] ?? '';
        $username = $_POST['username'] ?? '';
        $realname = $_POST['realname'] ?? '';
        
        if (empty($nickname) || empty($username) || empty($realname)) {
            echo json_encode(['success' => false, 'message' => 'All fields must be filled']);
            return;
        }
        
        try {
            // Connect to the IRC server
            $socket = $this->connectToServer();
            
            if (!$socket) {
                echo json_encode(['success' => false, 'message' => 'Failed to connect to IRC server']);
                return;
            }
            
            // Send user data
            fwrite($socket, "NICK {$nickname}\r\n");
            fwrite($socket, "USER {$username} 0 * :{$realname}\r\n");
            
            // Generate unique socket ID and store in static array
            $socketId = uniqid('sock_', true);
            self::$activeSockets[$socketId] = $socket;
            
            // Store socket ID in session
            $_SESSION['irc_socket_id'] = $socketId;
            $_SESSION['irc_connected'] = true;
            $_SESSION['irc_nickname'] = $nickname;
            $_SESSION['irc_last_activity'] = time();
            $_SESSION['irc_buffer'] = [];
            
            $this->logger->info("Web client connected: {$nickname}");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $this->logger->error("Connection error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    /**
     * Processes a message request
     */
    private function handleSend(): void {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed']);
            return;
        }
        
        if (!isset($_SESSION['irc_connected']) || !$_SESSION['irc_connected']) {
            echo json_encode(['success' => false, 'message' => 'Not connected to IRC server']);
            return;
        }
        
        $message = $_POST['message'] ?? '';
        
        if (empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Empty message']);
            return;
        }
        
        try {
            $socket = $this->getSocketFromSession();
            
            if (!$socket) {
                echo json_encode(['success' => false, 'message' => 'Socket connection lost']);
                return;
            }
            
            // Process commands
            if ($message[0] === '/') {
                $parts = explode(' ', substr($message, 1));
                $command = strtoupper($parts[0]);
                
                switch ($command) {
                    case 'JOIN':
                        if (isset($parts[1])) {
                            fwrite($socket, "JOIN {$parts[1]}\r\n");
                        }
                        break;
                    case 'PART':
                        if (isset($parts[1])) {
                            fwrite($socket, "PART {$parts[1]}\r\n");
                        }
                        break;
                    case 'NICK':
                        if (isset($parts[1])) {
                            fwrite($socket, "NICK {$parts[1]}\r\n");
                            $_SESSION['irc_nickname'] = $parts[1];
                        }
                        break;
                    case 'MSG':
                    case 'PRIVMSG':
                        if (isset($parts[1]) && isset($parts[2])) {
                            $target = $parts[1];
                            $msgText = implode(' ', array_slice($parts, 2));
                            fwrite($socket, "PRIVMSG {$target} :{$msgText}\r\n");
                            
                            // Display own message in chat
                            $_SESSION['irc_buffer'][] = [
                                'text' => "-> {$target}: {$msgText}",
                                'type' => 'user'
                            ];
                        }
                        break;
                    case 'QUIT':
                        $quitMsg = isset($parts[1]) ? implode(' ', array_slice($parts, 1)) : "Web client disconnected";
                        fwrite($socket, "QUIT :{$quitMsg}\r\n");
                        $this->closeConnection();
                        break;
                    default:
                        // Send unknown command directly
                        fwrite($socket, substr($message, 1) . "\r\n");
                        break;
                }
            } else {
                // Send as message to current channel, if not in channel then error
                if (isset($_SESSION['irc_current_channel'])) {
                    fwrite($socket, "PRIVMSG {$_SESSION['irc_current_channel']} :{$message}\r\n");
                    
                    // Display own message in chat
                    $_SESSION['irc_buffer'][] = [
                        'text' => "{$_SESSION['irc_nickname']}: {$message}",
                        'type' => 'user'
                    ];
                } else {
                    echo json_encode(['success' => false, 'message' => 'You must first join a channel']);
                    return;
                }
            }
            
            $_SESSION['irc_last_activity'] = time();
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $this->logger->error("Send error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    /**
     * Processes a request for new messages
     */
    private function handleReceive(): void {
        header('Content-Type: application/json');
        
        if (!isset($_SESSION['irc_connected']) || !$_SESSION['irc_connected']) {
            echo json_encode(['success' => false, 'message' => 'Not connected to IRC server']);
            return;
        }
        
        try {
            $socket = $this->getSocketFromSession();
            
            if (!$socket) {
                echo json_encode(['success' => false, 'message' => 'Socket connection lost']);
                return;
            }
            
            // Check for new data
            $read = [$socket];
            $write = null;
            $except = null;
            $messages = [];
            $users = [];
            
            // Retrieve buffered messages
            if (isset($_SESSION['irc_buffer']) && !empty($_SESSION['irc_buffer'])) {
                $messages = $_SESSION['irc_buffer'];
                $_SESSION['irc_buffer'] = [];
            }
            
            // Check for new data (non-blocking)
            if (stream_select($read, $write, $except, 0, 200000)) { // 0.2 seconds timeout
                foreach ($read as $socket) {
                    $data = fgets($socket, 4096);
                    
                    if ($data === false || feof($socket)) {
                        // Connection lost
                        $this->closeConnection();
                        echo json_encode([
                            'success' => false, 
                            'message' => 'Connection to IRC server lost'
                        ]);
                        return;
                    }
                    
                    // Parse and process message
                    $message = trim($data);
                    if (!empty($message)) {
                        $this->processIrcMessage($message, $messages, $users);
                    }
                }
            }
            
            $_SESSION['irc_last_activity'] = time();
            
            echo json_encode([
                'success' => true,
                'messages' => $messages,
                'users' => $users
            ]);
        } catch (Exception $e) {
            $this->logger->error("Receive error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    /**
     * Processes an IRC message
     * 
     * @param string $message The IRC message
     * @param array &$messages Array for outgoing messages
     * @param array &$users Array for the user list
     */
    private function processIrcMessage(string $message, array &$messages, array &$users): void {
        // Extract message parts
        $prefix = '';
        if ($message[0] === ':') {
            $prefixEnd = strpos($message, ' ');
            $prefix = substr($message, 1, $prefixEnd - 1);
            $message = substr($message, $prefixEnd + 1);
        }
        
        $trailingStart = strpos($message, ' :');
        $trailing = '';
        if ($trailingStart !== false) {
            $trailing = substr($message, $trailingStart + 2);
            $message = substr($message, 0, $trailingStart);
        }
        
        $parts = explode(' ', $message);
        $command = $parts[0];
        $params = array_slice($parts, 1);
        
        if (!empty($trailing)) {
            $params[] = $trailing;
        }
        
        // Process various IRC commands
        switch ($command) {
            case 'PING':
                // Automatically respond with PONG
                $socket = $this->getSocketFromSession();
                fwrite($socket, "PONG :{$params[0]}\r\n");
                break;
                
            case 'PRIVMSG':
                // Chat message
                $sender = explode('!', $prefix)[0];
                $target = $params[0];
                $text = $params[1];
                
                // Only display if directed to the current channel or directly to us
                if ($target === $_SESSION['irc_nickname'] || 
                    (isset($_SESSION['irc_current_channel']) && $target === $_SESSION['irc_current_channel'])) {
                    $messages[] = [
                        'text' => "{$sender}: {$text}",
                        'type' => 'user'
                    ];
                }
                break;
                
            case 'JOIN':
                // Someone joins a channel
                $sender = explode('!', $prefix)[0];
                $channel = $params[0];
                
                if ($sender === $_SESSION['irc_nickname']) {
                    // We joined the channel
                    $_SESSION['irc_current_channel'] = $channel;
                }
                
                $messages[] = [
                    'text' => "{$sender} joined {$channel}",
                    'type' => 'system'
                ];
                break;
                
            case 'PART':
            case 'QUIT':
                // Someone leaves a channel or the server
                $sender = explode('!', $prefix)[0];
                $reason = isset($params[1]) ? $params[1] : "No reason";
                
                $messages[] = [
                    'text' => "{$sender} left the chat: {$reason}",
                    'type' => 'system'
                ];
                break;
                
            case 'NICK':
                // Someone changes their nickname
                $oldNick = explode('!', $prefix)[0];
                $newNick = $params[0];
                
                $messages[] = [
                    'text' => "{$oldNick} is now known as {$newNick}",
                    'type' => 'system'
                ];
                
                if ($oldNick === $_SESSION['irc_nickname']) {
                    $_SESSION['irc_nickname'] = $newNick;
                }
                break;
                
            case '353': // RPL_NAMREPLY
                // User list for a channel
                $channel = $params[2];
                $userList = explode(' ', $params[3]);
                
                // If it's about the current channel, update user list
                if (isset($_SESSION['irc_current_channel']) && $channel === $_SESSION['irc_current_channel']) {
                    $users = array_merge($users, $userList);
                }
                break;
                
            case '332': // RPL_TOPIC
                // Channel topic
                $channel = $params[1];
                $topic = $params[2];
                
                if (isset($_SESSION['irc_current_channel']) && $channel === $_SESSION['irc_current_channel']) {
                    $messages[] = [
                        'text' => "Topic for {$channel}: {$topic}",
                        'type' => 'system'
                    ];
                }
                break;
                
            case '001': // RPL_WELCOME
            case '002': // RPL_YOURHOST
            case '003': // RPL_CREATED
            case '004': // RPL_MYINFO
            case '005': // RPL_ISUPPORT
            case '251': // RPL_LUSERCLIENT
            case '252': // RPL_LUSEROP
            case '253': // RPL_LUSERUNKNOWN
            case '254': // RPL_LUSERCHANNELS
            case '255': // RPL_LUSERME
            case '265': // RPL_LOCALUSERS
            case '266': // RPL_GLOBALUSERS
            case '375': // RPL_MOTDSTART
            case '372': // RPL_MOTD
            case '376': // RPL_ENDOFMOTD
                // Display server information
                $messages[] = [
                    'text' => "Server: " . (isset($params[1]) ? $params[1] : $trailing),
                    'type' => 'system'
                ];
                break;
                
            default:
                // Display all other messages as system messages
                if (!empty($prefix)) {
                    $messages[] = [
                        'text' => "IRC: {$prefix} {$command} " . implode(' ', $params),
                        'type' => 'system'
                    ];
                } else {
                    $messages[] = [
                        'text' => "IRC: {$command} " . implode(' ', $params),
                        'type' => 'system'
                    ];
                }
                break;
        }
    }
    
    /**
     * Processes a request to disconnect
     */
    private function handleDisconnect(): void {
        header('Content-Type: application/json');
        
        try {
            $socket = $this->getSocketFromSession();
            
            if ($socket) {
                // Send QUIT command
                fwrite($socket, "QUIT :Web client disconnected\r\n");
                fclose($socket);
            }
            
            $this->closeConnection();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $this->logger->error("Disconnect error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    /**
     * Processes a request to retrieve server status
     */
    private function handleStatus(): void {
        header('Content-Type: application/json');
        
        try {
            $running = $this->isServerRunning();
            echo json_encode([
                'success' => true,
                'running' => $running,
                'uptime' => $running ? $this->getServerUptime() : 0
            ]);
        } catch (Exception $e) {
            $this->logger->error("Status error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    /**
     * Establishes a connection to the IRC server
     * 
     * @return resource|false The socket connection or false on error
     */
    private function connectToServer() {
        // Überprüfen, ob SSL aktiviert ist
        $useSSL = $this->config->get('ssl_enabled', false);
        $port = $this->config->get('port', 6667);
        
        if ($useSSL) {
            // SSL-Kontext erstellen
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ]);
            
            // Sichere Verbindung herstellen
            $socket = @stream_socket_client(
                "ssl://127.0.0.1:{$port}", 
                $errno, 
                $errstr, 
                5, 
                STREAM_CLIENT_CONNECT,
                $context
            );
        } else {
            // Normale Verbindung herstellen
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 5);
        }
        
        if (!$socket) {
            $this->logger->error("Failed to connect to IRC server: {$errstr} ({$errno})");
            return false;
        }
        
        // Set non-blocking
        stream_set_blocking($socket, false);
        
        return $socket;
    }
    
    /**
     * Retrieves the socket object from the session
     * 
     * @return resource|false The socket resource or false on error
     */
    private function getSocketFromSession() {
        if (!isset($_SESSION['irc_socket_id'])) {
            return false;
        }
        
        // Check inactivity
        if (time() - $_SESSION['irc_last_activity'] > $this->sessionTimeout) {
            $this->closeConnection();
            return false;
        }
        
        $socketId = $_SESSION['irc_socket_id'];
        
        // Check if socket exists in the static array
        if (!isset(self::$activeSockets[$socketId])) {
            $this->logger->error("Socket not found in active connections");
            $this->closeConnection();
            return false;
        }
        
        $socket = self::$activeSockets[$socketId];
        
        // Check if socket is still valid
        if (!is_resource($socket)) {
            $this->logger->error("Invalid socket resource");
            $this->closeConnection();
            return false;
        }
        
        return $socket;
    }
    
    /**
     * Closes the IRC connection and cleans up the session
     */
    private function closeConnection(): void {
        try {
            if (isset($_SESSION['irc_socket_id'])) {
                $socketId = $_SESSION['irc_socket_id'];
                if (isset(self::$activeSockets[$socketId])) {
                    $socket = self::$activeSockets[$socketId];
                    if (is_resource($socket)) {
                        fclose($socket);
                    }
                    // Remove socket from active sockets
                    unset(self::$activeSockets[$socketId]);
                }
            }
            
            // Reset session variables
            unset($_SESSION['irc_socket_id']);
            unset($_SESSION['irc_connected']);
            unset($_SESSION['irc_nickname']);
            unset($_SESSION['irc_current_channel']);
            unset($_SESSION['irc_buffer']);
            unset($_SESSION['irc_last_activity']);
        } catch (\Exception $e) {
            $this->logger->error("Error closing connection: " . $e->getMessage());
        }
    }
    
    /**
     * Checks if the IRC server is running
     * 
     * @return bool Whether the server is running
     */
    private function isServerRunning(): bool {
        $socket = @fsockopen('127.0.0.1', $this->config->get('port', 6667), $errno, $errstr, 1);
        if ($socket) {
            fclose($socket);
            return true;
        }
        return false;
    }
    
    /**
     * Returns the uptime of the IRC server
     * 
     * @return int The uptime in seconds or 0 on error
     */
    private function getServerUptime(): int {
        try {
            $socket = $this->connectToServer();
            
            if (!$socket) {
                return 0;
            }
            
            // Send a VERSION request to get server information
            fwrite($socket, "VERSION\r\n");
            
            // Wait for response with timeout
            $read = [$socket];
            $write = null;
            $except = null;
            $startTime = 0;
            
            // Attempt to read server startup time from response
            if (stream_select($read, $write, $except, 2, 0)) { // 2-second timeout
                while (($line = fgets($socket)) !== false) {
                    $line = trim($line);
                    
                    // Look for 003 message which contains server creation time
                    if (preg_match('/^:[^ ]+ 003 [^ ]+ :This server was created ([^)]+)/', $line, $matches)) {
                        $serverStartTime = strtotime($matches[1]);
                        if ($serverStartTime > 0) {
                            $startTime = $serverStartTime;
                            break;
                        }
                    }
                    
                    // Break if end of response
                    if (strpos($line, '366') !== false || empty($line)) {
                        break;
                    }
                }
            }
            
            // Close the socket
            fclose($socket);
            
            // Calculate uptime
            if ($startTime > 0) {
                return time() - $startTime;
            }
            
            // Fallback: return an indication that server is running but uptime is unknown
            return 1;
        } catch (Exception $e) {
            $this->logger->error("Error getting server uptime: " . $e->getMessage());
            return 0;
        }
    }
}