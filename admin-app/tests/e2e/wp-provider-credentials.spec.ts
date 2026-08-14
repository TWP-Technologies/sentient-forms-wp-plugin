import { expect, test } from '@playwright/test';
import { z } from 'zod';
import { requireWpRestHealthy, runWpEval } from './utils/wp-e2e-helpers';
import { ensureSentientFormsSpa, loginToWpAdmin } from './utils/wp-admin';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';
const providerDeleteSmokeLabel = 'Provider delete smoke OpenRouter key';
const providerConstantSmokeLabel = 'Provider constant smoke OpenRouter key';
const providerConstantName = 'SENTIENT_FORMS_OPENROUTER_E2E_KEY';
const providerConstantE2EPluginDir = 'sentient-forms-openrouter-constant-e2e';
const providerConstantE2EPluginEntry = `${providerConstantE2EPluginDir}/${providerConstantE2EPluginDir}.php`;
const referencedFormMappingSeedSchema = z.union([
	z.object({ mapping_id: z.number().int().positive() }),
	z.object({ error: z.string().min(1) })
]);

function seedOpenRouterCredential(label: string): number {
	const output = runWpEval(
		`
if ( ! class_exists( 'Sentient_Forms_Installer' ) ) {
	echo wp_json_encode([ 'error' => 'sentient_forms_not_loaded' ]);
	return;
}

Sentient_Forms_Installer::maybe_upgrade();

global $wpdb;
$label       = getenv( 'OPENROUTER_LABEL' ) ?: 'Provider delete smoke OpenRouter key';
$secret      = 'sk-or-provider-delete-smoke-' . wp_generate_uuid4();
$vault       = new Sentient_Forms_Provider_Credential_Vault();
$encrypted   = $vault->encrypt( $secret );
$credentials = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
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
			'status_json'       => wp_json_encode([ 'message' => 'Seeded for provider delete smoke.' ]),
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
			'status_json'       => [ 'message' => 'Seeded for provider delete smoke.' ],
			'last_validated_at' => current_time( 'mysql' ),
		]
	);
}

if ( is_wp_error( $credential_id ) ) {
	echo wp_json_encode([ 'error' => $credential_id->get_error_message() ]);
	return;
}

echo wp_json_encode([ 'credential_id' => (int) $credential_id ]);
`,
		{
			OPENROUTER_LABEL: label
		}
	);

	const parsed = JSON.parse(output) as { credential_id?: number; error?: string };
	if (parsed.error) {
		throw new Error(`Failed to seed provider credential: ${parsed.error}`);
	}

	if (!parsed.credential_id) {
		throw new Error(`Provider credential seed returned an invalid payload: ${output}`);
	}

	return parsed.credential_id;
}

function countOpenRouterCredentialsByLabel(label: string): number {
	const output = runWpEval(
		`
global $wpdb;
$label = getenv( 'OPENROUTER_LABEL' ) ?: 'Provider delete smoke OpenRouter key';
$count = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(*) FROM ' . esc_sql( $wpdb->prefix . 'sentient_provider_credentials' ) . ' WHERE provider = %s AND label = %s',
		'openrouter',
		$label
	)
);
echo wp_json_encode([ 'count' => $count ]);
`,
		{
			OPENROUTER_LABEL: label
		}
	);

	const parsed = JSON.parse(output) as { count?: number };
	return Number(parsed.count ?? 0);
}

function seedReferencedFormMapping(credentialId: number): number {
	const output = runWpEval(
		`
global $wpdb;
$credential_id = absint( getenv( 'OPENROUTER_CREDENTIAL_ID' ) ?: 0 );
$mappings      = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
$mapping_id    = $mappings->create(
	[
		'form_source'         => 'gravity_forms',
		'form_id'             => 'provider-delete-guard-e2e',
		'hook'                => 'validation',
		'action_kind'         => 'custom_action',
		'action_id'           => 991001,
		'input_bindings_json' => [],
		'execution_mode'      => 'sync',
		'settings_json'       => [
			'model_selection' => [
				'provider'      => 'openrouter',
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

echo wp_json_encode([ 'mapping_id' => (int) $mapping_id ]);
`,
		{
			OPENROUTER_CREDENTIAL_ID: String(credentialId)
		}
	);

	const parsed = referencedFormMappingSeedSchema.safeParse(JSON.parse(output));
	if (!parsed.success) {
		throw new Error(`Failed to seed referenced form mapping: ${output}`);
	}
	if ('error' in parsed.data) {
		throw new Error(`Failed to seed referenced form mapping: ${parsed.data.error}`);
	}

	return parsed.data.mapping_id;
}

function deleteFormMapping(mappingId: number): void {
	runWpEval(
		`
global $wpdb;
$wpdb->delete(
	$wpdb->prefix . 'sentient_form_mappings',
	[ 'id' => absint( getenv( 'SENTIENT_FORMS_MAPPING_ID' ) ?: 0 ) ],
	[ '%d' ]
);
echo wp_json_encode([ 'deleted' => true ]);
`,
		{
			SENTIENT_FORMS_MAPPING_ID: String(mappingId)
		}
	);
}

function installOpenRouterConstantMuPlugin(constantName: string, secretValue: string): void {
	const pluginContents = `<?php
/**
 * Plugin Name: Sentient Forms OpenRouter Constant E2E
 */

if ( ! defined( '${constantName}' ) ) {
\tdefine( '${constantName}', '${secretValue}' );
}

add_filter(
\t'pre_http_request',
\tstatic function ( $preempt, $args, $url ) {
\t\tif ( ! is_string( $url ) || 0 !== strpos( $url, 'https://openrouter.ai/api/v1/key' ) ) {
\t\t\treturn $preempt;
\t\t}

\t\treturn [
\t\t\t'headers'  => [],
\t\t\t'body'     => wp_json_encode(
\t\t\t\t[
\t\t\t\t\t'data' => [
\t\t\t\t\t\t'label'           => 'E2E OpenRouter server secret',
\t\t\t\t\t\t'usage'           => 10,
\t\t\t\t\t\t'limit'           => 1000,
\t\t\t\t\t\t'limit_remaining' => 990,
\t\t\t\t\t\t'is_free_tier'    => false,
\t\t\t\t\t],
\t\t\t\t]
\t\t\t),
\t\t\t'response' => [
\t\t\t\t'code'    => 200,
\t\t\t\t'message' => 'OK',
\t\t\t],
\t\t\t'cookies'  => [],
\t\t\t'filename' => null,
\t\t];
\t},
\t10,
\t3
);
`;

	const output = runWpEval(
		`
$dir = trailingslashit( WP_PLUGIN_DIR ) . '${providerConstantE2EPluginDir}';
if ( ! is_dir( $dir ) ) {
\twp_mkdir_p( $dir );
}

$encoded = getenv( 'SENTIENT_FORMS_E2E_MU_PLUGIN_B64' ) ?: '';
$path    = trailingslashit( $dir ) . '${providerConstantE2EPluginDir}.php';
$bytes   = file_put_contents( $path, base64_decode( $encoded ) );

if ( false === $bytes ) {
\techo wp_json_encode([ 'error' => 'write_failed' ]);
\treturn;
}

$activated = activate_plugin( '${providerConstantE2EPluginEntry}' );
if ( is_wp_error( $activated ) ) {
\techo wp_json_encode([ 'error' => $activated->get_error_message() ]);
\treturn;
}

echo wp_json_encode([ 'bytes' => (int) $bytes, 'path' => $path ]);
`,
		{
			SENTIENT_FORMS_E2E_MU_PLUGIN_B64: Buffer.from(pluginContents, 'utf8').toString('base64')
		}
	);

	const parsed = JSON.parse(output) as { bytes?: number; error?: string };
	if (parsed.error || !parsed.bytes) {
		throw new Error(`Failed to install provider constant mu-plugin: ${output}`);
	}
}

function removeOpenRouterConstantMuPlugin(): void {
	runWpEval(
		`
$path = trailingslashit( WP_PLUGIN_DIR ) . '${providerConstantE2EPluginEntry}';
deactivate_plugins( '${providerConstantE2EPluginEntry}', true, false );
if ( file_exists( $path ) ) {
\twp_delete_file( $path );
}

$dir = trailingslashit( WP_PLUGIN_DIR ) . '${providerConstantE2EPluginDir}';
if ( is_dir( $dir ) ) {
\t@rmdir( $dir );
}
echo wp_json_encode([ 'removed' => true ]);
`
	);
}

function deleteOpenRouterCredentialsByLabel(label: string): void {
	runWpEval(
		`
global $wpdb;
$label = getenv( 'OPENROUTER_LABEL' ) ?: '';
$wpdb->delete(
\t$wpdb->prefix . 'sentient_provider_credentials',
\t[
\t\t'provider' => 'openrouter',
\t\t'label'    => $label,
\t]
);
echo wp_json_encode([ 'deleted' => true ]);
`,
		{
			OPENROUTER_LABEL: label
		}
	);
}

function getOpenRouterCredentialSummary(label: string): {
	count: number;
	auth_mode: string | null;
	constant_name: string | null;
} {
	const output = runWpEval(
		`
global $wpdb;
$label = getenv( 'OPENROUTER_LABEL' ) ?: '';
$row   = $wpdb->get_row(
\t$wpdb->prepare(
\t\t'SELECT auth_mode, constant_name FROM ' . esc_sql( $wpdb->prefix . 'sentient_provider_credentials' ) . ' WHERE provider = %s AND label = %s ORDER BY id DESC LIMIT 1',
\t\t'openrouter',
\t\t$label
\t),
\tARRAY_A
);
$count = (int) $wpdb->get_var(
\t$wpdb->prepare(
\t\t'SELECT COUNT(*) FROM ' . esc_sql( $wpdb->prefix . 'sentient_provider_credentials' ) . ' WHERE provider = %s AND label = %s',
\t\t'openrouter',
\t\t$label
\t)
);
echo wp_json_encode([
\t'count'         => $count,
\t'auth_mode'     => is_array( $row ) ? (string) ( $row['auth_mode'] ?? '' ) : null,
\t'constant_name' => is_array( $row ) ? (string) ( $row['constant_name'] ?? '' ) : null,
]);
`,
		{
			OPENROUTER_LABEL: label
		}
	);

	return JSON.parse(output) as {
		count: number;
		auth_mode: string | null;
		constant_name: string | null;
	};
}

test.describe('Local provider credentials in real wp-admin', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise wp-admin flows.');

	test('providers can cancel and confirm OpenRouter key deletion without remote Sentient calls', async ({
		page
	}) => {
		await requireWpRestHealthy(page);
		seedOpenRouterCredential(providerDeleteSmokeLabel);

		const sentientRequests = new Set<string>();
		page.on('request', (request) => {
			if (request.url().includes('sentientforms.com')) {
				sentientRequests.add(`${request.method()} ${request.url()}`);
			}
		});

		await loginToWpAdmin(page);
		await ensureSentientFormsSpa(page, '/providers');

		const credentialRow = page
			.getByTestId('providers-openrouter-credential')
			.filter({ hasText: providerDeleteSmokeLabel })
			.first();

		await expect(credentialRow).toBeVisible();
		await credentialRow.getByRole('button', { name: 'Delete' }).click();

		const confirmation = credentialRow.getByTestId('providers-openrouter-delete-confirmation');
		await expect(confirmation).toBeVisible();

		await credentialRow.getByRole('button', { name: 'Cancel' }).click();
		await expect(confirmation).toBeHidden();
		await expect(credentialRow).toBeVisible();

		await credentialRow.getByRole('button', { name: 'Delete' }).click();
		await expect(confirmation).toBeVisible();

		await Promise.all([
			page.waitForResponse((response) => {
				return (
					response.request().method() === 'DELETE' &&
					response.url().includes('/wp-json/sentient-forms/v1/local/providers/credentials/') &&
					response.ok()
				);
			}),
			credentialRow.getByRole('button', { name: 'Delete key' }).click()
		]);

		await expect(credentialRow).toBeHidden();
		expect(countOpenRouterCredentialsByLabel(providerDeleteSmokeLabel)).toBe(0);
		expect(Array.from(sentientRequests)).toEqual([]);
	});

	test('providers keep a referenced key and explain the deletion conflict', async ({ page }) => {
		await requireWpRestHealthy(page);
		let mappingId: number | null = null;

		try {
			const credentialId = seedOpenRouterCredential(providerDeleteSmokeLabel);
			mappingId = seedReferencedFormMapping(credentialId);
			const sentientRequests = new Set<string>();
			page.on('request', (request) => {
				if (request.url().includes('sentientforms.com')) {
					sentientRequests.add(`${request.method()} ${request.url()}`);
				}
			});

			await loginToWpAdmin(page);
			await ensureSentientFormsSpa(page, '/providers');

			const credentialRow = page
				.getByTestId('providers-openrouter-credential')
				.filter({ hasText: providerDeleteSmokeLabel })
				.first();

			await expect(credentialRow).toBeVisible();
			await credentialRow.getByRole('button', { name: 'Delete' }).click();
			await expect(
				credentialRow.getByTestId('providers-openrouter-delete-confirmation')
			).toContainText('update Site Context if it uses this key');

			const responsePromise = page.waitForResponse((response) => {
				return (
					response.request().method() === 'DELETE' &&
					response.url().includes('/wp-json/sentient-forms/v1/local/providers/credentials/') &&
					response.status() === 409
				);
			});
			await credentialRow.getByRole('button', { name: 'Delete key' }).click();
			await responsePromise;

			await expect(credentialRow.getByTestId('providers-openrouter-delete-error')).toContainText(
				'still used by local configuration or queued work'
			);
			await expect(
				credentialRow.getByTestId('providers-openrouter-delete-references')
			).toContainText(`Gravity Forms form provider-delete-guard-e2e, mapping #${mappingId}`);
			await expect(credentialRow).toBeVisible();
			expect(countOpenRouterCredentialsByLabel(providerDeleteSmokeLabel)).toBe(1);
			expect(Array.from(sentientRequests)).toEqual([]);
		} finally {
			if (mappingId !== null) {
				deleteFormMapping(mappingId);
			}
			deleteOpenRouterCredentialsByLabel(providerDeleteSmokeLabel);
		}
	});

	test('providers can validate a server-backed OpenRouter constant without remote Sentient calls', async ({
		page
	}) => {
		await requireWpRestHealthy(page);
		deleteOpenRouterCredentialsByLabel(providerConstantSmokeLabel);
		installOpenRouterConstantMuPlugin(providerConstantName, 'sk-or-provider-constant-smoke');

		try {
			const sentientRequests = new Set<string>();
			page.on('request', (request) => {
				if (request.url().includes('sentientforms.com')) {
					sentientRequests.add(`${request.method()} ${request.url()}`);
				}
			});

			await loginToWpAdmin(page);
			await ensureSentientFormsSpa(page, '/providers');

			const constantCard = page.getByTestId('providers-openrouter-constant-card');
			await expect(constantCard).toBeVisible();

			await constantCard.getByLabel('Label').fill(providerConstantSmokeLabel);
			await constantCard.getByLabel('Constant or environment variable').fill(providerConstantName);
			await constantCard.getByRole('checkbox').check();

			await Promise.all([
				page.waitForResponse((response) => {
					return (
						response.request().method() === 'POST' &&
						response
							.url()
							.includes('/wp-json/sentient-forms/v1/local/providers/openrouter/constant') &&
						response.ok()
					);
				}),
				constantCard.getByTestId('providers-openrouter-constant-submit').click()
			]);

			await expect(constantCard.getByTestId('providers-openrouter-constant-result')).toContainText(
				providerConstantName
			);

			const credentialRow = page
				.getByTestId('providers-openrouter-credential')
				.filter({ hasText: providerConstantSmokeLabel })
				.first();

			await expect(credentialRow).toBeVisible();
			await expect(credentialRow).toContainText('Server secret');
			await expect(credentialRow).toContainText(`Reference ${providerConstantName}`);

			expect(getOpenRouterCredentialSummary(providerConstantSmokeLabel)).toEqual({
				count: 1,
				auth_mode: 'constant',
				constant_name: providerConstantName
			});
			expect(Array.from(sentientRequests)).toEqual([]);
		} finally {
			deleteOpenRouterCredentialsByLabel(providerConstantSmokeLabel);
			removeOpenRouterConstantMuPlugin();
		}
	});
});
