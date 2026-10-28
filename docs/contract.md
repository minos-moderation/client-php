# The gateway contract, from the client's side

A Minos plugin talks to the Wergiliusz **gateway** over HTTP and nothing else. This page is
the client's side of that contract: what a plugin sends, what it gets back, and what it
must do with it.

The gateway's own documentation is the source of truth; this copy is kept in step with it.
`tests/Client/ContractDocumentTest.php` executes the parts of it that can be executed.

**Wire strings are Polish and are never renamed**: JSON keys, error codes, header names and
enumeration values stay exactly as below, whatever the naming of the code.

## Status

This copy follows the gateway's documentation as of **2026-10-28**.

The batch route, [`POST /api/v1/b2b/oceny`](#post-apiv1b2boceny), has been **open in
production** since 2026-10-25. It is the route the Minos plugins use: the gateway accepts
the items at once and delivers each verdict to the key's webhook, signed.

The synchronous route, [`POST /api/v1/b2b/ocena`](#post-apiv1b2bocena), exists on the
gateway but stays **closed in production** until a decision of its own. It has a switch
of its own, apart from the batch route's; while that is off, the route answers
`404 nie_znaleziono` to any key, a B2B key of either class or another surface's, whatever
the body, and asks the engine nothing (a missing or unknown key gets `401 brak_klucza`
first). It is described below for when it opens. The mock gateway keeps it closed as well,
unless you open it (see the README).

Develop and test against the mock gateway (`mock-gateway/`, see the README), never against
the production gateway.

The previous copy followed the gateway as of 2026-10-01 (this client's 0.1.0). A client
built on it keeps working, by its [receiving checklist](#a-plugins-receiving-checklist)
and each route's rules for a refusal. It sends no [conversation
context](#the-conversation-context-kontekst), so it meets neither the context's refusals
nor the `dane_osobowe` section, and its requests stay under the old, lower body ceilings.
`WebhookPayload::parse` ignored a key it did not know. Every new cause of `nieocenione`
goes to the plugin's fail-open or fail-closed setting and every `wsparcie` to support,
whatever the verdict (step 4). A refused profile is a `4xx`, shown to the forum's
administrator as a configuration error, and a full queue is a `429`, retried after
`ponow_za_s`.

## The key

Every request carries `X-Gateway-Key: wgb2b_…`. The key is a record in the gateway's key
store, issued by the operator. The record decides everything; no request header does:

- **the class**, `b2b_free` or `b2b_paid`. Free B2B accepts up to 15 minutes of latency;
  only a paid key may use the synchronous route;
- **the profiles** the key may ask for. The first one is the default. A B2B key may use
  only a profile on the gateway's B2B list: `forum_adult`, `forum_teen`, `blog_comments`,
  `ig_comments` and `api_generic`; the forum profiles are `forum_adult` and `forum_teen`.
  Any other profile is refused with `403 profil_niedozwolony` on every request, even when
  the key's record lists it, and also when it is the key's first profile and the request
  names none;
- **the key's own limits**: per minute, per day and in flight. Exceeding one is a `429` for
  that key alone. On top of them sits a global B2B ceiling that all keys share, 600
  requests an hour at the gateway's defaults: only a request the key's own limits admitted
  counts towards it, and a refusal by the ceiling spends none of the key's allowance;
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
| `elementy[].profil` | no | A profile on the key's list and on the B2B list ([the key](#the-key)). Without it, the key's first profile is used. |
| `elementy[].meta` | no | Spam signals. Only five fields are kept (`links`, `author_account_age_days`, `author_posts_24h` as integers 0–100000; `author_first_post` as a boolean; `link_domains` as up to 10 registrable domains). Anything else is dropped silently, never answered with an error. **Never send an e-mail, IP address or author id.** |
| `elementy[].kontekst` | no | The conversation before the comment: at most 8 entries `{rola, tekst}`, oldest first, 500 characters each. See [the conversation context](#the-conversation-context-kontekst). |

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
| 403 | `brak_webhooka` | the key has no usable webhook (none, no allowed host, or the URL's host off the key's list), so its results could never be delivered |
| 403 | `profil_niedozwolony` | an item asks for a profile that is not on the key's list, or for one off the B2B list whatever the key's list says (`element`) |
| 400 | `bledne_wejscie` | the body is not a JSON object, `elementy` is not a list, or an item is not an object (`element`) |
| 400 | `brak_elementow` | `elementy` is empty |
| 400 | `bledny_identyfikator` / `powtorzony_identyfikator` | an item's `id` is malformed / repeated (`element`) |
| 400 | `brak_tekstu` | an item has no text (`element`) |
| 413 | `za_duzo_elementow` | more than 20 items |
| 413 | `limit_dlugosci` | an item is longer than 3000 characters (`element`) |
| 400 | `bledny_kontekst` | an item's `kontekst` is not a list, an entry is not an object, `rola` is neither `autor` nor `inny`, or `tekst` is empty or not a string (`element`) |
| 413 | `za_duzy_kontekst` | an item's `kontekst` has more than 8 entries, or an entry is longer than 500 characters (`element`) |
| 413 | `za_duze_zadanie` | the body is over its ceiling, before it is parsed: 1,727,104 bytes, so every item and a full context fit sent as ASCII-escaped JSON (12 bytes a character): 20 × (3000 × 12 + 1024 + 8 × (500 × 12 + 64)) + 16,384 |
| 429 | `kolejka_pelna` | the gateway's queue is full: it holds 350 comments (`ponow_za_s`) |
| 429 | `limit_minutowy_klucza` / `limit_dobowy_klucza` / `limit_w_locie_klucza` / `limit_globalny_b2b` | the key's limits, or the shared B2B ceiling (`ponow_za_s`) |
| 503 | `kolejka_niedostepna` | the queue cannot work right now |
| 404 | `nie_znaleziono` | B2B is off on this gateway |

A missing or unknown key gets `401 brak_klucza` before anything else, and a body over its
ceiling is refused before it is parsed. The other checks run in this order:
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

### The queue and the assessment

The gateway keeps each accepted comment, with its context, in an encrypted queue until its
verdict is delivered, and at most for the entry's TTL: 15 minutes by default, a setting of
the gateway's operator of at most 30 minutes. The queue is the one place the gateway keeps
content.

The gateway's worker assesses the queued comments one at a time, about **30 a minute**, so
a full queue takes about 12 minutes, inside the TTL, and a comment waits for its verdict
about its place in the queue times two seconds. The pace decides only when a comment is
assessed, never how.

**Personal data never reaches the engine**, on either route: before the engine reads a
comment, the gateway replaces every phone number, address, PESEL number, payment card and
bank account number it detects with a plausible dummy. `ocenzurowany` masks the comment
**as you sent it**, so it covers the real data the engine censored and never shows a
dummy. The gateway masks only what the engine censored: a phone number the engine let
through stays in your comment, and whether to hide it from your readers is your decision.
When that step cannot vouch for a text, the comment's or its context's, the comment is
`nieocenione`, the engine is not asked, and no retry follows.

### The conversation context (`kontekst`)

A comment may come with the conversation it belongs to: the posts before it in a thread.
The gateway keeps none of it on its own, so the conversation exists for the gateway only
when you send it with the comment: `elementy[].kontekst` on this route, `kontekst` on the
[synchronous one](#post-apiv1b2bocena).

```json
{ "id": "k-1031", "tekst": "dobra, dzwoń …", "profil": "forum_teen",
  "kontekst": [
    { "rola": "inny",  "tekst": "Ile masz lat? Napisz do mnie prywatnie." },
    { "rola": "autor", "tekst": "mam 14" },
    { "rola": "inny",  "tekst": "To podaj numer, zadzwonię." }
  ] }
```

| Field | Description |
|---|---|
| `kontekst` | Optional. A list of at most **8** entries, **oldest first**: the last one is the message directly before the comment. Absent, `null` or `[]` is no context, and the comment is assessed exactly as without the field. |
| `kontekst[].rola` | `autor`: written by the author of the assessed comment; `inny`: by anyone else. The roles name participants, never an age. |
| `kontekst[].tekst` | The entry's text, 1–**500** characters after surrounding whitespace is trimmed. |

**When the gateway asks.** Only when the comment comes with a context, **the comment
itself** carries personal data the gateway detects (a phone number, an address, a PESEL
number, a card or account number), **and** the verdict is not `zablokowane`. Then the
engine gets one more question: is handing out this datum risky in this conversation. The
datum asked about is the first address, else the first finding. The comment and its context
go through the personal-data replacement as one conversation, the same datum being the same
dummy wherever it occurs, and the context goes with this question only, never with the
moderation of the comment.

**The answer** is one more section of the verdict, and nothing else changes: `ocenzurowany`
stays exactly what the verdict made it.

```json
"dane_osobowe": { "rodzaj": "telefon", "reakcja": "ukryj" }
```

| Field | Values |
|---|---|
| `rodzaj` | the datum's kind: `adres`, `telefon`, `pesel`, `karta_platnicza`, `rachunek` |
| `reakcja` | `ukryj`, the engine recommends hiding the datum from your readers; `pozostaw`, it may stay; `nieocenione`, the question got no answer |

It is a recommendation: whether and how to hide the datum (mask it, hold the post, ask a
moderator) is your decision. The section carries no fragment and no position, only the
kind. Without a context, or without a datum, there is no section. When the question fails
(no answer in time, an engine error, an overload), the section says
`"reakcja": "nieocenione"` and the verdict comes as it is; the comment is not retried for
the question.

The context waits with its comment in the same encrypted queue entry, under the same TTL,
and is deleted with it; on the synchronous route it lives only as long as the request.

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
| `wsparcie` | `true` when the comment reads as self-harm (`samookaleczenie`), even when it was let through, and also when the engine rates it a crisis under a category the gateway cannot name yet (`kategorie` then lacks it): someone may be in danger, and the gateway cannot say how. This is your cue to show support, not to punish. |
| `wersja` | opaque, 16 hex characters. Equal values mean the same engine version, configuration and knowledge. It says nothing about the tuning. `null` when the engine did not report its version. |
| `dane_osobowe` | present **only** for a comment sent with a context that carries a detected personal datum and is not `zablokowane`: `{rodzaj, reakcja}`, see [the conversation context](#the-conversation-context-kontekst). |

A `nieocenione` payload is exactly `{"id": "k-1027", "status": "nieocenione"}`.

### Delivery, retries and `nieocenione`

- **A delivery** is a `POST` with `Content-Type: application/json; charset=utf-8` and
  `X-Wergiliusz-Podpis`. Any `2xx` counts as delivered, and the answer's body is
  discarded unread.
- **A failed delivery** (no connection, a DNS lookup that failed, a timeout, a non-`2xx`
  answer) is retried with a growing backoff of 5 s, 10 s, 20 s and so on, at most
  2 minutes, until the worker's margin before the entry's TTL (about 2.5 minutes at the
  defaults; the TTL is 15 minutes by default). Then it is given up, and the verdict with
  it: the gateway keeps no verdict anywhere.
- **`nieocenione`** is sent when the engine cannot assess the comment in time, or answers
  something the gateway does not recognise as a verdict; when the personal-data step cannot
  vouch for the comment, which then never reaches the engine; and for a queued comment
  whose profile the key no longer allows (its record was changed after the comment was
  accepted, or lists a profile off the B2B list). **The gateway never guesses a verdict.
  Whether to publish such a comment is the plugin's fail-open or fail-closed setting.**
- **Delivery is at least once.** A worker restart can repeat a delivery. **Use `id` to
  drop a repeat.**
- **An item may get no answer at all**, if the gateway's worker was down for the whole
  TTL. Treat an item without an answer after the TTL plus a few minutes as `nieocenione`.

### Where a delivery may go

Deliveries go only to the key's webhook, and only if all of these hold, checked before
every delivery:
- the URL is `https`, with a host **name** (never an IP literal), no credentials, and none
  of the characters that parsers read differently (`@`, a backslash, whitespace, control
  characters). The delivery goes to the URL rebuilt from the checked scheme, host, port,
  path and query, never to the stored string;
- the host is on the key's list, checked before any DNS query;
- every address the host resolves to (A and AAAA) is public: no private, loopback,
  link-local (cloud metadata included), carrier-grade NAT, multicast, reserved,
  documentation or IPv4-in-IPv6 address. One such address among public ones refuses the
  delivery.

The connection then goes to exactly the checked addresses, so a DNS answer that changes
between the check and the connection cannot redirect it, and TLS still verifies the
certificate against the host name. Redirects are not followed and no proxy is used. A
refused target is not retried: the entry is deleted. Plan the plugin's receiving endpoint
accordingly: a public HTTPS URL on the forum's own domain.

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
   - `wsparcie` → show support, whatever the verdict;
   - `dane_osobowe` (only for a comment sent with a context) → a recommendation about the
     datum; what to do with it is the plugin's decision.
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
| `profil` | no | A profile on the key's list and on the B2B list ([the key](#the-key)). Without it, the key's first profile is used. |
| `meta` | no | The same five spam signals as in a batch item. Anything else is dropped silently. **Never send an e-mail, IP address or author id.** |
| `kontekst` | no | The conversation before the comment, as in a batch item: at most 8 entries `{rola, tekst}`, oldest first, 500 characters each. See [the conversation context](#the-conversation-context-kontekst). |

One request is one comment: there is no `id`, no `elementy` and no batch.

### Response `200`

The [webhook payload](#payload) **without `id`**; its fields mean the same, `dane_osobowe`
included:

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

When the engine is unavailable, does not answer in time, answers an error, or answers
something the gateway does not recognise as a verdict, when the personal-data step cannot
vouch for the comment (it is then not sent to the engine), or when the gateway has no way
to its engine configured, the answer is `200` with exactly `{"status": "nieocenione"}`.
**The gateway never guesses a verdict.** Apply the plugin's fail-open or fail-closed
setting, as for a `nieocenione` webhook, and do the same when your own request to the
gateway times out: without an answer there is no verdict. At its defaults the gateway
answers within 20 seconds (26 when it runs two engines) for a comment without a context,
and within 30 seconds (42 with two engines) for one it asks the context question about; in
the usual case within 10 s, plus 5 s for the question. Set your request's timeout above
that. Asking again is a new request under the key's limits.

The B2B limits count per key, plus the shared ceiling, and never per address, so this
route may be called from behind a server-side relay that forwards each forum's own key.
The key's in-flight slot is held for the whole engine call. Nothing is kept: the comment,
its context and its verdict live only as long as the request.

### Errors

The same shape, `{"blad": {"kod", "komunikat", "ponow_za_s"?}}`, with no `element` and no
`Retry-After` header.

| HTTP | `kod` | When |
|---|---|---|
| 401 | `brak_klucza` | no key, or an unknown one |
| 403 | `nie_ta_powierzchnia` | the key belongs to another surface |
| 403 | `tylko_klucze_platne` | a free key (`b2b_free`): this route is for paid keys only |
| 403 | `profil_niedozwolony` | `profil` is not on the key's list, or is off the B2B list whatever the key's list says |
| 400 | `bledne_wejscie` | the body is not a JSON object, or `tekst` is there but is not a string (`null` included) |
| 400 | `brak_tekstu` | no `tekst`, or only whitespace |
| 413 | `limit_dlugosci` | `tekst` is longer than 3000 characters |
| 400 | `bledny_kontekst` | `kontekst` is not a list, an entry is not an object, `rola` is neither `autor` nor `inny`, or `tekst` is empty or not a string |
| 413 | `za_duzy_kontekst` | `kontekst` has more than 8 entries, or an entry is longer than 500 characters |
| 413 | `za_duze_zadanie` | the body is over its ceiling, before it is parsed: 101,920 bytes, so the longest comment and a full context fit however they are escaped: 3000 × 12 + 1024 + 8 × (500 × 12 + 64) + 16,384 (3000 characters as escaped surrogate pairs, the default of `json_encode`, are 36,012 bytes) |
| 429 | `limit_minutowy_klucza` / `limit_dobowy_klucza` / `limit_w_locie_klucza` / `limit_globalny_b2b` | the key's limits, or the shared B2B ceiling (`ponow_za_s`) |
| 503 | `silnik_przeciazony` | the engine cannot take the comment right now, and nothing was assessed (`ponow_za_s`, in the body only) |
| 404 | `nie_znaleziono` | the route is closed (see [Status](#status)), or B2B is off on this gateway: any key gets it, whatever its class and the body, and nothing is assessed |

A missing or unknown key gets `401 brak_klucza` before any other check. Then the checks
run in this order:
1. the route's switch: closed, the route answers `404 nie_znaleziono`;
2. the key's surface and class;
3. the body's size;
4. the JSON;
5. the text;
6. the profile;
7. the context;
8. the key's own limits and the global ceiling.

**What a client does with a refusal:**
- On `429` and **every** `503`, whatever its `kod`, the comment has no verdict yet: apply
  the fail-open or fail-closed setting now, or retry after `ponow_za_s` (after a backoff
  when that field is missing). Tell a `503` by its status, never by its code.
- On `4xx` other than `429`, the request is wrong and retrying will not help. Treat it
  as a configuration error and show it to the forum's administrator.
