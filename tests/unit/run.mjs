// Unit tests for src/reference/connector-logic.mjs — the executable form of the
// decision rules in docs/SPECIFICATION.md §04, §06, §07, §08.
//
// No server, no network. These pin the branches that decide whether structured
// data stays on or comes off a live customer page.

import {
  Action, classifyLookup, decideFromLookup, decideFromInventory,
  validateEnvelope, checkBinding, localUrlKeyInput, isAuthoritative,
  NOT_FOUND, NOT_GENERATED,
} from '../../src/reference/connector-logic.mjs';
import { test, assert, eq, throws, heading, finish } from '../harness.mjs';

const env = (over = {}) => ({
  urlId: 'u1', chainId: 'c1', businessId: 'b1', url: 'https://example.com/p',
  languageCode: 'de', stale: false, generatedAt: '2026-09-01T10:00:00.000Z',
  jsonLd: { '@type': 'WebPage' }, ...over,
});
/** An object of exactly `n` nested levels. */
const nest = n => n <= 1 ? {} : { child: nest(n - 1) };
const body = message => ({ error: { message } });

console.log('\nAIVIS connector — unit tests\n');

/* ── classifyLookup: every status branch ──────────────────────────────── */
heading('classifyLookup');
await test('200 → ok',                     () => eq(classifyLookup({ status: 200 }), 'ok', 'kind'));
await test('400 → bad_request',            () => eq(classifyLookup({ status: 400 }), 'bad_request', 'kind'));
await test('401 → auth',                   () => eq(classifyLookup({ status: 401 }), 'auth', 'kind'));
await test('403 → account',                () => eq(classifyLookup({ status: 403 }), 'account', 'kind'));
await test('429 → throttled',              () => eq(classifyLookup({ status: 429 }), 'throttled', 'kind'));
await test('500 → transport',              () => eq(classifyLookup({ status: 500 }), 'transport', 'kind'));
await test('503 → transport',              () => eq(classifyLookup({ status: 503 }), 'transport', 'kind'));
await test('0 (network failure) → transport', () => eq(classifyLookup({ status: 0 }), 'transport', 'kind'));
await test('404 + "URL not found" → url_gone',        () => eq(classifyLookup({ status: 404, body: body(NOT_FOUND) }), 'url_gone', 'kind'));
await test('404 + "not generated" → not_generated',   () => eq(classifyLookup({ status: 404, body: body(NOT_GENERATED) }), 'not_generated', 'kind'));
await test('404 with an unknown message → unreadable', () => eq(classifyLookup({ status: 404, body: body('Something else entirely') }), 'unreadable', 'kind'));
await test('404 with no body at all → unreadable',     () => eq(classifyLookup({ status: 404 }), 'unreadable', 'kind'));
await test('a reworded message degrades safely, not silently', () => {
  // The exact scenario API-3 exists to remove: they edit the copy, we must not
  // start deactivating pages.
  const d = decideFromLookup({ status: 404, body: body('URL not found in your businesses.'), apiReachable: true });
  eq(d.action, Action.HOLD, 'action');
  eq(d.needsInventoryConfirmation, true, 'flagged for confirmation');
  return 'trailing full stop → hold, not suspend';
});

/* ── decideFromLookup: every branch reachable from a refresh ──────────── */
heading('decideFromLookup');
await test('200 → serve',                              () => eq(decideFromLookup({ status: 200, apiReachable: true }).action, Action.SERVE, 'action'));
await test('404 url_gone + reachable → suspend (R-01)', () => {
  const d = decideFromLookup({ status: 404, body: body(NOT_FOUND), apiReachable: true });
  eq(d.action, Action.SUSPEND, 'action'); eq(d.rule, 'R-01', 'rule');
});
await test('404 url_gone + unreachable → hold',        () => eq(decideFromLookup({ status: 404, body: body(NOT_FOUND), apiReachable: false }).action, Action.HOLD, 'action'));
await test('404 not_generated → hold (R-01a)',         () => {
  const d = decideFromLookup({ status: 404, body: body(NOT_GENERATED), apiReachable: true });
  eq(d.action, Action.HOLD, 'action'); eq(d.rule, 'R-01a', 'rule');
});
await test('404 not_generated never suspends, even when reachable', () => {
  for (const reachable of [true, false]) {
    eq(decideFromLookup({ status: 404, body: body(NOT_GENERATED), apiReachable: reachable }).action, Action.HOLD, `reachable=${reachable}`);
  }
});
await test('401 → hold, never removes an artifact',    () => eq(decideFromLookup({ status: 401, apiReachable: true }).action, Action.HOLD, 'action'));
await test('403 → hold',                               () => eq(decideFromLookup({ status: 403, apiReachable: true }).action, Action.HOLD, 'action'));
await test('429 → hold',                               () => eq(decideFromLookup({ status: 429, apiReachable: true }).action, Action.HOLD, 'action'));
await test('5xx → hold',                               () => eq(decideFromLookup({ status: 503, apiReachable: true }).action, Action.HOLD, 'action'));
await test('400 → hold',                               () => eq(decideFromLookup({ status: 400, apiReachable: true }).action, Action.HOLD, 'action'));
await test('no non-200 branch ever returns serve or retire', () => {
  const cases = [400, 401, 403, 404, 429, 500, 0].flatMap(s => [true, false].map(r => decideFromLookup({ status: s, body: body(NOT_FOUND), apiReachable: r })));
  const bad = cases.filter(d => d.action === Action.SERVE || d.action === Action.RETIRE);
  assert(bad.length === 0, `${bad.length} branch(es) returned ${bad.map(b => b.action).join(',')}`);
  return `${cases.length} combinations checked`;
});

/* ── decideFromInventory ──────────────────────────────────────────────── */
heading('decideFromInventory');
await test('first authoritative absence suspends on an idle chain, holds on a busy one, retires on the second (#62)', () => {
  eq(decideFromInventory({ authoritative: true, row: null, missingCompleteRuns: 0, chainIdle: true }).action, Action.SUSPEND);
  eq(decideFromInventory({ authoritative: true, row: null, missingCompleteRuns: 0, chainIdle: false }).action, Action.HOLD);
  eq(decideFromInventory({ authoritative: true, row: null, missingCompleteRuns: 1, chainIdle: false }).action, Action.RETIRE);
  eq(decideFromInventory({ authoritative: false, row: null, missingCompleteRuns: 0, chainIdle: true }).action, Action.HOLD);
});
const row = (over = {}) => ({ id: 'u1', url: 'https://example.com/p', captureStatus: 'processed', jsonLd: { ready: true, stale: false, generatedAt: '2026-09-01T10:00:00.000Z' }, ...over });
await test('ready:true → serve and resets the miss counter', () => {
  const d = decideFromInventory({ authoritative: true, row: row(), missingCompleteRuns: 1 });
  eq(d.action, Action.SERVE, 'action'); eq(d.missingCompleteRuns, 0, 'counter reset');
});
await test('ready:false + processing → hold (R-02a)', () => {
  const d = decideFromInventory({ authoritative: true, row: row({ captureStatus: 'processing', jsonLd: { ready: false, stale: false, generatedAt: null } }), missingCompleteRuns: 0 });
  eq(d.action, Action.HOLD, 'action'); eq(d.rule, 'R-02a', 'rule');
});
await test('ready:false + processed → deactivate (R-02a)', () => {
  const d = decideFromInventory({ authoritative: true, row: row({ jsonLd: { ready: false, stale: false, generatedAt: null } }), missingCompleteRuns: 0 });
  eq(d.action, Action.DEACTIVATE, 'action');
});
await test('ready:false + failed capture → deactivate', () => {
  eq(decideFromInventory({ authoritative: true, row: row({ captureStatus: 'failed', jsonLd: { ready: false, stale: false, generatedAt: null } }), missingCompleteRuns: 0 }).action, Action.DEACTIVATE, 'action');
});
await test('absent once → hold; absent twice → retire (R-02)', () => {
  const a = decideFromInventory({ authoritative: true, row: null, missingCompleteRuns: 0 });
  const b = decideFromInventory({ authoritative: true, row: null, missingCompleteRuns: a.missingCompleteRuns });
  eq(a.action, Action.HOLD, 'first'); eq(a.missingCompleteRuns, 1, 'counter');
  eq(b.action, Action.RETIRE, 'second');
});
await test('a URL that reappears resets the miss counter', () => {
  const gone = decideFromInventory({ authoritative: true, row: null, missingCompleteRuns: 0 });
  const back = decideFromInventory({ authoritative: true, row: row(), missingCompleteRuns: gone.missingCompleteRuns });
  eq(back.missingCompleteRuns, 0, 'counter');
  const goneAgain = decideFromInventory({ authoritative: true, row: null, missingCompleteRuns: back.missingCompleteRuns });
  eq(goneAgain.action, Action.HOLD, 'one absence must not retire after a reappearance');
});
await test('a partial traversal never retires or deactivates', () => {
  for (const r of [null, row({ jsonLd: { ready: false, stale: false, generatedAt: null } })]) {
    eq(decideFromInventory({ authoritative: false, row: r, missingCompleteRuns: 99 }).action, Action.HOLD, 'action');
  }
});

/* ── isAuthoritative ──────────────────────────────────────────────────── */
heading('isAuthoritative');
await test('all chains complete and reconciled → true', () => eq(isAuthoritative([{ completed: true, seen: 4, total: 4 }, { completed: true, seen: 2, total: 2 }]), true, 'result'));
await test('one incomplete chain → false',              () => eq(isAuthoritative([{ completed: true, seen: 4, total: 4 }, { completed: false, seen: 1, total: 2 }]), false, 'result'));
await test('row count short of total → false',          () => eq(isAuthoritative([{ completed: true, seen: 3, total: 4 }]), false, 'result'));
await test('no chains at all → false, not vacuously true', () => eq(isAuthoritative([]), false, 'result'));

/* ── validateEnvelope: required fields and types ──────────────────────── */
heading('validateEnvelope');
await test('a well-formed envelope passes', () => assert(validateEnvelope(env()).ok, validateEnvelope(env()).errors.join('; ')));
await test('each of the eight fields is individually required', () => {
  const fields = ['urlId','chainId','businessId','url','languageCode','stale','generatedAt','jsonLd'];
  for (const f of fields) {
    const e = env(); delete e[f];
    const v = validateEnvelope(e);
    assert(!v.ok && v.errors.some(x => x.includes(f)), `${f} was not required`);
  }
  return `${fields.length} fields`;
});
await test('stale as the string "false" is rejected', () => assert(!validateEnvelope(env({ stale: 'false' })).ok, 'accepted a string where a boolean is required'));
await test('an unparseable generatedAt is rejected',   () => assert(!validateEnvelope(env({ generatedAt: 'yesterday' })).ok, 'accepted a bad timestamp'));
await test('a relative url is rejected',               () => assert(!validateEnvelope(env({ url: '/p' })).ok, 'accepted a relative url'));
await test('jsonLd as an array is accepted',           () => assert(validateEnvelope(env({ jsonLd: [{ '@type': 'A' }, { '@type': 'B' }] })).ok, 'rejected an array graph'));
await test('jsonLd as null is rejected',               () => assert(!validateEnvelope(env({ jsonLd: null })).ok, 'accepted null'));
await test('jsonLd as a scalar is rejected',           () => assert(!validateEnvelope(env({ jsonLd: 'a string' })).ok, 'accepted a scalar root'));
await test('a non-object envelope is rejected',        () => {
  for (const v of [null, 'a string', 42, ['array']]) assert(!validateEnvelope(v).ok, `accepted ${JSON.stringify(v)}`);
});
await test('unknown extra fields are ignored (forward compatible)', () => assert(validateEnvelope(env({ suppressedAt: '2026-09-05T00:00:00Z' })).ok, 'rejected a field API-2 would add'));

/* ── validateEnvelope: the boundaries, where off-by-ones live ─────────── */
heading('validateEnvelope · boundaries');
await test('depth exactly 32 is accepted', () => {
  const v = validateEnvelope(env({ jsonLd: nest(32) }));
  assert(v.ok, 'rejected the limit itself: ' + v.errors.join('; '));
});
await test('depth 33 is rejected', () => {
  const v = validateEnvelope(env({ jsonLd: nest(33) }));
  assert(!v.ok && v.errors.some(e => e.includes('depth')), 'accepted one level past the limit');
});
await test('exactly 1 MiB is accepted', () => {
  const filler = 'a'.repeat(1024 * 1024 - Buffer.byteLength('{"b":""}', 'utf8'));
  const doc = { b: filler };
  eq(Buffer.byteLength(JSON.stringify(doc), 'utf8'), 1024 * 1024, 'fixture is not exactly 1 MiB');
  assert(validateEnvelope(env({ jsonLd: doc })).ok, 'rejected the limit itself');
});
await test('1 MiB + 1 byte is rejected', () => {
  const filler = 'a'.repeat(1024 * 1024 - Buffer.byteLength('{"b":""}', 'utf8') + 1);
  const doc = { b: filler };
  eq(Buffer.byteLength(JSON.stringify(doc), 'utf8'), 1024 * 1024 + 1, 'fixture is not 1 MiB + 1');
  assert(!validateEnvelope(env({ jsonLd: doc })).ok, 'accepted one byte past the limit');
});

/* ── checkBinding (ZT-03) ─────────────────────────────────────────────── */
heading('checkBinding');
const bindOpts = { businessId: 'b1', allowedHosts: ['example.com'] };
await test('a matching artifact binds',                () => assert(checkBinding(env(), bindOpts).ok, checkBinding(env(), bindOpts).errors.join('; ')));
await test('a foreign businessId is rejected (AC-19)', () => {
  const v = checkBinding(env({ businessId: 'b2' }), bindOpts);
  assert(!v.ok && v.errors[0].startsWith('AIVIS_SCOPE_MISMATCH'), 'accepted a foreign business');
});
await test('a foreign host is rejected',               () => assert(!checkBinding(env({ url: 'https://andere.de/p' }), bindOpts).ok, 'accepted a foreign host'));
await test('host comparison is case-insensitive',      () => assert(checkBinding(env({ url: 'https://EXAMPLE.com/p' }), bindOpts).ok, 'rejected an uppercase host'));
await test('chain membership is enforced only when inventory context exists', () => {
  const e = env({ chainId: 'unknown' });
  assert(checkBinding(e, bindOpts).ok, 'should pass with no chain context');
  assert(!checkBinding(e, { ...bindOpts, knownChainIds: ['c1', 'c2'] }).ok, 'should fail with chain context');
});
await test('the returned url must be the requested one or its slash alias', () => {
  assert(checkBinding(env(), { ...bindOpts, requestedUrl: 'https://example.com/p' }).ok, 'rejected an exact match');
  assert(checkBinding(env({ url: 'https://example.com/p/' }), { ...bindOpts, requestedUrl: 'https://example.com/p' }).ok, 'rejected a slash alias');
  assert(!checkBinding(env({ url: 'https://example.com/other' }), { ...bindOpts, requestedUrl: 'https://example.com/p' }).ok, 'accepted a different page');
});
await test('an unparseable url is rejected, not thrown', () => {
  const v = checkBinding(env({ url: 'not a url' }), bindOpts);
  assert(!v.ok && v.errors.some(e => e.includes('AIVIS_SCHEMA_INVALID')), 'did not report a schema error');
});

/* ── localUrlKeyInput (§07) ───────────────────────────────────────────── */
heading('localUrlKeyInput');
await test('trailing-slash variants collapse to one key', () => eq(localUrlKeyInput('https://example.com/services/'), localUrlKeyInput('https://example.com/services'), 'keys'));
await test('the root path is preserved in both spellings', () => {
  eq(localUrlKeyInput('https://example.com/'), 'https://example.com/', 'with slash');
  eq(localUrlKeyInput('https://example.com'),  'https://example.com/', 'without slash');
});
await test('tracking parameters are stripped and the rest sorted', () => eq(localUrlKeyInput('https://example.com/p?utm_source=x&b=2&a=1&fbclid=z&gclid=q&msclkid=w&_ga=1'), 'https://example.com/p?a=1&b=2', 'key'));
await test('a meaningful query string survives',        () => eq(localUrlKeyInput('https://example.com/p?id=7'), 'https://example.com/p?id=7', 'key'));
await test('host case and fragments normalise',         () => eq(localUrlKeyInput('https://EXAMPLE.com/p#frag'), 'https://example.com/p', 'key'));
await test('default ports are stripped, others kept',   () => {
  eq(localUrlKeyInput('https://example.com:443/p'), 'https://example.com/p', 'https 443');
  eq(localUrlKeyInput('http://example.com:80/p'),   'http://example.com/p',  'http 80');
  eq(localUrlKeyInput('https://example.com:8443/p'), 'https://example.com:8443/p', 'non-default port');
});
await test('percent-encoding is preserved',             () => eq(localUrlKeyInput('https://example.com/caf%C3%A9'), 'https://example.com/caf%C3%A9', 'key'));
await test('a non-http scheme throws rather than passing through', () => throws(() => localUrlKeyInput('ftp://example.com/x'), 'unsupported scheme', 'ftp'));
await test('normalisation is idempotent', () => {
  const once = localUrlKeyInput('https://EXAMPLE.com:443/services/?utm_source=x&b=2#f');
  eq(localUrlKeyInput(once), once, 'second pass changed the key');
  return once;
});

process.exit(finish('unit') ? 1 : 0);
