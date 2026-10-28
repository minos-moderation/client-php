# Changelog

What changes in `minos-moderation/client-php`, release by release. The version policy is at
the end of this file.

## 0.2.0

The contract's copy brought up to the gateway's documentation of 2026-10-28, the mock with
it, and what the library reads of the new verdict. Tagged after 0.1.0; a plugin moves to
`^0.2` in a change of its own (see the version policy).

### The contract

- `docs/contract.md` follows the gateway's documentation as of 2026-10-28 (0.1.0: as of
  2026-10-01). New in it: the optional conversation context of a comment (`kontekst`, at
  most 8 entries `{rola: autor|inny, tekst}` of 500 characters) with its refusals
  `400 bledny_kontekst` and `413 za_duzy_kontekst`; the `dane_osobowe` section of a
  verdict (`{rodzaj, reakcja}`) for a comment sent with a context; the body ceilings that
  fit it (1,727,104 bytes for a batch, 101,920 for the synchronous route); `wsparcie` also
  for a crisis under a category the gateway cannot name; the B2B list of profiles
  (`forum_adult`, `forum_teen`, `blog_comments`, `ig_comments`, `api_generic`) and
  `403 profil_niedozwolony` for any other, even one the key lists; personal data replaced
  before the engine reads a comment, and `nieocenione` when that step cannot vouch for a
  text or the key no longer allows a queued comment's profile; the queue of 350 comments
  assessed about 30 a minute; the synchronous route's own switch (closed in production)
  and its answer times with a context (30 s, 42 s with two engines); a failed DNS lookup as
  a failed, retried delivery, and the webhook address checks (`@`, a backslash, whitespace
  and control characters refused, the delivery going to the rebuilt URL); the TTL, 15
  minutes by default and at most 30.
- A plugin built on the 0.1.0 copy keeps working: it sends no context, so it meets neither
  the new refusals nor the section, and every new `nieocenione` goes to its fail-open or
  fail-closed setting. The copy's status says why, item by item.

### The library, `Minos\Client`

- `WebhookPayload::parse` returns one more key, `dane_osobowe`: `[]` without the section,
  else `['rodzaj' => ?string, 'reakcja' => string]`. A `reakcja` it does not know reads as
  `nieocenione`, and a kind it does not know is kept. The lists are the new constants
  `WebhookPayload::PERSONAL_DATA_KINDS` and `PERSONAL_DATA_REACTIONS`. A plugin that reads
  the other keys is unaffected.
- `WebhookPayload::parse` fails closed on two more shapes the contract never sends: an
  `ocenzurowany` that is empty or masks nothing (no `█`) reads as absent, so the plugin
  holds the comment instead of publishing it, and `kategorie` is read only from a JSON
  list (an object names no category). An unknown category label is still kept.

- `Signature::check($secret, $header, $body, $now, $toleranceS = 300)`, new and additive:
  the answer of `verify` with its reason, `Signature::OK`, `MALFORMED`, `STALE` or
  `MISMATCH`, for the administrator's log (never for the HTTP answer). `check(...) === OK`
  exactly when `verify(...)` is true; `verify` stays the contract's reference verifier,
  unchanged, and neither guards an empty secret (the caller does).
- The README names the public API ("Versioning") and what the library does at the edges;
  `tests/Repo/PublicApiTest.php` holds the API to a declared list.

### The mock gateway

- The new codes, ceilings and checks of the contract: the context and its refusals, the B2B
  list of profiles, a queue of 350 by default, a 10 s delivery timeout. A batch is checked
  as the contract orders it: every item's shape, id, text and length, then every item's
  profile, then every item's context; a JSON object is no longer taken for a list, nor a
  list for an object.
- The synchronous route is closed by default, as in production: `404 nie_znaleziono` to
  the key. `MINOS_MOCK_SYNC=1` opens it.
- New markers: `[minos:wsparcie]` (support without a category) and
  `[minos:dane-osobowe=<rodzaj>[:<reakcja>]]` (the section, for a comment sent with a
  context). A `[minos:kategoria]` without a label is ignored instead of breaking the route
  and the worker.
- One broken queue entry no longer stops the worker: it is reported `blad` and dropped. A
  queued comment whose profile the key no longer allows is delivered as `nieocenione`.
- The worker signs every attempt when it is made: a long pass over slow receivers no longer
  sends signatures already stale. Its category list is the library's
  (`WebhookPayload::CATEGORIES`), no longer a copy.
- Mock-only failures to test against: `X-Minos-Mock-Error: 503-bez-kodu` and
  `503-nie-json` on both routes, and the `[minos:nieczytelne]` delivery, signed but no
  payload.

### The package

- `docs/plugin-behaviour.md`, new: the rules every Minos plugin shares, from the plugins'
  security reviews of 2026-09-30 and the owner's decisions of 2026-10-28, each with a stable
  identifier (`F1`…`D2`) that the plugins' compliance tables map to their code and tests.
  It is their one public source; a change to one rule is a decision for every plugin.
  Three rules were made precise before the tag, after the plugins' reviews: F7 (a bare
  address the reader sees is text; a post whose only visible content is an image, a video,
  an attachment or a link with no visible label goes to the failure mode, and nothing is
  sent), A8 (the 30 days cover the plugin's own data; what the platform keeps in its own
  records follows the forum's rules, and each plugin's README names what outlives the 30
  days) and S1 (`title` and `alt` hints are sent next to their word; an `href`, an HTML
  comment or an attribute the sanitiser removes never is).
- The release archive leaves out `tests/`, `.claude/`, `.github/`, the `CLAUDE.md` files and
  `phpunit.xml.dist`; it keeps `src/`, `docs/`, `vectors/` and `mock-gateway/`, which the
  plugins use from `vendor/`.

## 0.1.0

The first tagged release: what the Minos PHP plugins bundle and pin with `^0.1`. PHP 7.4 or
later, no dependency beyond `ext-json`; CI runs the suite on PHP 7.4 and 8.3.

### The library, `Minos\Client`

- `Signature::verify($secret, $header, $body, $now, $toleranceS = 300)`: whether a
  delivery carries a valid `X-Wergiliusz-Podpis`. It accepts only a well-formed header, a
  timestamp within five minutes of the receiver's clock and a matching HMAC-SHA256 of
  `"<t>.<body>"`, compared in constant time. It is the contract's reference verifier,
  unchanged, and is tested on the gateway's own vector.
- `Signature::sign($secret, $body, $timestamp)`: the header value the gateway would send.
  The mock and the tests use it; a plugin never needs it.
- `WebhookPayload::parse($body)`: a verified body read into one shape (`id`, `status`,
  `kwalifikacja`, `kategorie`, `ocenzurowany`, `wsparcie`, `wersja`), or `null` for a body
  that is not a payload. It fails closed: an unknown `status` or `kwalifikacja` reads as
  `nieocenione`, never as a verdict. An unknown category is kept, because the gateway's
  list grows, and a key it does not know is ignored.
- The wire values as constants: `Signature::HEADER`, `Signature::DEFAULT_TOLERANCE_S`,
  `WebhookPayload::ASSESSED`, `UNASSESSED`, `QUALIFICATIONS` and `CATEGORIES`.

### Also in the package

- `docs/contract.md`, the contract from the client's side: the requests, the errors, the
  webhook, the signature and a plugin's receiving checklist. Its status at this release:
  the batch route, `POST /api/v1/b2b/oceny`, has been open in production since 2026-10-25
  and is the route the plugins use; the synchronous route, `POST /api/v1/b2b/ocena`, stays
  closed in production (`404 nie_znaleziono`) until a decision of its own. The sections
  describe both routes as the gateway documented them on 2026-10-01.
- `vectors/signature.json`, the gateway's signature vector, byte for byte.
- `mock-gateway/`, a local stand-in for both routes and for the delivery worker, to develop
  plugins against. It never assesses content: the verdict comes from markers in the
  comment.

### Known differences from the gateway

**The empty-secret guard is the caller's.** `Signature::verify`, like the reference
verifier it copies, takes any secret, an empty one included, and anyone can sign with an
empty secret. A receiver refuses a delivery when no secret is stored, before it verifies;
the README's example shows how.

**The contract copy, and the mock that follows it, describe the gateway as of 2026-10-01.**
Not yet in this copy (planned for 0.2.0):

- the optional conversation context of a comment (`kontekst`), its refusals
  `400 bledny_kontekst` and `413 za_duzy_kontekst`, and the higher body ceilings that fit
  it: 1,727,104 bytes for a batch and 101,920 for the synchronous route;
- the personal-data note in a verdict (`dane_osobowe`), for a comment sent with that
  context; `parse` ignores it;
- personal data replaced before the engine reads a comment, and a new cause of
  `nieocenione` when that step cannot vouch for a text;
- `wsparcie` also for a crisis the gateway cannot name a category for;
- the fixed list of profiles a B2B key may use (the forum profiles are on it):
  `403 profil_niedozwolony` for any other, and `nieocenione` for a queued comment whose
  profile is no longer allowed;
- the queue: 350 comments and about 30 assessments a minute (the mock's queue holds 500
  by default, and the mock does not pace its verdicts);
- the synchronous route's own switch: the mock serves the route, the gateway keeps it
  closed.

A plugin built on this copy keeps working: it sends no context, a key it does not know is
ignored, and every new `nieocenione` goes to its fail-open or fail-closed setting. The
contract's status says why, item by item.

The mock also differs by design, as the README lists: it keeps its queue as plain JSON on
disk, delivers to any URL, does not simulate the rate limits and answers the synchronous
route at once.

## Version policy

Until 1.0.0, the minor number carries the changes a plugin may have to follow: a new or
changed public method, or a contract copy brought up to the gateway's documentation (0.2.0,
0.3.0, …). A patch (0.1.1, …) only fixes or narrows: a change that makes the library
refuse more, failing closed, is a patch, because a plugin that follows the contract never
depends on what was accepted before. From 0.2.0 the PHP plugins require `^0.2`, that is at
least 0.2.0 and below 0.3.0: they take every patch, and a minor version only in a change of
their own.
