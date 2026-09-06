# Installing AIVIS OS

This guide is for the people who operate a site — in v1 that means AIVIS or a
partner acting for AIVIS (see *Who may install this* below). Fifteen minutes,
most of it waiting for the first sync.

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
caches (WP Super Cache, W3 Total Cache, LiteSpeed Cache).

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

## 5. First sync

AIVIS OS → **Status** → *Sync now*, or wait for the next cron tick, or:

```bash
wp aivis sync --all
```

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

## Other SEO plugins

AIVIS is the primary source of structured data. If Yoast, Rank Math, All in One
SEO, SEOPress or similar also emit JSON-LD, the plugin flags it in red — two
`Organization` nodes on one page give search engines conflicting answers.

AIVIS OS → **Settings → Structured data sources** lists what was found and says,
per plugin, where to switch that output off. The connector never changes another
plugin itself. Publishing continues either way; *Override* on the warning keeps
it quiet until the set of conflicts changes. Logged-in admins also see the
warning in the admin bar on the front end, and the site admin is emailed when
the set changes.

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
| Pages say *Holding last good* | AIVIS has not generated that page yet; nothing is wrong on this side |
| A page shows *Suspended* | It was unpublished in AIVIS; injection stopped, cache purged, awaiting confirmation |
| Marker missing in view-source | Cache not purged, or the theme does not call `wp_head()` |
| Sync overdue in Site Health | Set up the system cron above |

`wp aivis status` prints all of this in one screen, and the runbook
([RUNBOOK.md](RUNBOOK.md)) has the drills for each failure.
