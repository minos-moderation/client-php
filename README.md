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
| `CHANGELOG.md` | What each release contains, its known differences from the gateway, and the version policy (0.x; see "Versioning") |

A release archive (`composer install --prefer-dist`, the zip of a tag) carries `src/`,
`docs/`, `vectors/` and `mock-gateway/`, which the plugins use from `vendor/`, and leaves out
what only this repository's development needs: `tests/`, `.claude/`, `.github/`, the
`CLAUDE.md` files and `phpunit.xml.dist` (`.gitattributes`).

## Receiving a verdict

Read [`docs/contract.md`](docs/contract.md) before writing plugin code; its receiving
checklist is the short version of this:

```php
use Minos\Client\Signature;
use Minos\Client\WebhookPayload;

// $webhookSecret: the secret stored for this forum's key, as the forum's storage returns it
$body = file_get_contents('php://input');                 // the raw bytes, before any parsing
$header = $_SERVER['HTTP_X_WERGILIUSZ_PODPIS'] ?? '';
if (!is_string($webhookSecret) || $webhookSecret === ''
    || !Signature::verify($webhookSecret, $header, $body, time())) {
    http_response_code(401);                              // no secret, or a bad signature
    exit;                                                 // the gateway retries
}
$verdict = WebhookPayload::parse($body);                  // null: not a usable payload
// Drop an unknown or already handled $verdict['id'], then apply
// $verdict['kwalifikacja'], or the plugin's fail-open/fail-closed setting when
// $verdict['status'] is 'nieocenione'. Answer 2xx quickly.
```

A missing or empty stored secret is always a refusal, never a valid signature: anyone can
sign with an empty secret, a `false` that a forum's storage returns for a missing setting
would be read as one, and `Signature::verify`, the same code as the contract's reference
verifier, leaves that guard to the caller.

To tell the forum's administrator why a delivery was refused, `Signature::check()` takes
the same arguments and returns the reason: `Signature::OK` exactly when `verify` is true,
else `Signature::MALFORMED` (the header's form), `Signature::STALE` (the clock: more than
five minutes apart) or `Signature::MISMATCH` (another secret, or a body that is not the
exact bytes sent). Put the reason in the administrator's log only, never in the HTTP
answer: the sender gets the same `401` whatever it is.

What the library does at the edges, so a plugin does not have to guess:

- `Signature::verify` accepts a timestamp up to five minutes **either side** of your clock:
  the window is symmetric. It trims the header before reading it and forgives nothing else
  (`t=…, v1=…`, with a space after the comma, is malformed).
- `WebhookPayload::parse` is for a webhook's body only. The synchronous route's answer has
  no `id`, so `parse` returns `null` for it.
- `parse` reads what it cannot use as absent, never as a verdict: a missing `kategorie` (or
  one that is not a JSON list) is `[]`, an upper-case or malformed `wersja` is `null`, an
  `ocenzurowany` that masks nothing (no `█`) is `null`, so the comment is held, and an
  unknown `reakcja` in `dane_osobowe` is `nieocenione`.
- Give every revision of a post its own `id` (`<platform>:<post id>.<n>`), and acknowledge
  and ignore a verdict for an older revision: the verdict is for the text that was sent.

## Versioning

Semantic versioning from `v0.1.0`, with the notes of each release in
[`CHANGELOG.md`](CHANGELOG.md) and in the GitHub release of its tag. The public API, what a
plugin may rely on, is `Minos\Client`:

- `Signature::verify`, `Signature::check` and its reasons (`OK`, `MALFORMED`, `STALE`,
  `MISMATCH`), `Signature::sign`, `Signature::HEADER` and `Signature::DEFAULT_TOLERANCE_S`;
- `WebhookPayload::parse` and the shape of its result (`id`, `status`, `kwalifikacja`,
  `kategorie`, `ocenzurowany`, `wsparcie`, `wersja`, `dane_osobowe`), and the constants
  `ASSESSED`, `UNASSESSED`, `QUALIFICATIONS`, `CATEGORIES`, `PERSONAL_DATA_KINDS` and
  `PERSONAL_DATA_REACTIONS`.

The mock gateway (its settings, markers and classes) and the tests are not API; the
changelog still says what changed in them. Until 1.0.0 a minor version (0.2.0, 0.3.0, …)
carries a new or changed public method or a contract copy brought up to the gateway, and a
patch only fixes or narrows: a change that makes the library refuse more, failing closed,
is a patch (the policy at the end of the changelog). `tests/Repo/PublicApiTest.php` holds
the API to a declared list, so a change to it is always deliberate.

## Tests

```bash
composer install
vendor/bin/phpunit
```

CI runs the suite on PHP 7.4 and 8.3, `composer validate --strict`, `actionlint` on the
workflows and `shellcheck` on the shell scripts; its actions are pinned to commit SHAs.
`tests/Repo/Php74SyntaxTest.php` reads every PHP file for the PHP 8 syntax a 7.4 host
cannot run, so a file no test loads is checked too.

## The mock gateway

The real batch route has been open in production since 2026-10-25 and the synchronous one
is still closed (see the contract's status); never test against them. The mock answers as
[the contract's copy](docs/contract.md) describes the two routes, with the checks and error
codes it lists, and verdicts delivered to your webhook, signed the same way, or answered at
once on the synchronous route. Like the gateway in production, it keeps the synchronous
route closed until you open it (`MINOS_MOCK_SYNC=1`). It needs no `composer install`.

```bash
# 1. The API (terminal 1)
export MINOS_MOCK_WEBHOOK_URL=http://localhost:8080/wergiliusz/webhook
php -S 127.0.0.1:8100 -t mock-gateway/public

# 2. The worker that delivers verdicts (terminal 2, same variables)
export MINOS_MOCK_WEBHOOK_URL=http://localhost:8080/wergiliusz/webhook
php mock-gateway/bin/worker.php

# 3. A batch (terminal 3); the second item comes with its conversation context
curl -s http://127.0.0.1:8100/api/v1/b2b/oceny \
  -H 'X-Gateway-Key: wgb2b_atrapa_minos_0000000000000000' \
  -H 'Content-Type: application/json' \
  -d '{"elementy":[{"id":"k-1","tekst":"Świetny wpis!"},
                   {"id":"k-2","tekst":"dzwoń [minos:dane-osobowe=telefon]",
                    "kontekst":[{"rola":"inny","tekst":"Podaj numer."}]}]}'

# 4. One comment, synchronously (start the API with MINOS_MOCK_SYNC=1 to open the route):
#    the verdict is the answer, nothing is queued or delivered
curl -s http://127.0.0.1:8100/api/v1/b2b/ocena \
  -H 'X-Gateway-Key: wgb2b_atrapa_minos_0000000000000000' \
  -H 'Content-Type: application/json' \
  -d '{"tekst":"spadaj [minos:blokuj] [minos:kategoria=nekanie]"}'
```

The paths also answer without `/v1` (`/api/b2b/oceny`, `/api/b2b/ocena`), an alias the
gateway documents for its routes; use `/api/v1/…`. Any other path answers
`404 nie_znaleziono`.

The mock never assesses content. **You choose the verdict** with markers in the comment:

| Marker | Effect |
|---|---|
| (none) | `bezpieczne` |
| `[minos:blokuj]` | `zablokowane` |
| `[minos:cenzuruj]` | `ocenzurowane`. In `ocenzurowany`, every `[[fragment]]` becomes `[[█…]]`, `█` per character: the brackets and markers stay, so the masked text is exactly as long as the (trimmed) text you sent, as with the gateway. No `[[…]]`, or nested or unbalanced brackets: no `ocenzurowany`. |
| `[minos:kategoria=<label>]` | adds a category. A label outside the contract's list, or none, is ignored. `samookaleczenie` also sets `wsparcie`. |
| `[minos:wsparcie]` | `wsparcie` without a category, as the gateway sends it for a crisis it cannot name |
| `[minos:dane-osobowe=<rodzaj>]` or `[minos:dane-osobowe=<rodzaj>:<reakcja>]` | the `dane_osobowe` section (`reakcja` is `ukryj` unless you name another), with a kind and a reaction from the contract's lists. As with the gateway, only for a comment sent with a `kontekst`, and never with `zablokowane` or `nieocenione`. |
| `[minos:nieocenione]` | `status: nieocenione` (exactly `{"status":"nieocenione"}` on the synchronous route) |
| `[minos:bez-wersji]` | `wersja: null` |
| `[minos:dwa-razy]` | delivered twice (at-least-once delivery) |
| `[minos:zly-podpis]` | signed with a wrong secret |
| `[minos:stary-podpis]` | signed ten minutes ago |
| `[minos:nieczytelne]` | signed with the right secret, but the body is the payload cut in half: a delivery that verifies and is no payload (`parse` returns `null`) |
| `[minos:cisza]` | never delivered; the entry disappears with its TTL |

`nieocenione` wins over `blokuj`, and `blokuj` over `cenzuruj`. The five delivery markers
(`dwa-razy`, `zly-podpis`, `stary-podpis`, `nieczytelne`, `cisza`) mean nothing on the
synchronous route, which delivers nothing. The worker signs every attempt at the moment it
is made, however long its pass over the queue takes.

To test error handling, send the mock-only header `X-Minos-Mock-Error: <kod>` with a code
from that route's error table in the contract: for example `kolejka_pelna` or
`limit_minutowy_klucza` on the batch route, `silnik_przeciazony` or `tylko_klucze_platne`
on the synchronous one. A code the route never sends (`kolejka_pelna` on the synchronous
route, say) answers `400 atrapa_nieznany_kod`. Two values that are no code exist for the
mock alone, on both routes, for what a proxy in front of a gateway may answer:
`503-bez-kodu` (a `503` whose `blad` has no `kod`) and `503-nie-json` (a `503` whose body
is HTML, not JSON). Tell a `503` by its status, never by its body. The header is read after
the key, so a
missing or unknown key still gets `401 brak_klucza`; on the synchronous route it is read
after the route's switch and the key's class, as the gateway checks them first: a closed
route answers `404 nie_znaleziono` and a free key `403 tylko_klucze_platne`, whatever the
header asks for.

On the batch route, a refusal before the webhook's check comes from the key
(`401 brak_klucza`), the body's size (`413 za_duze_zadanie`) or a body that is not a JSON
object (`400 bledne_wejscie`); then `403 brak_webhooka` when no webhook is set; then the
batch, item by item; then each item's profile; then each item's `kontekst`; then the
queue's room.

A comment's profile must be on the key's list (`MINOS_MOCK_PROFILES`) **and** on the B2B
list of the contract, as with the gateway: a profile off the B2B list is refused even when
the key lists it, also as the key's first profile. A queued comment whose profile the key no
longer allows when the worker reaches it (you changed `MINOS_MOCK_PROFILES` meanwhile) is
delivered as `nieocenione`.

Settings (environment):

| Variable | Default |
|---|---|
| `MINOS_MOCK_WEBHOOK_URL` | none. Without it, every batch gets `403 brak_webhooka`. |
| `MINOS_MOCK_WEBHOOK_SECRET` | `atrapa-minos-sekret-webhooka-tylko-lokalnie` |
| `MINOS_MOCK_KEY` | `wgb2b_atrapa_minos_0000000000000000` |
| `MINOS_MOCK_KEY_CLASS` | `b2b_paid`. With `b2b_free` (or any other value) the synchronous route answers `403 tylko_klucze_platne`; batches are accepted either way. |
| `MINOS_MOCK_SYNC` | `0`: the synchronous route is closed, as in production, and answers `404 nie_znaleziono` to the key. `1` opens it. |
| `MINOS_MOCK_PROFILES` | `forum_adult,forum_teen` (the first is the default) |
| `MINOS_MOCK_MAX_ITEMS` / `MINOS_MOCK_MAX_CHARS` / `MINOS_MOCK_QUEUE_MAX` | `20` / `3000` / `350`, the gateway's |
| `MINOS_MOCK_TTL_S` | `900` |
| `MINOS_MOCK_DELAY_S` | `2`: seconds before the first delivery attempt |
| `MINOS_MOCK_BACKOFF_S` | `5`: first retry pause, which doubles up to 120 s |
| `MINOS_MOCK_WEBHOOK_TIMEOUT_S` | `10`, the gateway's |
| `MINOS_MOCK_DATA_DIR` | `<temp>/minos-mock`: the queue file |

**Where the mock differs from the gateway:**
- It keeps its queue, conversation contexts included, as plain JSON on your disk, so
  **never send real users' comments to it**. It keeps `meta` as sent; the gateway keeps
  only the five known fields.
- It delivers to any URL, `http://localhost` included. The gateway delivers only over
  `https`, to hosts on the key's list that resolve to public addresses.
- It has one key and one webhook, both from the settings. Another surface's key, and so
  `403 nie_ta_powierzchnia`, exists only through `X-Minos-Mock-Error`.
- It does not simulate the key's rate limits, the shared ceiling or the queue's readiness;
  use `X-Minos-Mock-Error` instead. The `ponow_za_s` of a forced refusal is the mock's
  number.
- It does not pace its verdicts (about 30 a minute in the gateway): each comment is
  delivered `MINOS_MOCK_DELAY_S` after it was accepted. It retries a failed delivery up to
  the entry's TTL itself; the gateway stops a margin before it.
- It answers the synchronous route at once, from the markers; the gateway waits for its
  engine there, up to 20 seconds, or 30 with the context question (see the contract).
- It never assesses content: no personal data is replaced, and the `dane_osobowe` section
  comes only from its marker.
- Where the contract places no check (the body's size and the JSON against the webhook,
  `kontekst` in a batch), the order above is the mock's.
