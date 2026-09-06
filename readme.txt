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
* Cache purges are confirmed before a page is reported as live. If your cache cannot confirm, the plugin says so instead of guessing.

= Distribution note =

This version is intended for sites operated or controlled by AIVIS. An AIVIS API token is account-scoped — it reads every business on the account — and a WordPress database travels through backups, staging clones and migrations. Keep the token in `wp-config.php` as `AIVIS_API_TOKEN`.

== Installation ==

1. Create an API token in AIVIS (profile → API tokens).
2. Add `define( 'AIVIS_API_TOKEN', 'aivis_…' );` to `wp-config.php`.
3. Upload and activate the plugin, or: `wp plugin install <release zip url> --activate`.
4. Under AIVIS OS → Settings, test the connection. The business whose domain matches this site is bound automatically.
5. Run a sync from the Status screen, or wait for the next cron tick.

See docs/INSTALL.md in the repository for the full guide, including system cron and cache plugins.

== Frequently Asked Questions ==

= Does this send visitor data anywhere? =

No. Nothing about visitors, crawlers, page views or users is sent to AIVIS. The only outbound traffic is the background sync to the AIVIS API.

= What happens if AIVIS is down? =

Pages keep serving their last known good structured data. Nothing is removed while the API is unreachable.

= How fast is a withdrawal? =

Worst case: your sync interval plus the time your cache takes to drop the page. The Status screen shows the real figure.

== Changelog ==

= 1.0.0 =
* Initial release. See CHANGELOG.md.
