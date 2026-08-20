import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import { wpBaseUrl } from './utils/wp-admin';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';
const adapterPath = fileURLToPath(
	new URL('../../../includes/adapters/forms/class-sentient-forms-elementor-forms-adapter.php', import.meta.url)
);
const rendererContractPath = fileURLToPath(
	new URL('../fixtures/elementor-pro-renderer-contract.v4.1.2.json', import.meta.url)
);

interface ElementorRendererContract {
	elementor_pro_version: string;
	asset_path: string;
	asset_sha256: string;
	error_selector_fragment: string;
}

interface JQuerySelection {
	addClass(className: string): JQuerySelection;
	append(content: string): JQuerySelection;
	attr(name: string, value: string): JQuerySelection;
	find(selector: string): JQuerySelection;
	parent(): JQuerySelection;
	trigger(eventName: string): JQuerySelection;
}

function readFormValidationErrorKey(): string {
	const adapterSource = readFileSync(adapterPath, 'utf8');
	const match = adapterSource.match(/FORM_VALIDATION_ERROR_KEY\s*=\s*'([^']+)'/);
	expect(match, 'The Elementor adapter must declare its form-level validation error key.').not.toBeNull();
	return match?.[1] ?? '';
}

function readRendererContract(): ElementorRendererContract {
	return JSON.parse(readFileSync(rendererContractPath, 'utf8')) as ElementorRendererContract;
}

test.describe('Elementor Pro public validation renderer contract', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise the WordPress jQuery renderer contract.');

	test('renders field and form errors without a selector exception', async ({ page }) => {
		const formValidationErrorKey = readFormValidationErrorKey();
		const rendererContract = readRendererContract();

		await page.goto(`${wpBaseUrl}/`, { waitUntil: 'domcontentloaded' });
		const rendererResponse = await page.request.get(`${wpBaseUrl}${rendererContract.asset_path}`);
		expect(rendererResponse.ok(), `Elementor Pro ${rendererContract.elementor_pro_version} renderer is required.`).toBe(
			true
		);
		const rendererBytes = await rendererResponse.body();
		expect(createHash('sha256').update(rendererBytes).digest('hex')).toBe(rendererContract.asset_sha256);
		expect(rendererBytes.toString('utf8')).toContain(rendererContract.error_selector_fragment);

		await page.addScriptTag({ url: `${wpBaseUrl}/wp-includes/js/jquery/jquery.min.js` });
		const jqueryVersion = await page.evaluate(() => Reflect.get(Reflect.get(globalThis, 'jQuery'), 'fn').jquery);
		expect(jqueryVersion).toBe('3.7.1');

		await page.evaluate((errorKey) => {
			document.body.innerHTML = `
				<form id="elementor-contract-form">
					<div class="elementor-field-group">
						<input id="form-field-native-full-name" />
					</div>
				</form>
			`;

			const jQuery = Reflect.get(globalThis, 'jQuery') as (
				target: string | HTMLFormElement
			) => JQuerySelection;
			const form = document.querySelector<HTMLFormElement>('#elementor-contract-form');
			if (!form) throw new Error('Elementor contract fixture form is missing.');
			const $form = jQuery(form);
			const response = {
				success: false,
				data: {
					errors: {
						'native-full-name': 'Provide your full name.',
						[errorKey]: 'This submission could not be processed.'
					},
					message: 'This submission could not be processed. Please review it and try again.'
				}
			};

			for (const [key, title] of Object.entries(response.data.errors)) {
				$form
					.find('#form-field-' + key)
					.parent()
					.addClass('elementor-error')
					.append(
						'<span class="elementor-message elementor-message-danger elementor-help-inline elementor-form-help-inline" role="alert">' +
							title +
							'</span>'
					)
					.find(':input')
					.attr('aria-invalid', 'true');
			}
			$form.trigger('error');
			$form.append(
				'<div class="elementor-message elementor-message-danger" role="alert">' +
					response.data.message +
					'</div>'
			);
		}, formValidationErrorKey);

		await expect(page.locator('#form-field-native-full-name')).toHaveAttribute('aria-invalid', 'true');
		await expect(page.locator('.elementor-field-group')).toHaveClass(/elementor-error/);
		await expect(page.getByRole('alert').last()).toContainText(
			'This submission could not be processed. Please review it and try again.'
		);
	});
});
