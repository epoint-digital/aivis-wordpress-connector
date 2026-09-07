# Agent instructions for this repository

This is the AIVIS WordPress Connector (plugin slug `aivis-os`). Read `docs/INDEX.md` first; the specification is `docs/SPECIFICATION.md` and it is the source of truth.

## Skills

- `install-aivis-os` — install, connect and verify the plugin on a WordPress site, headless (WP-CLI) or through wp-admin in a browser. Identical copies live in `.claude/skills/` (Claude Code) and `.codex/skills/` (Codex); `npm run check:skills` fails if they drift. Codex: `/skills` or `$install-aivis-os`.

## Rules that apply to any agent working here

- Everything is tied to a GitHub issue; commits say `Closes #N`.
- Never put an API token, a status key or anything bearer-shaped into a commit, a test fixture, a log or a chat message. Tests use synthetic strings only.
- The connector never pushes to AIVIS and never alters another plugin. Do not add code that does either.
- The plugin is a delivery method (WP-I12). Facts the site alone knows and actions the site alone can perform belong here; judgments, histories and notification policy belong in AIVIS. Do not add decision logic; if the API lacks a signal, file the gap under the API requirements instead.
- PHP runs in Docker here: `./scripts/lint.sh`, `./scripts/phpunit.sh`. JS: `node tests/unit/run.mjs`, `node tests/contract/run.mjs`, `node tests/docs/check-embedded-json.mjs`.
- The vendored `tests/fixtures/openapi-v1.json` is the API contract; do not edit it by hand (`node tests/fixtures/refresh.mjs`).
