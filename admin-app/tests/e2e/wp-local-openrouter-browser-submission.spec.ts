import { expect, test, type Page } from '@playwright/test';
import {
	ensureGravityForm,
	findEntryIdByEmail,
	getEntryMeta,
	getLatestEntryId,
	requireWpRestHealthy,
	runActionScheduler,
	runWpEval
} from './utils/wp-e2e-helpers';
import { ensureSentientFormsSpa, loginToWpAdmin } from './utils/wp-admin';

const runLocalOpenRouterBrowserSmoke =
	process.env.SENTIENT_RUN_WP_E2E === '1' &&
	process.env.SENTIENT_RUN_LOCAL_OPENROUTER_BROWSER_SMOKE === '1';
const localOpenRouterBrowserExecutionMode =
	process.env.SENTIENT_FORMS_LOCAL_OPENROUTER_EXECUTION_MODE === 'async' ? 'async' : 'sync';

type LocalProviderSeed = {
	credential_id: number;
	consent_id: number;
};

type LocalExecutionEvent = {
	id?: number;
	status?: string;
	mapping_id?: number;
	entry_id?: string;
	provider?: string;
	model?: string;
};

function seedLocalOpenRouterProvider(label: string): LocalProviderSeed {
	const output = runWpEval(
		`
if ( ! class_exists( 'Sentient_Forms_Installer' ) ) {
    echo wp_json_encode([ 'error' => 'sentient_forms_not_loaded' ]);
    return;
}

Sentient_Forms_Installer::maybe_upgrade();

global $wpdb;
$label       = getenv( 'OPENROUTER_LABEL' ) ?: 'Browser smoke OpenRouter key';
$secret      = 'sk-or-browser-smoke-' . wp_generate_uuid4();
$vault       = new Sentient_Forms_Provider_Credential_Vault();
$encrypted   = $vault->encrypt( $secret );
$credentials = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
$consents    = new Sentient_Forms_External_Service_Consent_Repository( $wpdb );

if ( ! is_string( $encrypted ) ) {
    echo wp_json_encode([ 'error' => 'encrypt_failed' ]);
    return;
}

$credential_id = $credentials->create(
    [
        'provider'          => 'openrouter',
        'label'             => $label,
        'auth_mode'         => 'manual_key',
        'encrypted_secret'  => $encrypted,
        'status'            => 'valid',
        'last_validated_at' => current_time( 'mysql' ),
    ]
);

if ( is_wp_error( $credential_id ) ) {
    echo wp_json_encode([ 'error' => $credential_id->get_error_message() ]);
    return;
}

$consent_id = $consents->record( 'openrouter', '2026-04-local-first-openrouter-v1', 0 );
if ( is_wp_error( $consent_id ) ) {
    echo wp_json_encode([ 'error' => $consent_id->get_error_message() ]);
    return;
}

update_option( 'sentient_forms_local_openrouter_smoke_mock_enabled', '1', false );
update_option( 'sentient_forms_local_openrouter_smoke_http_urls', [], false );

echo wp_json_encode(
    [
        'credential_id' => (int) $credential_id,
        'consent_id'    => (int) $consent_id,
    ]
);
`,
		{
			OPENROUTER_LABEL: label
		}
	);

	const parsed = JSON.parse(output) as Partial<LocalProviderSeed> & { error?: string };
	if (parsed.error) {
		throw new Error(`Failed to seed local OpenRouter provider: ${parsed.error}`);
	}

	if (!parsed.credential_id || !parsed.consent_id) {
		throw new Error(`Local OpenRouter provider seed returned an invalid payload: ${output}`);
	}

	return {
		credential_id: parsed.credential_id,
		consent_id: parsed.consent_id
	};
}

function disableLocalOpenRouterMock(): void {
	runWpEval(
		`
delete_option( 'sentient_forms_local_openrouter_smoke_mock_enabled' );
delete_option( 'sentient_forms_local_openrouter_smoke_http_urls' );
echo 'ok';
`
	);
}

function ensureGravityFormPage(formId: number, pageTitle: string): string {
	const output = runWpEval(
		`
$form_id    = (int) getenv( 'FORM_ID' );
$page_title = getenv( 'PAGE_TITLE' ) ?: 'Local OpenRouter Smoke';
$content    = '[gravityform id="' . $form_id . '" title="false" description="false" ajax="false"]';
$existing   = get_page_by_title( $page_title, OBJECT, 'page' );

if ( $existing instanceof WP_Post ) {
    $page_id = wp_update_post(
        [
            'ID'           => $existing->ID,
            'post_status'  => 'publish',
            'post_content' => $content,
        ],
        true
    );
} else {
    $page_id = wp_insert_post(
        [
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => $page_title,
            'post_content' => $content,
        ],
        true
    );
}

if ( is_wp_error( $page_id ) ) {
    echo '';
    return;
}

echo get_permalink( (int) $page_id );
`,
		{
			FORM_ID: String(formId),
			PAGE_TITLE: pageTitle
		}
	);

	if (!output.startsWith('http')) {
		throw new Error(`Failed to create Gravity Forms front-end page: ${output}`);
	}

	return output;
}

async function submitFrontEndGravityForm(
	page: Page,
	formUrl: string,
	formId: number,
	name: string,
	email: string
): Promise<void> {
	await page.goto(formUrl, { waitUntil: 'domcontentloaded' });

	const form = page.locator('.gform_wrapper form').first();
	const nameInput = page.locator('input[name="input_1"]').first();
	const emailInput = page.locator('input[name="input_2"]').first();
	const submitButton = form.locator('input[type="submit"], button[type="submit"], button').first();

	await expect(form, `Gravity Forms page should render form ${formId}`).toBeVisible();
	await expect(
		nameInput,
		`Gravity Forms page should render input_1 for form ${formId}`
	).toBeVisible();
	await expect(
		emailInput,
		`Gravity Forms page should render input_2 for form ${formId}`
	).toBeVisible();
	await expect(
		submitButton,
		`Gravity Forms page should render a submit control for form ${formId}`
	).toBeVisible();

	await nameInput.fill(name);
	await emailInput.fill(email);

	await Promise.all([
		submitButton.click(),
		page.waitForSelector('.gform_confirmation_message, .gform_confirmation_wrapper', {
			timeout: 15000
		})
	]);
}

async function waitForEntryId(
	page: Page,
	formId: number,
	baselineEntryId: number,
	email: string
): Promise<number> {
	for (let attempt = 0; attempt < 10; attempt += 1) {
		const entryId = findEntryIdByEmail(formId, email);
		if (entryId > baselineEntryId) {
			return entryId;
		}

		await page.waitForTimeout(500);
	}

	throw new Error(`Submitted entry for ${email} was not found after browser submission.`);
}

async function waitForSummaryMeta(page: Page, entryId: number): Promise<string> {
	let lastValue: unknown = null;

	for (let attempt = 0; attempt < 10; attempt += 1) {
		if (localOpenRouterBrowserExecutionMode === 'async') {
			runActionScheduler();
		}

		lastValue = getEntryMeta(entryId, 'sentient_forms_summary');
		if (lastValue === 'Browser local-first submission completed.') {
			return lastValue;
		}

		await page.waitForTimeout(500);
	}

	throw new Error(
		`Summary meta was not stored for entry ${entryId}. Last value: ${String(lastValue)}`
	);
}

function getLatestLocalExecutionEvent(entryId: number): LocalExecutionEvent | null {
	const output = runWpEval(
		`
global $wpdb;
$entry_id = getenv( 'ENTRY_ID' ) ?: '';
$events   = new Sentient_Forms_Execution_Events_Repository( $wpdb );

foreach ( $events->list_recent( 20 ) as $event ) {
    if ( (string) ( $event['entry_id'] ?? '' ) === (string) $entry_id ) {
        echo wp_json_encode( $event );
        return;
    }
}

echo 'null';
`,
		{
			ENTRY_ID: String(entryId)
		}
	);

	return JSON.parse(output) as LocalExecutionEvent | null;
}

function getLocalOpenRouterSmokeUrls(): string[] {
	const output = runWpEval(
		`
$urls = get_option( 'sentient_forms_local_openrouter_smoke_http_urls', [] );
echo wp_json_encode( [ 'urls' => is_array( $urls ) ? $urls : [] ] );
`
	);

	const parsed = JSON.parse(output) as { urls?: string[] };
	return Array.isArray(parsed.urls) ? parsed.urls : [];
}

test.describe('Local OpenRouter browser submission @local-openrouter-browser', function () {
	test.skip(
		!runLocalOpenRouterBrowserSmoke,
		'Set SENTIENT_RUN_WP_E2E=1 and SENTIENT_RUN_LOCAL_OPENROUTER_BROWSER_SMOKE=1 to run this local-first browser smoke.'
	);

	test.afterEach(function () {
		if (runLocalOpenRouterBrowserSmoke) {
			disableLocalOpenRouterMock();
		}
	});

	test('creates a local action/mapping in wp-admin and runs it from a front-end Gravity Forms page', async function ({
		page
	}) {
		await requireWpRestHealthy(page);

		const token = String(Date.now());
		const formId = ensureGravityForm(`Local OpenRouter Browser Smoke ${token}`, [
			{ type: 'text', id: 1, label: 'Name', isRequired: true },
			{ type: 'email', id: 2, label: 'Email', isRequired: true }
		]);
		const formUrl = ensureGravityFormPage(formId, `Local OpenRouter Browser Smoke Page ${token}`);
		const seed = seedLocalOpenRouterProvider(`Browser smoke OpenRouter key ${token}`);

		await loginToWpAdmin(page);
		await ensureSentientFormsSpa(page, `/actions/gravity_forms/${formId}`);

		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();
		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();
		await page.getByRole('button', { name: 'Direct OpenRouter' }).click();

		await expect(drawer.getByTestId('local-openrouter-builder')).toBeVisible();
		await drawer.getByTestId('local-builder-credential').selectOption(String(seed.credential_id));
		await drawer
			.getByTestId('local-builder-action-name')
			.fill(`Browser OpenRouter summary ${token}`);
		await drawer.getByTestId('local-builder-result-meta-key').fill('sentient_forms_summary');
		await drawer
			.getByTestId('local-builder-execution-mode')
			.selectOption(localOpenRouterBrowserExecutionMode);
		await drawer.getByRole('button', { name: 'Create local action' }).click();

		await expect(drawer.getByTestId('local-builder-result')).toContainText('Action #');

		const email = `browser-local-${token}@example.test`;
		const baselineEntryId = getLatestEntryId(formId);
		await submitFrontEndGravityForm(page, formUrl, formId, 'Browser Local Lead', email);

		const entryId = await waitForEntryId(page, formId, baselineEntryId, email);
		expect(entryId).toBeGreaterThan(baselineEntryId);

		await expect
			.poll(function () {
				if (localOpenRouterBrowserExecutionMode === 'async') {
					runActionScheduler();
				}

				return getLatestLocalExecutionEvent(entryId)?.status ?? null;
			})
			.toBe('succeeded');

		await expect(waitForSummaryMeta(page, entryId)).resolves.toBe(
			'Browser local-first submission completed.'
		);

		const localResult = getEntryMeta(entryId, '_sentient_forms_local_result');
		expect(localResult && typeof localResult === 'object').toBeTruthy();

		const urls = getLocalOpenRouterSmokeUrls();
		expect(urls.some((url) => url.includes('openrouter.ai/api/v1/chat/completions'))).toBe(true);
		expect(urls.filter((url) => url.includes('sentientforms.com'))).toHaveLength(0);
	});
});
