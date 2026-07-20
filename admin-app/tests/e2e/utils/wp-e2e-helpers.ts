import { expect, test, type Page } from '@playwright/test';
import { spawnSync, type SpawnSyncReturns } from 'node:child_process';
import { z } from 'zod';
import { parseLocalFormMappings, type LocalFormMappingRecord } from './local-runtime-schemas';
import { loginToWpAdmin, wpBaseUrl } from './wp-admin';

const dockerContainers: Record<string, string> = {
	wordpress: 'sentient_forms_wordpress'
};
const sourcePluginSlug = 'sentient-forms';
const packagePluginSlug = 'sentient-forms-wporg-check';

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

export type InputMapping = {
	mode: 'all' | 'selected' | 'exclude';
	fieldIds?: string[];
	includeMetadata?: boolean;
};

export type BatchSettings = {
	enabled?: boolean;
	delaySeconds?: number;
	maxWaitSeconds?: number;
};

export type RealtimeSettings = {
	checkpointFieldIds?: string[];
	refreshMode?: 'auto' | 'checkpoint' | 'manual';
	pageCheckpointsEnabled?: boolean;
	pageCheckpointMode?: 'all_pages' | 'include_pages' | 'exclude_pages';
	pageCheckpointPages?: number[];
	debounceMs?: number;
	cooldownMs?: number;
	manualRefreshEnabled?: boolean;
	storageTargetFieldId?: string;
	blockingMode?: 'advisory' | 'require_answers';
	initialPanelState?: 'open' | 'minimized' | 'hidden_until_interaction';
};

const realtimeSettingsSchema = z.object({
	checkpointFieldIds: z.array(z.string()).optional(),
	refreshMode: z.enum(['auto', 'checkpoint', 'manual']).optional(),
	pageCheckpointsEnabled: z.boolean().optional(),
	pageCheckpointMode: z.enum(['all_pages', 'include_pages', 'exclude_pages']).optional(),
	pageCheckpointPages: z.array(z.number().int()).optional(),
	debounceMs: z.number().optional(),
	cooldownMs: z.number().optional(),
	manualRefreshEnabled: z.boolean().optional(),
	storageTargetFieldId: z.string().optional(),
	blockingMode: z.enum(['advisory', 'require_answers']).optional(),
	initialPanelState: z.enum(['open', 'minimized', 'hidden_until_interaction']).optional()
});

function parseRealtimeSettings(
	settings: RealtimeSettings | undefined
): RealtimeSettings | undefined {
	if (!settings) return undefined;
	const result = realtimeSettingsSchema.safeParse(settings);
	if (!result.success) {
		throw new Error(
			`Invalid realtime settings: ${result.error.issues[0]?.message ?? 'unknown error'}`
		);
	}
	return result.data;
}

export type ExecutionMode = 'validation' | 'after_submission' | 'real_time';

export type CapturedMailRecord = {
	captured_at_gmt: string;
	preexisting_return: string | null;
	to: string[];
	subject: string;
	message: string;
	headers: string[];
	attachments: string[];
};

export type GravityEntryNoteRecord = {
	entry_id: number;
	id: number;
	user_id: number | null;
	user_name: string | null;
	user_email: string | null;
	date_created: string;
	value: string;
	note_type: string | null;
	sub_type: string | null;
};

export type GravityFormFieldRecord = {
	id: string;
	type: string;
	label: string;
	adminLabel: string;
	inputName: string;
	cssClass: string;
};

function currentWpPluginMode(): 'source' | 'package' {
	return process.env.SENTIENT_WP_PLUGIN_MODE === 'package' ? 'package' : 'source';
}

export async function requireWpRestHealthy(page: Page): Promise<void> {
	ensureWpBaseUrlConfigured();
	ensureExpectedPluginMode();
	const res = await page.request.get(`${wpBaseUrl}/index.php?rest_route=/`, {
		timeout: 5000
	});
	if (!res.ok()) {
		test.skip(true, `WP REST unavailable (${res.status()})`);
	}
}

export async function waitForPreviewInputs(
	page: Page,
	formId: number,
	reloadOnce = true
): Promise<void> {
	const isVisible = async () =>
		page
			.locator('input[name="input_1"]')
			.isVisible({ timeout: 2000 })
			.catch(() => false);
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
	dependencyIds?: string[];
	triggerSources?: Record<string, { type: 'hook_root' | 'mapping'; mapping_id?: string }>;
	skipOnUpstreamSpam?: boolean;
	inputMapping?: InputMapping;
	batchSettings?: BatchSettings;
	executionMode?: ExecutionMode;
	realtimeSettings?: RealtimeSettings;
	additionalSettings?: Record<string, unknown>;
	mergeWithExistingMappings?: boolean;
};

type GravityField = {
	type: string;
	id: number;
	label: string;
	isRequired?: boolean;
};

export type GravityFormNotificationConfig = {
	id?: string;
	name: string;
	to: string;
	subject: string;
	message: string;
	event?: string;
	from?: string;
	fromName?: string;
	messageFormat?: 'html' | 'text' | 'multipart';
	service?: string;
	toType?: 'email' | 'field' | 'routing';
	toField?: string;
};

type GravityFormOptions = {
	notifications?: GravityFormNotificationConfig[];
};

export type GravityActionSettingsRecord = Record<string, unknown> & {
	enabled?: boolean;
	actions?: Record<string, Record<string, unknown>>;
};

export type { LocalFormMappingRecord } from './local-runtime-schemas';

export type ResetLocalFormFixtureArgs = {
	formId: number;
	actionCodes?: string[];
	actionNames?: string[];
};

function stripCliNoise(output: string): string {
	return output
		.split(/\r?\n/)
		.map((line) => line.trim())
		.filter(
			(line) =>
				line && !/^\[\d{2}-[A-Za-z]{3}-\d{4}\s+\d{2}:\d{2}:\d{2}\s+UTC\]/.test(line)
		)
		.join('\n');
}

function runDocker(args: string[], env: DockerEnv = {}): SpawnSyncReturns<string> {
	if (args[0] !== 'exec') {
		throw new Error(`Unsupported docker helper invocation: ${args.join(' ')}`);
	}

	const envEntries = Object.entries(env ?? {});
	const optionArgs: string[] = [];
	let cursor = 1;
	while (cursor < args.length && args[cursor]?.startsWith('-')) {
		if (args[cursor] !== '-T') {
			optionArgs.push(args[cursor]);
		}
		cursor += 1;
	}

	const serviceName = args[cursor];
	if (!serviceName) {
		throw new Error(`Missing docker exec target in helper invocation: ${args.join(' ')}`);
	}

	const containerName = dockerContainers[serviceName] ?? serviceName;
	const envArgs = envEntries.flatMap(([key, value]) => ['-e', `${key}=${value ?? ''}`]);
	const finalArgs = ['exec', ...optionArgs, ...envArgs, containerName, ...args.slice(cursor + 1)];

	const result = spawnSync('docker', finalArgs, {
		env: { ...process.env, ...env },
		encoding: 'utf-8'
	});

	if (result.error) {
		throw result.error;
	}

	return result as SpawnSyncReturns<string>;
}

function runWpCli(args: string[], env: DockerEnv = {}): SpawnSyncReturns<string> {
	return runDocker(['exec', '-T', 'wordpress', 'wp', `--url=${wpBaseUrl}`, ...args], env);
}

function runWpCliSkippingSentientPlugins(
	args: string[],
	env: DockerEnv = {}
): SpawnSyncReturns<string> {
	return runWpCli(['--skip-plugins=sentient-forms,sentient-forms-wporg-check', ...args], env);
}

export function runWpEval(phpScript: string, env: DockerEnv = {}): string {
	const result = runWpCli(['eval', phpScript], env);
	if (result.status !== 0) {
		const message = stripCliNoise(result.stderr || result.stdout);
		throw new Error(`wp eval failed: ${message}`);
	}

	return stripCliNoise(result.stdout);
}

function ensureWpBaseUrlConfigured(): void {
	const php = `
update_option( 'home', '${wpBaseUrl}' );
update_option( 'siteurl', '${wpBaseUrl}' );
echo 'ok';
`;
	const output = runWpEval(php);
	if (!output.includes('ok')) {
		throw new Error(`Failed to align WordPress base URL: ${output}`);
	}
}

function readActivePlugins(): string[] {
	const result = runWpCli(['plugin', 'list', '--status=active', '--format=json']);
	if (result.status !== 0) {
		const message = stripCliNoise(result.stderr || result.stdout);
		throw new Error(`Failed to read active plugins: ${message}`);
	}

	const output = (result.stdout ?? '').trim();
	if (!output) {
		return [];
	}

	try {
		const decoded = JSON.parse(output) as unknown;
		if (Array.isArray(decoded)) {
			return decoded
				.map((item): string | null => {
					if (typeof item === 'string') {
						return item;
					}
					if (item && typeof item === 'object' && 'name' in item) {
						const { name } = item as { name?: unknown };
						return typeof name === 'string' ? name : null;
					}
					return null;
				})
				.filter((item): item is string => typeof item === 'string' && item.length > 0);
		}
		if (decoded && typeof decoded === 'object') {
			return Object.values(decoded).filter((item): item is string => typeof item === 'string');
		}
		return [];
	} catch (error) {
		throw new Error(
			`Failed to parse active_plugins payload: ${
				error instanceof Error ? error.message : String(error)
			}`
		);
	}
}

export function ensureExpectedPluginMode(): void {
	const wpPluginMode = currentWpPluginMode();
	const activePlugins = readActivePlugins();
	const packageActive = activePlugins.includes(packagePluginSlug);
	const sourceActive = activePlugins.includes(sourcePluginSlug);

	if (wpPluginMode === 'package') {
		if (!sourceActive && packageActive) {
			return;
		}

		if (sourceActive) {
			const deactivateResult = runWpCliSkippingSentientPlugins([
				'plugin',
				'deactivate',
				'sentient-forms'
			]);
			if (deactivateResult.status !== 0) {
				const message = stripCliNoise(deactivateResult.stderr || deactivateResult.stdout);
				throw new Error(`Failed to deactivate sentient-forms: ${message}`);
			}
		}

		if (!packageActive || sourceActive) {
			const activateResult = runWpCliSkippingSentientPlugins([
				'plugin',
				'activate',
				'sentient-forms-wporg-check'
			]);
			if (activateResult.status !== 0) {
				const message = stripCliNoise(activateResult.stderr || activateResult.stdout);
				throw new Error(`Failed to activate sentient-forms-wporg-check: ${message}`);
			}
		}

		const normalizedActivePlugins = readActivePlugins();
		if (
			normalizedActivePlugins.includes(sourcePluginSlug) ||
			!normalizedActivePlugins.includes(packagePluginSlug)
		) {
			throw new Error(
				`WordPress plugin mode is not normalized for package E2E. Active plugins: ${normalizedActivePlugins.join(', ')}`
			);
		}

		return;
	}

	if (!packageActive && sourceActive) {
		return;
	}

	if (packageActive) {
		const deactivateResult = runWpCliSkippingSentientPlugins([
			'plugin',
			'deactivate',
			'sentient-forms-wporg-check'
		]);
		if (deactivateResult.status !== 0) {
			const message = stripCliNoise(deactivateResult.stderr || deactivateResult.stdout);
			throw new Error(`Failed to deactivate sentient-forms-wporg-check: ${message}`);
		}
	}

	if (!sourceActive || packageActive) {
		const activateResult = runWpCliSkippingSentientPlugins([
			'plugin',
			'activate',
			'sentient-forms'
		]);
		if (activateResult.status !== 0) {
			const message = stripCliNoise(activateResult.stderr || activateResult.stdout);
			throw new Error(`Failed to activate sentient-forms: ${message}`);
		}
	}

	runWpCliSkippingSentientPlugins(['transient', 'delete', 'sentient_forms_admin_dev_url']);

	const normalizedActivePlugins = readActivePlugins();
	if (
		normalizedActivePlugins.includes(packagePluginSlug) ||
		!normalizedActivePlugins.includes(sourcePluginSlug)
	) {
		throw new Error(
			`WordPress plugin mode is not normalized for source E2E. Active plugins: ${normalizedActivePlugins.join(', ')}`
		);
	}
}

export function ensureSourcePluginMode(): void {
	process.env.SENTIENT_WP_PLUGIN_MODE = 'source';
	ensureExpectedPluginMode();
}

export function ensureGravityForm(
	title: string,
	fields?: GravityField[],
	options?: GravityFormOptions
): number {
	const payload = {
		title,
		fields:
			fields ??
			([
				{ type: 'text', id: 1, label: 'Name', isRequired: true },
				{ type: 'email', id: 2, label: 'Email', isRequired: true }
			] as GravityField[]),
		notifications: options?.notifications
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
$title         = $payload['title'];
$fields        = $payload['fields'] ?? [];
$notifications = array_key_exists( 'notifications', $payload ) ? ( $payload['notifications'] ?? [] ) : [];
$forms         = GFAPI::get_forms();
$form          = null;
foreach ( $forms as $candidate_form ) {
    if ( isset( $candidate_form['title'] ) && $candidate_form['title'] === $title ) {
        $form = GFAPI::get_form( (int) $candidate_form['id'] );
        break;
    }
}

if ( ! is_array( $form ) ) {
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

    $form = GFAPI::get_form( (int) $form_id );
    if ( ! is_array( $form ) ) {
        echo "0";
        return;
    }
}

// Normalize the form on every call so repeated runs do not inherit stale fields
// or notifications from previous tests that reused the same title.
$form['title'] = $title;
$form['fields'] = [];
$form['button'] = [ 'type' => 'text', 'text' => 'Submit' ];
foreach ( $fields as $field ) {
    $form['fields'][] = [
        'type'       => $field['type'] ?? 'text',
        'id'         => (int) ( $field['id'] ?? 0 ),
        'label'      => $field['label'] ?? '',
        'isRequired' => ! empty( $field['isRequired'] ),
    ];
}

$form['notifications'] = [];
foreach ( $notifications as $index => $notification ) {
    if ( ! is_array( $notification ) ) {
        continue;
    }

    $notification_id = (string) ( $notification['id'] ?? ( 'playwright_notification_' . ( $index + 1 ) ) );
    $form['notifications'][ $notification_id ] = [
        'id'             => $notification_id,
        'name'           => (string) ( $notification['name'] ?? ( 'Playwright Notification ' . ( $index + 1 ) ) ),
        'event'          => (string) ( $notification['event'] ?? 'form_submission' ),
        'to'             => (string) ( $notification['to'] ?? '' ),
        'toType'         => (string) ( $notification['toType'] ?? 'email' ),
        'toField'        => isset( $notification['toField'] ) ? (string) $notification['toField'] : '',
        'subject'        => (string) ( $notification['subject'] ?? '' ),
        'message'        => (string) ( $notification['message'] ?? '' ),
        'from'           => (string) ( $notification['from'] ?? 'no-reply@example.test' ),
        'fromName'       => (string) ( $notification['fromName'] ?? 'Playwright Mail Capture' ),
        'message_format' => (string) ( $notification['messageFormat'] ?? 'html' ),
        'service'        => (string) ( $notification['service'] ?? 'wordpress' ),
        'isActive'       => true,
    ];
}

$updated = GFAPI::update_form( $form );
if ( is_wp_error( $updated ) ) {
    echo "0";
    return;
}

echo (int) ( $form['id'] ?? 0 );
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
	const mappingId = args.localMappingId ?? `map_${args.actionId}`;
	const dependencyIds = Array.from(
		new Set(
			(args.dependencyIds ?? [])
				.map((dependencyId) => dependencyId?.toString().trim())
				.filter(Boolean)
		)
	);
	const triggerSources = args.triggerSources
		? Object.fromEntries(
				Object.entries(args.triggerSources)
					.map(([hook, source]) => {
						const normalizedHook = hook?.toString().trim();
						if (!normalizedHook || !source?.type) {
							return null;
						}
						if (source.type === 'mapping' && source.mapping_id) {
							const mappingId = source.mapping_id.toString().trim();
							if (!mappingId) {
								return null;
							}
							return [normalizedHook, { type: 'mapping' as const, mapping_id: mappingId }];
						}
						return [normalizedHook, { type: 'hook_root' as const }];
					})
					.filter(
						(entry): entry is [string, { type: 'hook_root' | 'mapping'; mapping_id?: string }] =>
							Array.isArray(entry)
					)
			)
		: undefined;
	const inputMapping = args.inputMapping
		? {
				mode: args.inputMapping.mode,
				field_ids: args.inputMapping.fieldIds ?? [],
				include_metadata: args.inputMapping.includeMetadata ?? true
			}
		: undefined;
	const batchSettings = args.batchSettings
		? {
				enabled: args.batchSettings.enabled ?? false,
				delay_seconds: args.batchSettings.delaySeconds ?? 60,
				max_wait_seconds: args.batchSettings.maxWaitSeconds ?? 43200
			}
		: undefined;
	const parsedRealtimeSettings = parseRealtimeSettings(args.realtimeSettings);
	const realtimeSettings = parsedRealtimeSettings
		? {
				checkpoint_field_ids: parsedRealtimeSettings.checkpointFieldIds ?? [],
				refresh_mode: parsedRealtimeSettings.refreshMode,
				page_checkpoints_enabled: parsedRealtimeSettings.pageCheckpointsEnabled,
				page_checkpoint_mode: parsedRealtimeSettings.pageCheckpointMode,
				page_checkpoint_pages: parsedRealtimeSettings.pageCheckpointPages,
				debounce_ms: parsedRealtimeSettings.debounceMs ?? 600,
				cooldown_ms: parsedRealtimeSettings.cooldownMs ?? 8000,
				manual_refresh_enabled: parsedRealtimeSettings.manualRefreshEnabled ?? true,
				storage_target_field_id: parsedRealtimeSettings.storageTargetFieldId ?? '',
				blocking_mode: parsedRealtimeSettings.blockingMode ?? 'advisory',
				initial_panel_state: parsedRealtimeSettings.initialPanelState ?? 'minimized'
			}
		: undefined;
	const mappingSettings: Record<string, unknown> = {
		input_mapping: inputMapping,
		batch_settings: batchSettings,
		execution_mode: args.executionMode ?? undefined,
		realtime_settings: realtimeSettings,
		...(args.additionalSettings ?? {}),
		...(dependencyIds.length > 0 ? { dependency_ids: dependencyIds } : {}),
		...(triggerSources ? { trigger_sources: triggerSources } : {}),
		...(args.skipOnUpstreamSpam ? { skip_on_upstream_spam: true } : {})
	};
	const mapping = {
		id: mappingId,
		enabled: true,
		is_action_enabled_for_form: true,
		central_action_id: args.centralActionId,
		action_name_label: args.actionNameLabel ?? args.centralActionId,
		trigger_hooks: args.hooks,
		async: args.async ?? false,
		reject_submission: args.rejectSubmission ?? false,
		mark_as_spam: args.markAsSpam ?? false,
		execution_priority: args.executionPriority ?? 10,
		action_type_indicator: args.actionTypeIndicator ?? 'master',
		action_template_id: args.actionTemplateId,
		local_mapping_id: mappingId,
		batch_settings: batchSettings,
		settings: mappingSettings
	};
	const settings: Record<string, unknown> = {
		enabled: true,
		actions: {
			[mappingId]: mapping
		},
		[mappingId]: mapping
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
$current = get_option( $option_name, [] );
if ( ! is_array( $current ) ) {
    $current = [];
}

$extract_mappings = static function ( array $payload ): array {
    $mappings = [];

    if ( isset( $payload['actions'] ) && is_array( $payload['actions'] ) ) {
        foreach ( $payload['actions'] as $mapping_id => $mapping ) {
            if ( is_array( $mapping ) ) {
                $mappings[ $mapping_id ] = $mapping;
            }
        }
    }

    foreach ( $payload as $mapping_id => $mapping ) {
        if ( 'actions' === $mapping_id || ! is_array( $mapping ) ) {
            continue;
        }

        if ( isset( $mapping['local_mapping_id'] ) || isset( $mapping['central_action_id'] ) ) {
            $mappings[ $mapping_id ] = $mapping;
        }
    }

    return $mappings;
};

$existing_actions = $extract_mappings( $current );
$incoming_actions = $extract_mappings( $data );
$merged_actions = getenv( 'MERGE_EXISTING_MAPPINGS' ) === '1'
    ? array_replace( $existing_actions, $incoming_actions )
    : $incoming_actions;
$base_settings = $current;
unset( $base_settings['actions'] );
foreach ( $base_settings as $setting_key => $setting_value ) {
    if (
        is_array( $setting_value ) &&
        ( isset( $setting_value['local_mapping_id'] ) || isset( $setting_value['central_action_id'] ) )
    ) {
        unset( $base_settings[ $setting_key ] );
    }
}

$settings = array_merge(
    $base_settings,
    [
        'enabled' => true,
    ],
    $data
);
$settings['actions'] = $merged_actions;

foreach ( $merged_actions as $mapping_id => $mapping ) {
    $settings[ $mapping_id ] = $mapping;
}
update_option( $option_name, $settings, false );
echo 'ok';
`
		],
		{
			FORM_ID: String(args.formId),
			SETTINGS_JSON: JSON.stringify(settings),
			MERGE_EXISTING_MAPPINGS: args.mergeWithExistingMappings ? '1' : '0'
		}
	);

	if (result.status !== 0 || !stripCliNoise(result.stdout).includes('ok')) {
		throw new Error(
			`Failed to configure action mapping: ${stripCliNoise(result.stderr || result.stdout)}`
		);
	}
}

export function getGravityActionSettings(formId: number): GravityActionSettingsRecord {
	const result = runWpCli([
		'option',
		'get',
		`sentient_forms_actions_gravity_forms_${formId}`,
		'--format=json'
	]);

	if (result.status !== 0) {
		throw new Error(
			`Failed to read action settings for form ${formId}: ${stripCliNoise(result.stderr || result.stdout)}`
		);
	}

	const payload = result.stdout.trim();
	if (!payload) {
		throw new Error(`Empty action settings payload for form ${formId}`);
	}

	try {
		return JSON.parse(payload) as GravityActionSettingsRecord;
	} catch (error) {
		throw new Error(
			`Failed to parse action settings for form ${formId}: ${
				error instanceof Error ? error.message : String(error)
			}`
		);
	}
}

export function getLocalFormMappings(formId: number): LocalFormMappingRecord[] {
	const payload = runWpEval(
		`
global $wpdb;
$form_id        = sanitize_text_field( (string) absint( getenv( 'FORM_ID' ) ?: 0 ) );
$mappings_table = $wpdb->prefix . 'sentient_form_mappings';
$rows           = [];

if ( '' !== $form_id && '0' !== $form_id ) {
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT id, form_source, form_id, hook, action_kind, action_id, input_bindings_json, execution_mode, enabled FROM ' . esc_sql( $mappings_table ) . ' WHERE form_source = %s AND form_id = %s ORDER BY id ASC',
            'gravity_forms',
            $form_id
        ),
        ARRAY_A
    );
}

$rows = is_array( $rows ) ? $rows : [];
$normalized = array_map(
    static function ( array $row ): array {
        return [
            'id'             => absint( $row['id'] ?? 0 ),
            'form_source'    => isset( $row['form_source'] ) ? (string) $row['form_source'] : '',
            'form_id'        => isset( $row['form_id'] ) ? (string) $row['form_id'] : '',
            'hook'           => isset( $row['hook'] ) ? (string) $row['hook'] : '',
            'action_kind'    => isset( $row['action_kind'] ) ? (string) $row['action_kind'] : '',
            'action_id'      => isset( $row['action_id'] ) ? absint( $row['action_id'] ) : null,
            'input_bindings_json' => isset( $row['input_bindings_json'] )
                ? json_decode( (string) $row['input_bindings_json'], true )
                : null,
            'execution_mode'      => isset( $row['execution_mode'] ) ? (string) $row['execution_mode'] : null,
            'enabled'             => ! empty( $row['enabled'] ),
        ];
    },
    $rows
);

echo wp_json_encode(
    [
        'mappings' => array_values( $normalized ),
    ]
);
`,
		{
			FORM_ID: String(formId)
		}
	);

	if (!payload) {
		return [];
	}

	return parseLocalFormMappings(payload);
}

export function resetLocalFormFixture(args: ResetLocalFormFixtureArgs): void {
	const output = runWpEval(
		`
if ( ! class_exists( 'Sentient_Forms_Installer' ) ) {
    echo wp_json_encode([ 'error' => 'sentient_forms_not_loaded' ]);
    return;
}

Sentient_Forms_Installer::maybe_upgrade();

$payload = json_decode( getenv( 'RESET_LOCAL_FORM_FIXTURE' ) ?: '{}', true );
if ( ! is_array( $payload ) ) {
    echo wp_json_encode([ 'error' => 'invalid_payload' ]);
    return;
}

global $wpdb;
$form_id        = sanitize_text_field( (string) absint( $payload['formId'] ?? 0 ) );
$action_codes   = array_values(
    array_filter(
        array_map(
            static fn( $value ): string => sanitize_key( (string) $value ),
            is_array( $payload['actionCodes'] ?? null ) ? $payload['actionCodes'] : []
        )
    )
);
$action_names   = array_values(
    array_filter(
        array_map(
            static fn( $value ): string => sanitize_text_field( (string) $value ),
            is_array( $payload['actionNames'] ?? null ) ? $payload['actionNames'] : []
        )
    )
);
$mappings_table = $wpdb->prefix . 'sentient_form_mappings';
$actions_table  = $wpdb->prefix . 'sentient_custom_actions';
$action_ids     = [];
$deleted_mappings = 0;

if ( '' !== $form_id && '0' !== $form_id ) {
    $mapping_rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT id, action_id FROM ' . esc_sql( $mappings_table ) . ' WHERE form_source = %s AND form_id = %s AND action_kind = %s',
            'gravity_forms',
            $form_id,
            'custom_action'
        ),
        ARRAY_A
    );

    $mapping_rows = is_array( $mapping_rows ) ? $mapping_rows : [];
    foreach ( $mapping_rows as $row ) {
        $action_id = absint( $row['action_id'] ?? 0 );
        if ( $action_id > 0 ) {
            $action_ids[] = $action_id;
        }

        $mapping_id = absint( $row['id'] ?? 0 );
        if ( $mapping_id > 0 ) {
            $deleted = $wpdb->delete( $mappings_table, [ 'id' => $mapping_id ], [ '%d' ] );
            if ( false !== $deleted ) {
                $deleted_mappings += (int) $deleted;
            }
        }
    }
}

if ( ! empty( $action_codes ) ) {
    $placeholders = implode( ', ', array_fill( 0, count( $action_codes ), '%s' ) );
    $action_rows  = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT id FROM ' . esc_sql( $actions_table ) . ' WHERE code IN (' . $placeholders . ')',
            ...$action_codes
        ),
        ARRAY_A
    );

    $action_rows = is_array( $action_rows ) ? $action_rows : [];
    foreach ( $action_rows as $row ) {
        $action_id = absint( $row['id'] ?? 0 );
        if ( $action_id > 0 ) {
            $action_ids[] = $action_id;
        }
    }
}

if ( ! empty( $action_names ) ) {
    $placeholders = implode( ', ', array_fill( 0, count( $action_names ), '%s' ) );
    $action_rows  = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT id FROM ' . esc_sql( $actions_table ) . ' WHERE display_name IN (' . $placeholders . ')',
            ...$action_names
        ),
        ARRAY_A
    );

    $action_rows = is_array( $action_rows ) ? $action_rows : [];
    foreach ( $action_rows as $row ) {
        $action_id = absint( $row['id'] ?? 0 );
        if ( $action_id > 0 ) {
            $action_ids[] = $action_id;
        }
    }
}

$action_ids = array_values( array_unique( array_filter( array_map( 'absint', $action_ids ) ) ) );
foreach ( $action_ids as $action_id ) {
    $deleted = $wpdb->delete(
        $mappings_table,
        [
            'action_kind' => 'custom_action',
            'action_id'   => $action_id,
        ],
        [ '%s', '%d' ]
    );
    if ( false !== $deleted ) {
        $deleted_mappings += (int) $deleted;
    }

    $wpdb->delete( $actions_table, [ 'id' => $action_id ], [ '%d' ] );
}

echo wp_json_encode(
    [
        'deleted_mapping_count' => $deleted_mappings,
        'deleted_action_ids'    => $action_ids,
    ]
);
`,
		{
			RESET_LOCAL_FORM_FIXTURE: JSON.stringify(args)
		}
	);

	const parsed = JSON.parse(output) as { error?: string };
	if (parsed.error) {
		throw new Error(`Failed to reset local form fixture: ${parsed.error}`);
	}
}

export function runActionScheduler(): void {
	const result = runWpCli(['action-scheduler', 'run']);
	if (result.status !== 0) {
		throw new Error(
			`action-scheduler run failed: ${stripCliNoise(result.stderr || result.stdout)}`
		);
	}
}

export function setGravityAsyncNotificationsEnabled(enabled: boolean): void {
	const result = runWpCli([
		'option',
		'update',
		'gform_enable_async_notifications',
		enabled ? '1' : '0'
	]);

	if (result.status !== 0) {
		throw new Error(
			`Failed to update Gravity Forms async notification setting: ${stripCliNoise(result.stderr || result.stdout)}`
		);
	}
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
		throw new Error(
			`getLatestEntryId failed for form ${formId}: ${stripCliNoise(result.stderr || result.stdout)}`
		);
	}

	return Number.parseInt(stripCliNoise(result.stdout), 10) || 0;
}

export function getGravityFormFields(formId: number): GravityFormFieldRecord[] {
	const payload = runWpEval(
		`
$form_id = (int) getenv( 'FORM_ID' );
$form = GFAPI::get_form( $form_id );
if ( ! is_array( $form ) ) {
    echo '{"fields":[]}';
    return;
}

$fields = [];
foreach ( $form['fields'] ?? [] as $field ) {
    $fields[] = [
        'id'         => isset( $field->id ) ? (string) $field->id : '',
        'type'       => isset( $field->type ) ? (string) $field->type : '',
        'label'      => isset( $field->label ) ? (string) $field->label : '',
        'adminLabel' => isset( $field->adminLabel ) ? (string) $field->adminLabel : '',
        'inputName'  => isset( $field->inputName ) ? (string) $field->inputName : '',
        'cssClass'   => isset( $field->cssClass ) ? (string) $field->cssClass : '',
    ];
}
echo wp_json_encode( [ 'fields' => $fields ] );
`,
		{
			FORM_ID: String(formId)
		}
	);

	try {
		const decoded = JSON.parse(payload) as { fields?: GravityFormFieldRecord[] };
		return Array.isArray(decoded.fields) ? decoded.fields : [];
	} catch (_error) {
		throw new Error(`Failed to parse Gravity Forms fields for form ${formId}: ${payload}`);
	}
}

export function getGravityEntryFieldValue(entryId: number, fieldId: string): string | null {
	const payload = runWpEval(
		`
$entry_id = (int) getenv( 'ENTRY_ID' );
$field_id = getenv( 'FIELD_ID' ) ?: '';
$entry = GFAPI::get_entry( $entry_id );
if ( is_wp_error( $entry ) || '' === $field_id || ! array_key_exists( $field_id, $entry ) ) {
    echo '';
    return;
}

$value = $entry[ $field_id ];
if ( is_scalar( $value ) ) {
    echo (string) $value;
    return;
}
echo wp_json_encode( $value );
`,
		{
			ENTRY_ID: String(entryId),
			FIELD_ID: fieldId
		}
	);

	return payload === '' ? null : payload;
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
$meta = function_exists( 'gform_get_meta' ) ? gform_get_meta( $entry_id, 'sentient_forms_spam_classification' ) : null;
echo wp_json_encode([
    'status' => $entry['status'] ?? 'unknown',
    'is_spam' => is_string( $meta ) ? in_array( $meta, [ 'spam', 'likely_spam' ], true ) : null,
    'classification' => is_string( $meta ) ? $meta : null,
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

export function clearCapturedMail(): void {
	const output = runWpEval(
		`
if ( function_exists( 'sentient_forms_dev_mail_capture_clear_records' ) ) {
    sentient_forms_dev_mail_capture_clear_records();
} else {
    update_option( 'sentient_forms_dev_mail_capture', [], false );
}
echo 'ok';
`
	);

	if (!output.includes('ok')) {
		throw new Error(`Failed to clear captured mail records: ${output}`);
	}
}

export function getCapturedMailRecords(): CapturedMailRecord[] {
	const payload = runWpEval(
		`
$records = function_exists( 'sentient_forms_dev_mail_capture_get_records' )
    ? sentient_forms_dev_mail_capture_get_records()
    : get_option( 'sentient_forms_dev_mail_capture', [] );
echo wp_json_encode(
    [
        'records' => is_array( $records ) ? array_values( $records ) : [],
    ]
);
`
	);

	if (!payload) {
		return [];
	}

	try {
		const decoded = JSON.parse(payload) as { records?: CapturedMailRecord[] };
		return Array.isArray(decoded.records) ? decoded.records : [];
	} catch (_error) {
		throw new Error(`Failed to parse captured mail payload: ${payload}`);
	}
}

export function getGravityEntryNotes(entryId: number): GravityEntryNoteRecord[] {
	const payload = runWpEval(
		`
$entry_id = (int) getenv( 'ENTRY_ID' );
$notes = class_exists( 'GFAPI' )
    ? GFAPI::get_notes(
        [ 'entry_id' => $entry_id ],
        [ 'key' => 'id', 'direction' => 'ASC' ]
    )
    : [];

if ( ! is_array( $notes ) ) {
    echo '[]';
    return;
}

$normalized = array_map(
    static function ( $note ) {
        $item = is_object( $note ) ? get_object_vars( $note ) : ( is_array( $note ) ? $note : [] );

        return [
            'entry_id'     => (int) ( $item['entry_id'] ?? 0 ),
            'id'           => (int) ( $item['id'] ?? 0 ),
            'user_id'      => isset( $item['user_id'] ) ? (int) $item['user_id'] : null,
            'user_name'    => isset( $item['user_name'] ) ? (string) $item['user_name'] : null,
            'user_email'   => isset( $item['user_email'] ) ? (string) $item['user_email'] : null,
            'date_created' => isset( $item['date_created'] ) ? (string) $item['date_created'] : '',
            'value'        => isset( $item['value'] ) ? (string) $item['value'] : '',
            'note_type'    => isset( $item['note_type'] ) ? (string) $item['note_type'] : null,
            'sub_type'     => isset( $item['sub_type'] ) ? (string) $item['sub_type'] : null,
        ];
    },
    $notes
);

echo wp_json_encode(
    [
        'notes' => $normalized,
    ]
);
`,
		{
			ENTRY_ID: String(entryId)
		}
	);

	if (!payload) {
		return [];
	}

	try {
		const decoded = JSON.parse(payload) as { notes?: GravityEntryNoteRecord[] };
		return Array.isArray(decoded.notes) ? decoded.notes : [];
	} catch (_error) {
		throw new Error(`Failed to parse Gravity Forms entry notes payload: ${payload}`);
	}
}

type WaitForGravityEntryNotesOptions = {
	description?: string;
	maxAttempts?: number;
	intervalMs?: number;
	runScheduler?: boolean;
};

export async function waitForGravityEntryNotes(
	entryId: number,
	page: Page,
	predicate: (notes: GravityEntryNoteRecord[]) => boolean,
	options: WaitForGravityEntryNotesOptions = {}
): Promise<GravityEntryNoteRecord[]> {
	const description = options.description ?? 'matching Gravity Forms entry notes';
	const maxAttempts = options.maxAttempts ?? 10;
	const intervalMs = options.intervalMs ?? 1000;
	let notes: GravityEntryNoteRecord[] = [];

	for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
		if (options.runScheduler) {
			runActionScheduler();
		}

		notes = getGravityEntryNotes(entryId);
		if (predicate(notes)) {
			return notes;
		}

		await page.waitForTimeout(intervalMs);
	}

	throw new Error(
		`Could not find ${description} for entry ${entryId}. Last notes: ${JSON.stringify(notes)}`
	);
}

export function setFormStatusOption(
	formId: number,
	status: 'success' | 'error',
	message: string
): void {
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
	throw new Error(
		`Meta ${metaKey} not satisfied after ${maxAttempts} attempts; lastValue=${JSON.stringify(lastValue)}`
	);
}

export async function submitGravityForm(
	page: Page,
	formId: number,
	name: string,
	email: string,
	additionalFieldValues: Record<string, string> = {}
): Promise<void> {
	await loginToWpAdmin(page);
	await page.goto(`${wpBaseUrl}/?gf_page=preview&id=${formId}`, { waitUntil: 'domcontentloaded' });
	await waitForPreviewInputs(page, formId);
	const fieldValues: Array<[string, string]> = [
		['1', name],
		['2', email],
		...Object.entries(additionalFieldValues)
	];
	for (const [fieldId, value] of fieldValues) {
		const field = page
			.locator(`input[name="input_${fieldId}"], textarea[name="input_${fieldId}"]`)
			.first();
		await expect(field, `Gravity Forms preview field input_${fieldId} should exist`).toBeVisible();
		await field.fill(value);
	}
	await Promise.all([
		page.click('input[type="submit"], button[type="submit"]'),
		page.waitForSelector('.gform_confirmation_message, .gform_confirmation_wrapper', {
			timeout: 15000
		})
	]);
}
