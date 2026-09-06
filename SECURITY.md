# Security

## Reporting

Please report vulnerabilities privately to **security@epoint.ro**. Do not open a
public issue. You will get an acknowledgement within 72 hours and a fix or a
plan within 14 days for anything affecting installed sites.

## Scope worth knowing

- **Stored XSS via structured data** is the failure mode this plugin is designed
  against. Every artifact is re-serialized with `JSON_HEX_TAG | JSON_HEX_AMP |
  JSON_HEX_APOS | JSON_HEX_QUOT` before storage, and the injector refuses to
  print anything containing `<`, `>`, `&` or `'`. A bypass of that is a
  critical report.
- **Token exposure.** The AIVIS token is account-scoped. If you find it in HTML,
  a log, a REST response, Site Health output or a diagnostics message, that is
  a high-severity report even without a further exploit.
- **Update channel.** The plugin declares `Update URI` and only accepts the
  `aivis-os.zip` asset from this repository's GitHub Releases. A way to make an
  installed site fetch an update from anywhere else is critical.
- **Cross-business data.** An artifact whose `businessId` differs from the bound
  business must never be stored (ZT-03). If you can get one onto a page, report it.

## Not in scope

Findings that require an administrator account on the WordPress site, or that
concern the AIVIS platform itself (report those to AIVIS directly).
