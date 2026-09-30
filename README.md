# Minos client for PHP

The PHP side of the Wergiliusz gateway's B2B API, for the Minos forum plugins: a forum
sends its new posts to the gateway, and each verdict comes back as a signed webhook. This
repository holds what a PHP plugin needs to receive that webhook safely, and a mock gateway
to develop against.

| Path | What |
|---|---|
| `src/` | `Minos\Client`: `Signature` (verify a delivery) and `WebhookPayload` (read one). PHP 7.4+, no dependencies beyond `ext-json`, meant to be bundled into plugins. |
| `docs/contract.md` | The contract, from the client's side: the request, the errors, the webhook, the signature and a plugin's receiving checklist |
| `vectors/` | The gateway's test vectors (the webhook signature) |
| `mock-gateway/` | A local stand-in for the gateway's B2B routes (batch and synchronous) and its delivery worker |
| `tests/` | PHPUnit: the library, the mock, the contract document, the Claude Code rules |

## Receiving a verdict

Read [`docs/contract.md`](docs/contract.md) before writing plugin code; its receiving
checklist is the short version of this:

```php
use Minos\Client\Signature;
use Minos\Client\WebhookPayload;

$body = file_get_contents('php://input');                 // the raw bytes, before any parsing
$header = $_SERVER['HTTP_X_WERGILIUSZ_PODPIS'] ?? '';
if (!Signature::verify($webhookSecret, $header, $body, time())) {
    http_response_code(401);                              // the gateway retries
    exit;
}
$verdict = WebhookPayload::parse($body);                  // null: not a usable payload
// Drop an unknown or already handled $verdict['id'], then apply
// $verdict['kwalifikacja'], or the plugin's fail-open/fail-closed setting when
// $verdict['status'] is 'nieocenione'. Answer 2xx quickly.
```

## Tests

```bash
composer install
vendor/bin/phpunit
```

CI runs the suite on PHP 7.4 and 8.3.

## The mock gateway

The real B2B route is off in production (see the contract's status). The mock answers
like it: the same checks, the same error codes, and verdicts delivered to your webhook,
signed the same way, or answered at once on the synchronous route. It needs no
`composer install`.

```bash
# 1. The API (terminal 1)
export MINOS_MOCK_WEBHOOK_URL=http://localhost:8080/wergiliusz/webhook
php -S 127.0.0.1:8100 -t mock-gateway/public

# 2. The worker that delivers verdicts (terminal 2, same variables)
export MINOS_MOCK_WEBHOOK_URL=http://localhost:8080/wergiliusz/webhook
php mock-gateway/bin/worker.php

# 3. A batch (terminal 3)
curl -s http://127.0.0.1:8100/api/v1/b2b/oceny \
  -H 'X-Gateway-Key: wgb2b_atrapa_minos_0000000000000000' \
  -H 'Content-Type: application/json' \
  -d '{"elementy":[{"id":"k-1","tekst":"Świetny wpis!"},
                   {"id":"k-2","tekst":"spadaj [minos:blokuj] [minos:kategoria=nekanie]"}]}'

# 4. One comment, synchronously: the verdict is the answer, nothing is queued or delivered
curl -s http://127.0.0.1:8100/api/v1/b2b/ocena \
  -H 'X-Gateway-Key: wgb2b_atrapa_minos_0000000000000000' \
  -H 'Content-Type: application/json' \
  -d '{"tekst":"spadaj [minos:blokuj] [minos:kategoria=nekanie]"}'
```

The mock never assesses content. **You choose the verdict** with markers in the comment:

| Marker | Effect |
|---|---|
| (none) | `bezpieczne` |
| `[minos:blokuj]` | `zablokowane` |
| `[minos:cenzuruj]` | `ocenzurowane`. Every `[[fragment]]` is masked in `ocenzurowany`. |
| `[minos:kategoria=<label>]` | adds a category. `samookaleczenie` also sets `wsparcie`. |
| `[minos:nieocenione]` | `status: nieocenione` (exactly `{"status":"nieocenione"}` on the synchronous route) |
| `[minos:bez-wersji]` | `wersja: null` |
| `[minos:dwa-razy]` | delivered twice (at-least-once delivery) |
| `[minos:zly-podpis]` | signed with a wrong secret |
| `[minos:stary-podpis]` | signed ten minutes ago |
| `[minos:cisza]` | never delivered; the entry disappears with its TTL |

The four delivery markers (`dwa-razy`, `zly-podpis`, `stary-podpis`, `cisza`) mean nothing
on the synchronous route, which delivers nothing.

To test error handling, send the mock-only header `X-Minos-Mock-Error: <kod>` with any
code from the contract's error tables (for example `kolejka_pelna`,
`limit_minutowy_klucza` or `silnik_przeciazony`), on either route. On the synchronous
route the key and its class are checked first, as in the gateway.

Settings (environment):

| Variable | Default |
|---|---|
| `MINOS_MOCK_WEBHOOK_URL` | none. Without it, every batch gets `403 brak_webhooka`. |
| `MINOS_MOCK_WEBHOOK_SECRET` | `atrapa-minos-sekret-webhooka-tylko-lokalnie` |
| `MINOS_MOCK_KEY` | `wgb2b_atrapa_minos_0000000000000000` |
| `MINOS_MOCK_KEY_CLASS` | `b2b_paid`. With `b2b_free` (or any other value) the synchronous route answers `403 tylko_klucze_platne`; batches are accepted either way. |
| `MINOS_MOCK_PROFILES` | `forum_adult,forum_teen` (the first is the default) |
| `MINOS_MOCK_MAX_ITEMS` / `MINOS_MOCK_MAX_CHARS` / `MINOS_MOCK_QUEUE_MAX` | `20` / `3000` / `500`, the gateway's defaults |
| `MINOS_MOCK_TTL_S` | `900` |
| `MINOS_MOCK_DELAY_S` | `2`: seconds before the first delivery attempt |
| `MINOS_MOCK_BACKOFF_S` | `5`: first retry pause, which doubles up to 120 s |
| `MINOS_MOCK_WEBHOOK_TIMEOUT_S` | `5` |
| `MINOS_MOCK_DATA_DIR` | `<temp>/minos-mock`: the queue file |

**Where the mock differs from the gateway:**
- It keeps its queue as plain JSON on your disk, so **never send real users' comments to
  it**.
- It delivers to any URL, `http://localhost` included. The gateway delivers only over
  `https`, to hosts on the key's list that resolve to public addresses.
- It does not simulate the key's rate limits; use `X-Minos-Mock-Error` instead.
- It answers the synchronous route at once, from the markers, and serves it already; the
  gateway waits for its engine there, and does not serve the route yet (see the
  contract's status).
