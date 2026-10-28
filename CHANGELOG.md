# Changelog

What changes in `minos-moderation/client-php`, release by release. The version policy is at
the end of this file.

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
depends on what was accepted before. The PHP plugins require `^0.1`, that is at least 0.1.0
and below 0.2.0: they take every patch, and a minor version only in a change of their own.
