#!/usr/bin/env node
import { execSync } from 'node:child_process';

const port = process.env.PREVIEW_PORT || '4175';

try {
  execSync(`lsof -ti :${port} | xargs -r kill -9`, { stdio: 'ignore' });
} catch (err) {
  // ignore if nothing to kill
}

