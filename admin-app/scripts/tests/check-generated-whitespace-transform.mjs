#!/usr/bin/env node
import { parseAst } from 'rollup/parseAst';
import { canonicalizeGeneratedWhitespace } from '../generated-whitespace-plugin.mjs';

const parseProgram = (fileName, code) => {
  try {
    return parseAst(code);
  } catch (error) {
    throw new Error(`Unable to parse whitespace-transform fixture: ${fileName}`, { cause: error });
  }
};

const templateDelimiter = String.fromCharCode(96);
const source = [
  'const formatting = 1;  ',
  `const semantic = ${templateDelimiter}alpha  `,
  `beta${templateDelimiter};`
].join('\n');
const canonical = canonicalizeGeneratedWhitespace(
  source,
  parseProgram('generated-whitespace-fixture.js', source),
  'generated-whitespace-fixture.js'
);
if (canonical.includes('const formatting = 1;  \n')) {
  throw new Error('Generated formatting whitespace was not removed.');
}
const evaluate = (code) => Function(`${code}\nreturn semantic;`)();
if (evaluate(canonical) !== evaluate(source)) {
  throw new Error('Generated whitespace canonicalization changed a template literal value.');
}
if (!canonical.includes('alpha\\x20\\x20\n')) {
  throw new Error('Semantic template whitespace was not encoded explicitly.');
}

const tagged = `const value = String.raw${templateDelimiter}alpha  \nbeta${templateDelimiter};`;
let taggedRejected = false;
try {
  canonicalizeGeneratedWhitespace(
    tagged,
    parseProgram('generated-tagged-whitespace-fixture.js', tagged),
    'generated-tagged-whitespace-fixture.js'
  );
} catch (error) {
  taggedRejected = String(error).includes('Tagged template');
}
if (!taggedRejected) {
  throw new Error('Tagged template raw whitespace did not fail closed.');
}

const oddBackslash = `const semantic = ${templateDelimiter}alpha\\  \nbeta${templateDelimiter};`;
let oddBackslashRejected = false;
try {
  canonicalizeGeneratedWhitespace(
    oddBackslash,
    parseProgram('generated-odd-backslash-whitespace-fixture.js', oddBackslash),
    'generated-odd-backslash-whitespace-fixture.js'
  );
} catch (error) {
  oddBackslashRejected = String(error).toLowerCase().includes('template escape');
}
if (!oddBackslashRejected) {
  throw new Error('Ambiguous template escape before trailing whitespace did not fail closed.');
}

console.log('Generated whitespace transform contract passed.');
