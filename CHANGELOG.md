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
- Status for AIVIS, fetched never pushed (§11a): `published_at`, `verified_at`
  and `verified_hash` per page; read-only REST endpoint
  `/wp-json/aivis-os/v1/status` (+ `/status/urls`, paged) gated by a
  site-issued status key (Settings → Status for AIVIS, `wp aivis status-key`);
  `wp aivis status --format=json` prints the same document. The connector
  issues no request to AIVIS other than GET.

### Security
- `Update URI` present from the first commit to prevent a wordpress.org slug
  collision from pushing third-party code to installed sites.
- The API token is never written to HTML, logs, diagnostics or Site Health;
  bearer-shaped strings are redacted from recorded messages.
