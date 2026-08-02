# OctoPrint API compatibility

WPrint 3D exposes a deliberately small OctoPrint 1.11.x-compatible facade at
`/api`. The native frontend API remains at `/backend/api`; the two namespaces
have separate authentication boundaries and routing.

The compatibility profile reports API version `0.1`. It is intended for Cura,
OctoPrint-aware upload clients, and basic job controls. It is not a complete
OctoPrint server implementation.

## Authentication

Protected routes accept one of these forms:

- `X-Api-Key: <token>`
- `Authorization: Bearer <token>`
- `?apikey=<token>` for legacy clients
- the session cookie returned by `POST /api/login`

Missing, expired, revoked, or invalid credentials return `403`. WPrint 3D only
stores a SHA-256 hash of each token. A token is bound to a stable
`machine.uuid`; it cannot be moved to another printer by sending a different
header. Password sessions may additionally send `X-WPrint3D-Printer-UUID`.

Tokens have fixed capabilities: `read`, `files`, `print`, and `control`.
Spectator accounts receive only `read`.

```bash
HOST=https://wprint3d.local
COOKIE_JAR=./wprint3d-cookies.txt

curl --fail-with-body --insecure \
  --cookie-jar "$COOKIE_JAR" \
  --header 'Content-Type: application/json' \
  --data '{"user":"operator","pass":"replace-me"}' \
  "$HOST/api/login"

curl --fail-with-body --insecure \
  --cookie "$COOKIE_JAR" \
  "$HOST/api/wprint3d/printers"
```

`--insecure` is appropriate only for initial diagnostics against the default
self-signed certificate. Production clients should validate a public
certificate or explicitly pin the verified WPrint 3D SHA-256 fingerprint.

## Routes

| Method and path | v1 behavior |
| --- | --- |
| `POST /api/login` | Active login with `user`/`pass`, or passive login with `passive=true`. |
| `POST /api/logout` | Invalidates the compatibility session. |
| `GET /api/currentuser` | OctoPrint-style user, group, and permission fields. |
| `POST/DELETE /api/access/users/{username}/apikey` | Regenerates or revokes the personal key for the selected printer. |
| `GET /api/version` | Reports API `0.1`, OctoPrint 1.11.x compatibility, and WPrint 3D metadata. |
| `GET /api/wprint3d/printers` | Lists host printers and the current selection. |
| `GET /api/wprint3d/cameras` | Lists enabled cameras linked to the selected printer, including stream and snapshot URLs. |
| `GET /api/wprint3d/terminal` | WPrint 3D extension returning recent console lines for the selected printer. |
| `POST /api/wprint3d/printer` | Selects `{ "printerUuid": "..." }` for a password session. |
| `GET /api/files` and `GET /api/files/local` | Lists recursively stored local G-code. |
| `POST /api/files/local` | Multipart upload; supports OctoPrint `select` and `print` booleans. |
| `GET/POST/DELETE /api/files/local/{path}` | Reads metadata, selects/prints/unselects, or deletes local G-code. |
| `GET/POST /api/job` | Reads the active job or sends `start`, `pause`, `resume`, and `cancel`. |
| `GET /api/printer` | Operational flags and tool/bed temperatures. |
| `POST /api/printer/command` | Queues one `command` or a `commands` array using OctoPrint's printer-command contract. |
| `GET /api/connection` | Current WPrint 3D serial connection state. |

Only the `local` storage origin is supported.

Temperature maps follow OctoPrint's JSON object contract. When no cached tool
or bed statistics are available, `/api/printer` returns `"temperature": {}` and
`/api/printer/tool` returns `{}`, never an empty JSON array.

The camera extension returns only enabled cameras linked to the selected
printer. Stream and snapshot values are relative `/video/...` URLs on the same
WPrint 3D host; arbitrary external camera URLs are not relayed to clients.

The terminal extension accepts an optional `limit` query parameter between 1
and 500 and returns `lines`, `total`, `truncated`, and an opaque `cursor`. It is
available with `read` access. Sending commands uses OctoPrint's standard
`POST /api/printer/command` endpoint and requires `control` access:

```bash
curl --fail-with-body \
  --header "X-Api-Key: $TOKEN" \
  --header 'Content-Type: application/json' \
  --data '{"command":"M115"}' \
  "$HOST/api/printer/command"
```

The command endpoint accepts either one `command` string or an array named
`commands`, never both, and returns `204` after queuing them. Each command must
be a single line of at most 512 characters; one request may contain at most 25
commands. Arbitrary commands can alter printer state or interrupt a running
job, so clients should expose this endpoint only to deliberate operator input.
Named OctoPrint scripts and custom controls are not implemented.

## Upload and print

The server sanitizes upload names, rejects traversal and absolute paths, and
uses a Redis-backed per-printer lock while selecting and starting a job. An
inactive duplicate may be replaced; an active file cannot be overwritten or
deleted. When a failed job retains its file for WPrint 3D recovery, replacement
and new print requests return `409` with `reason: recovery_pending` until the
operator recovers or dismisses that session in the WPrint 3D web panel.

```bash
TOKEN='replace-with-the-one-time-secret'

curl --fail-with-body \
  --header "X-Api-Key: $TOKEN" \
  --form 'select=true' \
  --form 'print=true' \
  --form 'file=@example.gcode;type=text/x-gcode' \
  "$HOST/api/files/local"
```

Selection without upload uses the standard command body:

```bash
curl --fail-with-body \
  --header "X-Api-Key: $TOKEN" \
  --header 'Content-Type: application/json' \
  --data '{"command":"select","print":true}' \
  "$HOST/api/files/local/example.gcode"
```

Incompatible state transitions, disconnected printers, active jobs, and lock
contention return `409` with a machine-readable `reason` where applicable.
`activeFile` can remain populated for recovery without representing a running
job. In that case `/api/job`, `/api/printer`, and `/api/connection` report
`Recovery required`, while the printer remains connected and available for
safe recovery controls.

## Native token administration

The WPrint 3D frontend uses these native routes:

- `POST /backend/api/user/confirm-password`
- `GET/POST /backend/api/users/{userId}/tokens`
- `DELETE /backend/api/users/{userId}/tokens/{tokenId}`

Creation accepts `name`, `printerUuid`, and `expiresInDays`. Expiration can be
30, 90, 365, or `null`, and defaults to 365 days. The creation response includes
`plainTextToken` exactly once. Lists include timestamps, expiry, last use, and
printer UUID but never the secret. The operator's password confirmation remains
valid for five minutes. Users manage their own tokens; administrators may
manage other users' tokens.

## Proxy routing

nginx reserves public `/api` and rewrites it to Laravel's internal
`/octoprint-api` route group. `/backend/api` continues to use the native route
group. The same rule is present in the production proxy image and in the
development bind-mounted configuration.

## Deliberate v1 omissions

Application Keys, compatible group/user CRUD, WebSocket push, virtual SD,
remote STL slicing, standard OctoPrint webcam endpoints beyond the WPrint 3D
camera extension, named scripts, custom controls, and full OctoPrint plugin
emulation are outside this version. Clients requiring those
features should feature-detect instead of assuming a complete OctoPrint
installation.

See the official OctoPrint documentation for the upstream
[authentication](https://docs.octoprint.org/en/main/api/general.html) and
[file operations](https://docs.octoprint.org/en/main/api/files.html) contracts,
plus the standard [printer command](https://docs.octoprint.org/en/main/api/printer.html#send-an-arbitrary-command-to-the-printer) endpoint.
