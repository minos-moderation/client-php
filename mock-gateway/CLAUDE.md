# The mock gateway

A local stand-in for the gateway's B2B route and its delivery worker, for developing
plugins without the real gateway. Its manual is the README's "The mock gateway"; keep the
two in step.

- **It mirrors, it does not invent.** `Intake` checks in the gateway's order and answers
  with the gateway's codes and statuses (`docs/contract.md` → Errors). A new check or
  code comes from the contract first.
- **It never assesses content.** Verdicts come only from `[minos:…]` markers (`Verdicts`),
  each one listed in the README's marker table.
- **The mock-only surface stays mock-only**: `X-Minos-Mock-Error` and `MINOS_MOCK_*` exist
  here alone, and nothing in `src/` depends on them.
- **Its differences from the gateway are listed in the README** (plain JSON on disk, any
  webhook URL, no rate limits); a new difference goes on that list.
- **Tests start real processes** (`EndToEndTest`): pass `proc_open` an array, never a
  string (a shell survives `proc_terminate`), and assert every port is closed afterwards.
