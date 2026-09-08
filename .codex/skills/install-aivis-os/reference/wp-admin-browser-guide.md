# Driving wp-admin in a browser to install AIVIS OS

For an agent with browser tools (a page reader, click, type, screenshot). Written against WordPress 6.5–7.1 and plugin 1.0.0. Element ids and names below come from the plugin's own templates; WordPress core screens are named by their visible labels, which are stable across these versions.

Reference screenshots of the three screens (rendered from the prototype that mirrors the plugin): `docs/screenshots/status.png`, `docs/screenshots/pages.png`, `docs/screenshots/settings.png` in the connector repository. Compare what you see against them; a screen that looks different is a finding.

Conventions: `→` means click or navigate; *italics* are visible labels; `code` is an element id, name or URL. After every step, read the page (accessibility tree or text) before deciding the next click — do not chain clicks blind. Take a screenshot at each ✱ for the report.

## 0. Preconditions the human handles

- The human is logged into wp-admin in this browser session. If you land on `/wp-login.php`, stop and ask them to log in. Never type credentials.
- The API token is in `wp-config.php` as `AIVIS_API_TOKEN` (preferred). If the human insists on the Settings field, they type it in step 4, not you.

## 1. Check the site can run the plugin

`→ <site>/wp-admin/site-health.php?tab=debug` → expand *Server* and *WordPress*.

- PHP version ≥ 8.1, WordPress ≥ 6.5. Below either: stop and report.
- Multisite: if *WordPress → Multisite* is *Yes*, install and activate **per site**, never network-wide (network activation is refused).
- Note the page cache in use under *Active plugins* (WP Super Cache, W3 Total Cache, LiteSpeed Cache and WP Rocket are supported; anything else means manual purges; Cloudflare is detected but never purged).

## 2. Upload and activate

`→ <site>/wp-admin/plugin-install.php?tab=upload`

1. File input `#pluginzip` → choose `aivis-os.zip`.
2. `#install-plugin-submit` (*Install Now*).
3. On the result page → *Activate Plugin*. If WordPress offers *Replace current with uploaded* (an upgrade), take it, then activate from the Plugins list.
4. ✱ `→ <site>/wp-admin/plugins.php` — confirm **AIVIS OS** is listed as active with *Version 1.0.0*. The menu now shows **AIVIS OS** (network icon) near the bottom.

Activation makes no network request and needs no token. If the site is not on HTTPS you will see nothing special yet, but the connector requires HTTPS to talk to AIVIS.

## 3. Open Settings

`→ <site>/wp-admin/admin.php?page=aivis-os-settings`

The page has these boxes in order: **Connection**, **Business**, **Languages & chains**, **Delivery**, **Structured data sources**, **Status for AIVIS**, **Page cache**. One *Save changes* button at the bottom submits all of them.

### Connection

- If `wp-config.php` defines the token you see `AIVIS_API_TOKEN is defined in wp-config.php` with a green *Recommended* chip. Good.
- Otherwise there is a password field `#aivis_token`. **The human types the token.** Then *Save changes*, then come back.
- Click *Test connection* (a small form; button label *Test connection*). Expected: a green chip *Connected as <email>*. A red *Token invalid or revoked* means a wrong or revoked token — ask the human to fix it; do not retry in a loop.
- The yellow note *What this token can reach* is informational: the token reads every business on the account. Mention it in the report.

### Business

One of three states:

- *Matched automatically* (green chip) with the business name: nothing to choose. A hidden field `business_id` is already set.
- Radio buttons named `business_id`, one per business on this domain, each with creation date and chain count: **ask the human which one is live** before selecting. Do not guess.
- *No business on this account uses <host>*: **stop**. Report that the business's base URL in AIVIS must be this domain. Businesses on other domains are deliberately not offered.

Field `#aivis_hosts` (`allowed_hosts`): leave empty unless the human names alias hosts. The site's own host and `www` twin are always included; language subdomains are added automatically.

### Languages & chains

Text at the top says which languages the site publishes in and which plugin manages them (*WordPress core (one language)* if none).

- Single-language site: usually nothing to do; chains are assigned automatically on the first sync. If the table is already there, every row's select `chain_lang[<chainId>]` should show the site language.
- Multilingual site: the table lists each chain with *AIVIS reports* (the language AIVIS sees on its pages) and a select *Serves WordPress language*. Set each chain to the language the human named. A select left on *— not assigned (not synced) —* means that chain is skipped. Rows marked *suggested from what AIVIS reports — save to confirm* are pre-filled guesses; confirm them with the human when the language plugin and AIVIS disagree.
- Below the table: green chips *<Language> — N chains*, or a red chip *<Language> — no chain: nothing is injected on these pages*. A red chip is a decision for the human, not something to paper over.
- If the box says *AIVIS could not be reached*, wait a minute and reload; do not save.

### Delivery

- Checkbox `injection` (*Add AIVIS structured data to pages on this site*): on.
- Checkbox `on_demand`: leave off unless asked.
- Select `#aivis_interval` (`interval`): *Every 15 minutes (recommended)*. Choose *Every 5 minutes* only if the human confirmed a real system cron.

### Structured data sources

Read-only list. If another emitter is listed (Yoast, Rank Math, …), note it for the report with the *How to switch it off there* text. **Do not go and change that plugin.** Publishing continues either way. There is no email setting: who gets told is AIVIS's decision.

### Status for AIVIS

Shows the endpoint URL and the **status key** (`aivis_status_…`). Tell the human: "Copy the status key from this box into AIVIS for this business." Do not read it out, do not paste it anywhere. Do not click *Regenerate* or *Disable*.

### Page cache

Select `#aivis_cache` (`cache_adapter`): leave on *Detect automatically (currently: …)*. Note which adapter it detected.

### Save

`→` *Save changes*. Expected notice: *Settings saved.* If the notice is *Token invalid or revoked* after a business save, the domain check refused the binding — report it.

## 4. First sync

`→ <site>/wp-admin/admin.php?page=aivis-os` (Status). The plugin has three tabs: **Status** (overview, nothing to read), **Pages** (the list: paginated, searchable, filterable — `admin.php?page=aivis-os-pages`) and **Settings**.

1. In the **Needs attention** box header → *Sync now*. Expected notice: *Sync run finished.*
2. Read the **Connection** box: *Business* named, *Domain* `<host>` matched to `<host>`, *Languages* line with each language → its chains, *Last complete sync* a moment ago, *Next sync* in ≤ 15 min.
3. Read the tiles: **Injected** should be > 0 after the first complete run on a small site. On a large site the first run takes several ticks (20 artifacts per tick, continuing every minute); wait and reload rather than clicking *Sync now* repeatedly. Each tile links to the **Pages** screen filtered to that state.
3a. Read the **Needs attention** box. *Nothing needs you* is the goal. Every listed item links to the filtered Pages view; put the counts in the report, do not work through the list.
4. The **Languages** box: every language with chains and counts; a red *no chain* means step 3 is incomplete.
5. The **Pipelines** box: each chain with its language; *not assigned — not synced* is a warning.
5a. A **Moved pages** box appears only when pages changed address since AIVIS crawled them. Read it, put it in the report, change nothing — AIVIS re-crawls.
6. ✱ Screenshot the Status page for the report.

If *Sync now* results in *Last sync incomplete* or a notice about no chain assigned, go back to Languages & chains.

## 5. Verify on the public site

On the **Pages** screen, search for a page (search box, top right) that shows *Active*; open it in a new tab, as `view-source:<url>` (or read the page HTML with your tool). Look for exactly one:

```html
<script type="application/ld+json" data-aivis="1" data-aivis-hash="…">{…}</script>
```

Any post or term edit screen also shows a read-only **AIVIS OS** box with the same facts for that object.

Also run the plugin's own check: `→ <site>/wp-admin/site-health.php` → *Status* tab → look for tests labelled **AIVIS OS:** — token custody, sync is running, cache purge is confirmable, storage, single source of structured data, every language has a chain. All should be green or *recommended*; any *critical* one is a finding.

If the marker is missing on the public page but Status says *Active*: the page cache has not dropped the page. Check the **Pages** box header chip — *Purge confirmed*, *Purge requested, not confirmed* (LiteSpeed; usually live within seconds) or *Manual purge required* (purge in the cache plugin, or ask the human).

## 6. Hand-offs to the human

- Copy the **status key** into AIVIS (step 3, Status for AIVIS).
- Set up a **system cron** if Site Health says syncs are overdue or if the 5-minute interval is wanted: INSTALL.md → *Cron*.
- Decide on any **other structured-data emitters** flagged red.
- Decide on any **language without a chain**.

## What never to click

*Disconnect and remove local data* (purges everything and empties the table), *Regenerate* / *Disable* under Status for AIVIS, *Disable here* on a page row, *Override* on the conflict warning — unless the human asked for exactly that in this conversation.

## URL map

| Screen | URL |
|---|---|
| Upload plugin | `/wp-admin/plugin-install.php?tab=upload` |
| Plugins list | `/wp-admin/plugins.php` |
| AIVIS OS → Status | `/wp-admin/admin.php?page=aivis-os` |
| AIVIS OS → Pages (list; `&state=attention` etc.) | `/wp-admin/admin.php?page=aivis-os-pages` |
| AIVIS OS → Settings | `/wp-admin/admin.php?page=aivis-os-settings` |
| Site Health status / info | `/wp-admin/site-health.php` · `/wp-admin/site-health.php?tab=debug` |
| Status document (needs the key; for AIVIS, not for you) | `/wp-json/aivis-os/v1/status` |
