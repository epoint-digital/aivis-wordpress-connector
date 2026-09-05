// Contract suite: the AIVIS Public API over real HTTP.
//
//   node tests/contract/run.mjs                 # against the built-in mock
//   AIVIS_API_BASE=https://aivis-new.dev.onepoint.ro \
//   AIVIS_API_TOKEN=aivis_… node tests/contract/run.mjs --live
//
// Pure logic lives in tests/unit — everything here crosses the wire. In --live
// mode, assertions that need controlled fixtures are skipped and reported.

import { createServer } from '../mock/server.js';
import { VALID_TOKEN } from '../mock/data.js';
import { validateEnvelope, checkBinding, classifyLookup, decideFromLookup, decideFromInventory, Action, NOT_FOUND, NOT_GENERATED }
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
const unchecked = [];         // responses the document declares no schema for

async function api(path, { auth = token, ...opts } = {}) {
  const res = await fetch(base + path, {
    ...opts,
    headers: { accept: 'application/json', ...(auth ? { authorization: `Bearer ${auth}` } : {}), ...(opts.headers || {}) },
    redirect: 'manual',
  });
  let body = null;
  try { body = await res.json(); } catch {}
  // Every response in this suite is checked against the vendored document, not
  // only the ones a test remembers to look at.
  const v = conform(SPEC, 'GET', path, res.status, body);
  const where = `GET ${path.split('?')[0]} -> ${res.status}`;
  if (!v.checked) unchecked.push(`${where}: ${v.errors.join('; ') || 'no body schema in the document'}`);
  observed.push({ where, errors: v.errors });
  return { status: res.status, body };
}
const scenario = async stage => { if (!LIVE) await fetch(`${base.replace('/api/public/v1', '')}/__scenario?stage=${stage}`, { method: 'POST' }); };
const q = u => '/jsonld?url=' + encodeURIComponent(u);

console.log(`\nAIVIS connector — contract suite (${LIVE ? 'LIVE: ' + base : 'mock'})\n`);

/* ── Authentication ───────────────────────────────────────────────────── */
heading('authentication');
await test('401 with no Authorization header', async () => {
  const r = await api('/me', { auth: null });
  assert(r.status === 401, `status ${r.status}`);
  eq(r.body.error.message, 'Missing bearer token', 'message');
});
await test('401 for a token without the aivis_ prefix', async () => {
  const r = await api('/me', { auth: 'not-an-aivis-token' });
  assert(r.status === 401, `status ${r.status}`);
  eq(r.body.error.message, 'Invalid API token', 'message');
});
await test('401 for a well-formed but unknown token', async () => {
  const r = await api('/me', { auth: 'aivis_' + 'x'.repeat(43) });
  assert(r.status === 401, `status ${r.status}`);
  eq(r.body.error.message, 'Invalid API token', 'message');
});
await test('/me returns email, name and tokenName', async () => {
  const r = await api('/me');
  assert(r.status === 200, `status ${r.status}`);
  for (const f of ['email', 'name', 'tokenName']) assert(f in r.body, `missing ${f}`);
  return `token "${r.body.tokenName}"`;
});

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
  // Verified against lib/pagination.ts: parseInt("abc") || 100, then clamped.
  const r = await api('/businesses?limit=abc');
  assert(r.status === 200, `status ${r.status} — the real API does not reject this`);
  assert(r.body.items.length <= 200, 'clamp not applied');
}, FIXTURE);

/* ── Inventory shape ──────────────────────────────────────────────────── */
heading('inventory');
await test('chain rows carry state, knowledgeGraphReady and urlCount', async () => {
  const b = (await api('/businesses')).body.items[0];
  const r = await api(`/businesses/${b.id}/chains`);
  assert(r.status === 200, `status ${r.status}`);
  const c = r.body.items[0];
  assert(c, 'no chains on the first business');
  for (const f of ['id','name','description','state','currentStep','knowledgeGraphReady','graphScore','urlCount','createdAt']) assert(f in c, `missing ${f}`);
  assert(['empty','building','ready','re_ingesting'].includes(c.state), `unexpected state ${c.state}`);
  return `state=${c.state}`;
});
await test('url rows carry layer, captureStatus and jsonLd state', async () => {
  const b = (await api('/businesses')).body.items[0];
  const c = (await api(`/businesses/${b.id}/chains`)).body.items[0];
  const r = await api(`/chains/${c.id}/urls`);
  assert(r.status === 200, `status ${r.status}`);
  const u = r.body.items[0];
  assert(u, 'no url rows');
  for (const f of ['id','url','languageCode','layer','captureStatus','jsonLd']) assert(f in u, `missing ${f}`);
  for (const f of ['ready','stale','generatedAt']) assert(f in u.jsonLd, `jsonLd missing ${f}`);
  assert(['structural_core','editorial'].includes(u.layer), `unexpected layer ${u.layer}`);
  assert(u.captureStatus === null || ['draft','processing','processed','failed'].includes(u.captureStatus), `unexpected captureStatus ${u.captureStatus}`);
  return `layer=${u.layer} captureStatus=${u.captureStatus}`;
});
await test('an unknown chain id is a 404', async () => {
  const r = await api('/chains/chain_does_not_exist/urls');
  assert(r.status === 404, `status ${r.status}`);
});

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
await test('a missing url parameter is 400, not 404', async () => {
  const r = await api('/jsonld');
  assert(r.status === 400, `status ${r.status}`);
});
await test('a relative url parameter is 400', async () => {
  const r = await api('/jsonld?url=' + encodeURIComponent('/services/'));
  assert(r.status === 400, `status ${r.status}`);
});

/* ── The two 404s ─────────────────────────────────────────────────────── */
heading('the two 404s');
await test('an unknown URL says "URL not found in your businesses"', async () => {
  const r = await api(q('https://example.com/nichts-hier/'));
  assert(r.status === 404, `status ${r.status}`);
  eq(r.body.error.message, NOT_FOUND, 'message');
  eq(classifyLookup(r), 'url_gone', 'classification');
}, FIXTURE);
await test('an ungenerated URL says "JSON-LD not generated yet"', async () => {
  const r = await api(q('https://example.com/kontakt/'));
  assert(r.status === 404, `status ${r.status}`);
  eq(r.body.error.message, NOT_GENERATED, 'message');
  eq(classifyLookup(r), 'not_generated', 'classification');
}, FIXTURE);
await test('the two 404s are distinguishable (AC-18 depends on it)', async () => {
  const gone = await api(q('https://example.com/nichts-hier/'));
  const pend = await api(q('https://example.com/kontakt/'));
  eq(gone.status, pend.status, 'statuses — differing would be easier');
  assert(gone.body.error.message !== pend.body.error.message, 'same message: the fast path is impossible');
  assert(decideFromLookup({ ...gone, apiReachable: true }).action !== decideFromLookup({ ...pend, apiReachable: true }).action,
    'the two 404s produce the same action');
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
await test('deleted: R-01 suspends, two authoritative passes retire', async () => {
  await scenario('deleted');
  const r = await api(q(RETRACTED));
  assert(r.status === 404, `status ${r.status}`);
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
  const missing = conform(SPEC, 'GET', '/me', 200, { email: 'a@b.c', name: 'A' });
  const extra   = conform(SPEC, 'GET', '/me', 200, { email: 'a@b.c', name: 'A', tokenName: 't', surprise: 1 });
  const badType = conform(SPEC, 'GET', '/businesses', 200, { items: [], nextCursor: null, hasMore: 'yes', total: 0 });
  const badEnum = validate(SPEC.paths['/chains/{chainId}/urls'].get.responses['200'].content['application/json'].schema.properties.items.items.properties.layer, 'nonsense');
  assert(missing.errors.length, 'a missing required property passed');
  assert(extra.errors.length,   'an unexpected property passed');
  assert(badType.errors.length, 'a wrong type passed');
  assert(badEnum.length,        'a value outside an enum passed');
  return '4 deliberately broken payloads all rejected';
});

await test('the validator refuses to check keywords it does not understand', () => {
  let threw = false;
  try { validate({ type: 'string', pattern: '^x$' }, 'x'); } catch { threw = true; }
  assert(threw, 'an unknown keyword was silently ignored — that is false confidence');
});

await test('the mock serves exactly the operations the document declares', async () => {
  const declared = specOperations(SPEC);
  eq(declared.length, 6, 'operation count');
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
  await api('/jsonld?url=' + encodeURIComponent('https://example.com/'));
  await api('/urls/u_home/jsonld');
  const bad = observed.filter(o => o.errors.length);
  assert(bad.length === 0, bad.map(o => `${o.where}: ${o.errors.join('; ')}`).join(' | '));
  return observed.length + ' responses checked so far';
}, FIXTURE);

await test('error envelopes conform too (401, 404, 400)', async () => {
  await api('/me', { auth: null });
  await api('/jsonld?url=' + encodeURIComponent('https://example.com/nichts/'));
  await api('/jsonld');
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
