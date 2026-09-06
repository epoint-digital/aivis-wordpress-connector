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
| **API-9** | Connector status report (conflicts, sync health) | Medium — the only monitoring that respects WP-I9 |

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

## API-9 — Connector status report

**Priority: medium.**

AIVIS has no visibility into connected sites: whether they sync, which plugin
version they run, whether their cache purges confirm, and — the one that matters
for the product — whether **other plugins are also emitting structured data**
on the same pages. The connector detects that (§09a: AIVIS is the primary
source; Yoast, Rank Math and friends are flagged red) and needs somewhere to
send it.

**Proposed contract**

```
POST /api/public/v1/businesses/{businessId}/connector-status
Authorization: Bearer aivis_…
Content-Type: application/json

{ "connector": "aivis-os", "version": "1.0.0", "site": "example.com",
  "businessId": "…", "trigger": "sync" | "conflicts", "reportedAt": "…",
  "sync":  { "lastCompleteAt": "…", "authoritative": true, "intervalSec": 900,
             "counts": { "active": 312, "stale": 4, "hold": 2, "suspended": 0, "retired": 1, "total": 319 } },
  "cache": { "adapter": "wp-super-cache", "lastPurge": "confirmed" },
  "conflicts": { "fingerprint": "…", "acknowledged": false, "pagesScanned": 10,
                 "activePlugins": ["yoast"], "suppressed": [],
                 "items": [ { "url": "https://example.com/", "sources": ["yoast"],
                              "types": ["Organization","WebSite"], "blocks": 1 } ] } }

202 Accepted
```

Sent after each authoritative sync and whenever the conflict set changes.
**Nothing about people** — no visitor, crawler, IP, user-agent or WordPress
user data; WP-I9 is amended to say exactly that, and the report is opt-out in
the plugin settings.

**Until it ships:** the connector already sends it; a 404/405 marks the endpoint
unavailable for 24 hours and nothing else changes. The client is contract-tested
against the mock.

**Unlocks:** a "connected sites" view per business (last seen, version, sync
health), and the product conversation with a customer whose Yoast is fighting
AIVIS for the same `Organization` node.

## What the connector does in the meantime

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
| API-9 | Report sent anyway; 404 backs off for a day. Conflicts still flagged inside WordPress and emailed to the site admin |
