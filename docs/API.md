# PHP-IRCd API Dokumentation

## Authentifizierung

### Login (JWT + Refresh-Token)

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

### Token-Refresh

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

- Leitet zu Google/GitHub weiter

**GET** `/api/oauth/callback?provider=google|github&code=...`

- Tauscht Code gegen Token, gibt JWT + Refresh-Token zurück

---

## User-Session

### Eigene User-Info

**GET** `/api/me` _(JWT erforderlich)_
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

- (Clientseitig, JWT einfach löschen)

---

## Channel-Management

### Channel-Liste

**GET** `/api/channels`

### Channel-Details

**GET** `/api/channels/{name}`

### Channel erstellen

**POST** `/api/channels`

```json
{
  "name": "#channel"
}
```

### Channel beitreten

**POST** `/api/channels/{name}/join`

### Channel verlassen

**POST** `/api/channels/{name}/part`

### Channel löschen

**DELETE** `/api/channels/{name}`

---

## Channel-Moderation

**POST** `/api/channels/{name}/moderate`

```json
{
  "action": "op|deop|voice|devoice|kick|ban|unban",
  "target": "nickname"
}
```

---

## Nachrichten

### Nachricht an Channel

**POST** `/api/messages`

```json
{
  "target": "#channel",
  "message": "Hallo Welt!"
}
```

### Private Nachricht

**POST** `/api/messages/private`

```json
{
  "to": "nickname",
  "message": "Hi!"
}
```

---

## Channel-Infos

### Channel-User-Liste

**GET** `/api/channels/{name}/users`

### Channel-Nachrichtenverlauf

**GET** `/api/channels/{name}/messages?limit=50`

---

## User-Liste

**GET** `/api/users`

---

## Server-Status

**GET** `/api/status`

---

## WebSocket

**URL:** `ws://localhost:8081/?token=<JWT>`

- Authentifizierung per JWT im Query-String
- Nachrichtenformat:

```json
{
  "type": "message",
  "channel": "#channel",
  "message": "Text"
}
```

- Events: Neue Nachrichten, User-Join/Part, Channel-Änderungen (je nach Implementierung)

---

## Fehlerbehandlung

Alle Fehler werden als JSON mit `success: false` und `error`-Feld zurückgegeben.

**Beispiel:**

```json
{
  "success": false,
  "error": "Authentication required"
}
```

---

## Hinweise

- Alle Endpunkte (außer `/login`, `/refresh`, `/oauth/*`) erfordern einen gültigen JWT im Header: `Authorization: Bearer <JWT>`
- Für OAuth2 müssen die Redirect-URIs und Client-IDs/-Secrets in der `config.php` hinterlegt werden.
- Channel-Moderation ist nur für Operatoren möglich.
- Refresh-Tokens sind im Demo-Modus temporär, für Produktion persistent speichern!

---

## Beispiel: Channel erstellen und beitreten (curl)

```bash
# Login
TOKEN=$(curl -s -X POST http://localhost:8080/api/login -d '{"nick":"admin","password":"test123"}' -H 'Content-Type: application/json' | jq -r .token)

# Channel erstellen
curl -X POST http://localhost:8080/api/channels -H "Authorization: Bearer $TOKEN" -d '{"name":"#test"}' -H 'Content-Type: application/json'

# Channel beitreten
curl -X POST http://localhost:8080/api/channels/%23test/join -H "Authorization: Bearer $TOKEN"
```

---

## Beispiel: OAuth2-Login (Ablauf)

1. Rufe `/api/oauth/login?provider=google` oder `/api/oauth/login?provider=github` im Browser auf
2. Nach Login und Consent wirst du zu `/api/oauth/callback?...` weitergeleitet
3. Die API gibt ein JWT + Refresh-Token zurück

---

## Kontakt & Support

Für Fragen und Erweiterungen siehe README oder erstelle ein Issue.
