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

### Security
- `Update URI` present from the first commit to prevent a wordpress.org slug
  collision from pushing third-party code to installed sites.
- The API token is never written to HTML, logs, diagnostics or Site Health;
  bearer-shaped strings are redacted from recorded messages.
