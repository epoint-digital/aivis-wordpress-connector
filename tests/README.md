# Tests

## Contract suite

```bash
npm test
```

Boots the mock AIVIS API on an ephemeral port and runs every assertion — API
shape, the two distinct 404s, the retraction scenario, binding, envelope
validation, and local URL normalisation. No network, no credentials.

## Against the live API

```bash
export AIVIS_API_BASE=https://aivis-new.dev.onepoint.ro
export AIVIS_API_TOKEN=aivis_…        # from your AIVIS profile → API tokens
npm run test:live
```

Runs only the assertions that are safe against real data; anything needing
controlled fixtures (the retraction scenario, the duplicate-domain case) is
skipped and reported as such.

**Never paste a token into a commit, an issue, or a chat.** Put it in your
shell environment or a gitignored `.env` — the token is account-scoped and
grants read access to every business on the account (see §13 of the spec).

## Drift alarm

```bash
npm run vendor:openapi          # re-vendor and report surface changes
node tests/fixtures/refresh.mjs --check   # non-zero exit on any change (CI)
```

The previous specification went four weeks unverified against an API that had
meanwhile shipped. This is the check that would have caught it.

## Layout

| Path | Purpose |
|---|---|
| `tests/fixtures/openapi-v1.json` | Vendored live contract — the source of truth the mock is built against |
| `tests/mock/server.js` | Mock AIVIS Public API v1, no dependencies |
| `tests/mock/data.js` | Fixtures: duplicate domains, multi-chain URLs, an ungenerated URL, a retraction subject |
| `tests/contract/run.mjs` | The suite |
| `src/reference/connector-logic.mjs` | Executable reference for the decision rules the PHP plugin will implement |
