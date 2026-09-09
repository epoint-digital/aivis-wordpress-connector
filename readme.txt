=== AIVIS OS ===
Contributors: epointdigital
Tags: structured data, json-ld, schema, seo, ai
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Delivers the structured data AIVIS generates for this site into its pages — synced locally, injected in the head, never fetched on a public request.

== Description ==

AIVIS OS connects a WordPress site to an AIVIS business and delivers the JSON-LD AIVIS generates for each page into that page's `<head>`.

* Structured data is synced in the background and stored locally. Rendering a page never calls AIVIS — an outage costs freshness, not availability.
* Exactly one `<script type="application/ld+json" data-aivis="1">` is added. Existing structured data from your theme or SEO plugin is never read or changed.
* Every artifact is validated, bound to this site's business and domain, and re-serialized before it is stored, so nothing AIVIS sends can become markup.
* When a page is unpublished in AIVIS, the block is withdrawn — and the plugin tells you honestly how long that takes.
* Cache purges are confirmed before a page is reported as live (WP Super Cache, W3 Total Cache, LiteSpeed Cache, WP Rocket). If your cache cannot confirm, the plugin says so instead of guessing.
* Multilingual sites (WPML, Polylang, TranslatePress, Weglot): one AIVIS chain per language, assigned under Settings. A language without a chain is flagged, never silently empty.
* Nothing is sent to AIVIS. The plugin keeps the status of publishing per page and serves it read-only, gated by a key you issue here, for AIVIS to fetch.

= Distribution note =

Create the API token for this business (a business-bound token reads nothing else) and the plugin may run on any site. An account-wide token reads every business on the account — and a WordPress database travels through backups, staging clones and migrations — so use one only on a site you control. Keep the token in `wp-config.php` as `AIVIS_API_TOKEN`.

== Installation ==

1. Create an API token in AIVIS (profile → API tokens) bound to this site's business.
2. Add `define( 'AIVIS_API_TOKEN', 'aivis_…' );` to `wp-config.php`.
3. Upload and activate the plugin, or: `wp plugin install <release zip url> --activate`.
4. Under AIVIS OS → Settings, choose the environment (Production or Test) and click *Save & test connection*. The business whose domain matches this site is bound automatically; a failure names the host and the reason.
5. On a multilingual site, assign each chain to the language it serves under Settings → Languages & chains. Single-language sites need nothing here.
6. Run a sync from the Status screen, or wait for the next cron tick.

See docs/INSTALL.md in the repository for the full guide, including system cron and cache plugins.

== Frequently Asked Questions ==

= Does this send visitor data anywhere? =

No. Nothing is sent to AIVIS at all — the plugin only reads from the AIVIS API. AIVIS can fetch this site's publishing status (which pages carry which structured data, since when) from a read-only endpoint, with a key you issue under Settings. That document never contains visitor data or the API token.

= What happens if AIVIS is down? =

Pages keep serving their last known good structured data. Nothing is removed while the API is unreachable.

= How fast is a withdrawal? =

With an idle pipeline and no backlog: your sync interval plus the time your cache takes to drop the page. While a chain is rebuilding in AIVIS, up to two intervals. The Status screen shows the real figure and any backlog.

= Does it work on a multilingual site? =

Yes, with WPML, Polylang, TranslatePress or Weglot. AIVIS has one chain per language, so you assign each chain to a WordPress language under Settings → Languages & chains; a single-language site is assigned automatically. Language subdirectories and subdomains are supported. A separate domain per language needs its own AIVIS business and its own WordPress site.

== Changelog ==

= 1.0.0 =
* Initial release. See CHANGELOG.md.
