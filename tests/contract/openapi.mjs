// Validates responses against the vendored AIVIS OpenAPI document.
//
// Why this exists: the contract suite asserts against a hand-written mock. If
// the mock is shaped wrong, every test passes and the plugin still breaks
// against the real API. Checking each response against AIVIS's own published
// description makes the spec the arbiter instead of our memory of it.
//
// Deliberately small — it supports exactly the JSON Schema keywords this
// document uses, and THROWS on any keyword it does not recognise. A validator
// that silently ignores what it does not understand is worse than none: it
// reports success it did not verify.

import { readFileSync } from 'node:fs';

const KNOWN = new Set([
  'type','properties','required','additionalProperties','items','anyOf',
  'enum','minimum','maximum','description','title','example','default','nullable',
]);

export function loadSpec(url = new URL('../fixtures/openapi-v1.json', import.meta.url)) {
  return JSON.parse(readFileSync(url, 'utf8'));
}

const typeOf = v =>
  v === null ? 'null' :
  Array.isArray(v) ? 'array' :
  typeof v === 'number' ? (Number.isInteger(v) ? 'integer' : 'number') :
  typeof v;

function typeMatches(expected, value) {
  const actual = typeOf(value);
  if (expected === 'number') return actual === 'number' || actual === 'integer';
  return actual === expected;
}

/** Returns an array of human-readable errors; empty means valid. */
export function validate(schema, value, at = '$') {
  if (schema === true || schema === undefined) return [];
  if (schema === false) return [`${at}: schema forbids any value`];

  for (const k of Object.keys(schema)) {
    if (!KNOWN.has(k)) throw new Error(`openapi.mjs cannot check "${k}" (at ${at}) — extend the validator rather than trust it`);
  }

  const errs = [];

  if (schema.anyOf) {
    const branches = schema.anyOf.map(s => validate(s, value, at));
    if (!branches.some(b => b.length === 0)) {
      errs.push(`${at}: matched none of ${schema.anyOf.length} anyOf branches (got ${typeOf(value)})`);
    }
    return errs;   // anyOf carries the whole constraint in this document
  }

  if (schema.type && !typeMatches(schema.type, value)) {
    return [`${at}: expected ${schema.type}, got ${typeOf(value)}`];
  }

  if (schema.enum && !schema.enum.includes(value)) {
    errs.push(`${at}: ${JSON.stringify(value)} is not one of ${JSON.stringify(schema.enum)}`);
  }

  if (typeof value === 'number') {
    if (schema.minimum !== undefined && value < schema.minimum) errs.push(`${at}: ${value} < minimum ${schema.minimum}`);
    if (schema.maximum !== undefined && value > schema.maximum) errs.push(`${at}: ${value} > maximum ${schema.maximum}`);
  }

  if (schema.type === 'object' || schema.properties) {
    if (value === null || typeof value !== 'object' || Array.isArray(value)) return errs;
    const props = schema.properties || {};
    for (const req of schema.required || []) {
      if (!(req in value)) errs.push(`${at}: missing required property "${req}"`);
    }
    for (const [k, v] of Object.entries(value)) {
      if (props[k]) errs.push(...validate(props[k], v, `${at}.${k}`));
      else if (schema.additionalProperties === false) errs.push(`${at}: unexpected property "${k}" (additionalProperties is false)`);
    }
  }

  if (schema.type === 'array' && Array.isArray(value) && schema.items) {
    value.forEach((v, i) => errs.push(...validate(schema.items, v, `${at}[${i}]`)));
  }

  return errs;
}

/** Turn "/businesses/{businessId}/chains" into a matcher. */
const toRegex = tpl => new RegExp('^' + tpl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\\\{[^}]+\\\}/g, '[^/]+') + '$');

export function matchTemplate(spec, concretePath) {
  const clean = concretePath.split('?')[0];
  const exact = Object.keys(spec.paths).find(p => p === clean);
  if (exact) return exact;
  return Object.keys(spec.paths).find(p => p.includes('{') && toRegex(p).test(clean)) || null;
}

/**
 * Validate one observed response. Returns {checked, errors}. `checked:false`
 * means the document declares no schema for that path/status — reported rather
 * than silently passed.
 */
export function conform(spec, method, concretePath, status, body) {
  const tpl = matchTemplate(spec, concretePath);
  if (!tpl) return { checked: false, errors: [`no path in the spec matches ${concretePath}`] };
  const op = spec.paths[tpl][method.toLowerCase()];
  if (!op) return { checked: false, errors: [`spec declares no ${method} for ${tpl}`] };
  const res = op.responses?.[String(status)];
  if (!res) return { checked: false, errors: [`spec declares no ${status} response for ${method} ${tpl}`] };
  const schema = res.content?.['application/json']?.schema;
  if (!schema) {
    // Documented without a body (a 304): checked — the body must then be empty.
    return { checked: true, errors: body == null ? [] : [`${method} ${tpl} ${status}: body present but the document declares none`] };
  }
  return { checked: true, errors: validate(schema, body, `${method} ${tpl} ${status}`) };
}

/** Every method+path the document declares, as "GET /jsonld" strings. */
export function specOperations(spec) {
  return Object.entries(spec.paths)
    .flatMap(([p, ops]) => Object.keys(ops).map(m => `${m.toUpperCase()} ${p}`))
    .sort();
}
