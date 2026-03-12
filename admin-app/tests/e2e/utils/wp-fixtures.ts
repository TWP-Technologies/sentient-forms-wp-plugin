import { spawnSync } from 'node:child_process';
import { wpBaseUrl } from './wp-admin';

const pluginPath = '/var/www/html/wp-content/plugins/sentient-forms/scripts/wp-playwright-fixtures.php';
const wordpressContainer = 'sentient_forms_wordpress';

export function ensurePlaywrightFixtures(): number {
	const result = spawnSync(
		'docker',
		['exec', wordpressContainer, 'wp', `--url=${wpBaseUrl}`, 'eval-file', pluginPath],
		{
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
