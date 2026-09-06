// docs/api-requirements.html tells the AIVIS team it is machine-readable.
// This checks that claim still holds, so a copy edit cannot quietly break it.

import { readFileSync } from 'node:fs';

const html = readFileSync(new URL('../../docs/api-requirements.html', import.meta.url), 'utf8');
const problems = [];

const blocks = [...html.matchAll(/<script type="application\/(ld\+json|json)"[^>]*>([\s\S]*?)<\/script>/g)];
if (blocks.length !== 2) problems.push(`expected 2 embedded JSON blocks, found ${blocks.length}`);

let data = null;
for (const [, kind, raw] of blocks) {
  try { const parsed = JSON.parse(raw); if (kind === 'json') data = parsed; }
  catch (e) { problems.push(`${kind} block is not valid JSON: ${e.message}`); }
}

if (data) {
  const ids = (data.requirements || []).map(r => r.id);
  const expected = ['API-1','API-2','API-3','API-4','API-5','API-6','API-7','API-8','API-9','API-10'];
  for (const id of expected) {
    if (!ids.includes(id)) problems.push(`requirements data is missing ${id}`);
    if (!new RegExp(`id="${id}"[^>]*data-requirement="${id}"`).test(html)) problems.push(`${id} has no matching section with id and data-requirement`);
  }
  const blockers = (data.requirements || []).filter(r => r.priority === 'blocker').map(r => r.id);
  if (blockers.length !== 1 || blockers[0] !== 'API-1') problems.push(`expected API-1 to be the only blocker, got [${blockers}]`);
  for (const r of data.requirements || []) {
    for (const f of ['id','title','priority','proposedContract','unlocks']) {
      if (!(f in r)) problems.push(`${r.id} is missing "${f}"`);
    }
  }
}

if (problems.length) { console.error('docs check FAILED:'); problems.forEach(p => console.error('  - ' + p)); process.exit(1); }
console.log(`docs check ok — 2 JSON blocks, ${data.requirements.length} requirements, ids match their sections`);
