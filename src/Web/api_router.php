<?php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function get_jwt_secret($config) {
    return $config['jwt_secret'] ?? 'php-ircd-super-secret-key';
}

function issue_jwt($user, $config) {
    $payload = [
        'sub' => $user->getNick(),
        'iat' => time(),
        'exp' => time() + 3600, // 1h gültig
    ];
    return JWT::encode($payload, get_jwt_secret($config), 'HS256');
}

function get_authenticated_user($server, $config) {
    $headers = getallheaders();
    if (!isset($headers['Authorization'])) return null;
    $auth = $headers['Authorization'];
    if (stripos($auth, 'Bearer ') !== 0) return null;
    $jwt = trim(substr($auth, 7));
    try {
        $payload = JWT::decode($jwt, new Key(get_jwt_secret($config), 'HS256'));
        $nick = $payload->sub ?? null;
        if (!$nick) return null;
        foreach ($server->getUsers() as $user) {
            if ($user->getNick() === $nick) return $user;
        }
        return null;
    } catch (\Exception $e) {
        return null;
    }
}

function handle_api_request($apiPath, $requestMethod, $server, $config) {
    header('Content-Type: application/json');
    $segments = explode('/', trim($apiPath, '/'));

    // --- Refresh Token Store (Demo: temporär, produktiv: DB oder Redis) ---
    $refreshTokenStore = [];

    function issue_refresh_token($user) {
        return base64_encode(bin2hex(random_bytes(32)) . '|' . $user->getNick() . '|' . time());
    }
    function validate_refresh_token($token, &$nickOut = null) {
        $parts = explode('|', base64_decode($token));
        if (count($parts) < 2) return false;
        $nickOut = $parts[1];
        return true;
    }

    // --- OAuth2-Login-Stub (Demo: Google) ---
    $OAUTH_CLIENT_ID = $config['oauth_client_id'] ?? '';
    $OAUTH_CLIENT_SECRET = $config['oauth_client_secret'] ?? '';
    $OAUTH_REDIRECT_URI = $config['oauth_redirect_uri'] ?? '';

    // --- /refresh ---
    if ($requestMethod === 'POST' && $apiPath === 'refresh') {
        $input = json_decode(file_get_contents('php://input'), true);
        $refresh = $input['refresh_token'] ?? null;
        if (!$refresh) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing refresh_token']);
            return;
        }
        $nick = null;
        if (!validate_refresh_token($refresh, $nick)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Invalid refresh_token']);
            return;
        }
        // Demo: Kein Blacklist/Expiry, produktiv: prüfen!
        $user = null;
        foreach ($server->getUsers() as $u) if ($u->getNick() === $nick) $user = $u;
        if (!$user) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'User not connected']);
            return;
        }
        $token = issue_jwt($user, $config);
        echo json_encode(['success' => true, 'token' => $token]);
        return;
    }

    // --- /login: JWT + Refresh-Token ---
    if ($requestMethod === 'POST' && $apiPath === 'login') {
        $input = json_decode(file_get_contents('php://input'), true);
        $nick = $input['nick'] ?? null;
        $password = $input['password'] ?? null;
        if (!$nick || !$password) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing nick or password']);
            return;
        }
        $valid = false;
        if (isset($config['opers'][$nick]) && $config['opers'][$nick] === $password) {
            $valid = true;
        } else {
            foreach ($server->getUsers() as $user) {
                if ($user->getNick() === $nick && $user->getPassword() === $password) {
                    $valid = true;
                    break;
                }
            }
        }
        if (!$valid) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
            return;
        }
        $user = null;
        foreach ($server->getUsers() as $u) if ($u->getNick() === $nick) $user = $u;
        if (!$user) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'User not connected']);
            return;
        }
        $token = issue_jwt($user, $config);
        $refresh = issue_refresh_token($user);
        // Demo: Speichern im Array
        $refreshTokenStore[$refresh] = $user->getNick();
        echo json_encode(['success' => true, 'token' => $token, 'refresh_token' => $refresh]);
        return;
    }

    // --- OAuth2-Login für Google und GitHub ---
    if ($requestMethod === 'GET' && $apiPath === 'oauth/login') {
        $provider = $_GET['provider'] ?? 'google';
        $state = bin2hex(random_bytes(8));
        if ($provider === 'google') {
            $url = 'https://accounts.google.com/o/oauth2/v2/auth?response_type=code'
                . '&client_id=' . urlencode($config['oauth_google_client_id'] ?? '')
                . '&redirect_uri=' . urlencode($config['oauth_google_redirect_uri'] ?? '')
                . '&scope=openid%20email%20profile'
                . '&state=' . $state
                . '&access_type=offline';
            header('Location: ' . $url, true, 302);
            exit;
        } elseif ($provider === 'github') {
            $url = 'https://github.com/login/oauth/authorize?response_type=code'
                . '&client_id=' . urlencode($config['oauth_github_client_id'] ?? '')
                . '&redirect_uri=' . urlencode($config['oauth_github_redirect_uri'] ?? '')
                . '&scope=user:email'
                . '&state=' . $state;
            header('Location: ' . $url, true, 302);
            exit;
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown provider']);
            return;
        }
    }
    if ($requestMethod === 'GET' && $apiPath === 'oauth/callback') {
        $provider = $_GET['provider'] ?? 'google';
        $code = $_GET['code'] ?? null;
        if (!$code) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing code']);
            return;
        }
        if ($provider === 'google') {
            // Token tauschen
            $tokenUrl = 'https://oauth2.googleapis.com/token';
            $postFields = [
                'code' => $code,
                'client_id' => $config['oauth_google_client_id'] ?? '',
                'client_secret' => $config['oauth_google_client_secret'] ?? '',
                'redirect_uri' => $config['oauth_google_redirect_uri'] ?? '',
                'grant_type' => 'authorization_code',
            ];
            $ch = curl_init($tokenUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
            $tokenResp = curl_exec($ch);
            $tokenData = json_decode($tokenResp, true);
            curl_close($ch);
            if (!isset($tokenData['access_token'])) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Token exchange failed', 'details' => $tokenData]);
                return;
            }
            // Userinfo holen
            $ch = curl_init('https://openidconnect.googleapis.com/v1/userinfo');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $tokenData['access_token']]);
            $userInfoResp = curl_exec($ch);
            $userInfo = json_decode($userInfoResp, true);
            curl_close($ch);
            if (!isset($userInfo['email'])) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Userinfo failed', 'details' => $userInfo]);
                return;
            }
            $nick = $userInfo['email'];
        } elseif ($provider === 'github') {
            // Token tauschen
            $tokenUrl = 'https://github.com/login/oauth/access_token';
            $postFields = [
                'code' => $code,
                'client_id' => $config['oauth_github_client_id'] ?? '',
                'client_secret' => $config['oauth_github_client_secret'] ?? '',
                'redirect_uri' => $config['oauth_github_redirect_uri'] ?? '',
            ];
            $ch = curl_init($tokenUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
            $tokenResp = curl_exec($ch);
            $tokenData = json_decode($tokenResp, true);
            curl_close($ch);
            if (!isset($tokenData['access_token'])) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Token exchange failed', 'details' => $tokenData]);
                return;
            }
            // Userinfo holen
            $ch = curl_init('https://api.github.com/user');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $tokenData['access_token'],
                'User-Agent: PHP-IRCd-OAuth'
            ]);
            $userInfoResp = curl_exec($ch);
            $userInfo = json_decode($userInfoResp, true);
            curl_close($ch);
            if (!isset($userInfo['login'])) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Userinfo failed', 'details' => $userInfo]);
                return;
            }
            $nick = $userInfo['login'];
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown provider']);
            return;
        }
        // User suchen oder anlegen
        $user = null;
        foreach ($server->getUsers() as $u) if ($u->getNick() === $nick) $user = $u;
        if (!$user) {
            // Demo: User muss verbunden sein
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'User not connected']);
            return;
        }
        $token = issue_jwt($user, $config);
        $refresh = issue_refresh_token($user);
        echo json_encode(['success' => true, 'token' => $token, 'refresh_token' => $refresh, 'user' => $nick]);
        return;
    }

    // --- Channel-Moderation: POST /channels/{name}/moderate ---
    if ($requestMethod === 'POST' && count($segments) === 3 && $segments[0] === 'channels' && $segments[2] === 'moderate') {
        $channel = $server->getChannel($segments[1]);
        if (!$channel) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Channel not found']);
            return;
        }
        $authUser = get_authenticated_user($server, $config);
        if (!$authUser || !$channel->isOperator($authUser)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Not operator']);
            return;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? null;
        $targetNick = $input['target'] ?? null;
        $targetUser = null;
        foreach ($channel->getUsers() as $u) if ($u->getNick() === $targetNick) $targetUser = $u;
        if (!$action || !$targetUser) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing action or target']);
            return;
        }
        $result = false;
        switch ($action) {
            case 'op': $channel->setOperator($targetUser, true); $result = true; break;
            case 'deop': $channel->setOperator($targetUser, false); $result = true; break;
            case 'voice': $channel->setVoiced($targetUser, true); $result = true; break;
            case 'devoice': $channel->setVoiced($targetUser, false); $result = true; break;
            case 'kick': $channel->removeUser($targetUser); $result = true; break;
            case 'ban': $channel->addBan($targetUser->getMask(), $authUser->getNick()); $result = true; break;
            case 'unban': $channel->removeBan($targetUser->getMask()); $result = true; break;
            default: http_response_code(400); echo json_encode(['success' => false, 'error' => 'Unknown action']); return;
        }
        echo json_encode(['success' => $result]);
        return;
    }

    // LOGOUT (JWT: clientseitig, optional Blacklist)
    if ($requestMethod === 'POST' && $apiPath === 'logout') {
        // JWT-Logout ist meist clientseitig (Token löschen)
        echo json_encode(['success' => true, 'message' => 'Logged out (client-side, stateless)']);
        return;
    }

    // ME
    if ($requestMethod === 'GET' && $apiPath === 'me') {
        $user = get_authenticated_user($server, $config);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Not authenticated']);
            return;
        }
        echo json_encode([
            'success' => true,
            'user' => [
                'nick' => $user->getNick(),
                'ident' => $user->getIdent(),
                'host' => $user->getHost(),
                'realname' => $user->getRealname(),
                'modes' => $user->getModes(),
                'is_oper' => $user->isOper(),
                'away' => $user->isAway(),
                'away_message' => $user->getAwayMessage(),
                'connected_since' => $user->getConnectTime(),
            ]
        ]);
        return;
    }

    // Ab hier: Authentifizierung für alle weiteren Endpunkte
    $authUser = null;
    $authRequired = [
        'users', 'channels', 'messages', 'channels/', 'channels/', 'channels/', 'channels/', 'channels/', 'channels/', 'channels/', 'channels/'
    ];
    if (in_array($segments[0], $authRequired)) {
        $authUser = get_authenticated_user($server, $config);
        if (!$authUser) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Authentication required']);
            return;
        }
    }

    // GET /status
    if ($requestMethod === 'GET' && $apiPath === 'status') {
        $info = $server->getServerStats();
        $info['success'] = true;
        echo json_encode($info);
        return;
    }

    // GET /users
    if ($requestMethod === 'GET' && $apiPath === 'users') {
        $users = array_map(function($user) {
            return [
                'nick' => $user->getNick(),
                'ident' => $user->getIdent(),
                'host' => $user->getHost(),
                'realname' => $user->getRealname(),
                'modes' => $user->getModes(),
                'is_oper' => $user->isOper(),
                'away' => $user->isAway(),
                'away_message' => $user->getAwayMessage(),
                'connected_since' => $user->getConnectTime(),
            ];
        }, $server->getUsers());
        echo json_encode(['success' => true, 'users' => $users]);
        return;
    }

    // GET /channels
    if ($requestMethod === 'GET' && $apiPath === 'channels') {
        $channels = array_map(function($channel) {
            return [
                'name' => $channel->getName(),
                'topic' => $channel->getTopic(),
                'topic_set_by' => $channel->getTopicSetBy(),
                'topic_set_time' => $channel->getTopicSetTime(),
                'user_count' => count($channel->getUsers()),
                'modes' => $channel->getModeString(),
                'created' => $channel->getCreationTime(),
            ];
        }, $server->getChannels());
        echo json_encode(['success' => true, 'channels' => $channels]);
        return;
    }

    // GET /channels/{name}
    if ($requestMethod === 'GET' && count($segments) === 2 && $segments[0] === 'channels') {
        $channel = $server->getChannel($segments[1]);
        if (!$channel) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Channel not found']);
            return;
        }
        $data = [
            'name' => $channel->getName(),
            'topic' => $channel->getTopic(),
            'topic_set_by' => $channel->getTopicSetBy(),
            'topic_set_time' => $channel->getTopicSetTime(),
            'user_count' => count($channel->getUsers()),
            'modes' => $channel->getModeString(),
            'created' => $channel->getCreationTime(),
            'users' => array_map(function($user) {
                return $user->getNick();
            }, $channel->getUsers()),
        ];
        echo json_encode(['success' => true, 'channel' => $data]);
        return;
    }

    // GET /channels/{name}/messages
    if ($requestMethod === 'GET' && count($segments) === 3 && $segments[0] === 'channels' && $segments[2] === 'messages') {
        $channel = $server->getChannel($segments[1]);
        if (!$channel) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Channel not found']);
            return;
        }
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $messages = $channel->getMessageHistory($limit);
        echo json_encode(['success' => true, 'messages' => $messages]);
        return;
    }

    // GET /channels/{name}/users
    if ($requestMethod === 'GET' && count($segments) === 3 && $segments[0] === 'channels' && $segments[2] === 'users') {
        $channel = $server->getChannel($segments[1]);
        if (!$channel) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Channel not found']);
            return;
        }
        $users = array_map(function($user) {
            return [
                'nick' => $user->getNick(),
                'ident' => $user->getIdent(),
                'host' => $user->getHost(),
                'realname' => $user->getRealname(),
                'modes' => $user->getModes(),
                'is_oper' => $user->isOper(),
                'away' => $user->isAway(),
                'away_message' => $user->getAwayMessage(),
                'connected_since' => $user->getConnectTime(),
            ];
        }, $channel->getUsers());
        echo json_encode(['success' => true, 'users' => $users]);
        return;
    }

    // POST /messages
    if ($requestMethod === 'POST' && $apiPath === 'messages') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['target'], $input['message'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing target or message']);
            return;
        }
        $target = $input['target'];
        $message = $input['message'];
        // Hier müsste Authentifizierung und User-Kontext geprüft werden!
        // Beispiel: Sende Nachricht als System/Server
        $channel = $server->getChannel($target);
        if ($channel) {
            $channel->addMessageToHistory($message, 'Server');
            // Optional: Nachricht an alle User im Channel senden
            foreach ($channel->getUsers() as $user) {
                $user->send("[API] {$message}");
            }
            echo json_encode(['success' => true]);
            return;
        }
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Target channel not found']);
        return;
    }

    // Channel erstellen: POST /channels {name}
    if ($requestMethod === 'POST' && $apiPath === 'channels') {
        $input = json_decode(file_get_contents('php://input'), true);
        $name = $input['name'] ?? null;
        if (!$name) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing channel name']);
            return;
        }
        if ($server->getChannel($name)) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Channel already exists']);
            return;
        }
        $channel = new \PhpIrcd\Models\Channel($name);
        $server->addChannel($channel);
        $channel->addUser($authUser, true); // Ersteller wird OP
        echo json_encode(['success' => true, 'channel' => $name]);
        return;
    }

    // Channel-Join: POST /channels/{name}/join
    if ($requestMethod === 'POST' && count($segments) === 3 && $segments[0] === 'channels' && $segments[2] === 'join') {
        $channel = $server->getChannel($segments[1]);
        if (!$channel) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Channel not found']);
            return;
        }
        if ($channel->hasUser($authUser)) {
            echo json_encode(['success' => true, 'message' => 'Already in channel']);
            return;
        }
        if (!$channel->canJoin($authUser)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Cannot join channel']);
            return;
        }
        $channel->addUser($authUser);
        echo json_encode(['success' => true]);
        return;
    }

    // Channel-Part: POST /channels/{name}/part
    if ($requestMethod === 'POST' && count($segments) === 3 && $segments[0] === 'channels' && $segments[2] === 'part') {
        $channel = $server->getChannel($segments[1]);
        if (!$channel) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Channel not found']);
            return;
        }
        if (!$channel->hasUser($authUser)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Not in channel']);
            return;
        }
        $channel->removeUser($authUser);
        echo json_encode(['success' => true]);
        return;
    }

    // Channel löschen: DELETE /channels/{name}
    if ($requestMethod === 'DELETE' && count($segments) === 2 && $segments[0] === 'channels') {
        $channel = $server->getChannel($segments[1]);
        if (!$channel) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Channel not found']);
            return;
        }
        // Nur OP oder Ersteller darf löschen
        if (!$channel->isOperator($authUser)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Not allowed']);
            return;
        }
        $server->removeChannel($channel->getName());
        echo json_encode(['success' => true]);
        return;
    }

    // Private Nachricht: POST /messages/private {to, message}
    if ($requestMethod === 'POST' && $apiPath === 'messages/private') {
        $input = json_decode(file_get_contents('php://input'), true);
        $to = $input['to'] ?? null;
        $message = $input['message'] ?? null;
        if (!$to || !$message) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing recipient or message']);
            return;
        }
        $targetUser = null;
        foreach ($server->getUsers() as $user) {
            if ($user->getNick() === $to) $targetUser = $user;
        }
        if (!$targetUser) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'User not found']);
            return;
        }
        $targetUser->send("[PM] {$authUser->getNick()}: {$message}");
        echo json_encode(['success' => true]);
        return;
    }

    // Default: 404
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Unknown API endpoint']);
}
