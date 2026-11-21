=== JSON-LD Delivery for Aivis-OS ===
Contributors: epointdigital
Tags: structured data, json-ld, schema, seo, ai
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publishes the JSON-LD that Aivis-OS has prepared for this domain. Sends only GET requests, carrying your API token. Collects no visitor data.

== Description ==

JSON-LD Delivery for Aivis-OS publishes the structured data (JSON-LD) that Aivis-OS has prepared for your domain, each block on the page it was made for. The plugin does not create structured data itself.

= What is Aivis-OS? =

Aivis-OS stands for Domain-based AI Source Integrity and is delivered as a Managed Architectural Service. Aivis-OS finds contradictions and gaps in the content of a domain, lets the company decide what applies, and uses these decisions to produce domain-based rather than URL-based structured data. This plugin is the delivery component for WordPress. Learn more at [aivis-os.com](https://aivis-os.com/en/).

= Who provides what =

* [Aivis-OS](https://aivis-os.com/en/): the method and the service, from clarifying the content of your domain to the structured data this plugin publishes.
* [epoint](https://epoint.at), Development & Technology Partner in the [Aivis-OS Network](https://aivis-os.com/en/network/): develops and maintains this plugin.

Questions about Aivis-OS: [aivis-os.com](https://aivis-os.com/en/). Bug reports and feature requests for the plugin: the [issue tracker](https://github.com/epoint-digital/aivis-wordpress-connector/issues).

= Features =

* One JSON-LD block per page, the same for every visitor and crawler, kept in step with your page cache. WP Super Cache, W3 Total Cache, LiteSpeed Cache and WP Rocket are purged when a block changes; with any other cache the plugin tells you that a manual purge is needed.
* A local copy, updated in the background by WP-Cron. Rendering a page never waits for Aivis-OS.
* When Aivis-OS withdraws the structured data for a page, the plugin removes it from that page.
* Other JSON-LD on the site, for example from Yoast SEO or Rank Math, is detected and reported, with where to switch it off. Two sources describing the same page can contradict each other. The plugin never changes another plugin.
* Moved pages: a changed address is detected and reported until Aivis-OS has read the new address.
* Multilingual sites (WPML, Polylang, TranslatePress, Weglot): one Aivis-OS chain per language. A language without a chain is flagged, never silently empty.
* Status and Pages screens, Site Health tests and WP-CLI commands.

= Data and security =

The plugin sends only GET requests, carrying your API token, to the Aivis-OS interface. It sends no visitor data, sets no cookies, adds no front-end JavaScript and changes nothing in Aivis-OS. Aivis-OS reads the delivery status of your site through a read-only endpoint protected by a key. It never writes to your site.

= Requirements =

WordPress 6.5 or later; PHP 8.1 or later; WP-Cron or a system cron. An Aivis-OS project for the site's domain, with an API token. To start a project, see [aivis-os.com](https://aivis-os.com/en/).

= Choosing a token =

Create the API token for this site's business: a business-bound token reads nothing else. An account-wide token reads every business on the account, and a WordPress database travels through backups, staging clones and migrations, so use one only on a site you control. Keep the token in `wp-config.php` as `AIVIS_API_TOKEN`.

= AI disclosure =

Most of this plugin's code, tests and documentation were written with an AI coding assistant (Claude Code) and reviewed by the maintainer.

== External services ==

This plugin connects to Aivis-OS, the service that prepares the structured data it publishes. Without an Aivis-OS project and an API token the plugin does nothing.

* What is sent: GET requests to the Aivis-OS interface. Each carries the API token you entered and a User-Agent naming the plugin version and the WordPress version. A request for one page also names that page's address on your own site. No visitor data and no personal data are sent.
* When: in the background on the sync interval you set under Settings, and when you press Save & test connection, Sync now or a page's re-check. If you switch on the on-demand option, an address Aivis-OS is not yet known to cover is also looked up in the background after it was viewed. Never while a visitor's page is being rendered.
* The other direction: Aivis-OS can read this site's delivery status (which pages carry which structured data, and since when) from a read-only endpoint protected by a key you issue under Settings. That document contains no visitor data and never the API token.

Aivis-OS: [privacy policy](https://aivis-os.com/en/privacy-policy/) and [legal notice](https://aivis-os.com/en/legal-notice/).

== Installation ==

1. Create an API token in Aivis-OS (profile → API tokens), bound to this site's business.
2. Add `define( 'AIVIS_API_TOKEN', 'aivis_…' );` to `wp-config.php`, or enter the token under Settings in step 4.
3. Upload and activate the plugin, or: `wp plugin install <release zip url> --activate`.
4. Under Aivis-OS → Settings, choose the environment (Production or Test) and click *Save & test connection*. The business whose domain matches this site is bound automatically; a failure names the environment and the reason.
5. On a multilingual site, assign each chain to the language it serves under Settings → Languages & chains. Single-language sites need nothing here.
6. Run a sync from the Status screen, or wait for the next cron run.
7. Switch off any other JSON-LD on the site (see the FAQ), then copy the status key from Settings → Status for Aivis-OS into Aivis-OS.

See docs/INSTALL.md in the repository for the full guide, including system cron and cache plugins.

== Frequently Asked Questions ==

= Does this send visitor data anywhere? =

No. Nothing is sent to Aivis-OS at all — the plugin only reads from the Aivis-OS API. Aivis-OS can fetch this site's publishing status (which pages carry which structured data, since when) from a read-only endpoint, with a key you issue under Settings. That document never contains visitor data or the API token.

= What happens if Aivis-OS is down? =

Pages keep serving their last known good structured data. Nothing is removed while the API is unreachable.

= How fast is a withdrawal? =

With an idle pipeline and no backlog: your sync interval plus the time your cache takes to drop the page. While a chain is rebuilding in Aivis-OS, up to two intervals. The Status screen shows the real figure and any backlog.

= Does it work on a multilingual site? =

Yes, with WPML, Polylang, TranslatePress or Weglot. Aivis-OS has one chain per language, so you assign each chain to a WordPress language under Settings → Languages & chains; a single-language site is assigned automatically. Language subdirectories and subdomains are supported. A separate domain per language needs its own Aivis-OS business and its own WordPress site.

= My SEO plugin already prints structured data. What now? =

Aivis-OS should be the only source of JSON-LD on a page. The plugin reports other sources on its Settings screen and says where to switch each one off; it never changes another plugin. Rank Math: Dashboard → Modules → switch off Schema (Structured Data). Yoast SEO has no setting for this; add `add_filter( 'wpseo_json_ld_output', '__return_false' );` with a code snippet plugin or in your theme. Titles, meta descriptions and sitemaps keep working in both.

== Changelog ==

= 1.1.0 =
* The plugin is now called JSON-LD Delivery for Aivis-OS, and the word mark is written Aivis-OS throughout.
* New: choose which Aivis-OS pipeline the site delivers structured data from, the standard one (V1) or the Universal Pipeline (V2), under Settings → Delivery. Switching removes nothing.
* An unpublished page loses its block before anything else happens in a sync.
* The two Aivis-OS environments are named Production and Test everywhere.

= 1.0.2 =
* Fixed: the Status screen was rendered twice.

= 1.0.1 =
* Fixed: the recurring sync was not scheduled on activation, and the business was bound only on the second save.

= 1.0.0 =
* Initial release. See CHANGELOG.md.
