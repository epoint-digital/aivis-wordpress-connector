// Contract suite for the AIVIS Public API + the connector's decision rules.
//
//   node tests/contract/run.mjs                 # against the built-in mock
//   AIVIS_API_BASE=https://aivis-new.dev.onepoint.ro \
//   AIVIS_API_TOKEN=aivis_… node tests/contract/run.mjs --live
//
// --live runs only the read-only API-shape assertions; fixture-dependent and
// retraction-scenario tests are skipped, because they need controlled data.

import { createServer } from '../mock/server.js';
import { VALID_TOKEN, businesses } from '../mock/data.js';
import {
  Action, decideFromLookup, decideFromInventory, validateEnvelope,
  checkBinding, localUrlKeyInput, isAuthoritative, classifyLookup,
  NOT_FOUND, NOT_GENERATED,
} from '../../src/reference/connector-logic.mjs';

const LIVE = process.argv.includes('--live');
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

let pass = 0, failed = 0, skipped = 0;
const results = [];

function record(status, name, detail) {
  results.push({ status, name, detail });
  if (status === 'PASS') pass++; else if (status === 'FAIL') failed++; else skipped++;
  const icon = status === 'PASS' ? '  ok  ' : status === 'FAIL' ? ' FAIL ' : ' skip ';
  console.log(`${icon} ${name}${detail ? '  — ' + detail : ''}`);
}
async function test(name, fn, { liveSafe = true } = {}) {
  if (LIVE && !liveSafe) return record('SKIP', name, 'needs fixture data');
  try { const d = await fn(); record('PASS', name, typeof d === 'string' ? d : ''); }
  catch (e) { record('FAIL', name, e.message); }
}
function assert(cond, msg) { if (!cond) throw new Error(msg); }
const eq = (a, b, msg) => assert(JSON.stringify(a) === JSON.stringify(b), `${msg} — got ${JSON.stringify(a)}, want ${JSON.stringify(b)}`);

async function api(path, { auth = token, ...opts } = {}) {
  const res = await fetch(base + path, {
    ...opts,
    headers: { accept: 'application/json', ...(auth ? { authorization: `Bearer ${auth}` } : {}), ...(opts.headers || {}) },
    redirect: 'manual',
  });
  let body = null;
  try { body = await res.json(); } catch {}
  return { status: res.status, body };
}
const scenario = async stage => { if (!LIVE) await fetch(`${base.replace('/api/public/v1','')}/__scenario?stage=${stage}`, { method: 'POST' }); };

console.log(`\nAIVIS connector contract suite — ${LIVE ? 'LIVE: ' + base : 'mock'}\n`);

/* ── Authentication (§03) ─────────────────────────────────────────────── */
await test('401 with no Authorization header', async () => {
  const r = await api('/me', { auth: null });
  assert(r.status === 401, `status ${r.status}`);
  eq(r.body.error.message, 'Missing bearer token', 'message');
});
await test('401 for a malformed token', async () => {
  const r = await api('/me', { auth: 'not-an-aivis-token' });
  assert(r.status === 401, `status ${r.status}`);
});
await test('401 for a well-formed but unknown token', async () => {
  const r = await api('/me', { auth: 'aivis_' + 'x'.repeat(43) });
  assert(r.status === 401, `status ${r.status}`);
  eq(r.body.error.message, 'Invalid API token', 'message');
});
await test('/me returns email, name, tokenName', async () => {
  const r = await api('/me');
  assert(r.status === 200, `status ${r.status}`);
  for (const f of ['email', 'name', 'tokenName']) assert(f in r.body, `missing ${f}`);
  return `token "${r.body.tokenName}"`;
});

/* ── Pagination (§03) ─────────────────────────────────────────────────── */
await test('list envelope carries items, nextCursor, hasMore, total', async () => {
  const r = await api('/businesses');
  assert(r.status === 200, `status ${r.status}`);
  for (const f of ['items', 'nextCursor', 'hasMore', 'total']) assert(f in r.body, `missing ${f}`);
  assert(Array.isArray(r.body.items), 'items not an array');
  return `${r.body.total} businesses`;
});
await test('cursor paging walks the whole collection exactly once', async () => {
  let cursor = null, seen = [], guard = 0, total = null;
  do {
    const r = await api(`/businesses?limit=1${cursor ? `&cursor=${encodeURIComponent(cursor)}` : ''}`);
    assert(r.status === 200, `status ${r.status}`);
    total = r.body.total;
    seen.push(...r.body.items.map(b => b.id));
    cursor = r.body.nextCursor;
  } while (cursor && ++guard < 50);
  assert(new Set(seen).size === seen.length, 'duplicate rows across pages');
  assert(seen.length === total, `walked ${seen.length}, total says ${total}`);
  return `${seen.length} rows in ${guard + 1} pages`;
});
await test('limit is clamped to 200, not rejected', async () => {
  const r = await api('/businesses?limit=500');
  assert(r.status === 200, `status ${r.status}`);
  assert(r.body.items.length <= 200, 'returned more than 200 rows');
});

/* ── Inventory shape (§03) ────────────────────────────────────────────── */
await test('inventory rows carry layer, captureStatus and jsonLd state', async () => {
  const b = (await api('/businesses')).body.items[0];
  const chains = (await api(`/businesses/${b.id}/chains`)).body.items;
  assert(chains.length > 0, 'no chains on the first business');
  const c = chains[0];
  for (const f of ['state', 'knowledgeGraphReady', 'currentStep', 'urlCount']) assert(f in c, `chain missing ${f}`);
  assert(['empty','building','ready','re_ingesting'].includes(c.state), `unexpected state ${c.state}`);
  const rows = (await api(`/chains/${c.id}/urls`)).body.items;
  assert(rows.length > 0, 'no url rows');
  const u = rows[0];
  for (const f of ['id','url','languageCode','layer','captureStatus','jsonLd']) assert(f in u, `url row missing ${f}`);
  for (const f of ['ready','stale','generatedAt']) assert(f in u.jsonLd, `jsonLd missing ${f}`);
  assert(['structural_core','editorial'].includes(u.layer), `unexpected layer ${u.layer}`);
  return `layer=${u.layer} captureStatus=${u.captureStatus}`;
});

/* ── Lookup semantics (§03, §07) ──────────────────────────────────────── */
await test('exact permalink resolves', async () => {
  const r = await api('/jsonld?url=' + encodeURIComponent('https://example.com/'));
  assert(r.status === 200, `status ${r.status}`);
  const v = validateEnvelope(r.body);
  assert(v.ok, 'envelope invalid: ' + v.errors.join('; '));
}, { liveSafe: false });
await test('trailing-slash variant resolves to the same artifact', async () => {
  const withSlash = await api('/jsonld?url=' + encodeURIComponent('https://example.com/services/'));
  const without   = await api('/jsonld?url=' + encodeURIComponent('https://example.com/services'));
  assert(withSlash.status === 200 && without.status === 200, `${withSlash.status}/${without.status}`);
  eq(without.body.urlId, withSlash.body.urlId, 'different artifact');
}, { liveSafe: false });
await test('a query string is a miss, not a fuzzy match', async () => {
  const r = await api('/jsonld?url=' + encodeURIComponent('https://example.com/services/?utm_source=x'));
  assert(r.status === 404, `status ${r.status} — normalization would be silent corruption`);
}, { liveSafe: false });
await test('multi-chain winner: non-stale beats stale', async () => {
  const r = await api('/jsonld?url=' + encodeURIComponent('https://example.com/services/'));
  assert(r.status === 200, `status ${r.status}`);
  eq(r.body.stale, false, 'stale artifact won');
  eq(r.body.chainId, 'chain_edit', 'wrong chain won');
}, { liveSafe: false });
await test('missing url parameter is 400, not 404', async () => {
  const r = await api('/jsonld');
  assert(r.status === 400, `status ${r.status}`);
});

/* ── The two 404s (§04, §06) — the finding this suite exists for ──────── */
await test('404 for an unknown URL says "URL not found in your businesses"', async () => {
  const r = await api('/jsonld?url=' + encodeURIComponent('https://example.com/nichts-hier/'));
  assert(r.status === 404, `status ${r.status}`);
  eq(r.body.error.message, NOT_FOUND, 'message');
  eq(classifyLookup(r), 'url_gone', 'classification');
}, { liveSafe: false });
await test('404 for an ungenerated URL says "JSON-LD not generated yet"', async () => {
  const r = await api('/jsonld?url=' + encodeURIComponent('https://example.com/kontakt/'));
  assert(r.status === 404, `status ${r.status}`);
  eq(r.body.error.message, NOT_GENERATED, 'message');
  eq(classifyLookup(r), 'not_generated', 'classification');
}, { liveSafe: false });
await test('the two 404s are distinguishable (AC-18 depends on this)', async () => {
  const gone = await api('/jsonld?url=' + encodeURIComponent('https://example.com/nichts-hier/'));
  const pend = await api('/jsonld?url=' + encodeURIComponent('https://example.com/kontakt/'));
  assert(gone.status === pend.status, 'statuses differ, which would be easier');
  assert(gone.body.error.message !== pend.body.error.message, 'same message — the fast path is impossible');
}, { liveSafe: false });

/* ── Retraction scenario (§06) ────────────────────────────────────────── */
const RETRACTED = 'https://example.com/altes-angebot/';
await test('stage live: artifact serves', async () => {
  await scenario('live');
  const r = await api('/jsonld?url=' + encodeURIComponent(RETRACTED));
  assert(r.status === 200, `status ${r.status}`);
  eq(decideFromLookup({ ...r, apiReachable: true }).action, Action.SERVE, 'action');
}, { liveSafe: false });
await test('stage regenerating: holds last-known-good, never deactivates', async () => {
  await scenario('regenerating');
  const r = await api('/jsonld?url=' + encodeURIComponent(RETRACTED));
  assert(r.status === 404, `status ${r.status}`);
  const d = decideFromLookup({ ...r, apiReachable: true });
  eq(d.action, Action.HOLD, 'action');
  eq(d.rule, 'R-01a', 'rule');
  const rows = (await api('/chains/chain_core/urls')).body.items;
  const row = rows.find(x => x.url === RETRACTED);
  eq(row.jsonLd.ready, false, 'inventory ready');
  eq(row.captureStatus, 'processing', 'captureStatus');
  eq(decideFromInventory({ authoritative: true, row, missingCompleteRuns: 0 }).action, Action.HOLD, 'inventory action');
}, { liveSafe: false });
await test('stage unready: inventory deactivates (R-02a), lookup still holds', async () => {
  await scenario('unready');
  const r = await api('/jsonld?url=' + encodeURIComponent(RETRACTED));
  eq(decideFromLookup({ ...r, apiReachable: true }).action, Action.HOLD, 'lookup action');
  const rows = (await api('/chains/chain_core/urls')).body.items;
  const row = rows.find(x => x.url === RETRACTED);
  eq(row.captureStatus, 'processed', 'captureStatus');
  eq(decideFromInventory({ authoritative: true, row, missingCompleteRuns: 0 }).action, Action.DEACTIVATE, 'inventory action');
}, { liveSafe: false });
await test('stage deleted: R-01 suspends, two authoritative passes retire', async () => {
  await scenario('deleted');
  const r = await api('/jsonld?url=' + encodeURIComponent(RETRACTED));
  assert(r.status === 404, `status ${r.status}`);
  const d = decideFromLookup({ ...r, apiReachable: true });
  eq(d.action, Action.SUSPEND, 'action');
  eq(d.rule, 'R-01', 'rule');
  const rows = (await api('/chains/chain_core/urls')).body.items;
  assert(!rows.some(x => x.url === RETRACTED), 'still in inventory');
  const first  = decideFromInventory({ authoritative: true, row: null, missingCompleteRuns: 0 });
  const second = decideFromInventory({ authoritative: true, row: null, missingCompleteRuns: first.missingCompleteRuns });
  eq(first.action, Action.HOLD, 'first run');
  eq(second.action, Action.RETIRE, 'second run');
}, { liveSafe: false });
await test('an unreachable API turns the same 404 into a hold, not a suspend', async () => {
  const r = { status: 404, body: { error: { message: NOT_FOUND } } };
  eq(decideFromLookup({ ...r, apiReachable: false }).action, Action.HOLD, 'action');
});
await test('a partial traversal never retires anything', async () => {
  eq(decideFromInventory({ authoritative: false, row: null, missingCompleteRuns: 5 }).action, Action.HOLD, 'action');
  eq(isAuthoritative([{ completed: true, seen: 4, total: 4 }, { completed: false, seen: 1, total: 2 }]), false, 'isAuthoritative');
  eq(isAuthoritative([{ completed: true, seen: 4, total: 4 }]), true, 'isAuthoritative complete');
});

/* ── Binding (§07 ZT-03) ──────────────────────────────────────────────── */
await test('an artifact from another business is rejected (AC-19)', async () => {
  await scenario('live');
  const r = await api('/jsonld?url=' + encodeURIComponent('https://example.com/rebuild-only/'));
  assert(r.status === 200, `status ${r.status}`);
  eq(r.body.businessId, 'biz_rebuild', 'fixture');
  const bind = checkBinding(r.body, { businessId: 'biz_live', allowedHosts: ['example.com'] });
  assert(!bind.ok, 'binding accepted a foreign business');
  assert(bind.errors[0].startsWith('AIVIS_SCOPE_MISMATCH'), bind.errors[0]);
  return 'rejected: ' + bind.errors[0];
}, { liveSafe: false });
await test('a foreign host is rejected even with the right business', async () => {
  const env = { urlId:'x', chainId:'c', businessId:'biz_live', url:'https://andere-domain.de/', languageCode:'de', stale:false, generatedAt:'2026-09-01T10:00:00.000Z', jsonLd:{} };
  const bind = checkBinding(env, { businessId: 'biz_live', allowedHosts: ['example.com'] });
  assert(!bind.ok && bind.errors.some(e => e.includes('host')), 'host not rejected');
});
await test('chain membership is enforced when inventory context exists', async () => {
  const env = { urlId:'x', chainId:'chain_unknown', businessId:'biz_live', url:'https://example.com/', languageCode:'de', stale:false, generatedAt:'2026-09-01T10:00:00.000Z', jsonLd:{} };
  const ok  = checkBinding(env, { businessId:'biz_live', allowedHosts:['example.com'] });
  const bad = checkBinding(env, { businessId:'biz_live', allowedHosts:['example.com'], knownChainIds:['chain_core','chain_edit'] });
  assert(ok.ok, 'should pass without inventory context');
  assert(!bad.ok, 'should fail with inventory context');
});
await test('two businesses share this site domain — selection cannot be automatic', async () => {
  const r = await api('/businesses');
  const host = 'example.com';
  const matching = r.body.items.filter(b => { try { return new URL(b.baseUrl).host === host; } catch { return false; } });
  assert(matching.length === 2, `expected 2 matches, got ${matching.length}`);
  return 'admin must disambiguate (AC-20)';
}, { liveSafe: false });

/* ── Envelope validation (§08 ZT-02/ZT-04) ────────────────────────────── */
await test('a valid envelope passes', async () => {
  const r = await api('/jsonld?url=' + encodeURIComponent('https://example.com/'));
  const v = validateEnvelope(r.body);
  assert(v.ok, v.errors.join('; '));
}, { liveSafe: false });
await test('a missing required field is rejected', () => {
  const env = { urlId:'x', chainId:'c', businessId:'b', url:'https://example.com/', languageCode:'de', stale:false, generatedAt:'2026-09-01T10:00:00.000Z' };
  const v = validateEnvelope(env);
  assert(!v.ok && v.errors.some(e => e.includes('jsonLd')), 'accepted a truncated envelope');
});
await test('unknown extra fields are ignored (forward compatible)', () => {
  const env = { urlId:'x', chainId:'c', businessId:'b', url:'https://example.com/', languageCode:'de', stale:false, generatedAt:'2026-09-01T10:00:00.000Z', jsonLd:{}, suppressedAt:'2026-09-05T00:00:00Z' };
  assert(validateEnvelope(env).ok, 'rejected a forward-compatible field');
});
await test('nesting deeper than 32 is rejected', () => {
  let deep = {}; let cur = deep;
  for (let i = 0; i < 40; i++) { cur.child = {}; cur = cur.child; }
  const env = { urlId:'x', chainId:'c', businessId:'b', url:'https://example.com/', languageCode:'de', stale:false, generatedAt:'2026-09-01T10:00:00.000Z', jsonLd: deep };
  const v = validateEnvelope(env);
  assert(!v.ok && v.errors.some(e => e.includes('depth')), 'accepted 40-deep nesting');
});
await test('an oversized payload is rejected', () => {
  const env = { urlId:'x', chainId:'c', businessId:'b', url:'https://example.com/', languageCode:'de', stale:false, generatedAt:'2026-09-01T10:00:00.000Z', jsonLd: { blob: 'a'.repeat(1024 * 1024 + 10) } };
  const v = validateEnvelope(env);
  assert(!v.ok && v.errors.some(e => e.includes('bytes')), 'accepted an oversized payload');
});
await test('script-breakout payloads cannot escape a script tag (AC-09)', () => {
  const hostile = { name: '</script><script>alert(1)</script>', note: '<!--', more: '<script src=x>' };
  const out = JSON.stringify(hostile).replace(/</g, '\\u003c');
  assert(!out.includes('</script>') && !out.includes('<script') && !out.includes('<!--'), 'breakout survived escaping');
  return 'all < escaped to \\u003c';
});

/* ── Local URL key (§07) ──────────────────────────────────────────────── */
await test('trailing-slash variants collapse to one local key', () => {
  eq(localUrlKeyInput('https://example.com/services/'), localUrlKeyInput('https://example.com/services'), 'keys differ');
});
await test('tracking parameters are stripped, meaningful ones kept and sorted', () => {
  const a = localUrlKeyInput('https://example.com/p?utm_source=x&b=2&a=1&fbclid=z');
  eq(a, 'https://example.com/p?a=1&b=2', 'normalized');
});
await test('host case and default ports normalise; fragments drop', () => {
  eq(localUrlKeyInput('https://EXAMPLE.com:443/p#frag'), 'https://example.com/p', 'normalized');
});

/* ── Summary ──────────────────────────────────────────────────────────── */
if (server) server.close();
console.log(`\n${pass} passed, ${failed} failed, ${skipped} skipped\n`);
if (failed) { console.log('Failures:'); results.filter(r => r.status === 'FAIL').forEach(r => console.log(`  - ${r.name}: ${r.detail}`)); }
process.exit(failed ? 1 : 0);
