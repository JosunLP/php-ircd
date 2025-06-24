# PHP-IRCd API Documentation

## Authentication

### Login (JWT + Refresh Token)

**POST** `/api/login`

```json
{
  "nick": "nickname",
  "password": "password"
}
```

**Response:**

```json
{
  "success": true,
  "token": "<JWT>",
  "refresh_token": "<refresh-token>"
}
```

### Token Refresh

**POST** `/api/refresh`

```json
{
  "refresh_token": "<refresh-token>"
}
```

**Response:**

```json
{
  "success": true,
  "token": "<new-JWT>"
}
```

### OAuth2 Login (Google, GitHub)

**GET** `/api/oauth/login?provider=google|github`

- Redirects to Google/GitHub

**GET** `/api/oauth/callback?provider=google|github&code=...`

- Exchanges code for token, returns JWT + refresh token

---

## User Session

### Own User Info

**GET** `/api/me` _(JWT required)_
**Header:** `Authorization: Bearer <JWT>`
**Response:**

```json
{
  "success": true,
  "user": { ... }
}
```

### Logout

**POST** `/api/logout`

- (Client-side, just delete JWT)

---

## Channel Management

### Channel List

**GET** `/api/channels`

### Channel Details

**GET** `/api/channels/{name}`

### Create Channel

**POST** `/api/channels`

```json
{
  "name": "#channel"
}
```

### Join Channel

**POST** `/api/channels/{name}/join`

### Leave Channel

**POST** `/api/channels/{name}/part`

### Delete Channel

**DELETE** `/api/channels/{name}`

---

## Channel Moderation

**POST** `/api/channels/{name}/moderate`

```json
{
  "action": "op|deop|voice|devoice|kick|ban|unban",
  "target": "nickname"
}
```

---

## Messages

### Message to Channel

**POST** `/api/messages`

```json
{
  "target": "#channel",
  "message": "Hello World!"
}
```

### Private Message

**POST** `/api/messages/private`

```json
{
  "to": "nickname",
  "message": "Hi!"
}
```

---

## Channel Info

### Channel User List

**GET** `/api/channels/{name}/users`

### Channel Message History

**GET** `/api/channels/{name}/messages?limit=50`

---

## User List

**GET** `/api/users`

---

## Server Status

**GET** `/api/status`

---

## WebSocket

**URL:** `ws://localhost:8081/?token=<JWT>`

- Authenticate via JWT in query string
- Message format:

```json
{
  "type": "message",
  "channel": "#channel",
  "message": "Text"
}
```

- Events: New messages, user join/part, channel changes (depending on implementation)

---

## Error Handling

All errors are returned as JSON with `success: false` and an `error` field.

**Example:**

```json
{
  "success": false,
  "error": "Authentication required"
}
```

---

## Notes

- All endpoints (except `/login`, `/refresh`, `/oauth/*`) require a valid JWT in the header: `Authorization: Bearer <JWT>`
- For OAuth2, redirect URIs and client IDs/secrets must be set in `config.php`.
- Channel moderation is only possible for operators.
- Refresh tokens are temporary in demo mode; persist them for production!

---

## Example: Create and Join Channel (curl)

```bash
# Login
TOKEN=$(curl -s -X POST http://localhost:8080/api/login -d '{"nick":"admin","password":"test123"}' -H 'Content-Type: application/json' | jq -r .token)

# Create channel
curl -X POST http://localhost:8080/api/channels -H "Authorization: Bearer $TOKEN" -d '{"name":"#test"}' -H 'Content-Type: application/json'

# Join channel
curl -X POST http://localhost:8080/api/channels/%23test/join -H "Authorization: Bearer $TOKEN"
```

---

## Example: OAuth2 Login (Flow)

1. Open `/api/oauth/login?provider=google` or `/api/oauth/login?provider=github` in your browser
2. After login and consent, you will be redirected to `/api/oauth/callback?...`
3. The API returns a JWT + refresh token

---

## Contact & Support

For questions and extensions, see the README or create an issue.
