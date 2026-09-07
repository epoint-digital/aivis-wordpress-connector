# AIVIS Public API — extension requirements

**Audience:** the AIVIS platform team (`epoint-digital/aivis`)
**Requested by:** the AIVIS WordPress Connector (`aivis-wordpress-connector`) and, where noted, the
Cloudflare Edge Connector
**Status:** requirements, not designs — each item states the need and a proposed contract, and the
platform team owns the final shape
**Verified against:** `origin/main` @ `e96b89c` (2026-08-11) and the live OpenAPI at
`https://aivis-new.dev.onepoint.ro/api/public/v1/openapi.json`, both read 2026-09-05

---

## Summary

The Public API v1 (#142) is a good fit for what the connector does — the endpoints, the envelope,
the cursor pagination and the trailing-slash-tolerant lookup all match what a CMS plugin needs, and
`lib/public-api/lookup.ts` was clearly written with this plugin in mind. The connector is being
built against it as-is.

Two gaps change what the connector can *promise* to a customer, and the rest are efficiency:

| Req | Ask | Priority |
|---|---|---|
| **API-1** | Business-scoped read-only tokens | **Blocker** for customer-managed installs |
| **API-2** | Explicit retraction signal | **High** — retraction is currently inference-only |
| **API-3** | Stable machine-readable error `code` | **High**, and the cheapest item here |
| **API-4** | Conditional requests (`ETag` / `If-None-Match`) | Medium |
| **API-5** | Per-URL inventory lookup | Medium |
| **API-6** | Change feed / push | Medium |
| **API-7** | Documented rate limits + `Retry-After` | Low |
| **API-8** | Server-side URL normalization | Low, and contested — see the note |
| **API-9** | Fetch the connector's publishing status (site-served, key-gated) | Medium — AIVIS-side fetcher; the connector pushes nothing |
| **API-10** | `languageCode` on the chain resource | Medium — makes chain → language assignment exact instead of sampled |
| **API-11** | Versioning and compatibility policy | **High** — nothing else in this list can ship safely without it |

---

## API-1 — Business-scoped read-only tokens

**Priority: blocker for customer-managed installs.**

### Current behaviour

`ApiToken` (`db/schema.prisma:285`) carries only `userId`:

```prisma
model ApiToken {
  id          String    @id @default(uuid())
  userId      String
  name        String
  tokenHash   String    @unique
  prefixLast4 String
  createdAt   DateTime  @default(now())
  lastUsedAt  DateTime?
}
```

`requireApiToken()` resolves a token to a user, and every route scopes by `userId` — the lookup
filter in `app/api/public/v1/jsonld/route.ts` is `chain: { business: { userId: auth.userId } }`.
The tokens UI advises "create one per website or integration so you can revoke them", but that is
a convention for revocability; nothing enforces it.

### Why it matters

A WordPress plugin stores its token in the site's database. That database is copied into every
backup, every staging clone, every migration, and is readable by anyone with admin access to the
site or its host. Today, one such token grants **read access to every business on the account** —
so an agency account with 30 client businesses exposes all 30 through any one client's WordPress
install.

The connector therefore ships v1 restricted to AIVIS-controlled or AIVIS-operated installs, with
the token preferentially held in `wp-config.php` rather than the database. Customer-managed
distribution stays blocked until this lands.

### Proposed contract

A token bound to one business at creation:

- `ApiToken.businessId String?` — null keeps today's account-wide behaviour for existing tokens.
- When set, `/businesses` returns only that business; `/businesses/{id}/chains`,
  `/chains/{id}/urls`, `/urls/{id}/jsonld` and `/jsonld?url=` resolve only within it, returning 404
  for anything outside exactly as they do for another user's data today.
- Permission flags, as already designed for the edge connector's V-02: `jsonld:read` and
  `events:write`. The WordPress connector needs only `jsonld:read`.
- `GET /me` reports the binding so an integration can show the admin what its token can reach.

Existing unbound tokens must keep working — this is additive.

---

## API-2 — Explicit retraction signal

**Priority: high.**

### Current behaviour

There is no way to withdraw a published JSON-LD artifact and have a consumer learn that it was
withdrawn rather than merely absent. `UrlJsonLdArtifact` has `staleAt` — which means "inputs
changed, regenerate", not "withdrawn" — and no `suppressedAt`, publication state, or tombstone.
Withdrawal today is a hard delete of the `Url` row, which cascades to the artifact
(`onDelete: Cascade`) and leaves no trace.

### Why it matters

Retraction is the inverse of publishing, and it is the operation with real consequences: a wrong
`Organization` node, a stale price, an incorrect medical or legal claim shipped into structured data
that AI crawlers ingest. "Publish a correction" is not equivalent — the customer needs the block
*gone*.

The connector currently infers withdrawal from a combination of the 404 body (API-3) and an
authoritative inventory pass showing the URL absent or `jsonLd.ready = false`. That works, but it
is inference over two signals, and its worst-case latency is a full sync interval.

### Proposed contract

Preferred, and already sketched as the edge connector's V-08:

- `UrlJsonLdArtifact.suppressedAt DateTime?`, set by a per-URL "Unpublish" action (plus a
  chain-level bulk action). Reversible, keeps the audit trail; the existing delete remains for true
  deletion.
- While suppressed, `/jsonld?url=` and `/urls/{id}/jsonld` return **410 Gone** — distinguishable
  from both 404 cases — with `code: "withdrawn"` per API-3.
- `/chains/{id}/urls` reports it on the inventory row, e.g. `jsonLd.suppressedAt`, so a sync pass
  sees it without a per-URL fetch.

A 410 is what lets a consumer act immediately and safely. Without it, every consumer must build the
same two-signal inference, and each will get it slightly wrong.

---

## API-3 — Stable machine-readable error code

**Priority: high. Cheapest item in this document.**

### Current behaviour

The route already distinguishes the two 404 causes — `app/api/public/v1/jsonld/route.ts`:

```ts
if (candidates.length === 0) {
  throw new NotFoundError("URL not found in your businesses");
}
…
if (withArtifacts.length === 0) {
  throw new NotFoundError("JSON-LD not generated yet for this URL");
}
```

This distinction is exactly what a consumer needs, and it is genuinely useful. But it is only
available as English prose in `{"error":{"message":"…"}}`, and the published OpenAPI description
flattens both into one line: *"URL not found in your businesses, or JSON-LD not generated yet."*

### Why it matters

The two cases demand opposite responses. "URL not found" on a URL that previously had an artifact
means the row is gone — evidence of withdrawal. "JSON-LD not generated yet" means the page is still
there and only the artifact is missing: regeneration in flight, a covering intent that left the
`deployment` phase, or an `is_editable = false` page. Deactivating on the second would pull live
structured data off a customer's site during ordinary regeneration.

The connector currently string-matches the message and falls back to the conservative branch when it
does not recognise the text. That is fragile by construction: any copy edit silently changes plugin
behaviour on customer sites.

### Proposed contract

Add a stable `code` beside the human-readable message:

```json
{ "error": { "code": "jsonld_not_generated", "message": "JSON-LD not generated yet for this URL" } }
```

Initial vocabulary: `url_not_found`, `jsonld_not_generated`, `withdrawn` (with API-2),
`invalid_token`, `account_deactivated`, `bad_request`. Codes are contract and versioned with the
API; messages stay free to change. Documenting them in the OpenAPI responses would also correct the
description that currently conflates the two 404s.

---

## API-4 — Conditional requests

**Priority: medium.**

No `ETag`, `Last-Modified`, `If-None-Match` or `Cache-Control` is emitted on any endpoint. Every
poll transfers the full payload, so a site with a few thousand URLs at a 5-minute interval re-reads
everything it already has, forever.

**Proposed:** `ETag` on `/jsonld` and `/urls/{id}/jsonld` (the artifact's `updatedAt` plus a payload
hash is sufficient), honouring `If-None-Match` with `304 Not Modified`. On the list endpoints, an
`ETag` per page keyed on the newest `updatedAt` in that page. This is what makes a 5-minute
freshness target affordable rather than merely possible.

---

## API-5 — Per-URL inventory lookup

**Priority: medium.**

Inventory state (`jsonLd.ready`, `jsonLd.stale`, `jsonLd.generatedAt`, `captureStatus`) is available
only by paginating a whole chain via `/chains/{id}/urls`. To check freshness for **one** URL — which
is what a "Refresh this page now" button in the WordPress admin does — the plugin must walk the
entire chain, or fetch the artifact itself and discard it.

**Proposed:** either a `?url=` filter on `/chains/{id}/urls`, or the inventory fields on a
`GET /urls/{id}` response. Either removes an O(chain) walk from an O(1) operation.

---

## API-6 — Change feed

**Priority: medium.**

There is no way to ask "what changed since X". Sync is a full inventory walk every interval, which
sets the practical polling floor and scales with catalogue size rather than with change rate.

**Proposed, in ascending order of effort:**

1. An `updatedSince` query parameter on `/chains/{id}/urls`, returning only rows whose artifact or
   capture state changed after that timestamp. Cheap, and removes most of the waste.
2. The HMAC-signed outbound refresh ping already sketched as the edge connector's V-03: AIVIS calls
   a consumer-registered endpoint after a generate or retract. This is what would make retraction
   near-instant on WordPress, where — unlike the edge worker — there is no CDN purge hook to
   piggyback on.

---

## API-7 — Documented rate limits

**Priority: low.**

No rate limiting exists in the codebase and none is documented, so an integration has no budget to
design against. The connector self-throttles to 20 artifact requests per job — a guess, chosen to be
obviously polite rather than because it matches anything.

**Proposed:** document the intended per-token limits, and when limits are introduced, return `429`
with `Retry-After`. The connector already implements `429` + `Retry-After` handling defensively, so
it will honour them the day they appear.

---

## API-8 — Server-side URL normalization

**Priority: low, and deliberately contested.**

`lib/public-api/lookup.ts` matches exact plus a trailing-slash variant, and says why:

> Anything beyond that (scheme swaps, host case, query strings) stays an exact-match miss on purpose
> — silently matching the wrong page would be worse than a 404.

**We think that judgement is right**, and this item is filed for completeness rather than as a
request to change it. The edge connector's V-07 asked for full server-side normalization so worker
and API could never disagree about identity; on reflection the failure mode it prevents (a missing
block) is much safer than the one it introduces (the wrong page's structured data on a page).

What would help without giving that up: **host-level equivalence only** — treating `example.com` and
`www.example.com` as the same host when the business's `baseUrl` establishes which is canonical.
That is the one variant that arises from ordinary WordPress configuration rather than from a
genuinely different page. Scheme, case and query-string handling should stay strict.

---

## API-9 — Fetch the connector's publishing status

**Priority: medium.** *Rewritten 2026-09-06 — the connector never pushes.*

AIVIS has no visibility into connected sites: whether they sync, which plugin
version they run, whether their cache purges confirm, what is actually
published on which page, and whether **other plugins are also emitting
structured data** on the same pages. The connector knows all of that and keeps
it in the plugin. **AIVIS fetches it** — the connector sends nothing.

**What the site serves** (WordPress REST, read-only, GET only, key-gated):

```
GET {baseUrl}/wp-json/aivis-os/v1/status
GET {baseUrl}/wp-json/aivis-os/v1/status/urls?cursor=<id>&limit=<n≤500>
Authorization: Bearer aivis_status_…
```

```json
{ "connector": "aivis-os", "version": "1.0.0", "schema": 1,
  "site": "example.com", "businessId": "…", "generatedAt": "…",
  "sync":  { "lastCompleteAt": "…", "authoritative": true, "intervalSec": 900, "nextAt": "…",
             "counts": { "active": 312, "stale": 4, "hold": 2, "suspended": 0, "retired": 1, "total": 319 } },
  "delivery": { "injection": true, "lastVerified": { "result": "live", "url": "…", "at": "…" },
                "moved": [ { "url": "https://example.com/old/", "currentUrl": "https://example.com/new/", "since": "…" } ] },
  "cache": { "adapter": "wp-super-cache", "lastPurge": "confirmed", "purgedAt": "…" },
  "languages": { "provider": "wpml", "site": ["de","en"], "chains": { "chain_de": "de", "chain_en": "en" }, "mismatch": {} },
  "conflicts": { "fingerprint": "…", "acknowledged": false, "scannedAt": "…", "pagesScanned": 10,
                 "activePlugins": ["yoast"],
                 "items": [ { "url": "https://example.com/", "sources": ["yoast"], "types": ["Organization","WebSite"], "blocks": 1 } ] },
  "urls": "https://example.com/wp-json/aivis-os/v1/status/urls" }
```

Per page (`/status/urls`, paged by row id):

```json
{ "items": [ { "url": "https://example.com/services/", "currentUrl": null, "objectType": "post", "objectId": "42",
               "urlId": "u_services", "chainId": "chain_de",
               "languageCode": "de", "state": "published", "contentHash": "sha256…",
               "generatedAt": "…", "publishedAt": "…", "lastSyncedAt": "…",
               "verifiedAt": "…", "verifiedHash": "sha256…", "errorCode": null } ],
  "nextCursor": "412", "hasMore": true }
```

`state` ∈ `published | stale | holding | suspended | retired | inactive`. `objectType`/`objectId`
name the WordPress object the URL resolved to; `currentUrl` is set when that object's address
differs from the URL AIVIS has (the page moved — AIVIS decides what to do). `publishedAt` is when
what the page serves last changed; `verifiedAt` / `verifiedHash` is when the site last fetched
the page over loopback and found those bytes in it. `contentHash` is the SHA-256 of the exact
bytes injected, so AIVIS can compare it with its own artifact. **Nothing about people.**

**What AIVIS needs to add**

1. Per business, a field for the site's **status key** (the admin copies it from the plugin's
   Settings screen). No discovery step: the endpoint path is fixed under the business's
   `baseUrl`.
2. A fetcher — on demand from the business screen, and/or on a schedule AIVIS chooses — that
   reads `/status` and, when wanted, walks `/status/urls`, and stores the result.
3. Optionally, compare `contentHash` per `urlId` with the current artifact to show "published /
   behind / not yet" per page.

**Why pull rather than push.** Nothing about the site is emitted on a schedule the site does not
control; a switched-off site simply stops answering; AIVIS decides when to look; and the
connector's outbound traffic is reads of the Public API and nothing else — which is what the
WordPress spec's WP-I9 now says literally.

**Unlocks:** a "connected sites" view per business (last seen, version, sync health, per-page
publication), and the product conversation with a customer whose Yoast is fighting AIVIS for the
same `Organization` node.

## API-10 — Language on the chain resource

### Current behaviour

A chain is one language in AIVIS — intents and forensic prompts are bound per language — but the
API exposes language only per **URL** row (`languageCode` on `/chains/{id}/urls` and on the
artifact envelope). `GET /businesses/{id}/chains` items carry `id`, `name`, `description`,
`state`, `currentStep`, `knowledgeGraphReady`, `graphScore`, `urlCount`, `createdAt` — no
language.

### Why it matters

The WordPress connector assigns each chain to one WordPress language (SPECIFICATION §07a) and
syncs only assigned chains. Without a chain-level language it must **sample inventory**
(`/chains/{id}/urls?limit=5`, one request per chain) to suggest an assignment, cannot tell a
genuinely mixed chain from a mislabelled one, and can only *report* a disagreement between the
admin's assignment and what the rows say. With API-10:

- automatic assignment becomes exact (chain language == site language) instead of a guess from
  five rows;
- Site Health can flag "chain X is `en` but assigned to Deutsch" authoritatively;
- one request per site instead of one per chain on the Settings screen.

### Proposed contract

```json
GET /api/public/v1/businesses/{id}/chains
{ "items": [ { "id": "chain_de", "name": "Deutsch", "languageCode": "de", "…": "…" } ] }
```

`languageCode`: ISO 639-1, optionally with a region subtag (`pt-BR`). Additive — the connector
ignores unknown fields today, so this ships without a version bump.

### Answered (2026-09-06)

AIVIS collects a chain's URLs by following links or by manual entry. There is no host or
subdomain concept and **no correlation between the URLs of different languages**. The connector
therefore matches every page by its exact URL; language subdomains are an allowed-hosts matter on
the WordPress side only, and a separate domain per language is a separate business and a separate
site.

---

## API-11 — Versioning and compatibility policy

**Priority: high.** Every other item in this document changes the API; this one says how such
changes reach a plugin installed on sites nobody redeploys.

### Current behaviour

The API is path-versioned (`/api/public/v1`) and the OpenAPI document says `info.version: 1.0.0`.
Nothing else exists: no written compatibility policy, no deprecation signalling, no changelog, no
way for the API to tell a client it is too old. And the published document declares
`additionalProperties: false` on **every** object — so even an additive field, which API-1 and
API-10 assume is harmless, technically breaks the contract as written.

### Why it matters

The connector pins v1 in the path, tolerates fields it does not know, and is strict on the eight
envelope fields (ZT-02). If a response stops validating, it holds last-known-good and records
`AIVIS_SCHEMA_INVALID` — pages stay correct, but the failure is silent toward AIVIS and visible only
in the plugin's diagnostics. Its only early warning is a nightly CI job comparing the live
`openapi.json` with the vendored copy. There is no runtime version negotiation at all. A plugin
version on a customer site can stay in service for years; the API must be able to say, in-band,
"this client is too old" before something breaks, and "this endpoint is going away" before it goes.

### Proposed contract

1. **Additive within `/v1`.** New endpoints, new optional or nullable fields, new query parameters.
   Breaking changes — removed or retyped fields, changed semantics, changed matching rules — go to
   `/v2`, with v1 kept running in parallel for a stated period (six months is the usual floor).
2. **Say what "compatible" means for strict validators.** State that consumers must tolerate unknown
   fields, and either drop `additionalProperties: false` from the published schema or accept that
   validators alarm on additive changes. Either is fine; undefined is not.
3. **Enums.** Decide whether new values for `state`, `layer` and `captureStatus` count as breaking.
   The connector tolerates unknown values at runtime; its contract suite does not. Declaring them
   open (a documented "other values may appear") settles it.
4. **Deprecation signalling.** `Deprecation` and `Sunset` response headers (RFC 9745 / RFC 8594) on
   endpoints and versions being retired, with a `Link rel="deprecation"` to the notice.
5. **A visible version.** Bump `info.version` on every change, publish a changelog next to the
   OpenAPI document, and echo the version in a response header (`X-Aivis-Api-Version`).
6. **Minimum client.** AIVIS already sees `aivis-os/1.0.0 (WordPress/7.1; …)` in the user agent.
   A response header naming the minimum supported connector version (`X-Aivis-Min-Client: 1.3.0`)
   — and, once a version is actually unsupported, `426 Upgrade Required` with the API-3 code
   `client_too_old` — lets the plugin show "update required" in Site Health before the break.

### What the connector does in the meantime

Path-pinned v1; unknown fields ignored; strict on required fields; last-known-good on validation
failure; nightly drift job. A runtime check that reads the version and minimum-client headers and
surfaces them in Site Health is planned on the connector side and lands the day the headers exist.

---

## What the connector does in the meantime

- **API-10:** samples the first five inventory rows of each chain for a language hint (cached
  15 minutes), assigns automatically only when unambiguous, and reports — never acts on — a
  disagreement between AIVIS's per-URL `languageCode` and the admin's assignment.

For the record, so the platform team can see what is being worked around rather than waited on:

| Gap | Interim behaviour |
|---|---|
| API-1 | v1 restricted to AIVIS-controlled/operated installs. Token preferentially in `wp-config.php`, not the DB. Mandatory `businessId` equality check on every artifact, plus `chainId` membership when inventory context exists |
| API-2 | Retraction inferred from the 404 body plus an authoritative inventory pass; worst-case latency one sync interval |
| API-3 | Defensive string-match on the 404 message, conservative fallback on anything unrecognised |
| API-4 | Full-payload polling at a 15-minute default |
| API-5 | Chain walk for single-URL refresh |
| API-6 | Interval polling only |
| API-7 | Self-imposed 20 artifact requests per job |
| API-8 | Outbound lookups send the site permalink unmodified; normalization is applied only to the plugin's local index key |
| API-9 | The status document is already served (`/wp-json/aivis-os/v1/status`, key-gated). Until AIVIS fetches it, conflicts are flagged inside WordPress only |
| API-11 | v1 pinned in the path; unknown fields tolerated; strict on required fields; last-known-good on validation failure; nightly OpenAPI drift job. No runtime version negotiation yet |
