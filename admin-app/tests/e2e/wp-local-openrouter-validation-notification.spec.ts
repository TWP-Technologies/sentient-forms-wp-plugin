import { expect, test, type Page } from '@playwright/test';
import {
	clearCapturedMail,
	ensureGravityForm,
	findEntryIdByEmail,
	getCapturedMailRecords,
	getEntryMeta,
	getLocalFormMappings,
	getEntrySpamStatus,
	getLatestEntryId,
	requireWpRestHealthy,
	resetLocalFormFixture,
	runWpEval,
	waitForGravityEntryNotes
} from './utils/wp-e2e-helpers';

const runLocalOpenRouterValidationNotificationSmoke =
	process.env.SENTIENT_RUN_WP_E2E === '1' &&
	process.env.SENTIENT_RUN_LOCAL_OPENROUTER_VALIDATION_NOTIFICATION_SMOKE === '1';
const localOpenRouterValidationSmokeCredentialLabel =
	'Validation notification smoke OpenRouter key';
const localOpenRouterValidationFormTitle = 'Local OpenRouter Validation Block';
const localOpenRouterValidationPageTitle = 'Local OpenRouter Validation Block Page';
const localOpenRouterValidationActionCode = 'local_validation_block_browser_smoke';
const localOpenRouterValidationActionName = 'Local validation block';
const localOpenRouterControlFormTitle = 'Local OpenRouter Notification Control';
const localOpenRouterControlPageTitle = 'Local OpenRouter Notification Control Page';
const localOpenRouterControlNotificationSubject = 'Local OpenRouter control notification';
const localOpenRouterSpamFormTitle = 'Local OpenRouter Spam Suppression';
const localOpenRouterSpamPageTitle = 'Local OpenRouter Spam Suppression Page';
const localOpenRouterSpamActionCode = 'local_spam_suppress_browser_smoke';
const localOpenRouterSpamActionName = 'Local spam suppress';
const localOpenRouterSpamNotificationSubject = 'Local OpenRouter spam notification';

type LocalProviderSeed = {
	credential_id: number;
	consent_id: number;
};

type LocalMappingSeed = {
	action_id: number;
	mapping_id: number;
};

type LocalMappingSeedArgs = {
	formId: number;
	hook: 'gform_validation' | 'gform_after_submission';
	actionCode: string;
	displayName: string;
	credentialId: number;
	promptTemplate: string;
	inputBindings?: Record<string, string>;
	effectMapping?: Record<string, unknown>;
};

type LocalExecutionEvent = {
	id?: number;
	status?: string;
	mapping_id?: number;
	entry_id?: string | null;
	provider?: string;
	model?: string;
	result_json?: {
		structured?: Record<string, unknown>;
	};
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
$label       = getenv( 'OPENROUTER_LABEL' ) ?: 'Validation notification smoke OpenRouter key';
$secret      = 'sk-or-validation-notification-smoke-' . wp_generate_uuid4();
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

function createLocalOpenRouterMapping(args: LocalMappingSeedArgs): LocalMappingSeed {
	const output = runWpEval(
		`
if ( ! class_exists( 'Sentient_Forms_Installer' ) ) {
    echo wp_json_encode([ 'error' => 'sentient_forms_not_loaded' ]);
    return;
}

Sentient_Forms_Installer::maybe_upgrade();

$payload = json_decode( getenv( 'LOCAL_MAPPING_DATA' ) ?: '{}', true );
if ( ! is_array( $payload ) ) {
    echo wp_json_encode([ 'error' => 'invalid_payload' ]);
    return;
}

global $wpdb;
$custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
$mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );

$action_id = $custom_actions->upsert_by_code(
    [
        'code'                 => sanitize_key( (string) ( $payload['actionCode'] ?? '' ) ),
        'display_name'         => sanitize_text_field( (string) ( $payload['displayName'] ?? 'Local OpenRouter smoke action' ) ),
        'definition_json'      => [
            'system_prompt'   => 'You are validating or classifying a Gravity Forms submission for a local-first smoke test.',
            'prompt_template' => (string) ( $payload['promptTemplate'] ?? 'Review this submission.' ),
        ],
        'model_selection_json' => [
            'provider'      => 'openrouter',
            'model'         => 'openrouter/auto',
            'credential_id' => absint( $payload['credentialId'] ?? 0 ),
        ],
        'status'               => 'active',
    ]
);

if ( is_wp_error( $action_id ) ) {
    echo wp_json_encode([ 'error' => $action_id->get_error_message() ]);
    return;
}

$form_source  = 'gravity_forms';
$form_id      = (string) absint( $payload['formId'] ?? 0 );
$hook         = sanitize_key( (string) ( $payload['hook'] ?? '' ) );
$mapping_data = [
    'form_source'         => $form_source,
    'form_id'             => $form_id,
    'hook'                => $hook,
    'action_kind'         => 'custom_action',
    'action_id'           => (int) $action_id,
    'input_bindings_json' => is_array( $payload['inputBindings'] ?? null ) ? $payload['inputBindings'] : [],
    'execution_mode'      => 'sync',
    'effect_mapping_json' => is_array( $payload['effectMapping'] ?? null ) ? $payload['effectMapping'] : [],
    'enabled'             => true,
];

$existing_mapping = null;
foreach ( $mappings->list_for_form( $form_source, $form_id ) as $candidate ) {
    if (
        'custom_action' === (string) ( $candidate['action_kind'] ?? '' ) &&
        $hook === (string) ( $candidate['hook'] ?? '' ) &&
        (int) $action_id === (int) ( $candidate['action_id'] ?? 0 )
    ) {
        $existing_mapping = $candidate;
        break;
    }
}

if ( is_array( $existing_mapping ) && absint( $existing_mapping['id'] ?? 0 ) > 0 ) {
    $updated_mapping = $mappings->update( absint( $existing_mapping['id'] ), $mapping_data );
    if ( is_wp_error( $updated_mapping ) ) {
        echo wp_json_encode([ 'error' => $updated_mapping->get_error_message() ]);
        return;
    }

    $mapping_id = absint( $updated_mapping['id'] ?? 0 );
} else {
    $mapping_id = $mappings->create( $mapping_data );
}

if ( is_wp_error( $mapping_id ) ) {
    echo wp_json_encode([ 'error' => $mapping_id->get_error_message() ]);
    return;
}

echo wp_json_encode(
    [
        'action_id'  => (int) $action_id,
        'mapping_id' => (int) $mapping_id,
    ]
);
`,
		{
			LOCAL_MAPPING_DATA: JSON.stringify(args)
		}
	);

	const parsed = JSON.parse(output) as Partial<LocalMappingSeed> & { error?: string };
	if (parsed.error) {
		throw new Error(`Failed to create local OpenRouter mapping: ${parsed.error}`);
	}

	if (!parsed.action_id || !parsed.mapping_id) {
		throw new Error(`Local OpenRouter mapping seed returned an invalid payload: ${output}`);
	}

	return {
		action_id: parsed.action_id,
		mapping_id: parsed.mapping_id
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

function disableLocalOpenRouterMock(): void {
	runWpEval(
		`
delete_option( 'sentient_forms_local_openrouter_smoke_mock_enabled' );
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

async function fillGravityForm(
	page: Page,
	formUrl: string,
	formId: number,
	values: Record<string, string>
) {
	await page.goto(formUrl, { waitUntil: 'domcontentloaded' });

	const form = page.locator('.gform_wrapper form').first();
	await expect(form, `Gravity Forms page should render form ${formId}`).toBeVisible();

	for (const [fieldId, value] of Object.entries(values)) {
		const field = page
			.locator(`input[name="input_${fieldId}"], textarea[name="input_${fieldId}"]`)
			.first();
		await expect(field, `Gravity Forms page should render input_${fieldId}`).toBeVisible();
		await field.fill(value);
	}
}

async function submitGravityFormForConfirmation(
	page: Page,
	formUrl: string,
	formId: number,
	values: Record<string, string>
): Promise<void> {
	await fillGravityForm(page, formUrl, formId, values);

	const submitButton = page
		.locator('.gform_wrapper form')
		.first()
		.locator('input[type="submit"], button[type="submit"], button')
		.first();
	await expect(
		submitButton,
		`Gravity Forms page should render a submit control for ${formId}`
	).toBeVisible();

	await Promise.all([
		submitButton.click(),
		page.waitForSelector('.gform_confirmation_message, .gform_confirmation_wrapper', {
			timeout: 15000
		})
	]);
}

async function submitGravityFormForValidationError(
	page: Page,
	formUrl: string,
	formId: number,
	values: Record<string, string>,
	message: string
): Promise<void> {
	await fillGravityForm(page, formUrl, formId, values);

	const submitButton = page
		.locator('.gform_wrapper form')
		.first()
		.locator('input[type="submit"], button[type="submit"], button')
		.first();
	await expect(
		submitButton,
		`Gravity Forms page should render a submit control for ${formId}`
	).toBeVisible();
	await submitButton.click();
	await expect(page.getByText(message, { exact: false })).toBeVisible({ timeout: 15000 });
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

function getLatestLocalExecutionEventByMapping(mappingId: number): LocalExecutionEvent | null {
	const output = runWpEval(
		`
global $wpdb;
$mapping_id = absint( getenv( 'MAPPING_ID' ) ?: 0 );
$events     = new Sentient_Forms_Execution_Events_Repository( $wpdb );

foreach ( $events->list_recent( 50 ) as $event ) {
    if ( (int) ( $event['mapping_id'] ?? 0 ) === $mapping_id ) {
        echo wp_json_encode( $event );
        return;
    }
}

echo 'null';
`,
		{
			MAPPING_ID: String(mappingId)
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

test.describe('Local OpenRouter validation and notification package smoke @local-openrouter-validation-notification', function () {
	test.skip(
		!runLocalOpenRouterValidationNotificationSmoke,
		'Set SENTIENT_RUN_WP_E2E=1 and SENTIENT_RUN_LOCAL_OPENROUTER_VALIDATION_NOTIFICATION_SMOKE=1 to run this local-first package smoke.'
	);

	test.afterEach(function () {
		if (runLocalOpenRouterValidationNotificationSmoke) {
			disableLocalOpenRouterMock();
			clearCapturedMail();
		}
	});

	test('blocks validation and suppresses spam notifications from local OpenRouter without Sentient calls', async function ({
		page
	}) {
		await requireWpRestHealthy(page);

		const token = String(Date.now());
		const seed = seedLocalOpenRouterProvider(localOpenRouterValidationSmokeCredentialLabel);

		const validationFormId = ensureGravityForm(localOpenRouterValidationFormTitle, [
			{ type: 'text', id: 1, label: 'Name', isRequired: true },
			{ type: 'email', id: 2, label: 'Email', isRequired: true },
			{ type: 'textarea', id: 3, label: 'Project Details', isRequired: true }
		]);
		resetLocalFormFixture({
			formId: validationFormId,
			actionCodes: [localOpenRouterValidationActionCode],
			actionNames: [localOpenRouterValidationActionName]
		});
		expect(getLocalFormMappings(validationFormId)).toEqual([]);
		const validationFormUrl = ensureGravityFormPage(
			validationFormId,
			localOpenRouterValidationPageTitle
		);
		const validationMapping = createLocalOpenRouterMapping({
			formId: validationFormId,
			hook: 'gform_validation',
			actionCode: localOpenRouterValidationActionCode,
			displayName: localOpenRouterValidationActionName,
			credentialId: seed.credential_id,
			promptTemplate: 'Block this submission when the project details are insufficient.',
			inputBindings: {
				name: '1',
				email: '2',
				details: '3'
			}
		});
		const validationMappings = getLocalFormMappings(validationFormId);
		expect(validationMappings).toHaveLength(1);
		expect(validationMappings[0]?.id).toBe(validationMapping.mapping_id);
		expect(validationMappings[0]).toMatchObject({
			form_source: 'gravity_forms',
			form_id: String(validationFormId),
			hook: 'gform_validation',
			action_kind: 'custom_action',
			execution_mode: 'sync',
			enabled: true
		});

		setLocalOpenRouterMockResponse({
			is_valid: false,
			message: 'Local validation blocked this submission.',
			fields: [
				{
					field_id: '3',
					is_valid: false,
					message: 'Project details need more substance.'
				}
			]
		});

		const validationBaselineEntryId = getLatestEntryId(validationFormId);
		await submitGravityFormForValidationError(
			page,
			validationFormUrl,
			validationFormId,
			{
				1: 'Validation Local Lead',
				2: `validation-local-${token}@example.test`,
				3: 'Too short.'
			},
			'Project details need more substance.'
		);

		expect(getLatestEntryId(validationFormId)).toBe(validationBaselineEntryId);
		const validationEvent = getLatestLocalExecutionEventByMapping(validationMapping.mapping_id);
		expect(validationEvent?.status).toBe('succeeded');
		expect(validationEvent?.result_json?.structured?.message).toBe(
			'Local validation blocked this submission.'
		);
		expect(getLocalOpenRouterSmokeUrls()).toContainEqual(
			expect.stringContaining('openrouter.ai/api/v1/chat/completions')
		);
		expect(
			getLocalOpenRouterSmokeUrls().filter((url) => url.includes('sentientforms.com'))
		).toHaveLength(0);

		const controlFormId = ensureGravityForm(
			localOpenRouterControlFormTitle,
			[
				{ type: 'text', id: 1, label: 'Name', isRequired: true },
				{ type: 'email', id: 2, label: 'Email', isRequired: true },
				{ type: 'textarea', id: 3, label: 'Message', isRequired: true }
			],
			{
				notifications: [
					{
						id: 'control_admin',
						name: 'Control Admin Notification',
						event: 'form_submission',
						to: 'admin@example.test',
						subject: localOpenRouterControlNotificationSubject,
						message: 'Control notification should send.'
					}
				]
			}
		);
		const controlFormUrl = ensureGravityFormPage(controlFormId, localOpenRouterControlPageTitle);

		clearCapturedMail();
		await submitGravityFormForConfirmation(page, controlFormUrl, controlFormId, {
			1: 'Control Local Lead',
			2: `control-local-${token}@example.test`,
			3: 'This submission proves Gravity Forms notifications are being captured.'
		});

		await expect
			.poll(function () {
				return getCapturedMailRecords().filter(
					(record) => record.subject === localOpenRouterControlNotificationSubject
				).length;
			})
			.toBeGreaterThan(0);

		const spamFormId = ensureGravityForm(
			localOpenRouterSpamFormTitle,
			[
				{ type: 'text', id: 1, label: 'Name', isRequired: true },
				{ type: 'email', id: 2, label: 'Email', isRequired: true },
				{ type: 'textarea', id: 3, label: 'Message', isRequired: true }
			],
			{
				notifications: [
					{
						id: 'spam_admin',
						name: 'Spam Admin Notification',
						event: 'form_submission',
						to: 'admin@example.test',
						subject: localOpenRouterSpamNotificationSubject,
						message: 'Spam notification should be suppressed.'
					}
				]
			}
		);
		resetLocalFormFixture({
			formId: spamFormId,
			actionCodes: [localOpenRouterSpamActionCode],
			actionNames: [localOpenRouterSpamActionName]
		});
		expect(getLocalFormMappings(spamFormId)).toEqual([]);
		const spamFormUrl = ensureGravityFormPage(spamFormId, localOpenRouterSpamPageTitle);
		const spamMapping = createLocalOpenRouterMapping({
			formId: spamFormId,
			hook: 'gform_after_submission',
			actionCode: localOpenRouterSpamActionCode,
			displayName: localOpenRouterSpamActionName,
			credentialId: seed.credential_id,
			promptTemplate: 'Classify this submission for spam and suppress notifications when spam.',
			inputBindings: {
				name: '1',
				email: '2',
				message: '3'
			},
			effectMapping: {
				store_result: true,
				entry_note: {
					path: 'structured.summary',
					prefix: 'Local spam suppression:'
				},
				spam: {
					enabled: true,
					classification_path: 'structured.classification',
					confidence_path: 'structured.confidence',
					min_confidence: 0.8,
					suppress_notifications_on_spam: true
				},
				meta: {
					sentient_forms_spam_summary: 'structured.summary'
				}
			}
		});
		const spamMappings = getLocalFormMappings(spamFormId);
		expect(spamMappings).toHaveLength(1);
		expect(spamMappings[0]?.id).toBe(spamMapping.mapping_id);
		expect(spamMappings[0]).toMatchObject({
			form_source: 'gravity_forms',
			form_id: String(spamFormId),
			hook: 'gform_after_submission',
			action_kind: 'custom_action',
			execution_mode: 'sync',
			enabled: true
		});

		setLocalOpenRouterMockResponse({
			classification: 'spam',
			confidence: 0.99,
			summary: 'Exact-package local spam suppression completed.'
		});

		clearCapturedMail();
		const spamEmail = `spam-local-${token}@example.test`;
		const spamBaselineEntryId = getLatestEntryId(spamFormId);
		await submitGravityFormForConfirmation(page, spamFormUrl, spamFormId, {
			1: 'Spam Local Lead',
			2: spamEmail,
			3: 'WIN BIG NOW, claim a free prize and send card details.'
		});

		const spamEntryId = await waitForEntryId(page, spamFormId, spamBaselineEntryId, spamEmail);
		await expect
			.poll(function () {
				return getLatestLocalExecutionEventByMapping(spamMapping.mapping_id)?.status ?? null;
			})
			.toBe('succeeded');

		const spamStatus = getEntrySpamStatus(spamEntryId);
		expect(spamStatus.status).toBe('spam');
		expect(spamStatus.classification).toBe('spam');
		expect(getEntryMeta(spamEntryId, 'sentient_forms_spam_summary')).toBe(
			'Exact-package local spam suppression completed.'
		);
		const localSpamNotes = (
			await waitForGravityEntryNotes(
				spamEntryId,
				page,
				(notes) =>
					notes.some(
						(note) =>
							note.user_name === 'Sentient Forms AI' &&
							note.note_type === 'sentient_forms_local_action' &&
							note.value.includes('Local spam suppression:') &&
							note.value.includes('Exact-package local spam suppression completed.')
					),
				{
					description: 'the local spam suppression note'
				}
			)
		).filter(
			(note) =>
				note.user_name === 'Sentient Forms AI' &&
				note.note_type === 'sentient_forms_local_action' &&
				note.value.includes('Local spam suppression:')
		);
		expect(localSpamNotes).toHaveLength(1);
		expect(localSpamNotes[0]?.value).toContain('Exact-package local spam suppression completed.');
		expect(getEntryMeta(spamEntryId, 'sentient_forms_spam_notification_preference')).toBe(
			'suppress'
		);
		expect(
			getCapturedMailRecords().filter(
				(record) => record.subject === localOpenRouterSpamNotificationSubject
			)
		).toHaveLength(0);

		const urls = getLocalOpenRouterSmokeUrls();
		expect(urls.some((url) => url.includes('openrouter.ai/api/v1/chat/completions'))).toBe(true);
		expect(urls.filter((url) => url.includes('sentientforms.com'))).toHaveLength(0);
	});
});
