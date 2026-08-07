#!/usr/bin/env node
import { readFile, readdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parse } from 'vite';

const here = path.dirname(fileURLToPath(import.meta.url));
const outputRoot = path.resolve(here, '..', '..', '..', 'assets', 'dist');
const textExtensions = new Set(['.css', '.html', '.js', '.json', '.md', '.svg', '.txt']);

const collectStringLiteralRanges = (ast) => {
  const ranges = [];
  const visit = (value) => {
    if (Array.isArray(value)) {
      value.forEach(visit);
      return;
    }
    if (!value || typeof value !== 'object') {
      return;
    }
    if (
      (value.type === 'Literal' && typeof value.value === 'string') ||
      value.type === 'TemplateElement'
    ) {
      ranges.push({ start: value.start, end: value.end });
    }
    for (const [key, child] of Object.entries(value)) {
      if (!['start', 'end', 'loc', 'range'].includes(key)) {
        visit(child);
      }
    }
  };
  visit(ast);
  return ranges;
};

const whitespaceFailures = async (contents, relative) => {
  const failures = [];
  if (contents.includes('\r')) {
    failures.push(`${relative}: contains carriage-return bytes`);
  }

  let literalRanges = [];
  if (path.extname(relative) === '.js') {
    const result = await parse(relative, contents);
    if (result.errors.length > 0) {
      throw new Error(`Generated JavaScript is not parseable: ${relative}`);
    }
    literalRanges = collectStringLiteralRanges(result.program);
  }

  let line = 1;
  let cursor = 0;
  for (const match of contents.matchAll(/[\t ]+(?=\n|$)/g)) {
    const start = match.index;
    line += contents.slice(cursor, start).split('\n').length - 1;
    cursor = start;
    const isSemanticLiteral = literalRanges.some(
      ({ start: rangeStart, end }) => start >= rangeStart && start < end
    );
    if (!isSemanticLiteral) {
      failures.push(`${relative}:${line}: trailing horizontal whitespace`);
    }
  }
  return failures;
};

const collectTextFiles = async (directory) => {
  const files = [];
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      files.push(...(await collectTextFiles(absolute)));
    } else if (entry.isFile() && textExtensions.has(path.extname(entry.name))) {
      files.push(absolute);
    }
  }
  return files;
};

const templateDelimiter = String.fromCharCode(96);
const semanticFixture = `const chars = ${templateDelimiter}  \n${templateDelimiter};\n`;
const formattingFixture = 'const value = 1;  \n';
if ((await whitespaceFailures(semanticFixture, 'semantic.js')).length !== 0) {
  throw new Error('Whitespace inside a JavaScript string literal must remain semantic data.');
}
if ((await whitespaceFailures(formattingFixture, 'formatting.js')).length !== 1) {
  throw new Error('JavaScript formatting whitespace must fail the generated-build contract.');
}

const failures = [];
for (const file of await collectTextFiles(outputRoot)) {
  const contents = await readFile(file, 'utf8');
  const relative = path.relative(outputRoot, file).split(path.sep).join('/');
  failures.push(...(await whitespaceFailures(contents, relative)));
}

if (failures.length > 0) {
  console.error('Generated build whitespace contract failed:');
  for (const failure of failures.slice(0, 20)) {
    console.error(`- ${failure}`);
  }
  process.exit(1);
}

console.log('Generated build whitespace contract passed.');
