# Known issues

## Node v26.0.0 — `JSON.parse(JSON.stringify(x))` can change an object key

**Status:** environment defect, not a connector defect. Reproducer in the repo.
**Affects:** the ZT-05 serializer fidelity fuzz (`npm run test:serializer`), which
skips itself when the defect is detected.

### What happens

After a few thousand distinct JSON documents have been processed in one
process, a **single-character object key changes identity** through a round
trip. In the captured case a key `"` (`U+0022`) comes back as `\` (`U+005C`):

```
$.k2.<key>.': key 1 is [U+0022] vs [U+005C]
```

Reproduce with builtins only — no dependencies, no connector code:

```bash
node tests/known-issues/node26-json-roundtrip.mjs
```

```
node       : v26.0.0
platform   : darwin arm64
result     : DEFECT at case 1298
```

### What we established

| Check | Result |
|---|---|
| Reproduces with `JSON.stringify` alone (no connector code) | **yes** — this is what rules our serializer out |
| Reproduces under `--jitless` | yes — not a JIT tier-up bug |
| Reproduces in a fresh process on the same document alone | **no** — needs the preceding workload |
| Same document, 20,000 repeat parses in a fresh process | no defect |
| Serialized text well-formed UTF-16, no lone surrogates | confirmed clean |
| Rope vs flattened string | identical behaviour, both wrong |
| `Object.prototype` pollution | none |
| Our serializer's output vs a re-serialization of the parse | byte-identical for 426 of 441 characters; only the final key differs |

The connector's serializer is not implicated: its output is correct, and the
defect appears with the platform's own serializer on the same workload.

### How the suite handles it

`tests/unit/serializer.mjs` runs a canary first, using only `JSON.stringify`
and `JSON.parse`. If the canary detects the defect, the fidelity fuzz **skips**
with that reason rather than reporting a failure against our code — a
round-trip property cannot be verified with a round trip that is itself broken.

The canary is a failing test, deliberately. A green suite that silently stopped
checking a security-adjacent property would be worse than a red one.

### What to do

Run the test suite on a Node LTS release (20, 22 or 24). CI already does, on all
three, so a version-specific defect shows up there as green while this machine
reports red.

**Worth noting beyond the tests:** the local aivis dev server runs on this same
Node v26.0.0. A JSON round-trip defect is a poor thing to have under an
application whose whole job is generating and serving JSON-LD.
