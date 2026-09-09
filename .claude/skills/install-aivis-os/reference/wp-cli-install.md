# Headless install with WP-CLI

Run from the WordPress root, or add `--path=/var/www/html` to every `wp` call. Everything below is idempotent; re-running is safe.

## 1. Preconditions

```bash
wp core version                       # ≥ 6.5
wp eval 'echo PHP_VERSION, PHP_EOL;'   # ≥ 8.1
wp core is-installed --network && echo "MULTISITE: install per site, never network-activate" || true
wp option get home                    # this is the domain the AIVIS business must use
```

## 2. Token — from the environment, never from the conversation

The human exports it in the shell you are using (you never see the value):

```bash
# human runs: export AIVIS_API_TOKEN='aivis_…'
test -n "$AIVIS_API_TOKEN" || { echo "AIVIS_API_TOKEN is not set in this shell"; exit 1; }
wp config set AIVIS_API_TOKEN "$AIVIS_API_TOKEN" --type=constant
unset AIVIS_API_TOKEN
```

Choose the AIVIS instance before connecting — a token works on one instance only:

```bash
wp aivis environment            # shows the current one (production by default)
wp aivis environment test       # aivis-new.dev.onepoint.ro — pilots run here while production has no DNS
```

A custom host can still be fixed in `wp-config.php`; it overrides and disables the switch:

```bash
wp config set AIVIS_API_BASE_URL 'https://aivis-new.dev.onepoint.ro' --type=constant
```

## 3. Install and activate

```bash
# from a release:
gh release download --repo epoint-digital/aivis-wordpress-connector --pattern 'aivis-os.zip' --pattern 'SHA-256SUMS' -D /tmp/aivis && (cd /tmp/aivis && shasum -a 256 -c SHA-256SUMS)
# or from source while unreleased/private:
git clone git@github.com:epoint-digital/aivis-wordpress-connector.git /tmp/aivis-src && (cd /tmp/aivis-src && ./scripts/build-zip.sh /tmp/aivis)

wp plugin install /tmp/aivis/aivis-os.zip --activate --force
wp plugin list --name=aivis-os --fields=name,status,version
```

## 4. Connect and bind

```bash
wp aivis connection test          # "Connected to <host> (contract 1.9.0, minimum client 1.0.0). Token … — bound to business …" (or "account-wide as <email>")
wp aivis bind                     # binds the one business whose domain matches; lists candidates if several
# wp aivis bind --business=<id>   # only when more than one business uses this domain and the human said which
```

`bind` refuses a business on another domain. If it reports *No business on this account uses <host>*, stop and report: the business's base URL in AIVIS must be this domain.

## 5. Languages

```bash
wp aivis languages                # provider, site languages, chains, what AIVIS reports, what serves what
wp aivis languages auto           # assigns where unambiguous; lists what is left to decide
wp aivis languages assign <chainId> <lang>   # per the human's answer; "none" unassigns
```

Every site language needs at least one chain. A warning `<Language> has no chain — nothing is injected on its pages` is a decision for the human.

## 6. Sync and verify

```bash
wp aivis sync --all               # ticks until nothing is pending (20 artifacts per tick)
wp aivis status                   # counts, last sync, cache adapter, languages, moved pages
wp aivis pages --state=attention  # the pages that want a decision (same views/filters/search as the Pages screen)
wp aivis verify                   # fetches one page over loopback: expect "live"
wp aivis verify --url=https://example.com/some-page/
curl -s https://example.com/ | grep -c 'data-aivis="1"'    # expect 1 on a synced page
```

`verify` answering *could-not-verify* means loopback is blocked on this host, not that the block is missing — check with `curl` from outside or in a browser.

## 7. Cron

WP-Cron runs only on traffic. For anything better than "eventually":

```bash
wp config set DISABLE_WP_CRON true --raw --type=constant
( crontab -l 2>/dev/null; echo "*/5 * * * * cd $(pwd) && wp cron event run --due-now >/dev/null 2>&1" ) | crontab -
wp cron event list | grep aivis_os
```

## 8. Status key for AIVIS

```bash
wp aivis status-key               # prints the endpoint and the key
```

Tell the human to enter the key in AIVIS for this business. Do not copy it into the conversation. `wp aivis status-key regenerate` invalidates the old key at once — only on request.

## 9. Report

`wp aivis status --format=json` is the same document AIVIS fetches; attach it (it contains no secrets) together with the filled `checklist.md`.
