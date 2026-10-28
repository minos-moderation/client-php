---
name: programista-prosty
description: FIXTURE for ClaudeRulesTest - a Sonnet writer whose "You may change" list names a risk path (docs/contract.md, as an exception written there instead of on the risk list). The test must reject it.
model: sonnet
effort: medium
tools: Read, Grep, Glob, Bash, Edit, Write
---
You implement one simple change. You may change:
- `README.md` and `docs/` other than `docs/contract.md`;
- test fixtures and test data.

Risk list — STOP before editing:
- `src/`, `docs/contract.md`, `docs/plugin-behaviour.md`, `vectors/`;
- `mock-gateway/src/`, `.github/`, `.claude/`, every `CLAUDE.md`, `composer.json`,
  `phpunit.xml.dist`.
