# Installing AIVIS OS

This guide is for the people who operate a site — in v1 that means AIVIS or a
partner acting for AIVIS (see *Who may install this* below). Fifteen minutes,
most of it waiting for the first sync.

## Let an agent do it

Claude Code and Codex can run this guide: the repository ships an agent skill,
`install-aivis-os` (in `.claude/skills/` and `.codex/skills/`), that installs
headless with WP-CLI or drives wp-admin in a browser, with the field names of
every screen and a report checklist. The human still logs in and places the
token; the agent never sees it. `wp aivis bind` does the business binding and
language assignment without the Settings screen.

## Who may install this

**v1 ships to AIVIS-controlled or AIVIS-operated sites only.** An AIVIS API
token is account-scoped: it can read structured data for *every* business on
the account, not just this site's. A WordPress database is copied into backups,
staging clones and migrations. Until the platform offers business-scoped tokens
(tracked as API-1), do not install this on a site whose database you do not
control.

## Requirements

| | Minimum | Tested |
|---|---|---|
| WordPress | 6.5 | 6.5, 6.9, 7.1 |
| PHP | 8.1 | 8.1, 8.3, 8.5 |
| Database | MySQL 5.7+ / MariaDB 10.3+ | |
| Theme | calls `wp_head()` | every standard theme does |
| Site type | single site | multisite network activation is refused in 1.0 |

Strongly recommended: a real system cron (below) and one of the supported page
caches (WP Super Cache, W3 Total Cache, LiteSpeed Cache, WP Rocket). Cloudflare
in front of the site is detected and named; the connector cannot purge it.

## 1. Create an API token in AIVIS

In AIVIS, open your profile → **API tokens** → create one named for this site
(e.g. `wordpress-example.com`). Copy it now; it is shown once.

## 2. Put the token in `wp-config.php`

Above the `/* That's all, stop editing! */` line:

```php
define( 'AIVIS_API_TOKEN', 'aivis_…' );
```

This keeps the token out of the database. The plugin will accept a token
entered in Settings instead, but says so on every screen and in Site Health.

The plugin talks to `https://app.aivis-os.com`. For a staging site that should
use the AIVIS dev instance instead, add — and remove again before go-live:

```php
define( 'AIVIS_API_BASE_URL', 'https://aivis-new.dev.onepoint.ro' );
```

The base URL is deliberately not a setting in the admin: anything typed there
would receive the bearer token.

## 3. Install the plugin

**From a release ZIP** (Plugins → Add New → Upload):

Download `aivis-os.zip` from the
[latest release](https://github.com/epoint-digital/aivis-wordpress-connector/releases/latest)
and verify it against `SHA-256SUMS` from the same release:

```bash
shasum -a 256 -c SHA-256SUMS
```

**Or with WP-CLI:**

```bash
wp plugin install https://github.com/epoint-digital/aivis-wordpress-connector/releases/latest/download/aivis-os.zip --activate
```

Activation creates one table (`wp_aivis_jsonld`) and schedules the sync. It
makes no network request and needs no token.

## 4. Connect and bind the business

AIVIS OS → **Settings** → *Test connection*. On success the business whose
domain matches this site is bound automatically.

If **two** businesses on the account share this domain (a live one and a
rebuild, typically), the plugin will not guess — pick the right one. If **none**
matches, the plugin refuses to bind: create the business for this domain in
AIVIS, or correct its base URL there. Businesses on other domains are never
offered.

## 4a. Assign chains to languages

AIVIS OS has no multilingual model: **each chain is one language** (intents
and forensic prompts are bound per language). The connector therefore expects
one chain per WordPress language, and syncs only chains that are assigned to
one.

On a **single-language site** with matching chains, this happens by itself on
the first sync — nothing to do. Otherwise open AIVIS OS → **Settings →
Languages & chains**: every chain of the business is listed with the language
AIVIS reports for its pages; pick the WordPress language each chain serves and
save. A language without a chain gets **no structured data**, and the plugin
says so in red on Settings, on Status and in Site Health. From the command line:

```bash
wp aivis languages
wp aivis languages assign chain_en en
```

See *Multilingual sites* below for what the plugin detects and what it does not
support.

## 5. First sync

AIVIS OS → **Status** → *Sync now*, or wait for the next cron tick, or:

```bash
wp aivis sync --all
```

Headless installs bind first with `wp aivis bind` (the one business on this
domain; `--business=<id>` when two share it) — it also assigns chains to
languages where that is unambiguous.

The Status screen shows every page: **Active**, **Stale but served**, **Holding
last good**, **Suspended**, **Retired** — with a legend. The first run on a
large site takes a few ticks (20 artifacts per tick, by design).

## 6. Verify

```bash
wp aivis verify
```

fetches a page over loopback and confirms the block is present *and current*.
Or view source on any synced page and look for
`<script type="application/ld+json" data-aivis="1">`. If the marker is missing,
the usual cause is the page cache — see below.

## Cron

WordPress's built-in cron only runs when someone visits the site. For anything
better than "eventually", point a system cron at it and disable the built-in one:

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```

```cron
*/5 * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron > /dev/null
# or: */5 * * * * wp --path=/var/www/html cron event run --due-now
```

The **5-minute** sync interval requires this; the plugin says so when you pick it.

## Page caches

When structured data changes, the plugin purges the affected pages through
your cache plugin and reports one of:

| Chip | Meaning |
|---|---|
| **Purge confirmed** | WP Super Cache / W3 Total Cache confirmed the purge. The page is live. |
| **Purge requested, not confirmed** | LiteSpeed accepted the request but cannot confirm. Usually live within seconds. |
| **Manual purge required** | No supported cache detected. Purge yourself after a change. |

The plugin never claims a page is live without confirmation. If you use a
different cache, the purge is your responsibility, and Site Health will remind you.

## Multilingual sites

WordPress core knows one locale per site and has no multilingual content model
(that is Gutenberg phase 4, 2027 or later), so languages come from a plugin.
The connector reads them from whichever is active, in this order:

| System | What the plugin reads | URL schemes it handles |
|---|---|---|
| **WPML** | active languages, the default, each language's home URL, the post's language | directory (`/de/`), subdomain (`de.example.com`), `?lang=` |
| **Polylang** | the language list, the default, home URLs, the post's language | directory, subdomain, `?lang=` |
| **TranslatePress** | published languages and URL slugs from its settings | directory |
| **Weglot** | original and destination languages | directory |
| **none** | the site locale — one language | — |

Anything else can be wired in with the `aivis_connector_site_languages` and
`aivis_connector_url_language` filters.

What holds regardless of plugin:

- **One chain per language, assigned by you** (step 4a). A chain may be assigned to one language;
  a language may have several chains.
- **Language subdomains are fine** — they are added to the allowed hosts automatically.
- **A separate domain per language is not supported.** An AIVIS business has one domain, so
  `example.de` and `example.fr` are two businesses — and two WordPress sites (or two sites of a
  multisite, each with its own plugin activation).
- If AIVIS reports pages of a chain in a language other than the one you assigned, the plugin
  keeps your assignment and flags the disagreement on Status and in Site Health.
- AIVIS finds a chain's pages by following links or by manual entry; there is no link between a
  page and its translation. Each page is matched by its exact URL.

## Status for AIVIS

The plugin **never sends anything to AIVIS**. It keeps the status of publishing
here — per page: what is published, with which content, since when, and when
this site last saw it on the page — and AIVIS can **fetch** it:

```
GET https://example.com/wp-json/aivis-os/v1/status
GET https://example.com/wp-json/aivis-os/v1/status/urls?cursor=&limit=200
Authorization: Bearer aivis_status_…
```

The key is issued by this site on activation and shown under AIVIS OS →
**Settings → Status for AIVIS** (also `wp aivis status-key`). Enter it in AIVIS
for this business. It is not the API token: it reaches nothing but the status
document, which names this site's pages and their publishing state and never
contains the token. *Regenerate* invalidates the old key at once; *Disable*
makes the endpoint answer 404.

`wp aivis status --format=json` prints the same document.

## Other SEO plugins

AIVIS is the primary source of structured data. If Yoast, Rank Math, All in One
SEO, SEOPress or similar also emit JSON-LD, the plugin flags it in red — two
`Organization` nodes on one page give search engines conflicting answers.

AIVIS OS → **Settings → Structured data sources** lists what was found and says,
per plugin, where to switch that output off. The connector never changes another
plugin itself. Publishing continues either way; *Override* on the warning keeps
it quiet until the set of conflicts changes. Logged-in admins also see the
warning in the admin bar on the front end. Nobody is emailed: who gets told is
AIVIS's decision, and AIVIS sees the conflicts when it fetches the status
document.

## Moved pages

Once a day the plugin compares each page's current address with the URL AIVIS
crawled. Pages that moved (a slug or parent changed, a post was trashed) are
listed on Status under **Moved pages**, flagged in Site Health, and included in
the status document AIVIS fetches. Nothing else happens: the structured data
stays with the URL AIVIS has, and the new address receives nothing until AIVIS
re-crawls. That is deliberate — the plugin delivers, AIVIS decides.

Each post and term edit screen has a read-only **AIVIS OS** box with the same
facts for that object.

## Staging and live

A staging copy of the site cannot bind to the live business: the domain rule
refuses it. The supported workflow is: staging talks to the AIVIS dev instance
(`AIVIS_API_BASE_URL`) and its own business; live syncs fresh from production.
Nothing is migrated. When a staging database is pushed to live, run
*Disconnect and remove local data* (or `wp aivis sync --all` after the first
live sync retires the staging rows) — do not carry staging rows across.

## Upgrading

Updates arrive through the normal Plugins screen — the plugin checks this
repository's GitHub Releases, never wordpress.org. Each release ships with
`SHA-256SUMS`; WordPress verifies the download it is given.

## Rolling back

Install the previous release ZIP over the current one (Plugins → Add New →
Upload → *Replace current with uploaded*). Synced data is kept; nothing is
re-fetched.

## Disconnecting and uninstalling

Settings → *Disconnect and remove local data* purges every page and empties the
table. Deleting the plugin removes the table and every option **unless** *Keep
data on uninstall* is ticked — the token is removed either way.

## Troubleshooting

| Symptom | Look at |
|---|---|
| "Token invalid or revoked" | Recreate the token in AIVIS; update `wp-config.php` |
| No business matches | The business's base URL in AIVIS must be this site's domain |
| "… has no chain" in Site Health | Assign a chain to that language under Settings → Languages & chains, or create one in AIVIS |
| Sync says *no chain assigned to a language* | Same — nothing syncs until at least one chain is assigned |
| A chain shows *AIVIS reports N pages as en* | The chain's pages are not in the language you assigned; check the assignment, or the chain in AIVIS |
| AIVIS cannot fetch the status (401 / 404) | 401: the key in AIVIS is not this site's current key — copy it from Settings → Status for AIVIS. 404: the endpoint is disabled; issue a key |
| Pages say *Holding last good* | AIVIS has not generated that page yet; nothing is wrong on this side |
| A page shows *Suspended* | It was unpublished in AIVIS; injection stopped, cache purged, awaiting confirmation |
| Marker missing in view-source | Cache not purged, or the theme does not call `wp_head()` |
| Sync overdue in Site Health | Set up the system cron above |

`wp aivis status` prints all of this in one screen, and the runbook
([RUNBOOK.md](RUNBOOK.md)) has the drills for each failure.
