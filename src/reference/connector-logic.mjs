// Executable reference for the rules in docs/SPECIFICATION.md.
//
// The shipping connector is PHP. This module exists so the *decisions* — which
// are the part that can silently pull structured data off a customer's site —
// are testable before any PHP is written, and so the contract suite asserts
// behaviour rather than prose. Keep it in step with §04, §06, §07 and §08.

/** Actions a refresh or inventory pass can produce. */
export const Action = {
  SERVE:      'serve',      // store and inject
  HOLD:       'hold',       // keep injecting last-known-good, re-check next run
  SUSPEND:    'suspend',    // stop injecting, keep the row, confirm via inventory
  DEACTIVATE: 'deactivate', // active = 0 (R-02a)
  RETIRE:     'retire',     // deactivate + set retired_at (R-01 confirmed, R-02)
};

export const NOT_FOUND    = 'URL not found in your businesses';
export const NOT_GENERATED = 'JSON-LD not generated yet for this URL';

/**
 * Classify a lookup outcome. The message strings are prose, not contract —
 * anything unrecognised falls to 'unreadable', which is treated conservatively.
 * API-3 would replace this with a stable `code`.
 */
export function classifyLookup({ status, body }) {
  if (status === 200) return 'ok';
  if (status === 400) return 'bad_request';
  if (status === 401) return 'auth';
  if (status === 403) return 'account';
  if (status === 429) return 'throttled';
  if (status >= 500 || status === 0) return 'transport';
  if (status === 404) {
    const m = body && body.error && body.error.message;
    if (m === NOT_FOUND) return 'url_gone';
    if (m === NOT_GENERATED) return 'not_generated';
    return 'unreadable';
  }
  return 'unreadable';
}

/**
 * R-01 / R-01a — decide what a single refresh attempt does to a stored row.
 * `apiReachable` must be confirmed within the same run (another request
 * succeeded, or a /me probe passed); without it a 404 proves nothing.
 */
export function decideFromLookup({ status, body, apiReachable }) {
  const kind = classifyLookup({ status, body });
  switch (kind) {
    case 'ok':            return { action: Action.SERVE,   rule: null,      kind };
    // The Url row is gone. Cascade delete is how a retraction looks today.
    case 'url_gone':      return apiReachable
                                 ? { action: Action.SUSPEND, rule: 'R-01',  kind }
                                 : { action: Action.HOLD,    rule: 'R-01',  kind };
    // The page still exists; only the artifact is missing. Never deactivate.
    case 'not_generated': return { action: Action.HOLD,    rule: 'R-01a',   kind };
    // A signal we cannot read is not evidence. Keep serving, confirm later.
    case 'unreadable':    return { action: Action.HOLD,    rule: 'R-01a',   kind, needsInventoryConfirmation: true };
    case 'auth':
    case 'account':
    case 'throttled':
    case 'transport':
    case 'bad_request':   return { action: Action.HOLD,    rule: null,      kind };
    default:              return { action: Action.HOLD,    rule: null,      kind };
  }
}

/**
 * R-02 / R-02a — decide from an authoritative inventory pass. A non-authoritative
 * pass never retires or deactivates anything.
 */
export function decideFromInventory({ authoritative, row, missingCompleteRuns }) {
  if (!authoritative) return { action: Action.HOLD, rule: null, reason: 'partial traversal' };

  if (!row) {
    const runs = missingCompleteRuns + 1;
    return runs >= 2
      ? { action: Action.RETIRE, rule: 'R-02', missingCompleteRuns: runs }
      : { action: Action.HOLD,   rule: 'R-02', missingCompleteRuns: runs };
  }
  if (row.jsonLd && row.jsonLd.ready) return { action: Action.SERVE, rule: null, missingCompleteRuns: 0 };
  // ready:false — regeneration in flight is not a withdrawal.
  if (row.captureStatus === 'processing') return { action: Action.HOLD, rule: 'R-02a', missingCompleteRuns: 0 };
  return { action: Action.DEACTIVATE, rule: 'R-02a', missingCompleteRuns: 0 };
}

/** §03 — the eight required envelope fields, with types. */
const ENVELOPE = {
  urlId: 'string', chainId: 'string', businessId: 'string', url: 'string',
  languageCode: 'string', stale: 'boolean', generatedAt: 'string', jsonLd: 'object-or-array',
};

export function validateEnvelope(obj, { maxBytes = 1024 * 1024, maxDepth = 32 } = {}) {
  const errors = [];
  if (obj === null || typeof obj !== 'object' || Array.isArray(obj)) {
    return { ok: false, errors: ['envelope must be a JSON object'] };
  }
  for (const [field, type] of Object.entries(ENVELOPE)) {
    if (!(field in obj)) { errors.push(`missing field: ${field}`); continue; }
    const v = obj[field];
    if (type === 'string'  && typeof v !== 'string')  errors.push(`${field} must be a string`);
    if (type === 'boolean' && typeof v !== 'boolean') errors.push(`${field} must be a boolean`);
    if (type === 'object-or-array' && (v === null || typeof v !== 'object')) errors.push('jsonLd must be an object or array');
  }
  if (typeof obj.generatedAt === 'string' && Number.isNaN(Date.parse(obj.generatedAt))) {
    errors.push('generatedAt must be a valid timestamp');
  }
  if (typeof obj.url === 'string' && !/^https?:\/\//.test(obj.url)) errors.push('url must be absolute');
  if (errors.length === 0) {
    const bytes = Buffer.byteLength(JSON.stringify(obj.jsonLd), 'utf8');
    if (bytes > maxBytes) errors.push(`jsonLd exceeds ${maxBytes} bytes`);
    if (depthOf(obj.jsonLd) > maxDepth) errors.push(`jsonLd nesting exceeds depth ${maxDepth}`);
  }
  return { ok: errors.length === 0, errors };
}

function depthOf(v, d = 1) {
  if (v === null || typeof v !== 'object') return d;
  let max = d;
  for (const child of Array.isArray(v) ? v : Object.values(v)) {
    const cd = depthOf(child, d + 1);
    if (cd > max) max = cd;
  }
  return max;
}

/** §07 ZT-03 — binding. businessId always; chainId when inventory context exists. */
export function checkBinding(env, { businessId, allowedHosts, knownChainIds = null, requestedUrl = null }) {
  const errors = [];
  if (env.businessId !== businessId) errors.push(`AIVIS_SCOPE_MISMATCH: businessId ${env.businessId} != ${businessId}`);
  if (knownChainIds && !knownChainIds.includes(env.chainId)) errors.push(`AIVIS_SCOPE_MISMATCH: chainId ${env.chainId} not in the selected business`);
  let host = null;
  try { host = new URL(env.url).host.toLowerCase(); } catch { errors.push('AIVIS_SCHEMA_INVALID: url unparseable'); }
  if (host && !allowedHosts.map(h => h.toLowerCase()).includes(host)) errors.push(`AIVIS_SCOPE_MISMATCH: host ${host} not allowed`);
  if (requestedUrl && env.url !== requestedUrl && !slashAliases(requestedUrl).includes(env.url)) {
    errors.push('AIVIS_SCOPE_MISMATCH: returned url is not the requested url or its slash alias');
  }
  return { ok: errors.length === 0, errors };
}

const slashAliases = raw => raw.endsWith('/') ? [raw, raw.replace(/\/+$/, '')] : [raw, `${raw}/`];

/**
 * §07 — the local index key. NOT what goes on the wire: outbound lookups send
 * the permalink unmodified, because AIVIS applies no normalization on purpose.
 */
const TRACKING = [/^utm_/i, /^gclid$/i, /^fbclid$/i, /^msclkid$/i, /^_ga$/i];

export function localUrlKeyInput(raw) {
  const u = new URL(raw);
  if (u.protocol !== 'http:' && u.protocol !== 'https:') throw new Error('unsupported scheme');
  u.protocol = u.protocol.toLowerCase();
  u.hostname = u.hostname.toLowerCase();
  u.hash = '';
  if ((u.protocol === 'https:' && u.port === '443') || (u.protocol === 'http:' && u.port === '80')) u.port = '';
  const keep = [...u.searchParams.entries()].filter(([k]) => !TRACKING.some(re => re.test(k)));
  keep.sort(([a], [b]) => a < b ? -1 : a > b ? 1 : 0);
  u.search = '';
  for (const [k, v] of keep) u.searchParams.append(k, v);
  // Trailing slash canonicalises to the form WITHOUT it (root stays '/').
  if (u.pathname.length > 1 && u.pathname.endsWith('/')) u.pathname = u.pathname.replace(/\/+$/, '');
  return u.toString();
}

/** A sync is authoritative only if every page of every chain completed. */
export function isAuthoritative(chainResults) {
  return chainResults.length > 0 && chainResults.every(c => c.completed && c.seen === c.total);
}
