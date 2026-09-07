// The install skill ships to two agents from two directories. This keeps the
// copies identical, so an edit to one cannot quietly leave the other behind.

import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const A = new URL('../../.claude/skills/install-aivis-os', import.meta.url).pathname;
const B = new URL('../../.codex/skills/install-aivis-os', import.meta.url).pathname;

function tree(root, dir = root, out = new Map()) {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) tree(root, p, out);
    else out.set(relative(root, p), readFileSync(p, 'utf8'));
  }
  return out;
}

const problems = [];
let a, b;
try { a = tree(A); b = tree(B); } catch (e) { problems.push(`missing skill directory: ${e.message}`); }
if (a && b) {
  for (const f of new Set([...a.keys(), ...b.keys()])) {
    if (!a.has(f)) problems.push(`.codex has ${f}, .claude does not`);
    else if (!b.has(f)) problems.push(`.claude has ${f}, .codex does not`);
    else if (a.get(f) !== b.get(f)) problems.push(`${f} differs between .claude and .codex`);
  }
  const fm = a.get('SKILL.md') || '';
  if (!/^---\nname: install-aivis-os\ndescription: .+\n---/m.test(fm)) problems.push('SKILL.md frontmatter must start with name and description');
}
if (problems.length) { console.error('skills check FAILED:'); problems.forEach(p => console.error('  - ' + p)); process.exit(1); }
console.log(`skills check ok — ${a.size} files identical in .claude/skills and .codex/skills`);
