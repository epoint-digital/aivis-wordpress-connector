// Re-vendor the live OpenAPI document and report what changed.
//
// The previous specification went four weeks unverified against an API that
// had meanwhile shipped. This is the drift alarm: run it in CI, fail on diff.
import { readFileSync, writeFileSync } from 'node:fs';

// Defaults to the dev instance, which is where the OpenAPI document is served
// today; switch to https://app.aivis-os.com once production publishes it.
const BASE = process.env.AIVIS_API_BASE || 'https://aivis-new.dev.onepoint.ro';
const PATH = new URL('./openapi-v1.json', import.meta.url);

const res = await fetch(`${BASE}/api/public/v1/openapi.json`);
if (!res.ok) { console.error(`fetch failed: ${res.status}`); process.exit(1); }
const next = await res.json();

let prev = null;
try { prev = JSON.parse(readFileSync(PATH, 'utf8')); } catch {}

const surface = d => Object.entries(d.paths).flatMap(([p, ops]) =>
  Object.entries(ops).map(([m, op]) => `${m.toUpperCase()} ${p} :: ${(op.parameters || []).map(x => x.name).sort().join(',')}`)
).sort();

if (prev) {
  const a = new Set(surface(prev)), b = new Set(surface(next));
  const gone = [...a].filter(x => !b.has(x));
  const added = [...b].filter(x => !a.has(x));
  if (gone.length || added.length) {
    console.log('API surface changed:');
    gone.forEach(x => console.log('  - ' + x));
    added.forEach(x => console.log('  + ' + x));
    if (process.argv.includes('--check')) process.exit(1);
  } else {
    console.log(`no surface change (${b.size} operations, version ${next.info.version})`);
  }
}
writeFileSync(PATH, JSON.stringify(next, null, 2) + '\n');
console.log('vendored ' + PATH.pathname.split('/').pop());
