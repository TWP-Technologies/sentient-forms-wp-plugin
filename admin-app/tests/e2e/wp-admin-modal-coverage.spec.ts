import { expect, test, type Locator, type Page } from '@playwright/test';
import { requireWpRestHealthy, runWpEval } from './utils/wp-e2e-helpers';
import { ensureSentientFormsSpa, loginToWpAdmin } from './utils/wp-admin';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';
const modalCoverageCustomActionCode = 'browser_mcp_modal_coverage';
const modalCoverageCustomActionLabel = 'Browser MCP Modal Coverage';

test.describe('WP admin modal and guard coverage @wp-visual', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise WordPress admin modal flows.');

	test.beforeEach(async ({ page }) => {
		ensureLocalCustomAction(modalCoverageCustomActionCode, modalCoverageCustomActionLabel);
		await requireWpRestHealthy(page);
		await loginToWpAdmin(page);
		await page.setViewportSize({ width: 1440, height: 1000 });
	});

	test('exact-package local-first modals, panels, and guards are reachable', async ({ page }) => {
		await coverProvidersDisclosures(page);
		await coverActionsOverviewDefaults(page);
		await coverFormActionPanelsAndModals(page);
		await coverSettingsRetentionControls(page);
		await coverSiteContextWarningFlow(page);
		await coverMigrationGuards(page);
	});
});

function ensureLocalCustomAction(code: string, displayName: string): void {
	const output = runWpEval(
		`
if ( ! class_exists( 'Sentient_Forms_Installer' ) ) {
	echo wp_json_encode([ 'error' => 'sentient_forms_not_loaded' ]);
	return;
}

Sentient_Forms_Installer::maybe_upgrade();

global $wpdb;
$code          = sanitize_key( getenv( 'CUSTOM_ACTION_CODE' ) ?: '' );
$display_name  = sanitize_text_field( getenv( 'CUSTOM_ACTION_LABEL' ) ?: '' );
$actions_table = $wpdb->prefix . 'sentient_custom_actions';

if ( '' === $code || '' === $display_name ) {
	echo wp_json_encode([ 'error' => 'missing_custom_action_identity' ]);
	return;
}

$existing_id = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT id FROM ' . esc_sql( $actions_table ) . ' WHERE code = %s ORDER BY id ASC LIMIT 1',
		$code
	)
);

if ( $existing_id > 0 ) {
	echo wp_json_encode([ 'id' => $existing_id, 'status' => 'existing' ]);
	return;
}

$custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
$action_id      = $custom_actions->create(
	[
		'code'                 => $code,
		'display_name'         => $display_name,
		'description'          => 'Reusable WP admin modal coverage action.',
		'definition_json'      => [
			'system_prompt'   => 'Classify the submission and produce a concise operations note.',
			'prompt_template' => 'Submission summary for {{form.title}}.',
		],
		'model_selection_json' => [
			'provider' => 'openrouter',
			'model'    => 'openrouter/auto',
		],
		'status'               => 'active',
	]
);

if ( is_wp_error( $action_id ) ) {
	echo wp_json_encode([ 'error' => $action_id->get_error_message() ]);
	return;
}

echo wp_json_encode([ 'id' => (int) $action_id, 'status' => 'created' ]);
`,
		{
			CUSTOM_ACTION_CODE: code,
			CUSTOM_ACTION_LABEL: displayName
		}
	);

	const parsed = JSON.parse(output) as { id?: number; error?: string };
	if (parsed.error || !parsed.id) {
		throw new Error(`Failed to ensure local custom action: ${output}`);
	}
}

async function coverProvidersDisclosures(page: Page): Promise<void> {
	await ensureSentientFormsSpa(page, '/providers');

	const openRouterCard = page.getByTestId('providers-openrouter-form-card');
	await expect(openRouterCard).toBeVisible();
	const openRouterDisclosure = openRouterCard.getByLabel(/I understand OpenRouter receives request data/i);
	await expect(openRouterDisclosure).toBeVisible();
	await openRouterDisclosure.check();
	await expect(openRouterDisclosure).toBeChecked();
	await attachLocatorScreenshot(page, openRouterCard, 'providers-openrouter-disclosure');

	const managedCard = page.getByTestId('providers-managed-setup-card');
	await expect(managedCard).toBeVisible();
	const managedAccountRequired = managedCard.getByTestId('providers-managed-account-required');
	if (await managedAccountRequired.count()) {
		await expect(managedAccountRequired).toBeVisible();
		await attachLocatorScreenshot(page, managedCard, 'providers-managed-account-required');
		return;
	}

	const managedDisclosure = managedCard.getByLabel(/I understand Sentient receives the rendered prompt/i);
	await expect(managedDisclosure).toBeVisible();
	await managedDisclosure.check();
	await expect(managedDisclosure).toBeChecked();
	await attachLocatorScreenshot(page, managedCard, 'providers-managed-disclosure');
}

async function coverActionsOverviewDefaults(page: Page): Promise<void> {
	await ensureSentientFormsSpa(page, '/actions');

	const spamDefaultsButton = page.getByRole('button', { name: 'Defaults' }).first();
	await expect(spamDefaultsButton).toBeVisible();
	await spamDefaultsButton.click();

	const defaultsModal = page.getByTestId('action-defaults-modal');
	await expect(defaultsModal).toBeVisible();
	await attachLocatorScreenshot(page, defaultsModal, 'actions-defaults-spam');
	await defaultsModal.getByTestId('action-defaults-close').click();
	await expect(defaultsModal).toBeHidden();

	const customDefaultsButton = page.getByTestId(`action-defaults-button-${modalCoverageCustomActionCode}`);
	await expect(customDefaultsButton).toBeVisible();
	await customDefaultsButton.click();
	await expect(defaultsModal).toBeVisible();
	await attachLocatorScreenshot(page, defaultsModal, 'actions-defaults-non-spam');
	await defaultsModal.getByTestId('action-defaults-close').click();
	await expect(defaultsModal).toBeHidden();
}

async function coverFormActionPanelsAndModals(page: Page): Promise<void> {
	await openFirstFormActions(page);
	await coverFormDefaults(page);
	await coverAddActionDrawer(page);
	await coverTemplateLibrary(page);
	await coverMappingConfigAndGraph(page);
}

async function coverFormDefaults(page: Page): Promise<void> {
	const definitionsCard = page.getByTestId('action-definitions-card');
	await expect(definitionsCard).toBeVisible();
	await definitionsCard.getByRole('button', { name: 'Defaults' }).first().click();

	const formDefaultsModal = page.getByTestId('form-defaults-modal');
	await expect(formDefaultsModal).toBeVisible();
	await attachLocatorScreenshot(page, formDefaultsModal, 'form-defaults-modal');
	await formDefaultsModal.getByTestId('form-defaults-close').click();
	await expect(formDefaultsModal).toBeHidden();
}

async function coverAddActionDrawer(page: Page): Promise<void> {
	const header = page.locator('header').first();
	await header.getByRole('button', { name: 'Add action' }).click();

	const drawer = page.getByTestId('link-action-form');
	await expect(drawer).toBeVisible();

	await attachLocatorScreenshot(page, drawer, 'add-action-template');

	const customTab = page.getByRole('button', { name: 'Custom actions' });
	await expect(customTab).toBeEnabled();
	await customTab.click();
	await expect(drawer.getByRole('radio').first()).toBeVisible();
	await attachLocatorScreenshot(page, drawer, 'add-action-custom');

	const directTab = page.getByRole('button', { name: /^Direct OpenRouter$/ });
	await directTab.click();
	await expect(page.getByTestId('local-openrouter-builder')).toBeVisible();
	await attachLocatorScreenshot(page, drawer, 'add-action-direct-openrouter');

	await page.getByRole('button', { name: 'Close' }).click();
	await expect(drawer).toBeHidden();
}

async function coverTemplateLibrary(page: Page): Promise<void> {
	await page.getByRole('button', { name: 'Import from Library' }).click();
	const libraryDialog = page.getByRole('dialog').filter({ hasText: 'Import from Template Library' });
	await expect(libraryDialog).toBeVisible();
	await attachLocatorScreenshot(page, libraryDialog, 'template-library');
	await libraryDialog.getByRole('button', { name: 'Close' }).click();
	await expect(libraryDialog).toBeHidden();
}

async function coverMappingConfigAndGraph(page: Page): Promise<void> {
	await ensureAtLeastOneLinkedAction(page);

	const tableToggle = page.getByTestId('linked-actions-view-table');
	if ((await tableToggle.count()) > 0) {
		await tableToggle.first().click();
	}

	const table = page.getByTestId('form-actions-table');
	await expect(table).toBeVisible();
	await table.locator('tbody tr').first().getByRole('button', { name: 'Configure' }).click();

	const mappingModal = page.getByTestId('mapping-config-modal');
	await expect(mappingModal).toBeVisible();
	await expandVisibleMappingSections(mappingModal);
	await attachLocatorScreenshot(page, mappingModal, 'mapping-config-all-sections');

	await mappingModal.getByTestId('mapping-config-open-graph').click();
	const graphCanvas = page.getByTestId('dependency-graph-canvas');
	await expect(graphCanvas).toBeVisible();
	await attachLocatorScreenshot(page, graphCanvas, 'dependency-graph');
}

async function coverSettingsRetentionControls(page: Page): Promise<void> {
	await ensureSentientFormsSpa(page, '/settings');

	const retentionHeading = page.getByText('Local data retention');
	await expect(retentionHeading).toBeVisible();
	await expect(page.getByTestId('settings-store-full-ai-outputs')).toBeVisible();
	await expect(page.getByRole('button', { name: 'Save retention' })).toBeVisible();
	await attachViewportScreenshot(page, 'settings-privacy-retention-controls');
}

async function coverSiteContextWarningFlow(page: Page): Promise<void> {
	await ensureSentientFormsSpa(page, '/settings/context');

	const generateButton = page.getByRole('button', { name: 'Generate Site Context' });
	if (await generateButton.isVisible().catch(() => false)) {
		const piiAck = page.getByRole('checkbox').first();
		await piiAck.check();
		await generateButton.click();
		await expect(page.getByRole('button', { name: /Regenerate/ })).toBeVisible();
	}

	const regenerateButton = page.getByRole('button', { name: /Regenerate/ }).first();
	await expect(regenerateButton).toBeVisible();
	await regenerateButton.click();
	const confirmAlert = page.getByText('Confirm Regeneration').locator('..');
	await expect(confirmAlert).toBeVisible();
	await attachViewportScreenshot(page, 'site-context-warnings');
	await page.getByRole('button', { name: 'Cancel' }).click();
}

async function coverMigrationGuards(page: Page): Promise<void> {
	await ensureSentientFormsSpa(page, '/settings/migration');

	await expect(page.getByText('Apply guard')).toBeVisible();
	await expect(page.getByRole('button', { name: 'Apply current preview' })).toBeDisabled();
	await page.locator('textarea').fill('{');
	await page.getByRole('button', { name: 'Preview import' }).click();
	await expect(page.getByText('The CPS export bundle is not valid JSON.')).toBeVisible();
	await attachViewportScreenshot(page, 'migration-guards');
}

async function openFirstFormActions(page: Page): Promise<void> {
	await ensureSentientFormsSpa(page, '/actions');
	const configureButton = page.getByRole('button', { name: 'Configure Actions' }).first();
	await expect(configureButton).toBeVisible();
	await Promise.all([
		page.waitForURL(/#\/actions\/[^/]+\/\d+$/, { timeout: 10000 }),
		configureButton.click()
	]);
	await expect(page.getByTestId('action-definitions-card')).toBeVisible();
}

async function ensureAtLeastOneLinkedAction(page: Page): Promise<void> {
	const tableToggle = page.getByTestId('linked-actions-view-table');
	const table = page.getByTestId('form-actions-table');
	const hasTableToggle = (await tableToggle.count()) > 0;
	const hasTable = (await table.count()) > 0;

	if (hasTableToggle) {
		await tableToggle.first().click();
	}

	if (
		hasTable &&
		(await table.isVisible().catch(() => false)) &&
		(await table.locator('tbody tr').count()) > 0
	) {
		return;
	}

	await page.evaluate(() => {
		try {
			window.localStorage.setItem('sentient_forms_last_hooks', '["gform_after_submission"]');
		} catch {}
	});

	await page.locator('header').first().getByRole('button', { name: 'Add action' }).click();
	const drawer = page.getByTestId('link-action-form');
	await expect(drawer).toBeVisible();
	await drawer.getByRole('button', { name: /^Direct OpenRouter$/ }).click();
	await expect(drawer.getByTestId('local-openrouter-builder')).toBeVisible();

	const hookOption = drawer.locator('input[type="checkbox"]').first();
	await expect(hookOption).toBeVisible();
	if (!(await hookOption.isChecked())) {
		await hookOption.check();
	}

	const credentialSelect = drawer.getByTestId('local-builder-credential');
	await expect(credentialSelect).toBeVisible();
	const credentialOptions = await credentialSelect.locator('option').evaluateAll((options) =>
		options
			.map((option) => option.getAttribute('value') ?? '')
			.filter((value) => value.length > 0)
	);
	expect(credentialOptions.length).toBeGreaterThan(0);
	const firstCredentialValue = credentialOptions[0];
	if (firstCredentialValue) {
		await credentialSelect.selectOption(firstCredentialValue);
	}

	const submitButton = drawer.getByTestId('link-action-submit');
	await expect(submitButton).toBeVisible();
	await expect(submitButton).toContainText('Create Direct OpenRouter action');
	await submitButton.click();

	const builderResult = drawer.getByTestId('local-builder-result');
	await expect(builderResult).toContainText('Action #', { timeout: 15000 });
	await drawer.getByRole('button', { name: 'Cancel' }).click();
	await expect(drawer).toBeHidden({ timeout: 15000 });

	if (hasTableToggle) {
		await tableToggle.first().click();
	}
	if ((await table.count()) > 0) {
		await expect(table.locator('tbody tr').first()).toBeVisible();
		return;
	}

	await expect(page.getByRole('button', { name: 'Configure' }).first()).toBeVisible();
}

async function expandVisibleMappingSections(mappingModal: Locator): Promise<void> {
	const toggles = mappingModal.locator('[data-testid^="mapping-section-toggle-"]');
	const count = await toggles.count();
	for (let index = 0; index < count; index += 1) {
		const toggle = toggles.nth(index);
		await toggle.scrollIntoViewIfNeeded();
		if ((await toggle.getAttribute('aria-expanded')) !== 'true') {
			await toggle.click();
		}
	}
}

async function attachViewportScreenshot(page: Page, name: string): Promise<void> {
	const screenshot = await page.screenshot({ fullPage: true, type: 'png' });
	await test.info().attach(`${name}.png`, { body: screenshot, contentType: 'image/png' });
}

async function attachLocatorScreenshot(page: Page, locator: Locator, name: string): Promise<void> {
	await locator.scrollIntoViewIfNeeded();
	const screenshot = await locator.screenshot({ type: 'png' });
	await test.info().attach(`${name}.png`, { body: screenshot, contentType: 'image/png' });
}
