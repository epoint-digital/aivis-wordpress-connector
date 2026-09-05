// Replay one fuzz case exactly: node tests/unit/replay.mjs <index>
import { reseed, randomValue } from './fuzz-gen.mjs';
import { serializeJsonLd } from '../../src/reference/serialize.mjs';

const idx = Number(process.argv[2] ?? 0);
reseed();
let v;
for (let i = 0; i <= idx; i++) v = randomValue();

const out = serializeJsonLd(v);
const back = JSON.parse(out);
const A = JSON.stringify(v), B = JSON.stringify(back);
console.log('match:', A === B);
if (A !== B) {
  let d = 0; while (d < A.length && d < B.length && A[d] === B[d]) d++;
  console.log('first difference at', d);
  const cps = s => [...s.slice(Math.max(0, d - 8), d + 8)].map(c => c.codePointAt(0).toString(16).padStart(4, '0')).join(' ');
  console.log('in  codepoints:', cps(A));
  console.log('out codepoints:', cps(B));
  // walk the object to find the offending key
  const walk = (a, b, path = '$') => {
    if (typeof a === 'string' || typeof b === 'string') {
      if (a !== b) console.log(`  ${path}: ${JSON.stringify(a)} -> ${JSON.stringify(b)}`);
      return;
    }
    if (a && b && typeof a === 'object') {
      const ka = Object.keys(a), kb = Object.keys(b);
      if (JSON.stringify(ka) !== JSON.stringify(kb)) {
        console.log(`  ${path} KEYS differ:`);
        console.log('    in :', ka.map(k => [...k].map(c => c.codePointAt(0).toString(16)).join(',')).join(' | '));
        console.log('    out:', kb.map(k => [...k].map(c => c.codePointAt(0).toString(16)).join(',')).join(' | '));
      }
      for (const k of ka) walk(a[k], b?.[k], `${path}.${JSON.stringify(k)}`);
    }
  };
  walk(v, back);
}
