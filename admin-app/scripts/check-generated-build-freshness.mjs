#!/usr/bin/env bun
import { createHash } from 'node:crypto';
import { readFile, readdir } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const adminRoot = path.resolve(here, '..');
const outputRoot = path.resolve(adminRoot, '..', 'assets', 'dist');

const collectGeneratedInventory = async (directory, root = directory) => {
  const inventory = [];
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      inventory.push(...(await collectGeneratedInventory(absolute, root)));
    } else if (entry.isFile()) {
      const contents = await readFile(absolute);
      inventory.push({
        path: path.relative(root, absolute).split(path.sep).join('/'),
        sha256: createHash('sha256').update(contents).digest('hex'),
        size: contents.length
      });
    } else {
      throw new Error(`Generated asset tree contains a non-regular entry: ${absolute}`);
    }
  }
  return inventory.sort((left, right) => left.path.localeCompare(right.path, 'en'));
};

export const generatedBuildFreshnessFailures = (before, after) => {
  const beforeByPath = new Map(before.map((entry) => [entry.path, entry]));
  const afterByPath = new Map(after.map((entry) => [entry.path, entry]));
  const paths = [...new Set([...beforeByPath.keys(), ...afterByPath.keys()])].sort();
  return paths.filter((candidate) => {
    const previous = beforeByPath.get(candidate);
    const current = afterByPath.get(candidate);
    return (
      !previous ||
      !current ||
      previous.size !== current.size ||
      previous.sha256 !== current.sha256
    );
  });
};

const main = async () => {
  const before = await collectGeneratedInventory(outputRoot);
  const build = spawnSync(process.execPath, ['run', 'build:wp'], {
    cwd: adminRoot,
    encoding: 'utf8',
    stdio: 'inherit'
  });
  if (build.error) {
    throw build.error;
  }
  if (build.status !== 0) {
    throw new Error(`Locked admin build failed with exit code ${build.status}.`);
  }
  const after = await collectGeneratedInventory(outputRoot);
  const failures = generatedBuildFreshnessFailures(before, after);
  if (failures.length > 0) {
    console.error('Generated admin assets changed during the locked reproducibility build:');
    failures.slice(0, 40).forEach((file) => console.error(`- ${file}`));
    process.exit(1);
  }
  console.log(`Generated admin asset freshness contract passed: ${after.length} files.`);
};

if (path.resolve(process.argv[1] ?? '') === fileURLToPath(import.meta.url)) {
  await main();
}
