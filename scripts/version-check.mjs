// The plugin header is the single source of truth for the version. Everything
// else must agree with it, and a release fails if anything does not.
//
//   node scripts/version-check.mjs           # header == constant == readme == CHANGELOG
//   node scripts/version-check.mjs v1.2.3    # ...and == the given tag
import { readFileSync } from 'node:fs';

const read = f => readFileSync(new URL('../' + f, import.meta.url), 'utf8');
const pick = (re, s, what) => { const m = s.match(re); if (!m) throw new Error(`cannot find ${what}`); return m[1]; };

const main      = read('aivis-os.php');
const header    = pick(/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.]+)?)\s*$/m, main, 'Version header');
const constant  = pick(/define\(\s*'AIVIS_OS_VERSION',\s*'([^']+)'\s*\)/, main, 'AIVIS_OS_VERSION');
const readme    = pick(/^Stable tag:\s*(\S+)\s*$/m, read('readme.txt'), 'readme.txt Stable tag');
const changelog = pick(/^## \[?v?([0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.]+)?)\]?/m, read('CHANGELOG.md'), 'CHANGELOG top heading');
const tag       = process.argv[2] ? process.argv[2].replace(/^refs\/tags\//, '').replace(/^v/, '') : null;

const rows = [['aivis-os.php header', header], ['AIVIS_OS_VERSION', constant], ['readme.txt Stable tag', readme], ['CHANGELOG.md', changelog]];
if (tag !== null) rows.push(['git tag', tag]);

const distinct = [...new Set(rows.map(r => r[1]))];
for (const [k, v] of rows) console.log(`${k.padEnd(24)} ${v}`);
if (distinct.length !== 1) { console.error(`\nVERSION MISMATCH: ${distinct.join(' vs ')}`); process.exit(1); }
console.log(`\nversion ${header} is consistent`);
