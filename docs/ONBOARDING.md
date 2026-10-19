# Deploying AIVIS OS on a WordPress site — onboarding

For anyone who puts the connector on a site: an agency, the customer's own developer, or the AIVIS team.
Thirty minutes end to end, most of it waiting for the first sync. Written from two real
deployments (marketos.ro, clinicaluxorolinic.ro) in September and October 2026; every pitfall below
happened at least once.

Longer references: [INSTALL.md](INSTALL.md) (every setting explained), [RUNBOOK.md](RUNBOOK.md)
(what to do when something breaks), [INDEX.md](INDEX.md) (everything else).

## What you are deploying

AIVIS generates structured data (JSON-LD) for a business's pages. The plugin pulls that data into the
WordPress site in the background and prints one `<script type="application/ld+json" data-aivis="1">`
in each page's head. Rendering never calls AIVIS, an AIVIS outage costs freshness, never uptime, and
the plugin never sends anything to AIVIS. It is a delivery method: decisions about *what* the data
says are made in AIVIS.

## Where to get everything

| You need | Where |
|---|---|
| The plugin | [Latest release](https://github.com/epoint-digital/aivis-wordpress-connector/releases/latest) → `aivis-os.zip` (and `SHA-256SUMS` to verify it). **Never** use the repository's *Code → Download ZIP*: that installs a second copy named `aivis-wordpress-connector-main` next to `aivis-os` and every admin screen shows "constant already defined" warnings |
| An API token | In AIVIS: profile → **API tokens** → create one **bound to this site's business**. Copy it when shown; it is shown once |
| The AIVIS instance | **Test** `aivis-new.dev.onepoint.ro` or **Production** `app.aivis-os.com`. A token works on one instance only. As of October 2026 production has no DNS record yet; every live site runs on Test |
| The business | In AIVIS, with its **base URL on exactly this site's domain** (`www.` or not does not matter). One business = one domain; the plugin refuses to bind anything else |
| A chain with JSON-LD | In AIVIS, open the business → chain → *Generate JSON-LD* step. It must show artifacts ("53 JSON-LD artifacts"). A chain whose JSON-LD step says *Stale* or *no json* on every URL has nothing to deliver |
| Help | [GitHub issues](https://github.com/epoint-digital/aivis-wordpress-connector/issues) for the plugin; the AIVIS team for anything inside AIVIS (chains, languages, generation) |

## Before you start: five checks

1. **WordPress 6.5+, PHP 8.1+, single site.** Multisite network activation is refused.
2. **The business's base URL in AIVIS is this site's domain.** Fix it there first; nothing binds otherwise.
3. **The chain has JSON-LD generated** (see the table). Pages marked *no json* in AIVIS will stay without structured data on the site; that is correct behaviour, not a plugin fault.
4. **The chain's language is set correctly in AIVIS.** The plugin assigns a chain to a WordPress language automatically only when they agree. A chain labelled `ab` when the site is Romanian (a real case) forces a manual assignment.
5. **Know the site's other plugins.** An SEO plugin that emits its own JSON-LD (Yoast, Rank Math, AIOSEO…) must have that output switched off, or the page carries two competing graphs. A page cache plugin (WP Super Cache, W3 Total Cache, LiteSpeed, WP Rocket) is detected and purged; anything else means manual purges.

## Step by step

### 1. Install

wp-admin: **Plugins → Add New → Upload Plugin** → `aivis-os.zip` → **Install Now** → **Activate**.
Activation makes no network request and needs no token.

With a shell on the server (not on your laptop), from the WordPress root:

```bash
wp plugin install https://github.com/epoint-digital/aivis-wordpress-connector/releases/latest/download/aivis-os.zip --activate
```

Check **Plugins**: exactly one AIVIS OS entry. Two means the *Download ZIP* mistake; delete the
`aivis-wordpress-connector-main` one.

### 2. Connect

**AIVIS OS → Settings.** It is one form.

1. **Environment**: choose the instance the business lives on (Test today).
2. **API token**: paste it. Better: put `define( 'AIVIS_API_TOKEN', 'aivis_…' );` in `wp-config.php` above the "stop editing" line; the field then disappears and the token never enters the database.
3. Click **Save & test connection**. It saves the page, then verifies the token. Read the chip:

| Chip | Meaning |
|---|---|
| *Connected — bound to \<business\>* | Right token, right instance. With 1.0.1 or newer the business is bound at once when its domain is this site |
| *Connected as \<email\>* | An account-wide token. Works, but reads every business on the account; prefer a bound one on customer sites |
| *Token rejected by \<host\>* | Wrong, revoked, or created on the other instance |
| *Could not reach \<host\>* | The environment, not the token. Production has no DNS yet; switch to Test and save again |
| *Plugin update required* | AIVIS requires a newer connector; update under Plugins |

### 3. Bind the business and assign the chain

The **Business** box shows the business whose domain is this site, matched automatically. If it says
*No business on this account uses \<host\>*, stop: fix the base URL in AIVIS. If two businesses use
the domain, pick the live one.

**Languages & chains** lists the chains. Each row needs a WordPress language in *Serves WordPress
language*, or it is not synced. The plugin pre-fills the choice when the chain's language in AIVIS
matches a site language; otherwise choose it yourself (the only language on a single-language site).
When a business has two chains for the same pages, assign the one that has JSON-LD artifacts and
leave the other unassigned. Click **Save changes**.

### 4. First sync

**AIVIS OS → Status → Sync now.** The first run fetches 20 pages per tick and continues by itself a
minute apart; click again if you are impatient. Then read the Connection box:

- **Business**: the business name, and **Domain** matched to the same host.
- **Languages**: `English → <chain>` (or whatever the site language is), not *no chain*.
- **Last complete sync**: a time, not a dash.
- **Next sync**: *in N minutes*. If it reads **manual only** while an interval is set under Settings, the recurring sync is missing: update to 1.0.1 or newer, which repairs it on the next page load, or re-save the interval once.
- **Injected**: the number of pages AIVIS has JSON-LD for.

### 5. Verify on the public site

Open a page that AIVIS has JSON-LD for and view the source (or `curl -s <url> | grep -c 'data-aivis="1"'`).
Expect exactly one `<script type="application/ld+json" data-aivis="1" data-aivis-hash="…">` in the
head and no other JSON-LD. If the block is missing but Status says injected, purge the page cache.

### 6. Switch off other structured data

The plugin never changes another plugin; it tells you where to switch its JSON-LD off.

- **Rank Math**: Rank Math → Dashboard → Modules → **Schema (Structured Data)** off. Titles, meta and sitemaps keep working.
- **Yoast SEO**: Yoast has no setting. Add a one-line snippet (Code Snippets, WPCode, or the theme):
  `add_filter( 'wpseo_json_ld_output', '__return_false' );`
- Then purge the cache and run **Settings → Structured data sources → Scan pages now**. The red
  *Other JSON-LD* chips clear once a scan no longer finds it.

### 7. Hand the status key to AIVIS

**Settings → Status for AIVIS** shows a status key. Enter it in AIVIS for this business; AIVIS then
fetches the site's publishing status from `/wp-json/aivis-os/v1/status`. The key reaches nothing but
that document and is not the API token.

### 8. Cron

WordPress's own cron runs only when the site gets traffic. For a 15-minute sync that matters on a
quiet site: point a system cron at `wp-cron.php` every five minutes. Site Health → **AIVIS OS: sync is
running** tells you when syncs are overdue.

## Done when

- [ ] Plugins shows one active **AIVIS OS**; Site Health has no critical *AIVIS OS:* test.
- [ ] Status: business bound, domain matched, every site language has a chain, *Last complete sync* set, *Next sync in …*.
- [ ] Injected count equals the pages AIVIS has JSON-LD for; the rest are *no json* in AIVIS.
- [ ] A public page carries exactly one JSON-LD block, the AIVIS one.
- [ ] The SEO plugin's schema output is off and a scan finds nothing else.
- [ ] The status key is entered in AIVIS.
- [ ] Cache: *Purge confirmed* or *requested*; if *Manual purge required*, the site owner knows.

## Updates

Updates come from GitHub Releases through the normal **Plugins** screen (the plugin carries an `Update
URI`, so wordpress.org is never consulted). Enable **auto-updates** for AIVIS OS there, or apply them
by hand. A site can follow release candidates with `define( 'AIVIS_OS_UPDATE_CHANNEL', 'prerelease' );`
in `wp-config.php`; leave that to pilot sites. The release lookup is cached for 12 hours per site.

## Troubleshooting, from real deployments

| Symptom | Cause | Do |
|---|---|---|
| Every screen says *Token invalid or revoked* (versions before 1.0.0) | Nested forms broke Save; the token was never stored | Update the plugin |
| *Connected* but Status says *not bound* for days | The first Save posts no business; the test alone did not bind (before 1.0.1) | Click **Save changes** once more, or update |
| *Next sync: manual only* although an interval is set | Recurring cron never scheduled on activation (before 1.0.1) | Update, or re-save the interval once |
| Admin screens rendered twice, "constant already defined" warnings | Two copies installed (`aivis-os` and `aivis-wordpress-connector-main`) | Delete the `-main` copy |
| Nothing injected, Languages says *no chain* | Chain language in AIVIS differs from the site language, so nothing was assigned | Assign the chain under Languages & chains |
| Many pages *no json* in AIVIS and missing on the site | AIVIS has not generated JSON-LD for them | Rerun the JSON-LD step in AIVIS; nothing to do in WordPress |
| Page shows two JSON-LD blocks | The SEO plugin still emits its own | Step 6 |
| Status says injected, page shows nothing | Cached HTML | Purge the page cache (and Cloudflare, if any) |
| *Could not reach app.aivis-os.com* | Production has no DNS record yet | Environment = Test |

## Reporting an installation

Report what you saw, not what you expected: site, instance, token kind, bound business, chains and
their assignment, Injected count, *Next sync*, cache result, SEO plugin state, and the public-page
check. The install skill's [checklist](../.claude/skills/install-aivis-os/reference/checklist.md) is
the template.
