// Two rules for everything a person reads (AGENTS.md "Name and addresses", spec §11):
//
//   1. The word mark is "Aivis-OS". All-capitals "AIVIS" appears only in technical
//      names, where an underscore follows (AIVIS_API_TOKEN, AIVIS_* codes).
//   2. The two instance addresses live in code and are never spelled out in
//      the interface, descriptions or documentation.
//
// The addresses are read from Options::ENVIRONMENTS, so this file does not
// spell them out either.
//
//   node tests/docs/check-copy.mjs
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const root = new URL('../../', import.meta.url);
const read = f => readFileSync(new URL(f, root), 'utf8');

const hosts = [...read('src/Storage/Options.php').matchAll(/'(?:production|test)'\s*=>\s*'https?:\/\/([^'\/]+)'/g)].map(m => m[1]);
if (hosts.length !== 2) { console.error(`expected two instance addresses in Options::ENVIRONMENTS, found ${hosts.length}`); process.exit(1); }

// Code that has to use an address: the list itself, tests of it, and the tooling that fetches the contract.
const ADDRESS_OK = [/^src\/Storage\/Options\.php$/, /^tests\/php\//, /^tests\/fixtures\//, /^\.github\/workflows\/api-drift\.yml$/];
// Aivis-OS's own document, vendored and never edited by hand.
const MARK_OK = [/^tests\/fixtures\/openapi-v1\.json$/, /^tests\/docs\/check-copy\.mjs$/ /* names the rule */];
const SKIP = [/^vendor\//, /^build\//, /^docs\/screenshots\//, /^assets\//, /\.(png|jpg|jpeg|gif|ico|zip|lock)$/, /^LICENSE$/];

const mark = /(^|[^A-Za-z0-9_])AIVIS(?![A-Za-z0-9_])/;
const files = execFileSync('git', ['ls-files'], { cwd: root, encoding: 'utf8' }).split('\n').filter(Boolean);
const problems = [];
for (const f of files) {
  if (SKIP.some(re => re.test(f))) continue;
  let text;
  try { text = read(f); } catch { continue; }
  text.split('\n').forEach((line, i) => {
    if (!MARK_OK.some(re => re.test(f)) && mark.test(line)) problems.push(`${f}:${i + 1}: word mark — write "Aivis-OS"`);
    if (!ADDRESS_OK.some(re => re.test(f)) && hosts.some(h => line.toLowerCase().includes(h))) problems.push(`${f}:${i + 1}: instance address — name the environment instead`);
  });
}
if (problems.length) { console.error(problems.join('\n') + `\n\n${problems.length} problem(s)`); process.exit(1); }
console.log(`copy check: ${files.length} files, word mark and instance addresses clean`);
