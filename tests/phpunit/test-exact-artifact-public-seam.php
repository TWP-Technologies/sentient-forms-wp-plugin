<?php

/**
 * Assignment-driven behavioral evidence for the exact-artifact run plan.
 *
 * @package Sentient_Forms
 */

require_once __DIR__ . '/fixtures/exact-artifact/class-sentient-forms-test-exact-artifact-validation-scenario.php';
require_once dirname( __DIR__, 2 ) . '/scripts/check-action-facet-policy-snapshot.php';

final class Sentient_Forms_Test_Exact_Artifact_Accepted_Adapter implements
    Sentient_Forms_Adapter_Interface,
    Sentient_Forms_Accepted_Submission_Adapter_Interface
{
    /** @param array<string, mixed> $descriptor */
    public function __construct(
        private string $source,
        private string $form_id,
        private string $native_hook,
        private array $descriptor,
        private ?string $native_entry_id = null
    )
    {
    }

    public function get_id(): string
    {
        return $this->source;
    }

    public function get_name(): string
    {
        return 'Exact Artifact ' . $this->source;
    }

    public function is_active(): bool
    {
        return true;
    }

    public function get_forms(): array
    {
        return [ [ 'id' => $this->form_id, 'name' => 'Exact Artifact Form' ] ];
    }

    public function get_form_fields( $form_id ): array
    {
        return [ [ 'id' => 'message', 'label' => 'Message', 'type' => 'textarea' ] ];
    }

    public function form_exists( mixed $form_id ): bool
    {
        return (string) $form_id === $this->form_id;
    }

    public function get_entry_data( $entry_id, $form_id = null ): array
    {
        return [ 'id' => $entry_id, 'form_id' => $form_id ];
    }

    public function update_entry_meta( $entry_id, string $meta_key, $meta_value ): bool
    {
        return false;
    }

    public function mark_entry_as_spam( mixed $entry_id ): bool
    {
        return false;
    }

    public function reject_submission( mixed $entry_id, string $message ): bool
    {
        return false;
    }

    public function add_entry_note( mixed $entry_id, string $note_author, string $note_content ): bool
    {
        return false;
    }

    public function get_action_hook_for_event( string $event_name ): ?string
    {
        return 'after_submission' === $event_name ? $this->native_hook : null;
    }

    public function get_form_object( int $form_id ): array | null
    {
        return (string) $form_id === $this->form_id ? [ 'id' => $this->form_id ] : null;
    }

    public function get_accepted_submission_native_hook(): string
    {
        return $this->native_hook;
    }

    /** @return array<string, mixed> */
    public function normalize_accepted_submission( mixed $native_submission ): array | WP_Error
    {
        $normalized = [
            'form_id'        => $this->form_id,
            'form'           => [ 'id' => $this->form_id, 'title' => 'Exact Artifact Form' ],
            'logical_fields' => [ 'message' => 'Exact artifact behavioral fixture.' ],
            'files'          => [],
            'source_submitted_at' => '2026-07-12T12:00:00Z',
        ];
        if ( 'gravity_forms' === $this->source && null !== $this->native_entry_id )
        {
            $normalized['native_entry_id'] = $this->native_entry_id;
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    public function get_capability_descriptor(): array
    {
        return $this->descriptor;
    }
}

class Tests_Exact_Artifact_Public_Seam extends WP_UnitTestCase
{
    private const SPAM_GUIDANCE_FACET_EFFECT_DESCRIPTION = 'Authenticated Spam Guidance generates and saves one historical-entry rationale through Direct OpenRouter only after an active Sentient Forms subscription authorizes the facet and the managed route is unavailable.';
    private const CONTROLLED_GRAVITY_EMAIL = 'qa.sentientforms@totalwebpartners.com';

    /** @var array<string, mixed> */
    private array $assignment = [];

    /** @var array<string, mixed> */
    private array $expected_effect = [];

    private string $observation_path = '';

    public function test_exact_artifact_test_identity_distinguishes_action_facet_policy(): void
    {
        $base = [
            'action_code'                 => 'spam_detection_v1',
            'form_source'                 => 'gravity_forms',
            'lifecycle'                   => 'after_submission',
            'required_semantic_outcome'   => 'effect_applied',
            'facet_scenario_assignment'   => 'base_action',
            'policy_basis_assignment'     => 'action_catalog',
        ];
        $facet = array_merge(
            $base,
            [
                'facet_scenario_assignment' => 'spam_guidance_rationale_generation',
                'policy_basis_assignment'   => 'action_facet_catalog',
            ]
        );

        $this->assertNotSame( self::build_test_id( $base ), self::build_test_id( $facet ) );
        $this->assertSame( self::build_test_id( $base ), self::build_test_id( $base ) );
        $this->assertSame( 'reject', self::validation_mode_for_semantic_outcome( 'validation_effect_applied' ) );
        $this->assertSame( 'reject', self::validation_mode_for_semantic_outcome( 'validation_rejection' ) );
        $this->assertSame( 'accept', self::validation_mode_for_semantic_outcome( 'effect_applied' ) );
    }

    public function test_exact_artifact_assignment_public_seam(): void
    {
        $this->load_and_verify_assignment();

        $identities = [
            'request_trace_id'   => null,
            'rejection_trace_id' => null,
            'submission_id'      => null,
            'execution_id'       => null,
            'lifecycle_id'       => null,
        ];

        if ( 'base_action' !== $this->assignment['facet_scenario_assignment'] )
        {
            $identities = $this->exercise_action_facet_assignment();
        }
        elseif ( 'source_contract_rejection' === $this->assignment['required_semantic_outcome'] )
        {
            $identities = $this->exercise_authenticated_source_contract_rejection();
        }
        elseif ( 'validation' === $this->assignment['lifecycle'] )
        {
            $identities = $this->exercise_concrete_validation_hook();
        }
        elseif ( 'after_submission' === $this->assignment['lifecycle'] )
        {
            $identities = $this->exercise_accepted_submission_runner();
        }
        elseif ( 'real_time' === $this->assignment['lifecycle'] )
        {
            $identities = $this->exercise_gravity_realtime_persistence();
        }
        else
        {
            $this->fail( 'The assignment does not identify a supported behavioral seam.' );
        }

        $this->write_observation( $identities );
    }

    private function load_and_verify_assignment(): void
    {
        $assignment_path = getenv( 'SENTIENT_FORMS_EXACT_ASSIGNMENT_PATH' );
        $observation_path = getenv( 'SENTIENT_FORMS_EXACT_OBSERVATION_PATH' );
        if ( false === $assignment_path && false === $observation_path )
        {
            $this->markTestSkipped( 'Exact-artifact assignment environment is not active.' );
        }
        if ( ! is_string( $assignment_path ) || '' === trim( $assignment_path )
            || ! is_file( $assignment_path ) || is_link( $assignment_path ) )
        {
            $this->fail( 'SENTIENT_FORMS_EXACT_ASSIGNMENT_PATH must name a regular assignment file.' );
        }
        if ( ! is_string( $observation_path ) || '' === trim( $observation_path )
            || file_exists( $observation_path ) || is_link( $observation_path ) )
        {
            $this->fail( 'SENTIENT_FORMS_EXACT_OBSERVATION_PATH must name a new observation file.' );
        }

        $bytes = file_get_contents( $assignment_path );
        $assignment = json_decode( is_string( $bytes ) ? $bytes : '', true, 128, JSON_THROW_ON_ERROR );
        $this->assertIsArray( $assignment );

        $this->assignment      = $assignment;
        $this->observation_path = $observation_path;
        $this->expected_effect = $this->verify_against_public_authorities();
        $this->assertSame( $assignment['expected_effect_code'] ?? null, $this->expected_effect['code'] ?? null );
        $this->assertSame( $assignment['expected_effect_sha256'] ?? null, $this->expected_effect['description_sha256'] ?? null );
    }

    /** @return array<string, mixed> */
    private function verify_against_public_authorities(): array
    {
        Sentient_Forms_Action_Facet_Policy_Snapshot_Verifier::verify_snapshot( dirname( __DIR__, 2 ) );

        $required = [
            'id', 'action_code', 'form_source', 'lifecycle', 'required_semantic_outcome',
            'facet_scenario_assignment', 'effective_feature_access_assignment',
            'effective_execution_requirement_assignment', 'effective_required_form_source_capabilities',
            'effective_required_managed_capabilities', 'effective_eligible_lifecycles',
            'effective_metering_class', 'expected_effect_code', 'expected_effect_sha256',
        ];
        foreach ( $required as $key )
        {
            $this->assertArrayHasKey( $key, $this->assignment );
        }
        $this->assertNotSame( 'policy_rejection', $this->assignment['required_semantic_outcome'] );
        $snapshot = json_decode(
            (string) file_get_contents( dirname( __DIR__, 2 ) . '/contracts/action-source-compatibility.v1.json' ),
            true,
            128,
            JSON_THROW_ON_ERROR
        );
        $row = null;
        foreach ( $snapshot['rows'] ?? [] as $candidate )
        {
            if ( ( $candidate['action_code'] ?? null ) === $this->assignment['action_code']
                && ( $candidate['form_source'] ?? null ) === $this->assignment['form_source'] )
            {
                $row = $candidate;
                break;
            }
        }
        $this->assertIsArray( $row );
        $definition = Sentient_Forms_Bundled_Action_Templates::get( $this->assignment['action_code'] );
        $this->assertIsArray( $definition );
        $facet_code = $this->assignment['facet_scenario_assignment'];
        $facets = 'base_action' === $facet_code ? [] : [ $facet_code ];
        $policy = ( new Sentient_Forms_Action_Policy_Resolver() )->resolve_action_definition( $definition, $facets );
        $this->assertIsArray( $policy );
        foreach (
            [
                'feature_access'                    => 'effective_feature_access_assignment',
                'execution_requirement'             => 'effective_execution_requirement_assignment',
                'required_form_source_capabilities' => 'effective_required_form_source_capabilities',
                'required_managed_capabilities'     => 'effective_required_managed_capabilities',
                'eligible_lifecycles'                => 'effective_eligible_lifecycles',
                'metering_class'                     => 'effective_metering_class',
            ] as $policy_key => $assignment_key
        )
        {
            $this->assertSame( $this->assignment[ $assignment_key ], $policy[ $policy_key ] );
        }

        $effect = $this->derive_public_effect( $row );
        $this->assertSame( hash( 'sha256', $effect['description'] ), $effect['description_sha256'] );

        return $effect;
    }

    /** @param array<string, mixed> $row @return array{code:string,description:string,description_sha256:string} */
    private function derive_public_effect( array $row ): array
    {
        $action = $this->assignment['action_code'];
        $source = $this->assignment['form_source'];
        $lifecycle = $this->assignment['lifecycle'];
        $outcome = $this->assignment['required_semantic_outcome'];
        if ( 'spam_guidance_rationale_generation' === $this->assignment['facet_scenario_assignment'] )
        {
            $this->assertSame( 'spam_detection_v1', $action );
            $this->assertSame( 'gravity_forms', $source );
            $this->assertSame( 'after_submission', $lifecycle );
            $this->assertSame( 'effect_applied', $outcome );
            $code = 'spam_guidance_rationale_generation_effect_applied';
            $description = self::SPAM_GUIDANCE_FACET_EFFECT_DESCRIPTION;
        }
        elseif ( 'source_contract_rejection' === $outcome )
        {
            $this->assertSame( 'intentional_unsupported', $row['support_status'] ?? null );
            $code = implode( '_', [ $action, $source, $lifecycle, 'source_contract_rejection' ] );
            $description = "{$action} on {$source} at {$lifecycle} is rejected as intentionally unsupported before provider execution.";
        }
        elseif ( 'validation' === $lifecycle && 'effect_applied' === $outcome )
        {
            $native = $row['lifecycles'][ $lifecycle ]['native_effects'] ?? [];
            $without = 'spam_detection_v1' === $action
                ? ( in_array( 'native_spam_state', $native, true ) ? 'native spam state' : 'blocking validation errors' )
                : ( in_array( 'form_errors', $native, true ) ? 'field or form errors' : 'field errors' );
            $code = implode( '_', [ $action, $source, $lifecycle, 'accepted' ] );
            $description = "{$action} on {$source} at {$lifecycle} accepts the submission without applying {$without}.";
        }
        else
        {
            $contract = $row['lifecycles'][ $lifecycle ] ?? null;
            $this->assertIsArray( $contract );
            $native = $contract['native_effects'] ?? [];
            $code = implode( '_', [ $action, $source, $lifecycle, 'effect_applied' ] );
            if ( [] !== $native )
            {
                $description = "{$action} on {$source} at {$lifecycle} applies native effects " . implode( ', ', $native ) . '.';
            }
            else
            {
                $this->assertTrue( $contract['requires_submission_ledger'] ?? false );
                $description = "{$action} on {$source} at {$lifecycle} records the outcome in the Submission Ledger.";
            }
        }

        return [ 'code' => $code, 'description' => $description, 'description_sha256' => hash( 'sha256', $description ) ];
    }

    /** @return array<string, mixed> */
    private function exercise_action_facet_assignment(): array
    {
        $this->assertSame( 'spam_guidance_rationale_generation', $this->assignment['facet_scenario_assignment'] );
        $this->assertNull( $this->assignment['provider_route_assignment'] ?? null );
        $this->assertSame( 'action_facet_catalog', $this->assignment['policy_basis_assignment'] ?? null );
        $this->assertSame( 'active_subscription', $this->assignment['effective_feature_access_assignment'] ?? null );
        $this->assertSame( 'provider_flexible', $this->assignment['effective_execution_requirement_assignment'] ?? null );

        Sentient_Forms_Installer::maybe_upgrade();
        $administrator = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $administrator );
        $form_id  = (string) self::factory()->post->create( [ 'post_title' => 'Exact-artifact Spam Guidance form' ] );
        $entry_id = (string) self::factory()->post->create( [ 'post_title' => 'Exact-artifact Spam Guidance entry' ] );
        $fixture_request_id = wp_generate_uuid4();

        $this->assertTrue( class_exists( 'GFAPI' ) );
        $this->assertTrue( property_exists( 'GFAPI', 'forms' ) );
        $this->assertTrue( property_exists( 'GFAPI', 'entries' ) );
        GFAPI::$forms[ (int) $form_id ] = [
            'id'     => (int) $form_id,
            'title'  => 'Exact-artifact Spam Guidance form',
            'fields' => [
                [ 'id' => '1', 'label' => 'Email' ],
                [ 'id' => '2', 'label' => 'Message' ],
            ],
        ];
        GFAPI::$entries[ (int) $entry_id ] = [
            'id'           => (int) $entry_id,
            'form_id'      => (int) $form_id,
            'status'       => 'active',
            'date_created' => gmdate( 'Y-m-d H:i:s' ),
            '1'            => self::CONTROLLED_GRAVITY_EMAIL,
            '2'            => 'Please quote a warranty repair for this exact-artifact proof.',
        ];

        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_status' => 'active',
                'proxy_api_key'  => 'exact-artifact-spam-guidance-proxy',
                'site_id'        => 'exact-artifact-spam-guidance-site',
                'tier'           => 'starter',
            ]
        );
        global $wpdb;
        $vault     = new Sentient_Forms_Provider_Credential_Vault();
        $encrypted = $vault->encrypt( 'sk-or-exact-artifact-boundary-fixture' );
        $this->assertIsString( $encrypted );
        $credential_id = ( new Sentient_Forms_Provider_Credentials_Repository( $wpdb ) )->create(
            [
                'provider'          => 'openrouter',
                'label'             => 'Exact-artifact paid Direct boundary',
                'auth_mode'         => 'manual_key',
                'encrypted_secret'  => $encrypted,
                'status'            => 'valid',
                'status_json'       => [ 'is_free_tier' => false ],
                'last_validated_at' => current_time( 'mysql' ),
            ]
        );
        $this->assertIsInt( $credential_id );

        $billing_filter = static fn (): array => [
            'status'  => 'active',
            'plan'    => [ 'code' => 'starter' ],
            'billing' => [
                'managed_enabled' => true,
                'subscription'    => [ 'status' => 'active' ],
            ],
            'credits' => [ 'current_balance' => 0 ],
        ];
        $provider_calls          = 0;
        $provider_observation_id = null;
        $provider_filter = static function () use ( $fixture_request_id, &$provider_calls, &$provider_observation_id ): array {
            ++$provider_calls;
            $provider_observation_id = 'openrouter:gen-' . $fixture_request_id;

            return [
                'id'      => 'gen-' . $fixture_request_id,
                'choices' => [
                    [
                        'message' => [
                            'content' => '{"rationale":"Specific warranty request from an authenticated historical entry."}',
                        ],
                    ],
                ],
            ];
        };
        add_filter( 'sentient_forms_spam_guidance_billing_state', $billing_filter );
        add_filter( 'sentient_forms_spam_guidance_openrouter_generation_response', $provider_filter );

        $route = '/sentient-forms/v1/spam-guidance/forms/gravity_forms/' . $form_id . '/examples';
        $body  = [
            'target_scope' => 'form',
            'label'        => 'ham',
            'entry_id'     => $entry_id,
        ];

        try
        {
            $controller = new Sentient_Forms_Spam_Guidance_Controller();
            add_action( 'rest_api_init', [ $controller, 'register_routes' ] );
            try
            {
                do_action( 'rest_api_init', rest_get_server() );
            }
            finally
            {
                remove_action( 'rest_api_init', [ $controller, 'register_routes' ] );
            }

            $denied_request = new WP_REST_Request( 'POST', $route );
            $denied_request->set_param( 'form_source', 'gravity_forms' );
            $denied_request->set_param( 'form_id', $form_id );
            $denied_request->set_body_params( $body );
            $denied_response = rest_get_server()->dispatch( $denied_request );

            $this->assertSame( 403, $denied_response->get_status(), wp_json_encode( $denied_response->get_data() ) );
            $this->assertSame( 'rest_forbidden', $denied_response->get_data()['code'] ?? null );
            $this->assertSame( 0, $provider_calls, 'A request without the required REST nonce must not reach the provider boundary.' );
            $this->assertEmpty( get_option( 'sentient_forms_form_config_gravity_forms_' . $form_id, [] ) );

            $request = new WP_REST_Request( 'POST', $route );
            $request->set_param( 'form_source', 'gravity_forms' );
            $request->set_param( 'form_id', $form_id );
            $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
            $request->set_body_params( $body );

            $response = rest_get_server()->dispatch( $request );
        }
        finally
        {
            remove_filter( 'sentient_forms_spam_guidance_billing_state', $billing_filter );
            remove_filter( 'sentient_forms_spam_guidance_openrouter_generation_response', $provider_filter );
        }

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 1, $provider_calls );
        $data = $response->get_data();
        $this->assertSame( 'openrouter', $data['generation']['route'] ?? null );
        $this->assertSame( 'openrouter:gen-' . $fixture_request_id, $provider_observation_id );
        $this->assertSame(
            'Specific warranty request from an authenticated historical entry.',
            $data['config']['spam_positive_examples'][0]['rationale'] ?? null
        );
        $this->assertStringContainsString(
            self::CONTROLLED_GRAVITY_EMAIL,
            $data['config']['spam_positive_examples'][0]['text'] ?? '',
            'The facet observation must retain the controlled submitted email identity.'
        );

        return [
            'request_trace_id'          => null,
            'rejection_trace_id'        => null,
            'submission_id'             => $entry_id,
            'execution_id'              => null,
            'lifecycle_id'              => null,
            'provider_observation_type' => 'automated_public_seam',
            'provider_observation_id'   => 'public-seam:' . $provider_observation_id,
            'observed_provider_route'   => 'openrouter',
            'applied_facets'            => [ 'spam_guidance_rationale_generation' ],
        ];
    }

    /** @return array<string, mixed> */
    private function exercise_concrete_validation_hook(): array
    {
        $mode = self::validation_mode_for_semantic_outcome(
            $this->assignment['required_semantic_outcome']
        );
        $result = Sentient_Forms_Test_Exact_Artifact_Validation_Scenario::run(
            $this->assignment['form_source'],
            $this->assignment['action_code'],
            $mode
        );
        $this->assertSame( $this->assignment['form_source'], $result['form_source'] ?? null );
        $this->assertSame( $this->assignment['action_code'], $result['action_code'] ?? null );
        $this->assertSame( $mode, $result['assignment'] ?? null );
        $expected_rejection = 'validation_rejection' === $this->assignment['required_semantic_outcome'];
        $this->assertSame( $expected_rejection, $result['rejected'] ?? null );
        $this->assertSame( 'reject' === $mode, $result['effect_applied'] ?? null );
        $this->assertSame( 1, $result['provider_calls'] ?? null );
        $this->assertNotEmpty( $result['native_hook'] ?? null );
        $this->assertIsArray( $result['native_hooks'] ?? null );
        $this->assertContains( $result['native_hook'], $result['native_hooks'] );
        $this->assertNotEmpty( $result['observed_effect'] ?? null );
        if ( 'validation_effect_applied' === $this->assignment['required_semantic_outcome'] )
        {
            $expected_effect = 'gravity_forms' === $this->assignment['form_source']
                ? 'gravity_forms_native_spam_state_applied'
                : 'contact_form_7_spam_flagged';
            $this->assertTrue( $result['spam_state_applied'] ?? false );
            $this->assertSame( $expected_effect, $result['observed_effect'] ?? null );
            $this->assertNull( $result['trace_id'] ?? null );
        }

        $request_id = is_string( $result['request_id'] ?? null ) && '' !== $result['request_id']
            ? $result['request_id']
            : wp_generate_uuid4();

        return [
            'request_trace_id'   => is_string( $result['request_id'] ?? null ) ? $result['request_id'] : null,
            'rejection_trace_id' => is_string( $result['trace_id'] ?? null ) ? $result['trace_id'] : null,
            'submission_id'      => null,
            'execution_id'       => is_string( $result['request_id'] ?? null ) ? $result['request_id'] : null,
            'lifecycle_id'       => null,
            'provider_observation_type' => 'automated_public_seam',
            'provider_observation_id'   => 'public-seam:' . $request_id,
            'observed_provider_route'   => null,
            'applied_facets'            => [],
        ];
    }

    private static function validation_mode_for_semantic_outcome( string $semantic_outcome ): string
    {
        return in_array( $semantic_outcome, [ 'validation_rejection', 'validation_effect_applied' ], true )
            ? 'reject'
            : 'accept';
    }

    /** @return array<string, mixed> */
    private function exercise_accepted_submission_runner(): array
    {
        Sentient_Forms_Installer::maybe_upgrade();
        $source  = $this->assignment['form_source'];
        $action  = $this->assignment['action_code'];
        $form_id = (string) self::factory()->post->create(
            [ 'post_title' => 'Exact-artifact accepted form identity' ]
        );
        $hooks = [
            'gravity_forms'       => 'gform_after_submission',
            'contact_form_7'      => 'wpcf7_mail_sent',
            'wpforms'             => 'wpforms_process_complete',
            'elementor_pro_forms' => 'elementor_pro/forms/new_record',
        ];
        $registry_adapter = Sentient_Forms_Plugin::instance()->get_form_adapter_registry()->get_adapter_by_id( $source );
        $this->assertIsObject( $registry_adapter );
        $this->assertTrue( method_exists( $registry_adapter, 'get_capability_descriptor' ) );
        $descriptor = $registry_adapter->get_capability_descriptor();

        $administrator = self::factory()->user->create( [ 'role' => 'administrator' ] );
        global $wpdb;
        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $enabled = $settings->set_enabled( $source, $form_id, true, $administrator );
        $this->assertIsArray( $enabled );

        $mapping_id = $this->create_sync_bundled_mapping( $source, $form_id, $action );
        $provider_result = [
            'status'      => 'succeeded',
            'result_data' => [
                'structured_output_valid' => true,
                'structured_output'       => $this->structured_fixture( $action ),
            ],
            'meta'        => [ 'fixture_action_code' => $action ],
        ];
        $boundary = new Sentient_Forms_Test_Exact_Artifact_Execution_Boundary( $provider_result );
        $runner   = new Sentient_Forms_Form_Source_Workflow_Runner(
            Sentient_Forms_Plugin::instance(),
            null,
            null,
            $boundary
        );
        $adapter = new Sentient_Forms_Test_Exact_Artifact_Accepted_Adapter(
            $source,
            $form_id,
            $hooks[ $source ],
            $descriptor,
            'gravity_forms' === $source
                ? (string) self::factory()->post->create( [ 'post_title' => 'Exact-artifact accepted entry identity' ] )
                : null
        );
        $result = $runner->run_accepted_submission_with_outcome( $adapter, [ 'fixture' => true ] );
        $submission_uuid = $result->get_submission_uuid();
        $runtime_key = 'local_first_' . $mapping_id;

        $this->assertNotEmpty( $submission_uuid );
        $this->assertSame( 'succeeded', $result->get_mapping_outcomes()[ $runtime_key ] ?? null );
        $execution_result = $result->get_execution_result( $runtime_key );
        $this->assertIsArray( $execution_result );
        $this->assertSame( $action, $execution_result['meta']['fixture_action_code'] ?? null );
        $this->assertCount( 1, $boundary->calls );
        $request_id = $boundary->calls[0]['context']['execution_request_id'] ?? null;
        $this->assertIsString( $request_id );
        $this->assertNotSame( '', $request_id );
        $event = ( new Sentient_Forms_Execution_Events_Repository( $wpdb ) )->get_by_request_id( $request_id );
        $this->assertIsArray( $event );
        $this->assertSame( 'succeeded', $event['status'] ?? null );
        $this->assertSame( $action, $event['action_code'] ?? null );
        $this->assertSame( $source, $event['form_source'] ?? null );
        $this->assertSame( $submission_uuid, $event['submission_uuid'] ?? null );
        $ledger = ( new Sentient_Forms_Submission_Ledger_Repository( $wpdb ) )->get_by_submission_uuid( $submission_uuid );
        $this->assertIsArray( $ledger );
        $this->assertSame( $source, $ledger['form_source'] ?? null );
        $this->assertSame( $form_id, $ledger['form_id'] ?? null );
        $this->assertSame( 'Exact artifact behavioral fixture.', $ledger['logical_fields_json']['message'] ?? null );

        $manifest_row = ( new Sentient_Forms_Action_Source_Compatibility_Manifest() )->get( $action, $source );
        $this->assertIsArray( $manifest_row );
        $contract = $manifest_row['lifecycle_contracts']['after_submission'] ?? null;
        $this->assertIsArray( $contract );
        if ( 'gravity_forms' === $source )
        {
            $this->assertNotEmpty( $contract['native_effects'] ?? [] );
            $this->assert_gravity_native_result_effect( $action, $form_id );
        }
        else
        {
            $this->assertTrue( $contract['requires_submission_ledger'] ?? false );
            $this->assertGreaterThan( 0, $ledger['id'] ?? 0 );
        }

        return [
            'request_trace_id'   => $request_id,
            'rejection_trace_id' => null,
            'submission_id'      => $submission_uuid,
            'execution_id'       => $request_id,
            'lifecycle_id'       => null,
            'provider_observation_type' => 'automated_public_seam',
            'provider_observation_id'   => 'public-seam:' . $request_id,
            'observed_provider_route'   => null,
            'applied_facets'            => [],
        ];
    }

    private function create_sync_bundled_mapping( string $source, string $form_id, string $action ): int
    {
        global $wpdb;
        $catalog = Sentient_Forms_Bundled_Action_Templates::get( $action );
        $this->assertIsArray( $catalog );
        $template_id = ( new Sentient_Forms_Action_Templates_Repository( $wpdb ) )->upsert_by_code(
            [
                'source'                   => 'bundled',
                'code'                     => $action,
                'display_name'             => $catalog['display_name'],
                'description'              => $catalog['description'] ?? null,
                'prompt_template'          => $catalog['prompt_template'],
                'default_model'            => $catalog['default_model'] ?? null,
                'structured_output_schema' => $catalog['structured_output_schema'] ?? null,
                'override_schema'          => $catalog['override_schema'] ?? null,
                'version'                  => $catalog['version'] ?? '1',
                'is_active'                => true,
            ]
        );
        $this->assertIsInt( $template_id );
        $custom_action_id = ( new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb ) )->upsert_by_code(
            [
                'code'                 => Sentient_Forms_Bundled_Action_Templates::build_managed_custom_action_code( $action ),
                'display_name'         => $catalog['display_name'],
                'template_id'          => $template_id,
                'definition_json'      => [ 'template_code' => $action ],
                'model_selection_json' => [ 'provider' => 'openrouter', 'model' => 'openrouter/auto' ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $custom_action_id );
        $mapping_id = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->create(
            [
                'form_source'         => $source,
                'form_id'             => $form_id,
                'hook'                => 'after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $custom_action_id,
                'input_bindings_json' => [],
                'execution_mode'      => 'sync',
                'settings_json'       => [ 'dispatch_mode' => 'sync', 'async' => false ],
                'effect_mapping_json' => $catalog['effect_mapping_json'] ?? [],
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        return $mapping_id;
    }

    /** @return array<string, mixed> */
    private function structured_fixture( string $action ): array
    {
        return [
            'classification'  => 'spam',
            'confidence'      => 0.99,
            'summary'         => 'Exact artifact fixture completed.',
            'message'         => 'Exact artifact validation result.',
            'sentiment'       => 'positive',
            'urgency'         => 'normal',
            'status'          => 'complete',
            'intent'          => 'request_information',
            'buying_stage'    => 'consideration',
            'route_to'        => 'support',
            'priority'        => 'normal',
            'recommendation'  => 'Route to support.',
            'severity'        => 'none',
            'needs_review'    => false,
            'staff_warning'   => 'No safety issue.',
            'grade'           => 'B',
            'fit_summary'     => 'The request is a suitable fit.',
            'recommended_priority' => 'normal',
            'profile_version' => 1,
            'next_best_action' => 'Review the request.',
            'suggested_reply_draft' => 'Thank you for your submission.',
            'do_not_send'      => true,
            'action_code'     => $action,
        ];
    }

    private function assert_gravity_native_result_effect( string $action, string $form_id ): void
    {
        require_once __DIR__ . '/fixtures/exact-artifact/class-sentient-forms-test-exact-artifact-gravity-runtime.php';
        $this->assertTrue( class_exists( 'GFAPI' ) );
        $this->assertTrue( property_exists( 'GFAPI', 'entries' ) );
        $entry_id = self::factory()->post->create( [ 'post_title' => 'Exact-artifact native effect identity' ] );
        GFAPI::$entries[ $entry_id ] = [
            'id'      => $entry_id,
            'form_id' => absint( $form_id ),
            'status'  => 'active',
        ];
        $catalog = Sentient_Forms_Bundled_Action_Templates::get( $action );
        $this->assertIsArray( $catalog );
        $effects = ( new Sentient_Forms_Local_Result_Applier() )->apply(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => $form_id,
                'effect_mapping_json' => $catalog['effect_mapping_json'] ?? [],
            ],
            [ 'id' => $form_id, 'title' => 'Exact-artifact form' ],
            [ 'id' => $entry_id ],
            [
                'execution_request_id' => wp_generate_uuid4(),
                'status'               => 'succeeded',
                'result'               => [
                    'content'    => 'Exact artifact fixture completed.',
                    'structured' => $this->structured_fixture( $action ),
                ],
            ]
        );
        $this->assertIsArray( $effects );
        $this->assertContains( 'store_result', $effects['applied'] ?? [] );
        if ( 'spam_detection_v1' === $action )
        {
            $this->assertContains( 'mark_as_spam', $effects['applied'] ?? [] );
            $this->assertSame( 'spam', GFAPI::$entries[ $entry_id ]['status'] ?? null );
        }
        else
        {
            $this->assertContains( 'entry_note', $effects['applied'] ?? [] );
        }
        $this->assertNotEmpty( gform_get_meta( $entry_id, 'sentient_forms_last_response' ) );
    }

    /** @return array<string, mixed> */
    private function exercise_gravity_realtime_persistence(): array
    {
        require_once __DIR__ . '/fixtures/exact-artifact/class-sentient-forms-test-exact-artifact-gravity-runtime.php';
        $this->assertSame( 'gravity_forms', $this->assignment['form_source'] );
        $adapter = Sentient_Forms_Plugin::instance()->get_form_adapter_registry()->get_adapter_by_id( 'gravity_forms' );
        $this->assertInstanceOf( Sentient_Forms_Realtime_Adapter_Interface::class, $adapter );
        $capabilities = $adapter->get_structural_realtime_capabilities();
        $this->assertTrue( $capabilities['qna_storage'] ?? false );
        $definition = Sentient_Forms_Bundled_Action_Templates::get( 'clarification_assistant_v1' );
        $this->assertIsArray( $definition );
        $effective = ( new Sentient_Forms_Action_Policy_Resolver() )->resolve_action_definition( $definition, [] );
        $this->assertIsArray( $effective );
        $this->assertContains( 'real_time', $effective['eligible_lifecycles'] );
        $attested = ( new Sentient_Forms_Action_Policy_Preflight() )->attest(
            $effective,
            'clarification_assistant_v1',
            [ 'lifecycle' => 'real_time', 'form_source' => 'gravity_forms' ]
        );
        $this->assertTrue( true === $attested, is_wp_error( $attested ) ? $attested->get_error_message() : '' );

        $this->assertTrue( class_exists( 'GFAPI' ) );
        $this->assertTrue( property_exists( 'GFAPI', 'forms' ) );
        $this->assertTrue( property_exists( 'GFAPI', 'entries' ) );
        $form_id          = self::factory()->post->create( [ 'post_title' => 'Exact-artifact realtime form identity' ] );
        $entry_id         = self::factory()->post->create( [ 'post_title' => 'Exact-artifact realtime entry identity' ] );
        $storage_field_id = (string) ( 100 + ( $form_id % 100 ) );
        $mapping_id       = wp_generate_uuid4();
        $request_id       = wp_generate_uuid4();
        $submitted_at     = gmdate( 'Y-m-d\TH:i:s\Z', time() - 2 );
        $returned_at      = gmdate( 'Y-m-d\TH:i:s\Z' );

        GFAPI::$forms[ $form_id ] = [
            'id'     => $form_id,
            'title'  => 'Exact-artifact realtime form',
            'fields' => [
                (object) [
                    'id'    => 1,
                    'type'  => 'text',
                    'label' => 'Name',
                ],
                (object) [
                    'id'                           => (int) $storage_field_id,
                    'type'                         => 'hidden',
                    'label'                        => 'Sentient Forms Realtime Q&A',
                    'adminLabel'                   => 'Sentient Forms Realtime Q&A',
                    'inputName'                    => 'sentient_forms_realtime_qna',
                    'cssClass'                     => 'sentient-forms-realtime-qna-storage',
                    'sentientFormsRealtimeStorage' => true,
                ],
            ],
        ];
        GFAPI::$entries[ $entry_id ] = [
            'id'           => $entry_id,
            'form_id'      => $form_id,
            'date_created' => gmdate( 'Y-m-d H:i:s', time() - 2 ),
            '1'            => 'Exact artifact visitor',
            $storage_field_id => '',
            'status'       => 'active',
        ];

        $adapter->finalize_async_success(
            [
                'entry_id'             => $entry_id,
                'form_id'              => $form_id,
                'central_action_id'    => 'clarification_assistant_v1',
                'action_name_label'    => 'Real-time Clarification Assistant',
                'mapping_id'           => $mapping_id,
                'execution_request_id' => $request_id,
                'submitted_at'         => $submitted_at,
                'settings'             => [
                    'realtime_settings' => [
                        'storage_target_field_id' => $storage_field_id,
                        'pre_submit_timeout_ms'   => 1,
                    ],
                ],
                'suggestion_context'   => [ 'request_reason' => 'pre_submit' ],
            ],
            [
                'status'      => 'succeeded',
                'result_data' => [
                    'structured_output_valid' => true,
                    'structured_output'       => [
                        'virtual_questions' => [
                            [
                                'question_id'     => 'exact-artifact-question',
                                'question'        => 'What result would make this submission successful?',
                                'reason'          => 'The answer makes the request actionable.',
                                'target_field_id' => '1',
                                'required'        => true,
                                'answer_type'     => 'short_text',
                            ],
                        ],
                    ],
                ],
                'meta'        => [
                    'returned_at'          => $returned_at,
                    'execution_request_id' => $request_id,
                ],
            ]
        );

        $stored = json_decode( (string) ( GFAPI::$entries[ $entry_id ][ $storage_field_id ] ?? '' ), true );
        $this->assertIsArray( $stored );
        $this->assertSame( 'sentient_forms_realtime_clarification_qna.v1', $stored['schema'] ?? null );
        $this->assertSame( $mapping_id, $stored['mappings'][0]['mapping_id'] ?? null );
        $this->assertSame( $request_id, $stored['mappings'][0]['execution_request_id'] ?? null );
        $this->assertSame(
            'What result would make this submission successful?',
            $stored['mappings'][0]['questions'][0]['question'] ?? null
        );

        // The callback fixture proves persisted payload correlation, but it does not
        // execute the realtime request-creation seam. Identify only this automated
        // public-seam observation, not the fixture-created provider correlation.
        $public_seam_id = wp_generate_uuid4();
        return [
            'request_trace_id'   => null,
            'rejection_trace_id' => null,
            'submission_id'      => null,
            'execution_id'       => null,
            'lifecycle_id'       => null,
            'provider_observation_type' => 'automated_public_seam',
            'provider_observation_id'   => 'public-seam:' . $public_seam_id,
            'observed_provider_route'   => null,
            'applied_facets'            => [],
        ];
    }

    /** @return array<string, mixed> */
    private function exercise_authenticated_source_contract_rejection(): array
    {
        $source = $this->assignment['form_source'];
        $this->assertContains( $source, [ 'contact_form_7', 'wpforms', 'elementor_pro_forms' ] );
        $administrator = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $administrator );
        $form_id = $this->create_source_contract_probe_form( $source );
        $controller = new Sentient_Forms_Form_Actions_Controller();
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/' . $source . '/forms/' . $form_id . '/actions' );
        $request->set_param( 'form_source_slug', $source );
        $request->set_param( 'form_id', $form_id );
        $request->set_param( 'central_action_id', 'clarification_assistant_v1' );
        // The REST write contract still accepts the historical request indicator and
        // immediately materializes a plugin-owned local Action. This exercises that
        // public compatibility boundary without restoring remote Action authority.
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'real_time' ] );
        $request->set_param( 'settings', [ 'execution_mode' => 'real_time' ] );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        try
        {
            $permission = $controller->permissions_check_for_form_source_and_id( $request );
            $this->assertTrue( true === $permission );
            global $wpdb;
            $repository = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
            $before = $repository->list_for_form( $source, $form_id );
            $response = $controller->add_form_action( $request );
            $after = $repository->list_for_form( $source, $form_id );
        }
        finally
        {
            $this->remove_source_contract_probe_filters();
        }
        $this->assertWPError( $response );
        $this->assertContains(
            $response->get_error_code(),
            [ 'rest_unsupported_form_source_lifecycle', 'rest_unsupported_action_source' ]
        );
        $this->assertSame( $before, $after, 'Rejected source contracts must not create a mapping.' );

        return [
            'request_trace_id'          => null,
            'rejection_trace_id'        => null,
            'submission_id'             => null,
            'execution_id'              => null,
            'lifecycle_id'              => null,
            'provider_observation_type' => 'source_contract_rejection',
            'provider_observation_id'   => 'source-rejection:' . wp_generate_uuid4(),
            'observed_provider_route'   => null,
            'applied_facets'            => [],
        ];
    }

    private function create_source_contract_probe_form( string $source ): string
    {
        if ( 'contact_form_7' === $source )
        {
            $probe_id = self::factory()->post->create(
                [ 'post_title' => 'Exact-artifact CF7 rejection identity' ]
            );
            add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
            add_filter(
                'sentient_forms_contact_form_7_forms',
                static fn(): array => [
                    new class( $probe_id ) {
                        public function __construct( private int $probe_id )
                        {
                        }

                        public function id(): int
                        {
                            return $this->probe_id;
                        }

                        public function title(): string
                        {
                            return 'Exact-artifact CF7 rejection probe';
                        }
                    },
                ]
            );
            add_filter(
                'sentient_forms_contact_form_7_form_object',
                static fn( mixed $form, mixed $form_id ): mixed => $probe_id === absint( $form_id )
                    ? new class( $probe_id ) {
                        public function __construct( private int $probe_id )
                        {
                        }

                        public function id(): int
                        {
                            return $this->probe_id;
                        }

                        public function title(): string
                        {
                            return 'Exact-artifact CF7 rejection probe';
                        }
                    }
                    : $form,
                10,
                2
            );

            return (string) $probe_id;
        }

        if ( 'wpforms' === $source )
        {
            add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );
            return (string) self::factory()->post->create(
                [
                    'post_type'    => 'wpforms',
                    'post_status'  => 'publish',
                    'post_title'   => 'Exact-artifact WPForms rejection probe',
                    'post_content' => wp_json_encode(
                        [
                            'settings' => [ 'form_title' => 'Exact-artifact WPForms rejection probe' ],
                            'fields'   => [],
                        ]
                    ),
                ]
            );
        }

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );
        $page_id = self::factory()->post->create(
            [
                'post_type'   => 'page',
                'post_status' => 'publish',
                'post_title'  => 'Exact-artifact Elementor rejection probe',
            ]
        );
        update_post_meta(
            $page_id,
            '_elementor_data',
            wp_slash(
                wp_json_encode(
                    [
                        [
                            'id'       => 'container-exact-artifact',
                            'elType'   => 'container',
                            'settings' => [],
                            'elements' => [
                                [
                                    'id'         => 'form-exact-artifact',
                                    'elType'     => 'widget',
                                    'widgetType' => 'form',
                                    'settings'   => [
                                        'form_name'   => 'Exact-artifact Elementor rejection probe',
                                        'form_fields' => [],
                                    ],
                                    'elements'   => [],
                                ],
                            ],
                        ],
                    ]
                )
            )
        );
        add_filter( 'sentient_forms_elementor_posts_with_data', static fn(): array => [ $page_id ] );

        return $page_id . ':form-exact-artifact';
    }

    private function remove_source_contract_probe_filters(): void
    {
        remove_all_filters( 'sentient_forms_contact_form_7_is_active' );
        remove_all_filters( 'sentient_forms_contact_form_7_forms' );
        remove_all_filters( 'sentient_forms_contact_form_7_form_object' );
        remove_all_filters( 'sentient_forms_wpforms_is_active' );
        remove_all_filters( 'sentient_forms_elementor_is_active' );
        remove_all_filters( 'sentient_forms_elementor_pro_forms_api_available' );
        remove_all_filters( 'sentient_forms_elementor_posts_with_data' );
    }

    /** @param array<string, mixed> $assignment */
    private static function build_test_id( array $assignment ): string
    {
        return implode(
            ':',
            [
                'phpunit-public-seam',
                $assignment['action_code'],
                $assignment['form_source'],
                $assignment['lifecycle'],
                $assignment['required_semantic_outcome'],
                $assignment['facet_scenario_assignment'],
                $assignment['policy_basis_assignment'],
            ]
        );
    }

    /** @param array<string, mixed> $identities */
    private function write_observation( array $identities ): void
    {
        $observation = [
            'observation_version'   => 1,
            'run_id'                => $this->assignment['id'],
            'status'                => 'passed',
            'test_id'               => self::build_test_id( $this->assignment ),
            'observed_effect'       => $this->expected_effect['description'],
            'observed_effect_code'  => $this->expected_effect['code'],
            'observed_effect_sha256' => $this->expected_effect['description_sha256'],
            'request_trace_id'      => $identities['request_trace_id'],
            'rejection_trace_id'    => $identities['rejection_trace_id'],
            'submission_id'         => $identities['submission_id'],
            'execution_id'          => $identities['execution_id'],
            'lifecycle_id'          => $identities['lifecycle_id'],
            'provider_observation_type' => $identities['provider_observation_type'] ?? null,
            'provider_observation_id'   => $identities['provider_observation_id'] ?? null,
            'observed_provider_route'   => $identities['observed_provider_route'] ?? null,
            'applied_facets'            => $identities['applied_facets'] ?? [],
        ];
        $handle = fopen( $this->observation_path, 'x' );
        $this->assertIsResource( $handle );
        try
        {
            $written = fwrite( $handle, wp_json_encode( $observation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
            $this->assertGreaterThan( 0, $written );
        }
        finally
        {
            fclose( $handle );
        }
    }
}
