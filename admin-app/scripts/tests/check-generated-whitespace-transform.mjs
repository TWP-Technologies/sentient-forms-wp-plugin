#!/usr/bin/env node
import { parse } from 'vite';
import { canonicalizeGeneratedWhitespace } from '../generated-whitespace-plugin.mjs';

const parseProgram = async (fileName, code) => {
  const result = await parse(fileName, code);
  if (result.errors.length > 0) {
    throw new Error(`Unable to parse whitespace-transform fixture: ${fileName}`);
  }
  return result.program;
};

const templateDelimiter = String.fromCharCode(96);
const source = [
  'const formatting = 1;  ',
  `const semantic = ${templateDelimiter}alpha  `,
  `beta${templateDelimiter};`
].join('\n');
const canonical = canonicalizeGeneratedWhitespace(
  source,
  await parseProgram('generated-whitespace-fixture.js', source),
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
    await parseProgram('generated-tagged-whitespace-fixture.js', tagged),
    'generated-tagged-whitespace-fixture.js'
  );
} catch (error) {
  taggedRejected = String(error).includes('Tagged template');
}
if (!taggedRejected) {
  throw new Error('Tagged template raw whitespace did not fail closed.');
}

console.log('Generated whitespace transform contract passed.');
