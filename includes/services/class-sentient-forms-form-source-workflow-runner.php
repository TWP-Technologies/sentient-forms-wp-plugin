<?php
/**
 * Source-neutral Form Source workflow runner.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Owns shared orchestration across Form Source lifecycles.
 */
final class Sentient_Forms_Form_Source_Workflow_Runner
{
    private Sentient_Forms_Plugin $plugin;

    private ?Sentient_Forms_Submission_Ledger_Capture_Service $capture_service;

    private Sentient_Forms_Action_Runtime_Settings_Resolver $runtime_settings_resolver;

    private ?Sentient_Forms_Local_Action_Execution_Service $local_execution_service;

    public function __construct(
        Sentient_Forms_Plugin $plugin,
        ?Sentient_Forms_Submission_Ledger_Capture_Service $capture_service = null,
        ?Sentient_Forms_Action_Runtime_Settings_Resolver $runtime_settings_resolver = null,
        ?Sentient_Forms_Local_Action_Execution_Service $local_execution_service = null
    )
    {
        $this->plugin                    = $plugin;
        $this->capture_service           = $capture_service;
        $this->runtime_settings_resolver = $runtime_settings_resolver ?? new Sentient_Forms_Action_Runtime_Settings_Resolver();
        $this->local_execution_service   = $local_execution_service;
    }

    /**
     * Capture and schedule one source-normalized accepted submission.
     */
    public function run_accepted_submission(
        Sentient_Forms_Accepted_Submission_Adapter_Interface $adapter,
        mixed $native_submission
    ): ?string
    {
        return $this->run_accepted_submission_with_outcome( $adapter, $native_submission )->get_submission_uuid();
    }

    /**
     * Capture and execute one accepted submission with source-neutral mapping outcomes.
     */
    public function run_accepted_submission_with_outcome(
        Sentient_Forms_Accepted_Submission_Adapter_Interface $adapter,
        mixed $native_submission
    ): Sentient_Forms_Accepted_Submission_Run_Result
    {
        if ( ! $adapter->is_active() )
        {
            return new Sentient_Forms_Accepted_Submission_Run_Result( null );
        }

        $normalized = $adapter->normalize_accepted_submission( $native_submission );
        if ( is_wp_error( $normalized ) )
        {
            return new Sentient_Forms_Accepted_Submission_Run_Result( null );
        }

        $form_source = sanitize_key( $adapter->get_id() );
        $form_id     = isset( $normalized['form_id'] ) && is_scalar( $normalized['form_id'] )
            ? sanitize_text_field( (string) $normalized['form_id'] )
            : '';
        $form        = isset( $normalized['form'] ) && is_array( $normalized['form'] )
            ? $normalized['form']
            : [];
        if ( '' === $form_source || '' === $form_id || [] === $form )
        {
            return new Sentient_Forms_Accepted_Submission_Run_Result( null );
        }

        $correlation_uuid = $this->stable_native_submission_uuid( $form_source, $form_id, $normalized );

        $capture_service = $this->get_capture_service();
        if ( null === $capture_service )
        {
            return new Sentient_Forms_Accepted_Submission_Run_Result( null );
        }

        $capture_payload = [
            'form_source'    => $form_source,
            'form_id'        => $form_id,
            'logical_fields' => isset( $normalized['logical_fields'] ) && is_array( $normalized['logical_fields'] )
                ? $normalized['logical_fields']
                : [],
            'files'          => isset( $normalized['files'] ) && is_array( $normalized['files'] )
                ? $normalized['files']
                : [],
        ];
        if ( null !== $correlation_uuid )
        {
            $capture_payload['submission_uuid'] = $correlation_uuid;
        }
        foreach ( [ 'native_entry_id', 'native_entry_url', 'source_submitted_at' ] as $identity_key )
        {
            if ( isset( $normalized[ $identity_key ] ) && is_scalar( $normalized[ $identity_key ] ) )
            {
                $capture_payload[ $identity_key ] = (string) $normalized[ $identity_key ];
            }
        }
        if ( isset( $normalized['provider_metadata'] ) && is_array( $normalized['provider_metadata'] ) )
        {
            $capture_payload['provider_metadata'] = $normalized['provider_metadata'];
        }

        $captured = $capture_service->capture( $capture_payload );
        if ( is_wp_error( $captured ) )
        {
            if (
                'sentient_forms_submission_ledger_disabled' !== $captured->get_error_code()
                || $this->accepted_submission_requires_ledger( $adapter )
                || null === $correlation_uuid
            )
            {
                return new Sentient_Forms_Accepted_Submission_Run_Result( null );
            }

            $captured = $this->uncaptured_native_submission_snapshot( $normalized, $correlation_uuid );
        }

        $submission_uuid = isset( $captured['submission_uuid'] ) && is_scalar( $captured['submission_uuid'] )
            ? sanitize_text_field( (string) $captured['submission_uuid'] )
            : '';
        if ( '' === $submission_uuid )
        {
            return new Sentient_Forms_Accepted_Submission_Run_Result( null );
        }

        $settings = $this->get_form_settings( $form_source, $form_id );
        if ( [] === $settings || ! empty( $this->get_execution_disable_flags( $form_source, $settings )['effective_disabled'] ) )
        {
            return new Sentient_Forms_Accepted_Submission_Run_Result( $submission_uuid );
        }

        return $this->schedule_actions(
            $adapter,
            $form_source,
            $form_id,
            $adapter->get_accepted_submission_native_hook(),
            $form,
            $this->ledger_entry_snapshot( $form_source, $form_id, $form, $captured, $submission_uuid ),
            $submission_uuid,
            $settings
        );
    }

    /**
     * Native-entry Form Sources can execute without local ledger opt-in. Use a
     * stable correlation UUID so retries share one idempotency identity.
     *
     * @param array<string, mixed> $normalized
     */
    private function stable_native_submission_uuid( string $form_source, string $form_id, array $normalized ): ?string
    {
        $native_entry_id = isset( $normalized['native_entry_id'] ) && is_scalar( $normalized['native_entry_id'] )
            ? sanitize_text_field( (string) $normalized['native_entry_id'] )
            : '';
        if ( '' === $native_entry_id )
        {
            return null;
        }

        $hex = substr(
            hash(
                'sha256',
                implode(
                    '|',
                    [
                        'sentient-forms-native-submission-v1',
                        (string) get_current_blog_id(),
                        $form_source,
                        $form_id,
                        $native_entry_id,
                    ]
                )
            ),
            0,
            32
        );
        $hex[12] = '5';
        $hex[16] = dechex( ( hexdec( $hex[16] ) & 0x3 ) | 0x8 );

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr( $hex, 0, 8 ),
            substr( $hex, 8, 4 ),
            substr( $hex, 12, 4 ),
            substr( $hex, 16, 4 ),
            substr( $hex, 20, 12 )
        );
    }

    private function accepted_submission_requires_ledger(
        Sentient_Forms_Accepted_Submission_Adapter_Interface $adapter
    ): bool
    {
        if ( ! method_exists( $adapter, 'get_capability_descriptor' ) )
        {
            return true;
        }

        $descriptor = $adapter->get_capability_descriptor();
        if ( ! is_array( $descriptor ) )
        {
            return true;
        }

        return ! empty( $descriptor['ledger']['required_for_parity'] )
            || ! empty( $descriptor['lifecycles']['after_submission']['requires_ledger'] );
    }

    /**
     * @param array<string, mixed> $normalized
     *
     * @return array<string, mixed>
     */
    private function uncaptured_native_submission_snapshot( array $normalized, string $submission_uuid ): array
    {
        return [
            'submission_uuid'    => $submission_uuid,
            'native_entry_id'    => isset( $normalized['native_entry_id'] ) && is_scalar( $normalized['native_entry_id'] )
                ? sanitize_text_field( (string) $normalized['native_entry_id'] )
                : null,
            'native_entry_url'   => isset( $normalized['native_entry_url'] ) && is_scalar( $normalized['native_entry_url'] )
                ? esc_url_raw( (string) $normalized['native_entry_url'] )
                : null,
            'logical_fields_json' => isset( $normalized['logical_fields'] ) && is_array( $normalized['logical_fields'] )
                ? $normalized['logical_fields']
                : [],
            'file_refs_json'     => isset( $normalized['files'] ) && is_array( $normalized['files'] )
                ? $normalized['files']
                : [],
        ];
    }

    /**
     * Load source-neutral option-backed workflow settings.
     *
     * @return array<string, mixed>
     */
    public function get_form_settings( string $form_source, mixed $form_id ): array
    {
        $form_source = sanitize_key( $form_source );
        $suffix      = Sentient_Forms_Provider_Form_Id_Keys::option_suffix( $form_id );
        if ( '' === $form_source || '0' === $suffix )
        {
            return [];
        }

        $settings = get_option( 'sentient_forms_actions_' . $form_source . '_' . $suffix, null );
        if ( null === $settings )
        {
            foreach ( Sentient_Forms_Provider_Form_Id_Keys::legacy_option_suffixes( $form_source, $form_id ) as $legacy_suffix )
            {
                $settings = get_option( 'sentient_forms_actions_' . $form_source . '_' . $legacy_suffix, null );
                if ( null !== $settings )
                {
                    break;
                }
            }
        }

        if ( ! is_array( $settings ) )
        {
            $settings = [];
        }

        return $this->merge_local_first_form_mappings(
            $this->normalize_action_wrapper( $settings ),
            $form_source,
            $form_id
        );
    }

    private function get_capture_service(): ?Sentient_Forms_Submission_Ledger_Capture_Service
    {
        if ( null !== $this->capture_service )
        {
            return $this->capture_service;
        }

        if ( ! class_exists( 'Sentient_Forms_Submission_Ledger_Capture_Service' ) )
        {
            return null;
        }

        global $wpdb;
        $this->capture_service = new Sentient_Forms_Submission_Ledger_Capture_Service( $wpdb );

        return $this->capture_service;
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $settings
     */
    private function schedule_actions(
        Sentient_Forms_Accepted_Submission_Adapter_Interface $adapter,
        string $form_source,
        string $form_id,
        string $native_hook,
        array $form,
        array $entry,
        string $submission_uuid,
        array $settings
    ): Sentient_Forms_Accepted_Submission_Run_Result
    {
        $plan = $this->plugin->get_mapping_dependency_planner()->build_execution_plan(
            $settings,
            Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION
        );
        $mapping_outcomes      = [];
        $execution_request_ids = [];
        $resolved_mappings     = [];
        $execution_results     = [];
        $native_effect_outcomes = [];
        $capability_descriptor = method_exists( $adapter, 'get_capability_descriptor' )
            ? $adapter->get_capability_descriptor()
            : [];
        $native_entry_id       = isset( $entry['id'] ) && is_scalar( $entry['id'] ) && '' !== (string) $entry['id']
            ? sanitize_text_field( (string) $entry['id'] )
            : null;

        foreach ( (array) ( $plan['order'] ?? [] ) as $mapping_id )
        {
            $node = $plan['nodes'][ $mapping_id ] ?? null;
            if ( ! $this->is_runnable_plan_node( $node ) )
            {
                continue;
            }

            $action_settings = $this->runtime_settings_resolver->resolve_mapping( $node['mapping'], $form_source, $form_id );
            $filtered_mapping = $this->filter_mapping_with_native_effect_outcomes( $action_settings, $capability_descriptor );
            $action_settings = $filtered_mapping['mapping'];
            $native_effect_outcomes[ (string) $mapping_id ] = $filtered_mapping['outcomes'];
            $action_settings['local_mapping_id'] = $action_settings['local_mapping_id'] ?? $mapping_id;
            $resolved_mappings[ (string) $mapping_id ] = $action_settings;
            $central_action_id = $this->central_action_id( $action_settings );
            if ( '' === $central_action_id )
            {
                continue;
            }

            $execution_request_ids[ (string) $mapping_id ] = Sentient_Forms_Action_Executor::generate_execution_request_id(
                $central_action_id,
                $form,
                $entry,
                [
                    'hook'      => $native_hook,
                    'action_id' => (string) $mapping_id,
                ]
            );
        }

        foreach ( (array) ( $plan['order'] ?? [] ) as $mapping_id )
        {
            $node = $plan['nodes'][ $mapping_id ] ?? null;
            if ( ! $this->is_runnable_plan_node( $node ) )
            {
                $mapping_outcomes[ (string) $mapping_id ] = 'skipped';
                continue;
            }

            if ( isset( $resolved_mappings[ (string) $mapping_id ] ) )
            {
                $action_settings = $resolved_mappings[ (string) $mapping_id ];
            }
            else
            {
                $filtered_mapping = $this->filter_mapping_with_native_effect_outcomes(
                    $this->runtime_settings_resolver->resolve_mapping( $node['mapping'], $form_source, $form_id ),
                    $capability_descriptor
                );
                $action_settings = $filtered_mapping['mapping'];
                $native_effect_outcomes[ (string) $mapping_id ] = $filtered_mapping['outcomes'];
            }
            $action_settings['local_mapping_id'] = $action_settings['local_mapping_id'] ?? $mapping_id;
            $resolved_mappings[ (string) $mapping_id ] = $action_settings;
            $central_action_id = $this->central_action_id( $action_settings );
            if ( '' === $central_action_id )
            {
                $mapping_outcomes[ (string) $mapping_id ] = 'failed';
                continue;
            }

            $dependency_ids = is_array( $node['dependency_ids'] ?? null ) ? $node['dependency_ids'] : [];
            $should_async  = $this->is_mapping_async( $action_settings );
            if ( null !== $this->resolve_dependency_blocking_mapping( $dependency_ids, $mapping_outcomes, $should_async ) )
            {
                $mapping_outcomes[ (string) $mapping_id ] = 'skipped';
                continue;
            }

            if ( $this->should_skip_for_upstream_spam( $dependency_ids, $resolved_mappings, $execution_results, $action_settings ) )
            {
                $mapping_outcomes[ (string) $mapping_id ] = 'skipped';
                continue;
            }

            if ( ! $this->plugin->get_condition_evaluator()->should_execute( $action_settings, $entry ) )
            {
                $mapping_outcomes[ (string) $mapping_id ] = 'skipped';
                continue;
            }

            $dependency_context = $this->dependency_context(
                (string) $mapping_id,
                $dependency_ids,
                $mapping_outcomes,
                $execution_request_ids,
                $action_settings
            );
            $mapping_native_effect_outcomes = $native_effect_outcomes[ (string) $mapping_id ] ?? [];
            $dependency_context['native_effect_outcomes'] = $mapping_native_effect_outcomes;

            if ( $this->is_local_first_mapping( $action_settings ) )
            {
                if ( ! $should_async )
                {
                    $run = $this->execute_claimed_synchronous_mapping(
                        $form_source,
                        $form_id,
                        $native_entry_id,
                        (string) $mapping_id,
                        $action_settings,
                        $submission_uuid,
                        $execution_request_ids[ (string) $mapping_id ] ?? '',
                        $mapping_native_effect_outcomes,
                        fn (): array | WP_Error => $this->get_local_execution_service()->execute_mapping(
                            absint( $action_settings['local_form_mapping_id'] ?? 0 ),
                            $form,
                            $entry,
                            [
                                'hook'             => $native_hook,
                                'form_source'      => $form_source,
                                'mapping_id'       => (string) $mapping_id,
                                'local_mapping_id' => (string) $mapping_id,
                                'form_id'          => $form_id,
                                'entry_id'         => $native_entry_id,
                                'submission_uuid'  => $submission_uuid,
                            ] + $dependency_context
                        )
                    );
                    $mapping_outcomes[ (string) $mapping_id ] = $run['outcome'];
                    $native_effect_outcomes[ (string) $mapping_id ] = $run['native_effect_outcomes'];
                    if ( null !== $run['result'] )
                    {
                        $execution_results[ (string) $mapping_id ] = $run['result'];
                    }
                    continue;
                }

                $scheduled = $this->schedule_local_first_mapping(
                    $form_source,
                    $form_id,
                    $native_hook,
                    $form,
                    $entry,
                    (string) $mapping_id,
                    $action_settings,
                    $submission_uuid,
                    $dependency_context
                );
                $schedule_outcome = $this->async_schedule_outcome(
                    $scheduled,
                    $execution_request_ids[ (string) $mapping_id ] ?? ''
                );
                $mapping_outcomes[ (string) $mapping_id ] = $schedule_outcome;
                if ( 'queued' === $schedule_outcome )
                {
                    $this->log_queued_accepted_mapping(
                        $form_source,
                        $form_id,
                        $native_entry_id,
                        (string) $mapping_id,
                        $action_settings,
                        $submission_uuid,
                        $execution_request_ids[ (string) $mapping_id ] ?? '',
                        $mapping_native_effect_outcomes
                    );
                }
                continue;
            }

            if ( ! $should_async )
            {
                $run = $this->execute_claimed_synchronous_mapping(
                    $form_source,
                    $form_id,
                    $native_entry_id,
                    (string) $mapping_id,
                    $action_settings,
                    $submission_uuid,
                    $execution_request_ids[ (string) $mapping_id ] ?? '',
                    $mapping_native_effect_outcomes,
                    fn (): array | bool | WP_Error => $this->execute_synchronous_mapping(
                        $central_action_id,
                        $form_source,
                        $form_id,
                        $native_hook,
                        $form,
                        $entry,
                        (string) $mapping_id,
                        $action_settings,
                        $submission_uuid,
                        $dependency_context
                    )
                );
                $mapping_outcomes[ (string) $mapping_id ] = $run['outcome'];
                $native_effect_outcomes[ (string) $mapping_id ] = $run['native_effect_outcomes'];
                if ( null !== $run['result'] )
                {
                    $execution_results[ (string) $mapping_id ] = $run['result'];
                }
                continue;
            }

            $scheduled = $this->plugin->process_action_async(
                $central_action_id,
                [
                    'hook'        => $native_hook,
                    'form_source' => $form_source,
                    'form'        => $form,
                    'entry'       => $entry,
                ],
                $action_settings,
                [
                    'hook'              => $native_hook,
                    'form_source'       => $form_source,
                    'action_id'         => (string) $mapping_id,
                    'mapping_id'        => (string) $mapping_id,
                    'local_mapping_id'  => (string) $mapping_id,
                    'form_id'           => $form_id,
                    'entry_id'          => $native_entry_id,
                    'submission_uuid'   => $submission_uuid,
                    'central_action_id' => $central_action_id,
                    'action_name_label' => $action_settings['action_name_label'] ?? $central_action_id,
                ] + $dependency_context
            );
            $schedule_outcome = $this->async_schedule_outcome(
                $scheduled,
                $execution_request_ids[ (string) $mapping_id ] ?? ''
            );
            $mapping_outcomes[ (string) $mapping_id ] = $schedule_outcome;
            if ( 'queued' === $schedule_outcome )
            {
                $this->log_queued_accepted_mapping(
                    $form_source,
                    $form_id,
                    $native_entry_id,
                    (string) $mapping_id,
                    $action_settings,
                    $submission_uuid,
                    $execution_request_ids[ (string) $mapping_id ] ?? '',
                    $mapping_native_effect_outcomes
                );
            }
        }

        return new Sentient_Forms_Accepted_Submission_Run_Result(
            $submission_uuid,
            $mapping_outcomes,
            $resolved_mappings,
            $execution_results,
            $native_effect_outcomes
        );
    }

    /**
     * Claim and execute one synchronous accepted mapping exactly once.
     *
     * @param array<string, mixed> $mapping
     * @param callable(): (array<string, mixed>|bool|WP_Error) $execute
     *
     * @return array{outcome: string, result: array<string, mixed>|bool|WP_Error|null, native_effect_outcomes: array<int, array{effect: string, status: string, reason: string}>}
     */
    private function execute_claimed_synchronous_mapping(
        string $form_source,
        string $form_id,
        ?string $entry_id,
        string $mapping_id,
        array $mapping,
        string $submission_uuid,
        string $execution_request_id,
        array $native_effect_outcomes,
        callable $execute
    ): array
    {
        if ( '' === $execution_request_id )
        {
            return [
                'outcome'                => 'failed',
                'result'                 => new WP_Error( 'sentient_forms_missing_execution_request_id', __( 'Synchronous execution identity is missing.', 'sentient-forms' ) ),
                'native_effect_outcomes' => $native_effect_outcomes,
            ];
        }

        $settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) ? $mapping['settings'] : [];
        $digest   = hash(
            'sha256',
            (string) wp_json_encode(
                [
                    'action_code'     => $this->central_action_id( $mapping ),
                    'mapping_id'      => $mapping_id,
                    'submission_uuid' => $submission_uuid,
                    'settings'        => $settings,
                ]
            )
        );
        $request_store = $this->plugin->get_async_request_store();
        $claim         = $request_store->claim_execution(
            $execution_request_id,
            [
                'action_id'      => $this->central_action_id( $mapping ),
                'adapter'        => $form_source,
                'payload_digest' => $digest,
            ],
            ! empty( $settings['synchronous_retry_safe'] ),
            'accepted_sync'
        );
        $claim_state = sanitize_key( (string) ( $claim['state'] ?? 'conflict' ) );

        if ( 'digest_conflict' === $claim_state )
        {
            return [
                'outcome'                => 'digest_conflict',
                'result'                 => new WP_Error(
                    'sentient_forms_execution_digest_conflict',
                    __( 'This execution identity was already used for a different action payload.', 'sentient-forms' )
                ),
                'native_effect_outcomes' => $native_effect_outcomes,
            ];
        }

        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        if ( 'claimed' !== $claim_state )
        {
            $event = $events->get_by_request_id( $execution_request_id );
            if ( 'success' === $claim_state )
            {
                return [
                    'outcome'                => 'replayed_success',
                    'result'                 => is_array( $event['result_json'] ?? null ) ? $event['result_json'] : [],
                    'native_effect_outcomes' => $this->replayed_native_effect_outcomes( $event, $native_effect_outcomes ),
                ];
            }
            if ( 'failed' === $claim_state )
            {
                return [
                    'outcome'                => 'replayed_failed',
                    'result'                 => new WP_Error(
                        isset( $event['error_code'] ) ? sanitize_key( (string) $event['error_code'] ) : 'sentient_forms_synchronous_execution_failed',
                        __( 'Synchronous accepted action previously failed.', 'sentient-forms' )
                    ),
                    'native_effect_outcomes' => $this->replayed_native_effect_outcomes( $event, $native_effect_outcomes ),
                ];
            }

            return [
                'outcome'                => 'replayed_active',
                'result'                 => null,
                'native_effect_outcomes' => $native_effect_outcomes,
            ];
        }

        $event_base = [
            'execution_request_id' => $execution_request_id,
            'mapping_id'           => isset( $mapping['local_form_mapping_id'] ) ? absint( $mapping['local_form_mapping_id'] ) : null,
            'mapping_key'          => $mapping_id,
            'action_code'          => $this->central_action_id( $mapping ),
            'action_label'         => $mapping['action_name_label'] ?? $this->central_action_id( $mapping ),
            'form_source'          => $form_source,
            'form_id'              => $form_id,
            'entry_id'             => $entry_id,
            'submission_uuid'      => $submission_uuid,
            'status'               => 'running',
            'payload_digest'       => $digest,
        ];
        $events->record( $event_base );

        try
        {
            $result = $execute();
        }
        catch ( Throwable )
        {
            $result = new WP_Error(
                'sentient_forms_synchronous_execution_exception',
                __( 'Synchronous accepted action failed.', 'sentient-forms' )
            );
        }

        if ( is_wp_error( $result ) )
        {
            $safe_error = __( 'Synchronous accepted action failed.', 'sentient-forms' );
            $events->record(
                array_merge( $event_base, [
                    'status'        => 'failed',
                    'error_code'    => $result->get_error_code(),
                    'error_message' => $safe_error,
                    'result_json'   => [ 'native_effect_outcomes' => $native_effect_outcomes ],
                ] )
            );
            $request_store->mark_status( $execution_request_id, 'failed', $safe_error, 'accepted_sync' );
            $this->log_synchronous_accepted_failure(
                $form_source,
                $form_id,
                $entry_id,
                $mapping_id,
                $mapping,
                $submission_uuid,
                $execution_request_id,
                $result,
                $native_effect_outcomes
            );

            return [
                'outcome'                => 'failed',
                'result'                 => $result,
                'native_effect_outcomes' => $native_effect_outcomes,
            ];
        }

        $result_payload = is_array( $result ) ? $result : [ 'completed' => (bool) $result ];
        $native_effect_outcomes = Sentient_Forms_Native_Effect_Outcomes::merge(
            Sentient_Forms_Native_Effect_Outcomes::from_execution_result( $result_payload ),
            $native_effect_outcomes
        );
        $result_payload['native_effect_outcomes'] = $native_effect_outcomes;
        $stored_result  = class_exists( 'Sentient_Forms_Local_Data_Governance' )
            ? Sentient_Forms_Local_Data_Governance::sanitize_execution_result_for_storage( $result_payload )
            : [];
        if ( [] !== $native_effect_outcomes )
        {
            $stored_result['native_effect_outcomes'] = $native_effect_outcomes;
        }
        $events->record(
            array_merge( $event_base, [
                'status'      => 'succeeded',
                'result_json' => $stored_result,
            ] )
        );
        $request_store->mark_status( $execution_request_id, 'success', null, 'accepted_sync' );
        $this->log_synchronous_accepted_success(
            $form_source,
            $form_id,
            $entry_id,
            $mapping_id,
            $mapping,
            $submission_uuid,
            $execution_request_id,
            $result_payload,
            $native_effect_outcomes
        );

        return [
            'outcome'                => 'succeeded',
            'result'                 => is_array( $result ) ? $result_payload : $result,
            'native_effect_outcomes' => $native_effect_outcomes,
        ];
    }

    /**
     * Keep terminal replay evidence stable when source capabilities change later.
     *
     * @param array<string, mixed>|null $event
     * @param array<int, array{effect: string, status: string, reason: string}> $fallback
     *
     * @return array<int, array{effect: string, status: string, reason: string}>
     */
    private function replayed_native_effect_outcomes( ?array $event, array $fallback ): array
    {
        $stored = Sentient_Forms_Native_Effect_Outcomes::from_execution_result(
            is_array( $event['result_json'] ?? null ) ? $event['result_json'] : []
        );

        return [] !== $stored ? $stored : $fallback;
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private function log_queued_accepted_mapping(
        string $form_source,
        string $form_id,
        ?string $entry_id,
        string $mapping_id,
        array $mapping,
        string $submission_uuid,
        string $execution_request_id,
        array $native_effect_outcomes
    ): void
    {
        if ( ! class_exists( 'Sentient_Forms_Action_Log_Controller' ) )
        {
            return;
        }

        Sentient_Forms_Action_Log_Controller::log_execution(
            [
                'form_source'          => $form_source,
                'form_id'              => $form_id,
                'entry_id'             => $entry_id,
                'action_code'          => $this->central_action_id( $mapping ),
                'action_label'         => $mapping['action_name_label'] ?? $this->central_action_id( $mapping ),
                'status'               => 'pending',
                'result_summary'       => __( 'Queued for background execution.', 'sentient-forms' ),
                'execution_request_id' => $execution_request_id,
                'submission_uuid'      => $submission_uuid,
                'mapping_id'           => $mapping_id,
                'details'              => [ 'native_effect_outcomes' => $native_effect_outcomes ],
            ]
        );
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $result
     */
    private function log_synchronous_accepted_success(
        string $form_source,
        string $form_id,
        ?string $entry_id,
        string $mapping_id,
        array $mapping,
        string $submission_uuid,
        string $execution_request_id,
        array $result,
        array $native_effect_outcomes
    ): void
    {
        if ( ! class_exists( 'Sentient_Forms_Action_Log_Controller' ) )
        {
            return;
        }

        $stored_result = class_exists( 'Sentient_Forms_Local_Data_Governance' )
            ? Sentient_Forms_Local_Data_Governance::sanitize_execution_result_for_storage( $result )
            : [];
        $classification = $this->extract_spam_classification( $stored_result );
        $result_summary = '' !== $classification
            ? sprintf(
                /* translators: %s: safe classification slug. */
                __( 'Accepted action completed with classification: %s.', 'sentient-forms' ),
                $classification
            )
            : __( 'Accepted action completed successfully.', 'sentient-forms' );

        Sentient_Forms_Action_Log_Controller::log_execution(
            [
                'form_source'         => $form_source,
                'form_id'             => $form_id,
                'entry_id'            => $entry_id,
                'action_code'         => $this->central_action_id( $mapping ),
                'action_label'        => $mapping['action_name_label'] ?? $this->central_action_id( $mapping ),
                'status'              => 'success',
                'result_summary'      => wp_trim_words( $result_summary, 20, '...' ),
                'classification'      => $classification,
                'execution_request_id'=> $execution_request_id,
                'submission_uuid'     => $submission_uuid,
                'mapping_id'          => $mapping_id,
                'details'             => [ 'native_effect_outcomes' => $native_effect_outcomes ],
            ]
        );
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private function log_synchronous_accepted_failure(
        string $form_source,
        string $form_id,
        ?string $entry_id,
        string $mapping_id,
        array $mapping,
        string $submission_uuid,
        string $execution_request_id,
        WP_Error $error,
        array $native_effect_outcomes
    ): void
    {
        if ( ! class_exists( 'Sentient_Forms_Action_Log_Controller' ) )
        {
            return;
        }

        $error_code = sanitize_key( (string) $error->get_error_code() );
        if ( '' === $error_code )
        {
            $error_code = 'synchronous_action_failed';
        }

        Sentient_Forms_Action_Log_Controller::log_execution(
            [
                'form_source'          => $form_source,
                'form_id'              => $form_id,
                'entry_id'             => $entry_id,
                'action_code'          => $this->central_action_id( $mapping ),
                'action_label'         => $mapping['action_name_label'] ?? $this->central_action_id( $mapping ),
                'status'               => 'error',
                'error_code'           => $error_code,
                'error_message'        => __( 'Synchronous accepted action failed.', 'sentient-forms' ),
                'execution_request_id' => $execution_request_id,
                'submission_uuid'      => $submission_uuid,
                'mapping_id'           => $mapping_id,
                'details'              => [ 'native_effect_outcomes' => $native_effect_outcomes ],
            ]
        );
    }

    private function async_schedule_outcome( bool $scheduled, string $execution_request_id ): string
    {
        if ( $scheduled )
        {
            return 'queued';
        }

        if ( '' === $execution_request_id )
        {
            return 'failed';
        }

        $existing = $this->plugin->get_async_request_store()->get( $execution_request_id );
        $status   = is_array( $existing ) ? sanitize_key( (string) ( $existing['status'] ?? '' ) ) : '';

        return in_array( $status, [ 'queued', 'running', 'success' ], true )
            ? 'replayed'
            : 'failed';
    }

    private function get_local_execution_service(): Sentient_Forms_Local_Action_Execution_Service
    {
        if ( null === $this->local_execution_service )
        {
            $this->local_execution_service = new Sentient_Forms_Local_Action_Execution_Service();
        }

        return $this->local_execution_service;
    }

    private function is_mapping_async( array $mapping ): bool
    {
        $settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) ? $mapping['settings'] : [];
        if ( array_key_exists( 'async', $settings ) )
        {
            return rest_sanitize_boolean( $settings['async'] );
        }
        if ( isset( $settings['execution_mode'] ) && is_scalar( $settings['execution_mode'] ) )
        {
            return Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION === Sentient_Forms_Form_Source_Lifecycles::normalize_id( $settings['execution_mode'] );
        }
        if ( array_key_exists( 'async', $mapping ) )
        {
            return rest_sanitize_boolean( $mapping['async'] );
        }
        if ( isset( $mapping['execution_mode'] ) && is_scalar( $mapping['execution_mode'] ) )
        {
            return Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION === Sentient_Forms_Form_Source_Lifecycles::normalize_id( $mapping['execution_mode'] );
        }

        return 'master' === sanitize_key( (string) ( $mapping['action_type_indicator'] ?? '' ) );
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $action_settings
     * @param array<string, mixed> $dependency_context
     *
     * @return array<string, mixed>|bool|WP_Error
     */
    private function execute_synchronous_mapping(
        string $central_action_id,
        string $form_source,
        string $form_id,
        string $native_hook,
        array $form,
        array $entry,
        string $mapping_id,
        array $action_settings,
        string $submission_uuid,
        array $dependency_context
    ): array | bool | WP_Error
    {
        $action = $this->plugin->get_action( $central_action_id );
        if ( $action instanceof Sentient_Forms_Action_Interface )
        {
            $execution_context = [
                'hook'                 => $native_hook,
                'form_source'          => $form_source,
                'mapping_id'           => $mapping_id,
                'local_mapping_id'     => $mapping_id,
                'form_id'              => $form_id,
                'entry_id'             => $entry['id'] ?? null,
                'submission_uuid'      => $submission_uuid,
                'execution_request_id' => $dependency_context['execution_request_id'] ?? null,
                'central_action_id'    => $central_action_id,
            ] + $dependency_context;

            return $action->execute(
                [
                    'form'              => $form,
                    'entry'             => $entry,
                    'hook'              => $native_hook,
                    'form_source'       => $form_source,
                    'execution_context' => $execution_context,
                ],
                $action_settings,
                $entry['id'] ?? '',
                $form_id
            );
        }

        return $this->plugin->get_action_executor()->execute(
            $central_action_id,
            $form,
            $entry,
            [
                'hook'              => $native_hook,
                'form_source'       => $form_source,
                'action_id'         => $mapping_id,
                'mapping_id'        => $mapping_id,
                'local_mapping_id'  => $mapping_id,
                'form_id'           => $form_id,
                'entry_id'          => $entry['id'] ?? null,
                'submission_uuid'   => $submission_uuid,
                'central_action_id' => $central_action_id,
                'settings'          => isset( $action_settings['settings'] ) && is_array( $action_settings['settings'] )
                    ? $action_settings['settings']
                    : [],
            ] + $dependency_context
        );
    }

    private function is_runnable_plan_node( mixed $node ): bool
    {
        return is_array( $node )
            && isset( $node['mapping'] )
            && is_array( $node['mapping'] )
            && ! empty( $node['enabled'] )
            && ! empty( $node['hook_enabled'] )
            && ! $this->is_plan_node_trigger_unbound( $node, Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION );
    }

    /**
     * Preserve attributable outcomes before removing unsupported native effects.
     *
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $descriptor
     *
     * @return array{mapping: array<string, mixed>, outcomes: array<int, array{effect: string, status: string, reason: string}>}
     */
    private function filter_mapping_with_native_effect_outcomes( array $mapping, array $descriptor ): array
    {
        return [
            'mapping'  => $this->filter_mapping_for_native_capabilities( $mapping, $descriptor ),
            'outcomes' => $this->unsupported_native_effect_outcomes( $mapping, $descriptor ),
        ];
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $descriptor
     *
     * @return array<int, array{effect: string, status: string, reason: string}>
     */
    private function unsupported_native_effect_outcomes( array $mapping, array $descriptor ): array
    {
        $settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] )
            ? $mapping['settings']
            : [];
        $effects = isset( $settings['effect_mapping_json'] ) && is_array( $settings['effect_mapping_json'] )
            ? $settings['effect_mapping_json']
            : [];
        $spam = isset( $effects['spam'] ) && is_array( $effects['spam'] )
            ? $effects['spam']
            : [];
        $native_entry = isset( $descriptor['native_entry'] ) && is_array( $descriptor['native_entry'] )
            ? $descriptor['native_entry']
            : [];
        $native_enrichment = isset( $descriptor['native_enrichment'] ) && is_array( $descriptor['native_enrichment'] )
            ? $descriptor['native_enrichment']
            : [];
        $outcomes = [];

        if ( empty( $native_entry['write'] ) )
        {
            if ( ! empty( $effects['store_result'] ) )
            {
                $this->add_unsupported_native_effect_outcome( $outcomes, 'store_result', 'native_entry_write_unavailable' );
            }
            if ( ! empty( $effects['store_result_meta'] ) )
            {
                $this->add_unsupported_native_effect_outcome( $outcomes, 'store_result_meta', 'native_entry_write_unavailable' );
            }
            if ( ! empty( $effects['meta'] ) )
            {
                $this->add_unsupported_native_effect_outcome( $outcomes, 'entry_metadata', 'native_entry_write_unavailable' );
            }
        }

        if ( empty( $native_enrichment['notes'] ) )
        {
            if ( ! empty( $effects['entry_note'] ) || ! empty( $spam['note'] ) )
            {
                $this->add_unsupported_native_effect_outcome( $outcomes, 'entry_note', 'native_notes_unavailable' );
            }
        }

        if ( empty( $native_enrichment['spam'] ) || empty( $native_enrichment['status'] ) )
        {
            $spam_requested = ! empty( $effects['mark_as_spam'] )
                || ! empty( $spam['enabled'] )
                || isset( $spam['classification_path'] )
                || isset( $spam['confidence_path'] );
            if ( $spam_requested )
            {
                $this->add_unsupported_native_effect_outcome( $outcomes, 'mark_as_spam', 'native_spam_unavailable' );
            }
        }

        if (
            empty( $native_enrichment['notification_controls'] )
            && ( ! empty( $settings['suppress_notifications_on_spam'] )
                || ! empty( $effects['suppress_notifications_on_spam'] )
                || ! empty( $spam['suppress_notifications_on_spam'] ) )
        )
        {
            $this->add_unsupported_native_effect_outcome( $outcomes, 'suppress_notifications', 'notification_controls_unavailable' );
        }

        if (
            empty( $native_enrichment['webhook_controls'] )
            && ( ! empty( $settings['suppress_webhooks_on_spam'] )
                || ! empty( $effects['suppress_webhooks_on_spam'] )
                || ! empty( $spam['suppress_webhooks_on_spam'] ) )
        )
        {
            $this->add_unsupported_native_effect_outcome( $outcomes, 'suppress_webhooks', 'webhook_controls_unavailable' );
        }

        ksort( $outcomes );

        return array_values( $outcomes );
    }

    /**
     * @param array<string, array{effect: string, status: string, reason: string}> $outcomes
     */
    private function add_unsupported_native_effect_outcome(
        array &$outcomes,
        string $effect,
        string $reason
    ): void
    {
        $effect = sanitize_key( $effect );
        if ( '' === $effect )
        {
            return;
        }

        $outcomes[ $effect ] = [
            'effect' => $effect,
            'status' => 'unsupported',
            'reason' => sanitize_key( $reason ),
        ];
    }

    /**
     * Remove native side effects the selected Form Source cannot execute.
     *
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $descriptor
     *
     * @return array<string, mixed>
     */
    private function filter_mapping_for_native_capabilities( array $mapping, array $descriptor ): array
    {
        if ( isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) )
        {
            $mapping['settings'] = $this->filter_runtime_settings_for_native_capabilities( $mapping['settings'], $descriptor );
        }

        return $mapping;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $descriptor
     *
     * @return array<string, mixed>
     */
    private function filter_runtime_settings_for_native_capabilities( array $settings, array $descriptor ): array
    {
        $native_enrichment = isset( $descriptor['native_enrichment'] ) && is_array( $descriptor['native_enrichment'] )
            ? $descriptor['native_enrichment']
            : [];

        if ( isset( $settings['effect_mapping_json'] ) && is_array( $settings['effect_mapping_json'] ) )
        {
            $settings['effect_mapping_json'] = $this->filter_effect_mapping_for_native_capabilities(
                $settings['effect_mapping_json'],
                $descriptor
            );
        }

        if ( empty( $native_enrichment['notification_controls'] ) )
        {
            unset( $settings['suppress_notifications_on_spam'] );
        }
        if ( empty( $native_enrichment['webhook_controls'] ) )
        {
            unset( $settings['suppress_webhooks_on_spam'] );
        }
        if ( empty( $native_enrichment['spam'] ) || empty( $native_enrichment['status'] ) )
        {
            unset(
                $settings['spam_confidence_threshold'],
                $settings['spam_result_display_mode'],
                $settings['spam_indicators_display']
            );
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $effect_mapping
     * @param array<string, mixed> $descriptor
     *
     * @return array<string, mixed>
     */
    private function filter_effect_mapping_for_native_capabilities( array $effect_mapping, array $descriptor ): array
    {
        if ( [] === $effect_mapping )
        {
            return [];
        }

        $native_entry = isset( $descriptor['native_entry'] ) && is_array( $descriptor['native_entry'] )
            ? $descriptor['native_entry']
            : [];
        if ( empty( $native_entry['write'] ) )
        {
            unset( $effect_mapping['store_result'], $effect_mapping['store_result_meta'], $effect_mapping['meta'] );
        }

        $native_enrichment = isset( $descriptor['native_enrichment'] ) && is_array( $descriptor['native_enrichment'] )
            ? $descriptor['native_enrichment']
            : [];
        if ( empty( $native_enrichment['notes'] ) )
        {
            unset( $effect_mapping['entry_note'] );
            if ( isset( $effect_mapping['spam'] ) && is_array( $effect_mapping['spam'] ) )
            {
                unset( $effect_mapping['spam']['note'] );
            }
        }

        if ( empty( $native_enrichment['spam'] ) || empty( $native_enrichment['status'] ) )
        {
            $skip_downstream_on_spam = null;
            if (
                isset( $effect_mapping['spam'] )
                && is_array( $effect_mapping['spam'] )
                && array_key_exists( 'skip_downstream_on_spam', $effect_mapping['spam'] )
            )
            {
                $skip_downstream_on_spam = rest_sanitize_boolean( $effect_mapping['spam']['skip_downstream_on_spam'] );
            }

            unset( $effect_mapping['spam'], $effect_mapping['mark_as_spam'] );
            if ( null !== $skip_downstream_on_spam )
            {
                $effect_mapping['spam'] = [
                    'skip_downstream_on_spam' => $skip_downstream_on_spam,
                ];
            }
        }
        elseif ( isset( $effect_mapping['spam'] ) && is_array( $effect_mapping['spam'] ) )
        {
            if ( empty( $native_enrichment['notification_controls'] ) )
            {
                unset( $effect_mapping['spam']['suppress_notifications_on_spam'] );
            }
            if ( empty( $native_enrichment['webhook_controls'] ) )
            {
                unset( $effect_mapping['spam']['suppress_webhooks_on_spam'] );
            }
        }

        if ( empty( $native_enrichment['notification_controls'] ) )
        {
            unset( $effect_mapping['suppress_notifications_on_spam'] );
        }
        if ( empty( $native_enrichment['webhook_controls'] ) )
        {
            unset( $effect_mapping['suppress_webhooks_on_spam'] );
        }

        return $effect_mapping;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function central_action_id( array $settings ): string
    {
        return isset( $settings['central_action_id'] ) && is_scalar( $settings['central_action_id'] )
            ? sanitize_key( (string) $settings['central_action_id'] )
            : '';
    }

    /**
     * @param array<int, mixed>       $dependency_ids
     * @param array<string, string>   $mapping_outcomes
     * @param array<string, string>   $execution_request_ids
     * @param array<string, mixed>    $action_settings
     *
     * @return array<string, mixed>
     */
    private function dependency_context(
        string $mapping_id,
        array $dependency_ids,
        array $mapping_outcomes,
        array $execution_request_ids,
        array $action_settings
    ): array
    {
        $dependency_initial_outcomes       = [];
        $dependency_execution_request_ids = [];
        foreach ( $dependency_ids as $dependency_id )
        {
            if ( ! is_scalar( $dependency_id ) )
            {
                continue;
            }

            $dependency_id = sanitize_text_field( (string) $dependency_id );
            if ( '' === $dependency_id )
            {
                continue;
            }

            if ( isset( $mapping_outcomes[ $dependency_id ] ) )
            {
                $dependency_initial_outcomes[ $dependency_id ] = $mapping_outcomes[ $dependency_id ];
            }

            if ( isset( $execution_request_ids[ $dependency_id ] ) )
            {
                $dependency_execution_request_ids[ $dependency_id ] = $execution_request_ids[ $dependency_id ];
            }
        }

        return [
            'execution_request_id'             => $execution_request_ids[ $mapping_id ] ?? null,
            'dependency_mapping_ids'           => $dependency_ids,
            'dependency_execution_request_ids' => $dependency_execution_request_ids,
            'dependency_initial_outcomes'      => $dependency_initial_outcomes,
            'dependency_wait_started_at'       => time(),
            'dependency_wait_max_seconds'      => max( 30, (int) ( $action_settings['settings']['batch_settings']['max_wait_seconds'] ?? 600 ) ),
            'dependency_wait_poll_seconds'     => 10,
        ];
    }

    /**
     * @param array<int, mixed>     $dependency_ids
     * @param array<string, string> $mapping_outcomes
     */
    private function resolve_dependency_blocking_mapping(
        array $dependency_ids,
        array $mapping_outcomes,
        bool $allow_queued_dependencies = true
    ): ?string
    {
        foreach ( $dependency_ids as $dependency_id )
        {
            if ( ! is_scalar( $dependency_id ) )
            {
                continue;
            }

            $dependency_id = sanitize_text_field( (string) $dependency_id );
            $blocking_outcomes = $allow_queued_dependencies
                ? [ null, 'failed', 'replayed_failed', 'skipped', 'digest_conflict' ]
                : [ null, 'failed', 'replayed_failed', 'skipped', 'digest_conflict', 'queued', 'replayed_active' ];
            if ( '' !== $dependency_id && in_array( $mapping_outcomes[ $dependency_id ] ?? null, $blocking_outcomes, true ) )
            {
                return $dependency_id;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed>                       $dependency_ids
     * @param array<string, array<string, mixed>>     $resolved_mappings
     * @param array<string, mixed>                    $execution_results
     * @param array<string, mixed>                    $dependent_mapping
     */
    private function should_skip_for_upstream_spam(
        array $dependency_ids,
        array $resolved_mappings,
        array $execution_results,
        array $dependent_mapping
    ): bool
    {
        $dependent_settings = isset( $dependent_mapping['settings'] ) && is_array( $dependent_mapping['settings'] )
            ? $dependent_mapping['settings']
            : [];
        $dependent_opted_in = array_key_exists( 'skip_on_upstream_spam', $dependent_settings )
            && rest_sanitize_boolean( $dependent_settings['skip_on_upstream_spam'] );

        foreach ( $dependency_ids as $dependency_id )
        {
            if ( ! is_scalar( $dependency_id ) )
            {
                continue;
            }

            $dependency_id = sanitize_text_field( (string) $dependency_id );
            $mapping       = $resolved_mappings[ $dependency_id ] ?? null;
            $result        = $execution_results[ $dependency_id ] ?? null;
            if ( ! is_array( $mapping ) || ! is_array( $result ) )
            {
                continue;
            }

            if ( ! in_array( $this->extract_spam_classification( $result ), [ 'spam', 'likely_spam' ], true ) )
            {
                continue;
            }

            if ( $dependent_opted_in )
            {
                return true;
            }

            if ( ! $this->is_spam_action_id( $this->central_action_id( $mapping ) ) )
            {
                continue;
            }

            $settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) ? $mapping['settings'] : [];
            if ( ! array_key_exists( 'skip_downstream_on_spam', $settings )
                || rest_sanitize_boolean( $settings['skip_downstream_on_spam'] ) )
            {
                return true;
            }
        }

        return false;
    }

    private function is_spam_action_id( string $action_id ): bool
    {
        return in_array( sanitize_key( $action_id ), [ 'spam_detection_v1', 'spam_analysis' ], true );
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extract_spam_classification( array $result ): string
    {
        $candidates = [
            $result['classification'] ?? null,
            $result['result_data']['classification'] ?? null,
            $result['result_data']['structured_output']['classification'] ?? null,
            $result['structured_output']['classification'] ?? null,
        ];
        foreach ( $candidates as $candidate )
        {
            if ( is_scalar( $candidate ) )
            {
                $classification = sanitize_key( (string) $candidate );
                if ( '' !== $classification )
                {
                    return $classification;
                }
            }
        }

        return '';
    }

    /**
     * Merge local WordPress mappings into the runtime planner shape.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function merge_local_first_form_mappings(
        array $settings,
        string $form_source,
        mixed $form_id
    ): array
    {
        if ( ! class_exists( 'Sentient_Forms_Form_Mappings_Repository' ) )
        {
            return $settings;
        }

        $form_id = Sentient_Forms_Provider_Form_Id_Keys::normalize( $form_id );
        if ( '' === $form_id )
        {
            return $settings;
        }

        global $wpdb;
        $repository = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $rows       = $repository->list_for_form( $form_source, $form_id );
        if ( empty( $rows ) )
        {
            return $settings;
        }

        if ( ! isset( $settings['actions'] ) || ! is_array( $settings['actions'] ) )
        {
            $settings['actions'] = [];
        }

        foreach ( $rows as $row )
        {
            $runtime_mapping = $this->normalize_local_first_form_mapping( $row );
            if ( null === $runtime_mapping )
            {
                continue;
            }

            $mapping_id = (string) $runtime_mapping['local_mapping_id'];
            $settings[ $mapping_id ]            = $runtime_mapping;
            $settings['actions'][ $mapping_id ] = $runtime_mapping;
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function normalize_local_first_form_mapping( array $row ): ?array
    {
        $id = absint( $row['id'] ?? 0 );
        if (
            $id <= 0
            || Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION !== Sentient_Forms_Form_Source_Lifecycles::normalize_id( $row['hook'] ?? '' )
            || 'custom_action' !== sanitize_key( (string) ( $row['action_kind'] ?? '' ) )
        )
        {
            return null;
        }

        $identity = $this->resolve_local_first_action_identity(
            $this->get_local_first_custom_action( absint( $row['action_id'] ?? 0 ) )
        );
        $settings = is_array( $row['settings_json'] ?? null ) ? $row['settings_json'] : [];
        $settings['local_form_mapping_id'] = $id;
        $settings['execution_mode']        = Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION;
        $settings['input_mapping']         = is_array( $row['input_bindings_json'] ?? null ) ? $row['input_bindings_json'] : [];
        if ( ! isset( $settings['trigger_sources'] ) || ! is_array( $settings['trigger_sources'] ) )
        {
            $settings['trigger_sources'] = [
                Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION => [ 'type' => 'hook_root' ],
            ];
        }

        if ( isset( $row['conditions_json'] ) && is_array( $row['conditions_json'] ) )
        {
            $settings['conditions'] = $row['conditions_json'];
        }
        if ( isset( $row['effect_mapping_json'] ) && is_array( $row['effect_mapping_json'] ) )
        {
            $settings['effect_mapping_json'] = $row['effect_mapping_json'];
        }
        $settings['linked_action_status'] = $identity['linked_action_status'];
        $settings['repair_state']         = $identity['repair_state'];

        return [
            'local_mapping_id'           => 'local_first_' . $id,
            'local_form_mapping_id'      => $id,
            'central_action_id'          => $identity['action_code'],
            'action_type_indicator'      => 'local_first',
            'action_kind'                => 'custom_action',
            'action_name_label'          => $identity['action_label'],
            'is_action_enabled_for_form' => ! empty( $row['enabled'] ),
            'trigger_hooks'              => [ Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION ],
            'execution_mode'             => Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION,
            'execution_priority'         => $id,
            'mark_as_spam'               => false,
            'linked_action_status'       => $identity['linked_action_status'],
            'repair_state'               => $identity['repair_state'],
            'settings'                   => $settings,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function get_local_first_custom_action( int $action_id ): ?array
    {
        if ( $action_id <= 0 || ! class_exists( 'Sentient_Forms_Local_Custom_Actions_Repository' ) )
        {
            return null;
        }

        global $wpdb;
        $repository = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );

        return $repository->get( $action_id );
    }

    /**
     * @param array<string, mixed>|null $custom_action
     *
     * @return array{action_code: string, action_label: string, linked_action_status: string, repair_state: string}
     */
    private function resolve_local_first_action_identity( ?array $custom_action ): array
    {
        $linked_action_status = 'missing';
        $repair_state         = 'needs_repair';
        $action_code          = 'sentient_forms_local_custom_action';
        $action_label         = __( 'Local OpenRouter action', 'sentient-forms' );

        if ( is_array( $custom_action ) )
        {
            $linked_action_status = isset( $custom_action['status'] ) && is_scalar( $custom_action['status'] )
                ? sanitize_key( (string) $custom_action['status'] )
                : 'unknown';
            $repair_state = 'active' === $linked_action_status ? 'ok' : 'needs_repair';
            $action_code  = isset( $custom_action['code'] ) && is_scalar( $custom_action['code'] )
                ? sanitize_key( (string) $custom_action['code'] )
                : $action_code;
            $action_label = isset( $custom_action['display_name'] ) && is_scalar( $custom_action['display_name'] )
                ? sanitize_text_field( (string) $custom_action['display_name'] )
                : $action_label;
        }

        $template = $this->get_local_first_template_for_custom_action( $custom_action );
        if ( is_array( $template ) )
        {
            $template_code = isset( $template['code'] ) && is_scalar( $template['code'] )
                ? sanitize_key( (string) $template['code'] )
                : '';
            if ( '' !== $template_code && Sentient_Forms_Bundled_Action_Templates::has( $template_code ) )
            {
                $action_code  = $template_code;
                $action_label = isset( $template['display_name'] ) && is_scalar( $template['display_name'] )
                    ? sanitize_text_field( (string) $template['display_name'] )
                    : $action_label;
            }
        }
        elseif ( is_array( $custom_action ) )
        {
            $custom_code   = isset( $custom_action['code'] ) && is_scalar( $custom_action['code'] )
                ? sanitize_key( (string) $custom_action['code'] )
                : '';
            $template_code = Sentient_Forms_Bundled_Action_Templates::extract_template_code_from_custom_action_code( $custom_code );
            $definition    = '' !== $template_code ? Sentient_Forms_Bundled_Action_Templates::get( $template_code ) : null;
            if ( is_array( $definition ) )
            {
                $action_code  = $template_code;
                $action_label = sanitize_text_field( (string) ( $definition['display_name'] ?? $action_label ) );
            }
        }

        return [
            'action_code'          => $action_code,
            'action_label'         => $action_label,
            'linked_action_status' => $linked_action_status,
            'repair_state'         => $repair_state,
        ];
    }

    /**
     * @param array<string, mixed>|null $custom_action
     *
     * @return array<string, mixed>|null
     */
    private function get_local_first_template_for_custom_action( ?array $custom_action ): ?array
    {
        if ( ! is_array( $custom_action ) || ! class_exists( 'Sentient_Forms_Action_Templates_Repository' ) )
        {
            return null;
        }

        $template_id = absint( $custom_action['template_id'] ?? 0 );
        if ( $template_id <= 0 )
        {
            return null;
        }

        global $wpdb;
        $repository = new Sentient_Forms_Action_Templates_Repository( $wpdb );

        return $repository->get( $template_id );
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private function is_local_first_mapping( array $mapping ): bool
    {
        $indicator = isset( $mapping['action_type_indicator'] ) && is_scalar( $mapping['action_type_indicator'] )
            ? sanitize_key( (string) $mapping['action_type_indicator'] )
            : '';

        return 'local_first' === $indicator
            && isset( $mapping['local_form_mapping_id'] )
            && absint( $mapping['local_form_mapping_id'] ) > 0;
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $action_settings
     * @param array<string, mixed> $async_context
     */
    private function schedule_local_first_mapping(
        string $form_source,
        string $form_id,
        string $native_hook,
        array $form,
        array $entry,
        string $mapping_id,
        array $action_settings,
        string $submission_uuid,
        array $async_context
    ): bool
    {
        $local_mapping_id = absint( $action_settings['local_form_mapping_id'] ?? 0 );
        if ( $local_mapping_id <= 0 )
        {
            return false;
        }

        return $this->plugin->get_async_handler()->schedule_local_mapping(
            $local_mapping_id,
            $form,
            $entry,
            [
                'hook'                  => $native_hook,
                'form_source'           => $form_source,
                'mapping_id'            => $mapping_id,
                'local_mapping_id'      => $mapping_id,
                'local_form_mapping_id' => $local_mapping_id,
                'form_id'               => $form_id,
                'entry_id'              => null,
                'submission_uuid'       => $submission_uuid,
                'action_name_label'     => $action_settings['action_name_label'] ?? __( 'Local OpenRouter action', 'sentient-forms' ),
                'central_action_id'     => $action_settings['central_action_id'] ?? 'sentient_forms_local_custom_action',
                'settings'              => isset( $action_settings['settings'] ) && is_array( $action_settings['settings'] )
                    ? $action_settings['settings']
                    : [],
            ] + $async_context
        );
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, bool>
     */
    private function get_execution_disable_flags( string $form_source, array $settings ): array
    {
        $plugin_settings = get_option( 'sentient_forms_plugin_settings', [] );
        $plugin_settings = is_array( $plugin_settings ) ? $plugin_settings : [];
        $provider_map    = isset( $plugin_settings['execution_provider_disabled'] ) && is_array( $plugin_settings['execution_provider_disabled'] )
            ? $plugin_settings['execution_provider_disabled']
            : [];
        $sf_disabled       = ! empty( $settings['sf_disabled'] );
        $global_disabled   = ! empty( $plugin_settings['execution_global_disabled'] );
        $provider_disabled = ! empty( $provider_map[ sanitize_key( $form_source ) ] );

        return [
            'sf_disabled'        => $sf_disabled,
            'global_disabled'    => $global_disabled,
            'provider_disabled'  => $provider_disabled,
            'effective_disabled' => $sf_disabled || $global_disabled || $provider_disabled,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function normalize_action_wrapper( array $settings ): array
    {
        $actions = isset( $settings['actions'] ) && is_array( $settings['actions'] ) ? $settings['actions'] : [];
        foreach ( $settings as $mapping_key => $mapping )
        {
            if ( in_array( (string) $mapping_key, [ 'actions', 'enabled', 'sf_disabled' ], true ) || ! is_array( $mapping ) || empty( $mapping['central_action_id'] ) )
            {
                continue;
            }

            $mapping_id = isset( $mapping['local_mapping_id'] ) && is_scalar( $mapping['local_mapping_id'] )
                ? sanitize_text_field( (string) $mapping['local_mapping_id'] )
                : sanitize_text_field( (string) $mapping_key );
            if ( '' !== $mapping_id )
            {
                $actions[ $mapping_id ] = $mapping;
            }
        }

        foreach ( $actions as $mapping_id => $mapping )
        {
            if ( is_array( $mapping ) )
            {
                $settings[ (string) $mapping_id ] = $mapping;
            }
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $captured
     *
     * @return array<string, mixed>
     */
    private function ledger_entry_snapshot(
        string $form_source,
        string $form_id,
        array $form,
        array $captured,
        string $submission_uuid
    ): array
    {
        $entry = isset( $captured['logical_fields_json'] ) && is_array( $captured['logical_fields_json'] )
            ? $captured['logical_fields_json']
            : [];
        $entry = $this->add_form_field_aliases( $entry, $form );
        if ( isset( $captured['file_refs_json'] ) && is_array( $captured['file_refs_json'] ) )
        {
            $entry['file_refs'] = $captured['file_refs_json'];
        }

        $entry['id']              = isset( $captured['native_entry_id'] ) && is_scalar( $captured['native_entry_id'] ) && '' !== (string) $captured['native_entry_id']
            ? sanitize_text_field( (string) $captured['native_entry_id'] )
            : null;
        $entry['submission_uuid'] = $submission_uuid;
        $entry['form_source']     = $form_source;
        $entry['form_id']         = $form_id;
        $entry['native_entry_url'] = isset( $captured['native_entry_url'] ) && is_scalar( $captured['native_entry_url'] ) && '' !== (string) $captured['native_entry_url']
            ? esc_url_raw( (string) $captured['native_entry_url'] )
            : null;

        return $entry;
    }

    /**
     * Add source-native field identifiers as runtime aliases without changing
     * the canonical ledger field storage.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $form
     *
     * @return array<string, mixed>
     */
    private function add_form_field_aliases( array $entry, array $form ): array
    {
        $fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : [];
        foreach ( $fields as $field )
        {
            if ( ! is_array( $field ) || empty( $field['storage_eligible'] ) )
            {
                continue;
            }

            $field_id = isset( $field['id'] ) && is_scalar( $field['id'] )
                ? sanitize_text_field( (string) $field['id'] )
                : '';
            if ( '' === $field_id || array_key_exists( $field_id, $entry ) )
            {
                continue;
            }

            foreach ( $this->form_field_storage_keys( $field, $field_id ) as $storage_key )
            {
                if ( array_key_exists( $storage_key, $entry ) )
                {
                    $entry[ $field_id ] = $entry[ $storage_key ];
                    break;
                }
            }
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $field
     *
     * @return array<int, string>
     */
    private function form_field_storage_keys( array $field, string $field_id ): array
    {
        $keys = [];
        foreach ( [ 'label', 'name', 'admin_label', 'adminLabel' ] as $label_key )
        {
            if ( ! isset( $field[ $label_key ] ) || ! is_scalar( $field[ $label_key ] ) )
            {
                continue;
            }

            $label = sanitize_text_field( (string) $field[ $label_key ] );
            if ( 'adminLabel' === $label_key && str_contains( $label, ': ' ) )
            {
                $label = substr( $label, strpos( $label, ': ' ) + 2 );
            }

            $key = sanitize_key( str_replace( [ ' ', '.', '-' ], '_', strtolower( $label ) ) );
            if ( '' !== $key )
            {
                $keys[] = $key;
                $keys[] = sanitize_key( $key . '_field_' . str_replace( '.', '_', $field_id ) );
            }
        }

        $keys[] = sanitize_key( 'field_' . str_replace( '.', '_', $field_id ) );

        return array_values( array_unique( array_filter( $keys ) ) );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function is_plan_node_trigger_unbound( array $node, string $hook ): bool
    {
        $lifecycle_hook = Sentient_Forms_Form_Source_Lifecycles::normalize_id( $hook );
        $hook_keys      = array_values( array_unique( array_filter( [ sanitize_key( $hook ), $lifecycle_hook ] ) ) );
        $trigger_sources = is_array( $node['trigger_sources'] ?? null ) ? $node['trigger_sources'] : [];

        foreach ( $hook_keys as $hook_key )
        {
            $source = is_array( $trigger_sources[ $hook_key ] ?? null ) ? $trigger_sources[ $hook_key ] : null;
            if ( 'unbound' === sanitize_key( (string) ( $source['type'] ?? '' ) ) )
            {
                return true;
            }
        }

        return false;
    }
}
