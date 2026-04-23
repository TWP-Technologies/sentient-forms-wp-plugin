import { expect, test, type Locator, type Page } from '@playwright/test';
import {
	ensureGravityForm,
	findEntryIdByEmail,
	getEntryMeta,
	getLocalFormMappings,
	getEntrySpamStatus,
	getLatestEntryId,
	requireWpRestHealthy,
	resetLocalFormFixture,
	runActionScheduler,
	runWpEval,
	waitForGravityEntryNotes
} from './utils/wp-e2e-helpers';
import { ensureSentientFormsSpa, loginToWpAdmin } from './utils/wp-admin';

const runLocalOpenRouterBrowserSmoke =
	process.env.SENTIENT_RUN_WP_E2E === '1' &&
	process.env.SENTIENT_RUN_LOCAL_OPENROUTER_BROWSER_SMOKE === '1';
const localOpenRouterBrowserExecutionMode =
	process.env.SENTIENT_FORMS_LOCAL_OPENROUTER_EXECUTION_MODE === 'async' ? 'async' : 'sync';
const localOpenRouterBrowserSmokeFormTitle = 'Local OpenRouter Browser Smoke';
const localOpenRouterBrowserSmokePageTitle = 'Local OpenRouter Browser Smoke Page';
const localOpenRouterBrowserSmokeCredentialLabel = 'Browser smoke OpenRouter key';
const localOpenRouterBrowserSmokeActionName = 'Local OpenRouter spam filter';

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

async function ensureDirectOpenRouterBuilder(page: Page, drawer: Locator): Promise<void> {
	const builder = drawer.getByTestId('local-openrouter-builder');
	if (!(await builder.isVisible({ timeout: 1000 }).catch(() => false))) {
		await page.getByTestId('create-kind-local-openrouter').click();
	}
	await expect(builder).toBeVisible();
}

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
$table       = $wpdb->prefix . 'sentient_provider_credentials';

if ( ! is_string( $encrypted ) ) {
    echo wp_json_encode([ 'error' => 'encrypt_failed' ]);
    return;
}

$existing_rows = $wpdb->get_results(
    $wpdb->prepare(
        'SELECT id FROM ' . esc_sql( $table ) . ' WHERE provider = %s AND auth_mode = %s AND label = %s ORDER BY id ASC',
        'openrouter',
        'manual_key',
        $label
    ),
    ARRAY_A
);

$existing_rows = is_array( $existing_rows ) ? $existing_rows : [];
$primary_id    = 0;

foreach ( $existing_rows as $index => $row ) {
    $row_id = absint( $row['id'] ?? 0 );
    if ( $row_id <= 0 ) {
        continue;
    }

    if ( 0 === $index ) {
        $primary_id = $row_id;
        continue;
    }

    $credentials->delete( $row_id );
}

if ( $primary_id > 0 ) {
    $updated = $wpdb->update(
        $table,
        [
            'encrypted_secret'  => $encrypted,
            'status'            => 'valid',
            'status_json'       => null,
            'last_validated_at' => current_time( 'mysql' ),
            'updated_at'        => current_time( 'mysql' ),
        ],
        [ 'id' => $primary_id ],
        [ '%s', '%s', '%s', '%s', '%s' ],
        [ '%d' ]
    );

    if ( false === $updated ) {
        echo wp_json_encode([ 'error' => 'credential_update_failed' ]);
        return;
    }

    $credential_id = $primary_id;
} else {
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
}

if ( is_wp_error( $credential_id ) ) {
    echo wp_json_encode([ 'error' => $credential_id->get_error_message() ]);
    return;
}

$latest_consent = $consents->latest_for_provider( 'openrouter' );
if (
    is_array( $latest_consent ) &&
    '2026-04-local-first-openrouter-v1' === (string) ( $latest_consent['disclosure_version'] ?? '' )
) {
    $consent_id = absint( $latest_consent['id'] ?? 0 );
} else {
    $consent_id = $consents->record( 'openrouter', '2026-04-local-first-openrouter-v1', 0 );
    if ( is_wp_error( $consent_id ) ) {
        echo wp_json_encode([ 'error' => $consent_id->get_error_message() ]);
        return;
    }
}

update_option( 'sentient_forms_local_openrouter_smoke_mock_enabled', '1', false );
update_option( 'sentient_forms_local_openrouter_smoke_http_urls', [], false );
delete_option( 'sentient_forms_local_openrouter_smoke_response_json' );

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

function setLocalOpenRouterMockResponse(payload: Record<string, unknown>): void {
	const output = runWpEval(
		`
$payload = json_decode( getenv( 'MOCK_RESPONSE_JSON' ) ?: '{}', true );
if ( ! is_array( $payload ) ) {
    echo 'invalid_payload';
    return;
}

update_option( 'sentient_forms_local_openrouter_smoke_mock_enabled', '1', false );
update_option( 'sentient_forms_local_openrouter_smoke_mode', 'success', false );
update_option( 'sentient_forms_local_openrouter_smoke_response_json', $payload, false );
update_option( 'sentient_forms_local_openrouter_smoke_http_urls', [], false );
echo 'ok';
`,
		{
			MOCK_RESPONSE_JSON: JSON.stringify(payload)
		}
	);

	if (!output.includes('ok')) {
		throw new Error(`Failed to set local OpenRouter mock response: ${output}`);
	}
}

function setLocalOpenRouterMockMode(
	mode: 'success' | 'missing_auth_wp_error' | 'http_429' | 'malformed_json'
): void {
	const output = runWpEval(
		`
$mode = getenv( 'MOCK_MODE' ) ?: 'success';
update_option( 'sentient_forms_local_openrouter_smoke_mock_enabled', '1', false );
update_option( 'sentient_forms_local_openrouter_smoke_mode', $mode, false );
update_option( 'sentient_forms_local_openrouter_smoke_http_urls', [], false );
delete_option( 'sentient_forms_local_openrouter_smoke_response_json' );
echo 'ok';
`,
		{
			MOCK_MODE: mode
		}
	);

	if (!output.includes('ok')) {
		throw new Error(`Failed to set local OpenRouter mock mode: ${output}`);
	}
}

function disableLocalOpenRouterMock(): void {
	runWpEval(
		`
delete_option( 'sentient_forms_local_openrouter_smoke_mock_enabled' );
delete_option( 'sentient_forms_local_openrouter_smoke_mode' );
delete_option( 'sentient_forms_local_openrouter_smoke_http_urls' );
delete_option( 'sentient_forms_local_openrouter_smoke_response_json' );
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
	submission: {
		name: string;
		email: string;
		message?: string;
	}
): Promise<void> {
	await page.goto(formUrl, { waitUntil: 'domcontentloaded' });

	const form = page.locator('.gform_wrapper form').first();
	const nameInput = page.locator('input[name="input_1"]').first();
	const emailInput = page.locator('input[name="input_2"]').first();
	const messageInput = page.locator('textarea[name="input_3"], input[name="input_3"]').first();
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

	await nameInput.fill(submission.name);
	await emailInput.fill(submission.email);
	if (submission.message) {
		await expect(
			messageInput,
			`Gravity Forms page should render input_3 for form ${formId}`
		).toBeVisible();
		await messageInput.fill(submission.message);
	}

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

async function waitForSpamStatus(
	page: Page,
	entryId: number
): Promise<ReturnType<typeof getEntrySpamStatus>> {
	let lastValue: ReturnType<typeof getEntrySpamStatus> | null = null;

	for (let attempt = 0; attempt < 10; attempt += 1) {
		if (localOpenRouterBrowserExecutionMode === 'async') {
			runActionScheduler();
		}

		lastValue = getEntrySpamStatus(entryId);
		if (lastValue.status === 'spam' && lastValue.classification === 'spam') {
			return lastValue;
		}

		await page.waitForTimeout(500);
	}

	throw new Error(
		`Spam status was not stored for entry ${entryId}. Last value: ${JSON.stringify(lastValue)}`
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
		const formId = ensureGravityForm(localOpenRouterBrowserSmokeFormTitle, [
			{ type: 'text', id: 1, label: 'Name', isRequired: true },
			{ type: 'email', id: 2, label: 'Email', isRequired: true },
			{ type: 'textarea', id: 3, label: 'Message', isRequired: true }
		]);
		resetLocalFormFixture({
			formId,
			actionNames: [localOpenRouterBrowserSmokeActionName]
		});
		expect(getLocalFormMappings(formId)).toEqual([]);
		const formUrl = ensureGravityFormPage(formId, localOpenRouterBrowserSmokePageTitle);
		const seed = seedLocalOpenRouterProvider(localOpenRouterBrowserSmokeCredentialLabel);

		setLocalOpenRouterMockResponse({
			classification: 'spam',
			confidence: 0.99,
			justification: 'Browser local-first spam filter completed.'
		});

		await loginToWpAdmin(page);
		await ensureSentientFormsSpa(page, `/actions/gravity_forms/${formId}`);

		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();
		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();
		await ensureDirectOpenRouterBuilder(page, drawer);
		await expect(drawer.getByTestId('local-builder-template')).toHaveValue('spam_filter');
		await drawer.getByTestId('local-builder-credential').selectOption(String(seed.credential_id));
		await expect(drawer.getByTestId('local-builder-result-meta-key')).toHaveValue(
			'sentient_forms_spam_classification'
		);
		await drawer
			.getByTestId('local-builder-action-name')
			.fill(localOpenRouterBrowserSmokeActionName);
		await drawer
			.getByTestId('local-builder-execution-mode')
			.selectOption(localOpenRouterBrowserExecutionMode);
		const submitButton = drawer.getByTestId('link-action-submit');
		await expect(submitButton).toContainText('Create Direct OpenRouter action');
		await submitButton.click();

		await expect(drawer.getByTestId('local-builder-result')).toContainText('Action #');
		const mappings = getLocalFormMappings(formId);
		const expectedHook =
			localOpenRouterBrowserExecutionMode === 'sync'
				? 'gform_validation'
				: 'gform_after_submission';
		expect(mappings).toHaveLength(1);
		expect(mappings[0]).toMatchObject({
			form_source: 'gravity_forms',
			form_id: String(formId),
			hook: expectedHook,
			action_kind: 'custom_action',
			execution_mode: localOpenRouterBrowserExecutionMode,
			enabled: true
		});

		const email = `browser-local-${token}@example.test`;
		const baselineEntryId = getLatestEntryId(formId);
		await submitFrontEndGravityForm(page, formUrl, formId, {
			name: 'Browser Local Lead',
			email,
			message: 'Claim your free prize now and reply with payment details immediately.'
		});

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

		await expect(waitForSpamStatus(page, entryId)).resolves.toMatchObject({
			status: 'spam',
			classification: 'spam',
			is_spam: true
		});

		const localResult = getEntryMeta(entryId, '_sentient_forms_local_result');
		expect(localResult && typeof localResult === 'object').toBeTruthy();
		expect(getEntryMeta(entryId, 'sentient_forms_spam_classification')).toBe('spam');
		const localActionNotes = (
			await waitForGravityEntryNotes(
				entryId,
				page,
				(notes) =>
					notes.some(
						(note) =>
							note.user_name === 'Sentient Forms AI' &&
							note.note_type === 'sentient_forms_local_action' &&
							note.value.includes('Sentient Forms AI classified this entry as SPAM') &&
							note.value.includes('Browser local-first spam filter completed.')
					),
				{
					description: 'the local OpenRouter browser spam note',
					runScheduler: localOpenRouterBrowserExecutionMode === 'async'
				}
			)
		).filter(
			(note) =>
				note.user_name === 'Sentient Forms AI' &&
				note.note_type === 'sentient_forms_local_action' &&
				note.value.includes('Sentient Forms AI classified this entry as SPAM')
		);
		expect(localActionNotes).toHaveLength(1);
		expect(localActionNotes[0]?.value).toContain('Browser local-first spam filter completed.');
		expect(
			localActionNotes.some((note) => note.value.includes('Missing Authentication header'))
		).toBe(false);

		const urls = getLocalOpenRouterSmokeUrls();
		expect(urls.some((url) => url.includes('openrouter.ai/api/v1/chat/completions'))).toBe(true);
		expect(urls.filter((url) => url.includes('sentientforms.com'))).toHaveLength(0);
	});

	test('surfaces a local provider transport failure without falling back to stale success state', async function ({
		page
	}) {
		await requireWpRestHealthy(page);

		const token = `failure-${Date.now()}`;
		const formId = ensureGravityForm(localOpenRouterBrowserSmokeFormTitle, [
			{ type: 'text', id: 1, label: 'Name', isRequired: true },
			{ type: 'email', id: 2, label: 'Email', isRequired: true },
			{ type: 'textarea', id: 3, label: 'Message', isRequired: true }
		]);
		resetLocalFormFixture({
			formId,
			actionNames: [localOpenRouterBrowserSmokeActionName]
		});
		expect(getLocalFormMappings(formId)).toEqual([]);
		const formUrl = ensureGravityFormPage(formId, localOpenRouterBrowserSmokePageTitle);
		const seed = seedLocalOpenRouterProvider(localOpenRouterBrowserSmokeCredentialLabel);

		setLocalOpenRouterMockMode('missing_auth_wp_error');

		await loginToWpAdmin(page);
		await ensureSentientFormsSpa(page, `/actions/gravity_forms/${formId}`);

		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();
		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();
		await ensureDirectOpenRouterBuilder(page, drawer);
		await drawer.getByTestId('local-builder-credential').selectOption(String(seed.credential_id));
		await drawer
			.getByTestId('local-builder-action-name')
			.fill(localOpenRouterBrowserSmokeActionName);
		await drawer
			.getByTestId('local-builder-execution-mode')
			.selectOption(localOpenRouterBrowserExecutionMode);
		const submitButton = drawer.getByTestId('link-action-submit');
		await expect(submitButton).toContainText('Create Direct OpenRouter action');
		await submitButton.click();

		const email = `${token}@example.test`;
		const baselineEntryId = getLatestEntryId(formId);
		await submitFrontEndGravityForm(page, formUrl, formId, {
			name: 'Browser Local Failure',
			email,
			message: 'This submission should record the local provider transport error.'
		});

		const entryId = await waitForEntryId(page, formId, baselineEntryId, email);
		expect(entryId).toBeGreaterThan(baselineEntryId);

		await expect
			.poll(function () {
				if (localOpenRouterBrowserExecutionMode === 'async') {
					runActionScheduler();
				}

				return getLatestLocalExecutionEvent(entryId)?.status ?? null;
			})
			.toBe('failed');

		const failureNotes = await waitForGravityEntryNotes(
			entryId,
			page,
			(notes) =>
				notes.some(
					(note) =>
						note.user_name === 'Sentient Forms AI' &&
						note.note_type === 'sentient_forms_local_action' &&
						note.value.includes('Missing Authentication header')
				),
			{
				description: 'the local OpenRouter failure note',
				runScheduler: localOpenRouterBrowserExecutionMode === 'async'
			}
		);
		expect(failureNotes.some((note) => note.value.includes('Missing Authentication header'))).toBe(
			true
		);

		await ensureSentientFormsSpa(page, `/actions/gravity_forms/${formId}`);
		const executionStatusError = page
			.locator('p')
			.filter({ hasText: /Last error: openrouter_http_error/i })
			.first();
		await expect(executionStatusError).toBeVisible();
		await expect(executionStatusError).toContainText('Missing Authentication header');
		await expect(page.getByText('success', { exact: true })).toHaveCount(0);

		const urls = getLocalOpenRouterSmokeUrls();
		expect(urls.some((url) => url.includes('openrouter.ai/api/v1/chat/completions'))).toBe(true);
		expect(urls.filter((url) => url.includes('sentientforms.com'))).toHaveLength(0);
	});
});
