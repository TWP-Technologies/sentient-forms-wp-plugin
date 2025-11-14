import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const repoRoot = path.resolve(__dirname, '../../../../..');
const pluginPath = 'wp-content/plugins/sentient-forms/scripts/wp-playwright-fixtures.php';
const composeArgs = ['compose', '-f', 'docker-compose.yml', 'exec', '-T', 'wordpress'];

export function ensurePlaywrightFixtures(): number {
	const result = spawnSync(
		'docker',
		[...composeArgs, 'wp', 'eval-file', pluginPath],
		{
			cwd: repoRoot,
			encoding: 'utf-8'
		}
	);

	if (result.error) {
		throw result.error;
	}

	if (result.status !== 0) {
		throw new Error(`Failed to seed WordPress fixtures: ${result.stderr.trim()}`);
	}

	const output = result.stdout.toString().trim();
	const formId = Number.parseInt(output, 10);

	if (!Number.isInteger(formId) || formId <= 0) {
		throw new Error(`Unexpected fixture output: ${output}`);
	}

	return formId;
}
