# Runbook

Operational drills for AIVIS OS. Each one names the symptom, what the plugin
does on its own, what you check, and what you do. Run every drill on the pilot
site before 1.0.0 (M6).

Quick tools: `wp aivis status`, `wp aivis verify`, `wp aivis sync --all`,
`wp aivis refresh <url>`, and the diagnostics buffer on the Status screen
(stable codes, last 100 entries).

---

## AIVIS API outage

**Symptom.** Diagnostics fill with `AIVIS_HTTP_TIMEOUT` / `AIVIS_HTTP_ERROR`;
the Status screen shows the outage notice.

**Plugin behaviour.** Every page keeps serving its last known good artifact.
Nothing is deactivated, suspended or retired while the API is unreachable — a
404 without confirmed reachability is a hold, not a suspend. Syncs retry on the
next tick.

**Check.** `curl -sI https://<aivis-host>/api/public/v1/openapi.json`.

**Do.** Nothing on the WordPress side. When the API returns, the next sync
reconciles. If the outage exceeds a day, expect the first full run to take
several ticks.

## Invalid or revoked token

**Symptom.** `AIVIS_AUTH_401` in diagnostics; Settings shows *Token invalid or
revoked*; Site Health flags it.

**Plugin behaviour.** Syncing stops. Pages keep serving last known good.

**Do.** Create a new token in AIVIS, update `AIVIS_API_TOKEN` in `wp-config.php`
(or Settings), *Test connection*, then `wp aivis sync --all`. Revoke the old
token in AIVIS afterwards, not before.

## Malformed artifact from AIVIS

**Symptom.** `AIVIS_SCHEMA_INVALID` against a URL; the page shows *Holding last
good* with "Last response failed validation".

**Plugin behaviour.** The response is rejected before storage; the previous
artifact keeps serving (ZT-06). Nothing reaches the page.

**Do.** `wp aivis refresh <url>` to see the current error. Report the URL to
AIVIS with the code. No local action makes an invalid artifact valid.

## Artifact bound to the wrong business

**Symptom.** `AIVIS_SCOPE_MISMATCH` against a URL.

**Plugin behaviour.** Rejected, never stored. This is the check that stops a
second business sharing the same domain from cross-serving.

**Do.** Confirm the bound business on Settings is the live one. If the account
has a rebuild business for this domain, that is the usual cause.

## Delayed or dead cron

**Symptom.** Site Health: *syncs are overdue*; Status: last complete sync
well beyond the interval.

**Plugin behaviour.** Nothing changes until a tick runs. Pages keep serving.

**Do.** Set up the system cron in INSTALL.md. Meanwhile `wp aivis sync --all`.

## Failed or unconfirmed purge

**Symptom.** Purge chip shows *Purge failed* or *Manual purge required*;
`wp aivis verify` reports *stale-on-page* or *marker-missing*.

**Plugin behaviour.** Local data is current; the served page may not be. The
plugin will not call the page live.

**Do.** Purge the affected pages in your cache plugin (or all pages, once).
Re-run `wp aivis verify`. If this recurs, switch the cache adapter under
Settings or file a bug with the adapter name.

## Retraction (a page unpublished in AIVIS)

**Symptom.** Status shows one or more **Suspended** rows and the withdrawal
notice; `AIVIS_RETRACTED` in diagnostics.

**Plugin behaviour.** On the first lookup returning "URL not found in your
businesses" with confirmed reachability, injection is suspended and the page
purged immediately. The row is kept until the next complete, authoritative
sync confirms the URL is absent from inventory — then it is retired and kept
30 more days for rollback. If the URL reappears in inventory, the suspicion is
cleared and the page serves again.

**Check.** `curl -s "https://<aivis-host>/api/public/v1/jsonld?url=<url>" -H "Authorization: Bearer …"`
should return that exact message. If it returns "JSON-LD not generated yet",
the plugin is correctly *holding* instead — the page is being regenerated, not
withdrawn.

**Do.** Usually nothing. To force immediate confirmation: `wp aivis sync --all`.
Worst-case latency is the sync interval plus the cache purge; the Status
screen shows the figure.

## A language has no chain (or a chain has no language)

**Symptom.** Site Health: "AIVIS OS: *Language* has no chain" (critical); Status shows the
language in red; pages in that language carry no AIVIS block. Or: a sync reports *no chain
assigned to a language*, or Status marks a chain "not assigned — not synced".

**Cause.** AIVIS has one chain per language. The connector only syncs chains an administrator
has assigned to a WordPress language; assignment is automatic only when there is nothing to
decide (one site language, or a chain whose reported language matches exactly one site
language).

**Fix.** Settings → Languages & chains: pick the language for each chain, save. Or
`wp aivis languages assign <chain> <lang>`. If the language genuinely has no chain, create one
in AIVIS and sync. If AIVIS reports a chain's pages in a *different* language than assigned
(`AIVIS_LANGUAGE_MISMATCH`), check which side is wrong before changing anything — the
assignment is kept either way.

**Unassigning** a chain deactivates its rows and purges their caches immediately; the rows
retire after two authoritative syncs and are deleted 30 days later, unless re-assigned first.

## Rollback of a plugin release

**Symptom.** A new release misbehaves.

**Do.** Upload the previous `aivis-os.zip` over it (Plugins → Add New → Upload →
Replace). The table schema is forward-only within a major version; data is
kept. Then `wp aivis verify`.

## Rollback of a wrongly retired page

**Symptom.** A page was retired that should be live.

**Do.** Within 30 days: Status → the row → *Restore*, then
`wp aivis refresh <url>`. After 30 days the row is gone; the next sync will
re-create it if AIVIS still serves the URL.

## Suspected token leak

**Do, in order.** Revoke the token in AIVIS. Create a new one. Update
`wp-config.php`. Search the site's database, logs and any backups made since
the suspected leak for the old token prefix. The plugin never writes the token
to any of those, but other software may have.

## Disconnect a site

Settings → *Disconnect and remove local data*: empties the table, purges every
page, clears the business and token status. The `wp-config.php` constant, if
used, must be removed by hand.
