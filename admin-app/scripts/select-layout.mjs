#!/usr/bin/env node
import { copyFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const routerType = process.env.SENTIENT_FORMS_ROUTER === 'pathname' ? 'pathname' : 'hash';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const projectRoot = path.resolve(__dirname, '..');
const templatesDir = path.join(__dirname, 'layout-templates');
const routesDir = path.join(projectRoot, 'src', 'routes');

const sourceFile = path.join(templatesDir, `layout.${routerType}.ts`);
const targetFile = path.join(routesDir, '+layout.ts');

if (!existsSync(sourceFile)) {
	console.error(`Unable to locate layout template for router "${routerType}"`);
	process.exit(1);
}

copyFileSync(sourceFile, targetFile);
