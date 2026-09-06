# Contributing

Thanks for looking. This is a small, opinionated plugin with a written
specification; most contributions start by reading
[docs/SPECIFICATION.md](docs/SPECIFICATION.md) — the invariants in §02 are
non-negotiable, and a change that weakens one will be declined however clean
the code is.

## Set-up

No local PHP is required for the checks: `scripts/` runs everything through
Docker when it has to.

```bash
npm test                 # JS reference suites (unit, serializer fuzz, API contract)
./scripts/lint.sh        # php -l on every file
./scripts/phpunit.sh     # PHPUnit
composer phpcs           # WordPress coding standards (needs composer install)
```

## Rules of the road

- **The render path stays cold.** Nothing added to `wp_head` or the Injector may
  call the network, and it may make at most one indexed query (WP-I2).
- **Fail open.** Any exception on a public request is swallowed and the page
  renders (WP-I1). Never let a connector problem show on a customer's site.
- **The token is a secret.** Never echo, log, or include it in an error message.
  `Options::redact()` exists for the messages you cannot control.
- **A 404 is not a retraction.** Read §06 before touching anything in `Sync/`.
- **Every response shape comes from the vendored OpenAPI document.** Do not
  assume a field; add it to `tests/fixtures/openapi-v1.json` via
  `npm run vendor:openapi` and let the contract suite hold the mock to it.

## Pull requests

- One concern per PR, tied to an issue. Commit messages say *why*.
- Add or extend a test for behaviour you change — the reference suites in
  `tests/` and the PHP suite in `tests/php` are held to the same rules.
- Bump nothing. Versions are cut by maintainers with a tag; CI enforces that the
  header, `readme.txt`, `CHANGELOG.md` and the tag agree.

## Developer Certificate of Origin

By contributing you certify the [DCO](https://developercertificate.org/). Sign
commits with `git commit -s`.
