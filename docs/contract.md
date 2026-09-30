# The gateway contract, from the client's side

A Minos plugin talks to the Wergiliusz **gateway** over HTTP and nothing else. This page is
the client's side of that contract: what a plugin sends, what it gets back, and what it
must do with it.

The gateway's own documentation is the source of truth; this copy is kept in step with it.
`tests/Client/ContractDocumentTest.php` executes the parts of it that can be executed.

**Wire strings are Polish and are never renamed**: JSON keys, error codes, header names and
enumeration values stay exactly as below, whatever the naming of the code.

## Status

The B2B route is **not yet open in production**. Until it is, develop against the mock
gateway (`mock-gateway/`, see the README).

The synchronous route, [`POST /api/v1/b2b/ocena`](#post-apiv1b2bocena), is described here
ahead of the gateway: it is still being built on the gateway's side and is not merged yet.
The mock gateway already answers it.

## The key

Every request carries `X-Gateway-Key: wgb2b_…`. The key is a record in the gateway's key
store, issued by the operator. The record decides everything; no request header does:

- **the class**, `b2b_free` or `b2b_paid`. Free B2B accepts up to 15 minutes of latency;
  only a paid key may use the synchronous route;
- **the profiles** the key may ask for. The first one is the default. The forum profiles
  are `forum_adult` and `forum_teen`;
- **the key's own limits**: per minute, per day and in flight. On top of them sits a
  global B2B ceiling that all keys share;
- **the webhook**: its URL, the hosts a delivery may go to, and its secret. The secret is
  printed once, when the key is issued.

A B2B key is refused on the gateway's other routes with `403 nie_ta_powierzchnia`.

## `POST /api/v1/b2b/oceny`

Send a batch and get **`202` at once**. Each verdict arrives later, as a signed `POST` to
the key's webhook.

```json
{
  "elementy": [
    { "id": "k-1027", "tekst": "treść komentarza", "profil": "forum_adult",
      "meta": { "links": 1, "author_first_post": true } }
  ]
}
```

| Field | Required | Description |
|---|---|---|
| `elementy` | yes | 1–20 items. |
| `elementy[].id` | yes | Your id of the comment: 1–64 characters of `A–Z a–z 0–9 . _ : -`, unique within the request. It comes back in the webhook. **Put no content in it.** |
| `elementy[].tekst` | yes | The comment, at most 3000 **characters** (not bytes). Surrounding whitespace is trimmed. |
| `elementy[].profil` | no | A profile on the key's list. Without it, the key's first profile is used. |
| `elementy[].meta` | no | Spam signals. Only five fields are kept (`links`, `author_account_age_days`, `author_posts_24h` as integers 0–100000; `author_first_post` as a boolean; `link_domains` as up to 10 registrable domains). Anything else is dropped silently, never answered with an error. **Never send an e-mail, IP address or author id.** |

**The webhook address is never read from the request.** A `webhook` or `url` field,
at the top or in an item, is ignored. Only the address in the key's record is used.

A batch is taken **whole or not at all**. One failing item refuses the request, with the
item's position (from 0) in `blad.element`, and nothing of the batch is queued.

### Response `202`

```json
{ "przyjete": ["k-1027"] }
```

`202` promises an attempt, not a verdict. See [delivery](#delivery-retries-and-nieocenione).

### Errors

Every refusal has the shape `{"blad": {"kod", "komunikat", "ponow_za_s"?, "element"?}}`.
`ponow_za_s` travels in the body; there is no `Retry-After` header.

| HTTP | `kod` | When |
|---|---|---|
| 401 | `brak_klucza` | no key, or an unknown one |
| 403 | `nie_ta_powierzchnia` | the key belongs to another surface |
| 403 | `brak_webhooka` | the key has no usable webhook, so its results could never be delivered |
| 403 | `profil_niedozwolony` | an item asks for a profile that is not on the key's list (`element`) |
| 400 | `bledne_wejscie` | the body is not a JSON object, `elementy` is not a list, or an item is not an object (`element`) |
| 400 | `brak_elementow` | `elementy` is empty |
| 400 | `bledny_identyfikator` / `powtorzony_identyfikator` | an item's `id` is malformed / repeated (`element`) |
| 400 | `brak_tekstu` | an item has no text (`element`) |
| 413 | `za_duzo_elementow` | more than 20 items |
| 413 | `limit_dlugosci` | an item is longer than 3000 characters (`element`) |
| 413 | `za_duze_zadanie` | the body is over its ceiling, before it is parsed |
| 429 | `kolejka_pelna` | the gateway's queue is full (`ponow_za_s`) |
| 429 | `limit_minutowy_klucza` / `limit_dobowy_klucza` / `limit_w_locie_klucza` / `limit_globalny_b2b` | the key's limits, or the shared B2B ceiling (`ponow_za_s`) |
| 503 | `kolejka_niedostepna` | the queue cannot work right now |
| 404 | `nie_znaleziono` | B2B is off on this gateway |

The checks run in this order:
1. the webhook;
2. the queue's readiness;
3. the batch;
4. the profiles;
5. the queue's room;
6. the key's own limits and the global ceiling.

A refusal the key did not cause (a full queue) spends none of its allowance. The limits
count **requests**, not items.

**What a plugin does with a refusal:**
- On `429` and `503`, keep the comment pending and retry after `ponow_za_s`, or after a
  backoff when that field is missing.
- On `4xx` other than `429`, the request is wrong and retrying will not help. Treat it
  as a configuration error and show it to the forum's administrator.

## The webhook

### Payload

Built from zero by the gateway. Nothing else is in it: no tier, model, cost, token count,
score, confidence, profile name, `reason` or `evidence`.

```json
{
  "id": "k-1027",
  "status": "ocenione",
  "kwalifikacja": "ocenzurowane",
  "kategorie": ["wulgaryzmy"],
  "ocenzurowany": "no to jest ███████ pomysł",
  "wsparcie": false,
  "wersja": "3f0c9a41d2b7e8c5"
}
```

| Field | Description |
|---|---|
| `id` | your item id |
| `status` | `ocenione`, or `nieocenione` (see below) |
| `kwalifikacja` | `bezpieczne` \| `ocenzurowane` \| `zablokowane` |
| `kategorie` | named categories, sorted. The possible labels are `mowa_nienawisci`, `grozba`, `przemoc`, `nekanie`, `tresc_seksualna`, `samookaleczenie`, `oszustwo`, `spam`, `ujawnianie_danych`, `dane_osobowe`, `wulgaryzmy`, `uzywki`, `uwodzenie_nieletnich`, `hazard`, `niebezpieczne_zachowanie`. A category the gateway does not know is **not named**, so an empty list is a valid answer. |
| `ocenzurowany` | present **only** with `ocenzurowane`, and only when a fragment was masked (`█` per character) |
| `wsparcie` | `true` when the comment reads as self-harm (`samookaleczenie`), even when it was let through. This is your cue to show support, not to punish. |
| `wersja` | opaque, 16 hex characters. Equal values mean the same engine version, configuration and knowledge. It says nothing about the tuning. `null` when the engine did not report its version. |

A `nieocenione` payload is exactly `{"id": "k-1027", "status": "nieocenione"}`.

### Delivery, retries and `nieocenione`

- **A delivery** is a `POST` with `Content-Type: application/json; charset=utf-8` and
  `X-Wergiliusz-Podpis`. Any `2xx` counts as delivered, and the answer's body is
  discarded unread.
- **A failed delivery** (no connection, a timeout, a non-`2xx` answer) is retried with a
  growing backoff of 5 s, 10 s, 20 s and so on, at most 2 minutes, until shortly before
  the entry's TTL (15 minutes by default). Then it is given up, and the verdict with it:
  the gateway keeps no verdict anywhere.
- **`nieocenione`** is sent when the engine cannot assess the comment in time, or answers
  something the gateway does not recognise as a verdict. **The gateway never guesses a
  verdict. Whether to publish such a comment is the plugin's fail-open or fail-closed
  setting.**
- **Delivery is at least once.** A worker restart can repeat a delivery. **Use `id` to
  drop a repeat.**
- **An item may get no answer at all**, if the gateway's worker was down for the whole
  TTL. Treat an item without an answer after the TTL plus a few minutes as `nieocenione`.

### Where a delivery may go

Deliveries go only to the key's webhook, and only if all of these hold:
- the URL is `https`, with a host **name** (never an IP literal) and no credentials;
- the host is on the key's list;
- every address the host resolves to is public.

Redirects are not followed. A refused target is not retried. Plan the plugin's receiving
endpoint accordingly: a public HTTPS URL on the forum's own domain.

### The signature

```
X-Wergiliusz-Podpis: t=1727430000,v1=299d2efa59614cfd71860eac52c66357d750ef428c13409c82b251d8c786b797
```

`v1` is the hex HMAC-SHA256 of `"<t>.<body>"`: the timestamp, a dot, and the **exact bytes
of the body**. It is keyed with the key's webhook secret. **Verify it before you parse the
body**, compare in constant time, and refuse a timestamp more than five minutes from your
clock. Every attempt is signed anew, with its own `t`.

Reference verifier, copied unchanged from the gateway's documentation.
`tests/Client/ContractDocumentTest.php` runs this very block against the vector below.
`Minos\Client\Signature::verify` is the same code, for plugins:

<!-- reference-verifier:start -->
```php
function verify_wergiliusz_signature(string $secret, string $header, string $body,
    int $now, int $toleranceS = 300): bool {
    if (!preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', trim($header), $m)) {
        return false;
    }
    if (abs($now - (int)$m[1]) > $toleranceS) {
        return false;
    }
    $expected = hash_hmac('sha256', $m[1] . '.' . $body, $secret);
    return hash_equals($expected, $m[2]);
}
```
<!-- reference-verifier:end -->

Test vector. The body is one line of UTF-8, signed byte for byte. The same vector, in
machine-readable form, is in `vectors/signature.json`:

<!-- test-vector:start -->
```
secret: 5f2d3c8a9b1e4f607d8c2a1b3e4f5a6b7c8d9e0f1a2b3c4d
timestamp: 1727430000
body: {"id":"k-1","status":"ocenione","kwalifikacja":"ocenzurowane","kategorie":["wulgaryzmy"],"ocenzurowany":"no to jest ███████ pomysł","wsparcie":false,"wersja":"3f0c9a41d2b7e8c5"}
header: t=1727430000,v1=299d2efa59614cfd71860eac52c66357d750ef428c13409c82b251d8c786b797
```
<!-- test-vector:end -->

## A plugin's receiving checklist

1. Read the raw body **before** anything parses it. In WordPress, for example, read
   `php://input` in a REST route and never the re-encoded parameters.
2. Verify `X-Wergiliusz-Podpis` (`Signature::verify`) and answer `401` to a failure.
   The gateway retries, so a legitimate delivery with a clock issue gets another chance.
3. Parse (`WebhookPayload::parse`) and drop a payload whose `id` is not a pending comment
   of this forum, or was already handled. Deliveries repeat.
4. Apply the verdict:
   - `bezpieczne` → publish;
   - `ocenzurowane` → publish `ocenzurowany`, or hold when it is absent;
   - `zablokowane` → hold or reject, as the forum administrator has configured it;
   - `nieocenione` → the plugin's fail-open or fail-closed setting;
   - `wsparcie` → show support, whatever the verdict.
5. Answer `2xx` quickly and do the slow work afterwards. The gateway gives one delivery
   10 seconds by default, connection included.

## `POST /api/v1/b2b/ocena`

One comment, and its verdict **in the response**: no queue, no webhook, no signature.
**For paid keys only** (`b2b_paid`). A free key is refused with `403 tylko_klucze_platne`,
because free B2B is asynchronous only.

```json
{ "tekst": "treść komentarza", "profil": "forum_adult", "meta": { "links": 1 } }
```

| Field | Required | Description |
|---|---|---|
| `tekst` | yes | The comment, a string: 1–3000 **characters** (not bytes) after surrounding whitespace is trimmed. |
| `profil` | no | A profile on the key's list. Without it, the key's first profile is used. |
| `meta` | no | The same five spam signals as in a batch item. Anything else is dropped silently. **Never send an e-mail, IP address or author id.** |

One request is one comment: there is no `id`, no `elementy` and no batch.

### Response `200`

The [webhook payload](#payload) **without `id`**; its fields mean the same:

```json
{
  "status": "ocenione",
  "kwalifikacja": "ocenzurowane",
  "kategorie": ["wulgaryzmy"],
  "ocenzurowany": "no to jest ███████ pomysł",
  "wsparcie": false,
  "wersja": "3f0c9a41d2b7e8c5"
}
```

When the engine is unavailable, does not answer in time, or answers something the gateway
does not recognise as a verdict, the answer is `200` with exactly
`{"status": "nieocenione"}`. **The gateway never guesses a verdict.** Apply the plugin's
fail-open or fail-closed setting, as for a `nieocenione` webhook, and do the same when your
own request to the gateway times out: without an answer there is no verdict.

### Errors

The same shape, `{"blad": {"kod", "komunikat", "ponow_za_s"?}}`, with no `element` and no
`Retry-After` header.

| HTTP | `kod` | When |
|---|---|---|
| 401 | `brak_klucza` | no key, or an unknown one |
| 403 | `nie_ta_powierzchnia` | the key belongs to another surface |
| 403 | `tylko_klucze_platne` | a free key (`b2b_free`): this route is for paid keys only |
| 403 | `profil_niedozwolony` | `profil` is not on the key's list |
| 400 | `bledne_wejscie` | the body is not a JSON object, or `tekst` is there but is not a string |
| 400 | `brak_tekstu` | no `tekst`, or only whitespace |
| 413 | `limit_dlugosci` | `tekst` is longer than 3000 characters |
| 413 | `za_duze_zadanie` | the body is over its ceiling, before it is parsed |
| 429 | `limit_minutowy_klucza` / `limit_dobowy_klucza` / `limit_w_locie_klucza` / `limit_globalny_b2b` | the key's limits, or the shared B2B ceiling (`ponow_za_s`) |
| 503 | `silnik_przeciazony` | the engine cannot take the comment right now (`ponow_za_s`) |
| 404 | `nie_znaleziono` | B2B is off on this gateway |

The checks run in this order:
1. the key and its class;
2. the body's size;
3. the JSON;
4. the text;
5. the profile;
6. the key's own limits and the global ceiling.

**What a client does with a refusal:**
- On `429` and **every** `503`, whatever its `kod`, the comment has no verdict yet: apply
  the fail-open or fail-closed setting now, or retry after `ponow_za_s` (after a backoff
  when that field is missing). Tell a `503` by its status, never by its code.
- On `4xx` other than `429`, the request is wrong and retrying will not help. Treat it
  as a configuration error and show it to the forum's administrator.
