# AIVIS WordPress Connector — Specification

| Field | Value |
|---|---|
| Specification version | **1.0.0** (supersedes adapted rev 1, 08 Aug 2026) |
| Target plugin version | 1.0.0 |
| Date | 05 Sep 2026 |
| Repository | `aivis-wordpress-connector` |
| Plugin slug / text domain | `aivis-os` (bootstrap `aivis-os.php`) |
| License | GPL-2.0-or-later (deliberately different from the edge connector's Apache-2.0) |
| API contract | AIVIS Public API v1.0.0 |
| Platforms | WordPress 6.5 – 7.1, PHP 8.1 – 8.5 |
| Companion | [API-REQUIREMENTS.md](API-REQUIREMENTS.md) — extensions requested from the AIVIS team |
| Process board | [AIVIS Connector — Program Map](https://www.figma.com/board/zQo3mgU7G8eRrVqsBa2LnP) — sync loop, retraction tree, edge composition, API dependencies |

**Provenance.** Developer draft 0.9.0 (08 Aug 2026) → adapted rev 1, published as the artifact
[40cc266c](https://claude.ai/code/artifact/40cc266c-ef32-47d0-8775-bd47a3a481fa) → this document.
The artifacts remain the historical record; **this file is the source of truth** and versions with
the code.

**Verified against reality on 05 Sep 2026**, which adapted rev 1 was not: the live OpenAPI at
`https://aivis-new.dev.onepoint.ro/api/public/v1/openapi.json` and the merged implementation on
`epoint-digital/aivis@e96b89c` (the Public API shipped as #142 on 2026-08-11, after rev 1 was
written). Every API statement below carries a source. Changes from rev 1 are listed in §01.

---

## §00 · Status and prerequisites

**Ship restriction (v1).** The connector ships to **AIVIS-controlled or AIVIS-operated WordPress
installs only**. Customer-managed distribution is blocked on API-1 (business-scoped tokens), because
an account-scoped token in a customer's database exposes every business on the account. See §13.

Rev 1 recorded business-scoped tokens as a prerequisite expected to land; they do not exist, and the
appendix's Q-02 contradicted the body on whether v1 could ship without them. Resolved here: **v1
ships without them, under the restriction above.**

**Marked `verify at build`** — one item remains (rev 1 had three):

- §11 live verification uses a loopback self-fetch, which some hosts block. Fallback: admin-browser
  check or WP-CLI `verify`. This is a hosting fact, not an API question, and can only be settled on
  real infrastructure.

The two rev-1 markers on AIVIS behaviour are now **confirmed** and no longer conditional: the
multi-chain winner rule and trailing-slash matching (§03).

---

## §01 · What changed from adapted rev 1

| # | Change | Why |
|---|---|---|
| 1 | Ship restriction stated outright; Q-02 resolved | Business-scoped tokens do not exist. `ApiToken` carries only `userId` |
| 2 | **Retraction redesigned** (§06) | A bare 404 is not proof of withdrawal. The route distinguishes two 404 causes; only one is evidence |
| 3 | URL handling split into outbound vs local (§07) | AIVIS applies no normalization, deliberately. Normalizing before the lookup loses real pages |
| 4 | Inventory row fields `layer` and `captureStatus` adopted (§03, §06) | Neither existed in rev 1; `captureStatus` separates regenerating from withdrawn |
| 5 | **Domain-matched business binding** (§06, §07, §11) | A business has exactly one domain (`Business.baseUrl`). Binding requires a host match; rev 1 made the admin pick blind from every business on the account |
| 6 | `hasMore` / `total` used for authoritative-run completion (§06) | Rev 1 had only `nextCursor` and no completion denominator |
| 7 | Serve-gate expectations dropped (§03) | Edge V-04 is a schema change, not a policy flag: `UrlJsonLdArtifact.urlId` is `@unique` |
| 8 | Platform matrix widened to WP 7.1 / PHP 8.5 | WordPress 7.1 and PHP 8.5.9 are current |
| 9 | Rate-limit wording corrected (§04) | No rate limiting exists; the real throttle is ours |
| 10 | Gaps closed: option keys, cron hooks, GC job, `PurgeResult`, depth limit, text domain, negative-cache naming, `url_key` canonicalisation | All were undefined in rev 1 |
| 11 | `data-aivis` marker documented as a shared contract (§14) | The edge worker depends on it; no document stated it |

---

## §02 · Invariants

Each maps to at least one automated test (§16).

| ID | Invariant |
|---|---|
| **WP-I1** | **Fail open.** Any connector failure returns the normal WordPress page. Never an error, redirect, maintenance page or non-2xx on the public path |
| **WP-I2** | **Local-only render path.** No blocking network call to AIVIS on a public request. At most one indexed local lookup plus serialization |
| **WP-I3** | **User-agent parity.** Identical JSON-LD for humans, search crawlers, AI crawlers, logged-in and anonymous, per canonical URL |
| **WP-I4** | **Additive output only.** Exactly one tagged AIVIS script when a valid artifact matches; nothing else modified or removed |
| **WP-I5** | **No content mutation.** Never `wp_update_post()`, status changes, revisions, or `post_modified` updates from sync or invalidation |
| **WP-I6** | **Secret containment.** The bearer token never appears in HTML, JS, REST responses, logs, exceptions, Site Health, or support bundles |
| **WP-I7** | **Zero-trust artifact handling.** Size-limited, strictly decoded, schema-checked, business- and host-bound, re-serialized, escaped (§08) |
| **WP-I8** | **Last-known-good, bounded by retraction.** A failed refresh never overwrites a valid artifact. An artifact is withdrawn only on admin disable, confirmed withdrawal (R-01), or inventory retirement (R-02) |
| **WP-I9** | **No telemetry about people.** No visitor, crawler, page-view, IP, UA or WP-user data goes to AIVIS — permanent (§13). The **connector status report** (§09a, API-9) is the one deliberate exception and carries only the connector's own state: version, site host, sync counts, cache state, structured-data conflicts. Opt-out |
| **WP-I10** | **Honest cache status.** "Live on the site" is claimed only after adapter-confirmed invalidation or a public-page verification that finds the marker and the expected hash |

---

## §03 · API contract

Base `https://app.aivis-os.com/api/public/v1` in production; `AIVIS_API_BASE_URL` overrides it for dev/staging (`https://aivis-new.dev.onepoint.ro`). Every request carries `Authorization: Bearer aivis_…` and
`Accept: application/json`. Errors are `{"error":{"message":"…"}}`.

**Authentication** (`lib/public-api/auth.ts`). Token format is `aivis_` + 32 random bytes base64url.
Only the SHA-256 digest is stored; the plaintext is shown once at creation. The token resolves to a
**user**, and every route scopes by `userId` — "the business → user chain is the tenancy boundary".
There is no `businessId`, scope, or expiry on the token, and no rate limiting. Revocation is a row
delete; rotation is manual (create, swap, delete).

### Endpoints

| Endpoint | Plugin use |
|---|---|
| `GET /me` | Validate the token; display `email`, `name`, `tokenName` |
| `GET /businesses` | Business selector. Returns `id`, `name`, `baseUrl`, `industry{key,name}`, `chainCount`, `createdAt` |
| `GET /businesses/{businessId}/chains` | Non-archived chains. Returns `id`, `name`, `description`, `state`, `currentStep`, `knowledgeGraphReady`, `graphScore`, `urlCount`, `createdAt` |
| `GET /chains/{chainId}/urls` | Inventory. Returns `id`, `url`, `languageCode`, `layer`, `captureStatus`, `jsonLd{ready,stale,generatedAt}` |
| `GET /jsonld?url={absoluteUrl}` | Artifact retrieval, the hot path |
| `GET /urls/{urlId}/jsonld` | Diagnostics and exact-ID verification only |

**Enums.** `state`: `empty` \| `building` \| `ready` \| `re_ingesting`. `layer`: `structural_core`
\| `editorial`. `captureStatus`: `draft` \| `processing` \| `processed` \| `failed` \| `null`.

**Artifact envelope**, from both `/jsonld` endpoints — all eight fields required,
`additionalProperties: false`:

```
urlId, chainId, businessId, url, languageCode, stale (bool), generatedAt (ts), jsonLd (object|array)
```

`jsonLd` is the Schema.org document itself, ready to embed. `stale` means the source page changed
after generation; stale documents are still served and **must still be used** — old structured data
beats none.

**Pagination.** All list endpoints: `?cursor=` + `?limit=` (default 100, **max 200** — request 200).
Responses carry `items`, `nextCursor`, `hasMore`, `total`. Follow `nextCursor` until null; `total`
gives the completion denominator a sync needs (§06).

**Confirmed behaviours** (rev 1 marked both `verify at build`):

- **Matching is exact plus a trailing-slash variant.** Nothing else — scheme swaps, host case and
  query strings are misses *by design* (`lib/public-api/lookup.ts`: "silently matching the wrong page
  would be worse than a 404"). See §07.
- **Multi-chain winner:** non-stale first, then newest (`compareJsonLdCandidates`).

**Absent by design — do not build against these.** No serve-gate (`validated` vs `latest`); one
artifact per URL is an enforced invariant (`UrlJsonLdArtifact.urlId @unique`), so there is no
previous version to fall back to. No `suppressedAt`, tombstone or publication state. No `ETag`,
`Last-Modified` or `Cache-Control`. No webhooks or change feed. No rate-limit headers. Each is
requested in [API-REQUIREMENTS.md](API-REQUIREMENTS.md).

---

## §04 · HTTP status handling

| Status | Behaviour |
|---|---|
| `200` | Validate through §08 before storing or displaying anything |
| `400` | Mark the URL request invalid; no retry without changing the request |
| `401` | Stop the sync, keep last-known-good rows, show "API token invalid or revoked". Covers both missing and unrecognised tokens |
| `403` | Stop the sync, keep rows, show "AIVIS account deactivated" |
| `404` — **unknown URL** (`"URL not found in your businesses"`) | New URL: negative-cache the miss (§06). Previously-stored URL: **withdrawal candidate**, R-01 |
| `404` — **no artifact** (`"JSON-LD not generated yet for this URL"`) | Never deactivate. Hold last-known-good; the page exists and only the artifact is missing |
| `404` — unrecognised body | Conservative branch: hold last-known-good, suspend, confirm via inventory |
| `429` | Honour `Retry-After`; schedule a later batch; never sleep in-process. Defensive only — no rate limiting exists today |
| `5xx` / network / timeout | Keep last-known-good; retry via scheduled backoff |

Message matching is defensive by construction: the strings are prose, not contract, and any
unrecognised 404 body takes the conservative branch. API-3 replaces this with a stable `code`.

**Transport.** `wp_safe_remote_get()`, TLS verified, **zero redirects** (a redirect would forward
the bearer token), fixed AIVIS host, 10 s timeout, 1 MiB response cap, connector version in the user
agent, JSON content type (charset parameter allowed), never on the public render path.

**Self-throttle.** Max 20 artifact requests per job; batch scheduling; no in-process sleeps. This is
the real rate limit, chosen by us, not negotiated with the API.

---

## §05 · Data model

### Table `{$wpdb->prefix}aivis_jsonld`

Created and upgraded with `dbDelta()` plus a schema-version option.

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint unsigned | Primary key |
| `url_key` | char(64) | SHA-256 of the normalized URL (§07) — unique index |
| `source_url` | text | Absolute URL as returned by AIVIS (audit) |
| `url_id`, `chain_id`, `business_id` | varchar(191) | AIVIS identities; winning chain |
| `language_code` | varchar(16) | AIVIS language |
| `json_ld` | longtext | Safely re-serialized document (ZT-05) |
| `content_hash` | char(64) | SHA-256 of the exact stored serialization |
| `source_generated_at` | datetime | AIVIS generation time, UTC |
| `source_stale` | tinyint(1) | Mirrors the API `stale` boolean. Stale artifacts remain serviceable |
| `active` | tinyint(1) | Runtime injection permitted |
| `local_post_id` | bigint unsigned null | Set when the URL resolves to a WP object via `url_to_postid()`, for cache invalidation; null for archives, terms and unresolvable URLs |
| `last_seen_sync_id` | char(36) | Last job that observed the URL |
| `missing_complete_runs` | smallint unsigned | Consecutive authoritative absences (R-02) |
| `last_synced_at` | datetime | Last successful validation |
| `suspended_at` | datetime null | Set by R-01: injection stopped, row kept pending inventory confirmation |
| `retired_at` | datetime null | Soft-retirement time |
| `last_error_code` | varchar(32) null | Why a row is holding or suspended — the status screen shows it |

**Indexes:** primary key; unique `url_key`; `(business_id, active)`; `url_id`; `last_seen_sync_id`;
`retired_at`.

### Options — all non-autoloaded

| Key | Contents |
|---|---|
| `aivis_os_token_status` | Token source (`constant` \| `option`), validation state, `tokenName`, last check |
| `aivis_os_token` | The token, only when no constant is defined |
| `aivis_os_business` | Selected `businessId`, `baseUrl`, allowed hosts |
| `aivis_os_delivery` | Injection switch, per-URL disables |
| `aivis_os_sync_state` | Cursors, current sync id, last authoritative completion, lock |
| `aivis_os_diagnostics` | Capped recent errors (100 entries, ring buffer) |
| `aivis_os_schema_version` | DB schema version |
| `aivis_os_uninstall_retention` | Whether uninstall drops data |

Transients may vanish before expiry and are never the only artifact store.

### Constants

`AIVIS_API_TOKEN` (preferred token location — §13), `AIVIS_API_BASE_URL` (development only; the
base is deliberately not editable in the settings UI, since an arbitrary endpoint would receive the
bearer token).

### Cron

| Hook | Schedule | Work |
|---|---|---|
| `aivis_os_sync` | `aivis_os_interval` (default 15 min; options 5 min, hourly, manual) | Inventory and artifact sync |
| `aivis_os_gc` | daily | Delete rows whose `retired_at` is older than 30 days |
| `aivis_os_verify` | daily | Live verification of a sample (§11) |

Custom schedules registered as `aivis_os_5min`, `aivis_os_15min`. Interval filterable via
`aivis_connector_sync_interval`. One per-site lock with expiry; abandoned locks recovered after
15 minutes and the recovery recorded. WP-Cron is traffic-driven — the docs recommend system cron
hitting `wp-cron.php`, or WP-CLI.

**Two distinct negative caches, named separately** (rev 1 gave both the same name):

- `aivis_os_miss_{url_key}` — a `/jsonld` 404 for an unknown URL. TTL 6 h; cleared by any inventory
  change touching that URL.
- `aivis_os_sched_{url_key}` — the on-demand "already scheduled a fetch" guard. TTL 15 min.

---

## §06 · Synchronisation and retraction

### Sync

1. **Business binding — domain-matched.** A business has exactly one domain
   (`Business.baseUrl`, a single non-null string; there is no alias or multi-domain concept), so the
   selected business's `baseUrl` host **must** equal this site's `home_url()` host, modulo
   www-equivalence. The plugin auto-selects when exactly one business matches and refuses to bind to
   any business that does not (§11). Allowed hosts derive from `home_url()` / `site_url()` plus
   configurable aliases.
2. **Inventory walk.** `/businesses/{id}/chains` → for each non-archived chain,
   `/chains/{id}/urls` with `limit=200`, following `nextCursor`.
3. **Authoritative run.** A sync is *authoritative* only when every page of every chain completed
   and the row count reconciles with `total`. A partial traversal **never** deactivates or retires
   anything.
4. **Target set.** The distinct absolute URLs with at least one row where `jsonLd.ready = true`.
5. **Artifact fetch.** `/jsonld?url=` per URL (§07 outbound form), max 20 per job, resuming from
   persisted cursors.
6. **Store.** Validate through §08, then atomically upsert. Purge caches only after a successful
   commit and only when `content_hash` or `active` changed.

### Retraction

Rev 1 treated any confirmed 404 as proof of withdrawal. It is not: the API returns 404 both when a
URL is unknown *and* when its artifact merely does not exist yet, and the second happens during
ordinary operation — regeneration, a covering intent leaving the `deployment` phase, or an
`is_editable = false` page. Acting on it would pull live structured data off customer sites.

| ID | Rule |
|---|---|
| **R-01** | **Confirmed withdrawal.** A stored URL returns 404 with body `"URL not found in your businesses"`, and API reachability is confirmed in the same run (another request succeeded, or a `/me` probe passes). The `Url` row is gone — and since the artifact cascades on delete, that is how a retraction looks today. **Suspend injection immediately and purge that URL's cache**, then confirm on the next authoritative inventory pass before setting `retired_at`. The delay before deletion exists because the same 404 appears when the token's account or the business selection changes, which is not a retraction |
| **R-01a** | **Not a withdrawal.** 404 with body `"JSON-LD not generated yet for this URL"`, or any unrecognised 404 body: hold last-known-good, keep serving, re-check next run. Never deactivate |
| **R-02** | **Inventory retirement.** A URL absent from two consecutive complete, authoritative syncs is retired: `active` → 0, then `retired_at` set. Inactive rows are kept 30 days for rollback, never injected, then deleted by `aivis_os_gc` |
| **R-02a** | **Ready-flag withdrawal.** An authoritative inventory row reporting `jsonLd.ready = false` for a URL that was previously `true` deactivates it — unless `captureStatus` is `processing`, which means regeneration is in flight and last-known-good is held |
| **R-03** | **Latency honesty.** Worst case is `sync interval + cache purge`. The R-01 fast path applies only to URLs the plugin re-fetches, so it is a bonus, not a guarantee. The status screen displays the real figure; 5-minute intervals are recommended for retraction-sensitive sites; "Refresh this URL now" forces an immediate check |

Other retirement paths, unchanged: admin disconnect with explicit clear, business change, per-URL
admin disable.

**Why this matters more here than at the edge.** The edge worker cannot retract a block that the
origin shipped — removing origin markup is modification, fenced out by its I4/D6. WordPress owns its
retraction path completely; nothing downstream will catch a mistake made here.

---

## §07 · URL handling — two different jobs

Rev 1 conflated these. They are separate, and the distinction is load-bearing because AIVIS applies
**no normalization at all**, on purpose.

### Outbound — what goes in `?url=`

Send the page's canonical permalink **unmodified**: the exact absolute URL WordPress would emit as
`rel="canonical"`, including any meaningful query string. Do not strip, reorder or lowercase
anything. AIVIS matches exact plus trailing-slash only, so "helpful" normalization turns a hit into
a silent miss.

### Local — what becomes `url_key`

For the local index only: scheme `http`/`https` only; lowercase scheme and host; strip default ports
and fragments; drop tracking-only parameters (`utm_*`, `gclid`, `fbclid`, `msclkid`, `_ga`); keep
semantically meaningful parameters, sorted deterministically; preserve path and percent-encoding
semantics. **Trailing slash canonicalises to the form without it** — both variants map to the same
key. `url_key` = SHA-256 of the result.

### Binding, before any row is stored (ZT-03)

- The selected business's `baseUrl` host equals this site's host — checked at bind time and
  re-checked on every authoritative sync, so a business edited in AIVIS to point elsewhere unbinds
  instead of quietly serving another site's data.
- Response `businessId` equals the selected business — **always**.
- Response `chainId` belongs to the selected business's known chain set — **when inventory context
  exists**. Strictly stronger than the business check, since `Chain` has a hard FK to `Business`, but
  unavailable on the on-demand path for an unsynced URL, which is why both checks exist.
- Response `url` host is in the allowed hosts.
- Requested URL and returned URL are equal or an approved trailing-slash alias.
- `urlId` and `chainId` are non-empty.

The business check is not ceremony, and the domain check does not replace it. `Business.baseUrl` is
**not unique**, so two businesses on one account can carry the same domain — a live business and a
rebuild is the ordinary way that happens. `/jsonld?url=` gathers candidates across **every business
on the account** and returns the freshest, so domain matching alone would still let the wrong
business's structured data onto the site, with no error anywhere. Domain match picks the right
candidate set; `businessId` equality pins the one the admin actually chose.

---

## §08 · Zero-trust ingestion

AIVIS output is untrusted input. The WordPress mirror of the edge connector's S-01…S-06.

| Gate | Requirement |
|---|---|
| **ZT-01 Transport** | HTTPS only, TLS verified, zero redirects, fixed host, 1 MiB cap, JSON content type |
| **ZT-02 Envelope** | Require the eight v1 fields (§03) with correct types. Unknown *additional* fields are ignored (forward-compatible); violations of the pinned schema reject |
| **ZT-03 Scope** | URL, host, business and chain binding per §07 |
| **ZT-04 Structure** | Exception-based decode; object or array root only; **maximum nesting depth 32** (matching the edge connector's S-04); reject invalid UTF-8, non-finite numbers, scalar roots, excess nesting, oversize. Never validate Schema.org semantics — that is AIVIS's job |
| **ZT-05 Safe re-serialization** | Plugin-generated serialization, never the raw API substring. `JSON_HEX_TAG \| JSON_HEX_AMP \| JSON_HEX_APOS \| JSON_HEX_QUOT \| JSON_UNESCAPED_SLASHES \| JSON_UNESCAPED_UNICODE \| JSON_PRESERVE_ZERO_FRACTION` — `JSON_HEX_TAG` makes `</script>` breakout impossible |
| **ZT-06 Atomic commit** | Validate and serialize before the write transaction; atomic upsert; prior row preserved on failure; hash computed from the exact stored bytes; purge only after a successful commit and only when hash or activation state changed |

**Stable error codes:** `AIVIS_AUTH_401`, `AIVIS_HTTP_TIMEOUT`, `AIVIS_SCHEMA_INVALID`,
`AIVIS_SCOPE_MISMATCH`, `AIVIS_RETRACTED`, `AIVIS_DB_WRITE`, `AIVIS_PURGE_UNSUPPORTED`.

---

## §09 · Delivery

**Hook:** `wp_head`, priority 100. No output buffering or document rewriting in v1. Themes must call
`wp_head()`; absence is a Site Health warning.

**Output**, exactly one element:

```html
<script type="application/ld+json" data-aivis="1">…</script>
```

**Gates:** site-wide switch on; a normal public front-end HTML GET; not admin, REST, AJAX, feed,
robots, sitemap, trackback, preview or a WordPress 404 page; the URL resolves safely; an active
matching local artifact exists; the stored integrity hash passes. A per-request guard prevents
duplicate emission.

> Note the collision rev 1 never flagged: a **WordPress 404 page** is an injection gate, while an
> **HTTP 404 from AIVIS** is a retraction signal. Same number, unrelated meanings.

**Performance budget:** zero outbound network calls, at most one indexed query, p95 connector
overhead **< 5 ms** excluding JSON write-out, no front-end writes except the deduplicated
miss-scheduling guard.

---

## §09a · AIVIS is the primary source of structured data

Any JSON-LD on a page that the connector did not emit is a **conflict**: two
`Organization` or `WebSite` nodes on one page give search engines conflicting
answers. The connector identifies such blocks, attributes them where a marker
allows (Yoast, Rank Math, All in One SEO, SEOPress, Slim SEO, Schema Pro, WPSSO,
or *unknown* for theme/hand-written blocks), extracts their `@type`s, and flags
them **red**. Publishing is never blocked.

**Detection** runs on the background loopback scan — daily, on demand, and once
after the first authoritative sync — over up to 10 active pages. Never on the
render path. Active emitter plugins are also detected statically so the warning
can appear before the first scan.

**Override.** The admin can acknowledge the current conflict set ("Override — I
know, keep publishing"). The red warning stays silent until the *set* changes
(new page, new source, new types), then re-arms. Site Health reports an
acknowledged set as *recommended*, an unacknowledged one as *critical*.

**Suppression.** For emitters whose output can be switched off through their
own public filter (`wpseo_json_ld_output`, `rank_math/json_ld`,
`aioseo_schema_disable`, `slim_seo_schema_graph`) the connector offers a
per-plugin toggle, **off by default** — the admin makes AIVIS the only source
with one checkbox, and can undo it the same way. Emitters without a filter get
manual guidance.

**Notifications.** Inside WordPress: the red notice on the plugin screens,
a Conflicts tile and per-URL badge on Status, a Site Health test, and an email
to the site admin when the conflict set changes (opt-out). Toward AIVIS: the
connector status report, API-9 (opt-out; see WP-I9).

## §10 · Cache publication

```php
interface CacheAdapter {
    public function is_available(): bool;
    public function purge_urls( array $urls ): PurgeResult;
    public function purge_all(): PurgeResult;   // first activation / deactivation only
}
```

`PurgeResult` — rev 1 named the states but never defined the shape:

```php
final class PurgeResult {
    public string $state;        // confirmed | requested | unsupported | failed
    public int    $purged;
    public array  $failed_urls;
    public ?string $message;     // sanitized, never contains credentials
}
```

`confirmed` is the only state that permits a "Live on the site" claim (WP-I10). `requested` means
the provider accepted without confirming. Provider-safe batching; sanitized errors; no AIVIS
credentials ever reach an adapter. Unsupported infrastructure is reported as manual-purge-required —
never as live.

---

## §11 · Admin, status and verification

**Settings.** Token entry (with the source and what it can reach stated plainly — §13), connection
test via `/me`, domain-matched business selector, injection switch, sync interval, cache adapter
selection.

**Business selector behaviour**, given one business has exactly one domain:

| `/businesses` rows matching this site's host | Behaviour |
|---|---|
| Exactly one | Auto-selected and shown as confirmed. No decision asked of the admin |
| More than one (same domain onboarded twice) | The admin must disambiguate. The list shows `name`, `createdAt`, `chainCount` and `industry` so the live business is distinguishable from a rebuild |
| None | Hard error: "No AIVIS business matches this site's domain." Non-matching businesses are **not** offered — binding to one would ship another site's structured data |

**Status.** Sync state and last authoritative completion; counts of active, stale, suspended and
retired artifacts; chain `state` and `knowledgeGraphReady` per chain; real retraction latency (R-03);
recent errors by stable code; per-URL table with "Refresh this URL now" and per-URL disable.

**Live verification.** Fetch a sample page over loopback and confirm the marker and expected hash
are present. *`verify at build`* — loopback is blocked on some hosts; fallbacks are an
admin-browser check and `wp aivis verify`.

**WP-CLI:** `wp aivis connection test`, `wp aivis sync --all`, `wp aivis status`,
`wp aivis verify [--url=…]`.

---

## §12 · Extension contract

```php
apply_filters( 'aivis_connector_current_url', $url, $query_context );
apply_filters( 'aivis_connector_allowed_hosts', $hosts );
apply_filters( 'aivis_connector_manage_capability', 'manage_options' );
apply_filters( 'aivis_connector_sync_interval', $seconds );

do_action( 'aivis_connector_artifact_changed', $url, $old_hash, $new_hash );
do_action( 'aivis_connector_purge_urls', $urls, $context );
do_action( 'aivis_connector_sync_completed', $summary );
do_action( 'aivis_connector_sync_failed', $error_code );
```

Plus `clean_post_cache()` for object caches, and `uninstall.php` via `register_uninstall_hook()`.

---

## §13 · Security, privacy and analytics

**Token custody.** `AIVIS_API_TOKEN` in `wp-config.php` or the environment is the **documented
default**, not a fallback — it keeps the token out of the database, and therefore out of backups,
staging clones and migrations. The database option exists for installs that cannot edit
`wp-config.php`, and the settings screen says which is in use.

**What the token can reach.** Stated plainly in the admin UI: an AIVIS API token is account-scoped
and grants read access to every business on the account. This is why v1 is restricted to
AIVIS-controlled installs (§00) and why API-1 is the blocker for customer-managed distribution.

**AN-01 — crawler analytics are permanently excluded from WordPress.** Not deferred. PHP sees only
cache-*miss* traffic while AI crawlers hit cached popular pages, so the sample is systematically
biased; a JS beacon cannot fix it, since AI crawlers do not execute JavaScript. Selling a Monitoring
subscription on skewed counts would contradict the product's own evidence positioning. There are no
analytics settings, warnings or partial modules in the WordPress admin, and WP-I9 is permanent.

Crawler measurement belongs at the edge — see §14.

---

## §14 · Composition with the edge connector

For a WordPress site behind Cloudflare, the two connectors compose: **delivery at the origin,
measurement at the edge.**

The worker runs with `INJECT=off` (edge A-07) as a pure measurement tap — no HTMLRewriter pass, no
mutation of any response. The plugin's block rides inside cached HTML at zero added edge latency,
and the worker records `injected:"origin"`.

**The shared marker contract**, which no document previously stated:

- The plugin emits `data-aivis="1"` on its `<script type="application/ld+json">`.
- The worker detects `script[data-aivis]` — **attribute presence**, not a specific value — for
  idempotency. Presence is what matters; the plugin must never drop the attribute.
- This also means a mis-configured `INJECT=on` in front of an active plugin degrades to a single
  block rather than two.

WordPress-only sites get no traffic analytics, by design (§13). Monitoring-SKU evidence for them
comes from platform-side forensic and citation runs.

---

## §15 · Acceptance criteria

Demonstrated on staging against the designated AIVIS environment.

| ID | Criterion |
|---|---|
| AC-01 | A valid token connects via `/me`; an invalid token is rejected without exposure |
| AC-02 | The admin selects a matching business and completes a paginated full sync |
| AC-03 | Every AIVIS-ready distinct URL has a valid active local artifact or an explicit per-URL error |
| AC-04 | Enabling injection changes no WordPress content records and creates no revisions |
| AC-05 | A matched public page contains exactly one safe `data-aivis="1"` script in `<head>` |
| AC-06 | A page without a ready artifact is unchanged |
| AC-07 | Existing non-AIVIS JSON-LD is neither inspected nor changed |
| AC-08 | Public requests make no AIVIS call and survive a complete AIVIS outage |
| AC-09 | Script-breakout payloads cannot create executable markup |
| AC-10 | Refresh failure preserves and serves last-known-good |
| AC-11 | Changed artifacts invalidate affected caches without republishing content |
| AC-12 | Unsupported cache infrastructure is reported as manual-purge-required, never as live |
| AC-13 | Identical markup for a browser, Googlebot, GPTBot and arbitrary user agents |
| AC-14 | Disable/deactivate plus purge restores pre-connector output |
| AC-15 | No token, telemetry, IP or raw UA appears in public output or diagnostics |
| AC-16 | The release ZIP is reproducibly built, checksummed, installable, matrix-green |
| **AC-17** | **Withdrawal deactivates on the next authoritative inventory pass with a cache purge, and does not resurrect from last-known-good during a subsequent outage** |
| **AC-18** | **A 404 meaning "JSON-LD not generated yet" never deactivates a live artifact** |
| **AC-19** | **An artifact whose `businessId` differs from the selected business is rejected and never stored** |
| **AC-20** | **A business whose `baseUrl` host differs from the site's host cannot be bound; two businesses sharing this site's domain force an explicit choice** |
| **AC-21** | **A page carrying foreign JSON-LD is flagged with its source and `@type`s after one scan; the connector's own block is never counted** |
| **AC-22** | **Enabling suppression for a supported plugin removes its JSON-LD from the page, confirmed by a rescan; injection of the AIVIS block is unaffected either way** |
| **AC-23** | **Overriding silences the warning for exactly the current conflict set and re-arms it when the set changes** |

AC-17 is rewritten from rev 1, where it required immediate deactivation on any fetch-404 — which the
API cannot support. AC-18 and AC-19 are new, and both guard failure modes that would otherwise be
silent.

---

## §16 · Testing

- **Unit:** URL normalization tables (outbound vs local), the zero-trust pipeline, the injection
  gates, retraction branch selection.
- **Contract, against the mock server** (§17 M1): every status code in §04, malformed payloads,
  oversized bodies, script-breakout corpus, depth-33 nesting, wrong-business and wrong-host
  envelopes, pagination past 200 items, and the retraction scenario `ready:true` → `ready:false` →
  absent. Asserts responses against the vendored `openapi.json`.
- **Nightly, against the dev instance:** the same contract tests. Divergence fails loudly — this is
  the drift alarm whose absence let the previous specification go four weeks unverified against an
  API that had meanwhile shipped.
- **Every invariant in §02 and every criterion in §15 maps to a named test.** Mapping table in
  `tests/INVARIANTS.md`.

---

## §17 · Milestones

| M | Content |
|---|---|
| **M0** | Contract lock: this document, [API-REQUIREMENTS.md](API-REQUIREMENTS.md), the process board, production hostname, repo creation |
| **M1** | Skeleton, storage, hardened API client, and the **mock AIVIS server** generated from the vendored `openapi.json` with a refresh script |
| **M2** | Inventory and synchronisation: business binding, cursor traversal, authoritative-run logic, last-known-good writes, retirement and retraction (§06), the GC job |
| **M3** | Delivery: URL resolver (§07), `wp_head` injector, all gates, zero-trust pipeline, fail-open and parity tests, the enforced performance budget |
| **M4** | Cache publication: adapter interface, launch adapters, manual fallback, live verification, status dashboard, Site Health, runbooks |
| **M5** | Hardening and OSS readiness: matrix WP 6.5/6.9/7.1 × PHP 8.1/8.3/8.5, threat model, privacy and external-service disclosure, CI gates, reproducible release |
| **M6** | Pilot and 1.0.0: staging smoke, pilot on an AIVIS-controlled site with a real page cache, failure drills including retraction and rollback, tagged release |

---

## §18 · Repository layout

```
aivis-os.php                  bootstrap + plugin headers (incl. Update URI)
src/{Admin,Api,Cache,Delivery,Domain,Security,Storage,Sync}/
assets/  languages/
docs/{SPECIFICATION,API-REQUIREMENTS,ARCHITECTURE,CACHE-INTEGRATIONS,PRIVACY,RUNBOOK,THREAT-MODEL}.md
tests/{Unit,Integration,EndToEnd}/  tests/fixtures/openapi-v1.json  tests/INVARIANTS.md
.github/{ISSUE_TEMPLATE,workflows,dependabot.yml}
composer.json  phpcs.xml.dist  phpunit.xml.dist
readme.txt  uninstall.php  CONTRIBUTING.md  SECURITY.md  CHANGELOG.md  LICENSE
```

---

## §19 · Deployment, versioning and updates

**Distribution.** GitHub Releases only for v1 (Q-07). Each tagged release
carries `aivis-os.zip`, built reproducibly (fixed mtimes from the commit,
sorted entries, `.distignore`) and a `SHA-256SUMS` file. The release workflow
builds the ZIP twice and fails if the bytes differ (AC-16).

**Install.** Operator-run in v1: ZIP upload, or
`wp plugin install <release asset url> --activate`. Full steps in
[INSTALL.md](INSTALL.md); operational drills in [RUNBOOK.md](RUNBOOK.md).

**Versioning.** The `Version:` header in `aivis-os.php` is the single source of
truth — it is the only version WordPress reads. `AIVIS_OS_VERSION`,
`readme.txt` *Stable tag*, the top `CHANGELOG.md` heading and the git tag must
all equal it; `scripts/version-check.mjs` enforces this in CI and in the
release job. Semver. The API contract version (v1.0.0) is tracked separately —
the plugin at 1.2.0 may still speak API v1.

**Updates.** WordPress only auto-updates plugins from wordpress.org, so the
plugin declares `Update URI: https://github.com/epoint-digital/aivis-wordpress-connector`.
That header does two things:

1. **Security.** WordPress matches installed plugins to the directory by folder
   slug. Without the header, anyone who registered `aivis-os` on wordpress.org
   could push their code to every site running this plugin. With it, WordPress
   never consults the directory for this plugin.
2. **Mechanism.** WordPress fires `update_plugins_github.com`, which
   `src/Update/GitHubReleases.php` answers from the releases API: if the latest
   tag is newer than the installed version, it offers the `aivis-os.zip` asset.
   A release without that asset is not an update. Results are cached 12 h.

**Rollback.** Install the previous release ZIP over the current one; the schema
is forward-only within a major version and data is kept.

---

## Appendix · Release decisions

| ID | Decision | Resolution |
|---|---|---|
| Q-01 | Production API base URL | **Resolved (2026-09-06)**: `https://app.aivis-os.com` is the shipped default (`Options::DEFAULT_API_BASE`). Dev/staging via the `AIVIS_API_BASE_URL` constant only |
| Q-02 | API token scope | **Resolved** (§00): account-scoped token, AIVIS-controlled installs only, until API-1 |
| Q-03 | Bundled cache adapters | **Open** — chosen from actual pilot infrastructure; core stays provider-neutral, manual purge always supported |
| Q-04 | Default freshness | **Resolved**: 15-minute polling default; 5 minutes for retraction-sensitive managed sites with reliable system cron |
| Q-05 | Minimum platforms | **Resolved**: WordPress 6.5–7.1, PHP 8.1–8.5 |
| Q-06 | Multisite | **Open** — per-site only if fully test-covered; otherwise block network activation in 1.0 |
| Q-07 | Distribution beyond GitHub | **Resolved for v1** (§19): GitHub Releases only. WordPress.org cannot be considered until API-1, since public distribution implies customer-managed installs |
| Q-08 | Public repo coordinates | **Open** — `aivis-wordpress-connector` under the org chosen in the shared edge decision |
