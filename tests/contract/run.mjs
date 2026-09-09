// Contract suite: the AIVIS Public API over real HTTP.
//
//   node tests/contract/run.mjs                 # against the built-in mock
//   AIVIS_API_BASE=https://aivis-new.dev.onepoint.ro \
//   AIVIS_API_TOKEN=aivis_… node tests/contract/run.mjs --live
//
// Pure logic lives in tests/unit — everything here crosses the wire. In --live
// mode, assertions that need controlled fixtures are skipped and reported.

import { createServer, API_VERSION, MIN_CLIENT } from '../mock/server.js';
import { VALID_TOKEN, BOUND_TOKEN } from '../mock/data.js';
import { validateEnvelope, checkBinding, classifyLookup, decideFromLookup, decideFromInventory, Action, CODE }
  from '../../src/reference/connector-logic.mjs';
import { test, assert, eq, heading, finish } from '../harness.mjs';
import { loadSpec, conform, specOperations, validate } from './openapi.mjs';

const LIVE = process.argv.includes('--live');
const FIXTURE = { skip: LIVE, skipReason: 'needs fixture data' };
let base, token, server;

if (LIVE) {
  base = (process.env.AIVIS_API_BASE || '').replace(/\/$/, '') + '/api/public/v1';
  token = process.env.AIVIS_API_TOKEN;
  if (!process.env.AIVIS_API_BASE || !token) {
    console.error('--live needs AIVIS_API_BASE and AIVIS_API_TOKEN in the environment.');
    process.exit(2);
  }
} else {
  server = createServer();
  await new Promise(r => server.listen(0, '127.0.0.1', r));
  base = `http://127.0.0.1:${server.address().port}/api/public/v1`;
  token = VALID_TOKEN;
}

const SPEC = loadSpec();
const observed = [];          // every response this run saw, with its verdict
const unchecked = [];         // responses the document declares nothing for
const UA = 'aivis-os/1.0.0 (contract-suite)';

async function api(path, { auth = token, ...opts } = {}) {
  const res = await fetch(base + path, {
    ...opts,
    headers: { accept: 'application/json', 'user-agent': UA, ...(auth ? { authorization: `Bearer ${auth}` } : {}), ...(opts.headers || {}) },
    redirect: 'manual',
  });
  let body = null;
  try { body = await res.json(); } catch {}
  // Every response in this suite is checked against the vendored document, not
  // only the ones a test remembers to look at.
  const v = conform(SPEC, 'GET', path, res.status, body);
  const where = `GET ${path.split('?')[0]} -> ${res.status}`;
  if (!v.checked) unchecked.push(`${where}: ${v.errors.join('; ') || 'not in the document'}`);
  observed.push({ where, errors: v.errors });
  const headers = {};
  res.headers.forEach((val, key) => { headers[key] = val; });
  return { status: res.status, body, headers };
}
const control = async params => { if (!LIVE) await fetch(`${base.replace('/api/public/v1', '')}/__scenario?${params}`, { method: 'POST' }); };
const scenario = stage => control(`stage=${stage}`);
const q = u => '/jsonld?url=' + encodeURIComponent(u);
const coded = (r) => r.body && r.body.error && r.body.error.code;

console.log(`\nAIVIS connector — contract suite (${LIVE ? 'LIVE: ' + base : 'mock'})\n`);

/* ── Authentication ───────────────────────────────────────────────────── */
heading('authentication');
await test('401 missing_token with no Authorization header', async () => {
  const r = await api('/me', { auth: null });
  assert(r.status === 401, `status ${r.status}`);
  eq(coded(r), 'missing_token', 'code');
});
await test('401 invalid_token for a token without the aivis_ prefix', async () => {
  const r = await api('/me', { auth: 'not-an-aivis-token' });
  assert(r.status === 401, `status ${r.status}`);
  eq(coded(r), 'invalid_token', 'code');
});
await test('401 invalid_token for a well-formed but unknown token', async () => {
  const r = await api('/me', { auth: 'aivis_' + 'x'.repeat(43) });
  assert(r.status === 401, `status ${r.status}`);
  eq(coded(r), 'invalid_token', 'code');
});
await test('/me returns tokenName, businessId, business and permissions', async () => {
  const r = await api('/me');
  assert(r.status === 200, `status ${r.status}`);
  for (const f of ['email', 'name', 'tokenName', 'businessId', 'business', 'permissions']) assert(f in r.body, `missing ${f}`);
  assert(Array.isArray(r.body.permissions), 'permissions is not an array');
  return `token "${r.body.tokenName}" — ${r.body.businessId ? 'bound to ' + r.body.businessId : 'account-wide'}`;
});
await test('an account-wide token has an email and no business', async () => {
  const r = await api('/me');
  eq(r.body.businessId, null, 'businessId');
  eq(r.body.business, null, 'business');
  assert(typeof r.body.email === 'string', 'email');
}, FIXTURE);
await test('a business-bound token names its business and hides the account (API-1)', async () => {
  const r = await api('/me', { auth: BOUND_TOKEN });
  eq(r.status, 200);
  eq(r.body.email, null, 'email is null on a bound token');
  eq(r.body.businessId, 'biz_live', 'businessId');
  eq(r.body.business.baseUrl, 'https://example.com', 'business.baseUrl');
  assert(r.body.permissions.includes('jsonld:read'), 'permissions');
}, FIXTURE);
await test('a bound token sees one business and 404s outside it', async () => {
  const list = await api('/businesses', { auth: BOUND_TOKEN });
  eq(list.body.total, 1, 'businesses visible');
  eq(list.body.items[0].id, 'biz_live');
  const other = await api('/businesses/biz_other/chains', { auth: BOUND_TOKEN });
  eq(other.status, 404); eq(coded(other), 'business_not_found');
  const chain = await api('/chains/chain_other/urls', { auth: BOUND_TOKEN });
  eq(chain.status, 404); eq(coded(chain), 'chain_not_found');
  const art = await api('/urls/u_other/jsonld', { auth: BOUND_TOKEN });
  eq(art.status, 404); eq(coded(art), 'url_not_found');
  const lookup = await api(q('https://andere-domain.de/'), { auth: BOUND_TOKEN });
  eq(lookup.status, 404); eq(coded(lookup), 'url_not_found');
}, FIXTURE);

/* ── Compatibility policy (API-11) ────────────────────────────────────── */
heading('compatibility');
await test('every response carries X-Aivis-Api-Version and X-Aivis-Min-Client', async () => {
  const ok = await api('/me');
  const bad = await api('/me', { auth: null });
  for (const r of [ok, bad]) {
    assert(/^\d+\.\d+\.\d+$/.test(r.headers['x-aivis-api-version'] || ''), `api version header: ${r.headers['x-aivis-api-version']}`);
    assert(/^\d+\.\d+\.\d+$/.test(r.headers['x-aivis-min-client'] || ''), `min client header: ${r.headers['x-aivis-min-client']}`);
  }
  eq(ok.headers['x-aivis-api-version'], SPEC.info.version, 'header vs info.version of the vendored document');
  return `contract ${ok.headers['x-aivis-api-version']}, minimum client ${ok.headers['x-aivis-min-client']}`;
});
await test('/changelog publishes the version, minimum client, rate limits and deprecations', async () => {
  const r = await api('/changelog', { auth: null });
  eq(r.status, 200);
  eq(r.body.apiVersion, SPEC.info.version, 'apiVersion');
  assert(/^\d+\.\d+\.\d+$/.test(r.body.minClientVersion), 'minClientVersion');
  assert(r.body.rateLimits.perToken.limit > 0, 'perToken limit');
  assert(Array.isArray(r.body.entries) && r.body.entries[0].version === r.body.apiVersion, 'entries newest first');
  assert(r.body.deprecations.some(d => d.path === '/deprecation-probe'), 'probe listed as deprecated');
  return `${r.body.entries.length} entries; per token ${r.body.rateLimits.perToken.limit}/${r.body.rateLimits.perToken.windowSeconds}s`;
});
await test('a client below the minimum is answered 426 client_too_old, docs routes excepted', async () => {
  const r = await api('/me', { headers: { 'user-agent': 'aivis-os/0.0.1 (contract-suite)' } });
  eq(r.status, 426);
  eq(coded(r), CODE.CLIENT_TOO_OLD, 'code');
  eq(classifyLookup(r), 'client_too_old', 'classification');
  const docs = await api('/changelog', { auth: null, headers: { 'user-agent': 'aivis-os/0.0.1 (contract-suite)' } });
  eq(docs.status, 200, 'an outdated client can still read what to do');
  return `Upgrade: ${r.headers.upgrade || '(header dropped by the edge — allowed)'}`;
});
await test('/deprecation-probe carries Deprecation, Sunset and Link rel="deprecation"', async () => {
  const r = await api('/deprecation-probe');
  eq(r.status, 200);
  assert(/^@\d+$/.test(r.headers.deprecation || ''), `Deprecation: ${r.headers.deprecation}`);
  assert(!Number.isNaN(Date.parse(r.headers.sunset || '')), `Sunset: ${r.headers.sunset}`);
  assert(/rel="deprecation"/.test(r.headers.link || ''), `Link: ${r.headers.link}`);
  eq(r.body.ok, true);
});

/* ── Conditional requests and rate limits ─────────────────────────────── */
heading('caching and rate limits');
await test('every 200 carries an ETag and Cache-Control: private, no-cache', async () => {
  const r = await api('/me');
  assert(/^(W\/)?"[^"]+"$/.test(r.headers.etag || ''), `ETag: ${r.headers.etag}`);
  assert(/private/.test(r.headers['cache-control'] || '') && /no-cache/.test(r.headers['cache-control'] || ''), `Cache-Control: ${r.headers['cache-control']}`);
  assert(/authorization/i.test(r.headers.vary || ''), `Vary: ${r.headers.vary}`);
});
await test('If-None-Match with the current ETag is a 304 without a body', async () => {
  const first = await api('/me');
  const again = await api('/me', { headers: { 'if-none-match': first.headers.etag } });
  eq(again.status, 304);
  eq(again.body, null, 'no body');
  eq(again.headers['x-aivis-api-version'], first.headers['x-aivis-api-version'], 'same headers');
  const weak = await api('/me', { headers: { 'if-none-match': 'W/' + first.headers.etag } });
  eq(weak.status, 304, 'weak comparison');
});
await test('rate-limit headers are on every response; 429 rate_limited carries Retry-After', async () => {
  const r = await api('/businesses');
  for (const h of ['x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset']) assert(/^\d+$/.test(r.headers[h] || ''), `${h}: ${r.headers[h]}`);
  await control('throttle=1');
  const t = await api('/businesses');
  eq(t.status, 429);
  eq(coded(t), CODE.RATE_LIMITED, 'code');
  assert(parseInt(t.headers['retry-after'], 10) >= 1, `Retry-After: ${t.headers['retry-after']}`);
  eq(classifyLookup(t), 'throttled', 'classification');
  eq(decideFromLookup({ ...t, apiReachable: true }).action, Action.HOLD, 'a throttled lookup holds');
}, FIXTURE);

/* ── Pagination ───────────────────────────────────────────────────────── */
heading('pagination');
await test('list envelope carries items, nextCursor, hasMore, total', async () => {
  const r = await api('/businesses');
  assert(r.status === 200, `status ${r.status}`);
  for (const f of ['items', 'nextCursor', 'hasMore', 'total']) assert(f in r.body, `missing ${f}`);
  assert(Array.isArray(r.body.items), 'items is not an array');
  return `${r.body.total} businesses`;
});
await test('cursor paging walks the collection exactly once', async () => {
  let cursor = null, seen = [], pages = 0, total = null;
  do {
    const r = await api(`/businesses?limit=1${cursor ? `&cursor=${encodeURIComponent(cursor)}` : ''}`);
    assert(r.status === 200, `status ${r.status}`);
    total = r.body.total;
    seen.push(...r.body.items.map(b => b.id));
    cursor = r.body.nextCursor;
  } while (cursor && ++pages < 50);
  assert(new Set(seen).size === seen.length, 'duplicate rows across pages');
  eq(seen.length, total, 'walked count vs total');
  return `${seen.length} rows over ${pages + 1} pages`;
});
await test('limit is clamped to 200, not rejected', async () => {
  const r = await api('/businesses?limit=500');
  assert(r.status === 200, `status ${r.status}`);
  assert(r.body.items.length <= 200, `returned ${r.body.items.length} rows`);
});
await test('a bogus limit falls back to the default rather than erroring', async () => {
  const r = await api('/businesses?limit=abc');
  assert(r.status === 200, `status ${r.status} — the real API does not reject this`);
  assert(r.body.items.length <= 200, 'clamp not applied');
}, FIXTURE);

/* ── Inventory shape ──────────────────────────────────────────────────── */
heading('inventory');
await test('chain rows carry state, knowledgeGraphReady, urlCount and the language fields (API-10)', async () => {
  const b = (await api('/businesses')).body.items[0];
  const r = await api(`/businesses/${b.id}/chains`);
  assert(r.status === 200, `status ${r.status}`);
  const c = r.body.items[0];
  assert(c, 'no chains on the first business');
  for (const f of ['id','name','description','state','currentStep','knowledgeGraphReady','graphScore','urlCount','languageCode','urlLanguageCodes','declaredLanguageCodes','createdAt']) assert(f in c, `missing ${f}`);
  assert(Array.isArray(c.urlLanguageCodes) && Array.isArray(c.declaredLanguageCodes), 'language arrays');
  return `state=${c.state} languageCode=${c.languageCode}`;
});
await test('a one-language chain reports it; a mixed chain reports null and lists what it holds', async () => {
  const list = (await api('/businesses/biz_live/chains')).body.items;
  const en = list.find(c => c.id === 'chain_en');
  eq(en.languageCode, 'en'); eq(en.urlLanguageCodes, ['en']); eq(en.declaredLanguageCodes, ['en']);
  const mixed = list.find(c => c.id === 'chain_mixed');
  eq(mixed.languageCode, null, 'mixed chain');
  eq(mixed.urlLanguageCodes, ['de', 'en']);
  eq(mixed.declaredLanguageCodes, ['de', 'en']);
  return 'the admin decides for chain_mixed';
}, FIXTURE);
await test('url rows carry chainId, businessId, layer, captureStatus and jsonLd state incl. suppressedAt', async () => {
  const b = (await api('/businesses')).body.items[0];
  const c = (await api(`/businesses/${b.id}/chains`)).body.items[0];
  const r = await api(`/chains/${c.id}/urls`);
  assert(r.status === 200, `status ${r.status}`);
  const u = r.body.items[0];
  assert(u, 'no url rows');
  for (const f of ['id','url','chainId','businessId','languageCode','layer','captureStatus','jsonLd']) assert(f in u, `missing ${f}`);
  for (const f of ['ready','stale','generatedAt','suppressedAt']) assert(f in u.jsonLd, `jsonLd missing ${f}`);
  eq(u.chainId, c.id, 'row chainId'); eq(u.businessId, b.id, 'row businessId');
  return `layer=${u.layer} captureStatus=${u.captureStatus}`;
});
await test('an unknown chain id is a 404 chain_not_found', async () => {
  const r = await api('/chains/chain_does_not_exist/urls');
  assert(r.status === 404, `status ${r.status}`);
  eq(coded(r), 'chain_not_found');
});
await test('/urls/{urlId} returns the same row the chain listing does (API-5)', async () => {
  const row = (await api('/chains/chain_core/urls')).body.items.find(x => x.id === 'u_home');
  const one = await api('/urls/u_home');
  eq(one.status, 200);
  eq(one.body, row, 'identical row');
  const gone = await api('/urls/u_does_not_exist');
  eq(gone.status, 404); eq(coded(gone), 'url_not_found');
}, FIXTURE);
await test('?url= narrows a chain listing to one page — exact plus slash variant; a miss is an empty page (API-5)', async () => {
  const hit = await api('/chains/chain_core/urls?url=' + encodeURIComponent('https://example.com/services'));
  eq(hit.status, 200); eq(hit.body.total, 1); eq(hit.body.items[0].id, 'u_services');
  const miss = await api('/chains/chain_core/urls?url=' + encodeURIComponent('https://example.com/nichts/'));
  eq(miss.status, 200, 'a miss is not a 404'); eq(miss.body.total, 0); eq(miss.body.items, []);
  const other = await api('/chains/chain_edit/urls?url=' + encodeURIComponent('https://example.com/services/'));
  eq(other.body.items[0].id, 'u_services_edit', 'each chain answers for its own row');
}, FIXTURE);
await test('?updatedSince= is the change feed: zone required, future empty, changes included (API-6)', async () => {
  const bad = await api('/chains/chain_core/urls?updatedSince=2026-09-01T00:00:00');
  eq(bad.status, 400); eq(coded(bad), 'bad_request');
  const future = await api('/chains/chain_core/urls?updatedSince=' + encodeURIComponent('2999-01-01T00:00:00Z'));
  eq(future.status, 200); eq(future.body.total, 0);
  const since = new Date(Date.now() - 60_000).toISOString();
  await scenario('withdrawn');
  const feed = await api('/chains/chain_core/urls?updatedSince=' + encodeURIComponent(since));
  eq(feed.body.total, 1, 'only the changed row');
  eq(feed.body.items[0].id, 'u_retract');
  await scenario('live');
}, FIXTURE);

/* ── Lookup semantics ─────────────────────────────────────────────────── */
heading('lookup');
await test('an exact permalink resolves to a valid envelope', async () => {
  const r = await api(q('https://example.com/'));
  assert(r.status === 200, `status ${r.status}`);
  const v = validateEnvelope(r.body);
  assert(v.ok, 'envelope invalid: ' + v.errors.join('; '));
}, FIXTURE);
await test('the trailing-slash variant resolves to the same artifact', async () => {
  const a = await api(q('https://example.com/services/'));
  const b = await api(q('https://example.com/services'));
  assert(a.status === 200 && b.status === 200, `${a.status}/${b.status}`);
  eq(b.body.urlId, a.body.urlId, 'urlId');
}, FIXTURE);
await test('a query string is a miss, not a fuzzy match', async () => {
  const r = await api(q('https://example.com/services/?utm_source=x'));
  assert(r.status === 404, `status ${r.status} — fuzzy matching would be silent corruption`);
}, FIXTURE);
await test('multi-chain winner: non-stale beats stale', async () => {
  const r = await api(q('https://example.com/services/'));
  assert(r.status === 200, `status ${r.status}`);
  eq(r.body.stale, false, 'a stale artifact won');
  eq(r.body.chainId, 'chain_edit', 'winning chain');
}, FIXTURE);
await test('a missing url parameter is 400 bad_request, not 404', async () => {
  const r = await api('/jsonld');
  assert(r.status === 400, `status ${r.status}`);
  eq(coded(r), 'bad_request');
});

// §07a — one chain per language. /jsonld?url= picks the freshest artifact
// across chains; /urls/{urlId}/jsonld pins the chain the inventory row came
// from. The connector fetches inventory targets by id for exactly this reason.
await test('fetching by urlId pins the chain that /jsonld?url= would not choose', async () => {
  const byUrl = await api(q('https://example.com/services/'));
  const byId = await api('/urls/u_services/jsonld');
  eq(byId.status, 200);
  eq(byId.body.chainId, 'chain_core', 'pinned chain');
  eq(byUrl.body.chainId, 'chain_edit', 'winner by url');
  eq(byId.body.urlId, 'u_services');
  return 'same URL, two chains, two answers';
}, FIXTURE);

await test('a language chain carries its languageCode on every inventory row', async () => {
  const rows = (await api('/chains/chain_en/urls')).body.items;
  assert(rows.length > 0, 'english rows');
  for (const row of rows) eq(row.languageCode, 'en', row.url);
  return `${rows.length} rows, all en`;
}, FIXTURE);
await test('a relative url parameter is 400', async () => {
  const r = await api('/jsonld?url=' + encodeURIComponent('/services/'));
  assert(r.status === 400, `status ${r.status}`);
});

/* ── The two 404s, now codes (API-3) ──────────────────────────────────── */
heading('the two 404s');
await test('an unknown URL is 404 url_not_found', async () => {
  const r = await api(q('https://example.com/nichts-hier/'));
  assert(r.status === 404, `status ${r.status}`);
  eq(coded(r), CODE.URL_NOT_FOUND, 'code');
  eq(classifyLookup(r), 'url_gone', 'classification');
}, FIXTURE);
await test('an ungenerated URL is 404 jsonld_not_generated', async () => {
  const r = await api(q('https://example.com/kontakt/'));
  assert(r.status === 404, `status ${r.status}`);
  eq(coded(r), CODE.NOT_GENERATED, 'code');
  eq(classifyLookup(r), 'not_generated', 'classification');
}, FIXTURE);
await test('the two 404s are distinguishable by code, not prose (AC-18 depends on it)', async () => {
  const gone = await api(q('https://example.com/nichts-hier/'));
  const pend = await api(q('https://example.com/kontakt/'));
  eq(gone.status, pend.status, 'statuses — differing would be easier');
  assert(gone.body.error.code !== pend.body.error.code, 'same code: the fast path is impossible');
  assert(decideFromLookup({ ...gone, apiReachable: true }).action !== decideFromLookup({ ...pend, apiReachable: true }).action,
    'the two 404s produce the same action');
  // The reworded message must not matter any more.
  const reworded = { status: 404, body: { error: { code: gone.body.error.code, message: 'Nope.' } } };
  eq(classifyLookup(reworded), 'url_gone', 'classification survives a message change');
}, FIXTURE);

/* ── Retraction, end to end ───────────────────────────────────────────── */
heading('retraction scenario');
const RETRACTED = 'https://example.com/altes-angebot/';
await test('live: the artifact serves', async () => {
  await scenario('live');
  const r = await api(q(RETRACTED));
  assert(r.status === 200, `status ${r.status}`);
  eq(decideFromLookup({ ...r, apiReachable: true }).action, Action.SERVE, 'action');
}, FIXTURE);
await test('regenerating: holds last-known-good on both signals', async () => {
  await scenario('regenerating');
  const r = await api(q(RETRACTED));
  assert(r.status === 404, `status ${r.status}`);
  eq(decideFromLookup({ ...r, apiReachable: true }).action, Action.HOLD, 'lookup action');
  const row = (await api('/chains/chain_core/urls')).body.items.find(x => x.url === RETRACTED);
  eq(row.jsonLd.ready, false, 'inventory ready');
  eq(row.captureStatus, 'processing', 'captureStatus');
  eq(decideFromInventory({ authoritative: true, row, missingCompleteRuns: 0 }).action, Action.HOLD, 'inventory action');
}, FIXTURE);
await test('unready: inventory deactivates, the lookup still holds', async () => {
  await scenario('unready');
  const r = await api(q(RETRACTED));
  eq(decideFromLookup({ ...r, apiReachable: true }).action, Action.HOLD, 'lookup action');
  const row = (await api('/chains/chain_core/urls')).body.items.find(x => x.url === RETRACTED);
  eq(row.captureStatus, 'processed', 'captureStatus');
  eq(decideFromInventory({ authoritative: true, row, missingCompleteRuns: 0 }).action, Action.DEACTIVATE, 'inventory action');
}, FIXTURE);
await test('withdrawn: 410 on both artifact routes deactivates at once (R-01b, API-2)', async () => {
  await scenario('withdrawn');
  const byUrl = await api(q(RETRACTED));
  eq(byUrl.status, 410); eq(coded(byUrl), CODE.WITHDRAWN, 'code');
  const byId = await api('/urls/u_retract/jsonld');
  eq(byId.status, 410); eq(coded(byId), CODE.WITHDRAWN, 'code by id');
  const d = decideFromLookup({ ...byUrl, apiReachable: false });
  eq(d.action, Action.DEACTIVATE, 'no reachability proof needed — only AIVIS emits the code');
  eq(d.rule, 'R-01b', 'rule');
  const row = (await api('/chains/chain_core/urls')).body.items.find(x => x.url === RETRACTED);
  eq(row.jsonLd.ready, false, 'ready false while unpublished');
  assert(typeof row.jsonLd.suppressedAt === 'string', 'suppressedAt set');
  eq(decideFromInventory({ authoritative: true, row, missingCompleteRuns: 0 }).action, Action.DEACTIVATE, 'inventory action');
  await scenario('live');
  const back = await api(q(RETRACTED));
  eq(back.status, 200, 'republished: answers as before');
}, FIXTURE);
await test('deleted: R-01 suspends, two authoritative passes retire', async () => {
  await scenario('deleted');
  const r = await api(q(RETRACTED));
  assert(r.status === 404, `status ${r.status}`);
  eq(coded(r), CODE.URL_NOT_FOUND, 'deletion stays url_not_found, distinguishable from withdrawal');
  const d = decideFromLookup({ ...r, apiReachable: true });
  eq(d.action, Action.SUSPEND, 'action');
  eq(d.rule, 'R-01', 'rule');
  const rows = (await api('/chains/chain_core/urls')).body.items;
  assert(!rows.some(x => x.url === RETRACTED), 'still present in inventory');
  const first = decideFromInventory({ authoritative: true, row: null, missingCompleteRuns: 0 });
  const second = decideFromInventory({ authoritative: true, row: null, missingCompleteRuns: first.missingCompleteRuns });
  eq(first.action, Action.HOLD, 'first pass');
  eq(second.action, Action.RETIRE, 'second pass');
}, FIXTURE);
await test('a withdrawn artifact does not resurrect (AC-17)', async () => {
  await scenario('deleted');
  // Simulate the outage that follows: transport failure must not revive it.
  eq(decideFromLookup({ status: 503, apiReachable: false }).action, Action.HOLD, 'outage action');
  const rows = (await api('/chains/chain_core/urls')).body.items;
  assert(!rows.some(x => x.url === RETRACTED), 'reappeared in inventory');
}, FIXTURE);

/* ── Binding against real responses ───────────────────────────────────── */
heading('binding');
await test('an artifact from a second business on the same domain is rejected (AC-19)', async () => {
  await scenario('live');
  const r = await api(q('https://example.com/rebuild-only/'));
  assert(r.status === 200, `status ${r.status}`);
  eq(r.body.businessId, 'biz_rebuild', 'fixture businessId');
  const bind = checkBinding(r.body, { businessId: 'biz_live', allowedHosts: ['example.com'] });
  assert(!bind.ok, 'binding accepted a foreign business');
  return bind.errors[0];
}, FIXTURE);
await test('two businesses share this domain, so selection cannot be automatic (AC-20)', async () => {
  const matching = (await api('/businesses')).body.items.filter(b => { try { return new URL(b.baseUrl).host === 'example.com'; } catch { return false; } });
  eq(matching.length, 2, 'businesses matching the site host');
  return 'the admin must disambiguate';
}, FIXTURE);

/* ── Conformance to the vendored OpenAPI document ─────────────────────── */
heading('openapi conformance');

await test('the validator actually rejects a wrong response', () => {
  // Without this, a validator that returned [] for everything would make every
  // conformance test below pass while checking nothing.
  const me = { email: 'a@b.c', name: 'A', tokenName: 't', businessId: null, business: null, permissions: [] };
  const missing = conform(SPEC, 'GET', '/me', 200, { email: 'a@b.c', name: 'A' });
  const badNull = conform(SPEC, 'GET', '/me', 200, { ...me, businessId: 5 });
  const badType = conform(SPEC, 'GET', '/businesses', 200, { items: [], nextCursor: null, hasMore: 'yes', total: 0 });
  const badItem = validate(SPEC.paths['/businesses/{businessId}/chains'].get.responses['200'].content['application/json'].schema.properties.items.items, { id: 'c' });
  assert(missing.errors.length, 'a missing required property passed');
  assert(badNull.errors.length, 'a value matching no anyOf branch passed');
  assert(badType.errors.length, 'a wrong type passed');
  assert(badItem.length,        'a chain item without its language fields passed');
  // Since 1.1.0 the schemas allow unknown properties — additive fields within v1.
  const extra = conform(SPEC, 'GET', '/me', 200, { ...me, surprise: 1 });
  eq(extra.errors, [], 'an additive field must be tolerated');
  return '4 deliberately broken payloads rejected, 1 additive field tolerated';
});

await test('the validator refuses to check keywords it does not understand', () => {
  let threw = false;
  try { validate({ type: 'string', pattern: '^x$' }, 'x'); } catch { threw = true; }
  assert(threw, 'an unknown keyword was silently ignored — that is false confidence');
});

await test('enums are open: the document lists known values in descriptions, not as enum', () => {
  const chain = SPEC.paths['/businesses/{businessId}/chains'].get.responses['200'].content['application/json'].schema.properties.items.items.properties;
  const row = SPEC.paths['/chains/{chainId}/urls'].get.responses['200'].content['application/json'].schema.properties.items.items.properties;
  const err = SPEC.paths['/jsonld'].get.responses['404'].content['application/json'].schema.properties.error.properties;
  for (const [name, s] of [['state', chain.state], ['layer', row.layer], ['captureStatus', row.captureStatus], ['error.code', err.code]]) {
    const branches = s.anyOf ? s.anyOf : [s];
    assert(branches.every(b => !b.enum), `${name} is a closed enum — the connector would break on an additive value`);
  }
});

await test('the mock serves exactly the operations the document declares', async () => {
  const declared = specOperations(SPEC);
  eq(declared.length, 9, 'operation count');
  for (const op of declared) {
    const [, tpl] = op.split(' ');
    const concrete = tpl
      .replace('{businessId}', 'biz_live')
      .replace('{chainId}', 'chain_core')
      .replace('{urlId}', 'u_home');
    const path = concrete === '/jsonld' ? '/jsonld?url=' + encodeURIComponent('https://example.com/') : concrete;
    const r = await api(path);
    assert(r.status !== 404 || tpl === '/jsonld', `${op} is declared but the mock does not serve it (${r.status})`);
  }
  return declared.length + ' operations reachable';
}, FIXTURE);

await test('every 200 body conforms to its declared schema', async () => {
  const b = (await api('/businesses')).body.items[0];
  const c = (await api(`/businesses/${b.id}/chains`)).body.items[0];
  await api(`/chains/${c.id}/urls`);
  await api('/me');
  await api('/changelog', { auth: null });
  await api('/deprecation-probe');
  await api('/urls/u_home');
  await api('/jsonld?url=' + encodeURIComponent('https://example.com/'));
  await api('/urls/u_home/jsonld');
  const bad = observed.filter(o => o.errors.length);
  assert(bad.length === 0, bad.map(o => `${o.where}: ${o.errors.join('; ')}`).join(' | '));
  return observed.length + ' responses checked so far';
}, FIXTURE);

await test('error envelopes conform too (401, 404, 400, 410, 426, 429)', async () => {
  await api('/me', { auth: null });
  await api('/jsonld?url=' + encodeURIComponent('https://example.com/nichts/'));
  await api('/jsonld');
  await scenario('withdrawn');
  await api('/urls/u_retract/jsonld');
  await scenario('live');
  await api('/me', { headers: { 'user-agent': 'aivis-os/0.0.1 (contract-suite)' } });
  await control('throttle=1');
  await api('/me');
  const bad = observed.filter(o => o.errors.length);
  assert(bad.length === 0, bad.map(o => `${o.where}: ${o.errors.join('; ')}`).join(' | '));
}, FIXTURE);

await test('no response in this entire run violated the document', () => {
  const bad = observed.filter(o => o.errors.length);
  assert(bad.length === 0, `${bad.length} of ${observed.length} responses violated the spec:\n    ` +
    bad.slice(0, 6).map(o => `${o.where}: ${o.errors.join('; ')}`).join('\n    '));
  return `${observed.length} responses, 0 violations`;
});

await test('no response was skipped for lack of a schema', () => {
  assert(unchecked.length === 0, `${unchecked.length} unchecked:\n    ` + unchecked.slice(0, 6).join('\n    '));
  return 'every observed response had a schema to check against';
});

if (server) server.close();
process.exit(finish(LIVE ? 'contract (live)' : 'contract') ? 1 : 0);
