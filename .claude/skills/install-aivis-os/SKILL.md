---
name: install-aivis-os
description: Install, connect and verify the AIVIS OS WordPress plugin (slug aivis-os) on a site — with WP-CLI when a shell is available, otherwise by driving wp-admin in a browser. Use when asked to install, set up, connect, bind, verify or troubleshoot the AIVIS WordPress connector, assign chains to languages, or hand the status key to AIVIS.
---

# Install AIVIS OS on a WordPress site

The plugin syncs the JSON-LD that AIVIS generates for a business's pages into the site and prints it in each page's `<head>`. Rendering never calls AIVIS. Full operator guide: `docs/INSTALL.md` in the connector repository; failure drills: `docs/RUNBOOK.md`.

## Before you start — ask the human for these, in this order

1. **The site URL** and which kind of token the human has: a **business-bound** token (created in AIVIS for this site's business — reads nothing else; fine on any site) or an **account-wide** one (reads every business on the account; only on a site the account holder controls).
1a. **Which AIVIS instance** the site belongs to: Production (`app.aivis-os.com`) or Test (`aivis-new.dev.onepoint.ro`). It is a switch under Settings → Connection; a token works on one instance only, and switching later unbinds everything.
2. **Who logs in.** The human logs into wp-admin themselves. Never type a password, and never ask for one.
3. **The API token**, without ever seeing it in chat. Preferred: the human puts `define( 'AIVIS_API_TOKEN', 'aivis_…' );` in `wp-config.php` above the "stop editing" line before you begin. With a shell: the human exports it as an environment variable and you run `wp config set` from that variable. Only if neither is possible does it go into the Settings field — and then the human types it, not you.
4. **The business exists in AIVIS** with a `baseUrl` on exactly this site's domain. One business = one domain; the plugin refuses to bind anything else.
5. **Languages.** If the site is multilingual (WPML, Polylang, TranslatePress, Weglot), which AIVIS chain serves which language. Each chain is one language.

## Hard rules

- Never paste, echo, log or screenshot the API token or the status key into the conversation. Refer to them by where they are.
- Never click **Disconnect and remove local data**, **Regenerate** or **Disable** (status key) without an explicit go-ahead in the conversation.
- Never change other plugins (Yoast, Rank Math, …). The connector only warns about them; so do you.
- Never widen a language assignment to "make it work". A language without a chain gets nothing — say so.
- If the domain does not match any business, stop and report. Do not bind a business on another domain.

## Choose the path

| You have | Use |
|---|---|
| A shell on the server (SSH, container, `wp` available) | `reference/wp-cli-install.md` — fully headless, including binding and language assignment |
| Only a browser and a logged-in admin session | `reference/wp-admin-browser-guide.md` — every screen, field name and expected result |
| Both | WP-CLI for install and bind, the browser to show the human the Status screen |

## Get the plugin ZIP

- Released: download `aivis-os.zip` and `SHA-256SUMS` from the latest release of `epoint-digital/aivis-wordpress-connector` and verify with `shasum -a 256 -c SHA-256SUMS`.
- Not yet released, or the repository is private: clone it and run `./scripts/build-zip.sh dist` → `dist/aivis-os.zip` (needs `rsync`, `zip`, `shasum`; no PHP). Upload that file.

## Done means all of these are true

- [ ] Plugins screen shows **AIVIS OS** active; Site Health has no critical **AIVIS OS:** test.
- [ ] Settings shows *Connected — bound to <business>* (or *Connected as <email>* for an account-wide token) and the business **matched automatically** (or the human's explicit choice on a duplicate domain).
- [ ] Every site language has at least one chain under **Languages & chains**; unassigned chains are intentional.
- [ ] Status shows a completed sync (*Syncing* chip), a non-zero **Injected** count, and *Nothing needs you* — or the attention items are in the report with counts, not worked through.
- [ ] `view-source:` of a synced page contains `<script type="application/ld+json" data-aivis="1" data-aivis-hash="…">`, or `wp aivis verify` says *live*.
- [ ] Page cache: **Purge confirmed** or **Purge requested**; if **Manual purge required**, the human knows.
- [ ] The human has copied the **status key** from Settings → *Status for AIVIS* into AIVIS for this business (you do not handle the key).
- [ ] A real system cron hits `wp-cron.php`, or the human accepted traffic-driven WP-Cron.
- [ ] Status shows no **Moved pages**, or the human knows which pages moved and that AIVIS must re-crawl them (nothing to do in WordPress).

Report using `reference/checklist.md` — filled in, with what you saw, not what you expected.

## Quick troubleshooting

| Symptom | Do |
|---|---|
| "<host> rejected the token (invalid_token — …)" | Wrong, revoked, or issued on the other instance. Human recreates it on the selected instance and updates `wp-config.php`; click *Save & test connection* again |
| "Could not reach <host> (…)" | The environment, not the token: Production has no DNS record yet — switch Environment to Test and save again. If Test is unreachable too, the host blocks outbound requests: stop and report |
| "AIVIS requires a newer connector (minimum x.y.z)" | Stop. Report that the plugin must be updated before anything syncs |
| "No business on this account uses <host>" | Stop. The business's base URL in AIVIS must be this domain. Report |
| Two businesses match | Ask the human which is live; the list shows creation date and chain count |
| "<Language> — no chain" in red | Ask the human which chain serves it, or report that AIVIS has none |
| Sync says *no chain assigned to a language* | Assign under Languages & chains, then *Sync now* |
| Marker missing in view-source | Purge the page cache, then re-check; confirm the theme calls `wp_head()` |
| Site Health: syncs overdue | System cron is missing — see INSTALL.md "Cron" |
| Loopback verification "could not verify" | Not a failure; check a page in the browser instead |
