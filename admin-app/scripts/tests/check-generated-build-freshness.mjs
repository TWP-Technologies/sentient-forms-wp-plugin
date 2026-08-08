#!/usr/bin/env node
import { generatedBuildFreshnessFailures } from '../check-generated-build-freshness.mjs';

const entry = (filePath, sha256, size) => ({ path: filePath, sha256, size });
const baseline = [
  entry('_app/a.js', 'a'.repeat(64), 1),
  entry('manifest.json', 'b'.repeat(64), 2)
];
const clean = generatedBuildFreshnessFailures(baseline, [...baseline].reverse());
if (clean.length !== 0) {
  throw new Error('A clean generated tree was rejected.');
}

const stale = generatedBuildFreshnessFailures(baseline, [
  entry('_app/a.js', 'c'.repeat(64), 1),
  entry('_app/new.js', 'd'.repeat(64), 3)
]);
if (
  JSON.stringify(stale) !==
  JSON.stringify([
    '_app/a.js',
    '_app/new.js',
    'manifest.json'
  ])
) {
  throw new Error('Tracked and untracked generated drift was not reported canonically.');
}

console.log('Generated admin asset freshness unit contract passed.');
