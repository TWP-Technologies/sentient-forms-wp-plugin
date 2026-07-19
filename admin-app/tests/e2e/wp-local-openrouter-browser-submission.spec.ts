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
import {
	parseLocalExecutionEvent,
	parseLocalExecutionEvents,
	parseLocalOpenRouterRequests,
	parseLocalOpenRouterSmokeUrls,
	parseLocalSpamSummaryChainSeed,
	type LocalExecutionEvent,
	type LocalOpenRouterRequest,
	type LocalSpamSummaryChainSeed
} from './utils/local-runtime-schemas';
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

type LocalCustomActionSeed = {
	action_id: number;
	mapping_id: number;
	model_selection_json: Record<string, unknown>;
};

async function ensureDirectOpenRouterBuilder(page: Page, drawer: Locator): Promise<void> {
	const builder = drawer.getByTestId('local-openrouter-builder');
	if (!(await builder.isVisible({ timeout: 1000 }).catch(() => false))) {
		await page.getByTestId('create-kind-local-openrouter').click();
	}
	await expect(builder).toBeVisible();
}

function seedLocalOpenRouterProvider(
	label: string,
	options: { exclusive?: boolean } = {}
): LocalProviderSeed {
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
$exclusive   = '1' === ( getenv( 'OPENROUTER_EXCLUSIVE' ) ?: '' );

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

if ( $exclusive ) {
    $other_rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT id FROM ' . esc_sql( $table ) . ' WHERE provider = %s AND id <> %d ORDER BY id ASC',
            'openrouter',
            (int) $credential_id
        ),
        ARRAY_A
    );

    foreach ( is_array( $other_rows ) ? $other_rows : [] as $row ) {
        $row_id = absint( $row['id'] ?? 0 );
        if ( $row_id > 0 ) {
            $credentials->delete( $row_id );
        }
    }
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
update_option( 'sentient_forms_local_openrouter_smoke_requests', [], false );
delete_option( 'sentient_forms_local_openrouter_smoke_response_json' );

echo wp_json_encode(
    [
        'credential_id' => (int) $credential_id,
        'consent_id'    => (int) $consent_id,
    ]
);
`,
		{
			OPENROUTER_EXCLUSIVE: options.exclusive ? '1' : '',
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
update_option( 'sentient_forms_local_openrouter_smoke_requests', [], false );
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
update_option( 'sentient_forms_local_openrouter_smoke_requests', [], false );
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
delete_option( 'sentient_forms_local_openrouter_smoke_requests' );
delete_option( 'sentient_forms_local_openrouter_smoke_response_json' );
echo 'ok';
`
	);
}

function getLocalOpenRouterSmokeRequests(): LocalOpenRouterRequest[] {
	const output = runWpEval(
		`
$requests = get_option( 'sentient_forms_local_openrouter_smoke_requests', [] );
echo wp_json_encode( is_array( $requests ) ? $requests : [] );
`
	);
	return parseLocalOpenRouterRequests(output);
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

	return parseLocalExecutionEvent(output);
}

function getLocalExecutionEvents(entryId: number): LocalExecutionEvent[] {
	const output = runWpEval(
		`
global $wpdb;
$entry_id = getenv( 'ENTRY_ID' ) ?: '';
$events   = new Sentient_Forms_Execution_Events_Repository( $wpdb );
$matched  = [];

foreach ( $events->list_recent( 100 ) as $event ) {
    if ( (string) ( $event['entry_id'] ?? '' ) === (string) $entry_id ) {
        $matched[] = $event;
    }
}

echo wp_json_encode( $matched );
`,
		{
			ENTRY_ID: String(entryId)
		}
	);

	return parseLocalExecutionEvents(output);
}

function getLocalOpenRouterSmokeUrls(): string[] {
	const output = runWpEval(
		`
$urls = get_option( 'sentient_forms_local_openrouter_smoke_http_urls', [] );
echo wp_json_encode( [ 'urls' => is_array( $urls ) ? $urls : [] ] );
`
	);

	return parseLocalOpenRouterSmokeUrls(output);
}

function seedLocalSpamSummaryChain(
	formId: number,
	credentialId: number
): LocalSpamSummaryChainSeed {
	const output = runWpEval(
		`
if ( ! class_exists( 'Sentient_Forms_Installer' ) ) {
    echo wp_json_encode([ 'error' => 'sentient_forms_not_loaded' ]);
    return;
}

Sentient_Forms_Installer::maybe_upgrade();

global $wpdb;
$form_id       = (string) absint( getenv( 'FORM_ID' ) ?: 0 );
$credential_id = absint( getenv( 'CREDENTIAL_ID' ) ?: 0 );

if ( '' === $form_id || '0' === $form_id || $credential_id <= 0 ) {
    echo wp_json_encode([ 'error' => 'invalid_seed_input' ]);
    return;
}

$templates      = new Sentient_Forms_Action_Templates_Repository( $wpdb );
$custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
$mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
$model_cache    = new Sentient_Forms_Model_Cache_Repository( $wpdb );
$created        = [];
$model_id       = 'google/gemini-3-flash-preview';

$model_cache->upsert(
    'openrouter',
    $model_id,
    [
        'id'                   => $model_id,
        'name'                 => 'Google: Gemini 3 Flash Preview',
        'free'                 => false,
        'context_length'       => 1048576,
        'input_modalities'     => [ 'text' ],
        'output_modalities'    => [ 'text' ],
        'supported_parameters' => [ 'response_format', 'structured_outputs' ],
        'pricing'              => [
            'prompt'     => '0.0000005',
            'completion' => '0.000003',
        ],
    ],
    gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS )
);

foreach (
    [
        'spam' => [
            'template_code' => 'spam_detection_v1',
            'action_code'   => 'browser_chain_spam_' . $form_id,
            'display_name'  => 'Browser chain spam',
        ],
        'summary' => [
            'template_code' => 'entry_summary_v1',
            'action_code'   => 'browser_chain_summary_' . $form_id,
            'display_name'  => 'Browser chain summary',
        ],
    ] as $key => $seed
) {
    $definition = Sentient_Forms_Bundled_Action_Templates::get( $seed['template_code'] );
    if ( ! is_array( $definition ) ) {
        echo wp_json_encode([ 'error' => 'missing_' . $key . '_definition' ]);
        return;
    }

    $template_id = $templates->upsert_by_code(
        [
            'source'                   => 'bundled',
            'code'                     => $seed['template_code'],
            'display_name'             => $definition['display_name'] ?? $seed['display_name'],
            'description'              => $definition['description'] ?? null,
            'prompt_template'          => $definition['prompt_template'] ?? '',
            'default_model'            => $definition['default_model'] ?? 'openrouter/auto',
            'structured_output_schema' => $definition['structured_output_schema'] ?? null,
            'override_schema'          => $definition['override_schema'] ?? null,
            'version'                  => $definition['version'] ?? '1',
            'is_active'                => true,
        ]
    );
    if ( is_wp_error( $template_id ) ) {
        echo wp_json_encode([ 'error' => $template_id->get_error_message() ]);
        return;
    }

    $action_id = $custom_actions->upsert_by_code(
        [
            'template_id'          => $template_id,
            'code'                 => $seed['action_code'],
            'display_name'         => $seed['display_name'],
            'definition_json'      => array_merge(
                is_array( $definition['definition_json'] ?? null ) ? $definition['definition_json'] : [],
                [ 'template_code' => $seed['template_code'] ]
            ),
            'model_selection_json' => [
                'provider'      => 'openrouter',
                'model'         => $model_id,
                'credential_id' => $credential_id,
            ],
            'status'               => 'active',
        ]
    );
    if ( is_wp_error( $action_id ) ) {
        echo wp_json_encode([ 'error' => $action_id->get_error_message() ]);
        return;
    }

    $created[ $key ] = [
        'action_id'  => (int) $action_id,
        'definition' => $definition,
    ];
}

$spam_effects = is_array( $created['spam']['definition']['effect_mapping_json'] ?? null )
    ? $created['spam']['definition']['effect_mapping_json']
    : [];
$spam_effects['spam'] = array_merge(
    is_array( $spam_effects['spam'] ?? null ) ? $spam_effects['spam'] : [],
    [
        'enabled'                 => true,
        'skip_downstream_on_spam' => true,
    ]
);

$spam_mapping_id = $mappings->create(
    [
        'form_source'         => 'gravity_forms',
        'form_id'             => $form_id,
        'hook'                => 'after_submission',
        'action_kind'         => 'custom_action',
        'action_id'           => $created['spam']['action_id'],
        'input_bindings_json' => [],
        'effect_mapping_json' => $spam_effects,
        'execution_mode'      => 'sync',
        'settings_json'       => [
            'input_mapping' => [ 'mode' => 'all', 'field_ids' => [], 'include_metadata' => true ],
        ],
        'enabled'             => true,
    ]
);
if ( is_wp_error( $spam_mapping_id ) ) {
    echo wp_json_encode([ 'error' => $spam_mapping_id->get_error_message() ]);
    return;
}

$spam_runtime_key = 'local_first_' . (int) $spam_mapping_id;
$summary_mapping_id = $mappings->create(
    [
        'form_source'         => 'gravity_forms',
        'form_id'             => $form_id,
        'hook'                => 'after_submission',
        'action_kind'         => 'custom_action',
        'action_id'           => $created['summary']['action_id'],
        'input_bindings_json' => [],
        'effect_mapping_json' => is_array( $created['summary']['definition']['effect_mapping_json'] ?? null )
            ? $created['summary']['definition']['effect_mapping_json']
            : [ 'store_result' => true ],
        'execution_mode'      => 'sync',
        'settings_json'       => [
            'input_mapping'  => [ 'mode' => 'all', 'field_ids' => [], 'include_metadata' => true ],
            'dependency_ids' => [ $spam_runtime_key ],
            'trigger_sources' => [
                'after_submission' => [
                    'type'       => 'mapping',
                    'mapping_id' => $spam_runtime_key,
                ],
            ],
        ],
        'enabled'             => true,
    ]
);
if ( is_wp_error( $summary_mapping_id ) ) {
    echo wp_json_encode([ 'error' => $summary_mapping_id->get_error_message() ]);
    return;
}

echo wp_json_encode(
    [
        'spam_mapping_id'    => (int) $spam_mapping_id,
        'summary_mapping_id' => (int) $summary_mapping_id,
    ]
);
`,
		{
			FORM_ID: String(formId),
			CREDENTIAL_ID: String(credentialId)
		}
	);

	const parsed = parseLocalSpamSummaryChainSeed(output);
	if ('error' in parsed) {
		throw new Error(`Failed to seed local spam-summary chain: ${parsed.error}`);
	}

	return parsed satisfies LocalSpamSummaryChainSeed;
}

function seedImportedEntrySummaryMappingWithoutCredential(
	formId: number,
	executionMode: 'sync' | 'async',
	credentialId: number
): LocalCustomActionSeed {
	const output = runWpEval(
		`
if ( ! class_exists( 'Sentient_Forms_Installer' ) ) {
    echo wp_json_encode([ 'error' => 'sentient_forms_not_loaded' ]);
    return;
}

Sentient_Forms_Installer::maybe_upgrade();

global $wpdb;
$form_id        = (string) absint( getenv( 'FORM_ID' ) ?: 0 );
$execution_mode = sanitize_key( getenv( 'EXECUTION_MODE' ) ?: 'sync' );
$credential_id  = absint( getenv( 'CREDENTIAL_ID' ) ?: 0 );
$definition     = Sentient_Forms_Bundled_Action_Templates::get( 'entry_summary_v1' );

if ( ! is_array( $definition ) || '' === $form_id || '0' === $form_id || $credential_id <= 0 ) {
    echo wp_json_encode([ 'error' => 'invalid_seed_input' ]);
    return;
}

$templates      = new Sentient_Forms_Action_Templates_Repository( $wpdb );
$custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
$mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
$template_id    = $templates->upsert_by_code(
    [
        'source'                   => 'bundled',
        'code'                     => 'entry_summary_v1',
        'display_name'             => $definition['display_name'] ?? 'Entry Summary',
        'description'              => $definition['description'] ?? null,
        'prompt_template'          => $definition['prompt_template'] ?? '',
        'default_model'            => $definition['default_model'] ?? 'openrouter/auto',
        'structured_output_schema' => $definition['structured_output_schema'] ?? null,
        'override_schema'          => $definition['override_schema'] ?? null,
        'version'                  => $definition['version'] ?? '1',
        'is_active'                => true,
    ]
);

if ( is_wp_error( $template_id ) ) {
    echo wp_json_encode([ 'error' => $template_id->get_error_message() ]);
    return;
}

$action_id = $custom_actions->upsert_by_code(
    [
        'template_id'          => $template_id,
        'code'                 => 'imported_entry_summary_v1_5cfa445eee8f',
        'display_name'         => 'Entry Summary Import',
        'definition_json'      => array_merge(
            $definition['definition_json'] ?? [],
            [
                'template_code' => 'entry_summary_v1',
            ]
        ),
        'model_selection_json' => [
            'provider' => 'openrouter',
            'model'    => 'gemini-3-flash-preview',
        ],
        'status'               => 'active',
    ]
);

if ( is_wp_error( $action_id ) ) {
    echo wp_json_encode([ 'error' => $action_id->get_error_message() ]);
    return;
}

$mapping_id = $mappings->create(
    [
        'form_source'         => 'gravity_forms',
        'form_id'             => $form_id,
        'hook'                => 'gform_after_submission',
        'action_kind'         => 'custom_action',
        'action_id'           => $action_id,
        'input_bindings_json' => [
            'name'     => '1',
            'email'    => '2',
            'comments' => '3',
        ],
        'effect_mapping_json' => $definition['effect_mapping_json'] ?? [ 'store_result' => true ],
        'execution_mode'      => in_array( $execution_mode, [ 'sync', 'async' ], true ) ? $execution_mode : 'sync',
        'settings_json'       => [
            'model_selection' => [
                'provider'      => 'openrouter',
                'primary'       => 'openrouter/auto',
                'credential_id' => $credential_id,
            ],
        ],
        'enabled'             => true,
    ]
);

if ( is_wp_error( $mapping_id ) ) {
    echo wp_json_encode([ 'error' => $mapping_id->get_error_message() ]);
    return;
}

$action = $custom_actions->get( (int) $action_id );
echo wp_json_encode(
    [
        'action_id'            => (int) $action_id,
        'mapping_id'           => (int) $mapping_id,
        'model_selection_json' => is_array( $action['model_selection_json'] ?? null ) ? $action['model_selection_json'] : [],
    ]
);
`,
		{
			FORM_ID: String(formId),
			EXECUTION_MODE: executionMode,
			CREDENTIAL_ID: String(credentialId)
		}
	);

	const parsed = JSON.parse(output) as Partial<LocalCustomActionSeed> & { error?: string };
	if (parsed.error) {
		throw new Error(`Failed to seed imported Entry Summary mapping: ${parsed.error}`);
	}

	if (!parsed.action_id || !parsed.mapping_id || !parsed.model_selection_json) {
		throw new Error(`Imported Entry Summary seed returned an invalid payload: ${output}`);
	}

	return {
		action_id: parsed.action_id,
		mapping_id: parsed.mapping_id,
		model_selection_json: parsed.model_selection_json
	};
}

function getLocalCustomActionModelSelection(code: string): Record<string, unknown> {
	const output = runWpEval(
		`
global $wpdb;
$code           = sanitize_key( getenv( 'ACTION_CODE' ) ?: '' );
$custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
$action         = $custom_actions->get_by_code( $code );

echo wp_json_encode(
    [
        'model_selection_json' => is_array( $action['model_selection_json'] ?? null ) ? $action['model_selection_json'] : [],
    ]
);
`,
		{
			ACTION_CODE: code
		}
	);

	const parsed = JSON.parse(output) as { model_selection_json?: Record<string, unknown> };
	return parsed.model_selection_json ?? {};
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
			.getByTestId('local-builder-prompt-template')
			.fill(
				'Submission: {{entry}}\n\nReturn JSON shaped as {"classification":"ham|likely_spam|spam","confidence":0.0,"justification":"short reason"}.'
			);
		await drawer
			.getByTestId('local-builder-execution-mode')
			.selectOption(localOpenRouterBrowserExecutionMode);
		const submitButton = drawer.getByTestId('link-action-submit');
		await expect(submitButton).toContainText('Create Direct OpenRouter action');
		await submitButton.click();

		await expect(drawer.getByTestId('local-builder-result')).toContainText('Action #');
		await drawer.getByRole('button', { name: 'Cancel' }).click();
		await expect(drawer).toBeHidden();

		const tableToggle = page.getByTestId('linked-actions-view-table');
		if ((await tableToggle.count()) > 0) {
			await tableToggle.first().click();
		}
		const table = page.getByTestId('form-actions-table');
		await expect(table).toBeVisible();
		await table.locator('tbody tr').first().getByRole('button', { name: 'Configure' }).click();

		const modal = page.getByTestId('mapping-config-modal');
		await expect(modal.getByText('Configure Action Mapping')).toBeVisible();
		await modal.getByTestId('mapping-section-toggle-input_mapping').click();
		const inputSection = modal.locator('#mapping-section-content-input-mapping');
		await inputSection.locator('select').selectOption('selected');
		await inputSection.locator('label').filter({ hasText: 'Email' }).getByRole('checkbox').check();
		await inputSection
			.locator('label')
			.filter({ hasText: 'Include form metadata' })
			.getByRole('checkbox')
			.uncheck();
		await modal.locator('footer').getByRole('button', { name: 'Save mapping' }).click();
		await expect(modal).toBeHidden({ timeout: 15_000 });

		const mappings = getLocalFormMappings(formId);
		const expectedHook =
			localOpenRouterBrowserExecutionMode === 'sync' ? 'validation' : 'after_submission';
		expect(mappings).toHaveLength(1);
		expect(mappings[0]).toMatchObject({
			form_source: 'gravity_forms',
			form_id: String(formId),
			hook: expectedHook,
			action_kind: 'custom_action',
			execution_mode: localOpenRouterBrowserExecutionMode,
			enabled: true
		});
		expect(mappings[0]?.input_bindings_json).toMatchObject({
			mode: 'selected',
			field_ids: ['2'],
			include_metadata: false
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

		const requests = getLocalOpenRouterSmokeRequests();
		expect(requests.length).toBeGreaterThan(0);
		const requestMessages = JSON.stringify(requests.at(-1)?.body?.messages ?? []);
		expect(requestMessages).toContain(email);
		expect(requestMessages).not.toContain('Browser Local Lead');
		expect(requestMessages).not.toContain(
			'Claim your free prize now and reply with payment details immediately.'
		);
		expect(requestMessages).not.toContain(localOpenRouterBrowserSmokeFormTitle);
	});

	test('records a canonical dependent Action as skipped after upstream spam without a second provider call', async function ({
		page
	}) {
		await requireWpRestHealthy(page);

		const token = String(Date.now());
		const formId = ensureGravityForm('Local spam-summary chain', [
			{ type: 'text', id: 1, label: 'Name', isRequired: true },
			{ type: 'email', id: 2, label: 'Email', isRequired: true },
			{ type: 'textarea', id: 3, label: 'Message', isRequired: true }
		]);
		resetLocalFormFixture({
			formId,
			actionNames: ['Browser chain spam', 'Browser chain summary']
		});
		const formUrl = ensureGravityFormPage(formId, 'Local spam-summary chain page');
		const provider = seedLocalOpenRouterProvider('Browser chain OpenRouter key', {
			exclusive: true
		});
		const chain = seedLocalSpamSummaryChain(formId, provider.credential_id);
		setLocalOpenRouterMockResponse({
			classification: 'spam',
			confidence: 0.99,
			justification: 'Browser chain upstream spam result.',
			indicators: []
		});

		await loginToWpAdmin(page);
		const email = `browser-chain-${token}@example.test`;
		const baselineEntryId = getLatestEntryId(formId);
		await submitFrontEndGravityForm(page, formUrl, formId, {
			name: 'Browser Chain Lead',
			email,
			message: 'This message should be classified as spam before summary generation.'
		});
		const entryId = await waitForEntryId(page, formId, baselineEntryId, email);

		let events: LocalExecutionEvent[] = [];
		await expect
			.poll(function () {
				events = getLocalExecutionEvents(entryId);
				return events
					.filter(
						(event) =>
							event.mapping_id === chain.spam_mapping_id ||
							event.mapping_id === chain.summary_mapping_id
					)
					.map((event) => `${event.mapping_id}:${event.status}`)
					.sort();
			})
			.toEqual(
				[`${chain.spam_mapping_id}:succeeded`, `${chain.summary_mapping_id}:skipped`].sort()
			);

		const skippedEvent = events.find(
			(event) => event.mapping_id === chain.summary_mapping_id && event.status === 'skipped'
		);
		expect(skippedEvent?.result_json?.skip_reason).toBe('upstream_spam');
		expect(skippedEvent?.result_json?.native_effect_outcomes).toContainEqual({
			effect: 'workflow_execution',
			status: 'skipped',
			reason: 'upstream_spam'
		});
		expect(getLocalOpenRouterSmokeRequests()).toHaveLength(1);
		await expect(waitForSpamStatus(page, entryId)).resolves.toMatchObject({
			status: 'spam',
			classification: 'spam',
			is_spam: true
		});
		await waitForGravityEntryNotes(
			entryId,
			page,
			(notes) => notes.some((note) => note.value.includes('Browser chain upstream spam result.')),
			{ description: 'the upstream spam chain note' }
		);

		await ensureSentientFormsSpa(page, '/actions/log');
		await expect(page.getByRole('heading', { name: 'Action Log' })).toBeVisible();
		const skippedRow = page.getByTestId(`action-log-row-local-event-${skippedEvent?.id ?? 0}`);
		await expect(skippedRow).toContainText('Browser chain summary');
		await expect(skippedRow).toContainText('Blocked');
		await expect(skippedRow).toContainText('Not run');
		await expect(skippedRow).toContainText(
			'Skipped because an upstream Action classified the submission as spam.'
		);
	});

	test('runs an imported bundled Entry Summary through a mapping-scoped Direct route', async function ({
		page
	}) {
		await requireWpRestHealthy(page);

		const token = `imported-summary-${Date.now()}`;
		const formId = ensureGravityForm('Local OpenRouter Imported Entry Summary', [
			{ type: 'text', id: 1, label: 'Name', isRequired: true },
			{ type: 'email', id: 2, label: 'Email', isRequired: true },
			{ type: 'textarea', id: 3, label: 'Comments', isRequired: true }
		]);
		resetLocalFormFixture({
			formId,
			actionCodes: ['imported_entry_summary_v1_5cfa445eee8f']
		});
		expect(getLocalFormMappings(formId)).toEqual([]);

		const seed = seedLocalOpenRouterProvider('Imported Entry Summary OpenRouter key', {
			exclusive: true
		});
		const imported = seedImportedEntrySummaryMappingWithoutCredential(
			formId,
			localOpenRouterBrowserExecutionMode,
			seed.credential_id
		);
		expect(imported.model_selection_json).toMatchObject({
			provider: 'openrouter',
			model: 'gemini-3-flash-preview'
		});
		expect(imported.model_selection_json.credential_id).toBeUndefined();

		setLocalOpenRouterMockResponse({
			summary: 'Imported built-in entry summary completed from a real browser submission.'
		});

		const formUrl = ensureGravityFormPage(formId, 'Local OpenRouter Imported Entry Summary Page');
		const email = `${token}@example.test`;
		const baselineEntryId = getLatestEntryId(formId);

		await submitFrontEndGravityForm(page, formUrl, formId, {
			name: 'Imported Summary Lead',
			email,
			message:
				'Please quote product number 183671 at 500 pieces. Also, this message mentions suspicious viagra sales copy.'
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

		const event = getLatestLocalExecutionEvent(entryId);
		expect(event).toMatchObject({
			entry_id: String(entryId),
			provider: 'openrouter',
			model: 'openrouter/auto',
			status: 'succeeded'
		});

		const repairedSelection = getLocalCustomActionModelSelection(
			'imported_entry_summary_v1_5cfa445eee8f'
		);
		expect(repairedSelection).toMatchObject({
			provider: 'sentient_managed',
			backup_provider: 'openrouter',
			backup_model: 'openrouter/auto',
			backup_credential_id: seed.credential_id
		});

		const summary = getEntryMeta(entryId, 'sentient_forms_summary');
		const summaryText = typeof summary === 'string' ? summary : JSON.stringify(summary);
		expect(summaryText).toContain('Imported built-in entry summary completed');

		const notes = await waitForGravityEntryNotes(
			entryId,
			page,
			(entryNotes) =>
				entryNotes.every((note) => !note.value.includes('Provider credential could not be found')),
			{
				description: 'absence of provider credential failure notes',
				runScheduler: localOpenRouterBrowserExecutionMode === 'async'
			}
		);
		expect(
			notes.some((note) => note.value.includes('Provider credential could not be found'))
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

		await expect(drawer.getByTestId('local-builder-result')).toContainText('Action #');
		const mappings = getLocalFormMappings(formId);
		const expectedHook =
			localOpenRouterBrowserExecutionMode === 'sync' ? 'validation' : 'after_submission';
		expect(mappings).toHaveLength(1);
		expect(mappings[0]).toMatchObject({
			form_source: 'gravity_forms',
			form_id: String(formId),
			hook: expectedHook,
			action_kind: 'custom_action',
			execution_mode: localOpenRouterBrowserExecutionMode,
			enabled: true
		});

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
