import { expect, test } from '@playwright/test';
import { requireWpRestHealthy, runWpEval } from './utils/wp-e2e-helpers';
import { ensureSentientFormsSpa, loginToWpAdmin } from './utils/wp-admin';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';
const providerDeleteSmokeLabel = 'Provider delete smoke OpenRouter key';

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
});
