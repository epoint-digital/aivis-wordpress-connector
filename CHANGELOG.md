# Changelog

All notable changes to this project are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/). The plugin header in `aivis-os.php`
is the single source of truth for the version — CI fails a release if this
file, `readme.txt` and the git tag do not agree with it.

## [1.0.0] - Unreleased

### Added
- Background sync of AIVIS-generated JSON-LD into a local table; injection at
  `wp_head` priority 100 with exactly one `data-aivis="1"` script element.
- Zero-trust ingestion: envelope validation, depth and size limits, safe
  re-serialization, and binding to the selected business and this site's host.
- Domain-matched business binding — a business has exactly one domain; the
  plugin auto-binds when one matches, forces a choice when two do, refuses when
  none does.
- Retraction rules that distinguish "URL not found" from "not generated yet":
  suspend on the first, hold last-known-good on the second, retire only after
  two authoritative absences.
- Honest cache status: purges are reported as confirmed, requested or manual;
  a page is never called live without confirmation.
- Settings and Status screens, admin notices, Site Health tests, WP-CLI
  (`connection test`, `sync`, `status`, `verify`, `refresh`).
- Updates served from GitHub Releases via the `Update URI` header; wordpress.org
  is never consulted for this plugin.
- Reproducible release ZIP with `SHA-256SUMS`.
- AIVIS as the primary source of structured data: other JSON-LD emitters are
  detected by a loopback scan and flagged red with guidance; the connector never
  alters another plugin. Email to the site admin on change.
- Multilingual sites — one AIVIS chain per language (§07a): language detection
  for WPML, Polylang, TranslatePress, Weglot and core; chain → language
  assignment under Settings (automatic when unambiguous); only assigned chains
  sync; inventory targets fetched by `urlId` so the chain is pinned; artifacts
  from a chain not assigned to the page's language are rejected; language
  subdomains allowed; Status, Site Health and `wp aivis languages`.
- `wp aivis bind [--business=<id>]`: headless business binding on the same
  domain rule as Settings, followed by automatic chain → language assignment.
- Agent skill `install-aivis-os` for Claude Code and Codex (identical copies,
  checked in CI): headless WP-CLI install, a wp-admin browser guide with every
  field name, and a report checklist. `AGENTS.md` for repository rules.
- Environment switch under Settings → Connection: Production
  (`app.aivis-os.com`) or Test (`aivis-new.dev.onepoint.ro`), a closed
  two-value choice, never a free URL. Switching unbinds the business, stops
  serving everything from the other instance and purges. Shown on Status and
  in the status document; Site Health flags Test; `wp aivis environment`.
- Pages screen: WordPress list table with pagination (Screen Options), sorting,
  URL search, state/language/chain filters, views (Needs attention first when
  non-empty), row actions and bulk refresh/disable/restore. Status is an
  overview only — counts, a Needs-attention list, no page list. `wp aivis pages`.
- Delivery-only principle (WP-I12, Q-11): the plugin holds facts and performs
  delivery; judgments, histories and policies are AIVIS's. Taken from Norbert's
  `aivis-os-jsonld` (#65/#66): WP Rocket purge adapter and Cloudflare detection;
  `data-aivis-hash` on the script element so anyone can verify delivery from the
  public page; object references (post / term / archive) stored per row as data
  (schema v3); a daily **moved pages** report on Status, Site Health and in the
  status document; a read-only AIVIS OS box on post and term edit screens.
- Status for AIVIS, fetched never pushed (§11a): `published_at`, `verified_at`
  and `verified_hash` per page; read-only REST endpoint
  `/wp-json/aivis-os/v1/status` (+ `/status/urls`, paged) gated by a
  site-issued status key (Settings → Status for AIVIS, `wp aivis status-key`);
  `wp aivis status --format=json` prints the same document. The connector
  issues no request to AIVIS other than GET.

- AIVIS Public API contract 1.9.0 (#20, #54): classification by `error.code`
  with the 1.0.0 messages as fallback; `410 withdrawn` deactivates a page at
  once and `suppressedAt` on inventory rows does the same (R-01b, R-02a);
  `X-Aivis-Api-Version` / `X-Aivis-Min-Client` remembered from every response,
  `426 client_too_old` stops syncing and is shown on Status, in Site Health
  ("AIVIS OS: API contract") and as a notice, `/changelog` read on every
  connection test for the announced minimum and rate limits; business-bound
  tokens shown as such and the customer-managed install restriction lifted
  for them; chain `languageCode` drives automatic assignment (row sampling
  removed; mixed chains wait for the admin); refresh and on-demand lookups go
  through the page's language chains (`?url=`) instead of `/jsonld?url=`;
  inventory rows pinned to the bound business; change-feed walks
  (`?updatedSince=`) between full walks every 6 hours (`wp aivis sync
  --full`); `429` and a low `X-RateLimit-Remaining` make the tick yield. The
  mock API, the contract suite and the vendored OpenAPI document follow 1.9.0.
- `scripts/wp-dev/docker-compose.yml`: a throwaway WordPress for verifying the
  admin screens against the live instances.

### Removed
- Email to the site admin when the set of structured-data conflicts changes.
  Notification policy belongs to AIVIS, which fetches the conflicts in the
  status document (WP-I12).

### Fixed
- Settings (#69): the screen rendered six forms nested inside the main form,
  which browsers close at the first one — *Save changes* did nothing and *Test
  connection* checked a token that was never stored, so every attempt read
  "Token invalid or revoked". One form now; every button names its action;
  *Save & test connection* saves, then tests. The connection test names the
  host it contacted and the reason it failed (unreachable host, rejected
  token, update required, account deactivated, rate-limited) and no longer
  calls an unreachable production host a bad token.
- Independent review, 2026-09-07 (#55–#62): atomic sync lock in its own option
  row (INSERT IGNORE claim, compare-and-swap takeover, owner-checked release);
  an empty chain reconciles to zero and a chain that vanishes from AIVIS retires
  its rows at once; a page whose language has no chain never accepts another
  language's artifact; a row without an artifact can no longer displace a
  servable duplicate; live verification parses the real script element after
  stripping comments, requires HTTP 200 and verifies TLS; status pagination at
  the 500 cap detects the next page; switching injection off/on and deactivating
  purge through the cache adapter; a first authoritative absence suspends a
  withdrawn page on an idle chain and a sync backlog drains with one-minute
  continuation ticks, with the latency copy corrected accordingly.

### Security
- `Update URI` present from the first commit to prevent a wordpress.org slug
  collision from pushing third-party code to installed sites.
- The API token is never written to HTML, logs, diagnostics or Site Health;
  bearer-shaped strings are redacted from recorded messages.
