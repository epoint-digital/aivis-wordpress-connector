// Minimal shared test harness. No framework: the suites must run anywhere Node
// runs, with no install step, so a reviewer can clone and verify in one command.

let pass = 0, failed = 0, skipped = 0;
const failures = [];

export function record(status, name, detail) {
  if (status === 'PASS') pass++; else if (status === 'FAIL') { failed++; failures.push({ name, detail }); } else skipped++;
  const icon = status === 'PASS' ? '  ok  ' : status === 'FAIL' ? ' FAIL ' : ' skip ';
  console.log(`${icon} ${name}${detail ? '  — ' + detail : ''}`);
}

export async function test(name, fn, { skip = false, skipReason = '' } = {}) {
  if (skip) return record('SKIP', name, skipReason);
  try { const d = await fn(); record('PASS', name, typeof d === 'string' ? d : ''); }
  catch (e) { record('FAIL', name, e.message); }
}

export function assert(cond, msg) { if (!cond) throw new Error(msg); }

export function eq(actual, expected, msg) {
  const a = JSON.stringify(actual), b = JSON.stringify(expected);
  if (a !== b) throw new Error(`${msg} — got ${a}, want ${b}`);
}

export function throws(fn, match, msg) {
  try { fn(); } catch (e) {
    if (match && !String(e.message).includes(match)) throw new Error(`${msg} — threw "${e.message}", wanted "${match}"`);
    return;
  }
  throw new Error(`${msg} — did not throw`);
}

export function heading(text) { console.log(`\n  ${text}\n  ${'─'.repeat(text.length)}`); }

export function finish(label) {
  console.log(`\n${label}: ${pass} passed, ${failed} failed, ${skipped} skipped\n`);
  if (failures.length) {
    console.log('Failures:');
    failures.forEach(f => console.log(`  - ${f.name}: ${f.detail}`));
  }
  return failed;
}
