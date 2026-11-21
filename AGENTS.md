# Agent instructions for this repository

This is JSON-LD Delivery for Aivis-OS, the WordPress plugin (slug `aivis-os`). Read `docs/INDEX.md` first; the specification is `docs/SPECIFICATION.md` and it is the source of truth.

## Skills

- `install-aivis-os` — install, connect and verify the plugin on a WordPress site, headless (WP-CLI) or through wp-admin in a browser. Identical copies live in `.claude/skills/` (Claude Code) and `.codex/skills/` (Codex); `npm run check:skills` fails if they drift. Codex: `/skills` or `$install-aivis-os`.

## Rules that apply to any agent working here

- Everything is tied to a GitHub issue; commits say `Closes #N`.
- Never put an API token, a status key or anything bearer-shaped into a commit, a test fixture, a log or a chat message. Tests use synthetic strings only.
- The connector never pushes to Aivis-OS and never alters another plugin. Do not add code that does either.
- The plugin is a delivery method (WP-I12). Facts the site alone knows and actions the site alone can perform belong here; judgments, histories and notification policy belong in Aivis-OS. Do not add decision logic; if the API lacks a signal, file the gap under the API requirements instead.
- **Name and addresses.** The word mark is "Aivis-OS": hyphenated, not in capitals. The plugin's name is "JSON-LD Delivery for Aivis-OS", with the mark at the end: the wordpress.org directory accepts a name that begins with a trademark only from the trademark's owner. Technical names stay as they are (`aivis-os`, `aivis_os_*`, `wp aivis …`, `data-aivis`, `AIVIS_*` constants and codes, the `aivis_` token prefix). The two instance addresses live in code (`Options::ENVIRONMENTS`) and are used there; they are never spelled out in the interface, in CLI output, in descriptions or in documentation, where the instances are called Production and Test (spec §11).
- Anything published about Aivis-OS (directory page, release notes, announcements) is agreed with Aivis-OS first, through the maintainer.
- PHP runs in Docker here: `./scripts/lint.sh`, `./scripts/phpunit.sh`. JS: `node tests/unit/run.mjs`, `node tests/contract/run.mjs`, `node tests/docs/check-embedded-json.mjs`.
- The vendored `tests/fixtures/openapi-v1.json` is the API contract; do not edit it by hand (`node tests/fixtures/refresh.mjs`).
