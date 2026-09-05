// Mock AIVIS Public API v1.
//
// Mirrors the vendored tests/fixtures/openapi-v1.json and the observed
// behaviour of the merged implementation — including the two distinct 404
// messages, which the published OpenAPI description flattens into one line.
// No dependencies: the point is that `node tests/contract/run.mjs` just works.

import http from 'node:http';
import { businesses, chains, urls, VALID_TOKEN } from './data.js';

const PREFIX = 'aivis_';

// Retraction scenario stage for u_retract, advanced via POST /__scenario.
// live | regenerating | unready | deleted
export let stage = 'live';
export const setStage = s => { stage = s; };

function visibleUrls() {
  return urls.filter(u => {
    if (u.id !== 'u_retract') return true;
    return stage !== 'deleted';           // deleted = the Url row is gone
  }).map(u => {
    if (u.id !== 'u_retract') return u;
    if (stage === 'live') return u;
    // artifact withdrawn; captureStatus distinguishes regeneration from removal
    return { ...u, artifact: null, captureStatus: stage === 'regenerating' ? 'processing' : 'processed' };
  });
}

const json = (res, code, body) => {
  const payload = JSON.stringify(body);
  res.writeHead(code, { 'content-type': 'application/json' });
  res.end(payload);
};
const fail = (res, code, message) => json(res, code, { error: { message } });

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

// Mirrors lib/pagination.ts on origin/main exactly. Note what it does NOT do:
// it never rejects a bad `limit`. parseInt("abc") is NaN, `NaN || 100` is 100,
// and the result is clamped to [1, 200] — so a nonsense limit silently becomes
// the default and returns 200. An earlier version of this mock invented a 400
// there; the OpenAPI conformance check caught it, since the document declares
// no 400 for the list endpoints.
function paginate(items, q) {
  const raw = q.get('limit');
  const limit = Math.min(Math.max(parseInt(raw || '100', 10) || 100, 1), 200);
  const cursor = q.get('cursor');
  let start = 0;
  if (cursor !== null) {
    const idx = items.findIndex(i => i.id === cursor);
    // Unverified against the real API: Prisma is handed the cursor id directly,
    // so an unknown one probably errors rather than paging from the start. The
    // document declares no error response for it, so the mock does not invent
    // one — and nothing asserts this branch.
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

const envelope = u => ({
  urlId: u.id, chainId: u.chainId, businessId: u.businessId, url: u.url,
  languageCode: u.languageCode, stale: !!u.artifact.staleAt,
  generatedAt: u.artifact.generatedAt, jsonLd: u.artifact.jsonLd,
});

const inventoryRow = u => ({
  id: u.id, url: u.url, languageCode: u.languageCode, layer: u.layer,
  captureStatus: u.captureStatus,
  jsonLd: {
    ready: !!u.artifact,
    stale: u.artifact ? !!u.artifact.staleAt : false,
    generatedAt: u.artifact ? u.artifact.generatedAt : null,
  },
});

export function createServer() {
  return http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    const p = url.pathname;
    const q = url.searchParams;

    // Test-only control plane, never part of the real API.
    if (p === '/__scenario' && req.method === 'POST') { setStage(q.get('stage')); return json(res, 200, { stage }); }

    if (!p.startsWith('/api/public/v1')) return fail(res, 404, 'Not found');
    const route = p.slice('/api/public/v1'.length);

    // Docs and spec are deliberately unauthenticated.
    if (route === '/openapi.json' || route === '/docs') return json(res, 200, { ok: true });

    const auth = req.headers.authorization;
    if (!auth || !auth.toLowerCase().startsWith('bearer ')) return fail(res, 401, 'Missing bearer token');
    const token = auth.slice('bearer '.length).trim();
    if (!token.startsWith(PREFIX) || token !== VALID_TOKEN) return fail(res, 401, 'Invalid API token');

    if (route === '/me') return json(res, 200, { email: 'marketing@epoint.ro', name: 'Daniel', tokenName: 'wordpress-example.com' });

    if (route === '/businesses') {
      const r = paginate(businesses, q);
      return r.error ? fail(res, 400, r.error) : json(res, 200, r);
    }

    let m = route.match(/^\/businesses\/([^/]+)\/chains$/);
    if (m) {
      const list = chains[m[1]];
      if (!list) return fail(res, 404, 'Business not found');
      const r = paginate(list, q);
      return r.error ? fail(res, 400, r.error) : json(res, 200, r);
    }

    m = route.match(/^\/chains\/([^/]+)\/urls$/);
    if (m) {
      const chainId = m[1];
      const known = Object.values(chains).flat().some(c => c.id === chainId);
      if (!known) return fail(res, 404, 'Chain not found');
      const rows = visibleUrls().filter(u => u.chainId === chainId);
      const r = paginate(rows, q);
      if (r.error) return fail(res, 400, r.error);
      return json(res, 200, { ...r, items: r.items.map(inventoryRow) });
    }

    m = route.match(/^\/urls\/([^/]+)\/jsonld$/);
    if (m) {
      const u = visibleUrls().find(x => x.id === m[1]);
      if (!u) return fail(res, 404, 'URL not found');
      if (!u.artifact) return fail(res, 404, 'JSON-LD not generated yet for this URL');
      return json(res, 200, envelope(u));
    }

    if (route === '/jsonld') {
      const target = q.get('url');
      if (!target || !/^https?:\/\//.test(target)) {
        return fail(res, 400, 'Query parameter `url` must be an absolute URL, e.g. ?url=https://example.com/page');
      }
      const variants = urlLookupVariants(target);
      const candidates = visibleUrls().filter(u => variants.includes(u.url));
      if (candidates.length === 0) return fail(res, 404, 'URL not found in your businesses');
      const withArtifacts = candidates.filter(u => u.artifact);
      if (withArtifacts.length === 0) return fail(res, 404, 'JSON-LD not generated yet for this URL');
      const best = withArtifacts.sort(compareCandidates)[0];
      return json(res, 200, envelope(best));
    }

    return fail(res, 404, 'Not found');
  });
}

if (process.argv[1] && process.argv[1].endsWith('server.js')) {
  const port = Number(process.env.PORT || 8787);
  createServer().listen(port, () => console.log(`mock AIVIS API on http://127.0.0.1:${port}/api/public/v1`));
}
