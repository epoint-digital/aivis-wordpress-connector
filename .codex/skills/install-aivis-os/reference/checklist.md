# AIVIS OS install report

Fill every line with what you observed. "Expected" is not an observation.

| Item | Observed |
|---|---|
| Site | `https://` |
| WordPress / PHP | |
| Multisite | yes / no (per-site activation) |
| Plugin version active | |
| Environment | Production / Test (or fixed by `AIVIS_API_BASE_URL`) |
| Token location | `wp-config.php` constant / database option |
| Connection test | Connected — bound to … / Connected as … (account-wide) / failed: <host> — <reason> |
| Business bound | name, matched automatically / chosen by human / refused because … |
| Language provider | WPML / Polylang / TranslatePress / Weglot / core |
| Chains → languages | chain → lang, … ; unassigned: … |
| Languages without a chain | none / … (human decision pending) |
| First sync | complete at …, authoritative yes/no, injected N, holding N, suspended N |
| Public page check | `view-source:` marker present on … / missing because … |
| Site Health AIVIS OS tests | all good / recommended: … / critical: … |
| Page cache | adapter …, last purge confirmed / requested / manual |
| Other structured-data emitters | none / … (guidance shown to human) |
| Moved pages | none / N listed on Status (AIVIS to re-crawl) |
| Status key | human copied it into AIVIS: yes / pending |
| Cron | system cron every 5 min / WP-Cron only (accepted by human) |
| Open items for the human | |
