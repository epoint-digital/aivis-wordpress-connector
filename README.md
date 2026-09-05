# AIVIS WordPress Connector

Delivers AIVIS-generated JSON-LD into WordPress pages — synced locally, injected at `wp_head`,
never fetched on a public request.

- **Plugin slug:** `aivis-os`
- **Requires:** WordPress 6.5–7.1, PHP 8.1–8.5
- **License:** GPL-2.0-or-later
- **API contract:** [AIVIS Public API v1.0.0](https://aivis-new.dev.onepoint.ro/api/public/v1/docs)

## Status

Pre-implementation. The specification is verified against the live API and the merged platform
implementation; code lands per the milestones in the spec.

## Documentation

| Document | Purpose |
|---|---|
| [docs/SPECIFICATION.md](docs/SPECIFICATION.md) | The source of truth — invariants, API contract, data model, retraction, delivery, acceptance criteria |
| [docs/API-REQUIREMENTS.md](docs/API-REQUIREMENTS.md) | Extensions requested from the AIVIS platform team |

## Distribution restriction (v1)

v1 ships to **AIVIS-controlled or AIVIS-operated installs only**. AIVIS API tokens are
account-scoped: one token grants read access to every business on the account, and a WordPress
database travels through backups, staging clones and migrations. Customer-managed distribution is
blocked on business-scoped tokens (API-1). See §00 and §13 of the specification.
