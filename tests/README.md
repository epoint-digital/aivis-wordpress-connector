# Tests

Two layers, deliberately separate. `npm test` runs both.

| Layer | Command | Count | Crosses the wire? |
|---|---|---|---|
| **Unit** | `npm run test:unit` | 65 | No — pure functions only |
| **Contract** | `npm run test:contract` | 27 | Yes — real HTTP to the mock |

## Unit tests

```bash
npm run test:unit
```

Every branch of `src/reference/connector-logic.mjs`: status classification,
the retraction decision rules, envelope validation (including the exact
depth-32 and 1-MiB boundaries, where off-by-ones live), binding, and local URL
normalisation. Instant, no server.

These pin the decisions that can silently pull structured data off a live
customer page — the class of bug that has no visible symptom until a client
asks why their rich results vanished.

## Contract suite

```bash
npm run test:contract
```

Boots the mock AIVIS API on an ephemeral port: API shape, pagination, the two
distinct 404s, the four-stage retraction scenario, and binding against real
responses. No network, no credentials.

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

## Continuous integration

`.github/workflows/ci.yml` runs the unit tests, the contract suite and the
documentation check on Node 20, 22 and 24 for every push and pull request. It
is hermetic — no network, no secrets — so a fork PR runs exactly what a
maintainer runs.

`.github/workflows/api-drift.yml` runs the drift check on a weekday schedule.
It reaches the live dev instance, so it is kept off the PR path where it would
only add flake.

## Layout

| Path | Purpose |
|---|---|
| `tests/fixtures/openapi-v1.json` | Vendored live contract — the source of truth the mock is built against |
| `tests/mock/server.js` | Mock AIVIS Public API v1, no dependencies |
| `tests/mock/data.js` | Fixtures: duplicate domains, multi-chain URLs, an ungenerated URL, a retraction subject |
| `tests/contract/run.mjs` | Contract suite — everything that crosses the wire |
| `tests/unit/run.mjs` | Unit tests for the decision rules |
| `tests/harness.mjs` | Shared assertions and reporting; no framework, no install step |
| `tests/docs/check-embedded-json.mjs` | Verifies `docs/api-requirements.html` is still machine-readable |
| `src/reference/connector-logic.mjs` | Executable reference for the decision rules the PHP plugin will implement |
