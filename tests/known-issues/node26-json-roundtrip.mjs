// Self-contained reproducer: JSON.parse(JSON.stringify(x)) !== x on Node v26.0.0.
//
//   node tests/known-issues/node26-json-roundtrip.mjs
//
// Uses only Node builtins — no dependencies and no connector code — so it can
// be filed upstream as-is. Also reproduces under --jitless.

let seed = 0x5EED1;
const rnd = () => { seed ^= seed << 13; seed ^= seed >>> 17; seed ^= seed << 5; return ((seed >>> 0) / 0x100000000); };
const pick = a => a[Math.floor(rnd() * a.length)];
const int = n => Math.floor(rnd() * n);
const reseed = () => { seed = 0x5EED1; };

const ATOMS = ['</script>', '</script >', '</SCRIPT>', '<script>alert(1)</script>', '<!--', '-->',
  '<![CDATA[', ']]>', '<svg onload=alert(1)>', '"', "'", '&', '&amp;', '&lt;script&gt;', '\\',
  '\\u003c', '\\\\', '', ' ', '\n', '\r\n', '\t', 'https://example.com/a/b?x=1&y=2', 'Grüße',
  '日本語', '🎉👍', 'a b', ' ', ' ', '😀', 'O’Brien', '@context', '@type', 'schema.org'];

const str = () => {
  if (rnd() < 0.55) return pick(ATOMS);
  let s = ''; const n = int(24);
  for (let i = 0; i < n; i++) s += String.fromCodePoint(int(0.5 > rnd() ? 0x7f : 0x2000) || 32);
  return s;
};
function value(d = 0) {
  const r = rnd();
  if (d > 3 || r < 0.32) {
    const l = rnd();
    if (l < 0.55) return str();
    if (l < 0.7) return int(1000) - 500;
    if (l < 0.8) return (int(10000) - 5000) / 7;
    if (l < 0.9) return rnd() < 0.5;
    return null;
  }
  if (r < 0.62) return Array.from({ length: int(4) }, () => value(d + 1));
  const o = {}; const n = int(5);
  for (let i = 0; i < n; i++) o[str() || 'k' + i] = value(d + 1);
  return o;
}

function deepEqual(a, b, path = '$') {
  if (a === b) return null;
  if (a === null || b === null || typeof a !== typeof b || typeof a !== 'object') return `${path}: ${JSON.stringify(a)} vs ${JSON.stringify(b)}`;
  const ka = Object.keys(a), kb = Object.keys(b);
  if (ka.length !== kb.length) return `${path}: ${ka.length} keys vs ${kb.length}`;
  for (let i = 0; i < ka.length; i++) {
    if (ka[i] !== kb[i]) {
      const cps = k => [...k].map(c => 'U+' + c.codePointAt(0).toString(16).toUpperCase().padStart(4, '0')).join(' ');
      return `${path}: key ${i} is [${cps(ka[i])}] vs [${cps(kb[i])}]`;
    }
    const inner = deepEqual(a[ka[i]], b[kb[i]], `${path}.${ka[i]}`);
    if (inner) return inner;
  }
  return null;
}

const RUNS = 4000;
reseed();
for (let i = 0; i < RUNS; i++) JSON.stringify(value());   // warm-up workload
reseed();
let found = -1, detail = '';
for (let i = 0; i < RUNS; i++) {
  const v = value();
  const diff = deepEqual(v, JSON.parse(JSON.stringify(v)));
  if (diff) { found = i; detail = diff; break; }
}

console.log('node       :', process.version);
console.log('platform   :', process.platform, process.arch);
if (found < 0) {
  console.log('result     : OK — all', RUNS, 'documents round-tripped');
  process.exit(0);
}
console.log('result     : DEFECT at case', found);
console.log('difference :', detail);
console.log();
console.log('A single-character object key changes identity through');
console.log('JSON.parse(JSON.stringify(x)) — only after several thousand');
console.log('distinct documents have been processed. The same document');
console.log('round-trips correctly in a fresh process.');
process.exit(1);
