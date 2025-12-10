import { expect, test, type Page } from '@playwright/test';
import { spawnSync, type SpawnSyncReturns } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loginToWpAdmin, wpBaseUrl } from './wp-admin';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const repoRoot = path.resolve(__dirname, '../../../../..');
const composeArgs = ['compose', '-f', 'docker-compose.yml'];

type DockerEnv = NodeJS.ProcessEnv;

export type EntrySpamStatus = {
	status: string;
	is_spam: boolean | null;
	classification: string | null;
};

export type EntryEvaluation = {
	summary?: string;
	key_points?: unknown;
	sentiment?: string;
	suggested_tags?: unknown;
	suggested_response?: string;
	raw?: unknown;
};

export async function requireWpRestHealthy(page: Page): Promise<void> {
	const res = await page.request.get('http://localhost:8080/index.php?rest_route=/', {
		timeout: 5000
	});
	if (!res.ok()) {
		test.skip(`WP REST unavailable (${res.status()})`);
	}
}


export async function waitForPreviewInputs(page: Page, formId: number, reloadOnce = true): Promise<void> {
	const isVisible = async () =>
		page.locator('input[name="input_1"]').isVisible({ timeout: 2000 }).catch(() => false);
    if (await isVisible()) return;
    if (reloadOnce) {
        await page.reload({ waitUntil: 'domcontentloaded' });
        if (await isVisible()) return;
    }
    throw new Error(`Gravity Forms preview inputs not visible for form ${formId}`);
}

type ActionMappingArgs = {
	formId: number;
	actionId: string;
	centralActionId: string;
	actionNameLabel?: string;
	hooks: string[];
	async?: boolean;
	rejectSubmission?: boolean;
	markAsSpam?: boolean;
	executionPriority?: number;
	actionTypeIndicator?: 'master' | 'custom';
	actionTemplateId?: string;
	localMappingId?: string;
};

type GravityField = {
	type: string;
	id: number;
	label: string;
	isRequired?: boolean;
};

type CreditAdjustmentArgs = {
	delta: number;
	licenseKey?: string;
	reason?: string;
	requestId?: string;
};

function stripCliNoise(output: string): string {
	return output
		.split(/\r?\n/)
		.map((line) => line.trim())
		.filter((line) => line && !line.startsWith('['))
		.join('\n');
}

function runDocker(args: string[], env: DockerEnv = {}): SpawnSyncReturns<string> {
	const envEntries = Object.entries(env ?? {});
	let finalArgs: string[] = [...composeArgs, ...args];

	if (args[0] === 'exec' && envEntries.length > 0) {
		const optionArgs: string[] = [];
		let cursor = 1;
		while (cursor < args.length && args[cursor]?.startsWith('-')) {
			optionArgs.push(args[cursor]);
			cursor += 1;
		}

		const envArgs = envEntries.flatMap(([key, value]) => ['-e', `${key}=${value ?? ''}`]);
		finalArgs = [...composeArgs, 'exec', ...optionArgs, ...envArgs, ...args.slice(cursor)];
	}

	const result = spawnSync('docker', finalArgs, {
		cwd: repoRoot,
		env: { ...process.env, ...env },
		encoding: 'utf-8'
	});

	if (result.error) {
		throw result.error;
	}

	return result as SpawnSyncReturns<string>;
}

function runWpCli(args: string[], env: DockerEnv = {}): SpawnSyncReturns<string> {
	return runDocker(['exec', '-T', 'wordpress', 'wp', '--url=http://localhost:8080', ...args], env);
}

function runWpEval(phpScript: string, env: DockerEnv = {}): string {
	const result = runWpCli(['eval', phpScript], env);
	if (result.status !== 0) {
		const message = stripCliNoise(result.stderr || result.stdout);
		throw new Error(`wp eval failed: ${message}`);
	}

	return stripCliNoise(result.stdout);
}

export function setExecutionRequestIdOverride(executionRequestId: string | null): void {
	const php = executionRequestId
		? `update_option( 'sentient_forms_forced_execution_request_id', '${executionRequestId}', false ); echo 'ok';`
		: `delete_option( 'sentient_forms_forced_execution_request_id' ); echo 'ok';`;
	const output = runWpEval(php);
	if (!output.includes('ok')) {
		throw new Error(`Failed to set execution_request_id override: ${output}`);
	}
}

function runDbQuery(sql: string): string {
	const result = runDocker(
		['exec', '-T', 'cps-db', 'psql', '-U', 'postgres', '-d', 'sentient_forms', '-t', '-A', '-c', sql]
	);

	if (result.status !== 0) {
		const message = stripCliNoise(result.stderr || result.stdout);
		throw new Error(`psql failed: ${message}`);
	}

	return stripCliNoise(result.stdout);
}

function runTelemetryDbQuery(sql: string): string {
	const result = runDocker(
		[
			'exec',
			'-T',
			'telemetry-db',
			'psql',
			'-U',
			'postgres',
			'-d',
			process.env.TELEMETRY_DB_NAME ?? 'sentientforms_telemetry',
			'-t',
			'-A',
			'-c',
			sql
		]
	);

	if (result.status !== 0) {
		const message = stripCliNoise(result.stderr || result.stdout);
		throw new Error(`telemetry psql failed: ${message}`);
	}

	return stripCliNoise(result.stdout);
}

export function isTelemetryDbHealthy(): boolean {
	try {
		const output = runTelemetryDbQuery('SELECT 1;');
		return output.trim() === '1';
	} catch (_error) {
		return false;
	}
}

function sanitizeSqlLiteral(value: string): string {
	return value.replace(/'/g, "''");
}

export function getLicenseIdByKey(licenseKey = 'LIC-LOCAL-DEV'): string {
	const raw = runDbQuery(
		`SELECT id FROM licenses WHERE license_key='${sanitizeSqlLiteral(licenseKey)}' LIMIT 1;`
	);
	if (!raw) throw new Error(`license not found for ${licenseKey}`);
	return raw.trim();
}

export function getActionTemplateIdByCode(code: string): string {
	const raw = runDbQuery(
		`SELECT id FROM action_templates WHERE code='${sanitizeSqlLiteral(code)}' LIMIT 1;`
	);
	if (!raw) throw new Error(`action template not found for code=${code}`);
	return raw.trim();
}

export function setTelemetryOptIn(optIn: boolean, licenseKey = 'LIC-LOCAL-DEV'): void {
	const licenseId = getLicenseIdByKey(licenseKey);
	const sql = `
UPDATE sites SET telemetry_opt_in = ${optIn ? 'TRUE' : 'FALSE'}
WHERE license_id = '${sanitizeSqlLiteral(licenseId)}';
`;
	runDbQuery(sql);
}

export function getLatestTelemetryEvent(
	licenseKey = 'LIC-LOCAL-DEV'
): { event: string; payload: unknown } | null {
	const licenseId = getLicenseIdByKey(licenseKey);
	const raw = runTelemetryDbQuery(
		`
SELECT json_build_object('event', event, 'payload', payload)::text
FROM async_telemetry_events
WHERE license_id='${sanitizeSqlLiteral(licenseId)}'
ORDER BY ingested_at DESC
LIMIT 1;
`
	);

	if (!raw) return null;

	try {
		return JSON.parse(raw.trim()) as { event: string; payload: unknown };
	} catch (_error) {
		throw new Error(`Failed to parse telemetry event: ${raw}`);
	}
}

export function createCustomAction(code: string, displayName: string, baseCreditCost: number): string {
	const licenseId = getLicenseIdByKey();
	const templateId = getActionTemplateIdByCode('spam_detection_v1');
	const sql = `
INSERT INTO custom_actions (license_id, template_id, display_name, description, configuration, prompt_overrides, model_hint, base_credit_cost, code, status)
VALUES ('${licenseId}', '${templateId}', '${sanitizeSqlLiteral(displayName)}', 'Playwright custom action', '{}'::jsonb, '{}'::jsonb, 'gemini-pro', ${baseCreditCost}, '${sanitizeSqlLiteral(code)}', 'active')
ON CONFLICT (license_id, code) DO UPDATE SET updated_at = now()
RETURNING code;
`;
	const raw = runDbQuery(sql);
	return raw.trim();
}

export function ensureGravityForm(title: string, fields?: GravityField[]): number {
	const payload = {
		title,
		fields:
			fields ??
			([
				{ type: 'text', id: 1, label: 'Name', isRequired: true },
				{ type: 'email', id: 2, label: 'Email', isRequired: true }
			] as GravityField[])
	};

	const result = runWpCli(
		[
			'eval',
			`
$payload = json_decode( getenv( 'FORM_DATA' ) ?: '{}' , true );
if ( ! is_array( $payload ) || empty( $payload['title'] ) ) {
    echo "0";
    return;
}
$title  = $payload['title'];
$fields = $payload['fields'] ?? [];
$forms  = GFAPI::get_forms();
foreach ( $forms as $form ) {
    if ( isset( $form['title'] ) && $form['title'] === $title ) {
        echo (int) $form['id'];
        return;
    }
}
$form = [
    'title'  => $title,
    'fields' => [],
    'button' => [ 'type' => 'text', 'text' => 'Submit' ],
];
foreach ( $fields as $field ) {
    $form['fields'][] = [
        'type'       => $field['type'] ?? 'text',
        'id'         => (int) ( $field['id'] ?? 0 ),
        'label'      => $field['label'] ?? '',
        'isRequired' => ! empty( $field['isRequired'] ),
    ];
}
$form_id = GFAPI::add_form( $form );
if ( is_wp_error( $form_id ) ) {
    echo "0";
    return;
}
echo (int) $form_id;
`
		],
		{
			FORM_DATA: JSON.stringify(payload)
		}
	);

	if (result.status !== 0) {
		throw new Error(`Failed to ensure Gravity Form: ${stripCliNoise(result.stderr)}`);
	}

	const formId = Number.parseInt(stripCliNoise(result.stdout), 10);
	if (!Number.isInteger(formId) || formId <= 0) {
		throw new Error(`Unexpected form id output: ${result.stdout}`);
	}

	return formId;
}

export function configureGravityActionMapping(args: ActionMappingArgs): void {
	const settings = {
		enabled: true,
		actions: {
			[args.actionId]: {
				enabled: true,
				is_action_enabled_for_form: true,
				central_action_id: args.centralActionId,
				action_name_label: args.actionNameLabel ?? args.centralActionId,
				hooks: args.hooks,
				async: args.async ?? false,
				reject_submission: args.rejectSubmission ?? false,
				mark_as_spam: args.markAsSpam ?? false,
				execution_priority: args.executionPriority ?? 10,
				action_type_indicator: args.actionTypeIndicator ?? 'master',
				action_template_id: args.actionTemplateId,
				local_mapping_id: args.localMappingId ?? `map_${args.actionId}`
			}
		}
	};

	const result = runWpCli(
		[
			'eval',
			`
$form_id = (int) getenv( 'FORM_ID' );
$json    = getenv( 'SETTINGS_JSON' ) ?: '{}';
$data    = json_decode( $json, true );
if ( empty( $form_id ) || ! is_array( $data ) ) {
    echo '0';
    return;
}
$option_name = sprintf( 'sentient_forms_actions_gravity_forms_%d', $form_id );
$settings    = array_merge(
    [
        'enabled' => true,
        'actions' => [],
    ],
    $data
);
update_option( $option_name, $settings, false );
echo 'ok';
`
		],
		{
			FORM_ID: String(args.formId),
			SETTINGS_JSON: JSON.stringify(settings)
		}
	);

	if (result.status !== 0 || !stripCliNoise(result.stdout).includes('ok')) {
		throw new Error(`Failed to configure action mapping: ${stripCliNoise(result.stderr || result.stdout)}`);
	}
}

export function runActionScheduler(): void {
	const result = runWpCli(['action-scheduler', 'run']);
	if (result.status !== 0) {
		throw new Error(`action-scheduler run failed: ${stripCliNoise(result.stderr || result.stdout)}`);
	}
}

export function getProxyApiKey(): string {
	const result = runWpCli(['option', 'get', 'sentient_forms_settings', '--format=json']);

	let payload = stripCliNoise(result.stdout);
	if (!payload && result.stderr) {
		payload = stripCliNoise(result.stderr);
	}

	if (!payload) {
		throw new Error('Proxy API key lookup returned empty payload');
	}

	try {
		const settings = JSON.parse(payload) as Record<string, unknown>;
		const proxyKey =
			(settings?.license as Record<string, unknown> | undefined)?.['proxy_api_key'] ??
			(settings as Record<string, unknown> | undefined)?.['proxy_api_key'] ??
			'';
		if (typeof proxyKey === 'string' && proxyKey.length > 0) {
			return proxyKey;
		}
	} catch (_error) {
		// Continue to regex fallback
		const message = (error as Error)?.message ?? 'unknown';
		throw new Error(`Failed to parse sentient_forms_settings JSON: ${message}; payload=${payload.slice(0, 200)}`);
	}

	const match = payload.match(/"proxy_api_key":"([^"]+)"/);
	if (match?.[1]) {
		return match[1];
	}

	throw new Error('Proxy API key not found in sentient_forms_settings.');
}

export function getLatestEntryId(formId: number): number {
	const result = runWpCli(
		[
			'eval',
			`
$form_id = (int) getenv( 'FORM_ID' );
global $wpdb;
$entry_table = $wpdb->prefix . 'gf_entry';
$entry_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM {$entry_table} WHERE form_id = %d", $form_id ) );
echo $entry_id ?: 0;
`
		],
		{
			FORM_ID: String(formId)
		}
	);

	if (result.status !== 0) {
		throw new Error(`getLatestEntryId failed for form ${formId}: ${stripCliNoise(result.stderr || result.stdout)}`);
	}

	return Number.parseInt(stripCliNoise(result.stdout), 10) || 0;
}

export function findEntryIdByEmail(formId: number, email: string, emailField = '2'): number {
	const result = runWpCli(
		[
			'eval',
			`
$form_id     = (int) getenv( 'FORM_ID' );
$email       = getenv( 'EMAIL' ) ?: '';
$email_field = getenv( 'EMAIL_FIELD' ) ?: '2';
$statuses    = [ 'active', 'spam', 'trash' ];

global $wpdb;
$meta_table  = $wpdb->prefix . 'gf_entry_meta';
$entry_table = $wpdb->prefix . 'gf_entry';
$sql = $wpdb->prepare(
    "SELECT em.entry_id FROM {$meta_table} em JOIN {$entry_table} e ON e.id = em.entry_id WHERE e.form_id = %d AND em.meta_key = %s AND em.meta_value = %s ORDER BY em.entry_id DESC LIMIT 1",
    $form_id,
    $email_field,
    $email
);
$direct_entry_id = (int) $wpdb->get_var( $sql );
if ( $direct_entry_id > 0 ) {
    echo $direct_entry_id;
    return;
}

$form_ids     = [ $form_id ];
$base_filters = [
    'mode' => 'all',
    [ 'key' => $email_field, 'value' => $email, 'operator' => 'is' ],
];
$sorting = [ 'key' => 'id', 'direction' => 'DESC' ];
$paging  = [ 'offset' => 0, 'page_size' => 1 ];

foreach ( $statuses as $status ) {
    $criteria = [
        'status'         => $status,
        'field_filters'  => $base_filters,
    ];
    $entries = GFAPI::get_entries( $form_ids, $criteria, $sorting, $paging );
    if ( is_wp_error( $entries ) ) {
        fwrite( STDERR, $entries->get_error_message() );
        continue;
    }
    if ( ! empty( $entries ) ) {
        echo (int) $entries[0]['id'];
        return;
    }
}
echo "0";
`
		],
		{
			FORM_ID: String(formId),
			EMAIL: email,
			EMAIL_FIELD: emailField
		}
	);

	if (result.status !== 0) {
		throw new Error(`findEntryIdByEmail failed: ${stripCliNoise(result.stderr || result.stdout)}`);
	}

	const parsed = Number.parseInt(stripCliNoise(result.stdout), 10);
	if (!Number.isInteger(parsed)) {
		throw new Error(`Failed to parse entry id for ${email}: ${stripCliNoise(result.stdout)}`);
	}

	return parsed;
}

export function getEntrySpamStatus(entryId: number): EntrySpamStatus {
	const payload = runWpEval(
		`
$entry_id = (int) getenv( 'ENTRY_ID' );
$entry = GFAPI::get_entry( $entry_id );
if ( is_wp_error( $entry ) ) {
    echo wp_json_encode([ 'status' => 'error', 'is_spam' => null, 'classification' => null ]);
    return;
}
$meta = function_exists( 'gform_get_meta' ) ? gform_get_meta( $entry_id, '_sentient_forms_spam_analysis', true ) : null;
echo wp_json_encode([
    'status' => $entry['status'] ?? 'unknown',
    'is_spam' => is_array( $meta ) ? ! empty( $meta['is_spam'] ) : null,
    'classification' => is_array( $meta ) ? ( $meta['classification'] ?? null ) : null,
]);
`,
		{
			ENTRY_ID: String(entryId)
		}
	);

	try {
		return JSON.parse(payload) as EntrySpamStatus;
	} catch (_error) {
		throw new Error(`Failed to parse entry status for ${entryId}: ${payload}`);
	}
}

export function getEntryMeta(entryId: number, metaKey: string): unknown {
	const payload = runWpEval(
		`
$entry_id = (int) getenv( 'ENTRY_ID' );
$meta_key = getenv( 'META_KEY' ) ?: '';
$meta = function_exists( 'gform_get_meta' ) ? gform_get_meta( $entry_id, $meta_key, true ) : null;
if ( is_array( $meta ) || is_object( $meta ) ) {
    echo wp_json_encode( $meta );
    return;
}
if ( is_string( $meta ) ) {
    echo $meta;
    return;
}
echo '';
`,
		{
			ENTRY_ID: String(entryId),
			META_KEY: metaKey
		}
	);

	if (!payload) {
		return null;
	}

	try {
		return JSON.parse(payload);
	} catch (_error) {
		return payload;
	}
}

export function setFormStatusOption(formId: number, status: 'success' | 'error', message: string): void {
	const php = `
$option_key = sprintf( 'sentient_forms_form_status_gravity_forms_%d', (int) ${formId} );
$payload = [
    'status' => '${status}',
    'message' => '${message}',
    'updated_at' => time(),
];
update_option( $option_key, $payload, false );
echo 'ok';
`;
	const output = runWpEval(php);
	if (!output.includes('ok')) {
		throw new Error(`Failed to set form status option: ${output}`);
	}
}

export function getFormStatusOption(
	formId: number
): { status?: string; message?: string; updated_at?: number } | null {
	const php = `
$option_key = sprintf( 'sentient_forms_form_status_gravity_forms_%d', (int) ${formId} );
$value = get_option( $option_key, null );
if ( $value ) {
    echo wp_json_encode( $value );
} else {
    echo '';
}
`;
	const output = runWpEval(php);
	if (!output) return null;
	try {
		return JSON.parse(output);
	} catch (_error) {
		throw new Error(`Failed to parse form status option: ${output}`);
	}
}

export async function waitForEntryMeta(
	entryId: number,
	metaKey: string,
	page: Page,
	predicate: (value: unknown) => boolean,
	maxAttempts = 8
): Promise<unknown> {
	let lastValue: unknown = null;
	for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
		lastValue = getEntryMeta(entryId, metaKey);
		if (predicate(lastValue)) {
			return lastValue;
		}
		runActionScheduler();
		await page.waitForTimeout(1000);
	}
	throw new Error(`Meta ${metaKey} not satisfied after ${maxAttempts} attempts; lastValue=${JSON.stringify(lastValue)}`);
}

export async function submitGravityForm(page: Page, formId: number, name: string, email: string): Promise<void> {
	await loginToWpAdmin(page);
	await page.goto(`${wpBaseUrl}/?gf_page=preview&id=${formId}`, { waitUntil: 'domcontentloaded' });
	await waitForPreviewInputs(page, formId);
	await page.fill('input[name="input_1"]', name);
	await page.fill('input[name="input_2"]', email);
	await Promise.all([
		page.click('input[type="submit"], button[type="submit"]'),
		page.waitForSelector('.gform_confirmation_message, .gform_confirmation_wrapper', { timeout: 15000 })
	]);
}

export async function fetchCreditBalance(
	page: Page,
	apiKey: string,
	baseUrl = 'http://localhost:10081'
): Promise<number> {
	const response = await page.request.get(`${baseUrl}/v1/credits/balance`, {
		headers: { 'x-api-key': apiKey }
	});

	expect(response.ok()).toBeTruthy();
	const body = await response.json();
	return body?.data?.current_balance ?? NaN;
}

export function getCreditBalanceFromDb(licenseKey = 'LIC-LOCAL-DEV'): number {
	const sql = `
SELECT COALESCE(SUM(credits_delta), 0)
FROM credit_ledger_entries
WHERE license_id = (SELECT id FROM licenses WHERE license_key = '${sanitizeSqlLiteral(licenseKey)}' LIMIT 1);
`.trim();

	const raw = runDbQuery(sql);
	const parsed = Number.parseFloat(raw);
	return Number.isNaN(parsed) ? 0 : parsed;
}

export function getTierMonthlyQuota(licenseKey = 'LIC-LOCAL-DEV'): number {
	const sql = `
SELECT t.monthly_credit_quota
FROM licenses l
JOIN tiers t ON l.tier_id = t.id
WHERE l.license_key = '${sanitizeSqlLiteral(licenseKey)}'
LIMIT 1;
`.trim();

	const raw = runDbQuery(sql);
	const parsed = Number.parseInt(raw, 10);
	return Number.isNaN(parsed) ? 0 : parsed;
}

export function insertCreditDelta({
	delta,
	licenseKey = 'LIC-LOCAL-DEV',
	reason = 'playwright adjustment',
	requestId = `pw-${Date.now()}`
}: CreditAdjustmentArgs): number {
	if (!Number.isFinite(delta) || delta === 0) {
		return getCreditBalanceFromDb(licenseKey);
	}

	const sql = `
WITH target_license AS (
  SELECT id FROM licenses WHERE license_key = '${sanitizeSqlLiteral(licenseKey)}' LIMIT 1
), inserted AS (
  INSERT INTO credit_ledger_entries (
    license_id, site_id, action_template_id, credits_delta, reason, metadata, period_start, execution_request_id
  )
  SELECT id, NULL, NULL, ${delta}, '${sanitizeSqlLiteral(reason)}', '{}'::jsonb, current_date, '${sanitizeSqlLiteral(requestId)}'
  FROM target_license
  RETURNING license_id
)
SELECT COALESCE(SUM(credits_delta), 0)
FROM credit_ledger_entries
WHERE license_id = (SELECT id FROM target_license LIMIT 1);
`.trim();

	const raw = runDbQuery(sql);
	const parsed = Number.parseFloat(raw);
	return Number.isNaN(parsed) ? 0 : parsed;
}

export function ensureCreditBalanceAtLeast(minBalance: number, licenseKey = 'LIC-LOCAL-DEV'): number {
	const tierQuota = getTierMonthlyQuota(licenseKey);
	const currentLedger = getCreditBalanceFromDb(licenseKey);
	const currentTotal = tierQuota + currentLedger;
	if (currentTotal >= minBalance) {
		return currentTotal;
	}

	const newLedger = insertCreditDelta({
		delta: minBalance - currentTotal,
		licenseKey,
		reason: `top-up to ${minBalance}`,
		requestId: `pw-topup-${Date.now()}`
	});

	return tierQuota + newLedger;
}

export function setCreditBalance(targetBalance: number, licenseKey = 'LIC-LOCAL-DEV'): number {
	const tierQuota = getTierMonthlyQuota(licenseKey);
	const currentLedger = getCreditBalanceFromDb(licenseKey);
	const currentTotal = tierQuota + currentLedger;
	const delta = targetBalance - currentTotal;
	const newLedger = insertCreditDelta({
		delta,
		licenseKey,
		reason: `set balance to ${targetBalance}`,
		requestId: `pw-reset-${Date.now()}`
	});

	return tierQuota + newLedger;
}

export function resetE2eState(): void {
	// Standardize credits and clear deterministic overrides between suites.
	ensureCreditBalanceAtLeast(120);
	setExecutionRequestIdOverride(null);
}
