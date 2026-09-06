# Known issues

## Node 24+ (V8) — `JSON.parse(JSON.stringify(x))` can change an object key

**Status:** engine defect, not a connector defect. Reproducer in the repo.
**Confirmed on:** Node **v24.20.0** (GitHub Actions, ubuntu, x64) and Node
**v26.0.0** (macOS, arm64). **Not** on Node 20 or 22 on the same runners. That
rules out this machine, this OS and this architecture — it is the V8 line that
Node 24 and later ship.
**Affects:** the ZT-05 serializer fidelity fuzz (`npm run test:serializer`), which
detects the defect with a builtins-only canary and skips the property on affected
engines. CI sets `REQUIRE_FIDELITY=1` on Node 20 and 22, where the property must
run and pass, so it is always verified somewhere in the matrix.

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
node       : v26.0.0            node       : v24.20.0
platform   : darwin arm64       platform   : linux x64   (GitHub Actions)
result     : DEFECT at case 1298                 DEFECT at case 1298
```

Same case index on both — the defect is deterministic given the workload.

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

The canary is fatal only where `REQUIRE_FIDELITY=1` (CI: Node 20 and 22).
Elsewhere it reports loudly and the property skips. A suite that silently
stopped checking a security-adjacent property would be worse than a red one —
so the property is guaranteed to run on the sound engines, and the defective
ones cannot hide it.

### What to do

Nothing on the connector side. Upstream: file against nodejs/node with
`tests/known-issues/node26-json-roundtrip.mjs` — it is dependency-free and
reproduces on 24.20.0 and 26.0.0 (tracked as #19).

**Worth noting beyond the tests:** the local aivis dev server runs on this same
Node v26.0.0. A JSON round-trip defect is a poor thing to have under an
application whose whole job is generating and serving JSON-LD.
