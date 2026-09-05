// ZT-05 — safe re-serialization.
//
// The plugin never echoes the API's raw response substring into the page. It
// re-serializes the parsed value itself, with escaping that makes a <script>
// breakout impossible regardless of what AIVIS sent. This is the one function
// in the connector where a mistake is a stored-XSS on a customer's site, so it
// is written out explicitly rather than assembled from string replacements.
//
// The PHP port is:
//   json_encode($value,
//       JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
//     | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
//     | JSON_PRESERVE_ZERO_FRACTION)
//
// Reproduced here byte for byte, with one documented exception:
// JSON_PRESERVE_ZERO_FRACTION cannot be represented in JavaScript, where 1.0
// and 1 are the same value — PHP emits `1.0`, this emits `1`. That affects the
// textual form of whole-number floats only, never the escaping.
//
// PHP's casing quirk is preserved too: the HEX_* substitutions use uppercase
// hex digits, control characters use lowercase.

/** Characters that must never survive into the page. */
const HEX_ESCAPES = {
  '<': '\\u003C',   // JSON_HEX_TAG  — closes/opens no element
  '>': '\\u003E',   // JSON_HEX_TAG
  '&': '\\u0026',   // JSON_HEX_AMP  — starts no entity
  "'": '\\u0027',   // JSON_HEX_APOS — breaks no attribute
  '"': '\\u0022',   // JSON_HEX_QUOT — breaks no attribute
};

const SHORT_ESCAPES = {
  '\\': '\\\\',
  '\b': '\\b',
  '\f': '\\f',
  '\n': '\\n',
  '\r': '\\r',
  '\t': '\\t',
};

function encodeString(str) {
  let out = '"';
  for (const ch of str) {
    const hex = HEX_ESCAPES[ch];
    if (hex !== undefined) { out += hex; continue; }
    const short = SHORT_ESCAPES[ch];
    if (short !== undefined) { out += short; continue; }
    const code = ch.codePointAt(0);
    if (code < 0x20) { out += '\\u' + code.toString(16).padStart(4, '0'); continue; }
    // JSON_UNESCAPED_UNICODE and JSON_UNESCAPED_SLASHES: everything else as-is.
    out += ch;
  }
  return out + '"';
}

/**
 * Re-serialize a parsed JSON-LD document for embedding in a script element.
 * Throws on anything that cannot be represented — non-finite numbers,
 * functions, undefined — rather than silently emitting something else.
 */
export function serializeJsonLd(value) {
  if (value === null) return 'null';
  const t = typeof value;
  if (t === 'boolean') return value ? 'true' : 'false';
  if (t === 'number') {
    if (!Number.isFinite(value)) throw new Error('AIVIS_SCHEMA_INVALID: non-finite number');
    return String(value);
  }
  if (t === 'string') return encodeString(value);
  if (Array.isArray(value)) return '[' + value.map(serializeJsonLd).join(',') + ']';
  if (t === 'object') {
    return '{' + Object.entries(value)
      .filter(([, v]) => v !== undefined)
      .map(([k, v]) => encodeString(k) + ':' + serializeJsonLd(v))
      .join(',') + '}';
  }
  throw new Error('AIVIS_SCHEMA_INVALID: cannot serialize ' + t);
}

/**
 * The exact element the plugin emits — one script, carrying the data-aivis
 * marker the edge worker's idempotency check depends on (§14).
 */
export function renderScriptTag(value) {
  return '<script type="application/ld+json" data-aivis="1">'
    + serializeJsonLd(value)
    + '</' + 'script>';
}

/**
 * Characters that must not appear anywhere in serialized output.
 *
 * Checking for their absence is a stronger claim than checking for the absence
 * of "</script>": if none of these can occur, no markup can be formed at all.
 *
 * The double quote is deliberately NOT in this list — it is JSON's string
 * delimiter and must appear. Its safety is expressed differently: quotes inside
 * string content are escaped to \u0022, which the round-trip property proves
 * (a stray unescaped quote would produce unparseable output, not wrong markup).
 */
export const FORBIDDEN_IN_OUTPUT = Object.freeze(['<', '>', '&', "'"]);
