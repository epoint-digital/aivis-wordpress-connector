# AIVIS WordPress Connector — documentation index

Everything that exists for the connector, in one place. Repository:
**https://github.com/epoint-digital/aivis-wordpress-connector** (private until Q-08 is decided).
Plugin slug `aivis-os`. Production API base `https://app.aivis-os.com`.

## Start here

| Read | For |
|---|---|
| [`docs/REVIEWER-BRIEF.md`](REVIEWER-BRIEF.md) | Independent review: context, decisions, verified facts, code map, known gaps, review checklist — all with absolute links |
| [`README.md`](../README.md) | What the plugin is, in one page |
| [`docs/SPECIFICATION.md`](SPECIFICATION.md) | The full behaviour: invariants, API contract, retraction rules, URL handling, languages, zero-trust ingestion, delivery, cache, admin, status for AIVIS, security, deployment, acceptance criteria, release decisions Q-01…Q-10 |
| [`docs/INSTALL.md`](INSTALL.md) | Operator guide: token, `wp-config.php`, install, bind, languages, first sync, verify, cron, caches, status for AIVIS, other SEO plugins, troubleshooting |

## Documents in this repository

| File | What it is | Audience |
|---|---|---|
| [`docs/SPECIFICATION.md`](SPECIFICATION.md) | Specification, adapted rev 2 — source of truth, versioned with the code | Engineers, reviewers |
| [`docs/API-REQUIREMENTS.md`](API-REQUIREMENTS.md) | API-1 … API-11: what the connector needs from the AIVIS Public API, with proposed contracts and interim behaviour | AIVIS platform team |
| [`docs/api-requirements.html`](api-requirements.html) | The same requirements as a rendered page with embedded JSON-LD and a machine-readable JSON block (checked in CI) | AIVIS platform team, tooling |
| [`docs/INSTALL.md`](INSTALL.md) | Installation and operation | Site operators |
| [`docs/RUNBOOK.md`](RUNBOOK.md) | Drills: API outage, revoked token, malformed artifact, wrong business, dead cron, failed purge, retraction, rollback, language without a chain, status-key leak, disconnect | Site operators, support |
| [`docs/KNOWN-ISSUES.md`](KNOWN-ISSUES.md) | Known issues, including the Node 24+ JSON round-trip engine bug (#19) | Engineers |
| [`docs/admin-ui.html`](admin-ui.html) | Interactive prototype of the Settings and Status screens | Product, reviewers |
| [`CHANGELOG.md`](../CHANGELOG.md) | Keep-a-changelog; 1.0.0 unreleased | Everyone |
| [`readme.txt`](../readme.txt) | WordPress plugin readme (description, install, FAQ) | Site operators |
| [`SECURITY.md`](../SECURITY.md), [`CONTRIBUTING.md`](../CONTRIBUTING.md), [`CODE_OF_CONDUCT.md`](../CODE_OF_CONDUCT.md) | Project hygiene | Contributors |
| [`tests/README.md`](../tests/README.md) | How the suites fit together and how to run them | Engineers |
| [`.claude/skills/install-aivis-os/`](../.claude/skills/install-aivis-os/SKILL.md) · [`.codex/skills/install-aivis-os/`](../.codex/skills/install-aivis-os/SKILL.md) | Agent skill (Claude Code and Codex, identical): install, connect and verify the plugin headless via WP-CLI or by driving wp-admin in a browser; `reference/wp-admin-browser-guide.md`, `reference/wp-cli-install.md`, `reference/checklist.md` | Agents, operators |
| [`AGENTS.md`](../AGENTS.md) | Repository rules for any coding agent | Agents |

## Rendered pages and boards

Published from this repository's files. Artifacts are private by default; share from the page's menu.

| Page | URL |
|---|---|
| API requirements (rendered `docs/api-requirements.html`) | https://claude.ai/code/artifact/cfbb429c-6415-497d-8d55-669fd0f8b5ef |
| Install guide (rendered from `docs/INSTALL.md`) | https://claude.ai/code/artifact/9c021f5b-6619-4c30-9e3e-5f644cea7c53 |
| Admin UI prototype (`docs/admin-ui.html`) | https://claude.ai/code/artifact/26186cd5-b590-4c4d-86a3-a1df1aa8d8c4 |
| FigJam — AIVIS Connector program map (sync loop, retraction tree, WP-behind-Cloudflare composition, API dependency map) | https://www.figma.com/board/zQo3mgU7G8eRrVqsBa2LnP |

## Source map

```
aivis-os.php                 bootstrap, plugin headers (incl. Update URI), PHP guard
uninstall.php                removal honouring the retention setting; token always removed
src/Plugin.php               lazy container, hook registration, activation/deactivation
src/Api/                     Client (GET-only, hardened transport), Response (status classification)
src/Security/                Envelope (ZT-02/04), Serializer (ZT-05), Binding (ZT-03)
src/Storage/                 Schema (dbDelta, v2), Options (all non-autoloaded), Repository
src/Sync/                    Synchronizer, Decision (R-01…R-02a), ChainAssignment (§07a), Lock,
                             Scheduler, Gc, Verifier (loopback + conflict scan), Notifier (email)
src/Delivery/                Injector (wp_head 100), Gates, UrlResolver, Language (§07a), Conflicts (§09a)
src/Rest/                    StatusController + StatusDocument — the status AIVIS fetches (§11a)
src/Cache/                   CacheAdapter, PurgeResult, adapters (WP Super Cache, W3TC, LiteSpeed, Manual)
src/Admin/                   Menu, SettingsPage, StatusPage, Notices, SiteHealth, AdminBar
src/Cli/Commands.php         wp aivis connection|sync|status|verify|refresh|languages|status-key
src/Update/GitHubReleases.php  Update URI answers from GitHub Releases
src/Domain/                  UrlKey (local normalization), ErrorCode, Action
src/reference/connector-logic.mjs  JS reference of the decision rules, held to the PHP by tests
```

## Tests and tooling

| Piece | Where | Run |
|---|---|---|
| PHPUnit (WP function stubs, no WordPress needed) | `tests/php/` | `./scripts/phpunit.sh` (Docker) |
| JS unit + serializer fuzz (with the Node 24+ canary) | `tests/unit/` | `node tests/unit/run.mjs`, `node tests/unit/serializer.mjs` |
| Contract suite against the mock, or `--live` against an instance | `tests/contract/run.mjs` | `node tests/contract/run.mjs [--live]` |
| Mock AIVIS Public API, built from the vendored OpenAPI | `tests/mock/server.js`, `tests/mock/data.js` | `node tests/mock/server.js` |
| Vendored contract + refresh script | `tests/fixtures/openapi-v1.json`, `refresh.mjs` | `node tests/fixtures/refresh.mjs` |
| Docs machine-readability check | `tests/docs/check-embedded-json.mjs` | `node tests/docs/check-embedded-json.mjs` |
| Engine-bug reproducer (#19) | `tests/known-issues/node26-json-roundtrip.mjs` | — |
| Lint, version check, reproducible ZIP | `scripts/lint.sh`, `scripts/version-check.mjs`, `scripts/build-zip.sh` | — |
| CI: Node 20/22/24 × PHP 8.1/8.3/8.5, release, nightly API drift | `.github/workflows/ci.yml`, `release.yml`, `api-drift.yml` | — |

## Issues and milestones

Everything built is tied to an issue. Milestones M1 Skeleton → M6 Pilot & 1.0.0.

| Set | Issues |
|---|---|
| **For the AIVIS team** — transferred to `epoint-digital/aivis` on 2026-09-07, assigned to Marius Diacu | Parent [aivis#264](https://github.com/epoint-digital/aivis/issues/264) with native sub-issues: [aivis#265](https://github.com/epoint-digital/aivis/issues/265) API-1 tokens · [aivis#266](https://github.com/epoint-digital/aivis/issues/266) API-2 retraction · [aivis#267](https://github.com/epoint-digital/aivis/issues/267) API-3 error code · [aivis#268](https://github.com/epoint-digital/aivis/issues/268) API-4 ETag · [aivis#269](https://github.com/epoint-digital/aivis/issues/269) API-5 per-URL inventory · [aivis#270](https://github.com/epoint-digital/aivis/issues/270) API-6 change feed · [aivis#271](https://github.com/epoint-digital/aivis/issues/271) API-7 rate limits · [aivis#272](https://github.com/epoint-digital/aivis/issues/272) API-8 normalization (filed) · [aivis#273](https://github.com/epoint-digital/aivis/issues/273) API-9 status fetch · [aivis#274](https://github.com/epoint-digital/aivis/issues/274) API-10 chain language · [aivis#275](https://github.com/epoint-digital/aivis/issues/275) API-11 versioning |
| Internal API tracker | [#20](https://github.com/epoint-digital/aivis-wordpress-connector/issues/20) |
| Open, connector side | [#18](https://github.com/epoint-digital/aivis-wordpress-connector/issues/18) pilot and tag 1.0.0 · [#19](https://github.com/epoint-digital/aivis-wordpress-connector/issues/19) Node 24+ engine bug · [#31](https://github.com/epoint-digital/aivis-wordpress-connector/issues/31) inventory in wp_options · [#32](https://github.com/epoint-digital/aivis-wordpress-connector/issues/32) N+1 queries · [#34](https://github.com/epoint-digital/aivis-wordpress-connector/issues/34) updates blocked while private · [#35](https://github.com/epoint-digital/aivis-wordpress-connector/issues/35) webhook receiver v1.1 · [#40](https://github.com/epoint-digital/aivis-wordpress-connector/issues/40) API-10 (connector view) · [#54](https://github.com/epoint-digital/aivis-wordpress-connector/issues/54) version/min-client headers |
| Built (closed) | #1–#17 build, #21–#30 audit fixes, #33, #36–#39 primary source and languages, #41 status pull model — [full list](https://github.com/epoint-digital/aivis-wordpress-connector/issues?q=is%3Aissue) |

## External references

| What | Where |
|---|---|
| AIVIS platform repository | https://github.com/epoint-digital/aivis — Public API v1 merged as #142 (`e96b89c`, 2026-08-11); `lib/public-api/{auth,lookup,openapi,schemas}.ts`, `app/api/public/v1/*` |
| Live OpenAPI (dev) | https://aivis-new.dev.onepoint.ro/api/public/v1/openapi.json — docs at `/api/public/v1/docs` |
| Production API base | https://app.aivis-os.com (`AIVIS_API_BASE_URL` overrides for dev/staging) |
| Platforms | WordPress 6.5–7.1, PHP 8.1–8.5; multisite network activation refused in 1.0 |

## Decisions

Recorded in the [specification appendix](SPECIFICATION.md#appendix--release-decisions): Q-01 production URL, Q-02 token scope, Q-03 cache adapters, Q-04 freshness, Q-05 platforms, Q-06 multisite, Q-07 distribution, Q-08 repo visibility, Q-09 one chain per language, Q-10 status is pulled, never pushed.
