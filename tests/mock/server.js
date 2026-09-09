// Mock AIVIS Public API v1 — contract 1.9.0.
//
// Mirrors the vendored tests/fixtures/openapi-v1.json and the behaviour of the
// merged implementation: stable error codes, version / min-client headers and
// 426, ETag / If-None-Match / 304, rate-limit headers and 429, business-bound
// tokens, chain languageCode, unpublish (410 withdrawn + suppressedAt), the
// per-URL inventory lookup and the change feed.
// No dependencies: the point is that `node tests/contract/run.mjs` just works.

import http from 'node:http';
import { createHash } from 'node:crypto';
import { businesses, chains, urls, tokens } from './data.js';

const PREFIX = 'aivis_';
export const API_VERSION = '1.9.0';
export const MIN_CLIENT = '1.0.0';
export const RATE_LIMIT = { perToken: { limit: 600, windowSeconds: 60 }, perIp: { limit: 60, windowSeconds: 60 } };
const DEPRECATED_SINCE = '2026-09-08';
const SUNSET = '2027-09-08';

// Retraction scenario stage for u_retract, advanced via POST /__scenario.
// live | regenerating | unready | withdrawn | deleted
export let stage = 'live';
let stageChangedAt = '2026-08-15T10:00:00.000Z';
export const setStage = s => { stage = s; stageChangedAt = new Date().toISOString(); };
// Test-only: make the next N authenticated requests answer 429.
let throttleNext = 0;
// Fixed-window counters per bucket (token or address).
const windows = new Map();

function visibleUrls() {
  return urls.filter(u => {
    if (u.id !== 'u_retract') return true;
    return stage !== 'deleted';           // deleted = the Url row is gone
  }).map(u => {
    if (u.id !== 'u_retract') return u;
    if (stage === 'live') return u;
    if (stage === 'withdrawn') return { ...u, suppressedAt: stageChangedAt, updatedAt: stageChangedAt };
    // artifact withdrawn; captureStatus distinguishes regeneration from removal
    return { ...u, artifact: null, updatedAt: stageChangedAt, captureStatus: stage === 'regenerating' ? 'processing' : 'processed' };
  });
}

const etagOf = payload => '"' + createHash('sha256').update(payload).digest('hex') + '"';

function baseHeaders(bucket) {
  const w = windows.get(bucket) || { count: 0, reset: 0 };
  return {
    'x-aivis-api-version': API_VERSION,
    'x-aivis-min-client': MIN_CLIENT,
    'cache-control': 'private, no-cache',
    'vary': 'Authorization',
    'x-ratelimit-limit': String(bucket.startsWith('ip:') ? RATE_LIMIT.perIp.limit : RATE_LIMIT.perToken.limit),
    'x-ratelimit-remaining': String(Math.max(0, (bucket.startsWith('ip:') ? RATE_LIMIT.perIp.limit : RATE_LIMIT.perToken.limit) - w.count)),
    'x-ratelimit-reset': String(w.reset),
  };
}

function send(req, res, code, body, extra = {}) {
  const payload = body === null ? '' : JSON.stringify(body);
  const headers = { ...baseHeaders(req.bucket || 'ip:local'), ...extra };
  if (code === 200) {
    const etag = etagOf(payload);
    headers.etag = etag;
    const inm = req.headers['if-none-match'];
    if (inm && inm.split(',').map(s => s.trim().replace(/^W\//, '')).some(v => v === '*' || v === etag)) {
      res.writeHead(304, headers);
      return res.end();
    }
  }
  if (payload !== '') headers['content-type'] = 'application/json';
  res.writeHead(code, headers);
  res.end(payload);
}
const fail = (req, res, code, errCode, message, extra = {}) => send(req, res, code, { error: { code: errCode, message } }, extra);

// Matching is exact plus a trailing-slash variant. Nothing else — mirrors
// lib/public-api/lookup.ts, which is deliberate.
export function urlLookupVariants(raw) {
  const v = new Set([raw]);
  if (raw.endsWith('/')) v.add(raw.replace(/\/+$/, ''));
  else v.add(`${raw}/`);
  return [...v];
}

// Non-stale first, then newest.
export function compareCandidates(a, b) {
  const as = a.artifact.staleAt ? 1 : 0, bs = b.artifact.staleAt ? 1 : 0;
  if (as !== bs) return as - bs;
  return new Date(b.artifact.generatedAt) - new Date(a.artifact.generatedAt);
}

// Mirrors lib/pagination.ts exactly. Note what it does NOT do: it never rejects
// a bad `limit`. parseInt("abc") is NaN, `NaN || 100` is 100, and the result
// is clamped to [1, 200] — so a nonsense limit silently becomes the default.
function paginate(items, q) {
  const raw = q.get('limit');
  const limit = Math.min(Math.max(parseInt(raw || '100', 10) || 100, 1), 200);
  const cursor = q.get('cursor');
  let start = 0;
  if (cursor !== null) {
    const idx = items.findIndex(i => i.id === cursor);
    start = idx < 0 ? items.length : idx + 1;
  }
  const page = items.slice(start, start + limit);
  const hasMore = start + limit < items.length;
  return {
    items: page,
    nextCursor: hasMore ? page[page.length - 1].id : null,
    hasMore,
    total: items.length,
  };
}

const semverLt = (a, b) => {
  const pa = a.split('.').map(Number), pb = b.split('.').map(Number);
  for (let i = 0; i < 3; i++) { if ((pa[i] || 0) !== (pb[i] || 0)) return (pa[i] || 0) < (pb[i] || 0); }
  return false;
};

const envelope = u => ({
  urlId: u.id, chainId: u.chainId, businessId: u.businessId, url: u.url,
  languageCode: u.languageCode, stale: !!u.artifact.staleAt,
  generatedAt: u.artifact.generatedAt, jsonLd: u.artifact.jsonLd,
});

const inventoryRow = u => ({
  id: u.id, url: u.url, chainId: u.chainId, businessId: u.businessId,
  languageCode: u.languageCode, layer: u.layer,
  captureStatus: u.captureStatus,
  jsonLd: {
    ready: !!u.artifact && !u.suppressedAt,
    stale: u.artifact ? !!u.artifact.staleAt : false,
    generatedAt: u.artifact ? u.artifact.generatedAt : null,
    suppressedAt: u.suppressedAt || null,
  },
});

// Chain language fields derived from the rows (contract 1.4.0, #274).
const chainItem = c => {
  const rows = visibleUrls().filter(u => u.chainId === c.id);
  const codes = [...new Set(rows.map(u => u.languageCode))].sort();
  const declared = [...(c.declared || [])].sort();
  const languageCode = codes.length === 1 ? codes[0] : (codes.length === 0 && declared.length === 1 ? declared[0] : null);
  const { declared: _d, ...rest } = c;
  return { ...rest, urlCount: rows.length, languageCode, urlLanguageCodes: codes, declaredLanguageCodes: declared };
};

const changelog = () => ({
  apiVersion: API_VERSION,
  minClientVersion: MIN_CLIENT,
  nextMinClient: null,
  rateLimits: RATE_LIMIT,
  deprecations: [{ path: '/deprecation-probe', since: DEPRECATED_SINCE, sunset: SUNSET, reason: 'Test endpoint — deprecated by design so integrations can verify their deprecation handling.' }],
  entries: [
    { version: '1.9.0', date: '2026-09-08', notes: ['Rate limits (#271).'] },
    { version: '1.8.0', date: '2026-09-08', notes: ['Conditional requests (#268).'] },
    { version: '1.7.0', date: '2026-09-08', notes: ['Change feed (#270).'] },
    { version: '1.6.0', date: '2026-09-08', notes: ['Per-URL inventory lookup (#269).'] },
    { version: '1.5.0', date: '2026-09-08', notes: ['Explicit retraction (#266).'] },
    { version: '1.4.0', date: '2026-09-08', notes: ['Chain language (#274).'] },
    { version: '1.3.0', date: '2026-09-08', notes: ['Business-scoped tokens (#265).'] },
    { version: '1.2.0', date: '2026-09-08', notes: ['Versioning and compatibility policy (#275).'] },
    { version: '1.1.0', date: '2026-09-08', notes: ['Stable error.code (#267).'] },
    { version: '1.0.0', date: '2026-07-27', notes: ['Initial public API (#142).'] },
  ],
});

export function createServer() {
  return http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    const p = url.pathname;
    const q = url.searchParams;

    // Test-only control plane, never part of the real API.
    if (p === '/__scenario' && req.method === 'POST') {
      if (q.has('stage')) setStage(q.get('stage'));
      if (q.has('throttle')) throttleNext = parseInt(q.get('throttle'), 10) || 0;
      if (q.has('reset')) windows.clear();
      res.writeHead(200, { 'content-type': 'application/json' });
      return res.end(JSON.stringify({ stage, throttleNext }));
    }

    if (!p.startsWith('/api/public/v1')) return fail(req, res, 404, 'not_found', 'Not found');
    const route = p.slice('/api/public/v1'.length);

    // Documentation routes: unauthenticated, never 426, never rate-limited.
    if (route === '/openapi.json' || route === '/docs') return send(req, res, 200, { ok: true });
    if (route === '/changelog') return send(req, res, 200, changelog());

    // Minimum client (contract 1.2.0): `aivis-os/<semver>` below MIN_CLIENT is 426.
    const ua = /aivis-os\/(\d+\.\d+\.\d+)/.exec(req.headers['user-agent'] || '');
    if (ua && semverLt(ua[1], MIN_CLIENT)) {
      return fail(req, res, 426, 'client_too_old', `Client ${ua[1]} is older than the minimum ${MIN_CLIENT}`, { upgrade: `aivis-os/${MIN_CLIENT}` });
    }

    const auth = req.headers.authorization;
    const bearer = auth && auth.toLowerCase().startsWith('bearer ') ? auth.slice('bearer '.length).trim() : null;
    const tok = bearer && bearer.startsWith(PREFIX) ? tokens[bearer] : undefined;
    // Rate limiting (contract 1.9.0): a presented, valid token is charged to its
    // own bucket; missing or rejected tokens are charged to the address.
    req.bucket = tok ? `token:${bearer}` : 'ip:local';
    const limit = tok ? RATE_LIMIT.perToken : RATE_LIMIT.perIp;
    const now = Math.floor(Date.now() / 1000);
    let w = windows.get(req.bucket);
    if (!w || w.reset <= now) { w = { count: 0, reset: now + limit.windowSeconds }; windows.set(req.bucket, w); }
    w.count++;
    if (w.count > limit.limit || (tok && throttleNext > 0)) {
      if (tok && throttleNext > 0) throttleNext--;
      return fail(req, res, 429, 'rate_limited', 'Rate limit exceeded', { 'retry-after': String(Math.max(1, w.reset - now)) });
    }

    if (!bearer) return fail(req, res, 401, 'missing_token', 'Missing bearer token');
    if (!tok) return fail(req, res, 401, 'invalid_token', 'Invalid API token');

    // Business binding (contract 1.3.0): everything outside the bound business is 404.
    const inScope = businessId => !tok.businessId || tok.businessId === businessId;

    if (route === '/me') {
      const b = tok.businessId ? businesses.find(x => x.id === tok.businessId) : null;
      return send(req, res, 200, {
        email: tok.businessId ? null : 'marketing@epoint.ro',
        name: tok.businessId ? null : 'Daniel',
        tokenName: tok.name,
        businessId: tok.businessId,
        business: b ? { id: b.id, name: b.name, baseUrl: b.baseUrl } : null,
        permissions: ['jsonld:read'],
      });
    }

    if (route === '/deprecation-probe') {
      return send(req, res, 200, { ok: true, deprecatedSince: DEPRECATED_SINCE, sunset: SUNSET }, {
        deprecation: `@${Math.floor(Date.parse(DEPRECATED_SINCE) / 1000)}`,
        sunset: new Date(SUNSET).toUTCString(),
        link: '</api/public/v1/changelog>; rel="deprecation"',
      });
    }

    if (route === '/businesses') {
      return send(req, res, 200, paginate(businesses.filter(b => inScope(b.id)), q));
    }

    let m = route.match(/^\/businesses\/([^/]+)\/chains$/);
    if (m) {
      const list = chains[m[1]];
      if (!list || !inScope(m[1])) return fail(req, res, 404, 'business_not_found', 'Business not found');
      const r = paginate(list, q);
      return send(req, res, 200, { ...r, items: r.items.map(chainItem) });
    }

    m = route.match(/^\/chains\/([^/]+)\/urls$/);
    if (m) {
      const chainId = m[1];
      const owner = Object.keys(chains).find(b => chains[b].some(c => c.id === chainId));
      if (!owner || !inScope(owner)) return fail(req, res, 404, 'chain_not_found', 'Chain not found');
      let rows = visibleUrls().filter(u => u.chainId === chainId);
      // Per-URL narrowing (contract 1.6.0): exact + trailing-slash variant; a miss is an empty page.
      if (q.has('url')) {
        const variants = urlLookupVariants(q.get('url'));
        rows = rows.filter(u => variants.includes(u.url));
      }
      // Change feed (contract 1.7.0): strictly after an instant WITH a zone.
      if (q.has('updatedSince')) {
        const since = q.get('updatedSince');
        if (!/(Z|[+-]\d\d:\d\d)$/.test(since) || Number.isNaN(Date.parse(since))) {
          return fail(req, res, 400, 'bad_request', 'updatedSince must be an ISO-8601 date-time with a zone');
        }
        const t = Date.parse(since);
        rows = rows.filter(u => Date.parse(u.updatedAt) > t);
      }
      const r = paginate(rows, q);
      return send(req, res, 200, { ...r, items: r.items.map(inventoryRow) });
    }

    m = route.match(/^\/urls\/([^/]+)$/);
    if (m) {
      const u = visibleUrls().find(x => x.id === m[1]);
      if (!u || !inScope(u.businessId)) return fail(req, res, 404, 'url_not_found', 'URL not found');
      return send(req, res, 200, inventoryRow(u));
    }

    m = route.match(/^\/urls\/([^/]+)\/jsonld$/);
    if (m) {
      const u = visibleUrls().find(x => x.id === m[1]);
      if (!u || !inScope(u.businessId)) return fail(req, res, 404, 'url_not_found', 'URL not found');
      if (u.suppressedAt) return fail(req, res, 410, 'withdrawn', 'JSON-LD unpublished for this URL');
      if (!u.artifact) return fail(req, res, 404, 'jsonld_not_generated', 'JSON-LD not generated yet for this URL');
      return send(req, res, 200, envelope(u));
    }

    if (route === '/jsonld') {
      const target = q.get('url');
      if (!target || !/^https?:\/\//.test(target)) {
        return fail(req, res, 400, 'bad_request', 'Query parameter `url` must be an absolute URL, e.g. ?url=https://example.com/page');
      }
      const variants = urlLookupVariants(target);
      const candidates = visibleUrls().filter(u => variants.includes(u.url) && inScope(u.businessId));
      if (candidates.length === 0) return fail(req, res, 404, 'url_not_found', 'URL not found in your businesses');
      // Unpublished copies leave the tie-break (contract 1.5.0); when no published
      // copy has a document and any copy is unpublished, the answer is 410.
      const published = candidates.filter(u => u.artifact && !u.suppressedAt);
      if (published.length === 0) {
        if (candidates.some(u => u.suppressedAt)) return fail(req, res, 410, 'withdrawn', 'JSON-LD unpublished for this URL');
        return fail(req, res, 404, 'jsonld_not_generated', 'JSON-LD not generated yet for this URL');
      }
      const best = published.sort(compareCandidates)[0];
      return send(req, res, 200, envelope(best));
    }

    return fail(req, res, 404, 'not_found', 'Not found');
  });
}

if (process.argv[1] && process.argv[1].endsWith('server.js')) {
  const port = Number(process.env.PORT || 8787);
  createServer().listen(port, () => console.log(`mock AIVIS API on http://127.0.0.1:${port}/api/public/v1`));
}
