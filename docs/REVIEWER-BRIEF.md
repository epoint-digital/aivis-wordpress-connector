# AIVIS WordPress Connector — reviewer brief

**Purpose.** Everything an independent reviewer (human or model) needs to check this connector: what it is, what was decided and why, what was verified against reality, where the code and tests are, what is known to be missing, and the questions where a second opinion matters most. Every link points into the repository, which you have access to.

- Repository: https://github.com/epoint-digital/aivis-wordpress-connector (plugin slug `aivis-os`, GPL-2.0-or-later)
- State at the time of writing: all suites green — PHPUnit 181 tests / 12 893 assertions, JS contract 52, JS unit 85, serializer fuzz 16 — on `main`, 2026-09-09, against AIVIS API contract 1.9.0. Settings screen verified in a real WordPress (Docker) against the live Test instance. Not yet piloted on a live site (#18). No release tagged.
- Documentation index (every document, page, board and issue set): [`docs/INDEX.md`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/INDEX.md)

Suggested reading order, about two hours: this brief → §1–§3 of it → the specification's invariants (§02), retraction (§06), URL handling (§07, §07a), zero trust (§08), status for AIVIS (§11a) → the code and tests named in §7 below → the open issues in §6.

---

## 1. What the connector is

A WordPress plugin that connects one site to one **AIVIS business** and delivers the JSON-LD AIVIS generates for each page into that page's `<head>`:

- **Sync in the background** (WP-Cron, default every 15 minutes): list the business's chains, walk each assigned chain's URL inventory, fetch the artifacts that are new or changed, validate them through a zero-trust pipeline, store them in a local table.
- **Deliver on render**: at `wp_head` priority 100, one indexed lookup by the page's canonical URL, then print exactly one `<script type="application/ld+json" data-aivis="1">…</script>` with the stored bytes. Rendering never calls AIVIS. An outage costs freshness, not availability.
- **Retract**: when a page is withdrawn in AIVIS, stop printing the block and purge the page's cache — inferred today from two signals (see §3). With an idle pipeline and no backlog that takes one sync interval plus purge; two while a chain is rebuilding.
- **Never push**: the connector's only requests to AIVIS are reads. Its own status is stored locally and fetched by AIVIS from a key-gated, read-only endpoint on the site.

Audience for v1: any site with a business-bound token (API-1 shipped 2026-09-08); sites AIVIS controls or operates when the token is account-wide.

## 2. Documents

| Document | Link | What it is |
|---|---|---|
| Specification (source of truth) | [`docs/SPECIFICATION.md`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md) | §00 status · [§02 invariants WP-I1…I11](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#02--invariants) · [§03 API contract](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#03--api-contract) · [§04 status handling](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#04--http-status-handling) · [§05 data model](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#05--data-model) · [§06 sync and retraction](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#06--synchronisation-and-retraction) · [§07 URL handling](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#07--url-handling--two-different-jobs) · [§07a languages](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#07a--languages--one-chain-per-language) · [§08 zero trust](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#08--zero-trust-ingestion) · [§09 delivery](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#09--delivery) · [§09a primary source](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#09a--aivis-is-the-primary-source-of-structured-data) · [§10 cache](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#10--cache-publication) · [§11 admin](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#11--admin-status-and-verification) · [§11a status for AIVIS](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#11a--status-for-aivis--stored-here-fetched-by-aivis) · [§13 security](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#13--security-privacy-and-analytics) · [§15 acceptance criteria AC-01…27](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#15--acceptance-criteria) · [§19 deployment and versioning](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#19--deployment-versioning-and-updates) · [Appendix: decisions Q-01…Q-10](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#appendix--release-decisions) |
| API requirements for the AIVIS team | [`docs/API-REQUIREMENTS.md`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/API-REQUIREMENTS.md) · rendered: [`docs/api-requirements.html`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/api-requirements.html) | API-1 … API-11 with current behaviour, proposed contract, interim behaviour. The HTML embeds a machine-readable JSON block, checked in CI |
| Install guide | [`docs/INSTALL.md`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/INSTALL.md) | Operator steps: token, `wp-config.php`, install, bind, languages, sync, verify, cron, caches, status for AIVIS, other SEO plugins, troubleshooting |
| Runbook | [`docs/RUNBOOK.md`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/RUNBOOK.md) | Failure drills |
| Known issues | [`docs/KNOWN-ISSUES.md`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/KNOWN-ISSUES.md) | Including the Node 24+ engine bug |
| Admin UI prototype | [`docs/admin-ui.html`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/admin-ui.html) | Settings and Status screens as an interactive page |
| Changelog · plugin readme · security · contributing | [`CHANGELOG.md`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/CHANGELOG.md) · [`readme.txt`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/readme.txt) · [`SECURITY.md`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/SECURITY.md) · [`CONTRIBUTING.md`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/CONTRIBUTING.md) | |
| Tests overview | [`tests/README.md`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/README.md) | How the suites fit together |
| Program map (FigJam) | https://www.figma.com/board/zQo3mgU7G8eRrVqsBa2LnP | Sync loop, retraction decision tree, WordPress-behind-Cloudflare composition, API dependency map |

Rendered copies of the requirements page, install guide and prototype also exist as private claude.ai artifacts; the repository files above are the source and are identical.

## 3. What was verified against reality, and what was decided

**Verified** against `epoint-digital/aivis` `origin/main` @ `e96b89c` (2026-08-11, Public API v1 merged as [#142](https://github.com/epoint-digital/aivis/pull/142)) and the live OpenAPI at https://aivis-new.dev.onepoint.ro/api/public/v1/openapi.json, vendored as [`tests/fixtures/openapi-v1.json`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/fixtures/openapi-v1.json) with a nightly drift job:

- Tokens come in two kinds since contract 1.3.0: **account-wide** (reads every business on the account) and **business-bound** (`/me` names the business; every other endpoint answers 404 outside it). No expiry on either.
- `/jsonld?url=` returns **two distinct 404 messages**: `"URL not found in your businesses"` (the `Url` row is gone — how withdrawal looks today, since the artifact cascades on delete) and `"JSON-LD not generated yet for this URL"` (row exists, no artifact yet). Only prose distinguishes them.
- Matching is **exact plus a trailing-slash variant**, deliberately. No normalization on the AIVIS side.
- `/jsonld?url=` picks the freshest artifact **across every business on the account**; `Business.baseUrl` is single but **not unique**. Hence: `businessId` equality on every artifact, and inventory targets fetched by `urlId`.
- One artifact per URL (`urlId @unique`); no revision, no tombstone, no suppression state.
- Cursor pagination, `limit` ≤ 200, `nextCursor`, `hasMore`, `total`. No `ETag`, no rate limiting, no `Retry-After`.

**Decided** (product owner, 2026-09-05/06; recorded in the [appendix](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#appendix--release-decisions) and the spec sections):

| Decision | Where |
|---|---|
| Two instances, Production `app.aivis-os.com` and Test `aivis-new.dev.onepoint.ro`, chosen by a closed switch in Settings; never a free URL | Q-01, §03 |
| v1 ships to AIVIS-controlled installs only, token preferentially in `wp-config.php` | Q-02, §13 |
| One business = one domain; bind only the business whose `baseUrl` host equals the site's host; refuse otherwise; two matches force a choice | §06, §07, AC-20 |
| A bare 404 never deactivates; withdrawal = "URL not found" + confirmation on an authoritative inventory pass; absence on two authoritative passes retires | §06 R-01…R-02a, AC-17/18 |
| AIVIS is the primary source of structured data; other emitters are detected and flagged red; publishing never blocked; the connector **never alters another plugin** | §09a, AC-21…23 |
| **One AIVIS chain per language**; the admin assigns chains to WordPress languages; only assigned chains sync; an artifact from a chain not assigned to the page's language is rejected. AIVIS collects URLs from links or manual entry; no correlation between languages' URLs | Q-09, §07a, AC-24…26 |
| **Status is pulled, never pushed**: stored in the plugin, served read-only at `/wp-json/aivis-os/v1/status` with a site-issued key | Q-10, §11a, AC-27 |
| Nothing about people is ever collected or sent | WP-I9, §13 |
| **The plugin is a delivery method**: facts and mechanics here, judgments and policies in AIVIS; interim logic listed with its removal trigger | WP-I12, Q-11, §00 |
| Multisite network activation refused in 1.0 | Q-06 |
| Distribution via GitHub Releases only; `Update URI` header from the first commit | Q-07, §19 |

## 4. Architecture, with the files

```
aivis-os.php                       bootstrap, headers (Update URI), PHP guard
src/Plugin.php                     lazy container; hook registration; activation/deactivation
src/Api/Client.php                 GET-only client: TLS, zero redirects, host pin, 10 s, 1 MiB
src/Api/Response.php               status classification incl. the two 404 messages
src/Security/Envelope.php          ZT-02/ZT-04: eight required fields, depth 32, size
src/Security/Serializer.php        ZT-05: re-serialization flags; </script> impossible
src/Security/Binding.php           ZT-03: business, chain-for-language, host, requested URL
src/Storage/Schema.php             dbDelta table {prefix}aivis_jsonld, schema v2
src/Storage/Options.php            options (all non-autoloaded), token custody, chain → language map, status key
src/Storage/Repository.php         upsert (atomic), suspend/deactivate/retire, status rows
src/Sync/Synchronizer.php          inventory walk (assigned chains), fetch by urlId, retirement pass
src/Sync/Decision.php              R-01, R-01a, R-02, R-02a as pure functions
src/Sync/ChainAssignment.php       chain catalogue, language hint, auto-assignment rules
src/Sync/Verifier.php              loopback verification; conflict scan; records verified_at
src/Sync/Notifier.php              email to admin on conflict change (no network to AIVIS)
src/Sync/{Lock,Scheduler,Gc}.php   15-min stale lock recovery; cron schedules; 30-day GC
src/Delivery/Injector.php          wp_head 100: one query, one script element, fail open
src/Delivery/Gates.php             where injection is allowed
src/Delivery/UrlResolver.php       canonical permalink, sent unmodified
src/Delivery/Language.php          WPML / Polylang / TranslatePress / Weglot / core detection
src/Delivery/Conflicts.php         other JSON-LD emitters: registry, scan, attribution
src/Rest/StatusController.php      GET /status, /status/urls; bearer status key; no-store
src/Rest/StatusDocument.php        the document AIVIS fetches; also wp aivis status --format=json
src/Cache/*                        adapters: WP Super Cache, W3TC, LiteSpeed, Manual; PurgeResult
src/Admin/*                        Menu, StatusPage (overview), PagesPage + PagesTable + PagesQuery (list),
                                   SettingsPage, ObjectBox (edit screens), Notices, SiteHealth, AdminBar
src/Cli/Commands.php               wp aivis connection|sync|status|verify|refresh|languages|status-key
src/Update/GitHubReleases.php      Update URI → GitHub Releases
src/Domain/*                       UrlKey (local normalization), ErrorCode, Action
src/reference/connector-logic.mjs  JS reference of the decision rules; PHP held to it by tests
```

All links: https://github.com/epoint-digital/aivis-wordpress-connector/tree/main/src

## 5. How to run it

```bash
./scripts/lint.sh                      # PHP syntax, all files (Docker if no local php)
./scripts/phpunit.sh                   # PHPUnit 10 with WordPress function stubs (Docker)
node tests/unit/run.mjs                # JS unit
node tests/unit/serializer.mjs         # serializer fuzz + Node 24+ canary
node tests/contract/run.mjs            # contract suite against the built-in mock
AIVIS_API_BASE=… AIVIS_API_TOKEN=… node tests/contract/run.mjs --live   # against a real instance
node tests/docs/check-embedded-json.mjs
node tests/mock/server.js              # mock AIVIS API on :8787
```

CI: [`.github/workflows/ci.yml`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/.github/workflows/ci.yml) (Node 20/22/24 × PHP 8.1/8.3/8.5), [`release.yml`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/.github/workflows/release.yml) (reproducible ZIP built twice, `SHA-256SUMS`), [`api-drift.yml`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/.github/workflows/api-drift.yml) (nightly live OpenAPI vs vendored).

## 6. What is known to be missing or unproven

Say so before the reviewer finds it:

- **No WordPress integration tests.** PHPUnit runs against function stubs ([`tests/php/bootstrap.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/php/bootstrap.php)); nothing has executed inside a real WordPress yet. The pilot (#18) is the first real run.
- **Language providers are implemented from their documentation**, not against installed WPML/Polylang/TranslatePress/Weglot. Detection is best-effort with filters to override ([`src/Delivery/Language.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Delivery/Language.php)).
- **Retraction of a *deleted* page is still inference** (two authoritative absences, R-01/R-02): AIVIS leaves no tombstone for a deleted row by design. An *unpublished* page is explicit (`410 withdrawn`, `suppressedAt`) and comes down at once.
- **Loopback verification** may be blocked on some hosts; then "could not verify" is reported, never "not live".
- **Scaling**: the in-progress inventory is persisted in `wp_options` ([#31](https://github.com/epoint-digital/aivis-wordpress-connector/issues/31)); target selection and the retirement pass are N+1 ([#32](https://github.com/epoint-digital/aivis-wordpress-connector/issues/32)). Fine for thousands of URLs, not for hundreds of thousands.
- **Updates cannot be served while the repository is private** ([#34](https://github.com/epoint-digital/aivis-wordpress-connector/issues/34)).
- **Node 24+ engine bug** in `JSON.parse(JSON.stringify(x))` affects the JS test tooling only, not the PHP plugin ([#19](https://github.com/epoint-digital/aivis-wordpress-connector/issues/19), reproducer in `tests/known-issues/`).
- **Runtime API version handling exists** since 2026-09-09 (headers, 426, `/changelog`, Site Health; #54) but has only been exercised against the mock and the Test instance's current minimum (1.0.0); no real 426 has been observed.
- **Cache adapters** cover WP Super Cache, W3 Total Cache and LiteSpeed; anything else is "manual purge required" by design (Q-03 open until the pilot's infrastructure is known).

Open issues: https://github.com/epoint-digital/aivis-wordpress-connector/issues?q=is%3Aissue+is%3Aopen · For the AIVIS team, now in the AIVIS repository and assigned to Marius Diacu: [aivis#264](https://github.com/epoint-digital/aivis/issues/264) with sub-issues aivis#265–#275 · Internal API tracker: [#20](https://github.com/epoint-digital/aivis-wordpress-connector/issues/20).

## 7. Review checklist — where a second opinion is worth the most

For each: the rule, the code, the tests. The question to answer is "does the code do what the rule says, and is the rule right?"

| # | Question | Rule | Code | Tests |
|---|---|---|---|---|
| 1 | Can any path deactivate or retire a live artifact on a single, unconfirmed signal? It must not. | [§06](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#06--synchronisation-and-retraction), AC-17/18 | [`Decision.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Sync/Decision.php), [`Synchronizer.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Sync/Synchronizer.php) `apply_lookup()` / `retirement_pass()` | [`DecisionTest.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/php/DecisionTest.php), retraction drill in [`tests/contract/run.mjs`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/contract/run.mjs), [`connector-logic.mjs`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/reference/connector-logic.mjs) |
| 2 | Can anything AIVIS returns become executable markup or break out of the script element? | [§08](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#08--zero-trust-ingestion), AC-09 | [`Envelope.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Security/Envelope.php), [`Serializer.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Security/Serializer.php), [`Injector.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Delivery/Injector.php) | [`SerializerTest.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/php/SerializerTest.php), [`EnvelopeTest.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/php/EnvelopeTest.php), fuzz [`tests/unit/serializer.mjs`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/unit/serializer.mjs) |
| 3 | Can another business's or another language's artifact be stored for a page? | [§07](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#07--url-handling--two-different-jobs), [§07a](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#07a--languages--one-chain-per-language), AC-19/20/25 | [`Binding.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Security/Binding.php), [`Options.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Storage/Options.php) `allowed_hosts()`, [`SettingsPage.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Admin/SettingsPage.php) | [`BindingTest.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/php/BindingTest.php), [`SyncLanguageTest.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/php/SyncLanguageTest.php), [`AuditFixesTest.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/php/AuditFixesTest.php) |
| 4 | Is the language detection and chain assignment sound for WPML, Polylang, TranslatePress, Weglot and a core-only site? Which URL schemes break it? | [§07a](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#07a--languages--one-chain-per-language) | [`Language.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Delivery/Language.php), [`ChainAssignment.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Sync/ChainAssignment.php) | [`LanguageTest.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/php/LanguageTest.php), [`ChainAssignmentTest.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/php/ChainAssignmentTest.php) |
| 5 | Does the public render path make exactly one query, never a network call, and fail open on everything? | WP-I1/I2, [§09](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#09--delivery) | [`Injector.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Delivery/Injector.php), [`Gates.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Delivery/Gates.php), [`UrlResolver.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Delivery/UrlResolver.php), `Repository::find_active_for_render()` | [`GatesInjectorTest.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/php/GatesInjectorTest.php) |
| 6 | Is the status endpoint safe: header-only key, constant-time compare, no token or personal data in the document, no-store? | [§11a](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#11a--status-for-aivis--stored-here-fetched-by-aivis), AC-27, WP-I6/I9 | [`StatusController.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Rest/StatusController.php), [`StatusDocument.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Rest/StatusDocument.php) | [`StatusEndpointTest.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/php/StatusEndpointTest.php) |
| 7 | Does the connector ever alter another plugin, or block publishing? It must only warn. | [§09a](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#09a--aivis-is-the-primary-source-of-structured-data), AC-21…23 | [`Conflicts.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Delivery/Conflicts.php), [`Verifier.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Sync/Verifier.php), [`Notices.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Admin/Notices.php) | [`ConflictsTest.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/php/ConflictsTest.php), [`NotifierTest.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/php/NotifierTest.php) |
| 8 | Can the bearer token leak: HTML, REST, logs, diagnostics, Site Health, redirects? | WP-I6, [§13](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#13--security-privacy-and-analytics) | [`Client.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Api/Client.php) (zero redirects, host pin), `Options::redact()`, [`SiteHealth.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Admin/SiteHealth.php) | [`ClientTest.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/php/ClientTest.php) |
| 9 | Is "authoritative sync" airtight — can a partial walk ever retire anything? | [§06](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#06--synchronisation-and-retraction) item 3 | `Synchronizer::is_reconciled()`, `walk_inventory()` | [`AuditFixesTest.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/php/AuditFixesTest.php) |
| 10 | Is the cache story honest — is "live" ever claimed without confirmation? | WP-I10, [§10](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#10--cache-publication), AC-12 | [`src/Cache/`](https://github.com/epoint-digital/aivis-wordpress-connector/tree/main/src/Cache), [`StatusPage.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Admin/StatusPage.php) | — (adapter behaviour is asserted in the pilot) |
| 11 | Concurrency: two cron ticks, a manual "Sync now" and a WP-CLI run at once. | [§05 Cron](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#05--data-model) | [`Lock.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Sync/Lock.php), [`Scheduler.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Sync/Scheduler.php) | [`SchedulerLockTest.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/tests/php/SchedulerLockTest.php) |
| 12 | Release safety: reproducible ZIP, version single-source, `Update URI`, rollback. | [§19](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#19--deployment-versioning-and-updates), AC-16 | [`GitHubReleases.php`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/src/Update/GitHubReleases.php), [`scripts/build-zip.sh`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/scripts/build-zip.sh), [`scripts/version-check.mjs`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/scripts/version-check.mjs) | [`release.yml`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/.github/workflows/release.yml) |
| 14 | Does any code path make a judgment that belongs in AIVIS (see the interim table in §00)? | [WP-I12](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/SPECIFICATION.md#00--status-and-prerequisites) | whole `src/` | — |
| 13 | Are the eleven API asks the right asks, at the right priority, and is anything missing? | [`API-REQUIREMENTS.md`](https://github.com/epoint-digital/aivis-wordpress-connector/blob/main/docs/API-REQUIREMENTS.md) | — | [aivis#264](https://github.com/epoint-digital/aivis/issues/264) and sub-issues |

## 8. External references

| What | Where |
|---|---|
| AIVIS platform repository (the API's source) | https://github.com/epoint-digital/aivis — `lib/public-api/{auth,lookup,openapi,schemas}.ts`, `app/api/public/v1/*`, `db/schema.prisma` |
| Live OpenAPI (dev) and docs | https://aivis-new.dev.onepoint.ro/api/public/v1/openapi.json · https://aivis-new.dev.onepoint.ro/api/public/v1/docs |
| Production API base | https://app.aivis-os.com |
| Platform matrix | WordPress 6.5–7.1, PHP 8.1–8.5 |

## 9. Reporting findings

Open an issue in the repository with the `type:bug` or `type:architecture` label, or comment on the relevant open issue. For each finding: the rule (spec section or AC), the file and line, the input that breaks it, and the expected behaviour. A failing test is the best possible report — the suites run without WordPress or network.
