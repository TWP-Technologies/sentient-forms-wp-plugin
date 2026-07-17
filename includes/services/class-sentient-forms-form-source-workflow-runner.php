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

    /** @var array<string, array<string, mixed>|bool|WP_Error> */
    private array $validation_execution_cache = [];

    /** @var array<string, string> */
    private array $validation_request_ids = [];

    /** @var array<string, true> */
    private array $validation_logged_request_ids = [];

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
     * Execute one source-normalized visitor-validation workflow.
     */
    public function run_validation(
        Sentient_Forms_Validation_Adapter_Interface $adapter,
        mixed $native_validation,
        mixed $native_context = null
    ): Sentient_Forms_Validation_Run_Result
    {
        if ( ! $adapter->is_active() )
        {
            return new Sentient_Forms_Validation_Run_Result();
        }

        $normalized = $adapter->normalize_validation( $native_validation, $native_context );
        if ( is_wp_error( $normalized ) )
        {
            return new Sentient_Forms_Validation_Run_Result();
        }

        $form_source = sanitize_key( $adapter->get_id() );
        $form_id     = isset( $normalized['form_id'] ) && is_scalar( $normalized['form_id'] )
            ? sanitize_text_field( (string) $normalized['form_id'] )
            : '';
        $form        = isset( $normalized['form'] ) && is_array( $normalized['form'] ) ? $normalized['form'] : [];
        $entry       = isset( $normalized['entry'] ) && is_array( $normalized['entry'] ) ? $normalized['entry'] : [];
        $native_hook = sanitize_text_field( $adapter->get_validation_native_hook() );
        if ( '' === $form_source || '' === $form_id || [] === $form || '' === $native_hook )
        {
            return new Sentient_Forms_Validation_Run_Result();
        }

        $settings = $this->get_form_settings( $form_source, $form_id );
        if ( [] === $settings || ! empty( $this->get_execution_disable_flags( $form_source, $settings )['effective_disabled'] ) )
        {
            return new Sentient_Forms_Validation_Run_Result();
        }

        $plan = $this->plugin->get_mapping_dependency_planner()->build_execution_plan(
            $settings,
            Sentient_Forms_Form_Source_Lifecycles::VALIDATION
        );
        $mapping_outcomes      = [];
        $resolved_mappings     = [];
        $execution_results     = [];
        $errors                = [];
        $field_errors          = [];
        $form_error            = null;
        $spam_classifications  = [];
        $spam_payloads         = [];
        $execution_request_ids = [];
        $validation_rejection_traces = [];
        $native_spam_state_supported = $this->supports_native_submission_spam_effect( $adapter );

        foreach ( (array) ( $plan['cycle_ids'] ?? [] ) as $cycle_id )
        {
            if ( is_scalar( $cycle_id ) )
            {
                $mapping_outcomes[ (string) $cycle_id ] = 'skipped';
            }
        }

        foreach ( (array) ( $plan['order'] ?? [] ) as $mapping_id )
        {
            $node = $plan['nodes'][ $mapping_id ] ?? null;
            if ( ! is_array( $node ) || ! isset( $node['mapping'] ) || ! is_array( $node['mapping'] ) )
            {
                continue;
            }

            $mapping = $this->runtime_settings_resolver->resolve_mapping( $node['mapping'], $form_source, $form_id );
            $mapping['local_mapping_id'] = $mapping['local_mapping_id'] ?? $mapping_id;
            $resolved_mappings[ (string) $mapping_id ] = $mapping;
            $action_id = $this->central_action_id( $mapping );
            if ( '' === $action_id )
            {
                continue;
            }

            $request_fingerprint = Sentient_Forms_Execution_Identity::generate(
                $action_id,
                $form,
                $entry,
                [
                    'hook'        => Sentient_Forms_Form_Source_Lifecycles::VALIDATION,
                    'native_hook' => $native_hook,
                    'form_source' => $form_source,
                    'action_id'   => (string) $mapping_id,
                    'mapping_id'  => (string) $mapping_id,
                ]
            );
            if ( ! isset( $this->validation_request_ids[ $request_fingerprint ] ) )
            {
                $this->validation_request_ids[ $request_fingerprint ] = substr(
                    hash( 'sha256', $request_fingerprint . '|' . wp_generate_uuid4() ),
                    0,
                    32
                );
            }
            $execution_request_ids[ (string) $mapping_id ] = $this->validation_request_ids[ $request_fingerprint ];
        }

        foreach ( (array) ( $plan['order'] ?? [] ) as $mapping_id )
        {
            $mapping_key = (string) $mapping_id;
            $node        = $plan['nodes'][ $mapping_id ] ?? null;
            if (
                ! is_array( $node )
                || ! isset( $node['mapping'] )
                || ! is_array( $node['mapping'] )
                || empty( $node['enabled'] )
                || empty( $node['hook_enabled'] )
                || $this->is_plan_node_trigger_unbound( $node, Sentient_Forms_Form_Source_Lifecycles::VALIDATION )
            )
            {
                $mapping_outcomes[ $mapping_key ] = 'skipped';
                continue;
            }

            $mapping  = $resolved_mappings[ $mapping_key ] ?? [];
            $action_id = $this->central_action_id( $mapping );
            if ( '' === $action_id )
            {
                $mapping_outcomes[ $mapping_key ] = 'failed';
                continue;
            }

            $mapping = $this->authorize_runtime_action_policy(
                $adapter,
                $mapping,
                Sentient_Forms_Form_Source_Lifecycles::VALIDATION
            );
            if ( is_wp_error( $mapping ) )
            {
                $mapping_outcomes[ $mapping_key ] = 'failed';
                $errors[ $mapping_key ] = [
                    'code'    => $this->safe_error_code( $mapping, 'action_policy_rejected' ),
                    'message' => __( 'Validation action policy rejected execution.', 'sentient-forms' ),
                ];
                continue;
            }
            $resolved_mappings[ $mapping_key ] = $mapping;

            $dependency_ids = is_array( $node['dependency_ids'] ?? null ) ? $node['dependency_ids'] : [];
            if ( null !== $this->resolve_dependency_blocking_mapping( $dependency_ids, $mapping_outcomes, false ) )
            {
                $mapping_outcomes[ $mapping_key ] = 'skipped';
                continue;
            }
            if ( $this->should_skip_for_upstream_spam( $dependency_ids, $resolved_mappings, $execution_results, $mapping, true ) )
            {
                $mapping_outcomes[ $mapping_key ] = 'skipped';
                continue;
            }
            if ( ! $this->plugin->get_condition_evaluator()->should_execute( $mapping, $entry ) )
            {
                $mapping_outcomes[ $mapping_key ] = 'skipped';
                continue;
            }

            $dependency_context = $this->dependency_context(
                $mapping_key,
                $dependency_ids,
                $mapping_outcomes,
                $execution_request_ids,
                $mapping
            );
            $dependency_context['native_validation_context'] = $normalized['native_context'] ?? $native_context;
            $request_id = $execution_request_ids[ $mapping_key ] ?? '';
            $result     = $this->validation_execution_cache[ $request_id ] ?? null;
            if ( ! array_key_exists( $request_id, $this->validation_execution_cache ) )
            {
                try
                {
                    $result = $this->execute_validation_mapping(
                        $action_id,
                        $form_source,
                        $form_id,
                        $native_hook,
                        $form,
                        $entry,
                        $mapping_key,
                        $mapping,
                        $dependency_context
                    );
                }
                catch ( Throwable $throwable )
                {
                    unset( $throwable );
                    $result = new WP_Error(
                        'sentient_forms_validation_execution_exception',
                        __( 'Validation action failed open.', 'sentient-forms' )
                    );
                }
                if ( '' !== $request_id )
                {
                    $this->validation_execution_cache[ $request_id ] = $result;
                }
            }

            if ( is_wp_error( $result ) )
            {
                $mapping_outcomes[ $mapping_key ] = 'failed';
                $errors[ $mapping_key ] = [
                    'code'    => $this->safe_error_code( $result, 'validation_action_failed' ),
                    'message' => __( 'Validation action failed open.', 'sentient-forms' ),
                ];
                $this->log_validation_failure(
                    $form_source,
                    $form_id,
                    $mapping_key,
                    $mapping,
                    $request_id,
                    $errors[ $mapping_key ]['code']
                );
                continue;
            }

            $mapping_outcomes[ $mapping_key ] = 'succeeded';
            $execution_results[ $mapping_key ] = $result;
            $contract_code  = $this->validation_output_contract_code( $action_id );
            $classification = '';
            $spam_payload   = 'spam_detection_v1' === $contract_code && is_array( $result )
                ? $this->extract_trusted_spam_payload( $result )
                : null;
            if ( is_array( $spam_payload ) && $this->spam_payload_meets_confidence_threshold( $mapping, $spam_payload ) )
            {
                $classification = sanitize_key( (string) ( $spam_payload['classification'] ?? '' ) );
            }
            if ( '' !== $classification )
            {
                $spam_classifications[ $mapping_key ] = $classification;
                $spam_payloads[ $mapping_key ] = $spam_payload;
                if (
                    ! $native_spam_state_supported
                    && in_array( $classification, [ 'spam', 'likely_spam' ], true )
                    && '' !== $request_id
                )
                {
                    $validation_rejection_traces[ $mapping_key ] = [
                        'request_trace_id'   => $request_id,
                        'rejection_trace_id' => 'validation-rejection:' . $request_id,
                    ];
                }
            }

            $validation = 'content_validation_v1' === $contract_code && is_array( $result )
                ? $this->extract_trusted_content_validation_payload( $result )
                : null;
            if ( is_array( $validation ) && false === ( $validation['is_valid'] ?? true ) )
            {
                $field_error_count_before = count( $field_errors );
                if ( '' !== $request_id )
                {
                    $validation_rejection_traces[ $mapping_key ] = [
                        'request_trace_id'   => $request_id,
                        'rejection_trace_id' => 'validation-rejection:' . $request_id,
                    ];
                }
                $message = sanitize_text_field( (string) ( $validation['message'] ?? '' ) );
                if ( '' !== $message && null === $form_error )
                {
                    $form_error = $message;
                }
                foreach ( (array) ( $validation['fields'] ?? [] ) as $field_error )
                {
                    if ( ! is_array( $field_error ) )
                    {
                        continue;
                    }
                    if ( false !== ( $field_error['is_valid'] ?? true ) )
                    {
                        continue;
                    }
                    $field_id     = isset( $field_error['field_id'] ) && is_scalar( $field_error['field_id'] )
                        ? sanitize_text_field( (string) $field_error['field_id'] )
                        : '';
                    $field_message = isset( $field_error['message'] ) && is_scalar( $field_error['message'] )
                        ? sanitize_text_field( (string) $field_error['message'] )
                        : '';
                    if ( '' !== $field_id && '' !== $field_message )
                    {
                        $field_errors[] = [ 'field_id' => $field_id, 'message' => $field_message ];
                    }
                }
                if ( null === $form_error && $field_error_count_before === count( $field_errors ) )
                {
                    $form_error = __( 'This submission could not be validated. Please review your entry and try again.', 'sentient-forms' );
                }
            }

            $blocked = is_array( $validation ) && false === ( $validation['is_valid'] ?? true );
            $blocked = $blocked || in_array( $classification, [ 'spam', 'likely_spam' ], true );
            $this->log_validation_success(
                $form_source,
                $form_id,
                $mapping_key,
                $mapping,
                $request_id,
                is_array( $result ) ? $result : [],
                $blocked,
                null !== $validation || is_array( $spam_payload ),
                $classification
            );
        }

        return new Sentient_Forms_Validation_Run_Result(
            $mapping_outcomes,
            $resolved_mappings,
            $execution_results,
            $errors,
            $form_error,
            $field_errors,
            $spam_classifications,
            $execution_request_ids,
            $spam_payloads,
            $validation_rejection_traces
        );
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $dependency_context
     *
     * @return array<string, mixed>|bool|WP_Error
     */
    private function execute_validation_mapping(
        string $action_id,
        string $form_source,
        string $form_id,
        string $native_hook,
        array $form,
        array $entry,
        string $mapping_id,
        array $mapping,
        array $dependency_context
    ): array | bool | WP_Error
    {
        if ( $this->is_local_first_mapping( $mapping ) )
        {
            $local_mapping_id = absint( $mapping['local_form_mapping_id'] ?? 0 );
            if ( $local_mapping_id <= 0 )
            {
                return new WP_Error( 'sentient_forms_missing_local_mapping_id', __( 'Local form mapping id is missing.', 'sentient-forms' ) );
            }

            return $this->get_local_execution_service()->execute_mapping(
                $local_mapping_id,
                $form,
                $entry,
                [
                    'hook'                  => Sentient_Forms_Form_Source_Lifecycles::VALIDATION,
                    'native_hook'           => $native_hook,
                    'form_source'           => $form_source,
                    'mapping_id'            => $mapping_id,
                    'local_mapping_id'      => $mapping_id,
                    'local_form_mapping_id' => $local_mapping_id,
                    'form_id'               => $form_id,
                    'entry_id'              => $entry['id'] ?? null,
                    'central_action_id'     => $action_id,
                    'effective_action_policy' => $mapping['effective_action_policy'] ?? null,
                    'enabled_facets'        => $mapping['settings']['enabled_facets'] ?? [],
                    Sentient_Forms_Local_Action_Execution_Service::WORKFLOW_SETTINGS_SNAPSHOT_CONTEXT_KEY => true,
                    'settings'              => isset( $mapping['settings'] ) && is_array( $mapping['settings'] )
                        ? $mapping['settings']
                        : [],
                ] + $dependency_context
            );
        }

        return $this->execute_synchronous_mapping(
            $action_id,
            $form_source,
            $form_id,
            $native_hook,
            $form,
            $entry,
            $mapping_id,
            $mapping,
            '',
            $dependency_context
        );
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>|null
     */
    private function extract_trusted_content_validation_payload( array $result ): ?array
    {
        return $this->select_unique_attested_structured_output(
            'content_validation_v1',
            $result,
            [
                [ 'candidate_path' => [ 'result_data', 'structured_output' ], 'container_path' => [ 'result_data' ] ],
                [ 'candidate_path' => [ 'result', 'result_data', 'structured_output' ], 'container_path' => [ 'result', 'result_data' ] ],
                [ 'candidate_path' => [ 'structured' ], 'container_path' => [] ],
                [ 'candidate_path' => [ 'result', 'structured' ], 'container_path' => [ 'result' ] ],
                [ 'candidate_path' => [ 'evaluation_payload', 'result_data', 'structured_output' ], 'container_path' => [ 'evaluation_payload', 'result_data' ] ],
                [ 'candidate_path' => [ 'result', 'evaluation_payload', 'result_data', 'structured_output' ], 'container_path' => [ 'result', 'evaluation_payload', 'result_data' ] ],
            ]
        );
    }

    /**
     * Select one schema-valid payload whose validity marker belongs to the same wrapper.
     *
     * @param array<string, mixed> $result
     * @param array<int, array{candidate_path: array<int, string>, container_path: array<int, string>}> $candidate_specs
     *
     * @return array<string, mixed>|null
     */
    private function select_unique_attested_structured_output(
        string $action_code,
        array $result,
        array $candidate_specs
    ): ?array
    {
        $selected = null;
        foreach ( $candidate_specs as $candidate_spec )
        {
            $container = $this->array_value_at_path( $result, $candidate_spec['container_path'] );
            if ( ! is_array( $container ) || true !== ( $container['structured_output_valid'] ?? null ) )
            {
                continue;
            }

            $candidate = $this->array_value_at_path( $result, $candidate_spec['candidate_path'] );
            if ( ! is_array( $candidate )
                || ! Sentient_Forms_Bundled_Action_Templates::is_structured_output_valid( $action_code, $candidate ) )
            {
                continue;
            }

            if ( null !== $selected && $selected != $candidate ) // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- Schema validation fixes leaf types; loose comparison intentionally ignores key order.
            {
                return null;
            }

            $selected = $candidate;
        }

        return $selected;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, string>   $path
     */
    private function array_value_at_path( array $values, array $path ): mixed
    {
        $value = $values;
        foreach ( $path as $key )
        {
            if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) )
            {
                return null;
            }
            $value = $value[ $key ];
        }

        return $value;
    }

    private function validation_output_contract_code( string $action_id ): string
    {
        $template_code = Sentient_Forms_Bundled_Action_Templates::extract_template_code_from_custom_action_code(
            sanitize_key( $action_id )
        );

        return in_array( $template_code, [ 'content_validation_v1', 'spam_detection_v1' ], true )
            ? $template_code
            : '';
    }

    private function safe_error_code( WP_Error $error, string $fallback ): string
    {
        $code = sanitize_key( (string) $error->get_error_code() );

        return '' !== $code ? $code : $fallback;
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private function log_validation_failure(
        string $form_source,
        string $form_id,
        string $mapping_id,
        array $mapping,
        string $execution_request_id,
        string $error_code
    ): void
    {
        if ( '' !== $execution_request_id && isset( $this->validation_logged_request_ids[ $execution_request_id ] ) )
        {
            return;
        }
        if ( '' !== $execution_request_id )
        {
            $this->validation_logged_request_ids[ $execution_request_id ] = true;
        }

        $this->plugin->get_logger()->info(
            'validation action failed open',
            [
                'hook'           => Sentient_Forms_Form_Source_Lifecycles::VALIDATION,
                'form_source'    => $form_source,
                'form_id'        => $form_id,
                'mapping_id'     => $mapping_id,
                'error_code'     => $error_code,
            ]
        );
        if ( ! class_exists( 'Sentient_Forms_Action_Log_Controller' ) )
        {
            return;
        }

        Sentient_Forms_Action_Log_Controller::log_execution(
            [
                'form_source'          => $form_source,
                'form_id'              => $form_id,
                'action_code'          => $this->central_action_id( $mapping ),
                'action_label'         => $mapping['action_name_label'] ?? $this->central_action_id( $mapping ),
                'status'               => 'error',
                'error_code'           => $error_code,
                'error_message'        => __( 'Validation action failed open.', 'sentient-forms' ),
                'execution_request_id' => $execution_request_id,
                'mapping_id'           => $mapping_id,
            ]
        );
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $result
     */
    private function log_validation_success(
        string $form_source,
        string $form_id,
        string $mapping_id,
        array $mapping,
        string $execution_request_id,
        array $result,
        bool $blocked,
        bool $trusted_structure,
        string $classification
    ): void
    {
        if ( '' !== $execution_request_id && isset( $this->validation_logged_request_ids[ $execution_request_id ] ) )
        {
            return;
        }
        if ( '' !== $execution_request_id )
        {
            $this->validation_logged_request_ids[ $execution_request_id ] = true;
        }

        if ( ! class_exists( 'Sentient_Forms_Action_Log_Controller' ) )
        {
            return;
        }

        $meta           = isset( $result['meta'] ) && is_array( $result['meta'] )
            ? $result['meta']
            : ( isset( $result['result']['meta'] ) && is_array( $result['result']['meta'] ) ? $result['result']['meta'] : [] );
        $credits_used   = absint( $meta['credits_debited'] ?? $meta['credits_used'] ?? 0 );
        $summary        = '' !== $classification
            ? sprintf(
                /* translators: %s: safe classification slug. */
                __( 'Validation action completed with classification: %s.', 'sentient-forms' ),
                $classification
            )
            : __( 'Validation action completed.', 'sentient-forms' );

        $log_entry = [
                'form_source'             => $form_source,
                'form_id'                 => $form_id,
                'action_code'             => $this->central_action_id( $mapping ),
                'action_label'            => $mapping['action_name_label'] ?? $this->central_action_id( $mapping ),
                'status'                  => $blocked ? 'blocked' : 'success',
                'result_summary'          => wp_trim_words( $summary, 20, '...' ),
                'credits_used'            => $credits_used,
                'execution_request_id'    => $execution_request_id,
                'mapping_id'              => $mapping_id,
                'structured_output_valid' => $trusted_structure,
        ];
        if ( '' !== $classification )
        {
            $log_entry['classification'] = $classification;
        }

        Sentient_Forms_Action_Log_Controller::log_execution( $log_entry );
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
        $policy_errors         = [];
        $resolved_mappings     = [];
        $execution_results     = [];
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
            $action_settings = $this->filter_mapping_for_native_capabilities( $action_settings, $capability_descriptor );
            $action_settings['local_mapping_id'] = $action_settings['local_mapping_id'] ?? $mapping_id;
            $resolved_mappings[ (string) $mapping_id ] = $action_settings;
            $central_action_id = $this->central_action_id( $action_settings );
            if ( '' === $central_action_id )
            {
                continue;
            }

            $action_settings = $this->authorize_runtime_action_policy(
                $adapter,
                $action_settings,
                Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION
            );
            if ( is_wp_error( $action_settings ) )
            {
                $policy_errors[ (string) $mapping_id ] = $action_settings;
                continue;
            }
            $resolved_mappings[ (string) $mapping_id ] = $action_settings;

            $execution_request_ids[ (string) $mapping_id ] = Sentient_Forms_Execution_Identity::generate(
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

            $action_settings = $resolved_mappings[ (string) $mapping_id ]
                ?? $this->filter_mapping_for_native_capabilities(
                    $this->runtime_settings_resolver->resolve_mapping( $node['mapping'], $form_source, $form_id ),
                    $capability_descriptor
                );
            $action_settings['local_mapping_id'] = $action_settings['local_mapping_id'] ?? $mapping_id;
            $resolved_mappings[ (string) $mapping_id ] = $action_settings;
            $central_action_id = $this->central_action_id( $action_settings );
            if ( '' === $central_action_id )
            {
                $mapping_outcomes[ (string) $mapping_id ] = 'failed';
                continue;
            }
            if ( isset( $policy_errors[ (string) $mapping_id ] ) )
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

            if ( $this->should_skip_for_upstream_spam( $dependency_ids, $resolved_mappings, $execution_results ) )
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
                                'effective_action_policy' => $action_settings['effective_action_policy'] ?? null,
                                'enabled_facets'   => $action_settings['settings']['enabled_facets'] ?? [],
                                Sentient_Forms_Local_Action_Execution_Service::WORKFLOW_SETTINGS_SNAPSHOT_CONTEXT_KEY => true,
                                'settings'         => isset( $action_settings['settings'] ) && is_array( $action_settings['settings'] )
                                    ? $action_settings['settings']
                                    : [],
                            ] + $dependency_context
                        )
                    );
                    $mapping_outcomes[ (string) $mapping_id ] = $run['outcome'];
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
                        $execution_request_ids[ (string) $mapping_id ] ?? ''
                    );
                }
                continue;
            }

            // Executable Action definitions are local-table rows. Any option-backed
            // mapping that reaches this point is stale and must fail closed.
            $this->record_legacy_mapping_failure(
                $form_source,
                $form_id,
                $native_entry_id,
                (string) $mapping_id,
                $action_settings,
                $submission_uuid,
                $execution_request_ids[ (string) $mapping_id ] ?? ''
            );
            $mapping_outcomes[ (string) $mapping_id ] = 'failed';
        }

        return new Sentient_Forms_Accepted_Submission_Run_Result(
            $submission_uuid,
            $mapping_outcomes,
            $resolved_mappings,
            $execution_results
        );
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private function record_legacy_mapping_failure(
        string $form_source,
        string $form_id,
        ?string $entry_id,
        string $mapping_id,
        array $mapping,
        string $submission_uuid,
        string $execution_request_id
    ): void
    {
        if ( '' === $execution_request_id )
        {
            return;
        }

        $provider_identity = Sentient_Forms_Execution_Identity::resolve_provider_identity(
            [],
            [
                'settings' => isset( $mapping['settings'] ) && is_array( $mapping['settings'] )
                    ? $mapping['settings']
                    : [],
            ],
            null,
            $execution_request_id
        );
        global $wpdb;
        ( new Sentient_Forms_Execution_Events_Repository( $wpdb ) )->record(
            [
                'execution_request_id' => $execution_request_id,
                'mapping_key'          => $mapping_id,
                'action_code'          => $this->central_action_id( $mapping ),
                'action_label'         => $mapping['action_name_label'] ?? $this->central_action_id( $mapping ),
                'form_source'          => $form_source,
                'form_id'              => $form_id,
                'entry_id'             => $entry_id,
                'submission_uuid'      => $submission_uuid,
                'provider'             => $provider_identity['provider'],
                'model'                => $provider_identity['model'],
                'status'               => 'failed',
                'error_code'           => 'legacy_mapping_requires_migration',
                'error_message'        => __( 'This legacy Action mapping must be recreated before it can run.', 'sentient-forms' ),
                'payload_digest'       => hash( 'sha256', $mapping_id . '|' . $submission_uuid ),
            ]
        );
    }

    /**
     * Claim and execute one synchronous accepted mapping exactly once.
     *
     * @param array<string, mixed> $mapping
     * @param callable(): (array<string, mixed>|bool|WP_Error) $execute
     *
     * @return array{outcome: string, result: array<string, mixed>|bool|WP_Error|null}
     */
    private function execute_claimed_synchronous_mapping(
        string $form_source,
        string $form_id,
        ?string $entry_id,
        string $mapping_id,
        array $mapping,
        string $submission_uuid,
        string $execution_request_id,
        callable $execute
    ): array
    {
        if ( '' === $execution_request_id )
        {
            return [
                'outcome' => 'failed',
                'result'  => new WP_Error( 'sentient_forms_missing_execution_request_id', __( 'Synchronous execution identity is missing.', 'sentient-forms' ) ),
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

        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        if ( 'claimed' !== $claim_state )
        {
            $event = $events->get_by_request_id( $execution_request_id );
            if ( 'success' === $claim_state )
            {
                return [
                    'outcome' => 'replayed_success',
                    'result'  => is_array( $event['result_json'] ?? null ) ? $event['result_json'] : [],
                ];
            }
            if ( 'failed' === $claim_state )
            {
                return [
                    'outcome' => 'replayed_failed',
                    'result'  => new WP_Error(
                        isset( $event['error_code'] ) ? sanitize_key( (string) $event['error_code'] ) : 'sentient_forms_synchronous_execution_failed',
                        __( 'Synchronous accepted action previously failed.', 'sentient-forms' )
                    ),
                ];
            }

            return [ 'outcome' => 'replayed_active', 'result' => null ];
        }

        $provider_identity = Sentient_Forms_Execution_Identity::resolve_provider_identity(
            [
                'local_mapping_id' => absint( $mapping['local_form_mapping_id'] ?? 0 ),
            ],
            [
                'local_form_mapping_id' => absint( $mapping['local_form_mapping_id'] ?? 0 ),
                'settings'              => $settings,
            ],
            null,
            $execution_request_id
        );

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
            'provider'             => $provider_identity['provider'],
            'model'                => $provider_identity['model'],
            'status'               => 'running',
            'payload_digest'       => $digest,
        ];
        $events->record( $event_base );

        $result = $execute();
        if ( is_wp_error( $result ) )
        {
            $safe_error = __( 'Synchronous accepted action failed.', 'sentient-forms' );
            $events->record(
                $this->terminal_execution_event_payload(
                    $events,
                    $event_base,
                    [
                        'status'        => 'failed',
                        'error_code'    => $result->get_error_code(),
                        'error_message' => $safe_error,
                    ]
                )
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
                $result
            );

            return [ 'outcome' => 'failed', 'result' => $result ];
        }

        $result_payload = is_array( $result ) ? $result : [ 'completed' => (bool) $result ];
        $stored_result  = class_exists( 'Sentient_Forms_Local_Data_Governance' )
            ? Sentient_Forms_Local_Data_Governance::sanitize_execution_result_for_storage( $result_payload )
            : [];
        $events->record(
            $this->terminal_execution_event_payload(
                $events,
                $event_base,
                [
                    'status'      => 'succeeded',
                    'result_json' => $stored_result,
                ]
            )
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
            $result_payload
        );

        return [ 'outcome' => 'succeeded', 'result' => $result ];
    }

    /**
     * Preserve provider-owned audit details when the workflow claim writes its terminal state.
     *
     * @param array<string, mixed> $event_base
     * @param array<string, mixed> $terminal
     *
     * @return array<string, mixed>
     */
    private function terminal_execution_event_payload(
        Sentient_Forms_Execution_Events_Repository $events,
        array $event_base,
        array $terminal
    ): array
    {
        $existing = $events->get_by_request_id( (string) $event_base['execution_request_id'] );
        if ( is_array( $existing ) )
        {
            foreach ( [ 'provider', 'model', 'token_usage_json', 'cost_json', 'result_json' ] as $field )
            {
                if ( array_key_exists( $field, $existing ) && null !== $existing[ $field ] )
                {
                    $event_base[ $field ] = $existing[ $field ];
                }
            }
        }

        if (
            isset( $event_base['result_json'], $terminal['result_json'] )
            && is_array( $event_base['result_json'] )
            && is_array( $terminal['result_json'] )
        )
        {
            $terminal['result_json'] = array_replace_recursive( $event_base['result_json'], $terminal['result_json'] );
        }

        return array_merge( $event_base, $terminal );
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
        string $execution_request_id
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
        array $result
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
        WP_Error $error
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
        foreach ( [ $settings['dispatch_mode'] ?? null, $mapping['dispatch_mode'] ?? null ] as $dispatch_mode )
        {
            if ( ! is_scalar( $dispatch_mode ) )
            {
                continue;
            }

            $dispatch_mode = sanitize_key( (string) $dispatch_mode );
            if ( in_array( $dispatch_mode, [ 'sync', 'inline' ], true ) )
            {
                return false;
            }
            if ( in_array( $dispatch_mode, [ 'async', 'queued' ], true ) )
            {
                return true;
            }
        }
        if ( array_key_exists( 'async', $settings ) )
        {
            return rest_sanitize_boolean( $settings['async'] );
        }
        if ( isset( $settings['execution_mode'] ) && is_scalar( $settings['execution_mode'] ) )
        {
            $mode = sanitize_key( (string) $settings['execution_mode'] );
            if ( in_array( $mode, [ 'sync', 'inline' ], true ) )
            {
                return false;
            }
            if ( in_array( $mode, [ 'async', 'queued' ], true ) )
            {
                return true;
            }
        }
        if ( array_key_exists( 'async', $mapping ) )
        {
            return rest_sanitize_boolean( $mapping['async'] );
        }
        if ( isset( $mapping['execution_mode'] ) && is_scalar( $mapping['execution_mode'] ) )
        {
            $mode = sanitize_key( (string) $mapping['execution_mode'] );
            if ( in_array( $mode, [ 'sync', 'inline' ], true ) )
            {
                return false;
            }
            if ( in_array( $mode, [ 'async', 'queued' ], true ) )
            {
                return true;
            }
        }

        return false;
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
        return $this->plugin->execute_local_action_mapping(
            $form,
            $entry,
            [
                'hook'              => Sentient_Forms_Form_Source_Lifecycles::VALIDATION,
                'native_hook'       => $native_hook,
                'form_source'       => $form_source,
                'action_id'         => $mapping_id,
                'mapping_id'        => $mapping_id,
                'local_mapping_id'  => $mapping_id,
                'local_form_mapping_id' => absint( $action_settings['local_form_mapping_id'] ?? 0 ),
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
                ? [ null, 'failed', 'replayed_failed', 'skipped' ]
                : [ null, 'failed', 'replayed_failed', 'skipped', 'queued', 'replayed_active' ];
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
     */
    private function should_skip_for_upstream_spam(
        array $dependency_ids,
        array $resolved_mappings,
        array $execution_results,
        array $current_mapping = [],
        bool $require_attestation = false
    ): bool
    {
        $current_settings = isset( $current_mapping['settings'] ) && is_array( $current_mapping['settings'] )
            ? $current_mapping['settings']
            : [];
        $current_skips_on_spam = array_key_exists( 'skip_on_upstream_spam', $current_settings )
            && rest_sanitize_boolean( $current_settings['skip_on_upstream_spam'] );

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

            if ( 'spam_detection_v1' !== $this->validation_output_contract_code( $this->central_action_id( $mapping ) ) )
            {
                continue;
            }

            $settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) ? $mapping['settings'] : [];
            if ( ! $current_skips_on_spam && empty( $settings['skip_downstream_on_spam'] ) )
            {
                continue;
            }

            if ( $require_attestation )
            {
                $spam_payload = $this->extract_trusted_spam_payload( $result );
                $classification = is_array( $spam_payload ) && $this->spam_payload_meets_confidence_threshold( $mapping, $spam_payload )
                    ? sanitize_key( (string) ( $spam_payload['classification'] ?? '' ) )
                    : '';
            }
            else
            {
                $classification = $this->extract_spam_classification( $result );
            }
            if ( in_array( $classification, [ 'spam', 'likely_spam' ], true ) )
            {
                return true;
            }
        }

        return false;
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
            $result['structured']['classification'] ?? null,
            $result['evaluation_payload']['result_data']['classification'] ?? null,
            $result['evaluation_payload']['result_data']['structured_output']['classification'] ?? null,
            $result['result']['classification'] ?? null,
            $result['result']['structured']['classification'] ?? null,
            $result['result']['result_data']['classification'] ?? null,
            $result['result']['result_data']['structured_output']['classification'] ?? null,
            $result['result']['evaluation_payload']['result_data']['classification'] ?? null,
            $result['result']['evaluation_payload']['result_data']['structured_output']['classification'] ?? null,
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
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>|null
     */
    private function extract_trusted_spam_payload( array $result ): ?array
    {
        return $this->select_unique_attested_structured_output(
            'spam_detection_v1',
            $result,
            [
                [ 'candidate_path' => [ 'structured' ], 'container_path' => [] ],
                [ 'candidate_path' => [ 'structured_output' ], 'container_path' => [] ],
                [ 'candidate_path' => [ 'result_data', 'structured_output' ], 'container_path' => [ 'result_data' ] ],
                [ 'candidate_path' => [ 'result', 'structured' ], 'container_path' => [ 'result' ] ],
                [ 'candidate_path' => [ 'result', 'structured_output' ], 'container_path' => [ 'result' ] ],
                [ 'candidate_path' => [ 'result', 'result_data', 'structured_output' ], 'container_path' => [ 'result', 'result_data' ] ],
                [ 'candidate_path' => [ 'evaluation_payload', 'result_data', 'structured_output' ], 'container_path' => [ 'evaluation_payload', 'result_data' ] ],
                [ 'candidate_path' => [ 'result', 'evaluation_payload', 'result_data', 'structured_output' ], 'container_path' => [ 'result', 'evaluation_payload', 'result_data' ] ],
            ]
        );
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $spam_payload
     */
    private function spam_payload_meets_confidence_threshold( array $mapping, array $spam_payload ): bool
    {
        $classification = sanitize_key( (string) ( $spam_payload['classification'] ?? '' ) );
        if ( ! in_array( $classification, [ 'spam', 'likely_spam' ], true ) )
        {
            return true;
        }

        $confidence = $spam_payload['confidence'] ?? null;
        if ( ! is_numeric( $confidence ) )
        {
            return true;
        }

        $settings       = isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) ? $mapping['settings'] : [];
        $effect_mapping = isset( $settings['effect_mapping_json'] ) && is_array( $settings['effect_mapping_json'] )
            ? $settings['effect_mapping_json']
            : ( isset( $mapping['effect_mapping_json'] ) && is_array( $mapping['effect_mapping_json'] ) ? $mapping['effect_mapping_json'] : [] );
        $spam_effect = isset( $effect_mapping['spam'] ) && is_array( $effect_mapping['spam'] ) ? $effect_mapping['spam'] : [];
        $threshold = $settings['spam_confidence_threshold']
            ?? $mapping['spam_confidence_threshold']
            ?? $spam_effect['min_confidence']
            ?? 0.8;
        $threshold = is_numeric( $threshold ) ? max( 0.0, min( 1.0, (float) $threshold ) ) : 0.8;

        return (float) $confidence >= $threshold;
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
        $id        = absint( $row['id'] ?? 0 );
        $lifecycle = Sentient_Forms_Form_Source_Lifecycles::normalize_id( $row['hook'] ?? '' );
        if (
            $id <= 0
            || ! in_array(
                $lifecycle,
                [ Sentient_Forms_Form_Source_Lifecycles::VALIDATION, Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION ],
                true
            )
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
        $settings['execution_mode']        = $lifecycle;
        $settings['dispatch_mode']         = $this->normalize_dispatch_mode( $row['execution_mode'] ?? null, $lifecycle );
        $settings['input_mapping']         = is_array( $row['input_bindings_json'] ?? null ) ? $row['input_bindings_json'] : [];
        if ( ! isset( $settings['trigger_sources'] ) || ! is_array( $settings['trigger_sources'] ) )
        {
            $settings['trigger_sources'] = [
                $lifecycle => [ 'type' => 'hook_root' ],
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
        $mark_as_spam                     = $this->local_spam_effect_enabled( $settings['effect_mapping_json'] ?? [] );

        return [
            'local_mapping_id'           => 'local_first_' . $id,
            'local_form_mapping_id'      => $id,
            'central_action_id'          => $identity['action_code'],
            'action_type_indicator'      => 'local_first',
            'action_kind'                => 'custom_action',
            'action_name_label'          => $identity['action_label'],
            'is_action_enabled_for_form' => ! empty( $row['enabled'] ),
            'trigger_hooks'              => [ $lifecycle ],
            'execution_mode'             => $lifecycle,
            'dispatch_mode'              => $settings['dispatch_mode'],
            'execution_priority'         => $id,
            'mark_as_spam'               => $mark_as_spam,
            'linked_action_status'       => $identity['linked_action_status'],
            'repair_state'               => $identity['repair_state'],
            'settings'                   => $settings,
        ];
    }

    private function normalize_dispatch_mode( mixed $value, string $lifecycle ): string
    {
        $mode = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
        if ( in_array( $mode, [ 'sync', 'inline' ], true ) )
        {
            return 'sync';
        }
        if ( in_array( $mode, [ 'async', 'queued' ], true ) )
        {
            return 'async';
        }

        return Sentient_Forms_Form_Source_Lifecycles::VALIDATION === $lifecycle ? 'sync' : 'async';
    }

    /**
     * Resolve code-owned Action and facet policy before any provider or dispatch
     * decision. Custom definitions remain unrestricted but still use the same
     * source-neutral runner and lifecycle.
     *
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>|WP_Error
     */
    private function authorize_runtime_action_policy(
        object $adapter,
        array $mapping,
        string $lifecycle
    ): array | WP_Error
    {
        $action_code = $this->central_action_id( $mapping );
        $definition  = Sentient_Forms_Bundled_Action_Templates::get( $action_code );
        if ( ! is_array( $definition ) )
        {
            return $mapping;
        }

        $settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) ? $mapping['settings'] : [];
        $enabled_facets = is_array( $settings['enabled_facets'] ?? null )
            ? $settings['enabled_facets']
            : ( is_array( $mapping['enabled_facets'] ?? null ) ? $mapping['enabled_facets'] : [] );
        $effective = ( new Sentient_Forms_Action_Runtime_Policy_Gate() )->authorize(
            $definition,
            $enabled_facets,
            $lifecycle,
            $this->runtime_source_capabilities( $adapter )
        );
        if ( is_wp_error( $effective ) )
        {
            return $effective;
        }

        $mapping['effective_action_policy'] = $effective;
        $settings['effective_action_policy'] = $effective;
        $settings['enabled_facets'] = array_values( array_unique( array_map( 'sanitize_key', $enabled_facets ) ) );
        $mapping['settings'] = $settings;

        return $mapping;
    }

    /**
     * @return array<string, bool>
     */
    private function runtime_source_capabilities( object $adapter ): array
    {
        $validation = $adapter instanceof Sentient_Forms_Native_Validation_Effects_Adapter_Interface
            ? $adapter->get_structural_validation_effect_capabilities()
            : [];
        $realtime = $adapter instanceof Sentient_Forms_Realtime_Adapter_Interface
            ? $adapter->get_structural_realtime_capabilities()
            : [];

        return [
            'accepted_submission'  => $adapter instanceof Sentient_Forms_Accepted_Submission_Adapter_Interface,
            'field_errors'         => true === ( $validation['field_errors'] ?? false ),
            'realtime_qna_storage' => true === ( $realtime['qna_storage'] ?? false ),
        ];
    }

    private function supports_native_submission_spam_effect( object $adapter ): bool
    {
        if ( ! $adapter instanceof Sentient_Forms_Native_Validation_Effects_Adapter_Interface )
        {
            return false;
        }

        $capabilities = $adapter->get_structural_validation_effect_capabilities();

        return true === ( $capabilities['submission_spam'] ?? false );
    }

    /**
     * @param array<string, mixed> $effects
     */
    private function local_spam_effect_enabled( array $effects ): bool
    {
        if ( isset( $effects['spam'] ) )
        {
            if ( is_array( $effects['spam'] ) )
            {
                if ( array_key_exists( 'enabled', $effects['spam'] ) )
                {
                    return rest_sanitize_boolean( $effects['spam']['enabled'] );
                }

                foreach ( [ 'classification_path', 'confidence_path', 'min_confidence', 'mark_as_spam' ] as $control_key )
                {
                    if ( array_key_exists( $control_key, $effects['spam'] ) )
                    {
                        return true;
                    }
                }

                return false;
            }

            return rest_sanitize_boolean( $effects['spam'] );
        }

        return ! empty( $effects['mark_as_spam'] );
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
                'effective_action_policy' => $action_settings['effective_action_policy'] ?? null,
                'enabled_facets'        => $action_settings['settings']['enabled_facets'] ?? [],
                Sentient_Forms_Local_Action_Execution_Service::WORKFLOW_SETTINGS_SNAPSHOT_CONTEXT_KEY => true,
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
