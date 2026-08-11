<?php

/**
 * Assignment-driven behavioral evidence for the exact-artifact run plan.
 *
 * @package Sentient_Forms
 */

require_once __DIR__ . '/fixtures/exact-artifact/class-sentient-forms-test-exact-artifact-validation-scenario.php';
require_once dirname( __DIR__, 2 ) . '/scripts/check-action-facet-policy-snapshot.php';

final class Sentient_Forms_Test_Exact_Artifact_OpenRouter_Client implements Sentient_Forms_Provider_Client_Interface
{
    /** @var array<int, array{api_key:string,payload:array<string,mixed>,options:array<string,mixed>}> */
    public array $chat_calls = [];

    /** @param array<string, mixed>|string $response_content */
    public function __construct( private array | string $response_content )
    {
    }

    public function validate_key( string $api_key ): array | WP_Error
    {
        return [ 'data' => [ 'label' => 'Exact-artifact test key' ] ];
    }

    public function chat_completion( string $api_key, array $payload, array $options = [] ): array | WP_Error
    {
        $this->chat_calls[] = compact( 'api_key', 'payload', 'options' );

        return [
            'id'      => 'chatcmpl-exact-artifact-public-seam',
            'model'   => $payload['model'] ?? 'openrouter/auto',
            'choices' => [
                [
                    'message'       => [
                        'role'    => 'assistant',
                        'content' => is_string( $this->response_content )
                            ? $this->response_content
                            : wp_json_encode( $this->response_content ),
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage'   => [
                'prompt_tokens'     => 8,
                'completion_tokens' => 5,
                'total_tokens'      => 13,
            ],
        ];
    }
}

final class Sentient_Forms_Test_Exact_Artifact_Model_Selection_Service extends Sentient_Forms_Local_Action_Model_Selection_Service
{
    public function __construct( private int $fixture_credential_id )
    {
        parent::__construct();
    }

    public function resolve_execution_credential( string $provider, int $credential_id = 0 ): array | WP_Error
    {
        $resolved_id = $credential_id > 0 ? $credential_id : $this->fixture_credential_id;

        return parent::resolve_execution_credential( $provider, $resolved_id );
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

    /** @var callable(resource,string):int|false|null */
    private $observation_write_callback = null;

    /** @var callable(string,string):bool|null */
    private $observation_publish_callback = null;

    public function test_exact_artifact_test_identity_distinguishes_action_facet_policy(): void
    {
        $this->assertContains( 'policy_basis_assignment', self::required_assignment_keys() );

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
        $this->assertSame(
            Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION,
            Sentient_Forms_Form_Source_Lifecycles::normalize_id( 'elementor_pro/forms/new_record' )
        );
        $this->assertContains(
            'elementor_pro/forms/new_record',
            Sentient_Forms_Form_Source_Lifecycles::accepted_input_ids()
        );
        $this->assertNull( Sentient_Forms_Form_Source_Lifecycles::normalize_id( 'unrelated/forms/new_record' ) );
    }

    public function test_assignment_validation_fails_closed_without_policy_basis(): void
    {
        $this->assignment = array_fill_keys( self::required_assignment_keys(), null );
        unset( $this->assignment['policy_basis_assignment'] );
        $this->expectException( PHPUnit\Framework\AssertionFailedError::class );
        $this->expectExceptionMessage( 'policy_basis_assignment' );

        $this->verify_against_public_authorities();
    }

    public function test_assignment_validation_fails_closed_with_mismatched_policy_basis(): void
    {
        $this->assignment = array_fill_keys( self::required_assignment_keys(), null );
        $this->assignment['required_semantic_outcome'] = 'effect_applied';
        $this->assignment['facet_scenario_assignment'] = 'base_action';
        $this->assignment['policy_basis_assignment']    = 'action_facet_catalog';
        $this->expectException( PHPUnit\Framework\AssertionFailedError::class );
        $this->expectExceptionMessage( 'action_catalog' );

        $this->verify_against_public_authorities();
    }

    public function test_observation_writer_rejects_unencodable_payload_without_creating_artifact(): void
    {
        $this->assignment = [
            'id'                         => 'unencodable-observation',
            'action_code'                => 'spam_detection_v1',
            'form_source'                => 'gravity_forms',
            'lifecycle'                  => 'after_submission',
            'required_semantic_outcome'  => 'effect_applied',
            'facet_scenario_assignment'  => 'base_action',
            'policy_basis_assignment'    => 'action_catalog',
        ];
        $this->expected_effect = [
            'code'               => 'fixture_effect',
            'description'        => 'Fixture effect.',
            'description_sha256' => hash( 'sha256', 'Fixture effect.' ),
        ];
        $this->observation_path = trailingslashit( get_temp_dir() )
            . 'sentient-forms-unencodable-observation-' . wp_generate_uuid4() . '.json';
        $unencodable = fopen( 'php://memory', 'r' );
        $this->assertIsResource( $unencodable );

        try
        {
            $this->write_observation(
                [
                    'request_trace_id'          => null,
                    'rejection_trace_id'        => null,
                    'submission_id'             => null,
                    'execution_id'              => null,
                    'lifecycle_id'              => null,
                    'provider_observation_type' => 'automated_public_seam',
                    'provider_observation_id'   => 'public-seam:unencodable-observation',
                    'observed_provider_route'   => null,
                    'applied_facets'            => $unencodable,
                ]
            );
            $this->fail( 'Unencodable observation payloads must fail before creating an artifact.' );
        }
        catch ( PHPUnit\Framework\AssertionFailedError $error )
        {
            $this->assertStringContainsString(
                'Observation must be JSON-encodable before writing.',
                $error->getMessage()
            );
            $this->assertFalse( file_exists( $this->observation_path ) );
        }
        finally
        {
            fclose( $unencodable );
            if ( file_exists( $this->observation_path ) )
            {
                unlink( $this->observation_path );
            }
        }
    }

    public function test_observation_writer_rejects_short_write_without_publishing_truncated_artifact(): void
    {
        $this->configure_observation_writer_fixture( 'short-write' );
        $write_calls = 0;
        $this->observation_write_callback = static function ( $handle, string $payload ) use ( &$write_calls ): int | false {
            ++$write_calls;
            if ( 1 === $write_calls )
            {
                return fwrite( $handle, substr( $payload, 0, 7 ) );
            }

            return false;
        };

        try
        {
            $this->write_observation( $this->valid_observation_identities( 'short-write' ) );
            $this->fail( 'A short write must fail before the final observation artifact is published.' );
        }
        catch ( PHPUnit\Framework\AssertionFailedError $error )
        {
            $this->assertStringContainsString( 'complete observation payload', $error->getMessage() );
            $this->assertFalse( file_exists( $this->observation_path ) );
            $this->assertSame( [], glob( $this->observation_path . '.tmp.*' ) ?: [] );
        }
        finally
        {
            $this->observation_write_callback = null;
            if ( file_exists( $this->observation_path ) )
            {
                unlink( $this->observation_path );
            }
            foreach ( glob( $this->observation_path . '.tmp.*' ) ?: [] as $temporary_path )
            {
                unlink( $temporary_path );
            }
        }
    }

    public function test_observation_writer_cleans_staging_file_when_atomic_publication_fails(): void
    {
        $this->configure_observation_writer_fixture( 'publication-failure' );
        $this->observation_publish_callback = static fn( string $temporary_path, string $final_path ): bool => false;

        try
        {
            $this->write_observation( $this->valid_observation_identities( 'publication-failure' ) );
            $this->fail( 'A failed atomic publication must not leave a final or staging observation artifact.' );
        }
        catch ( PHPUnit\Framework\AssertionFailedError $error )
        {
            $this->assertStringContainsString( 'atomic', $error->getMessage() );
            $this->assertFalse( file_exists( $this->observation_path ) );
            $this->assertSame( [], glob( $this->observation_path . '.tmp.*' ) ?: [] );
        }
        finally
        {
            $this->observation_publish_callback = null;
            if ( file_exists( $this->observation_path ) )
            {
                unlink( $this->observation_path );
            }
            foreach ( glob( $this->observation_path . '.tmp.*' ) ?: [] as $temporary_path )
            {
                unlink( $temporary_path );
            }
        }
    }

    public function test_observation_writer_does_not_replace_existing_final_artifact(): void
    {
        $this->configure_observation_writer_fixture( 'existing-final' );
        $existing = "existing-authoritative-artifact\n";
        $this->assertSame( strlen( $existing ), file_put_contents( $this->observation_path, $existing ) );

        try
        {
            $this->write_observation( $this->valid_observation_identities( 'existing-final' ) );
            $this->fail( 'An existing final observation artifact must never be accepted or replaced.' );
        }
        catch ( PHPUnit\Framework\AssertionFailedError $error )
        {
            $this->assertStringContainsString( 'must not replace', $error->getMessage() );
            $this->assertSame( $existing, file_get_contents( $this->observation_path ) );
            $this->assertSame( [], glob( $this->observation_path . '.tmp.*' ) ?: [] );
        }
        finally
        {
            if ( file_exists( $this->observation_path ) )
            {
                unlink( $this->observation_path );
            }
            foreach ( glob( $this->observation_path . '.tmp.*' ) ?: [] as $temporary_path )
            {
                unlink( $temporary_path );
            }
        }
    }

    /** @dataProvider accepted_submission_sources */
    public function test_after_submission_evidence_dispatches_registered_adapter_hook_through_real_execution_service(
        string $source,
        string $native_hook,
        string $adapter_class
    ): void
    {
        $this->assignment = [
            'action_code' => 'spam_detection_v1',
            'form_source' => $source,
        ];

        $identities = $this->exercise_accepted_submission_runner();

        $this->assertSame( $native_hook, $identities['registered_native_hook'] ?? null );
        $this->assertSame( $adapter_class, $identities['registered_adapter_class'] ?? null );
        $this->assertSame( 1, $identities['external_provider_call_count'] ?? null );
    }

    /** @return array<string, array{string,string,string}> */
    public function accepted_submission_sources(): array
    {
        return [
            'gravity_forms'       => [ 'gravity_forms', 'gform_after_submission', Sentient_Forms_Gravity_Forms_Adapter::class ],
            'contact_form_7'      => [ 'contact_form_7', 'wpcf7_mail_sent', Sentient_Forms_Contact_Form_7_Adapter::class ],
            'wpforms'             => [ 'wpforms', 'wpforms_process_complete', Sentient_Forms_WPForms_Adapter::class ],
            'elementor_pro_forms' => [ 'elementor_pro_forms', 'elementor_pro/forms/new_record', Sentient_Forms_Elementor_Forms_Adapter::class ],
        ];
    }

    /** @dataProvider source_contract_probe_sources */
    public function test_source_contract_probe_restores_preexisting_hook_state( string $source, string $sentinel_hook ): void
    {
        $sentinel = static fn( bool $active ): bool => $active;
        add_filter( $sentinel_hook, $sentinel, 37 );
        $this->assignment = [ 'form_source' => $source ];

        try
        {
            $this->exercise_authenticated_source_contract_rejection();
            $this->assertSame( 37, has_filter( $sentinel_hook, $sentinel ) );
        }
        finally
        {
            remove_filter( $sentinel_hook, $sentinel, 37 );
        }
    }

    /** @return array<string, array{string,string}> */
    public function source_contract_probe_sources(): array
    {
        return [
            'contact_form_7'      => [ 'contact_form_7', 'sentient_forms_contact_form_7_is_active' ],
            'wpforms'             => [ 'wpforms', 'sentient_forms_wpforms_is_active' ],
            'elementor_pro_forms' => [ 'elementor_pro_forms', 'sentient_forms_elementor_is_active' ],
        ];
    }

    public function test_spam_guidance_facet_loads_gravity_runtime_when_file_runs_in_isolation(): void
    {
        $this->assignment = [
            'facet_scenario_assignment'                  => 'spam_guidance_rationale_generation',
            'policy_basis_assignment'                    => 'action_facet_catalog',
            'effective_feature_access_assignment'        => 'active_subscription',
            'effective_execution_requirement_assignment' => 'provider_flexible',
        ];

        $identities = $this->exercise_action_facet_assignment();

        $this->assertSame( 'openrouter', $identities['observed_provider_route'] ?? null );
    }

    public function test_spam_guidance_facet_restores_shared_runtime_state(): void
    {
        require_once __DIR__ . '/fixtures/exact-artifact/class-sentient-forms-test-exact-artifact-gravity-runtime.php';
        $plugin           = Sentient_Forms_Plugin::instance();
        $original_license = $plugin->get_license_data();
        $original_forms   = GFAPI::$forms;
        $original_entries = GFAPI::$entries;
        $original_user_id = get_current_user_id();
        $sentinel_user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $sentinel_user_id );
        $plugin->set_license_data(
            [
                'license_status' => 'inactive',
                'proxy_api_key'  => 'preexisting-fixture-key',
                'site_id'        => 'preexisting-fixture-site',
            ]
        );
        $expected_license = $plugin->get_license_data();
        $this->assignment = [
            'facet_scenario_assignment'                  => 'spam_guidance_rationale_generation',
            'policy_basis_assignment'                    => 'action_facet_catalog',
            'effective_feature_access_assignment'        => 'active_subscription',
            'effective_execution_requirement_assignment' => 'provider_flexible',
        ];

        try
        {
            $this->exercise_action_facet_assignment();

            $this->assertSame( $expected_license, $plugin->get_license_data() );
            $this->assertSame( $original_forms, GFAPI::$forms );
            $this->assertSame( $original_entries, GFAPI::$entries );
            $this->assertSame( $sentinel_user_id, get_current_user_id() );
        }
        finally
        {
            wp_set_current_user( $original_user_id );
            $plugin->set_license_data( $original_license );
            GFAPI::$forms   = $original_forms;
            GFAPI::$entries = $original_entries;
        }
    }

    public function test_realtime_evidence_executes_public_request_before_persisting_gravity_qna(): void
    {
        $this->assignment = [ 'form_source' => 'gravity_forms' ];

        $identities = $this->exercise_gravity_realtime_persistence();

        $this->assertIsString( $identities['request_trace_id'] ?? null );
        $this->assertSame( $identities['request_trace_id'], $identities['execution_id'] ?? null );
        $this->assertSame( 1, $identities['external_provider_call_count'] ?? null );
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

        foreach ( self::required_assignment_keys() as $key )
        {
            $this->assertArrayHasKey( $key, $this->assignment );
        }
        $this->assertNotSame( 'policy_rejection', $this->assignment['required_semantic_outcome'] );
        $facet_code = $this->assignment['facet_scenario_assignment'];
        $expected_policy_basis = 'base_action' === $facet_code ? 'action_catalog' : 'action_facet_catalog';
        $this->assertSame(
            $expected_policy_basis,
            $this->assignment['policy_basis_assignment'],
            sprintf(
                'policy_basis_assignment must be %s for facet scenario %s.',
                $expected_policy_basis,
                $facet_code
            )
        );
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

        return $this->derive_public_effect( $row );
    }

    /** @return array<int, string> */
    private static function required_assignment_keys(): array
    {
        return [
            'id', 'action_code', 'form_source', 'lifecycle', 'required_semantic_outcome',
            'facet_scenario_assignment', 'policy_basis_assignment', 'effective_feature_access_assignment',
            'effective_execution_requirement_assignment', 'effective_required_form_source_capabilities',
            'effective_required_managed_capabilities', 'effective_eligible_lifecycles',
            'effective_metering_class', 'expected_effect_code', 'expected_effect_sha256',
        ];
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
        require_once __DIR__ . '/fixtures/exact-artifact/class-sentient-forms-test-exact-artifact-gravity-runtime.php';
        $plugin           = Sentient_Forms_Plugin::instance();
        $previous_license = $plugin->get_license_data();
        $previous_forms   = GFAPI::$forms;
        $previous_entries = GFAPI::$entries;
        $previous_user_id = get_current_user_id();

        try
        {
            return $this->execute_action_facet_assignment_fixture();
        }
        finally
        {
            wp_set_current_user( $previous_user_id );
            $plugin->set_license_data( $previous_license );
            GFAPI::$forms   = $previous_forms;
            GFAPI::$entries = $previous_entries;
        }
    }

    /** @return array<string, mixed> */
    private function execute_action_facet_assignment_fixture(): array
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

        $billing_status = 'inactive';
        $billing_filter = static function () use ( &$billing_status ): array {
            return [
                'status'  => $billing_status,
                'plan'    => [ 'code' => 'starter' ],
                'billing' => [
                    'managed_enabled' => true,
                    'subscription'    => [ 'status' => $billing_status ],
                ],
                'credits' => [ 'current_balance' => 0 ],
            ];
        };
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

        $had_rest_server      = array_key_exists( 'wp_rest_server', $GLOBALS );
        $previous_rest_server = $GLOBALS['wp_rest_server'] ?? null;
        $rest_server          = new WP_REST_Server();
        $GLOBALS['wp_rest_server'] = $rest_server;

        $route = '/sentient-forms/v1/spam-guidance/forms/gravity_forms/' . $form_id . '/examples';
        $body  = [
            'target_scope' => 'form',
            'label'        => 'ham',
            'entry_id'     => $entry_id,
        ];

        try
        {
            $model_selection = new Sentient_Forms_Test_Exact_Artifact_Model_Selection_Service( $credential_id );
            $rationale_service = new Sentient_Forms_Spam_Guidance_Rationale_Service(
                null,
                null,
                null,
                $model_selection
            );
            $controller = new Sentient_Forms_Spam_Guidance_Controller( null, null, $rationale_service );
            add_action( 'rest_api_init', [ $controller, 'register_routes' ], 0 );
            try
            {
                do_action( 'rest_api_init', $rest_server );
            }
            finally
            {
                remove_action( 'rest_api_init', [ $controller, 'register_routes' ], 0 );
            }

            $denied_request = new WP_REST_Request( 'POST', $route );
            $denied_request->set_param( 'form_source', 'gravity_forms' );
            $denied_request->set_param( 'form_id', $form_id );
            $denied_request->set_body_params( $body );
            $denied_response = $rest_server->dispatch( $denied_request );

            $this->assertSame( 403, $denied_response->get_status(), wp_json_encode( $denied_response->get_data() ) );
            $this->assertSame( 'rest_forbidden', $denied_response->get_data()['code'] ?? null );
            $this->assertSame( 0, $provider_calls, 'A request without the required REST nonce must not reach the provider boundary.' );
            $this->assertEmpty( get_option( 'sentient_forms_form_config_gravity_forms_' . $form_id, [] ) );

            $inactive_request = new WP_REST_Request( 'POST', $route );
            $inactive_request->set_param( 'form_source', 'gravity_forms' );
            $inactive_request->set_param( 'form_id', $form_id );
            $inactive_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
            $inactive_request->set_body_params( $body );
            $inactive_response = $rest_server->dispatch( $inactive_request );

            $this->assertSame( 403, $inactive_response->get_status(), wp_json_encode( $inactive_response->get_data() ) );
            $this->assertSame(
                'sentient_forms_spam_rationale_subscription_required',
                $inactive_response->get_data()['code'] ?? null
            );
            $this->assertSame( 0, $provider_calls, 'An inactive subscription must not reach the provider boundary.' );
            $this->assertEmpty( get_option( 'sentient_forms_form_config_gravity_forms_' . $form_id, [] ) );

            $billing_status = 'active';
            $request = new WP_REST_Request( 'POST', $route );
            $request->set_param( 'form_source', 'gravity_forms' );
            $request->set_param( 'form_id', $form_id );
            $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
            $request->set_body_params( $body );

            $response = $rest_server->dispatch( $request );
        }
        finally
        {
            if ( $had_rest_server )
            {
                $GLOBALS['wp_rest_server'] = $previous_rest_server;
            }
            else
            {
                unset( $GLOBALS['wp_rest_server'] );
            }
            remove_filter( 'sentient_forms_spam_guidance_billing_state', $billing_filter );
            remove_filter( 'sentient_forms_spam_guidance_openrouter_generation_response', $provider_filter );
        }

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
        $this->assertSame( 1, $provider_calls );
        $data = $response->get_data();
        $this->assertSame( 'openrouter', $data['generation']['route'] ?? null );
        $this->assertSame( 'direct_ready', $data['generation']['route_decision_reason'] ?? null );
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
        $hooks = [
            'gravity_forms'       => 'gform_after_submission',
            'contact_form_7'      => 'wpcf7_mail_sent',
            'wpforms'             => 'wpforms_process_complete',
            'elementor_pro_forms' => 'elementor_pro/forms/new_record',
        ];
        $this->assertArrayHasKey( $source, $hooks );

        $all_hook_snapshots = $this->snapshot_all_hooks();
        $this->isolate_hooks(
            [
                $hooks[ $source ],
                'sentient_forms_contact_form_7_is_active',
                'sentient_forms_contact_form_7_current_submission',
                'sentient_forms_wpforms_is_active',
                'sentient_forms_elementor_is_active',
                'sentient_forms_elementor_pro_forms_api_available',
                'sentient_forms_elementor_posts_with_data',
            ]
        );

        $administrator = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $administrator );
        global $wpdb;
        try
        {
            $client = new Sentient_Forms_Test_Exact_Artifact_OpenRouter_Client( $this->provider_response_fixture( $action ) );
            $execution_service = new Sentient_Forms_Local_Action_Execution_Service(
                null,
                null,
                null,
                null,
                null,
                null,
                $client
            );
            $runner = new Sentient_Forms_Form_Source_Workflow_Runner(
                Sentient_Forms_Plugin::instance(),
                null,
                null,
                $execution_service
            );
            $fixture = $this->registered_accepted_submission_fixture( $source, $runner );
            $form_id = $fixture['form_id'];
            $adapter = $fixture['adapter'];
            $this->assertSame( 10, has_action( $hooks[ $source ], [ $adapter, $fixture['callback'] ] ) );

            $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
            $enabled  = $settings->set_enabled( $source, $form_id, 'gravity_forms' !== $source, $administrator );
            $this->assertIsArray( $enabled );
            if ( in_array( $action, [ 'lead_grading_v1', 'suggested_reply_v1' ], true ) )
            {
                $this->create_active_lead_profile_fixture( $source, $form_id );
            }
            $mapping_id = $this->create_bundled_mapping( $source, $form_id, $action );

            do_action_ref_array( $hooks[ $source ], $fixture['native_args'] );

            $ledger_repository = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
            $event_repository  = new Sentient_Forms_Execution_Events_Repository( $wpdb );
            $ledger_rows        = $ledger_repository->list_for_form( $source, $form_id, 1 );
            $ledger             = $ledger_rows[0] ?? null;
            if ( 'gravity_forms' === $source )
            {
                $this->assertSame( [], $ledger_rows, 'Gravity Forms must exercise the native-entry fallback with the Submission Ledger disabled.' );
                $this->assertIsString( $fixture['native_entry_id'] );
                $event = $event_repository->get_latest_for_entry(
                    $source,
                    $form_id,
                    $fixture['native_entry_id']
                );
                $submission_uuid = $event['submission_uuid'] ?? null;
            }
            else
            {
                $this->assertIsArray( $ledger );
                $submission_uuid = $ledger['submission_uuid'] ?? null;
                $this->assertIsString( $submission_uuid );
                $events = $event_repository->list_for_submission_uuid( $submission_uuid, 10 );
                $event  = $events[0] ?? null;
            }
            $this->assertIsArray( $event );
            $this->assertIsString( $submission_uuid );
            $this->assertSame( $mapping_id, (int) ( $event['mapping_id'] ?? 0 ) );
            $this->assertSame(
                'succeeded',
                $event['status'] ?? null,
                wp_json_encode( [ $event['error_code'] ?? null, $event['error_message'] ?? null ] )
            );
            $this->assertSame( $action, $event['action_code'] ?? null );
            $this->assertSame( $source, $event['form_source'] ?? null );
            $this->assertSame( $submission_uuid, $event['submission_uuid'] ?? null );
            $this->assertCount( 1, $client->chat_calls );

            $manifest_row = ( new Sentient_Forms_Action_Source_Compatibility_Manifest() )->get( $action, $source );
            $this->assertIsArray( $manifest_row );
            $contract = $manifest_row['lifecycle_contracts']['after_submission'] ?? null;
            $this->assertIsArray( $contract );
            if ( 'gravity_forms' === $source )
            {
                $this->assertNotEmpty( $contract['native_effects'] ?? [] );
                $this->assertSame( $fixture['native_entry_id'], $event['entry_id'] ?? null );
                $this->assert_gravity_native_result_effect( $action, $fixture['native_entry_id'], $event );
            }
            else
            {
                $this->assertTrue( $contract['requires_submission_ledger'] ?? false );
                $this->assertSame( $source, $ledger['form_source'] ?? null );
                $this->assertSame( $form_id, $ledger['form_id'] ?? null );
                $this->assertNotEmpty( $ledger['logical_fields_json'] ?? [] );
                $this->assertGreaterThan( 0, $ledger['id'] ?? 0 );
            }

            $request_id = $event['execution_request_id'] ?? null;
            $this->assertIsString( $request_id );
            $this->assertNotSame( '', $request_id );
        }
        finally
        {
            $this->restore_all_hooks( $all_hook_snapshots );
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
            'registered_native_hook'    => $hooks[ $source ],
            'registered_adapter_class'  => get_class( $adapter ),
            'external_provider_call_count' => count( $client->chat_calls ),
        ];
    }

    /**
     * @return array{adapter:object,callback:string,form_id:string,native_args:array<int,mixed>,native_entry_id:string|null}
     */
    private function registered_accepted_submission_fixture(
        string $source,
        Sentient_Forms_Form_Source_Workflow_Runner $runner
    ): array
    {
        $plugin = Sentient_Forms_Plugin::instance();
        if ( 'gravity_forms' === $source )
        {
            require_once __DIR__ . '/fixtures/exact-artifact/class-sentient-forms-test-exact-artifact-gravity-runtime.php';
            $form_id  = self::factory()->post->create( [ 'post_title' => 'Exact-artifact Gravity form' ] );
            $entry_id = self::factory()->post->create( [ 'post_title' => 'Exact-artifact Gravity entry' ] );
            $form     = [
                'id'     => $form_id,
                'title'  => 'Exact-artifact Gravity form',
                'fields' => [ [ 'id' => '1', 'label' => 'Message', 'type' => 'textarea' ] ],
            ];
            $entry = [
                'id'           => $entry_id,
                'form_id'      => $form_id,
                'status'       => 'active',
                'date_created' => gmdate( 'Y-m-d H:i:s' ),
                '1'            => 'Exact artifact behavioral fixture.',
            ];
            GFAPI::$forms[ $form_id ]   = $form;
            GFAPI::$entries[ $entry_id ] = $entry;
            $adapter = new Sentient_Forms_Gravity_Forms_Adapter( $plugin, $runner );
            $adapter->register_hooks();

            return [
                'adapter'         => $adapter,
                'callback'        => 'handle_accepted_submission',
                'form_id'         => (string) $form_id,
                'native_args'     => [ $entry, $form ],
                'native_entry_id' => (string) $entry_id,
            ];
        }

        if ( 'contact_form_7' === $source )
        {
            $form_id = self::factory()->post->create( [ 'post_title' => 'Exact-artifact CF7 form' ] );
            $form       = [ 'id' => $form_id, 'title' => 'Exact-artifact CF7 form' ];
            $submission = [ 'message' => 'Exact artifact behavioral fixture.' ];
            add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
            add_filter( 'sentient_forms_contact_form_7_current_submission', static fn(): array => $submission );
            $adapter = new Sentient_Forms_Contact_Form_7_Adapter( $plugin, $runner );
            $adapter->init();

            return [
                'adapter'         => $adapter,
                'callback'        => 'handle_mail_sent',
                'form_id'         => (string) $form_id,
                'native_args'     => [ $form ],
                'native_entry_id' => null,
            ];
        }

        if ( 'wpforms' === $source )
        {
            $form_id = self::factory()->post->create(
                [
                    'post_type'    => 'wpforms',
                    'post_status'  => 'publish',
                    'post_title'   => 'Exact-artifact WPForms form',
                    'post_content' => '{}',
                ]
            );
            $form_data = [
                'id'       => $form_id,
                'settings' => [ 'form_title' => 'Exact-artifact WPForms form' ],
                'fields'   => [ 1 => [ 'id' => 1, 'label' => 'Message', 'type' => 'textarea' ] ],
            ];
            $fields = [
                1 => [
                    'id'    => 1,
                    'name'  => 'Message',
                    'type'  => 'textarea',
                    'value' => 'Exact artifact behavioral fixture.',
                ],
            ];
            add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );
            $adapter = new Sentient_Forms_WPForms_Adapter( $plugin, $runner );
            $adapter->init();

            return [
                'adapter'         => $adapter,
                'callback'        => 'handle_process_complete',
                'form_id'         => (string) $form_id,
                'native_args'     => [ $fields, [], $form_data, 0 ],
                'native_entry_id' => null,
            ];
        }

        $form_id = $this->create_source_contract_probe_form( 'elementor_pro_forms' );
        $record = new class {
            public function get( string $key ): mixed
            {
                return 'fields' === $key
                    ? [
                        'message' => [
                            'id'    => 'message',
                            'title' => 'Message',
                            'type'  => 'textarea',
                            'value' => 'Exact artifact behavioral fixture.',
                        ],
                    ]
                    : null;
            }

            public function get_form_settings( ?string $key = null ): mixed
            {
                $settings = [ 'form_name' => 'Exact-artifact Elementor rejection probe' ];

                return null === $key ? $settings : ( $settings[ $key ] ?? null );
            }
        };
        $adapter  = new Sentient_Forms_Elementor_Forms_Adapter( $plugin );
        $property = new ReflectionProperty( Sentient_Forms_Elementor_Forms_Adapter::class, 'workflow_runner' );
        $property->setValue( $adapter, $runner );
        $adapter->init();

        return [
            'adapter'         => $adapter,
            'callback'        => 'handle_new_record',
            'form_id'         => $form_id,
            'native_args'     => [ $record, null ],
            'native_entry_id' => null,
        ];
    }

    /** @param array<int, string> $hook_names @return array<string, WP_Hook|null> */
    private function isolate_hooks( array $hook_names ): array
    {
        global $wp_filter;
        $snapshots = [];
        foreach ( array_unique( $hook_names ) as $hook_name )
        {
            $snapshots[ $hook_name ] = $wp_filter[ $hook_name ] ?? null;
            unset( $wp_filter[ $hook_name ] );
        }

        return $snapshots;
    }

    /** @param array<string, WP_Hook|null> $snapshots */
    private function restore_hooks( array $snapshots ): void
    {
        global $wp_filter;
        foreach ( $snapshots as $hook_name => $snapshot )
        {
            unset( $wp_filter[ $hook_name ] );
            if ( $snapshot instanceof WP_Hook )
            {
                $wp_filter[ $hook_name ] = $snapshot;
            }
        }
    }

    /** @return array<string, WP_Hook> */
    private function snapshot_all_hooks(): array
    {
        global $wp_filter;

        return array_map( static fn( WP_Hook $hook ): WP_Hook => clone $hook, $wp_filter );
    }

    /** @param array<string, WP_Hook> $snapshots */
    private function restore_all_hooks( array $snapshots ): void
    {
        global $wp_filter;
        $wp_filter = $snapshots;
    }

    /** @param array<string, mixed> $settings */
    private function create_bundled_mapping(
        string $source,
        string $form_id,
        string $action,
        string $hook = 'after_submission',
        string $execution_mode = 'sync',
        array $settings = []
    ): int
    {
        global $wpdb;
        $vault     = new Sentient_Forms_Provider_Credential_Vault();
        $encrypted = $vault->encrypt( 'sk-or-exact-artifact-public-seam' );
        $this->assertIsString( $encrypted );
        $credential_id = ( new Sentient_Forms_Provider_Credentials_Repository( $wpdb ) )->create(
            [
                'provider'          => 'openrouter',
                'label'             => 'Exact-artifact public seam',
                'auth_mode'         => 'manual_key',
                'encrypted_secret'  => $encrypted,
                'status'            => 'valid',
                'last_validated_at' => current_time( 'mysql' ),
            ]
        );
        $this->assertIsInt( $credential_id );
        $consent_id = ( new Sentient_Forms_External_Service_Consent_Repository( $wpdb ) )->record(
            'openrouter',
            '2026-04-16',
            get_current_user_id()
        );
        $this->assertIsInt( $consent_id );
        $model_id = 'example/exact-artifact-structured';
        $model_cached = ( new Sentient_Forms_Model_Cache_Repository( $wpdb ) )->upsert(
            'openrouter',
            $model_id,
            [
                'id'                   => $model_id,
                'name'                 => 'Exact-artifact structured-output fixture',
                'input_modalities'     => [ 'text' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'response_format', 'structured_outputs' ],
            ],
            gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS )
        );
        $this->assertTrue( true === $model_cached );

        $local_action = $this->create_plugin_owned_bundled_action(
            $action,
            [
                'provider'      => 'openrouter',
                'model'         => $model_id,
                'credential_id' => $credential_id,
            ]
        );
        $mapping_settings = array_merge(
            [
                'dispatch_mode'  => 'sync',
                'async'          => false,
                'execution_mode' => $execution_mode,
            ],
            $settings
        );
        $mapping_id = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->create(
            [
                'form_source'         => $source,
                'form_id'             => $form_id,
                'hook'                => $hook,
                'action_kind'         => 'custom_action',
                'action_id'           => $local_action['id'],
                'input_bindings_json' => [],
                'execution_mode'      => $execution_mode,
                'settings_json'       => $mapping_settings,
                'effect_mapping_json' => $local_action['catalog']['effect_mapping_json'] ?? [],
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        return $mapping_id;
    }

    private function create_active_lead_profile_fixture( string $source, string $form_id ): void
    {
        global $wpdb;
        $profile_id = ( new Sentient_Forms_Lead_Profiles_Repository( $wpdb ) )->save(
            [
                'form_source'              => $source,
                'form_id'                  => $form_id,
                'status'                   => 'active',
                'profile_version'          => 1,
                'consented_at'             => current_time( 'mysql' ),
                'generated_profile_prompt' => 'Exact-artifact consented Lead Scoring setup.',
                'good_lead_criteria_json'  => [
                    'summary_text' => 'Good leads show clear fit, contactability, and a practical next step.',
                ],
                'bad_lead_criteria_json'   => [
                    'summary_text' => 'Bad leads are irrelevant, abusive, or impossible to contact.',
                ],
                'grading_rubric_json'      => [
                    'scale' => [
                        'A'      => 'Strong fit',
                        'B'      => 'Likely fit',
                        'C'      => 'Weak fit',
                        'Reject' => 'Not a viable lead',
                    ],
                ],
                'handoff_rules_json'       => [],
            ]
        );
        $this->assertIsInt( $profile_id );
    }

    /** @return array<string, mixed>|string */
    private function provider_response_fixture( string $action ): array | string
    {
        $catalog = Sentient_Forms_Bundled_Action_Templates::get( $action );
        $this->assertIsArray( $catalog );
        $this->assertArrayHasKey( 'structured_output_schema', $catalog );
        $schema = $catalog['structured_output_schema'] ?? null;
        if ( null === $schema )
        {
            $this->assertSame(
                'entry_summary_v1',
                $action,
                'Only the intentionally unstructured Entry Summary catalog action may omit a schema.'
            );

            return 'Exact-artifact entry summary fixture.';
        }

        $this->assertIsArray( $schema );
        $fixture = $this->structured_schema_fixture_value( $schema );
        $this->assertIsArray( $fixture );

        return $fixture;
    }

    /** @param array<string, mixed> $schema */
    private function structured_schema_fixture_value( array $schema, string $property_name = '' ): mixed
    {
        $enum = is_array( $schema['enum'] ?? null ) ? $schema['enum'] : [];
        if ( [] !== $enum )
        {
            if ( 'classification' === $property_name && in_array( 'spam', $enum, true ) )
            {
                return 'spam';
            }

            return $enum[0];
        }

        $type = $schema['type'] ?? null;
        if ( is_array( $type ) )
        {
            $type = array_values( array_diff( $type, [ 'null' ] ) )[0] ?? 'string';
        }
        if ( 'object' === $type || is_array( $schema['properties'] ?? null ) )
        {
            $value = [];
            foreach ( is_array( $schema['required'] ?? null ) ? $schema['required'] : [] as $required_property )
            {
                $property_schema = $schema['properties'][ $required_property ] ?? [ 'type' => 'string' ];
                $value[ $required_property ] = $this->structured_schema_fixture_value( $property_schema, $required_property );
            }

            return $value;
        }
        if ( 'array' === $type )
        {
            $minimum = max( 0, absint( $schema['minItems'] ?? 0 ) );
            $items   = [];
            for ( $index = 0; $index < $minimum; ++$index )
            {
                $items[] = $this->structured_schema_fixture_value(
                    is_array( $schema['items'] ?? null ) ? $schema['items'] : [ 'type' => 'string' ],
                    $property_name
                );
            }

            return $items;
        }
        if ( 'boolean' === $type )
        {
            return true;
        }
        if ( in_array( $type, [ 'integer', 'number' ], true ) )
        {
            $minimum = is_numeric( $schema['minimum'] ?? null ) ? (float) $schema['minimum'] : 0.0;
            $maximum = is_numeric( $schema['maximum'] ?? null ) ? (float) $schema['maximum'] : max( 1.0, $minimum );
            $number  = 'confidence' === $property_name ? min( 0.99, $maximum ) : $minimum;

            return 'integer' === $type ? (int) ceil( $number ) : $number;
        }

        return 'Exact artifact fixture completed.';
    }

    /** @param array<string, mixed> $event */
    private function assert_gravity_native_result_effect( string $action, ?string $entry_id, array $event ): void
    {
        $this->assertIsString( $entry_id );
        $effects = $event['result_json']['effects'] ?? null;
        $this->assertIsArray( $effects );
        $this->assertContains( 'store_result', $effects['applied'] ?? [] );
        if ( 'spam_detection_v1' === $action )
        {
            $this->assertContains( 'mark_as_spam', $effects['applied'] ?? [] );
            $this->assertSame( 'spam', GFAPI::$entries[ (int) $entry_id ]['status'] ?? null );
        }
        else
        {
            $this->assertContains( 'entry_note', $effects['applied'] ?? [] );
        }
        $this->assertNotEmpty( gform_get_meta( (int) $entry_id, 'sentient_forms_last_response' ) );
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

        global $wpdb;
        $administrator = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $administrator );
        $mapping_id = $this->create_bundled_mapping(
            'gravity_forms',
            (string) $form_id,
            'clarification_assistant_v1',
            'real_time',
            'real_time',
            [
                'realtime_settings' => [
                    'checkpoint_field_ids'   => [ '1' ],
                    'storage_target_field_id' => $storage_field_id,
                    'pre_submit_timeout_ms'   => 1,
                ],
            ]
        );
        $mapping_key = 'local_first_' . $mapping_id;
        update_option(
            'sentient_forms_actions_gravity_forms_' . $form_id,
            [
                'actions' => [
                    [
                        'id'                         => $mapping_key,
                        'local_form_mapping_id'      => $mapping_id,
                        'central_action_id'          => 'clarification_assistant_v1',
                        'action_name_label'          => 'Real-time Clarification Assistant',
                        'action_type_indicator'      => 'local_first',
                        'is_action_enabled_for_form' => true,
                        'settings'                   => [
                            'execution_mode'  => 'real_time',
                            'realtime_settings' => [
                                'checkpoint_field_ids'    => [ '1' ],
                                'storage_target_field_id' => $storage_field_id,
                                'pre_submit_timeout_ms'   => 1,
                            ],
                        ],
                    ],
                ],
            ]
        );

        $client = new Sentient_Forms_Test_Exact_Artifact_OpenRouter_Client(
            [
                'suggestions'           => [],
                'virtual_questions'     => [
                    [
                        'question_id'     => 'exact-artifact-question',
                        'question'        => 'What result would make this submission successful?',
                        'reason'          => 'The answer makes the request actionable.',
                        'target_field_id' => '1',
                        'required'        => true,
                        'answer_type'     => 'short_text',
                    ],
                ],
                'conditional_decisions' => [],
            ]
        );
        $execution_service = new Sentient_Forms_Local_Action_Execution_Service(
            null,
            null,
            null,
            null,
            null,
            null,
            $client
        );
        $controller = new Sentient_Forms_Form_Suggestions_Controller(
            new Sentient_Forms_Form_Mappings_Repository( $wpdb ),
            $execution_service
        );
        $request = new WP_REST_Request(
            'POST',
            '/sentient-forms/v1/gravity_forms/forms/' . $form_id . '/actions/suggest'
        );
        $request->set_header(
            'X-Sentient-Forms-Suggest-Nonce',
            wp_create_nonce( 'sentient_forms_realtime_suggest_' . $form_id )
        );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', $form_id );
        $request->set_param( 'mapping_id', $mapping_key );
        $request->set_param( 'execution_request_id', $request_id );
        $request->set_param( 'all_known_field_values', [ '1' => 'Exact artifact visitor' ] );
        $request->set_param( 'visible_field_ids', [ '1' ] );
        $request->set_param( 'current_page_index', 1 );
        $request->set_param( 'total_pages', 1 );
        $request->set_param( 'request_reason', 'pre_submit' );
        $this->assertTrue( $controller->permission_callback_public_nonce( $request ) );

        $response = $controller->suggest( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response, is_wp_error( $response ) ? $response->get_error_message() : '' );
        $response_data = $response->get_data();
        $this->assertSame( 'success', $response_data['status'] ?? null, wp_json_encode( $response_data ) );
        $this->assertSame( $request_id, $response_data['meta']['execution_request_id'] ?? null );
        $this->assertCount( 1, $client->chat_calls );

        $event = ( new Sentient_Forms_Execution_Events_Repository( $wpdb ) )->get_by_request_id( $request_id );
        $this->assertIsArray( $event, 'The real-time public request must persist its execution event before any Q&A effect is accepted.' );
        $this->assertSame( $mapping_id, (int) ( $event['mapping_id'] ?? 0 ) );
        $this->assertSame( 'succeeded', $event['status'] ?? null );
        $this->assertSame(
            'What result would make this submission successful?',
            $event['result_json']['structured']['virtual_questions'][0]['question'] ?? null
        );

        $adapter->finalize_async_success(
            [
                'entry_id'             => $entry_id,
                'form_id'              => $form_id,
                'central_action_id'    => 'clarification_assistant_v1',
                'action_name_label'    => 'Real-time Clarification Assistant',
                'mapping_id'           => $mapping_key,
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
                    'structured_output_valid' => (bool) ( $event['result_json']['structured_output_valid'] ?? false ),
                    'structured_output'       => [
                        'virtual_questions'     => $response_data['virtual_questions'] ?? [],
                        'conditional_decisions' => $response_data['conditional_decisions'] ?? [],
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
        $this->assertSame( $mapping_key, $stored['mappings'][0]['mapping_id'] ?? null );
        $this->assertSame( $request_id, $stored['mappings'][0]['execution_request_id'] ?? null );
        $this->assertSame(
            'What result would make this submission successful?',
            $stored['mappings'][0]['questions'][0]['question'] ?? null
        );

        return [
            'request_trace_id'   => $request_id,
            'rejection_trace_id' => null,
            'submission_id'      => (string) $entry_id,
            'execution_id'       => $request_id,
            'lifecycle_id'       => null,
            'provider_observation_type' => 'automated_public_seam',
            'provider_observation_id'   => 'public-seam:' . $request_id,
            'observed_provider_route'   => 'openrouter',
            'applied_facets'            => [],
            'external_provider_call_count' => count( $client->chat_calls ),
        ];
    }

    /** @return array<string, mixed> */
    private function exercise_authenticated_source_contract_rejection(): array
    {
        $source = $this->assignment['form_source'];
        $this->assertContains( $source, [ 'contact_form_7', 'wpforms', 'elementor_pro_forms' ] );
        $administrator = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $administrator );
        $hook_snapshots = $this->isolate_hooks(
            [
                'sentient_forms_contact_form_7_is_active',
                'sentient_forms_contact_form_7_forms',
                'sentient_forms_contact_form_7_form_object',
                'sentient_forms_wpforms_is_active',
                'sentient_forms_elementor_is_active',
                'sentient_forms_elementor_pro_forms_api_available',
                'sentient_forms_elementor_posts_with_data',
            ]
        );

        try
        {
            $form_id = $this->create_source_contract_probe_form( $source );
            $local_action = $this->create_plugin_owned_bundled_action(
                'clarification_assistant_v1',
                [ 'provider' => 'openrouter', 'model' => 'openrouter/auto' ]
            );
            $action_code = $local_action['code'];
            $controller  = new Sentient_Forms_Form_Actions_Controller();
            $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/' . $source . '/forms/' . $form_id . '/actions' );
            $request->set_param( 'form_source_slug', $source );
            $request->set_param( 'form_id', $form_id );
            $request->set_param( 'central_action_id', $action_code );
            $request->set_param( 'action_type_indicator', 'custom' );
            $request->set_param( 'trigger_hooks', [ 'real_time' ] );
            $request->set_param( 'settings', [ 'execution_mode' => 'real_time' ] );
            $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

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
            $this->restore_hooks( $hook_snapshots );
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

    /**
     * @param array<string, mixed> $model_selection
     * @return array{id:int,code:string,catalog:array<string,mixed>}
     */
    private function create_plugin_owned_bundled_action( string $action_code, array $model_selection ): array
    {
        global $wpdb;
        $catalog = Sentient_Forms_Bundled_Action_Templates::get( $action_code );
        $this->assertIsArray( $catalog );
        $template_id = ( new Sentient_Forms_Action_Templates_Repository( $wpdb ) )->upsert_by_code(
            [
                'source'                   => 'bundled',
                'code'                     => $action_code,
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
        $local_action_code = Sentient_Forms_Bundled_Action_Templates::build_managed_custom_action_code( $action_code );
        $local_action_id   = ( new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb ) )->upsert_by_code(
            [
                'code'                 => $local_action_code,
                'display_name'         => $catalog['display_name'],
                'template_id'          => $template_id,
                'definition_json'      => [ 'template_code' => $action_code ],
                'model_selection_json' => $model_selection,
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $local_action_id );

        return [ 'id' => $local_action_id, 'code' => $local_action_code, 'catalog' => $catalog ];
    }

    private function create_source_contract_probe_form( string $source ): string
    {
        if ( 'contact_form_7' === $source )
        {
            $probe_id = self::factory()->post->create(
                [ 'post_title' => 'Exact-artifact CF7 rejection identity' ]
            );
            $probe_form = [ 'id' => $probe_id, 'title' => 'Exact-artifact CF7 rejection probe' ];
            add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
            add_filter(
                'sentient_forms_contact_form_7_forms',
                static fn(): array => [ $probe_form ]
            );
            add_filter(
                'sentient_forms_contact_form_7_form_object',
                static fn( mixed $form, mixed $form_id ): mixed => $probe_id === absint( $form_id ) ? $probe_form : $form,
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

    private function configure_observation_writer_fixture( string $suffix ): void
    {
        $this->assignment = [
            'id'                         => $suffix . '-observation',
            'action_code'                => 'spam_detection_v1',
            'form_source'                => 'gravity_forms',
            'lifecycle'                  => 'after_submission',
            'required_semantic_outcome'  => 'effect_applied',
            'facet_scenario_assignment'  => 'base_action',
            'policy_basis_assignment'    => 'action_catalog',
        ];
        $this->expected_effect = [
            'code'               => 'fixture_effect',
            'description'        => 'Fixture effect.',
            'description_sha256' => hash( 'sha256', 'Fixture effect.' ),
        ];
        $this->observation_path = trailingslashit( get_temp_dir() )
            . 'sentient-forms-' . $suffix . '-observation-' . wp_generate_uuid4() . '.json';
    }

    /** @return array<string, mixed> */
    private function valid_observation_identities( string $suffix ): array
    {
        return [
            'request_trace_id'          => $suffix . '-request',
            'rejection_trace_id'        => null,
            'submission_id'             => $suffix . '-submission',
            'execution_id'              => $suffix . '-execution',
            'lifecycle_id'              => null,
            'provider_observation_type' => 'automated_public_seam',
            'provider_observation_id'   => 'public-seam:' . $suffix,
            'observed_provider_route'   => 'direct_openrouter',
            'applied_facets'            => [],
        ];
    }

    /** @param resource $handle */
    private function write_observation_bytes( $handle, string $payload ): int | false
    {
        if ( is_callable( $this->observation_write_callback ) )
        {
            return ( $this->observation_write_callback )( $handle, $payload );
        }

        return fwrite( $handle, $payload );
    }

    private function publish_observation_file( string $temporary_path, string $final_path ): bool
    {
        if ( is_callable( $this->observation_publish_callback ) )
        {
            return ( $this->observation_publish_callback )( $temporary_path, $final_path );
        }

        return @link( $temporary_path, $final_path );
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
        $encoded = wp_json_encode( $observation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        $this->assertIsString( $encoded, 'Observation must be JSON-encodable before writing.' );
        $payload        = $encoded . "\n";
        $temporary_path = $this->observation_path . '.tmp.' . wp_generate_uuid4();
        $handle         = fopen( $temporary_path, 'x+b' );
        $this->assertIsResource( $handle );
        try
        {
            $offset = 0;
            $length = strlen( $payload );
            while ( $offset < $length )
            {
                $written = $this->write_observation_bytes( $handle, substr( $payload, $offset ) );
                $this->assertIsInt( $written, 'Writing the complete observation payload failed.' );
                $this->assertGreaterThan( 0, $written, 'Writing the complete observation payload made no progress.' );
                $this->assertLessThanOrEqual( $length - $offset, $written, 'Observation writer reported more bytes than supplied.' );
                $offset += $written;
            }
            $this->assertSame( $length, $offset, 'The complete observation payload must be written before publication.' );
            $this->assertTrue( fflush( $handle ), 'Observation staging bytes must be flushed before publication.' );
            if ( function_exists( 'fsync' ) )
            {
                $this->assertTrue( fsync( $handle ), 'Observation staging bytes must be synchronized before publication.' );
            }

            $this->assertTrue( fclose( $handle ), 'Observation staging file must close before publication.' );
            $handle = null;
            $this->assertTrue(
                $this->publish_observation_file( $temporary_path, $this->observation_path ),
                'Complete observation publication must be atomic and must not replace an existing artifact.'
            );
            $this->assertTrue( unlink( $temporary_path ), 'Observation staging file must be removed after publication.' );
            $temporary_path = '';
        }
        finally
        {
            if ( is_resource( $handle ) )
            {
                fclose( $handle );
            }
            if ( '' !== $temporary_path && file_exists( $temporary_path ) )
            {
                unlink( $temporary_path );
            }
        }
    }
}
