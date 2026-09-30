# Project: Minos client for PHP

The public (MIT) PHP client of the Wergiliusz gateway's B2B API: `Minos\Client` (`src/`),
bundled into the Minos forum plugins on customers' hosts, the contract's client-side copy
(`docs/contract.md`) and a mock gateway (`mock-gateway/`; open `mock-gateway/CLAUDE.md`
FIRST when you change it). Nothing here names private code, hosts or secrets.

## The library

- **PHP 7.4+, no dependencies beyond `ext-json`.** No PHP 8 syntax: no `match`,
  `readonly`, `mixed`, union types, promoted constructors, named arguments, `?->`,
  `str_contains` or `str_starts_with`. CI runs every test on 7.4 and 8.3.
- **Fail closed on what it does not know.** `Signature::verify` accepts only a
  well-formed, fresh, matching header. `WebhookPayload::parse` returns null for an unusable
  body and reads an unknown `status` or `kwalifikacja` as `nieocenione`: an unknown value is
  never a verdict. Unknown categories are kept, because the gateway's list grows.
- **Compare secrets with `hash_equals`**, never `===`.
- **Its public methods are the API of every PHP plugin**: keep them backward compatible,
  or version the change.

## The contract

- `docs/contract.md` follows the gateway's documentation. A change of the contract changes
  the copy, the code and the tests in ONE commit.
- **Vectors (`vectors/`) are the gateway's, byte for byte.** Never regenerate one here.
- **Proof is on the gateway's artefacts**: test through the public methods on `vectors/`,
  never only on values the code under test computed; a delivery change end to end over
  HTTP (`EndToEndTest`). `ContractDocumentTest` executes the copy; keep its
  `<!-- …:start/end -->` markers.
- **Wire strings are Polish and never renamed**: JSON keys, error codes, headers, enum values.

## Sessions and working rules

- **Context budget**: after closing a task, `/compact` or start fresh; stay under ~250k
  tokens; heavy reading goes to a subagent and only conclusions come back.
- **The cheapest model that fits**, through `.claude/agents/`, which pin it.
- **One gate to `main`**: sessions open PRs; only the owner or the coordinating session
  merges, after one review (`przeglad-bezpieczenstwa` for `src/`, the contract and delivery).
- **Tool hygiene**: no `gh … --jq` with complex expressions, loops or several `git`
  commands in one shell call; never a foreground `sleep`.
- **Code, comments, docs and commits in English**; an assertion asks about a property,
  not about the writing; never `git add -A`.
- **This file stays small** (`tests/Repo/ClaudeRulesTest.php` pins its size).
