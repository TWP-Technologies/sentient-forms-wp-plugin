<?php
/**
 * Gravity Forms adapter
 *
 * @package Sentient_Forms
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Gravity_Forms_Adapter
 * Adapter for Gravity Forms integration
 */
class Sentient_Forms_Gravity_Forms_Adapter implements Sentient_Forms_Adapter_Interface, Sentient_Forms_Async_Capable_Adapter_Interface
{
    private const REALTIME_DEFAULT_DEBOUNCE_MS = 600;
    private const REALTIME_DEFAULT_COOLDOWN_MS = 8000;
    private const REALTIME_MIN_DEBOUNCE_MS = 300;
    private const REALTIME_MAX_DEBOUNCE_MS = 3000;
    private const REALTIME_MIN_COOLDOWN_MS = 1000;
    private const REALTIME_MAX_COOLDOWN_MS = 60000;
    private const FORM_ACTION_CONFIG_OPTION_PREFIX = 'sentient_forms_form_config_';
    private const ACTION_DEFAULTS_OPTION_PREFIX = 'sentient_forms_action_defaults_';
    private const SPAM_NOTIFICATION_PREFERENCE_META_KEY = 'spam_notification_preference';
    private const SPAM_NOTIFICATION_PREFERENCE_SUPPRESS = 'suppress';
    private const SPAM_NOTIFICATION_PREFERENCE_ALLOW = 'allow';
    private const DEFERRED_NOTIFICATION_IDS_META_KEY = 'deferred_notification_ids';
    private const DEFERRED_NOTIFICATION_MAPPING_IDS_META_KEY = 'deferred_notification_mapping_ids';
    private const DEFERRED_NOTIFICATION_DECISION_META_KEY = 'deferred_notification_decision';
    private const DEFERRED_NOTIFICATION_DECISION_PENDING = 'pending';
    private const DEFERRED_NOTIFICATION_DECISION_SUPPRESS = 'suppress';
    private const DEFERRED_NOTIFICATION_REPLAY_FLAG = 'sentient_forms_async_spam_notification_replay';
    private const DEFERRED_NOTIFICATION_ALLOWED_IDS = 'sentient_forms_allowed_notification_ids';

    /**
     * Plugin instance
     */
    private Sentient_Forms_Plugin $plugin;

    /**
     * Local-first execution service, lazily initialized for direct provider runs.
     */
    private ?Sentient_Forms_Local_Action_Execution_Service $local_execution_service = null;

    /**
     * Cache async spam notification mapping checks per form/entry.
     *
     * @var array<string, array<int, string>>
     */
    private array $async_spam_notification_gate_cache = [];

    /**
     * Validation-hook audit request ids that can be linked once Gravity Forms saves the entry.
     *
     * @var array<int, array<int, string>>
     */
    private array $validation_execution_request_ids_by_form = [];

    /**
     * Constructor
     *
     * @param Sentient_Forms_Plugin $plugin Plugin instance.
     */
    public function __construct( Sentient_Forms_Plugin $plugin )
    {
        $this->plugin = $plugin;
    }

    /**
     * Get the adapter ID
     *
     * @return string
     */
    public function get_id(): string
    {
        return 'gravity_forms';
    }

    /**
     * Get the adapter name
     *
     * @return string
     */
    public function get_name(): string
    {
        return __( 'Gravity Forms', 'sentient-forms' );
    }

    /**
     * Initialize the adapter
     *
     * @return void
     */
    public function init(): void
    {
        if ( !$this->is_active() )
        {
            return;
        }

        $this->register_hooks();
    }

    /**
     * Register hooks for the adapter
     *
     * @return void
     */
    public function register_hooks(): void
    {
        // Register hooks for all forms
        add_filter( 'gform_validation', [ $this, 'handle_validation' ], 10, 1 );
        add_filter( 'gform_entry_post_save', [ $this, 'handle_after_submission_entry_post_save' ], 10, 2 );

        // FR-003: Notification interception hook - suppress notifications for blocking spam entries only
        add_filter( 'gform_notification', [ $this, 'maybe_suppress_spam_notification' ], 10, 3 );
        add_filter( 'gform_disable_notification', [ $this, 'maybe_defer_async_spam_notification' ], 10, 5 );

        // Add settings to the form editor
        add_action( 'gform_editor_js', [ $this, 'editor_js' ] );
        add_filter( 'gform_tooltips', [ $this, 'add_tooltips' ] );
        add_action( 'gform_field_standard_settings', [ $this, 'field_settings' ], 10, 2 );
        add_action( 'gform_enqueue_scripts', [ $this, 'enqueue_realtime_suggestions_runtime' ], 20, 2 );

        add_filter( 'sentient_forms_async_evaluation_jobs', [ $this, 'filter_async_evaluation_jobs' ], 10, 3 );
    }

    /**
     * Execute logical after-submission mappings after the entry is saved, before Gravity Forms dispatches notifications.
     *
     * Gravity Forms sends form-submission notifications before the later gform_after_submission action,
     * so blocking/background execution must be resolved at entry-post-save time instead of the literal
     * after-submission hook.
     *
     * @param array $entry The saved entry.
     * @param array $form  The form configuration.
     *
     * @return array The original entry for Gravity Forms' filter contract.
     */
    public function handle_after_submission_entry_post_save( array $entry, array $form ): array
    {
        $form_id                          = absint( $form['id'] ?? 0 );
        $validation_execution_request_ids = $form_id > 0
            ? ( $this->validation_execution_request_ids_by_form[ $form_id ] ?? [] )
            : [];

        $this->backfill_validation_action_log_entry_ids( $entry, $form );
        $this->apply_validation_local_execution_results_to_entry( $validation_execution_request_ids, $entry, $form );
        $this->handle_after_submission( $entry, $form );

        return $entry;
    }

    private function backfill_validation_action_log_entry_ids( array $entry, array $form ): void
    {
        if ( ! class_exists( 'Sentient_Forms_Action_Log_Controller' ) )
        {
            return;
        }

        $entry_id = absint( $entry['id'] ?? 0 );
        $form_id  = absint( $form['id'] ?? 0 );
        if ( $entry_id <= 0 || $form_id <= 0 )
        {
            return;
        }

        $execution_request_ids = $this->validation_execution_request_ids_by_form[ $form_id ] ?? [];
        if ( empty( $execution_request_ids ) )
        {
            return;
        }

        Sentient_Forms_Action_Log_Controller::backfill_entry_id_for_execution_requests(
            $execution_request_ids,
            $entry_id,
            $this->get_id(),
            $form_id,
        );

        unset( $this->validation_execution_request_ids_by_form[ $form_id ] );
    }

    private function remember_validation_execution_request_id( int $form_id, ?string $execution_request_id ): void
    {
        if ( $form_id <= 0 || null === $execution_request_id || '' === $execution_request_id )
        {
            return;
        }

        if ( ! isset( $this->validation_execution_request_ids_by_form[ $form_id ] ) )
        {
            $this->validation_execution_request_ids_by_form[ $form_id ] = [];
        }

        $this->validation_execution_request_ids_by_form[ $form_id ][] = $execution_request_id;
        $this->validation_execution_request_ids_by_form[ $form_id ] = array_values(
            array_unique( $this->validation_execution_request_ids_by_form[ $form_id ] )
        );
    }

    /**
     * Replay local validation effects once Gravity Forms has created an entry.
     *
     * Local validation actions run before an entry id exists. Their execution event can be
     * recorded during validation, but entry meta, spam flags, and notes must wait until
     * gform_entry_post_save provides the saved entry.
     *
     * @param array<int, string> $execution_request_ids Validation execution request ids.
     * @param array              $entry                 Saved Gravity Forms entry.
     * @param array              $form                  Gravity Forms form.
     *
     * @return void
     */
    private function apply_validation_local_execution_results_to_entry( array $execution_request_ids, array $entry, array $form ): void
    {
        $entry_id = absint( $entry['id'] ?? 0 );
        $form_id  = absint( $form['id'] ?? 0 );
        if ( $entry_id <= 0 || $form_id <= 0 || empty( $execution_request_ids ) )
        {
            return;
        }

        if (
            ! class_exists( 'Sentient_Forms_Execution_Events_Repository' )
            || ! class_exists( 'Sentient_Forms_Form_Mappings_Repository' )
            || ! class_exists( 'Sentient_Forms_Local_Custom_Actions_Repository' )
            || ! class_exists( 'Sentient_Forms_Local_Result_Applier' )
        )
        {
            return;
        }

        global $wpdb;

        $events        = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $mappings      = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $applier       = new Sentient_Forms_Local_Result_Applier();

        foreach ( $this->normalize_validation_execution_request_ids( $execution_request_ids ) as $execution_request_id )
        {
            $event = $events->get_by_request_id( $execution_request_id );
            if ( ! is_array( $event ) )
            {
                continue;
            }

            if ( ( $event['form_source'] ?? '' ) !== $this->get_id() || absint( $event['form_id'] ?? 0 ) !== $form_id )
            {
                continue;
            }

            $mapping_id = absint( $event['mapping_id'] ?? 0 );
            if ( $mapping_id <= 0 )
            {
                continue;
            }

            $mapping = $mappings->get( $mapping_id );
            if ( ! is_array( $mapping ) || 'gform_validation' !== (string) ( $mapping['hook'] ?? '' ) )
            {
                continue;
            }

            $event['entry_id'] = (string) $entry_id;

            if ( 'succeeded' === sanitize_key( (string) ( $event['status'] ?? '' ) ) )
            {
                $action = [];
                if ( 'custom_action' === sanitize_key( (string) ( $mapping['action_kind'] ?? '' ) ) )
                {
                    $action = $custom_actions->get( absint( $mapping['action_id'] ?? 0 ) ) ?? [];
                }

                $result = is_array( $event['result_json'] ?? null ) ? $event['result_json'] : [];
                $execution_result = [
                    'execution_request_id' => $execution_request_id,
                    'status'               => 'succeeded',
                    'provider'             => $event['provider'] ?? 'openrouter',
                    'model'                => $event['model'] ?? null,
                    'cached'               => false,
                    'result'               => $result,
                ];

                $effects = $applier->apply( $mapping, $form, $entry, $execution_result, $action );
                if ( ! is_wp_error( $effects ) )
                {
                    $result['effects']    = $effects;
                    $event['result_json'] = Sentient_Forms_Local_Data_Governance::sanitize_execution_result_for_storage( $result );

                    if ( $this->local_spam_effect_enabled( $mapping ) )
                    {
                        $this->record_blocking_spam_notification_state( $entry_id, $mapping, $execution_result );
                    }
                }

                $events->record( $event );
                continue;
            }

            if ( 'failed' === sanitize_key( (string) ( $event['status'] ?? '' ) ) )
            {
                $reason = isset( $event['error_message'] ) && '' !== trim( (string) $event['error_message'] )
                    ? trim( (string) $event['error_message'] )
                    : __( 'Unknown local provider error.', 'sentient-forms' );
                $note = sprintf(
                    /* translators: %s: local action failure reason */
                    __( 'Sentient Forms could not complete Local OpenRouter action. Reason: %s', 'sentient-forms' ),
                    $reason
                );
                $this->add_local_action_entry_note_if_missing( $entry_id, 'Sentient Forms AI', $note );
                $events->record( $event );
            }
        }
    }

    /**
     * @param array<int, string> $execution_request_ids Raw request ids.
     *
     * @return array<int, string>
     */
    private function normalize_validation_execution_request_ids( array $execution_request_ids ): array
    {
        $normalized = [];
        foreach ( $execution_request_ids as $execution_request_id )
        {
            if ( ! is_scalar( $execution_request_id ) )
            {
                continue;
            }

            $execution_request_id = sanitize_text_field( (string) $execution_request_id );
            if ( '' !== $execution_request_id )
            {
                $normalized[ $execution_request_id ] = $execution_request_id;
            }
        }

        return array_values( $normalized );
    }

    /**
     * Handle form validation
     *
     * @param array $validation_result The validation result.
     *
     * @return array The modified validation result.
     */
    public function handle_validation( array $validation_result ): array
    {
        $form    = $validation_result[ 'form' ];
        $form_id = $form[ 'id' ];
        $logger  = $this->plugin->get_logger();
        $correlation_id = $logger->correlation_id( $form['sentient_forms_request_id'] ?? null );

        // Get form settings
        $settings = $this->get_form_settings( $form_id );

        $disable_flags = $this->get_execution_disable_flags( $settings );

        // CB-FORMS-001 / CB-FORMS-002: Skip all actions when effective execution disable is enabled.
        if ( ! empty( $disable_flags['effective_disabled'] ) )
        {
            $logger->info(
                'form execution disabled, skipping all validation actions',
                [
                    'form_id'           => $form_id,
                    'sf_disabled'       => ! empty( $disable_flags['sf_disabled'] ),
                    'global_disabled'   => ! empty( $disable_flags['global_disabled'] ),
                    'provider_disabled' => ! empty( $disable_flags['provider_disabled'] ),
                ]
            );
            return $validation_result;
        }

        $entry            = $this->prepare_entry_from_submission();
        $planner          = $this->plugin->get_mapping_dependency_planner();
        $plan             = $planner->build_execution_plan( $settings, 'gform_validation' );
        $mapping_outcomes = [];
        $mapping_classifications = [];

        if ( ! empty( $plan['cycle_ids'] ) )
        {
            $logger->error(
                'validation dependency cycle detected; skipping blocked mappings',
                [
                    'hook'       => 'gform_validation',
                    'form_id'    => $form_id,
                    'cycle_ids'  => $plan['cycle_ids'],
                ]
            );
            foreach ( $plan['cycle_ids'] as $cycle_id )
            {
                $mapping_outcomes[ $cycle_id ] = 'skipped';
            }
        }

        foreach ( $plan['order'] as $mapping_id )
        {
            $node = $plan['nodes'][ $mapping_id ] ?? null;
            if ( ! is_array( $node ) || ! isset( $node['mapping'] ) || ! is_array( $node['mapping'] ) )
            {
                continue;
            }

            $action_settings = $this->resolve_mapping_runtime_settings( $node['mapping'], $form_id );
            $action_settings['local_mapping_id'] = $action_settings['local_mapping_id'] ?? $mapping_id;
            $should_async = $this->is_mapping_async( $action_settings );

            if ( empty( $node['enabled'] ) || empty( $node['hook_enabled'] ) )
            {
                $mapping_outcomes[ $mapping_id ] = 'skipped';
                continue;
            }

            $blocked_by_dependency = $this->resolve_dependency_blocking_mapping(
                is_array( $node['dependency_ids'] ?? null ) ? $node['dependency_ids'] : [],
                $mapping_outcomes,
                $should_async,
            );
            if ( null !== $blocked_by_dependency )
            {
                $mapping_outcomes[ $mapping_id ] = 'skipped';
                $logger->info(
                    'validation skipped due to dependency outcome',
                    [
                        'hook'                   => 'gform_validation',
                        'mapping_id'             => $mapping_id,
                        'blocked_by_dependency'  => $blocked_by_dependency,
                        'dependency_outcome'     => $mapping_outcomes[ $blocked_by_dependency ] ?? null,
                        'form_id'                => $form_id,
                        'correlation_id'         => $correlation_id,
                    ]
                );
                continue;
            }

            $action_id = (string) ( $action_settings['central_action_id'] ?? '' );
            if ( '' === $action_id )
            {
                $mapping_outcomes[ $mapping_id ] = 'failed';
                continue;
            }

            $action = $this->plugin->get_action( $action_id );

            if ( ! $this->plugin->get_condition_evaluator()->should_execute( $action_settings, $entry ) )
            {
                $mapping_outcomes[ $mapping_id ] = 'skipped';
                $logger->info(
                    'validation skipped by mapping conditions',
                    [
                        'hook'           => 'gform_validation',
                        'action_id'      => $action_id,
                        'mapping_id'     => $mapping_id,
                        'form_id'        => $form_id,
                        'correlation_id' => $correlation_id,
                    ]
                );
                continue;
            }

            $upstream_spam_skip = $this->resolve_upstream_spam_skip_classification(
                $action_settings,
                is_array( $node['dependency_ids'] ?? null ) ? $node['dependency_ids'] : [],
                $mapping_classifications,
                $plan['nodes'],
                $form_id,
            );
            if ( null !== $upstream_spam_skip )
            {
                $mapping_outcomes[ $mapping_id ] = 'skipped';
                $logger->info(
                    'validation skipped because upstream spam check classified submission as spam',
                    [
                        'hook'           => 'gform_validation',
                        'action_id'      => $action_id,
                        'mapping_id'     => $mapping_id,
                        'form_id'        => $form_id,
                        'correlation_id' => $correlation_id,
                        'classification' => $upstream_spam_skip,
                    ]
                );
                continue;
            }

            $logger->info(
                'validation start',
                [
                    'hook'           => 'gform_validation',
                    'action_id'      => $action_id,
                    'mapping_id'     => $mapping_id,
                    'form_id'        => $form_id,
                    'correlation_id' => $correlation_id,
                ]
            );

            $data  = [
                'form'              => $form,
                'entry'             => $entry,
                'validation_result' => $validation_result,
            ];

            $entry_id        = $entry['id'] ?? ( $data['entry']['id'] ?? 0 );
            $runtime_form_id = $form['id'] ?? 0;
            $local_failed    = false;

            if ( $this->is_local_first_mapping( $action_settings ) )
            {
                $result = $this->execute_local_first_validation_mapping(
                    $form,
                    $entry,
                    $mapping_id,
                    $action_settings,
                );

                if ( is_wp_error( $result ) )
                {
                    $local_failed = true;
                    $logger->info(
                        'local-first validation wp_error',
                        [
                            'hook'           => 'gform_validation',
                            'action_id'      => $action_id,
                            'mapping_id'     => $mapping_id,
                            'form_id'        => $runtime_form_id,
                            'correlation_id' => $correlation_id,
                            'error_code'     => $result->get_error_code(),
                        ]
                    );
                }

                $validation_result = $this->apply_local_first_validation_response( $validation_result, $result, $action_settings );
                $this->record_mapping_spam_classification( $mapping_classifications, $mapping_id, $result );

                $mapping_outcomes[ $mapping_id ] = $local_failed ? 'failed' : 'succeeded';

                $logger->info(
                    'validation complete',
                    [
                        'hook'           => 'gform_validation',
                        'action_id'      => $action_id,
                        'mapping_id'     => $mapping_id,
                        'outcome'        => $mapping_outcomes[ $mapping_id ],
                        'form_id'        => $runtime_form_id,
                        'correlation_id' => $correlation_id,
                    ]
                );

                continue;
            }

            if ( $action )
            {
                $result = $action->execute( $data, $action_settings, $entry_id, $runtime_form_id );

                if ( is_wp_error( $result ) )
                {
                    $local_failed = true;
                    $logger->info(
                        'validation wp_error',
                        [
                            'hook'           => 'gform_validation',
                            'action_id'      => $action_id,
                            'mapping_id'     => $mapping_id,
                            'form_id'        => $runtime_form_id,
                            'correlation_id' => $correlation_id,
                            'error_code'     => $result->get_error_code(),
                        ]
                    );
                    $validation_result = $this->inject_validation_message(
                        $validation_result,
                        $result->get_error_message(),
                        $action_settings,
                    );
                }
                elseif ( isset( $result[ 'validation_result' ] ) )
                {
                    $validation_result = $result[ 'validation_result' ];
                }

                $this->record_mapping_spam_classification( $mapping_classifications, $mapping_id, $result );
            }

            $cps_execution_status = 'success';
            $cps_response         = null;
            $validation_result    = $this->maybe_execute_cps_validation(
                $validation_result,
                $form,
                $entry,
                $action_id,
                $action_settings,
                $cps_execution_status,
                $cps_response,
            );
            $this->record_mapping_spam_classification( $mapping_classifications, $mapping_id, $cps_response );

            $mapping_outcomes[ $mapping_id ] = ( $local_failed || 'failed' === $cps_execution_status ) ? 'failed' : 'succeeded';

            $logger->info(
                'validation complete',
                [
                    'hook'           => 'gform_validation',
                    'action_id'      => $action_id,
                    'mapping_id'     => $mapping_id,
                    'outcome'        => $mapping_outcomes[ $mapping_id ],
                    'form_id'        => $runtime_form_id,
                    'correlation_id' => $correlation_id,
                ]
            );
        }

        return $validation_result;
    }

    /**
     * Handle form submission
     *
     * @param array $entry The entry that was created.
     * @param array $form  The form object.
     *
     * @return void
     */
    public function handle_after_submission( array $entry, array $form ): void
    {
        $form_id = $form[ 'id' ];
        $logger  = $this->plugin->get_logger();
        $correlation_id = $logger->correlation_id( $entry['id'] ?? null );

        // Get form settings - these are stored directly under local_mapping_id keys
        $settings = $this->get_form_settings( $form_id );

        $disable_flags = $this->get_execution_disable_flags( $settings );

        // CB-FORMS-001 / CB-FORMS-002: Skip all actions when effective execution disable is enabled.
        if ( ! empty( $disable_flags['effective_disabled'] ) )
        {
            $logger->info(
                'form execution disabled, skipping all after-submission actions',
                [
                    'form_id'           => $form_id,
                    'sf_disabled'       => ! empty( $disable_flags['sf_disabled'] ),
                    'global_disabled'   => ! empty( $disable_flags['global_disabled'] ),
                    'provider_disabled' => ! empty( $disable_flags['provider_disabled'] ),
                ]
            );
            return;
        }

        $planner             = $this->plugin->get_mapping_dependency_planner();
        $plan                = $planner->build_execution_plan( $settings, 'gform_after_submission' );
        $mapping_outcomes    = [];
        $mapping_classifications = [];
        $execution_request_ids = [];

        if ( ! empty( $plan['cycle_ids'] ) )
        {
            $logger->error(
                'after-submission dependency cycle detected; skipping blocked mappings',
                [
                    'hook'       => 'gform_after_submission',
                    'form_id'    => $form_id,
                    'entry_id'   => $entry['id'] ?? null,
                    'cycle_ids'  => $plan['cycle_ids'],
                ]
            );
            foreach ( $plan['cycle_ids'] as $cycle_id )
            {
                $mapping_outcomes[ $cycle_id ] = 'skipped';
            }
        }

        foreach ( $plan['order'] as $mapping_id )
        {
            $node = $plan['nodes'][ $mapping_id ] ?? null;
            if ( ! is_array( $node ) || ! isset( $node['mapping'] ) || ! is_array( $node['mapping'] ) )
            {
                continue;
            }

            $action_settings = $this->resolve_mapping_runtime_settings( $node['mapping'], $form_id );
            if ( empty( $node['enabled'] ) || empty( $node['hook_enabled'] ) )
            {
                continue;
            }

            $central_action_id = (string) ( $action_settings['central_action_id'] ?? '' );
            if ( '' === $central_action_id )
            {
                continue;
            }

            $execution_request_ids[ $mapping_id ] = Sentient_Forms_Action_Executor::generate_execution_request_id(
                $central_action_id,
                $form,
                $entry,
                [
                    'hook'      => 'gform_after_submission',
                    'action_id' => $mapping_id,
                ],
            );
        }

        foreach ( $plan['order'] as $mapping_id )
        {
            $node = $plan['nodes'][ $mapping_id ] ?? null;
            if ( ! is_array( $node ) || ! isset( $node['mapping'] ) || ! is_array( $node['mapping'] ) )
            {
                continue;
            }

            $action_settings = $this->resolve_mapping_runtime_settings( $node['mapping'], $form_id );
            $action_settings['local_mapping_id'] = $action_settings['local_mapping_id'] ?? $mapping_id;
            $should_async = $this->is_mapping_async( $action_settings );

            if ( empty( $node['enabled'] ) || empty( $node['hook_enabled'] ) )
            {
                $mapping_outcomes[ $mapping_id ] = 'skipped';
                continue;
            }

            $blocked_by_dependency = $this->resolve_dependency_blocking_mapping(
                is_array( $node['dependency_ids'] ?? null ) ? $node['dependency_ids'] : [],
                $mapping_outcomes,
                $should_async,
            );
            if ( null !== $blocked_by_dependency )
            {
                $mapping_outcomes[ $mapping_id ] = 'skipped';
                $logger->info(
                    'after-submission skipped due to dependency outcome',
                    [
                        'hook'                   => 'gform_after_submission',
                        'mapping_id'             => $mapping_id,
                        'blocked_by_dependency'  => $blocked_by_dependency,
                        'dependency_outcome'     => $mapping_outcomes[ $blocked_by_dependency ] ?? null,
                        'form_id'                => $form_id,
                        'entry_id'               => $entry['id'] ?? null,
                        'correlation_id'         => $correlation_id,
                    ]
                );
                continue;
            }

            if ( ! $this->plugin->get_condition_evaluator()->should_execute( $action_settings, $entry ) )
            {
                $mapping_outcomes[ $mapping_id ] = 'skipped';
                $logger->info(
                    'after-submission skipped by mapping conditions',
                    [
                        'hook'           => 'gform_after_submission',
                        'action_id'      => $action_settings['central_action_id'] ?? '',
                        'mapping_id'     => $mapping_id,
                        'form_id'        => $form_id,
                        'entry_id'       => $entry['id'] ?? null,
                        'correlation_id' => $correlation_id,
                    ]
                );
                continue;
            }

            $upstream_spam_skip = $this->resolve_upstream_spam_skip_classification(
                $action_settings,
                is_array( $node['dependency_ids'] ?? null ) ? $node['dependency_ids'] : [],
                $mapping_classifications,
                $plan['nodes'],
                $form_id,
            );
            if ( null !== $upstream_spam_skip )
            {
                $mapping_outcomes[ $mapping_id ] = 'skipped';
                $logger->info(
                    'after-submission skipped because upstream spam check classified submission as spam',
                    [
                        'hook'           => 'gform_after_submission',
                        'action_id'      => $action_settings['central_action_id'] ?? '',
                        'mapping_id'     => $mapping_id,
                        'form_id'        => $form_id,
                        'entry_id'       => $entry['id'] ?? null,
                        'correlation_id' => $correlation_id,
                        'classification' => $upstream_spam_skip,
                    ]
                );
                continue;
            }

            $action_id = (string) ( $action_settings['central_action_id'] ?? '' );
            if ( '' === $action_id )
            {
                $mapping_outcomes[ $mapping_id ] = 'failed';
                continue;
            }

            $action = $this->plugin->get_action( $action_id );

            $data = [
                'form'  => $form,
                'entry' => $entry,
            ];

            $dependency_ids                   = is_array( $node['dependency_ids'] ?? null ) ? $node['dependency_ids'] : [];
            $dependency_initial_outcomes      = [];
            $dependency_execution_request_ids = [];

            foreach ( $dependency_ids as $dependency_id )
            {
                if ( isset( $mapping_outcomes[ $dependency_id ] ) )
                {
                    $dependency_initial_outcomes[ $dependency_id ] = $mapping_outcomes[ $dependency_id ];
                }

                if ( isset( $execution_request_ids[ $dependency_id ] ) )
                {
                    $dependency_execution_request_ids[ $dependency_id ] = $execution_request_ids[ $dependency_id ];
                }
            }

            if ( $this->is_local_first_mapping( $action_settings ) )
            {
                if ( $should_async )
                {
                    $logger->info(
                        'local-first async action enqueued',
                        [
                            'hook'           => 'gform_after_submission',
                            'mapping_id'     => $mapping_id,
                            'form_id'        => $form_id,
                            'entry_id'       => $entry['id'] ?? null,
                            'correlation_id' => $correlation_id,
                        ]
                    );

                    $scheduled = $this->schedule_local_first_after_submission_mapping(
                        $form,
                        $entry,
                        $mapping_id,
                        $action_settings,
                        $execution_request_ids[ $mapping_id ] ?? null,
                        [
                            'dependency_mapping_ids'           => $dependency_ids,
                            'dependency_execution_request_ids' => $dependency_execution_request_ids,
                            'dependency_initial_outcomes'      => $dependency_initial_outcomes,
                            'dependency_wait_started_at'       => time(),
                            'dependency_wait_max_seconds'      => max(
                                30,
                                (int) ( $action_settings['settings']['batch_settings']['max_wait_seconds'] ?? 600 )
                            ),
                            'dependency_wait_poll_seconds'     => 10,
                        ]
                    );

                    $mapping_outcomes[ $mapping_id ] = $scheduled ? 'queued' : 'failed';

                    if ( ! $scheduled )
                    {
                        $logger->error(
                            'local-first async action scheduling failed',
                            [
                                'hook'           => 'gform_after_submission',
                                'mapping_id'     => $mapping_id,
                                'form_id'        => $form_id,
                                'entry_id'       => $entry['id'] ?? null,
                                'correlation_id' => $correlation_id,
                            ]
                        );
                    }

                    continue;
                }

                $result = $this->execute_local_first_after_submission_mapping(
                    $form,
                    $entry,
                    $mapping_id,
                    $action_settings,
                    $execution_request_ids[ $mapping_id ] ?? null
                );

                $mapping_outcomes[ $mapping_id ] = is_wp_error( $result ) ? 'failed' : 'succeeded';

                if ( is_wp_error( $result ) )
                {
                    $this->record_local_action_failure( $entry, $action_settings, $result );
                    $logger->error(
                        'local-first after-submission action failed',
                        [
                            'hook'           => 'gform_after_submission',
                            'mapping_id'     => $mapping_id,
                            'form_id'        => $form_id,
                            'entry_id'       => $entry['id'] ?? null,
                            'correlation_id' => $correlation_id,
                            'error_code'     => $result->get_error_code(),
                        ]
                    );
                }
                elseif ( is_array( $result ) )
                {
                    $this->record_blocking_spam_notification_state( absint( $entry['id'] ?? 0 ), $action_settings, $result );
                    $this->record_mapping_spam_classification( $mapping_classifications, $mapping_id, $result );
                }

                continue;
            }

            if ( $should_async )
            {
                $logger->info(
                    'async action enqueued',
                    [
                        'hook'           => 'gform_after_submission',
                        'action_id'      => $action_id,
                        'mapping_id'     => $mapping_id,
                        'form_id'        => $form_id,
                        'entry_id'       => $entry['id'] ?? null,
                        'correlation_id' => $correlation_id,
                    ]
                );

                $scheduled = $this->plugin->process_action_async(
                    $action_id,
                    $data,
                    $action_settings,
                    [
                        'hook'                           => 'gform_after_submission',
                        'form_source'                    => $this->get_id(),
                        'action_id'                      => $mapping_id,
                        'mapping_id'                     => $mapping_id,
                        'local_mapping_id'               => $mapping_id,
                        'form_id'                        => $form_id,
                        'entry_id'                       => $entry['id'] ?? null,
                        'execution_request_id'           => $execution_request_ids[ $mapping_id ] ?? null,
                        'action_name_label'              => $action_settings['action_name_label'] ?? ( $action_settings['central_action_id'] ?? $action_id ),
                        'mark_as_spam'                   => ! empty( $action_settings['mark_as_spam'] ),
                        'spam_confidence_threshold'      => $action_settings['settings']['spam_confidence_threshold'] ?? $action_settings['spam_confidence_threshold'] ?? 0.80,
                        'spam_indicators_display'        => $action_settings['settings']['spam_indicators_display'] ?? $action_settings['spam_indicators_display'] ?? 'simple',
                        'spam_result_display_mode'       => $action_settings['settings']['spam_result_display_mode'] ?? $action_settings['spam_result_display_mode'] ?? 'entry_note',
                        'central_action_id'              => $action_settings['central_action_id'] ?? null,
                        'dependency_mapping_ids'         => $dependency_ids,
                        'dependency_execution_request_ids' => $dependency_execution_request_ids,
                        'dependency_initial_outcomes'    => $dependency_initial_outcomes,
                        'dependency_wait_started_at'     => time(),
                        'dependency_wait_max_seconds'    => max(
                            30,
                            (int) ( $action_settings['settings']['batch_settings']['max_wait_seconds'] ?? 600 )
                        ),
                        'dependency_wait_poll_seconds'   => 10,
                    ],
                );

                $mapping_outcomes[ $mapping_id ] = $scheduled ? 'queued' : 'failed';

                if ( $scheduled )
                {
                    $this->log_action_execution(
                        [
                            'hook'                 => 'gform_after_submission',
                            'form_source'          => $this->get_id(),
                            'action_id'            => $mapping_id,
                            'mapping_id'           => $mapping_id,
                            'local_mapping_id'     => $mapping_id,
                            'form_id'              => $form_id,
                            'entry_id'             => $entry['id'] ?? null,
                            'execution_request_id' => $execution_request_ids[ $mapping_id ] ?? null,
                            'action_name_label'    => $action_settings['action_name_label'] ?? ( $action_settings['central_action_id'] ?? $action_id ),
                            'central_action_id'    => $action_settings['central_action_id'] ?? null,
                            'settings'             => isset( $action_settings['settings'] ) && is_array( $action_settings['settings'] )
                                ? $action_settings['settings']
                                : [],
                        ],
                        [],
                        'pending'
                    );
                }

                continue;
            }

            if ( ! $action )
            {
                if ( $this->is_cps_managed_mapping( $action_settings ) )
                {
                    $ran_via_cps_executor = true;
                    $result = $this->execute_blocking_after_submission_cps_action( $form, $entry, $mapping_id, $action_settings );
                }
                else
                {
                    $mapping_outcomes[ $mapping_id ] = 'failed';
                    continue;
                }
            }
            else
            {
                $ran_via_cps_executor = false;
                $entry_id        = $entry['id'] ?? 0;
                $runtime_form_id = $form['id'] ?? 0;
                $result          = $action->execute( $data, $action_settings, $entry_id, $runtime_form_id );
            }

            $entry_id = $entry['id'] ?? 0;
            $mapping_outcomes[ $mapping_id ] = is_wp_error( $result ) ? 'failed' : 'succeeded';

            $context = [
                'hook'              => 'gform_after_submission',
                'form_source'       => $this->get_id(),
                'action_id'         => $mapping_id,
                'mapping_id'        => $mapping_id,
                'local_mapping_id'  => $mapping_id,
                'form_id'           => $form_id,
                'entry_id'          => $entry['id'] ?? null,
                'action_name_label' => $action_settings['action_name_label'] ?? ( $action_settings['central_action_id'] ?? $action_id ),
                'central_action_id' => $action_settings['central_action_id'] ?? null,
                'mark_as_spam'      => ! empty( $action_settings['mark_as_spam'] ),
                'spam_confidence_threshold' => $action_settings['settings']['spam_confidence_threshold'] ?? $action_settings['spam_confidence_threshold'] ?? 0.80,
                'spam_indicators_display'   => $action_settings['settings']['spam_indicators_display'] ?? $action_settings['spam_indicators_display'] ?? 'simple',
                'spam_result_display_mode'  => $action_settings['settings']['spam_result_display_mode'] ?? $action_settings['spam_result_display_mode'] ?? 'entry_note',
                'settings'          => isset( $action_settings['settings'] ) && is_array( $action_settings['settings'] )
                    ? $action_settings['settings']
                    : [],
            ];

            if ( is_array( $result ) )
            {
                if ( $ran_via_cps_executor && $entry_id > 0 )
                {
                    $this->maybe_mark_entry_as_spam_from_result( absint( $entry_id ), $context, $result );
                }

                $this->record_blocking_spam_notification_state( absint( $entry_id ), $action_settings, $result );
            }

            if ( is_wp_error( $result ) )
            {
                $this->log_action_execution( $context, [], 'error', $result );
            }
            elseif ( is_array( $result ) )
            {
                $this->log_action_execution( $context, $result, 'success' );
                $this->run_post_execution_actions( absint( $entry_id ), $context, $result );
                $this->record_mapping_spam_classification( $mapping_classifications, $mapping_id, $result );
            }
        }
    }

    /**
     * Resolve whether a mapping should be blocked by prerequisite outcomes.
     *
     * @param array<int, string>         $dependency_ids  Dependency mapping ids.
     * @param array<string, string>      $mapping_outcomes Known outcomes keyed by mapping id.
     * @param bool                       $allow_queued_dependencies Whether queued prerequisites are allowed.
     *
     * @return string|null Mapping id that blocks execution, or null.
     */
    private function resolve_dependency_blocking_mapping( array $dependency_ids, array $mapping_outcomes, bool $allow_queued_dependencies = true ): ?string
    {
        foreach ( $dependency_ids as $dependency_id )
        {
            if ( ! is_string( $dependency_id ) || '' === $dependency_id )
            {
                continue;
            }

            $outcome = $mapping_outcomes[ $dependency_id ] ?? null;
            if ( null === $outcome )
            {
                return $dependency_id;
            }

            if ( 'failed' === $outcome || 'skipped' === $outcome )
            {
                return $dependency_id;
            }

            if ( ! $allow_queued_dependencies && 'queued' === $outcome )
            {
                return $dependency_id;
            }
        }

        return null;
    }

    /**
     * Determine whether a mapping executes asynchronously.
     *
     * @param array<string, mixed> $mapping Mapping payload.
     *
     * @return bool
     */
    private function is_mapping_async( array $mapping ): bool
    {
        if ( isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) && array_key_exists( 'async', $mapping['settings'] ) )
        {
            return rest_sanitize_boolean( $mapping['settings']['async'] );
        }

        if ( isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) && isset( $mapping['settings']['execution_mode'] ) && is_scalar( $mapping['settings']['execution_mode'] ) )
        {
            return 'after_submission' === sanitize_key( (string) $mapping['settings']['execution_mode'] );
        }

        if ( array_key_exists( 'async', $mapping ) )
        {
            return rest_sanitize_boolean( $mapping['async'] );
        }

        if ( isset( $mapping['execution_mode'] ) && is_scalar( $mapping['execution_mode'] ) )
        {
            return 'after_submission' === sanitize_key( (string) $mapping['execution_mode'] );
        }

        $indicator = isset( $mapping['action_type_indicator'] ) && is_scalar( $mapping['action_type_indicator'] )
            ? sanitize_key( (string) $mapping['action_type_indicator'] )
            : '';

        if ( 'master' === $indicator )
        {
            return true;
        }

        return false;
    }

    /**
     * Prepare entry data from form submission
     *
     * @return array The entry data.
     */
    private function prepare_entry_from_submission(): array
    {
        $entry = [];

        // Get form data from the Gravity Forms submission payload.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Gravity Forms owns frontend submission verification before this hook.
        if ( isset( $_POST[ 'gform_submit' ] ) )
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Gravity Forms owns frontend submission verification before this hook.
            $form_id = absint( wp_unslash( $_POST[ 'gform_submit' ] ) );
            $form    = GFAPI::get_form( $form_id );

            if ( $form )
            {
                foreach ( $form[ 'fields' ] as $field )
                {
                    $field_id   = $field->id;
                    $input_name = 'input_' . str_replace( '.', '_', $field_id );

                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Gravity Forms owns frontend submission verification before this hook.
                    if ( isset( $_POST[ $input_name ] ) )
                    {
                        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Gravity Forms owns frontend submission verification before this hook.
                        $entry[ $field_id ] = sanitize_text_field( wp_unslash( $_POST[ $input_name ] ) );
                    }
                }
            }
        }

        return $entry;
    }

    private function maybe_execute_cps_validation(
        array $validation_result,
        array $form,
        array $entry,
        string $action_id,
        array $action_settings,
        ?string &$execution_status = null,
        ?array &$execution_response = null
    ): array
    {
        $central_action_id = $action_settings['central_action_id'] ?? '';
        if ( empty( $central_action_id ) )
        {
            $execution_status = 'skipped';
            $execution_response = null;
            return $validation_result;
        }

        $action_type_indicator = null;
        if ( isset( $action_settings['action_type_indicator'] ) && is_scalar( $action_settings['action_type_indicator'] ) && '' !== $action_settings['action_type_indicator'] )
        {
            $action_type_indicator = (string) $action_settings['action_type_indicator'];
        }

        $local_mapping_id = null;
        if ( isset( $action_settings['local_mapping_id'] ) && is_scalar( $action_settings['local_mapping_id'] ) && '' !== $action_settings['local_mapping_id'] )
        {
            $local_mapping_id = (string) $action_settings['local_mapping_id'];
        }

        $context = [
            'hook'        => 'gform_validation',
            'form_source' => $this->get_id(),
            'action_id'   => $action_id,
            'form_id'     => $form['id'] ?? null,
            'action_name_label' => $action_settings['action_name_label'] ?? $central_action_id,
            'action_type_indicator' => $action_type_indicator,
            'local_mapping_id'      => $local_mapping_id,
            'settings'              => isset( $action_settings['settings'] ) && is_array( $action_settings['settings'] )
                ? $action_settings['settings']
                : [],
        ];

        $context['execution_request_id'] = Sentient_Forms_Action_Executor::generate_execution_request_id(
            (string) $central_action_id,
            $form,
            $entry,
            $context,
        );

        $response = $this->plugin->get_action_executor()->execute(
            $central_action_id,
            $form,
            $entry,
            $context,
        );

        $execution_status = is_wp_error( $response ) ? 'failed' : 'success';
        $execution_response = is_array( $response ) ? $response : null;

        if ( is_wp_error( $response ) )
        {
            $execution_request_id = $this->log_action_execution( $context, [], 'error', $response );
            $this->remember_validation_execution_request_id( absint( $form['id'] ?? 0 ), $execution_request_id );
        }
        elseif ( is_array( $response ) )
        {
            $execution_request_id = $this->log_action_execution( $context, $response, 'success' );
            $this->remember_validation_execution_request_id( absint( $form['id'] ?? 0 ), $execution_request_id );
        }

        return $this->apply_cps_validation_response( $validation_result, $response, $action_settings );
    }

    /**
     * Capture spam classification from a mapping execution result when present.
     *
     * @param array<string, string> $mapping_classifications Known classifications keyed by mapping id.
     * @param string                $mapping_id              Mapping id.
     * @param mixed                 $result                  Local/CPS execution result.
     *
     * @return void
     */
    private function record_mapping_spam_classification( array &$mapping_classifications, string $mapping_id, $result ): void
    {
        if ( ! is_array( $result ) )
        {
            return;
        }

        $classification = $this->extract_spam_classification( $result );
        if ( null === $classification || '' === $classification )
        {
            return;
        }

        $mapping_classifications[ $mapping_id ] = $classification;
    }

    /**
     * Resolve whether the current mapping should skip because its upstream spam dependency
     * classified the submission as spam during the active hook.
     *
     * @param array<string, mixed>                $mapping                 Mapping payload.
     * @param array<int, string>                  $dependency_ids          Hook-specific dependency mapping ids.
     * @param array<string, string>               $mapping_classifications Known classifications keyed by mapping id.
     * @param array<string, array<string, mixed>> $plan_nodes              Execution plan nodes keyed by mapping id.
     * @param int                                 $form_id                 Form id for hierarchy resolution.
     *
     * @return string|null Triggering classification when the mapping should skip, or null.
     */
    private function resolve_upstream_spam_skip_classification(
        array $mapping,
        array $dependency_ids,
        array $mapping_classifications,
        array $plan_nodes,
        int $form_id
    ): ?string
    {
        if ( 1 !== count( $dependency_ids ) )
        {
            return null;
        }

        $dependency_id = isset( $dependency_ids[0] ) && is_scalar( $dependency_ids[0] )
            ? sanitize_text_field( (string) $dependency_ids[0] )
            : '';
        if ( '' === $dependency_id )
        {
            return null;
        }

        $dependency_node = $plan_nodes[ $dependency_id ] ?? null;
        if ( ! is_array( $dependency_node ) || ! isset( $dependency_node['mapping'] ) || ! is_array( $dependency_node['mapping'] ) )
        {
            return null;
        }

        $dependency_mapping = $this->resolve_mapping_runtime_settings( $dependency_node['mapping'], $form_id );
        $dependency_action_id = isset( $dependency_mapping['central_action_id'] ) && is_scalar( $dependency_mapping['central_action_id'] )
            ? sanitize_key( (string) $dependency_mapping['central_action_id'] )
            : '';
        if ( 'spam_detection_v1' !== $dependency_action_id )
        {
            return null;
        }

        if ( ! $this->should_skip_on_upstream_spam( $mapping )
            && ! $this->should_skip_downstream_on_spam_for_mapping( $dependency_mapping, $form_id ) )
        {
            return null;
        }

        $classification = $mapping_classifications[ $dependency_id ] ?? null;
        if ( ! is_string( $classification ) )
        {
            return null;
        }

        return in_array( $classification, array( 'spam', 'likely_spam' ), true ) ? $classification : null;
    }

    /**
     * Determine whether a spam mapping should skip downstream work by default or override.
     *
     * @param array<string, mixed> $mapping Mapping payload.
     * @param int                  $form_id Form id.
     *
     * @return bool
     */
    private function should_skip_downstream_on_spam_for_mapping( array $mapping, int $form_id ): bool
    {
        $resolved_settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] )
            ? $mapping['settings']
            : [];

        if ( ! array_key_exists( 'skip_downstream_on_spam', $resolved_settings ) )
        {
            $mapping = $this->resolve_mapping_runtime_settings( $mapping, $form_id );
            $resolved_settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] )
                ? $mapping['settings']
                : [];
        }

        if ( array_key_exists( 'skip_downstream_on_spam', $resolved_settings ) )
        {
            return rest_sanitize_boolean( $resolved_settings['skip_downstream_on_spam'] );
        }

        $action_id = isset( $mapping['central_action_id'] ) && is_scalar( $mapping['central_action_id'] )
            ? sanitize_key( (string) $mapping['central_action_id'] )
            : '';

        return $this->is_spam_action_id( $action_id );
    }

    /**
     * Persist spam classification state for blocking after-submission execution before notifications are evaluated.
     *
     * Gravity Forms passes the in-memory entry to notification filters immediately after gform_entry_post_save.
     * When a blocking action classifies spam before notifications, we persist the classification meta here so the
     * notification filters can suppress delivery even if the in-memory entry status has not been refreshed yet.
     *
     * @param int   $entry_id         Gravity Forms entry id.
     * @param array $action_settings  Mapping settings.
     * @param array $result           Blocking action result payload.
     *
     * @return void
     */
    private function record_blocking_spam_notification_state( int $entry_id, array $action_settings, array $result ): void
    {
        if ( $entry_id <= 0 )
        {
            return;
        }

        $classification = $this->extract_spam_classification( $result );
        if ( ! is_string( $classification ) || '' === $classification )
        {
            return;
        }

        if ( in_array( $classification, [ 'spam', 'likely_spam' ], true ) )
        {
            $this->update_entry_meta(
                $entry_id,
                self::SPAM_NOTIFICATION_PREFERENCE_META_KEY,
                $this->should_suppress_notifications_on_spam( $action_settings )
                    ? self::SPAM_NOTIFICATION_PREFERENCE_SUPPRESS
                    : self::SPAM_NOTIFICATION_PREFERENCE_ALLOW,
            );

            if ( ! empty( $action_settings['mark_as_spam'] ) )
            {
                $this->update_entry_meta( $entry_id, 'spam_classification', 'spam' );
            }

            return;
        }

        if ( in_array( $classification, [ 'ham', 'legitimate' ], true ) )
        {
            $this->update_entry_meta( $entry_id, 'spam_classification', 'ham' );
            $this->update_entry_meta( $entry_id, self::SPAM_NOTIFICATION_PREFERENCE_META_KEY, self::SPAM_NOTIFICATION_PREFERENCE_ALLOW );
        }
    }

    /**
     * Determine whether a spam mapping should suppress notifications when it classifies spam.
     *
     * @param array<string, mixed> $mapping Mapping payload.
     *
     * @return bool
     */
    private function should_suppress_notifications_on_spam( array $mapping ): bool
    {
        $settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) ? $mapping['settings'] : [];
        if ( array_key_exists( 'suppress_notifications_on_spam', $settings ) )
        {
            return rest_sanitize_boolean( $settings['suppress_notifications_on_spam'] );
        }

        $effect_mapping = $this->get_local_effect_mapping( $mapping );
        if ( array_key_exists( 'suppress_notifications_on_spam', $effect_mapping ) )
        {
            return rest_sanitize_boolean( $effect_mapping['suppress_notifications_on_spam'] );
        }

        if (
            isset( $effect_mapping['spam'] )
            && is_array( $effect_mapping['spam'] )
            && array_key_exists( 'suppress_notifications_on_spam', $effect_mapping['spam'] )
        )
        {
            return rest_sanitize_boolean( $effect_mapping['spam']['suppress_notifications_on_spam'] );
        }

        $action_id = isset( $mapping['central_action_id'] ) && is_scalar( $mapping['central_action_id'] )
            ? sanitize_key( (string) $mapping['central_action_id'] )
            : '';

        if ( $this->local_spam_effect_enabled( $mapping ) )
        {
            return true;
        }

        return $this->is_spam_action_id( $action_id ) && ! $this->is_mapping_async( $mapping );
    }

    /**
     * Resolve local effect mapping JSON from either runtime settings or a custom-table row.
     *
     * @param array<string, mixed> $mapping Runtime mapping or repository row.
     *
     * @return array<string, mixed>
     */
    private function get_local_effect_mapping( array $mapping ): array
    {
        if (
            isset( $mapping['settings'] )
            && is_array( $mapping['settings'] )
            && isset( $mapping['settings']['effect_mapping_json'] )
            && is_array( $mapping['settings']['effect_mapping_json'] )
        )
        {
            return $mapping['settings']['effect_mapping_json'];
        }

        return isset( $mapping['effect_mapping_json'] ) && is_array( $mapping['effect_mapping_json'] )
            ? $mapping['effect_mapping_json']
            : [];
    }

    /**
     * Determine whether local effect mapping declares spam handling.
     *
     * @param array<string, mixed> $mapping Runtime mapping or repository row.
     *
     * @return bool
     */
    private function local_spam_effect_enabled( array $mapping ): bool
    {
        $effects = $this->get_local_effect_mapping( $mapping );
        if ( isset( $effects['spam'] ) )
        {
            if ( is_array( $effects['spam'] ) )
            {
                return ! array_key_exists( 'enabled', $effects['spam'] ) || rest_sanitize_boolean( $effects['spam']['enabled'] );
            }

            return rest_sanitize_boolean( $effects['spam'] );
        }

        return ! empty( $effects['mark_as_spam'] );
    }

    /**
     * Determine whether a runtime mapping is backed by local WordPress tables.
     *
     * @param array<string, mixed> $mapping Mapping settings.
     *
     * @return bool
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
     * Execute a local-first mapping through the local provider engine.
     *
     * @param array<string, mixed> $form                 Gravity Forms form payload.
     * @param array<string, mixed> $entry                Gravity Forms entry payload.
     * @param string               $mapping_id           Runtime planner mapping id.
     * @param array<string, mixed> $action_settings      Mapping settings.
     * @param string|null          $execution_request_id Optional precomputed request id.
     *
     * @return array<string, mixed>|WP_Error
     */
    private function execute_local_first_after_submission_mapping(
        array $form,
        array $entry,
        string $mapping_id,
        array $action_settings,
        ?string $execution_request_id = null
    ): array | WP_Error
    {
        $local_mapping_id = absint( $action_settings['local_form_mapping_id'] ?? 0 );
        if ( $local_mapping_id <= 0 )
        {
            return new WP_Error(
                'sentient_forms_missing_local_mapping_id',
                __( 'Local form mapping id is missing.', 'sentient-forms' )
            );
        }

        $context = [
            'hook'                    => 'gform_after_submission',
            'form_source'             => $this->get_id(),
            'mapping_id'              => $mapping_id,
            'local_mapping_id'        => $mapping_id,
            'local_form_mapping_id'   => $local_mapping_id,
            'form_id'                 => $form['id'] ?? null,
            'entry_id'                => $entry['id'] ?? null,
            'action_name_label'       => $action_settings['action_name_label'] ?? __( 'Local OpenRouter action', 'sentient-forms' ),
            'central_action_id'       => $action_settings['central_action_id'] ?? 'sentient_forms_local_custom_action',
            'mark_as_spam'            => ! empty( $action_settings['mark_as_spam'] ),
            'spam_confidence_threshold' => $this->get_local_spam_confidence_threshold( $action_settings ),
            'settings'                => isset( $action_settings['settings'] ) && is_array( $action_settings['settings'] )
                ? $action_settings['settings']
                : [],
            'execution_request_id'    => $execution_request_id,
        ];

        return $this->get_local_execution_service()->execute_mapping( $local_mapping_id, $form, $entry, $context );
    }

    /**
     * Execute a local-first validation mapping through the local provider engine.
     *
     * @param array<string, mixed> $form            Gravity Forms form payload.
     * @param array<string, mixed> $entry           Gravity Forms submitted values.
     * @param string               $mapping_id      Runtime planner mapping id.
     * @param array<string, mixed> $action_settings Mapping settings.
     *
     * @return array<string, mixed>|WP_Error
     */
    private function execute_local_first_validation_mapping(
        array $form,
        array $entry,
        string $mapping_id,
        array $action_settings
    ): array | WP_Error
    {
        $local_mapping_id = absint( $action_settings['local_form_mapping_id'] ?? 0 );
        if ( $local_mapping_id <= 0 )
        {
            return new WP_Error(
                'sentient_forms_missing_local_mapping_id',
                __( 'Local form mapping id is missing.', 'sentient-forms' )
            );
        }

        $context = [
            'hook'                    => 'gform_validation',
            'form_source'             => $this->get_id(),
            'mapping_id'              => $mapping_id,
            'local_mapping_id'        => $mapping_id,
            'local_form_mapping_id'   => $local_mapping_id,
            'form_id'                 => $form['id'] ?? null,
            'entry_id'                => $entry['id'] ?? null,
            'action_name_label'       => $action_settings['action_name_label'] ?? __( 'Local OpenRouter action', 'sentient-forms' ),
            'central_action_id'       => $action_settings['central_action_id'] ?? 'sentient_forms_local_custom_action',
            'settings'                => isset( $action_settings['settings'] ) && is_array( $action_settings['settings'] )
                ? $action_settings['settings']
                : [],
        ];

        $context['execution_request_id'] = Sentient_Forms_Action_Executor::generate_execution_request_id(
            'local:' . $local_mapping_id,
            $form,
            $entry,
            $context,
        );

        $result = $this->get_local_execution_service()->execute_mapping( $local_mapping_id, $form, $entry, $context );
        $this->remember_validation_execution_request_id( absint( $form['id'] ?? 0 ), $context['execution_request_id'] ?? null );

        return $result;
    }

    /**
     * Queue a local-first mapping through the plugin-owned async handler.
     *
     * @param array<string, mixed> $form                 Gravity Forms form payload.
     * @param array<string, mixed> $entry                Gravity Forms entry payload.
     * @param string               $mapping_id           Runtime planner mapping id.
     * @param array<string, mixed> $action_settings      Mapping settings.
     * @param string|null          $execution_request_id Optional precomputed request id.
     * @param array<string, mixed> $async_context        Dependency/runtime context.
     *
     * @return bool Whether the mapping was queued.
     */
    private function schedule_local_first_after_submission_mapping(
        array $form,
        array $entry,
        string $mapping_id,
        array $action_settings,
        ?string $execution_request_id = null,
        array $async_context = []
    ): bool
    {
        $local_mapping_id = absint( $action_settings['local_form_mapping_id'] ?? 0 );
        if ( $local_mapping_id <= 0 )
        {
            return false;
        }

        $context = array_merge(
            [
                'hook'                    => 'gform_after_submission',
                'form_source'             => $this->get_id(),
                'mapping_id'              => $mapping_id,
                'local_mapping_id'        => $mapping_id,
                'local_form_mapping_id'   => $local_mapping_id,
                'form_id'                 => $form['id'] ?? null,
                'entry_id'                => $entry['id'] ?? null,
                'action_name_label'       => $action_settings['action_name_label'] ?? __( 'Local OpenRouter action', 'sentient-forms' ),
                'central_action_id'       => $action_settings['central_action_id'] ?? 'sentient_forms_local_custom_action',
                'mark_as_spam'            => ! empty( $action_settings['mark_as_spam'] ),
                'spam_confidence_threshold' => $this->get_local_spam_confidence_threshold( $action_settings ),
                'settings'                => isset( $action_settings['settings'] ) && is_array( $action_settings['settings'] )
                    ? $action_settings['settings']
                    : [],
                'execution_request_id'    => $execution_request_id,
            ],
            $async_context,
        );

        return $this->plugin->get_async_handler()->schedule_local_mapping(
            $local_mapping_id,
            $form,
            $entry,
            $context
        );
    }

    /**
     * Resolve local spam confidence threshold from effect mapping or runtime settings.
     *
     * @param array<string, mixed> $mapping Runtime mapping.
     *
     * @return float
     */
    private function get_local_spam_confidence_threshold( array $mapping ): float
    {
        $effects = $this->get_local_effect_mapping( $mapping );
        if ( isset( $effects['spam'] ) && is_array( $effects['spam'] ) && is_numeric( $effects['spam']['min_confidence'] ?? null ) )
        {
            return (float) $effects['spam']['min_confidence'];
        }

        $settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) ? $mapping['settings'] : [];
        if ( is_numeric( $settings['spam_confidence_threshold'] ?? null ) )
        {
            return (float) $settings['spam_confidence_threshold'];
        }

        return 0.80;
    }

    private function get_local_execution_service(): Sentient_Forms_Local_Action_Execution_Service
    {
        if ( null === $this->local_execution_service )
        {
            $this->local_execution_service = new Sentient_Forms_Local_Action_Execution_Service();
        }

        return $this->local_execution_service;
    }

    /**
     * Determine whether a mapping can execute directly through the CPS action executor.
     *
     * @param array<string, mixed> $mapping Mapping settings.
     *
     * @return bool
     */
    private function is_cps_managed_mapping( array $mapping ): bool
    {
        $indicator = isset( $mapping['action_type_indicator'] ) && is_scalar( $mapping['action_type_indicator'] )
            ? sanitize_key( (string) $mapping['action_type_indicator'] )
            : '';

        return in_array( $indicator, [ 'master', 'custom' ], true );
    }

    /**
     * Execute a Blocking after-submission CPS-managed mapping immediately instead of queueing it.
     *
     * @param array<string, mixed> $form            Form payload.
     * @param array<string, mixed> $entry           Entry payload.
     * @param string               $mapping_id      Local mapping id.
     * @param array<string, mixed> $action_settings Mapping settings.
     *
     * @return array|WP_Error
     */
    private function execute_blocking_after_submission_cps_action( array $form, array $entry, string $mapping_id, array $action_settings )
    {
        $central_action_id = isset( $action_settings['central_action_id'] ) && is_scalar( $action_settings['central_action_id'] )
            ? (string) $action_settings['central_action_id']
            : '';

        if ( '' === $central_action_id )
        {
            return new WP_Error(
                'sentient_forms_missing_central_action',
                __( 'Sentient Forms could not run this action because the central action id is missing.', 'sentient-forms' )
            );
        }

        $local_mapping_id = isset( $action_settings['local_mapping_id'] ) && is_scalar( $action_settings['local_mapping_id'] ) && '' !== $action_settings['local_mapping_id']
            ? (string) $action_settings['local_mapping_id']
            : $mapping_id;

        $context = [
            'hook'                  => 'gform_after_submission',
            'form_source'           => $this->get_id(),
            'action_id'             => $mapping_id,
            'mapping_id'            => $mapping_id,
            'local_mapping_id'      => $local_mapping_id,
            'form_id'               => $form['id'] ?? null,
            'entry_id'              => $entry['id'] ?? null,
            'action_name_label'     => $action_settings['action_name_label'] ?? $central_action_id,
            'action_type_indicator' => $action_settings['action_type_indicator'] ?? null,
            'central_action_id'     => $central_action_id,
            'settings'              => isset( $action_settings['settings'] ) && is_array( $action_settings['settings'] )
                ? $action_settings['settings']
                : [],
        ];

        return $this->plugin->get_action_executor()->execute(
            $central_action_id,
            $form,
            $entry,
            $context,
        );
    }

    /**
     * Determine whether skip_on_upstream_spam is enabled for a mapping.
     *
     * @param array<string, mixed> $mapping Mapping payload.
     *
     * @return bool
     */
    private function should_skip_on_upstream_spam( array $mapping ): bool
    {
        if ( ! isset( $mapping['settings'] ) || ! is_array( $mapping['settings'] ) )
        {
            return false;
        }

        if ( ! array_key_exists( 'skip_on_upstream_spam', $mapping['settings'] ) )
        {
            return false;
        }

        return rest_sanitize_boolean( $mapping['settings']['skip_on_upstream_spam'] );
    }

    private function apply_cps_validation_response( array $validation_result, $response, array $action_settings ): array
    {
        // NFR-REL-001: Fail-open behavior - if CPS returns an error, log it but
        // allow the submission to continue. This prevents CPS downtime from
        // blocking all form submissions.
        if ( is_wp_error( $response ) )
        {
            $fail_open = $action_settings['fail_open'] ?? true; // Default to fail-open

            sentient_forms_debug_log(
                'Sentient Forms CPS validation error.',
                [
                    'fail_open'     => (bool) $fail_open,
                    'error_code'    => $response->get_error_code(),
                    'error_message' => $response->get_error_message(),
                ]
            );

            // If fail_open is enabled (default), don't block the submission
            if ( $fail_open )
            {
                return $validation_result;
            }

            // Only inject error message if explicitly configured to fail-closed
            return $this->inject_validation_message( $validation_result, $response->get_error_message(), $action_settings );
        }

        $validation = $this->extract_cps_validation_payload( $response, $action_settings );
        if ( null !== $validation )
        {
            if ( array_key_exists( 'is_valid', $validation ) && false === $validation['is_valid'] )
            {
                $message = $validation['message'] ?? $this->default_validation_failure_message( $action_settings );
                $validation_result = $this->inject_validation_message( $validation_result, $message, $action_settings );

                if ( !empty( $validation['fields'] ) && is_array( $validation['fields'] ) )
                {
                    $validation_result = $this->inject_field_validation_messages( $validation_result, $validation['fields'] );
                }
            }
        }

        return $validation_result;
    }

    /**
     * Apply validation outcomes returned by a local-first provider execution.
     *
     * @param array<string, mixed> $validation_result Current Gravity Forms validation result.
     * @param mixed                $response          Local execution result or WP_Error.
     * @param array<string, mixed> $action_settings   Runtime mapping settings.
     *
     * @return array<string, mixed>
     */
    private function apply_local_first_validation_response( array $validation_result, $response, array $action_settings ): array
    {
        if ( is_wp_error( $response ) )
        {
            $fail_open = $action_settings['fail_open'] ?? true;

            sentient_forms_debug_log(
                'Sentient Forms local-first validation error.',
                [
                    'fail_open'     => (bool) $fail_open,
                    'error_code'    => $response->get_error_code(),
                    'error_message' => $response->get_error_message(),
                ]
            );

            return $fail_open
                ? $validation_result
                : $this->inject_validation_message( $validation_result, $response->get_error_message(), $action_settings );
        }

        $validation = $this->extract_local_first_validation_payload( $response );
        if ( null === $validation )
        {
            return $validation_result;
        }

        if ( array_key_exists( 'is_valid', $validation ) && false === $validation['is_valid'] )
        {
            $message = $validation['message'] ?? $this->default_validation_failure_message( $action_settings );
            $validation_result = $this->inject_validation_message( $validation_result, $message, $action_settings );

            if ( ! empty( $validation['fields'] ) && is_array( $validation['fields'] ) )
            {
                $validation_result = $this->inject_field_validation_messages( $validation_result, $validation['fields'] );
            }
        }

        return $validation_result;
    }

    /**
     * Extract validation failure payloads from CPS envelopes.
     *
     * @param mixed                $response        CPS response payload.
     * @param array<string, mixed> $action_settings Mapping settings.
     *
     * @return array<string, mixed>|null
     */
    private function extract_cps_validation_payload( $response, array $action_settings ): ?array
    {
        if ( ! is_array( $response ) )
        {
            return null;
        }

        if ( isset( $response['validation'] ) && is_array( $response['validation'] ) )
        {
            return $this->normalize_cps_validation_payload( $response['validation'] );
        }

        if ( ! $this->is_content_validation_payload_context( $response, $action_settings ) )
        {
            return null;
        }

        $result_data_candidates = [];
        if ( isset( $response['result_data'] ) && is_array( $response['result_data'] ) )
        {
            $result_data_candidates[] = $response['result_data'];
        }

        if (
            isset( $response['evaluation_payload']['result_data'] )
            && is_array( $response['evaluation_payload']['result_data'] )
        )
        {
            $result_data_candidates[] = $response['evaluation_payload']['result_data'];
        }

        foreach ( $result_data_candidates as $result_data )
        {
            if (
                ! empty( $result_data['structured_output_valid'] )
                && isset( $result_data['structured_output'] )
                && is_array( $result_data['structured_output'] )
            )
            {
                $structured_validation = $this->normalize_cps_validation_payload( $result_data['structured_output'] );
                if ( null !== $structured_validation )
                {
                    return $structured_validation;
                }
            }

            $direct_validation = $this->normalize_cps_validation_payload( $result_data );
            if ( null !== $direct_validation )
            {
                return $direct_validation;
            }
        }

        return null;
    }

    /**
     * Extract validation payloads from local-first execution wrappers.
     *
     * @param mixed $response Local execution result.
     *
     * @return array<string, mixed>|null
     */
    private function extract_local_first_validation_payload( $response ): ?array
    {
        if ( ! is_array( $response ) )
        {
            return null;
        }

        $candidates = [];
        foreach (
            [
                [ 'validation' ],
                [ 'result', 'validation' ],
                [ 'result', 'structured' ],
                [ 'result', 'structured_output' ],
                [ 'structured' ],
                [ 'structured_output' ],
                [ 'result_data', 'structured_output' ],
                [ 'result_data' ],
            ] as $path
        )
        {
            $candidate = $this->extract_nested_post_execution_value( $response, implode( '.', $path ) );
            if ( is_array( $candidate ) )
            {
                $candidates[] = $candidate;
            }
        }

        foreach ( $candidates as $candidate )
        {
            $validation = $this->normalize_cps_validation_payload( $candidate );
            if ( null !== $validation )
            {
                return $validation;
            }
        }

        return null;
    }

    /**
     * Determine whether a CPS response belongs to content_validation_v1.
     *
     * @param array<string, mixed> $response        CPS response payload.
     * @param array<string, mixed> $action_settings Mapping settings.
     *
     * @return bool
     */
    private function is_content_validation_payload_context( array $response, array $action_settings ): bool
    {
        $candidates = [
            $action_settings['central_action_id'] ?? null,
            $action_settings['action_id'] ?? null,
            $action_settings['action_code'] ?? null,
            $response['central_action_id'] ?? null,
            $response['action_id'] ?? null,
            $response['action_code'] ?? null,
            $response['meta']['central_action_id'] ?? null,
            $response['meta']['action_template_code'] ?? null,
            $response['evaluation_payload']['central_action_id'] ?? null,
            $response['evaluation_payload']['action_id'] ?? null,
            $response['evaluation_payload']['action_template_code'] ?? null,
        ];

        foreach ( $candidates as $candidate )
        {
            if ( is_scalar( $candidate ) && 'content_validation_v1' === sanitize_key( (string) $candidate ) )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize CPS validation-style payloads into the adapter contract.
     *
     * @param array<string, mixed> $candidate Candidate payload.
     *
     * @return array<string, mixed>|null
     */
    private function normalize_cps_validation_payload( array $candidate ): ?array
    {
        if ( ! array_key_exists( 'is_valid', $candidate ) )
        {
            return null;
        }

        $validation = [
            'is_valid' => rest_sanitize_boolean( $candidate['is_valid'] ),
            'message'  => isset( $candidate['message'] ) && is_scalar( $candidate['message'] )
                ? sanitize_text_field( (string) $candidate['message'] )
                : '',
            'fields'   => [],
        ];

        if ( isset( $candidate['fields'] ) && is_array( $candidate['fields'] ) )
        {
            foreach ( $candidate['fields'] as $field )
            {
                if ( ! is_array( $field ) || ! isset( $field['field_id'] ) || ! is_scalar( $field['field_id'] ) )
                {
                    continue;
                }

                $field_id = sanitize_text_field( (string) $field['field_id'] );
                if ( '' === $field_id )
                {
                    continue;
                }

                $validation['fields'][] = [
                    'field_id' => $field_id,
                    'is_valid' => array_key_exists( 'is_valid', $field ) ? rest_sanitize_boolean( $field['is_valid'] ) : true,
                    'message'  => isset( $field['message'] ) && is_scalar( $field['message'] )
                        ? sanitize_text_field( (string) $field['message'] )
                        : '',
                ];
            }
        }

        return $validation;
    }

    /**
     * Map CPS/WP errors to user-friendly messages for admin status.
     */
    private function map_error_to_message( WP_Error $error ): string
    {
        if ( 'insufficient_credits' === $error->get_error_code() )
        {
            $error_data = $error->get_error_data();
            $payload    = is_array( $error_data ) && isset( $error_data['payload'] ) && is_array( $error_data['payload'] )
                ? $error_data['payload']
                : array();
            $meta       = isset( $payload['error']['meta'] ) && is_array( $payload['error']['meta'] )
                ? $payload['error']['meta']
                : array();

            if ( isset( $meta['current_balance'] ) && is_numeric( $meta['current_balance'] ) && (int) $meta['current_balance'] < 0 )
            {
                return sprintf(
                    /* translators: %d is the negative credit balance. */
                    __( 'Sentient Forms could not run: this license now has a negative balance of %d credits. Add credits before retrying.', 'sentient-forms' ),
                    (int) $meta['current_balance']
                );
            }

            if (
                isset( $meta['current_balance'], $meta['required_credits'] ) &&
                is_numeric( $meta['current_balance'] ) &&
                is_numeric( $meta['required_credits'] )
            )
            {
                return sprintf(
                    /* translators: 1: required credits, 2: current credits remaining. */
                    __( 'Sentient Forms could not run: this action needs %1$d credits, but only %2$d remain for this license.', 'sentient-forms' ),
                    (int) $meta['required_credits'],
                    (int) $meta['current_balance']
                );
            }
        }

        return match ( $error->get_error_code() ) {
            'insufficient_credits' => __( 'Sentient Forms could not run: insufficient credits remain for this license.', 'sentient-forms' ),
            'duplicate_execution'  => __( 'Sentient Forms already processed this submission. Refresh the status to view the existing result.', 'sentient-forms' ),
            'timeout'              => __( 'Sentient Forms timed out while contacting CPS. The submission was not processed.', 'sentient-forms' ),
            default                => $error->get_error_message(),
        };
    }

    /**
     * Persist form-level error status for admins to review.
     */
    private function record_entry_error( int $entry_id, WP_Error $error, int $form_id ): void
    {
        $option_key = sprintf( 'sentient_forms_form_status_gravity_forms_%s', $form_id );
        $status     = [
            'status'          => 'error',
            'last_error_code' => $error->get_error_code(),
            'message'         => $this->map_error_to_message( $error ),
            'entry_id'        => $entry_id,
            'updated_at'      => time(),
        ];

        update_option( $option_key, $status, false );
    }

    private function inject_validation_message( array $validation_result, string $message, array $action_settings ): array
    {
        if ( '' === trim( $message ) )
        {
            $message = $this->default_validation_failure_message( $action_settings );
        }

        $validation_result['is_valid']             = false;
        $validation_result['form']['failed_validation'] = true;

        if ( empty( $validation_result['form']['validation_message'] ) )
        {
            $validation_result['form']['validation_message'] = $message;
        }
        else
        {
            $validation_result['form']['validation_message'] .= ' ' . $message;
        }

        return $validation_result;
    }

    private function inject_field_validation_messages( array $validation_result, array $field_errors ): array
    {
        if ( empty( $validation_result['form']['fields'] ) )
        {
            return $validation_result;
        }

        foreach ( $field_errors as $field_error )
        {
            $field_id = $field_error['field_id'] ?? null;
            $message  = $field_error['message'] ?? '';

            if ( null === $field_id || '' === trim( $message ) )
            {
                continue;
            }

            foreach ( $validation_result['form']['fields'] as &$field )
            {
                if ( isset( $field->id ) && (string) $field->id === (string) $field_id )
                {
                    $field->failed_validation  = true;
                    $field->validation_message = $message;
                }
            }
            unset( $field );
        }

        return $validation_result;
    }

    private function default_validation_failure_message( array $action_settings ): string
    {
        $label = $action_settings['action_name_label'] ?? $action_settings['central_action_id'] ?? __( 'Sentient Forms action', 'sentient-forms' );

        return sprintf(
            /* translators: %s is the action label */
            __( '%s blocked this submission. Please review the entry and try again.', 'sentient-forms' ),
            $label,
        );
    }

    /**
     * Add JavaScript to the form editor
     *
     * @return void
     */
    public function editor_js()
    {
        ?>
        <script type="text/javascript">
            jQuery( document ).ready( function( $ )
            {
                // Add custom settings to the form editor
                $( '.sentient_forms_setting' ).each( function()
                {
                    const $this   = $( this );
                    const fieldId = $this.closest( 'li.field' ).data( 'fieldId' );

                    // Initialize settings
                    $this.find( 'input[type="checkbox"]' ).on( 'change', function()
                    {
                        SetFieldProperty( 'sentientFormsEnabled', $( this ).prop( 'checked' ) );
                    } );
                } );
            } );

            // Add custom field setting
            function SetSentientFormsFieldSetting( field )
            {
                const $setting = $( '#sentient_forms_field_setting' );
                if ( field.sentientFormsEnabled )
                {
                    $setting.find( 'input[type="checkbox"]' ).prop( 'checked', true );
                } else
                {
                    $setting.find( 'input[type="checkbox"]' ).prop( 'checked', false );
                }
            }

            // Hook into the form editor
            $( document ).bind( 'gform_load_field_settings', function( event, field, form )
            {
                SetSentientFormsFieldSetting( field );
            } );
        </script>
        <?php
    }

    /**
     * Add tooltips for custom settings
     *
     * @param array $tooltips The existing tooltips.
     *
     * @return array The modified tooltips.
     */
    public function add_tooltips( array $tooltips ): array
    {
        $tooltips[ 'sentient_forms_field_setting' ] = __(
            'Enable Sentient Forms processing for this field. This allows AI-powered actions to be performed on the field data.',
            'sentient-forms',
        );
        return $tooltips;
    }

    /**
     * Add custom settings to the form editor
     *
     * @param int $position The position of the settings.
     * @param int $form_id  The form ID.
     *
     * @return void
     */
    public function field_settings( int $position, int $form_id ): void
    {
        // Add settings at position 50 (advanced section)
        if ( $position === 50 )
        {
            $enable_sentient_forms = esc_html__( 'Enable Sentient Forms', 'sentient-forms' );
            $gform_tooltip         = gform_tooltip( 'sentient_forms_field_setting' );
            printf(
                '<li class="sentient_forms_setting field_setting" id="sentient_forms_field_setting"><input type="checkbox" id="sentient_forms_enabled" onclick="SetFieldProperty(\'sentientFormsEnabled\', this.checked);"/><label for="sentient_forms_enabled" class="inline">%s%s</label></li>',
                esc_html( $enable_sentient_forms ),
                wp_kses_post( $gform_tooltip )
            );
        }
    }

    /**
     * Check if the adapter is active
     *
     * @return bool Whether the adapter is active.
     */
    public function is_active(): bool
    {
        return class_exists( 'GFForms' );
    }

    /**
     * Get form data
     *
     * @param mixed $form_id The form ID.
     *
     * @return array|false The form data or false if not found.
     */
    public function get_form_data( mixed $form_id ): false | array
    {
        if ( !$this->is_active() )
        {
            return false;
        }

        return GFAPI::get_form( $form_id );
    }

    /**
     * Get entry data
     *
     * @param mixed      $entry_id The entry ID.
     * @param mixed|null $form_id  The form ID.
     *
     * @return array|false The entry data or false if not found.
     */
    public function get_entry_data( mixed $entry_id, mixed $form_id = null ): false | array
    {
        if ( !$this->is_active() )
        {
            return false;
        }

        return GFAPI::get_entry( $entry_id );
    }

    /**
     * Get form fields
     *
     * @param mixed $form_id The form ID.
     *
     * @return array The form fields.
     */
    public function get_form_fields( mixed $form_id ): array
    {
        $form = $this->get_form_data( $form_id );
        return $form ? $form[ 'fields' ] : [];
    }

    /**
     * Enqueue frontend real-time suggestion runtime for eligible mappings.
     *
     * @param array     $form    Gravity Forms form object/array.
     * @param bool|int  $is_ajax Whether form is rendered via AJAX.
     *
     * @return void
     */
    public function enqueue_realtime_suggestions_runtime( array $form, bool | int $is_ajax = false ): void
    {
        $form_id = isset( $form['id'] ) ? absint( $form['id'] ) : 0;
        if ( $form_id <= 0 )
        {
            return;
        }

        $settings = $this->get_form_settings( $form_id );
        $runtime_config = $this->build_realtime_runtime_config( $form, $settings );
        if ( null === $runtime_config )
        {
            return;
        }

        $script_handle = 'sentient-forms-realtime-suggestions';
        $style_handle  = 'sentient-forms-realtime-suggestions';
        wp_register_script(
            $script_handle,
            SENTIENT_FORMS_PLUGIN_URL . 'assets/js/realtime-suggestions.js',
            [],
            SENTIENT_FORMS_VERSION,
            true
        );
        wp_register_style(
            $style_handle,
            SENTIENT_FORMS_PLUGIN_URL . 'assets/css/realtime-suggestions.css',
            [],
            SENTIENT_FORMS_VERSION
        );

        wp_enqueue_script( $script_handle );
        wp_enqueue_style( $style_handle );

        $json_config = wp_json_encode( $runtime_config );
        if ( false === $json_config )
        {
            return;
        }

        $inline = sprintf(
            'window.sentientFormsRealtimeSuggestions = window.sentientFormsRealtimeSuggestions || { forms: {} }; window.sentientFormsRealtimeSuggestions.forms[%1$d] = %2$s;',
            $form_id,
            $json_config
        );
        wp_add_inline_script( $script_handle, $inline, 'before' );
    }

    /**
     * Build runtime config payload injected into the GF frontend page.
     *
     * @param array $form          Gravity Forms form array.
     * @param array $form_settings Stored Sentient Forms settings for this form.
     *
     * @return array<string,mixed>|null
     */
    private function build_realtime_runtime_config( array $form, array $form_settings ): ?array
    {
        $form_id = isset( $form['id'] ) ? absint( $form['id'] ) : 0;
        if ( $form_id <= 0 )
        {
            return null;
        }

        $actions = isset( $form_settings['actions'] ) && is_array( $form_settings['actions'] )
            ? $form_settings['actions']
            : [];
        $mappings = $this->collect_realtime_mappings( $actions );
        if ( empty( $mappings ) )
        {
            return null;
        }

        $field_manifest = $this->build_form_field_manifest( $form );
        $total_pages    = 1;
        foreach ( $field_manifest as $field_meta )
        {
            $page_index = isset( $field_meta['page_index'] ) ? (int) $field_meta['page_index'] : 1;
            if ( $page_index > $total_pages )
            {
                $total_pages = $page_index;
            }
        }

        return [
            'form_id'              => $form_id,
            'source'               => $this->get_id(),
            'total_pages'          => $total_pages,
            'suggest_endpoint_url' => rest_url( sprintf( 'sentient-forms/v1/gravity_forms/forms/%d/actions/suggest', $form_id ) ),
            'nonce'                => wp_create_nonce( 'sentient_forms_realtime_suggest_' . $form_id ),
            'mappings'             => $mappings,
            'field_manifest'       => $field_manifest,
        ];
    }

    /**
     * @param array<int,mixed> $actions
     *
     * @return array<int,array<string,mixed>>
     */
    private function collect_realtime_mappings( array $actions ): array
    {
        $eligible = [];

        foreach ( $actions as $action )
        {
            if ( ! is_array( $action ) )
            {
                continue;
            }

            $enabled = array_key_exists( 'is_action_enabled_for_form', $action )
                ? rest_sanitize_boolean( $action['is_action_enabled_for_form'] )
                : true;
            if ( ! $enabled )
            {
                continue;
            }

            $settings = isset( $action['settings'] ) && is_array( $action['settings'] )
                ? $action['settings']
                : [];
            $execution_mode = isset( $settings['execution_mode'] ) && is_scalar( $settings['execution_mode'] )
                ? sanitize_key( (string) $settings['execution_mode'] )
                : 'after_submission';
            if ( 'real_time' !== $execution_mode )
            {
                continue;
            }

            $central_action_id = isset( $action['central_action_id'] ) && is_scalar( $action['central_action_id'] )
                ? sanitize_text_field( (string) $action['central_action_id'] )
                : '';
            if ( '' === $central_action_id )
            {
                continue;
            }

            $mapping_id = isset( $action['id'] ) && is_scalar( $action['id'] )
                ? sanitize_text_field( (string) $action['id'] )
                : '';
            if ( '' === $mapping_id )
            {
                $mapping_id = substr( hash( 'sha256', wp_json_encode( $action ) ), 0, 16 );
            }

            $realtime_settings = isset( $settings['realtime_settings'] ) && is_array( $settings['realtime_settings'] )
                ? $settings['realtime_settings']
                : [];
            $checkpoint_field_ids = [];
            if ( isset( $realtime_settings['checkpoint_field_ids'] ) && is_array( $realtime_settings['checkpoint_field_ids'] ) )
            {
                foreach ( $realtime_settings['checkpoint_field_ids'] as $field_id )
                {
                    if ( ! is_scalar( $field_id ) )
                    {
                        continue;
                    }

                    $normalized = sanitize_text_field( (string) $field_id );
                    if ( '' !== $normalized )
                    {
                        $checkpoint_field_ids[] = $normalized;
                    }
                }
            }
            $checkpoint_field_ids = array_values( array_unique( $checkpoint_field_ids ) );

            $eligible[] = [
                'mapping_id'            => $mapping_id,
                'central_action_id'     => $central_action_id,
                'action_name_label'     => isset( $action['action_name_label'] ) && is_scalar( $action['action_name_label'] )
                    ? sanitize_text_field( (string) $action['action_name_label'] )
                    : $central_action_id,
                'debounce_ms'           => $this->normalize_realtime_millis(
                    $realtime_settings['debounce_ms'] ?? self::REALTIME_DEFAULT_DEBOUNCE_MS,
                    self::REALTIME_MIN_DEBOUNCE_MS,
                    self::REALTIME_MAX_DEBOUNCE_MS
                ),
                'cooldown_ms'           => $this->normalize_realtime_millis(
                    $realtime_settings['cooldown_ms'] ?? self::REALTIME_DEFAULT_COOLDOWN_MS,
                    self::REALTIME_MIN_COOLDOWN_MS,
                    self::REALTIME_MAX_COOLDOWN_MS
                ),
                'manual_refresh_enabled'=> array_key_exists( 'manual_refresh_enabled', $realtime_settings )
                    ? rest_sanitize_boolean( $realtime_settings['manual_refresh_enabled'] )
                    : true,
                'checkpoint_field_ids'  => $checkpoint_field_ids,
            ];
        }

        return $eligible;
    }

    /**
     * @param array<string,mixed> $form
     *
     * @return array<int,array<string,mixed>>
     */
    private function build_form_field_manifest( array $form ): array
    {
        $manifest = [];
        $fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : [];

        foreach ( $fields as $field )
        {
            if ( ! is_object( $field ) )
            {
                continue;
            }

            $field_id = isset( $field->id ) ? sanitize_text_field( (string) $field->id ) : '';
            $field_type = isset( $field->type ) ? sanitize_key( (string) $field->type ) : '';
            if ( '' === $field_id || '' === $field_type || 'page' === $field_type )
            {
                continue;
            }

            $manifest[] = [
                'field_id'   => $field_id,
                'label'      => isset( $field->label ) ? sanitize_text_field( (string) $field->label ) : '',
                'type'       => $field_type,
                // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Gravity Forms field objects expose pageNumber.
                'page_index' => isset( $field->pageNumber ) ? max( 1, (int) $field->pageNumber ) : 1,
            ];
        }

        return $manifest;
    }

    private function normalize_realtime_millis( mixed $raw_value, int $min, int $max ): int
    {
        $value = is_numeric( $raw_value ) ? (int) $raw_value : $min;
        return max( $min, min( $max, $value ) );
    }

    private function get_form_option_name( int $form_id ): string
    {
        return sprintf( 'sentient_forms_actions_%s_%d', $this->get_id(), absint( $form_id ) );
    }

    /**
     * Resolve effective execution disable flags from form settings + plugin settings.
     *
     * @param array $form_settings Form-level settings array.
     * @return array{sf_disabled: bool, global_disabled: bool, provider_disabled: bool, effective_disabled: bool}
     */
    private function get_execution_disable_flags( array $form_settings ): array
    {
        $plugin_settings = get_option( 'sentient_forms_plugin_settings', [] );
        if ( ! is_array( $plugin_settings ) )
        {
            $plugin_settings = [];
        }

        $provider_map = $plugin_settings['execution_provider_disabled'] ?? [];
        if ( ! is_array( $provider_map ) )
        {
            $provider_map = [];
        }

        $provider_key = sanitize_key( $this->get_id() );
        $sf_disabled = ! empty( $form_settings['sf_disabled'] );
        $global_disabled = ! empty( $plugin_settings['execution_global_disabled'] );
        $provider_disabled = ! empty( $provider_map[ $provider_key ] );

        return [
            'sf_disabled'       => $sf_disabled,
            'global_disabled'   => $global_disabled,
            'provider_disabled' => $provider_disabled,
            'effective_disabled'=> $sf_disabled || $global_disabled || $provider_disabled,
        ];
    }

    /**
     * Get form settings
     *
     * @param mixed $form_id The form ID.
     *
     * @return array The form settings.
     */
    public function get_form_settings( mixed $form_id ): array
    {
        $option_name = $this->get_form_option_name( $form_id );
        $settings    = get_option( $option_name, [] );

        if ( empty( $settings ) )
        {
            // Fallback to legacy option naming for backwards compatibility.
            $settings = get_option( 'sentient_forms_gravity_forms_' . $form_id, [] );
        }

        // Get global settings as defaults
        $global_settings = $this->plugin->get_options();
        $global_settings = $global_settings[ 'global_settings' ] ?? [];

        // Merge with global settings
        $settings = wp_parse_args(
            $settings,
            [
                'enabled' => $global_settings[ 'auto_apply_actions' ] ?? false,
                'actions' => [],
            ],
        );

        return $this->merge_local_first_form_mappings( $settings, $form_id );
    }

    /**
     * Update form settings
     *
     * @param mixed $form_id  The form ID.
     * @param array $settings The settings to update.
     *
     * @return bool Whether the update was successful.
     */
    public function update_form_settings( mixed $form_id, array $settings ): bool
    {
        $option_name = $this->get_form_option_name( $form_id );
        return update_option( $option_name, $settings, false );
    }

    /**
     * Resolve mapping runtime settings by applying action/form defaults beneath mapping overrides.
     *
     * @param array<string, mixed> $mapping Mapping payload.
     * @param int                  $form_id Form id.
     *
     * @return array<string, mixed>
     */
    private function resolve_mapping_runtime_settings( array $mapping, int $form_id ): array
    {
        $action_id = isset( $mapping['central_action_id'] ) && is_scalar( $mapping['central_action_id'] )
            ? sanitize_key( (string) $mapping['central_action_id'] )
            : '';
        if ( '' === $action_id )
        {
            return $mapping;
        }

        $resolved        = $mapping;
        $action_defaults = $this->get_action_defaults_config( $action_id );
        $form_config     = $this->get_form_action_config( $this->get_id(), $form_id, $action_id );
        $mapping_settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) ? $mapping['settings'] : [];

        foreach ( [ 'model_selection', 'include_site_context' ] as $field )
        {
            $mapping_settings = $this->merge_inherited_field( $mapping_settings, $field, $form_config, $action_defaults );
        }

        if ( $this->is_spam_action_id( $action_id ) )
        {
            foreach ( [ 'spam_positive_examples', 'spam_negative_examples' ] as $field )
            {
                $mapping_settings = $this->merge_inherited_field( $mapping_settings, $field, $form_config, $action_defaults );
            }

            foreach ( [ 'spam_result_display_mode', 'spam_indicators_display' ] as $field )
            {
                $mapping_settings = $this->merge_inherited_field( $mapping_settings, $field, $form_config, $action_defaults );
            }

            foreach ( [ 'suppress_notifications_on_spam', 'skip_downstream_on_spam' ] as $field )
            {
                $mapping_settings = $this->merge_inherited_boolean_field( $mapping_settings, $field, $form_config, $action_defaults );
            }
        }

        $resolved['settings'] = $mapping_settings;

        return $resolved;
    }

    /**
     * Merge local-first mapping table rows into the legacy form-settings shape used
     * by the Gravity Forms planner while the admin UI is still being cut over.
     *
     * @param array<string, mixed> $settings Current form settings.
     * @param mixed                $form_id  Gravity Forms form id.
     *
     * @return array<string, mixed>
     */
    private function merge_local_first_form_mappings( array $settings, mixed $form_id ): array
    {
        if ( ! class_exists( 'Sentient_Forms_Form_Mappings_Repository' ) )
        {
            return $settings;
        }

        $form_id_string = sanitize_text_field( (string) $form_id );
        if ( '' === $form_id_string )
        {
            return $settings;
        }

        global $wpdb;
        $repository = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $rows       = $repository->list_for_form( $this->get_id(), $form_id_string );
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
            $settings[ $mapping_id ] = $runtime_mapping;
            $settings['actions'][ $mapping_id ] = $runtime_mapping;
        }

        return $settings;
    }

    /**
     * Convert a local custom-table mapping row into the runtime mapping shape
     * consumed by the existing Gravity Forms execution planner.
     *
     * @param array<string, mixed> $row Local mapping row.
     *
     * @return array<string, mixed>|null Runtime mapping or null when unsupported.
     */
    private function normalize_local_first_form_mapping( array $row ): ?array
    {
        $id = absint( $row['id'] ?? 0 );
        if ( $id <= 0 )
        {
            return null;
        }

        $hook = sanitize_key( (string) ( $row['hook'] ?? '' ) );
        if ( ! in_array( $hook, [ 'gform_validation', 'gform_after_submission' ], true ) )
        {
            return null;
        }

        if ( 'custom_action' !== sanitize_key( (string) ( $row['action_kind'] ?? '' ) ) )
        {
            return null;
        }

        $custom_action = $this->get_local_first_custom_action( absint( $row['action_id'] ?? 0 ) );
        $identity      = $this->resolve_local_first_action_identity( $custom_action );
        $execution_mode = 'gform_validation' === $hook || 'sync' === sanitize_key( (string) ( $row['execution_mode'] ?? '' ) )
            ? 'validation'
            : 'after_submission';

        $settings = [
            'local_form_mapping_id' => $id,
            'execution_mode'        => $execution_mode,
            'input_mapping'         => is_array( $row['input_bindings_json'] ?? null )
                ? $row['input_bindings_json']
                : [],
            'trigger_sources'       => [
                $hook => [ 'type' => 'hook_root' ],
            ],
        ];

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

        $mark_as_spam = $this->local_spam_effect_enabled( [ 'settings' => $settings ] );

        return [
            'local_mapping_id'           => 'local_first_' . $id,
            'local_form_mapping_id'      => $id,
            'central_action_id'          => $identity['action_code'],
            'action_type_indicator'      => 'local_first',
            'action_kind'                => 'custom_action',
            'action_name_label'          => $identity['action_label'],
            'is_action_enabled_for_form' => ! empty( $row['enabled'] ),
            'trigger_hooks'              => [ $hook ],
            'execution_mode'             => $execution_mode,
            'execution_priority'         => $id,
            'mark_as_spam'               => $mark_as_spam,
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
        $action_id = absint( $action_id );
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
            $repair_state         = 'active' === $linked_action_status ? 'ok' : 'needs_repair';
            $action_code          = isset( $custom_action['code'] ) && is_scalar( $custom_action['code'] )
                ? sanitize_key( (string) $custom_action['code'] )
                : $action_code;
            $action_label         = isset( $custom_action['display_name'] ) && is_scalar( $custom_action['display_name'] )
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
                $action_code = $template_code;
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
     * Load and normalize global action defaults for a specific action.
     *
     * @param string $action_id Action id.
     *
     * @return array<string, mixed>
     */
    private function get_action_defaults_config( string $action_id ): array
    {
        $config = get_option( self::ACTION_DEFAULTS_OPTION_PREFIX . sanitize_key( $action_id ), [] );

        return $this->normalize_action_config_payload( $config );
    }

    /**
     * Load and normalize form-level action config for a specific action.
     *
     * @param string $form_source Form source id.
     * @param int    $form_id     Form id.
     * @param string $action_id   Action id.
     *
     * @return array<string, mixed>
     */
    private function get_form_action_config( string $form_source, int $form_id, string $action_id ): array
    {
        $configs = get_option(
            self::FORM_ACTION_CONFIG_OPTION_PREFIX . sanitize_key( $form_source ) . '_' . $form_id,
            []
        );

        if ( ! is_array( $configs ) )
        {
            return [];
        }

        return $this->normalize_action_config_payload( $configs[ $action_id ] ?? [] );
    }

    /**
     * Normalize persisted action config payloads for runtime use.
     *
     * @param mixed $config Raw config value.
     *
     * @return array<string, mixed>
     */
    private function normalize_action_config_payload( $config ): array
    {
        if ( ! is_array( $config ) )
        {
            return [];
        }

        if ( empty( $config['model_selection'] ) && ! empty( $config['model_override'] ) && is_string( $config['model_override'] ) )
        {
            $config['model_selection'] = [
                'primary'   => sanitize_text_field( $config['model_override'] ),
                'backup'    => null,
                'is_preset' => str_starts_with( (string) $config['model_override'], 'sf_' ),
            ];
        }

        foreach ( [ 'suppress_notifications_on_spam', 'skip_downstream_on_spam' ] as $field )
        {
            if ( array_key_exists( $field, $config ) )
            {
                $config[ $field ] = rest_sanitize_boolean( $config[ $field ] );
            }
        }

        if ( array_key_exists( 'spam_result_display_mode', $config ) )
        {
            $config['spam_result_display_mode'] = $this->normalize_spam_result_display_mode( $config['spam_result_display_mode'] );
        }

        if ( array_key_exists( 'spam_indicators_display', $config ) )
        {
            $config['spam_indicators_display'] = $this->normalize_spam_indicators_display( $config['spam_indicators_display'] );
        }

        return $config;
    }

    private function normalize_spam_result_display_mode( mixed $value ): string
    {
        $value = sanitize_key( (string) $value );

        return match ( $value ) {
            'entry_note' => 'all_results',
            'silent'     => 'none',
            'none',
            'spam_only',
            'all_results' => $value,
            default      => 'all_results',
        };
    }

    private function normalize_spam_indicators_display( mixed $value ): string
    {
        return 'detailed' === sanitize_key( (string) $value ) ? 'detailed' : 'simple';
    }

    /**
     * Merge a generic inheritable field into resolved settings when mapping scope does not define it.
     *
     * @param array<string, mixed> $resolved        Current resolved settings.
     * @param string               $field           Field name.
     * @param array<string, mixed> $form_config     Form-level config.
     * @param array<string, mixed> $action_defaults Action-level defaults.
     *
     * @return array<string, mixed>
     */
    private function merge_inherited_field( array $resolved, string $field, array $form_config, array $action_defaults ): array
    {
        if ( $this->has_inherited_value( $resolved, $field ) )
        {
            return $resolved;
        }

        if ( $this->has_inherited_value( $form_config, $field ) )
        {
            $resolved[ $field ] = $form_config[ $field ];
            return $resolved;
        }

        if ( $this->has_inherited_value( $action_defaults, $field ) )
        {
            $resolved[ $field ] = $action_defaults[ $field ];
        }

        return $resolved;
    }

    /**
     * Merge an inheritable boolean field into resolved settings when mapping scope does not define it.
     *
     * @param array<string, mixed> $resolved        Current resolved settings.
     * @param string               $field           Field name.
     * @param array<string, mixed> $form_config     Form-level config.
     * @param array<string, mixed> $action_defaults Action-level defaults.
     *
     * @return array<string, mixed>
     */
    private function merge_inherited_boolean_field( array $resolved, string $field, array $form_config, array $action_defaults ): array
    {
        if ( array_key_exists( $field, $resolved ) )
        {
            $resolved[ $field ] = rest_sanitize_boolean( $resolved[ $field ] );
            return $resolved;
        }

        if ( array_key_exists( $field, $form_config ) )
        {
            $resolved[ $field ] = rest_sanitize_boolean( $form_config[ $field ] );
            return $resolved;
        }

        if ( array_key_exists( $field, $action_defaults ) )
        {
            $resolved[ $field ] = rest_sanitize_boolean( $action_defaults[ $field ] );
        }

        return $resolved;
    }

    /**
     * Determine whether a field contains a meaningful inherited value.
     *
     * @param array<string, mixed> $settings Settings array.
     * @param string               $field    Field name.
     *
     * @return bool
     */
    private function has_inherited_value( array $settings, string $field ): bool
    {
        if ( ! array_key_exists( $field, $settings ) )
        {
            return false;
        }

        $value = $settings[ $field ];

        if ( is_array( $value ) )
        {
            return ! empty( $value );
        }

        if ( is_string( $value ) )
        {
            return '' !== trim( $value );
        }

        return null !== $value;
    }

    /**
     * Determine whether an action id refers to spam detection.
     *
     * @param string $action_id Action id.
     *
     * @return bool
     */
    private function is_spam_action_id( string $action_id ): bool
    {
        return in_array( sanitize_key( $action_id ), [ 'spam_detection_v1', 'spam_analysis' ], true );
    }

    /**
     * Get available forms
     *
     * @return array The available forms.
     */
    public function get_forms(): array
    {
        if ( !$this->is_active() )
        {
            return [];
        }

        /**
         * @var array{
         *     id: int,
         *     title: string,
         *     description: string,
         *     date_created: string,
         *     is_active: bool,
         *     is_trash: bool,
         *     version: string,
         *     fields: array,
         *     button: array,
         *     notifications: array,
         *     confirmations: array,
         *     confirmation: null|array,
         *     save: array,
         *     personalData: array,
         *     pagination: null|array,
         *     lastPageButton: null|array,
         *     nextFieldId: int,
         * }[] $forms
         */
        $forms  = GFAPI::get_forms();
        $result = [];

        foreach ( $forms as $form )
        {
            $result[] = [
                'id'                 => $form[ 'id' ],
                'title'              => $form[ 'title' ],
                'adapter'            => $this->get_id(),
                'adapter_name'       => $this->get_name(),
                'provider_is_active' => !empty( $form['is_active'] ),
                'provider_edit_url'  => admin_url(
                    sprintf(
                        'admin.php?page=gf_edit_forms&id=%d',
                        absint( $form['id'] )
                    )
                ),
                'settings'           => $this->get_form_settings( $form[ 'id' ] ),
            ];
        }

        return $result;
    }

    /**
     * Get the adapter settings fields
     *
     * @return array The settings fields.
     */
    public function get_settings_fields(): array
    {
        return [
            'enabled'    => [
                'type'    => 'checkbox',
                'label'   => __( 'Enable Sentient Forms for Gravity Forms', 'sentient-forms' ),
                'default' => true,
            ],
            'auto_apply' => [
                'type'    => 'checkbox',
                'label'   => __( 'Auto-apply actions to all forms', 'sentient-forms' ),
                'default' => false,
            ],
        ];
    }

    /**
     * Retrieve metadata for a specific form entry.
     * Mirrors update_entry_meta but uses gform_get_meta.
     *
     * @param mixed  $entry_id  The ID of the entry.
     * @param string $meta_key  The meta key to fetch.
     *
     * @return mixed|null Meta value or null on failure/missing.
     */
    public function get_entry_meta( $entry_id, string $meta_key )
    {
        if ( !$this->is_active() )
        {
            return null;
        }

        if ( !str_starts_with( $meta_key, 'sentient_forms_' ) )
        {
            $meta_key = 'sentient_forms_' . $meta_key;
        }

        if ( !function_exists( 'gform_get_meta' ) )
        {
            return null;
        }

        try
        {
            return gform_get_meta( $entry_id, $meta_key );
        } catch ( Exception $e )
        {
            sentient_forms_debug_log(
                'Sentient Forms could not get entry meta.',
                [
                    'entry_id'       => $entry_id,
                    'entry_meta_key' => $meta_key,
                    'error'          => $e->getMessage(),
                ]
            );
            return null;
        }
    }

    /**
     * Update metadata for a specific form entry.
     * Used to store results of Sentient Forms actions (e.g., spam score, evaluation notes).
     *
     * @param mixed  $entry_id   The ID of the entry.
     * @param string $meta_key   The meta key to update.
     * @param mixed  $meta_value The new meta value.
     *
     * @return bool True on success, false on failure.
     */
    public function update_entry_meta( $entry_id, string $meta_key, $meta_value ): bool
    {
        if ( !$this->is_active() )
        {
            return false;
        }

        // Normalize the meta key by prefixing it if not already
        if ( !str_starts_with( $meta_key, 'sentient_forms_' ) )
        {
            $meta_key = 'sentient_forms_' . $meta_key;
        }

        // Check if Gravity Forms API is available
        if ( !class_exists( 'GFAPI' ) )
        {
            return false;
        }

        try
        {
            // Use gform_update_meta to update entry meta
            $result = gform_update_meta( $entry_id, $meta_key, $meta_value );
            return $result !== false;
        } catch ( Exception $e )
        {
            sentient_forms_debug_log(
                'Sentient Forms could not update entry meta.',
                [
                    'entry_id'       => $entry_id,
                    'entry_meta_key' => $meta_key,
                    'error'          => $e->getMessage(),
                ]
            );
            return false;
        }
    }

    /**
     * Mark a form entry as spam.
     * How this is implemented depends on the form provider's capabilities.
     *
     * @param mixed $entry_id The ID of the entry.
     *
     * @return bool True on success, false on failure.
     */
    public function mark_entry_as_spam( mixed $entry_id ): bool
    {
        if ( !$this->is_active() )
        {
            return false;
        }

        // Check if Gravity Forms API is available
        if ( !class_exists( 'GFAPI' ) )
        {
            return false;
        }

        try
        {
            // Get the entry to ensure it exists
            $entry = GFAPI::get_entry( $entry_id );
            if ( is_wp_error( $entry ) )
            {
                sentient_forms_debug_log(
                    'Sentient Forms could not find entry before marking spam.',
                    [
                        'entry_id'      => $entry_id,
                        'error_code'    => $entry->get_error_code(),
                        'error_message' => $entry->get_error_message(),
                    ]
                );
                return false;
            }

            if ( isset( $entry['status'] ) && 'spam' === $entry['status'] )
            {
                return true;
            }

            // Update the status property to 'spam' (per Gravity Forms API)
            $result = GFAPI::update_entry_property( $entry_id, 'status', 'spam' );

            // Add a note about the spam marking
            if ( $result && !is_wp_error( $result ) )
            {
                $this->add_entry_note_if_missing(
                    $entry_id,
                    'Sentient Forms AI',
                    __( 'This entry has been marked as spam by Sentient Forms AI.', 'sentient-forms' ),
                );

                return true;
            }

            return false;
        } catch ( Exception $e )
        {
            sentient_forms_debug_log(
                'Sentient Forms could not mark entry as spam.',
                [
                    'entry_id' => $entry_id,
                    'error'    => $e->getMessage(),
                ]
            );
            return false;
        }
    }

    /**
     * Reject a form submission.
     * This could involve marking the entry as rejected, trashing it, or adding a specific note.
     *
     * @param mixed  $entry_id The ID of the entry.
     * @param string $message  The reason for rejection.
     *
     * @return bool True on success, false on failure.
     */
    public function reject_submission( mixed $entry_id, string $message ): bool
    {
        if ( !$this->is_active() )
        {
            return false;
        }

        // Check if Gravity Forms API is available
        if ( !class_exists( 'GFAPI' ) )
        {
            return false;
        }

        try
        {
            // Get the entry to ensure it exists
            $entry = GFAPI::get_entry( $entry_id );
            if ( is_wp_error( $entry ) )
            {
                sentient_forms_debug_log(
                    'Sentient Forms could not find entry before rejecting submission.',
                    [
                        'entry_id'      => $entry_id,
                        'error_code'    => $entry->get_error_code(),
                        'error_message' => $entry->get_error_message(),
                    ]
                );
                return false;
            }

            // Update status to 'trash' (effectively rejecting the submission)
            $result = GFAPI::update_entry_property( $entry_id, 'status', 'trash' );

            // Add a note explaining the rejection
            if ( $result && !is_wp_error( $result ) )
            {
                // Store rejection reason as entry meta
                $this->update_entry_meta( $entry_id, 'rejection_reason', $message );

                // Add a note with the rejection message
                $this->add_entry_note(
                    $entry_id,
                    'Sentient Forms AI',
                    sprintf(
                        /* translators: %s: rejection reason. */
                        __( 'This submission was rejected by Sentient Forms AI for the following reason: %s', 'sentient-forms' ),
                        $message,
                    ),
                );

                return true;
            }

            return false;
        } catch ( Exception $e )
        {
            sentient_forms_debug_log(
                'Sentient Forms could not reject submission.',
                [
                    'entry_id' => $entry_id,
                    'error'    => $e->getMessage(),
                ]
            );
            return false;
        }
    }

    /**
     * Add a note to a form entry.
     * Notes are often used for logging action results or manual reviews.
     *
     * @param mixed  $entry_id     The ID of the entry.
     * @param string $note_author  The author of the note (e.g., "Sentient Forms AI").
     * @param string $note_content The content of the note.
     *
     * @return bool True if the note was added successfully, false otherwise.
     */
    public function add_entry_note( mixed $entry_id, string $note_author, string $note_content ): bool
    {
        if ( !$this->is_active() )
        {
            return false;
        }

        if ( class_exists( 'GFAPI' ) && is_callable( [ 'GFAPI', 'get_entry' ] ) )
        {
            try
            {
                $entry = GFAPI::get_entry( $entry_id );
                if ( is_wp_error( $entry ) )
                {
                    sentient_forms_debug_log(
                        'Sentient Forms could not find entry before adding note.',
                        [
                            'entry_id'      => $entry_id,
                            'error_code'    => $entry->get_error_code(),
                            'error_message' => $entry->get_error_message(),
                        ]
                    );
                }
            } catch ( Exception $e )
            {
                sentient_forms_debug_log(
                    'Sentient Forms could not get entry before adding note.',
                    [
                        'entry_id' => $entry_id,
                        'error'    => $e->getMessage(),
                    ]
                );
            }
        }

        // Check if GF Notes API method exists
        if ( !class_exists( 'GFFormsModel' ) || !is_callable( [ 'GFFormsModel', 'add_note' ] ) )
        {
            // Fall back to meta storage if GF notes function isn't available
            $notes   = $this->get_entry_meta( $entry_id, 'sentient_forms_notes' ) ?: [];
            $notes[] = [
                'author'  => $note_author,
                'content' => $note_content,
                'date'    => current_time( 'mysql' ),
            ];
            return $this->update_entry_meta( $entry_id, 'sentient_forms_notes', $notes );
        }

        // Add the note using GF notes functionality
        try
        {
            // Format from GFFormsModel::add_note($entry_id, $user_id, $user_name, $note, $note_type = 'user')
            $result = GFFormsModel::add_note(
                $entry_id,
                0,        // User ID (0 for system)
                $note_author,
                $note_content,
                'system', // Note type
            );

            if ( false !== $result )
            {
                $this->append_entry_note_fallback_meta( $entry_id, $note_author, $note_content );
            }

            return $result !== false;
        } catch ( Exception $e )
        {
            sentient_forms_debug_log(
                'Sentient Forms could not add entry note.',
                [
                    'entry_id' => $entry_id,
                    'error'    => $e->getMessage(),
                ]
            );
            return false;
        }
    }

    private function append_entry_note_fallback_meta( mixed $entry_id, string $note_author, string $note_content ): void
    {
        $notes = $this->get_entry_meta( $entry_id, 'sentient_forms_notes' );
        if ( ! is_array( $notes ) )
        {
            $notes = [];
        }

        $notes[] = [
            'author'  => $note_author,
            'content' => $note_content,
            'date'    => current_time( 'mysql' ),
        ];

        $this->update_entry_meta( $entry_id, 'sentient_forms_notes', $notes );
    }

    private function add_entry_note_if_missing( mixed $entry_id, string $note_author, string $note_content ): bool
    {
        if ( $this->entry_note_exists( $entry_id, $note_author, $note_content ) )
        {
            return true;
        }

        return $this->add_entry_note( $entry_id, $note_author, $note_content );
    }

    private function entry_note_exists( mixed $entry_id, string $note_author, string $note_content ): bool
    {
        $notes = [];

        if ( class_exists( 'GFFormsModel' ) && is_callable( [ 'GFFormsModel', 'get_lead_notes' ] ) )
        {
            try
            {
                $notes = GFFormsModel::get_lead_notes( $entry_id );
            } catch ( Throwable $throwable )
            {
                $notes = [];
            }
        }

        if ( empty( $notes ) )
        {
            $fallback_notes = $this->get_entry_meta( (int) $entry_id, 'sentient_forms_notes' );
            $notes          = is_array( $fallback_notes ) ? $fallback_notes : [];
        }

        if ( ! is_array( $notes ) )
        {
            return false;
        }

        foreach ( $notes as $note )
        {
            if ( is_array( $note ) )
            {
                $stored_author = (string) ( $note['user_name'] ?? $note['note_author'] ?? $note['author'] ?? '' );
                $stored_value  = (string) ( $note['value'] ?? $note['note'] ?? $note['content'] ?? '' );
            }
            elseif ( is_object( $note ) )
            {
                $stored_author = (string) ( $note->user_name ?? $note->note_author ?? $note->author ?? '' );
                $stored_value  = (string) ( $note->value ?? $note->note ?? $note->content ?? '' );
            }
            else
            {
                continue;
            }

            if ( $stored_author === $note_author && $stored_value === $note_content )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the specific WordPress action hook name for a generic event.
     * This allows actions to hook into form provider events (like submission)
     * without needing to know the provider-specific hook names.
     *
     * @param string $event_name Generic event name (e.g., 'before_submission', 'after_submission', 'entry_created').
     *
     * @return string|null The WordPress hook name, or null if not applicable/supported for the event.
     */
    public function get_action_hook_for_event( string $event_name ): ?string
    {
        if ( !$this->is_active() )
        {
            return null;
        }

        // Map generic event names to Gravity Forms specific hooks
        $event_hook_map = [
            // Before form is displayed
            'form_display'      => 'gform_pre_render',

            // Before submission validation
            'before_validation' => 'gform_pre_validation',

            // During validation
            'validation'        => 'gform_validation',

            // After validation but before submission processing
            'after_validation'  => 'gform_validation_passed',

            // Before submission is saved/processed
            'before_submission' => 'gform_pre_submission',

            // After submission has been processed/saved
            'after_submission'  => 'gform_after_submission',

            // When an entry is created
            'entry_created'     => 'gform_entry_created',

            // When an entry is updated
            'entry_updated'     => 'gform_post_update_entry',

            // When an entry is sent to trash
            'entry_trashed'     => 'gform_update_status',

            // When a payment is completed
            'payment_completed' => 'gform_post_payment_completed',

            // When a payment fails
            'payment_failed'    => 'gform_post_payment_failed',
        ];

        return $event_hook_map[ $event_name ] ?? null;
    }

    /**
     * Retrieve the form object/structure from the provider.
     * This can be useful for accessing detailed form settings or properties.
     * The structure of the returned object/array depends on the form provider.
     *
     * @param int $form_id The ID of the form.
     *
     * @return array|object|null The form object/array, or null if not found.
     */
    public function get_form_object( int $form_id ): object | array | null
    {
        if ( !$this->is_active() )
        {
            return null;
        }

        // Check if Gravity Forms API is available
        if ( !class_exists( 'GFAPI' ) )
        {
            return null;
        }

        try
        {
            // Get the form using Gravity Forms API
            $form = GFAPI::get_form( $form_id );

            // GFAPI::get_form returns false if form not found
            if ( $form === false )
            {
                return null;
            }

            return $form;
        } catch ( Exception $e )
        {
            sentient_forms_debug_log(
                'Sentient Forms could not retrieve Gravity Forms form object.',
                [
                    'form_id' => $form_id,
                    'error'   => $e->getMessage(),
                ]
            );
            return null;
        }
    }

    public function finalize_async_success( array $context, array $result ): void
    {
        $entry_id = isset( $context['entry_id'] ) ? absint( $context['entry_id'] ) : 0;
        if ( $entry_id <= 0 )
        {
            return;
        }

        $this->persist_entry_runtime_meta( $entry_id, 'sentient_forms_last_error', '' );
        $this->persist_entry_runtime_meta( $entry_id, 'sentient_forms_last_processed_at', current_time( 'mysql' ) );

        $classification = $this->extract_spam_classification( $result );
        $excerpt        = $classification ? '' : $this->format_async_result_excerpt( $result );

        $this->persist_entry_runtime_meta(
            $entry_id,
            'sentient_forms_last_response',
            wp_json_encode( Sentient_Forms_Local_Data_Governance::sanitize_execution_payload_for_storage( $result ) )
        );

        // CA-EXEC-001: Store structured output validity for efficient querying.
        $structured_valid = ! empty( $result['result_data']['structured_output_valid'] );
        $this->persist_entry_runtime_meta( $entry_id, 'sentient_forms_structured_output_valid', $structured_valid ? '1' : '0' );

        if ( empty( $classification ) )
        {
            $this->add_entry_note(
                $entry_id,
                'Sentient Forms AI',
                sprintf(
                    /* translators: 1: action label, 2: result excerpt. */
                    __( 'Sentient Forms finished %1$s. Result: %2$s', 'sentient-forms' ),
                    $this->get_async_action_label( $context ),
                    $excerpt,
                ),
            );
        }

        // FR-001, FR-002: Auto-mark spam entries in Gravity Forms
        $this->maybe_mark_entry_as_spam_from_result( $entry_id, $context, $result );

        // FR-008: Log successful action execution
        $this->log_action_execution( $context, $result, 'success' );
        $this->run_post_execution_actions( $entry_id, $context, $result );

        $this->resolve_deferred_notifications_after_async_completion(
            $context,
            $this->should_suppress_deferred_notifications_from_result( $context, $result ),
        );
    }

    /**
     * Run configured post-execution side effects after CPS/local action success.
     *
     * These effects are intentionally best-effort. The form submission and CPS result are already
     * complete, so a failed email/webhook/hook must be observable without converting the action to
     * a failed execution.
     *
     * @param int   $entry_id Gravity Forms entry ID.
     * @param array $context  Runtime action context.
     * @param array $result   CPS/local action result.
     */
    private function run_post_execution_actions( int $entry_id, array $context, array $result ): void
    {
        if ( $entry_id <= 0 )
        {
            return;
        }

        $actions = $this->get_configured_post_execution_actions( $context );
        if ( [] === $actions )
        {
            return;
        }

        $results = [];
        foreach ( $actions as $index => $action )
        {
            $type = isset( $action['type'] ) && is_scalar( $action['type'] )
                ? sanitize_key( (string) $action['type'] )
                : 'unknown';

            try
            {
                $results[] = $this->run_post_execution_action( $entry_id, $context, $result, $action, $index );
            }
            catch ( Throwable $throwable )
            {
                $results[] = [
                    'index'   => $index,
                    'type'    => $type,
                    'status'  => 'failed',
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        $this->record_post_execution_action_results( $entry_id, $results );
    }

    /**
     * Read configured post-execution effects from a mapping context.
     *
     * @param array $context Runtime action context.
     *
     * @return array<int, array<string, mixed>>
     */
    private function get_configured_post_execution_actions( array $context ): array
    {
        $settings = isset( $context['settings'] ) && is_array( $context['settings'] )
            ? $context['settings']
            : [];

        $candidates = [
            $settings['post_execution_actions'] ?? null,
            $settings['custom_effects'] ?? null,
            $settings['effects'] ?? null,
            $context['post_execution_actions'] ?? null,
        ];

        foreach ( $candidates as $candidate )
        {
            if ( ! is_array( $candidate ) || [] === $candidate )
            {
                continue;
            }

            if ( isset( $candidate['type'] ) || isset( $candidate['kind'] ) )
            {
                $candidate = [ $candidate ];
            }

            $actions = [];
            foreach ( $candidate as $action )
            {
                if ( ! is_array( $action ) )
                {
                    continue;
                }

                if ( isset( $action['enabled'] ) && false === (bool) $action['enabled'] )
                {
                    continue;
                }

                if ( ! isset( $action['type'] ) && isset( $action['kind'] ) )
                {
                    $action['type'] = $action['kind'];
                }

                if ( isset( $action['type'] ) && is_scalar( $action['type'] ) )
                {
                    $actions[] = $action;
                }
            }

            if ( [] !== $actions )
            {
                return array_values( $actions );
            }
        }

        return [];
    }

    /**
     * Execute one configured post-execution effect.
     *
     * @param int   $entry_id Gravity Forms entry ID.
     * @param array $context  Runtime action context.
     * @param array $result   CPS/local action result.
     * @param array $action   Effect configuration.
     * @param int   $index    Effect index.
     *
     * @return array<string, mixed>
     */
    private function run_post_execution_action( int $entry_id, array $context, array $result, array $action, int $index ): array
    {
        $type = isset( $action['type'] ) && is_scalar( $action['type'] )
            ? sanitize_key( (string) $action['type'] )
            : '';

        if ( '' === $type )
        {
            return [
                'index'   => $index,
                'type'    => 'unknown',
                'status'  => 'failed',
                'message' => __( 'Missing post-execution action type.', 'sentient-forms' ),
            ];
        }

        switch ( $type )
        {
            case 'entry_note':
                $message = isset( $action['message'] ) && is_scalar( $action['message'] )
                    ? (string) $action['message']
                    : (string) ( $action['template'] ?? __( 'Sentient Forms completed {{action_label}}. Result: {{llm_output}}', 'sentient-forms' ) );
                $note = $this->render_post_execution_template( $message, $entry_id, $context, $result );

                return [
                    'index'   => $index,
                    'type'    => $type,
                    'status'  => $this->add_entry_note( $entry_id, 'Sentient Forms AI', $note ) ? 'success' : 'failed',
                    'message' => $note,
                ];

            case 'send_email':
                return $this->run_post_execution_email_action( $entry_id, $context, $result, $action, $index, $type );

            case 'wp_hook':
                return $this->run_post_execution_hook_action( $entry_id, $context, $result, $action, $index, $type );

            case 'webhook':
                return $this->run_post_execution_webhook_action( $entry_id, $context, $result, $action, $index, $type );

            default:
                return [
                    'index'   => $index,
                    'type'    => $type,
                    'status'  => 'failed',
                    'message' => sprintf(
                        /* translators: %s is the unsupported effect type. */
                        __( 'Unsupported post-execution action type: %s', 'sentient-forms' ),
                        $type,
                    ),
                ];
        }
    }

    /**
     * Run a post-execution email effect.
     *
     * @param int    $entry_id Gravity Forms entry ID.
     * @param array  $context  Runtime action context.
     * @param array  $result   CPS/local action result.
     * @param array  $action   Effect configuration.
     * @param int    $index    Effect index.
     * @param string $type     Effect type.
     *
     * @return array<string, mixed>
     */
    private function run_post_execution_email_action( int $entry_id, array $context, array $result, array $action, int $index, string $type ): array
    {
        if ( ! function_exists( 'wp_mail' ) )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => __( 'WordPress mail is unavailable.', 'sentient-forms' ),
            ];
        }

        $raw_recipients = $action['to'] ?? $action['recipients'] ?? '';
        if ( is_string( $raw_recipients ) )
        {
            $raw_recipients = array_filter( array_map( 'trim', explode( ',', $raw_recipients ) ) );
        }
        elseif ( ! is_array( $raw_recipients ) )
        {
            $raw_recipients = [];
        }

        if ( [] === $raw_recipients )
        {
            $raw_recipients[] = get_option( 'admin_email' );
        }

        $recipients = [];
        foreach ( $raw_recipients as $recipient )
        {
            if ( ! is_scalar( $recipient ) )
            {
                continue;
            }

            $email = sanitize_email(
                $this->render_post_execution_template( (string) $recipient, $entry_id, $context, $result )
            );
            if ( is_email( $email ) )
            {
                $recipients[] = $email;
            }
        }

        if ( [] === $recipients )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => __( 'No valid email recipients were configured.', 'sentient-forms' ),
            ];
        }

        $subject_template = isset( $action['subject'] ) && is_scalar( $action['subject'] )
            ? (string) $action['subject']
            : __( 'Sentient Forms completed {{action_label}}', 'sentient-forms' );
        $body_template = isset( $action['body'] ) && is_scalar( $action['body'] )
            ? (string) $action['body']
            : (string) ( $action['message'] ?? "{{llm_output}}\n\n{{justification}}" );

        $sent = wp_mail(
            $recipients,
            $this->render_post_execution_template( $subject_template, $entry_id, $context, $result ),
            $this->render_post_execution_template( $body_template, $entry_id, $context, $result ),
            [ 'Content-Type: text/plain; charset=UTF-8' ],
        );

        return [
            'index'      => $index,
            'type'       => $type,
            'status'     => $sent ? 'success' : 'failed',
            'recipients' => $recipients,
        ];
    }

    /**
     * Run a post-execution WordPress hook effect.
     *
     * @param int    $entry_id Gravity Forms entry ID.
     * @param array  $context  Runtime action context.
     * @param array  $result   CPS/local action result.
     * @param array  $action   Effect configuration.
     * @param int    $index    Effect index.
     * @param string $type     Effect type.
     *
     * @return array<string, mixed>
     */
    private function run_post_execution_hook_action( int $entry_id, array $context, array $result, array $action, int $index, string $type ): array
    {
        $hook_template = isset( $action['hook_name'] ) && is_scalar( $action['hook_name'] )
            ? (string) $action['hook_name']
            : '';
        $hook_name = preg_replace(
            '/[^A-Za-z0-9_.-]/',
            '',
            $this->render_post_execution_template( $hook_template, $entry_id, $context, $result )
        );

        if ( '' === $hook_name )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => __( 'Missing WordPress hook name.', 'sentient-forms' ),
            ];
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The hook name is an admin-configured local result effect and is sanitized before dispatch.
        do_action( $hook_name, $context, $result, $action, $entry_id );

        return [
            'index'     => $index,
            'type'      => $type,
            'status'    => 'success',
            'hook_name' => $hook_name,
        ];
    }

    /**
     * Run a post-execution webhook effect.
     *
     * @param int    $entry_id Gravity Forms entry ID.
     * @param array  $context  Runtime action context.
     * @param array  $result   CPS/local action result.
     * @param array  $action   Effect configuration.
     * @param int    $index    Effect index.
     * @param string $type     Effect type.
     *
     * @return array<string, mixed>
     */
    private function run_post_execution_webhook_action( int $entry_id, array $context, array $result, array $action, int $index, string $type ): array
    {
        if ( ! function_exists( 'wp_remote_request' ) )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => __( 'WordPress HTTP API is unavailable.', 'sentient-forms' ),
            ];
        }

        $url_template = isset( $action['url'] ) && is_scalar( $action['url'] )
            ? (string) $action['url']
            : '';
        $url = esc_url_raw( $this->render_post_execution_template( $url_template, $entry_id, $context, $result ) );
        $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => __( 'Webhook URL must use http or https.', 'sentient-forms' ),
            ];
        }

        $method = isset( $action['method'] ) && is_scalar( $action['method'] )
            ? strtoupper( sanitize_key( (string) $action['method'] ) )
            : 'POST';
        $headers = isset( $action['headers'] ) && is_array( $action['headers'] )
            ? array_map( 'sanitize_text_field', $action['headers'] )
            : [];
        $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';

        $response = wp_remote_request(
            $url,
            [
                'method'  => in_array( $method, [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) ? $method : 'POST',
                'timeout' => 5,
                'headers' => $headers,
                'body'    => wp_json_encode(
                    [
                        'entry_id' => $entry_id,
                        'context'  => $context,
                        'result'   => $result,
                        'action'   => $action,
                    ]
                ),
            ]
        );

        if ( is_wp_error( $response ) )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => $response->get_error_message(),
            ];
        }

        $status_code = function_exists( 'wp_remote_retrieve_response_code' )
            ? (int) wp_remote_retrieve_response_code( $response )
            : 0;

        return [
            'index'       => $index,
            'type'        => $type,
            'status'      => $status_code >= 200 && $status_code < 400 ? 'success' : 'failed',
            'status_code' => $status_code,
        ];
    }

    /**
     * Render supported placeholders for post-execution effect templates.
     *
     * @param string $template Template text.
     * @param int    $entry_id Gravity Forms entry ID.
     * @param array  $context  Runtime action context.
     * @param array  $result   CPS/local action result.
     *
     * @return string
     */
    private function render_post_execution_template( string $template, int $entry_id, array $context, array $result ): string
    {
        $entry = $this->get_entry_record( $entry_id ) ?? [];

        return (string) preg_replace_callback(
            '/{{\s*([A-Za-z0-9_.:-]+)\s*}}/',
            function ( array $matches ) use ( $entry_id, $entry, $context, $result ): string
            {
                $key = strtolower( (string) $matches[1] );

                if ( str_starts_with( $key, 'field:' ) )
                {
                    $field_id = substr( $key, strlen( 'field:' ) );
                    return $this->stringify_post_execution_value( $entry[ $field_id ] ?? '' );
                }

                $payload = $this->extract_execution_result_payload( $result );
                $values  = [
                    'entry_id'      => $entry_id,
                    'form_id'       => $context['form_id'] ?? '',
                    'action_label'  => $this->get_async_action_label( $context ),
                    'classification'=> $this->extract_spam_classification( $result ) ?? $this->extract_nested_post_execution_value( $payload, 'classification' ),
                    'confidence'    => $this->extract_nested_post_execution_value( $payload, 'confidence' ),
                    'justification' => $this->extract_nested_post_execution_value( $payload, 'justification' ),
                    'llm_output'    => $this->extract_nested_post_execution_value( $payload, 'llm_output' ),
                    'result_json'   => wp_json_encode( $result ),
                    'structured_output' => wp_json_encode( $this->extract_nested_post_execution_value( $payload, 'structured_output' ) ),
                ];

                if ( array_key_exists( $key, $values ) )
                {
                    return $this->stringify_post_execution_value( $values[ $key ] );
                }

                $value = $this->extract_nested_post_execution_value( $payload, $key );
                if ( null !== $value )
                {
                    return $this->stringify_post_execution_value( $value );
                }

                $value = $this->extract_nested_post_execution_value( $context, $key );
                return null === $value ? '' : $this->stringify_post_execution_value( $value );
            },
            $template
        );
    }

    /**
     * Extract the most useful result payload for placeholders.
     *
     * @param array $result CPS/local action result.
     *
     * @return array<string, mixed>
     */
    private function extract_execution_result_payload( array $result ): array
    {
        if ( isset( $result['evaluation_payload']['result_data'] ) && is_array( $result['evaluation_payload']['result_data'] ) )
        {
            return $result['evaluation_payload']['result_data'];
        }

        if ( isset( $result['result_data'] ) && is_array( $result['result_data'] ) )
        {
            return $result['result_data'];
        }

        return $result;
    }

    /**
     * Resolve a dot-notated value from an array.
     *
     * @param array  $source Source array.
     * @param string $path   Dot-notated path.
     *
     * @return mixed|null
     */
    private function extract_nested_post_execution_value( array $source, string $path )
    {
        $segments = array_filter( explode( '.', $path ), static fn ( string $segment ): bool => '' !== $segment );
        $value    = $source;

        foreach ( $segments as $segment )
        {
            if ( is_array( $value ) && array_key_exists( $segment, $value ) )
            {
                $value = $value[ $segment ];
                continue;
            }

            return null;
        }

        return $value;
    }

    /**
     * Convert placeholder values into plain strings.
     *
     * @param mixed $value Value to stringify.
     *
     * @return string
     */
    private function stringify_post_execution_value( $value ): string
    {
        if ( null === $value )
        {
            return '';
        }

        if ( is_scalar( $value ) )
        {
            return (string) $value;
        }

        return wp_json_encode( $value ) ?: '';
    }

    /**
     * Persist the post-execution side-effect audit trail on the entry.
     *
     * @param int   $entry_id Gravity Forms entry ID.
     * @param array $results  Effect results.
     */
    private function record_post_execution_action_results( int $entry_id, array $results ): void
    {
        $existing = $this->get_entry_meta( $entry_id, 'post_execution_actions' );
        if ( is_string( $existing ) )
        {
            $decoded  = json_decode( $existing, true );
            $existing = is_array( $decoded ) ? $decoded : [];
        }

        if ( ! is_array( $existing ) )
        {
            $existing = [];
        }

        $existing[] = [
            'ran_at'  => current_time( 'mysql' ),
            'results' => $results,
        ];

        $this->update_entry_meta( $entry_id, 'post_execution_actions', wp_json_encode( $existing ) );
    }

    /**
     * Check CPS result for spam classification and mark entry if applicable.
     * Implements FR-001 (auto spam marking) and FR-002 (note with justification).
     * Now supports confidence threshold and structured indicators display.
     *
     * @param int   $entry_id The GF entry ID.
     * @param array $context  The async job context (includes mark_as_spam, spam_confidence_threshold, spam_indicators_display).
     * @param array $result   The CPS result data.
     */
    private function maybe_mark_entry_as_spam_from_result( int $entry_id, array $context, array $result ): void
    {
        $mark_as_spam = ! empty( $context['mark_as_spam'] );
        $display_mode = $this->normalize_spam_result_display_mode( $context['spam_result_display_mode'] ?? 'all_results' );
        $should_note  = 'none' !== $display_mode;

        // Extract classification and confidence from result
        $classification = $this->extract_spam_classification( $result );
        if ( empty( $classification ) )
        {
            return;
        }
        $confidence     = $this->extract_spam_confidence( $result );
        $threshold      = isset( $context['spam_confidence_threshold'] ) 
            ? (float) $context['spam_confidence_threshold'] 
            : 0.80; // Default 80%

        // Check if classified as spam
        if ( ! in_array( $classification, [ 'spam', 'likely_spam' ], true ) )
        {
            // If ham/legitimate, add a review note only when explicitly requested.
            if ( 'all_results' === $display_mode && ( $classification === 'ham' || $classification === 'legitimate' ) )
            {
                $note = $this->format_spam_detection_note( $result, $context, false );
                $this->add_entry_note_if_missing( $entry_id, 'Sentient Forms AI', $note );
                $this->update_entry_meta( $entry_id, 'sentient_forms_spam_classification', 'ham' );
            }
            return;
        }

        // Check confidence against threshold
        // If confidence is null (legacy response), treat as 1.0 for backward compatibility
        $effective_confidence = $confidence ?? 1.0;
        if ( $effective_confidence < $threshold )
        {
            // Below threshold - add note but don't mark as spam
            if ( $should_note )
            {
                $note = sprintf(
                    /* translators: 1: confidence percent, 2: threshold percent */
                    __( '✅ Sentient Forms AI reviewed this entry (%1$s%% spam confidence - below %2$s%% threshold). Entry remains active for manual review.', 'sentient-forms' ),
                    round( $effective_confidence * 100 ),
                    round( $threshold * 100 ),
                );
                $this->add_entry_note_if_missing( $entry_id, 'Sentient Forms AI', $note );
            }
            $this->update_entry_meta( $entry_id, 'sentient_forms_spam_classification', 'reviewed' );
            return;
        }

        $note_added = false;
        if ( $should_note )
        {
            $note       = $this->format_spam_detection_note( $result, $context, true );
            $note_added = $this->add_entry_note_if_missing( $entry_id, 'Sentient Forms AI', $note );
        }

        // Spam classification above threshold - mark as spam when enabled
        if ( $mark_as_spam && $this->mark_entry_as_spam( $entry_id ) )
        {
            // Store spam classification meta for notification filtering
            $this->update_entry_meta( $entry_id, 'sentient_forms_spam_classification', 'spam' );
            return;
        }

        if ( $should_note && ! $note_added )
        {
            $note = $this->format_spam_detection_note( $result, $context, true );
            $this->add_entry_note_if_missing( $entry_id, 'Sentient Forms AI', $note );
        }
        $this->update_entry_meta( $entry_id, 'sentient_forms_spam_classification', 'spam' );
    }

    /**
     * Extract confidence score from CPS result.
     *
     * @param array $result The CPS result.
     * @return float|null The confidence score (0.0 to 1.0) or null if not present.
     */
    private function extract_spam_confidence( array $result ): ?float
    {
        $payload = $result['evaluation_payload']['result_data'] ?? $result['result_data'] ?? $result;
        if ( isset( $result['result']['structured'] ) && is_array( $result['result']['structured'] ) )
        {
            $payload = $result['result']['structured'];
        }

        if ( isset( $payload['confidence'] ) && is_numeric( $payload['confidence'] ) )
        {
            return (float) $payload['confidence'];
        }

        return null;
    }

    /**
     * Extract spam indicators array from CPS result.
     *
     * @param array $result The CPS result.
     * @return array The indicators array or empty array.
     */
    private function extract_spam_indicators( array $result ): array
    {
        $payload = $result['evaluation_payload']['result_data'] ?? $result['result_data'] ?? $result;
        if ( isset( $result['result']['structured'] ) && is_array( $result['result']['structured'] ) )
        {
            $payload = $result['result']['structured'];
        }

        if ( isset( $payload['indicators'] ) && is_array( $payload['indicators'] ) )
        {
            return $payload['indicators'];
        }

        return [];
    }

    /**
     * Format a spam detection note with structured data.
     *
     * @param array $result   The CPS result.
     * @param array $context  The async job context.
     * @param bool  $is_spam  Whether the entry is being marked as spam.
     * @return string The formatted note.
     */
    private function format_spam_detection_note( array $result, array $context, bool $is_spam ): string
    {
        $confidence    = $this->extract_spam_confidence( $result );
        $justification = $this->extract_spam_justification( $result );
        $indicators    = $this->extract_spam_indicators( $result );
        $display_mode  = $context['spam_indicators_display'] ?? 'simple';

        $confidence_pct = $confidence !== null ? round( $confidence * 100 ) . '%' : 'N/A';

        if ( $is_spam )
        {
            $icon   = '🚫';
            $status = __( 'SPAM', 'sentient-forms' );
        }
        else
        {
            $icon   = '✅';
            $status = __( 'LEGITIMATE', 'sentient-forms' );
        }

        $note = sprintf(
            /* translators: 1: icon, 2: status, 3: confidence */
            __( '%1$s Sentient Forms AI classified this entry as %2$s (%3$s confidence)', 'sentient-forms' ),
            $icon,
            $status,
            $confidence_pct,
        );

        if ( ! empty( $justification ) )
        {
            $note .= "\n\n" . $justification;
        }

        // Add indicators list in detailed mode
        if ( 'detailed' === $display_mode && ! empty( $indicators ) )
        {
            $note .= "\n\n" . __( 'Signals Detected:', 'sentient-forms' );
            foreach ( $indicators as $indicator )
            {
                $type     = $indicator['type'] ?? 'unknown';
                $evidence = $indicator['evidence'] ?? '';
                $weight   = $indicator['weight'] ?? 'medium';

                // Humanize the type (high_pressure_language -> High Pressure Language)
                $type_label = ucwords( str_replace( '_', ' ', $type ) );

                $note .= sprintf(
                    "\n• %s: \"%s\" (%s)",
                    $type_label,
                    esc_html( $evidence ),
                    $weight,
                );
            }
        }

        return $note;
    }

    /**
     * Extract spam classification from CPS result structure.
     *
     * @param array $result The CPS result.
     * @return string|null The classification ('spam', 'ham', etc.) or null.
     */
    private function extract_spam_classification( array $result ): ?string
    {
        // Check evaluation_payload structure (async flow)
        if ( isset( $result['evaluation_payload']['result_data']['classification'] ) )
        {
            return strtolower( (string) $result['evaluation_payload']['result_data']['classification'] );
        }

        // Check direct result_data structure
        if ( isset( $result['result_data']['classification'] ) )
        {
            return strtolower( (string) $result['result_data']['classification'] );
        }

        // Check local-first OpenRouter structured result wrapper.
        if ( isset( $result['result']['structured']['classification'] ) )
        {
            return strtolower( (string) $result['result']['structured']['classification'] );
        }

        // Check top-level classification
        if ( isset( $result['classification'] ) )
        {
            return strtolower( (string) $result['classification'] );
        }

        return null;
    }

    /**
     * Extract justification/reasoning from CPS result.
     * Prefers structured 'justification' field from JSON mode, falls back to llm_output.
     *
     * @param array $result The CPS result.
     * @return string|null The justification text.
     */
    private function extract_spam_justification( array $result ): ?string
    {
        // Check evaluation_payload structure
        $payload = $result['evaluation_payload']['result_data'] ?? $result['result_data'] ?? $result;
        if ( isset( $result['result']['structured'] ) && is_array( $result['result']['structured'] ) )
        {
            $payload = $result['result']['structured'];
        }

        // Prefer structured justification field (JSON mode output)
        if ( ! empty( $payload['justification'] ) )
        {
            return (string) $payload['justification'];
        }

        if ( ! empty( $payload['reasoning'] ) )
        {
            return wp_trim_words( (string) $payload['reasoning'], 50, '...' );
        }

        // Fall back to llm_output (legacy support) with truncation
        if ( ! empty( $payload['llm_output'] ) )
        {
            return wp_trim_words( (string) $payload['llm_output'], 50, '...' );
        }

        return null;
    }

    /**
     * Log action execution to the action log.
     * Implements FR-008: Persist action log entries.
     *
     * @param array         $context  The async job context.
     * @param array         $result   The CPS result (empty for errors).
     * @param string        $status   'pending', 'success', 'blocked', or 'error'.
     * @param WP_Error|null $error    Error object if status is 'error'.
     * @return string|null The execution request id mirrored to the action log.
     */
    private function log_action_execution( array $context, array $result, string $status, ?WP_Error $error = null ): ?string
    {
        if ( ! class_exists( 'Sentient_Forms_Action_Log_Controller' ) )
        {
            return null;
        }

        $classification = $this->extract_spam_classification( $result );
        $meta = $this->extract_action_log_meta( $result );
        $credits_used = 0;

        // Try to extract credits from various result structures
        if ( isset( $meta['credits_debited'] ) )
        {
            $credits_used = absint( $meta['credits_debited'] );
        }
        elseif ( isset( $meta['credits_used'] ) )
        {
            $credits_used = absint( $meta['credits_used'] );
        }

        $status = $this->resolve_action_log_status( $context, $result, $status );
        $log_data = [
            'form_source'              => $context['form_source'] ?? $this->get_id(),
            'form_id'                  => absint( $context['form_id'] ?? 0 ),
            'entry_id'                 => isset( $context['entry_id'] ) ? absint( $context['entry_id'] ) : null,
            'action_code'              => $context['central_action_id'] ?? $context['action_id'] ?? '',
            'action_label'             => $context['action_name_label'] ?? $this->get_async_action_label( $context ),
            'status'                   => $status,
            'result_summary'           => 'pending' === $status
                ? __( 'Queued for background execution.', 'sentient-forms' )
                : $this->format_async_result_excerpt( $result ),
            'classification'           => $classification,
            'credits_used'             => $credits_used,
            'structured_output_valid'  => $this->extract_structured_output_valid( $result ),
            'error_code'               => $error ? $error->get_error_code() : null,
            'error_message'            => $error ? $error->get_error_message() : null,
            'execution_request_id'     => $this->extract_execution_request_id_from_log( $context, $result ),
            'mapping_id'               => $context['mapping_id'] ?? $context['local_mapping_id'] ?? $context['action_id'] ?? null,
            'resolved_model_id'        => isset( $meta['resolved_model_id'] ) && is_scalar( $meta['resolved_model_id'] )
                ? sanitize_text_field( (string) $meta['resolved_model_id'] )
                : null,
            'pricing'                  => isset( $meta['pricing'] ) && is_array( $meta['pricing'] )
                ? $meta['pricing']
                : [],
            'details'                  => [
                'meta'               => $meta,
                'evaluation_payload' => isset( $result['evaluation_payload'] ) && is_array( $result['evaluation_payload'] )
                    ? $result['evaluation_payload']
                    : [],
            ],
        ];

        Sentient_Forms_Action_Log_Controller::log_execution( $log_data );

        return $log_data['execution_request_id'];
    }

    private function resolve_action_log_status( array $context, array $result, string $status ): string
    {
        if ( 'success' !== $status )
        {
            return $status;
        }

        if ( ! $this->is_blocking_execution_context( $context ) )
        {
            return $status;
        }

        $classification = $this->extract_spam_classification( $result );
        if ( ! in_array( $classification, [ 'spam', 'likely_spam' ], true ) )
        {
            return $status;
        }

        $validation = null;
        if ( isset( $result['validation'] ) && is_array( $result['validation'] ) )
        {
            $validation = $result['validation'];
        }
        elseif ( isset( $result['evaluation_payload']['validation'] ) && is_array( $result['evaluation_payload']['validation'] ) )
        {
            $validation = $result['evaluation_payload']['validation'];
        }

        if ( is_array( $validation ) && array_key_exists( 'is_valid', $validation ) && false === $validation['is_valid'] )
        {
            return 'blocked';
        }

        if ( $this->should_suppress_notifications_on_spam_for_context( $context ) || ! empty( $context['mark_as_spam'] ) )
        {
            return 'blocked';
        }

        return $status;
    }

    private function is_blocking_execution_context( array $context ): bool
    {
        $settings = isset( $context['settings'] ) && is_array( $context['settings'] ) ? $context['settings'] : [];

        if ( array_key_exists( 'async', $settings ) )
        {
            return ! rest_sanitize_boolean( $settings['async'] );
        }

        if ( isset( $settings['execution_mode'] ) && is_scalar( $settings['execution_mode'] ) )
        {
            return 'after_submission' !== sanitize_key( (string) $settings['execution_mode'] );
        }

        $hook = isset( $context['hook'] ) && is_scalar( $context['hook'] )
            ? sanitize_key( (string) $context['hook'] )
            : '';

        if ( 'gform_validation' === $hook )
        {
            return true;
        }

        if ( isset( $context['execution_mode'] ) && is_scalar( $context['execution_mode'] ) )
        {
            return 'after_submission' !== sanitize_key( (string) $context['execution_mode'] );
        }

        if ( array_key_exists( 'async', $context ) )
        {
            return ! rest_sanitize_boolean( $context['async'] );
        }

        $indicator = isset( $context['action_type_indicator'] ) && is_scalar( $context['action_type_indicator'] )
            ? sanitize_key( (string) $context['action_type_indicator'] )
            : '';

        if ( 'gform_after_submission' === $hook && 'master' === $indicator )
        {
            return false;
        }

        return true;
    }

    private function should_suppress_notifications_on_spam_for_context( array $context ): bool
    {
        $settings = isset( $context['settings'] ) && is_array( $context['settings'] ) ? $context['settings'] : [];
        if ( array_key_exists( 'suppress_notifications_on_spam', $settings ) )
        {
            return rest_sanitize_boolean( $settings['suppress_notifications_on_spam'] );
        }

        $action_id = isset( $context['central_action_id'] ) && is_scalar( $context['central_action_id'] )
            ? sanitize_key( (string) $context['central_action_id'] )
            : '';

        return $this->is_spam_action_id( $action_id ) && $this->is_blocking_execution_context( $context );
    }

    private function extract_action_log_meta( array $result ): array
    {
        if ( isset( $result['evaluation_payload']['meta'] ) && is_array( $result['evaluation_payload']['meta'] ) )
        {
            return $result['evaluation_payload']['meta'];
        }

        if ( isset( $result['meta'] ) && is_array( $result['meta'] ) )
        {
            return $result['meta'];
        }

        return [];
    }

    private function extract_structured_output_valid( array $result ): bool
    {
        if ( ! empty( $result['result_data']['structured_output_valid'] ) )
        {
            return true;
        }

        if ( ! empty( $result['evaluation_payload']['result_data']['structured_output_valid'] ) )
        {
            return true;
        }

        return false;
    }

    private function extract_execution_request_id_from_log( array $context, array $result ): ?string
    {
        $candidates = [
            $context['execution_request_id'] ?? null,
            $result['execution_request_id'] ?? null,
            $result['meta']['execution_request_id'] ?? null,
            $result['evaluation_payload']['execution_request_id'] ?? null,
            $result['evaluation_payload']['meta']['execution_request_id'] ?? null,
        ];

        foreach ( $candidates as $candidate )
        {
            if ( is_scalar( $candidate ) )
            {
                $value = sanitize_text_field( (string) $candidate );
                if ( '' !== $value )
                {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Defer form-submission notifications until async spam detection finishes.
     *
     * @param bool  $is_disabled  Whether a previous filter already disabled the notification.
     * @param array $notification The Gravity Forms notification config.
     * @param array $form         The form object.
     * @param array $entry        The entry object.
     * @param array $data         Notification data payload.
     *
     * @return bool True to suppress the notification for now, false to allow it.
     */
    public function maybe_defer_async_spam_notification( bool $is_disabled, array $notification, array $form, array $entry, array $data = [] ): bool
    {
        if ( $is_disabled )
        {
            return true;
        }

        if ( $this->is_entry_spam( $entry ) )
        {
            return true;
        }

        $notification_id = isset( $notification['id'] ) && is_scalar( $notification['id'] )
            ? (string) $notification['id']
            : '';

        if ( ! empty( $data[ self::DEFERRED_NOTIFICATION_REPLAY_FLAG ] ) )
        {
            $allowed_notification_ids = $this->normalize_deferred_notification_ids( $data[ self::DEFERRED_NOTIFICATION_ALLOWED_IDS ] ?? [] );

            if ( empty( $allowed_notification_ids ) )
            {
                return false;
            }

            return ! in_array( $notification_id, $allowed_notification_ids, true );
        }

        $event = isset( $notification['event'] ) && is_scalar( $notification['event'] )
            ? sanitize_key( (string) $notification['event'] )
            : 'form_submission';

        if ( 'form_submission' !== $event || '' === $notification_id )
        {
            return false;
        }

        $deferred_mapping_ids = $this->get_deferred_notification_mapping_ids_for_async_spam_submission( $form, $entry );
        if ( empty( $deferred_mapping_ids ) )
        {
            return false;
        }

        $entry_id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
        if ( $entry_id <= 0 )
        {
            return false;
        }

        $this->store_deferred_notification_id( $entry_id, $notification_id );
        $this->update_entry_meta(
            $entry_id,
            self::DEFERRED_NOTIFICATION_MAPPING_IDS_META_KEY,
            $deferred_mapping_ids,
        );
        $this->update_entry_meta(
            $entry_id,
            self::DEFERRED_NOTIFICATION_DECISION_META_KEY,
            self::DEFERRED_NOTIFICATION_DECISION_PENDING,
        );

        return true;
    }

    /**
     * Suppress Gravity Forms notifications for spam entries.
     * Implements FR-003 (hook), FR-004 (suppress spam), FR-005 (pass ham).
     *
     * This filter runs before each notification is sent. If the entry is
     * marked as spam (status = 'spam') or has spam classification meta, the
     * notification is suppressed by returning false.
     *
     * @param array $notification The notification configuration.
     * @param array $form         The form data.
     * @param array $entry        The entry data.
     *
     * @return array|false The notification array to send, or false to suppress.
     */
    public function maybe_suppress_spam_notification( array $notification, array $form, array $entry )
    {
        if ( $this->should_suppress_notifications_for_entry( $entry ) )
        {
            sentient_forms_debug_log(
                'Sentient Forms suppressed notification for spam entry.',
                [
                    'notification_name' => $notification['name'] ?? 'unknown',
                    'entry_id'          => $entry['id'] ?? 0,
                    'form_id'           => $form['id'] ?? 0,
                ]
            );

            // Return false to suppress this notification entirely
            return false;
        }

        // FR-005: Pass through notification unchanged for ham entries
        return $notification;
    }

    /**
     * Determine whether notifications should be suppressed for this entry.
     *
     * @param array $entry The entry data.
     *
     * @return bool
     */
    private function should_suppress_notifications_for_entry( array $entry ): bool
    {
        $entry_id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
        if ( $entry_id > 0 )
        {
            $preference = $this->get_entry_spam_notification_preference( $entry_id );
            if ( self::SPAM_NOTIFICATION_PREFERENCE_SUPPRESS === $preference )
            {
                return true;
            }

            if ( self::SPAM_NOTIFICATION_PREFERENCE_ALLOW === $preference )
            {
                return false;
            }
        }

        return isset( $entry['status'] ) && 'spam' === $entry['status'];
    }

    /**
     * Retrieve the persisted spam-notification preference for an entry, when set by Sentient Forms.
     *
     * @param int $entry_id Entry id.
     *
     * @return string|null
     */
    private function get_entry_spam_notification_preference( int $entry_id ): ?string
    {
        if ( $entry_id <= 0 )
        {
            return null;
        }

        $preference = $this->get_entry_meta( $entry_id, self::SPAM_NOTIFICATION_PREFERENCE_META_KEY );
        if ( ! is_scalar( $preference ) )
        {
            return null;
        }

        $normalized = sanitize_key( (string) $preference );

        return in_array(
            $normalized,
            [ self::SPAM_NOTIFICATION_PREFERENCE_SUPPRESS, self::SPAM_NOTIFICATION_PREFERENCE_ALLOW ],
            true
        ) ? $normalized : null;
    }

    /**
     * Check if a Gravity Forms entry is marked as spam.
     *
     * @param array $entry The entry data.
     * @return bool True if entry is spam, false otherwise.
     */
    private function is_entry_spam( array $entry ): bool
    {
        // Check native GF status property (set by mark_entry_as_spam)
        if ( isset( $entry['status'] ) && $entry['status'] === 'spam' )
        {
            return true;
        }

        // Also check our spam classification meta as backup
        $entry_id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
        if ( $entry_id > 0 )
        {
            $classification = $this->get_entry_meta( $entry_id, 'sentient_forms_spam_classification' );
            if ( $classification === 'spam' )
            {
                return true;
            }
        }

        return false;
    }

    public function finalize_async_error( array $context, WP_Error $error ): void
    {
        $this->record_local_action_failure( $context, [], $error );

        // FR-008: Log failed action execution
        $this->log_action_execution( $context, [], 'error', $error );

        $this->resolve_deferred_notifications_after_async_completion( $context, false );
    }

    public function finalize_async_evaluation( array $context, array $result ): void
    {
        $this->finalize_async_success( $context, $result );
    }

    /**
     * Persist entry-facing failure state for a local action execution.
     *
     * @param array<string, mixed> $context         Runtime context.
     * @param array<string, mixed> $action_settings Mapping settings.
     */
    private function record_local_action_failure( array $context, array $action_settings, WP_Error $error ): void
    {
        $entry_id = 0;
        foreach ( [ $context['entry_id'] ?? null, $context['id'] ?? null ] as $candidate )
        {
            if ( is_scalar( $candidate ) )
            {
                $entry_id = absint( $candidate );
                if ( $entry_id > 0 )
                {
                    break;
                }
            }
        }

        $message  = $this->format_local_action_failure_message( $context, $action_settings, $error );

        if ( $entry_id <= 0 )
        {
            sentient_forms_debug_log(
                'Sentient Forms local action failed without an entry context.',
                [
                    'message' => $message,
                ]
            );
            return;
        }

        $this->record_local_action_failure_meta( $entry_id, $error->get_error_message() );
        $this->add_local_action_entry_note_if_missing( $entry_id, 'Sentient Forms AI', $message );
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $action_settings
     */
    private function format_local_action_failure_message( array $context, array $action_settings, WP_Error $error ): string
    {
        return sprintf(
            /* translators: 1: action label, 2: error reason */
            __( 'Sentient Forms could not complete %1$s. Reason: %2$s', 'sentient-forms' ),
            $this->resolve_local_action_label( $context, $action_settings ),
            $error->get_error_message(),
        );
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $action_settings
     */
    private function resolve_local_action_label( array $context, array $action_settings ): string
    {
        foreach ( [ $context['action_name_label'] ?? null, $action_settings['action_name_label'] ?? null ] as $candidate )
        {
            if ( is_scalar( $candidate ) && '' !== trim( (string) $candidate ) )
            {
                return sanitize_text_field( (string) $candidate );
            }
        }

        return $this->get_async_action_label( $context );
    }

    private function record_local_action_failure_meta( int $entry_id, string $message ): void
    {
        if ( $entry_id <= 0 )
        {
            return;
        }

        $this->persist_entry_runtime_meta( $entry_id, 'sentient_forms_last_error', sanitize_textarea_field( $message ) );
        $this->persist_entry_runtime_meta( $entry_id, 'sentient_forms_last_processed_at', current_time( 'mysql' ) );
    }

    private function persist_entry_runtime_meta( int $entry_id, string $meta_key, mixed $meta_value ): bool
    {
        if ( $entry_id <= 0 )
        {
            return false;
        }

        if ( ! str_starts_with( $meta_key, 'sentient_forms_' ) )
        {
            $meta_key = 'sentient_forms_' . $meta_key;
        }

        if ( function_exists( 'gform_update_meta' ) )
        {
            return gform_update_meta( $entry_id, $meta_key, $meta_value ) !== false;
        }

        return $this->update_entry_meta( $entry_id, $meta_key, $meta_value );
    }

    private function add_local_action_entry_note( mixed $entry_id, string $note_author, string $note_content ): bool
    {
        if ( ! $this->is_active() )
        {
            return false;
        }

        if ( ! class_exists( 'GFFormsModel' ) || ! is_callable( [ 'GFFormsModel', 'add_note' ] ) )
        {
            return $this->add_entry_note( $entry_id, $note_author, $note_content );
        }

        try
        {
            $result = GFFormsModel::add_note(
                $entry_id,
                0,
                $note_author,
                $note_content,
                'sentient_forms_local_action',
            );

            if ( false !== $result )
            {
                $this->append_entry_note_fallback_meta( $entry_id, $note_author, $note_content );
            }

            return $result !== false;
        } catch ( Exception $e )
        {
            sentient_forms_debug_log(
                'Sentient Forms could not add local action entry note.',
                [
                    'entry_id' => $entry_id,
                    'error'    => $e->getMessage(),
                ]
            );
            return false;
        }
    }

    private function add_local_action_entry_note_if_missing( mixed $entry_id, string $note_author, string $note_content ): bool
    {
        if ( $this->entry_note_exists( $entry_id, $note_author, $note_content ) )
        {
            return true;
        }

        return $this->add_local_action_entry_note( $entry_id, $note_author, $note_content );
    }

    private function get_async_action_label( array $context ): string
    {
        if ( !empty( $context['action_name_label'] ) )
        {
            return sanitize_text_field( (string) $context['action_name_label'] );
        }

        if ( !empty( $context['central_action_id'] ) )
        {
            return sanitize_text_field( (string) $context['central_action_id'] );
        }

        if ( !empty( $context['action_id'] ) )
        {
            return sanitize_text_field( (string) $context['action_id'] );
        }

        return __( 'Sentient Forms action', 'sentient-forms' );
    }

    private function is_spam_detection_async_result( array $context, array $result ): bool
    {
        $candidates = [
            $context['central_action_id'] ?? null,
            $context['action_template_code'] ?? null,
            $result['central_action_id'] ?? null,
            $result['action_template_code'] ?? null,
            $result['meta']['action_template_code'] ?? null,
            $result['evaluation_payload']['central_action_id'] ?? null,
            $result['evaluation_payload']['meta']['action_template_code'] ?? null,
        ];

        foreach ( $candidates as $candidate )
        {
            if ( 'spam_detection_v1' === $candidate )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a mapping should hold form-submission notifications.
     *
     * @param array<string, mixed> $mapping Mapping payload.
     * @param bool                 $should_async Whether the mapping executes asynchronously.
     *
     * @return bool
     */
    private function should_defer_notifications_for_mapping( array $mapping, bool $should_async ): bool
    {
        if ( ! $should_async )
        {
            return false;
        }

        if ( $this->local_spam_effect_enabled( $mapping ) )
        {
            return $this->should_suppress_notifications_on_spam( $mapping );
        }

        return ( $mapping['central_action_id'] ?? '' ) === 'spam_detection_v1'
            && ! empty( $mapping['mark_as_spam'] );
    }

    /**
     * Determine whether this submission should defer notifications for async spam detection.
     *
     * @param array $form  The form object.
     * @param array $entry The entry object.
     *
     * @return bool
     */
    private function should_defer_notifications_for_async_spam_submission( array $form, array $entry ): bool
    {
        return ! empty( $this->get_deferred_notification_mapping_ids_for_async_spam_submission( $form, $entry ) );
    }

    /**
     * Resolve async spam mapping IDs that should hold form-submission notifications.
     *
     * @param array $form  The form object.
     * @param array $entry The entry object.
     *
     * @return array<int, string>
     */
    private function get_deferred_notification_mapping_ids_for_async_spam_submission( array $form, array $entry ): array
    {
        $form_id  = isset( $form['id'] ) ? absint( $form['id'] ) : 0;
        $entry_id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
        $cache_key = $form_id . ':' . $entry_id;

        if ( array_key_exists( $cache_key, $this->async_spam_notification_gate_cache ) )
        {
            return $this->async_spam_notification_gate_cache[ $cache_key ];
        }

        $settings = $this->get_form_settings( $form_id );
        $disable_flags = $this->get_execution_disable_flags( $settings );
        if ( ! empty( $disable_flags['effective_disabled'] ) )
        {
            $this->async_spam_notification_gate_cache[ $cache_key ] = [];
            return [];
        }

        $planner = $this->plugin->get_mapping_dependency_planner();
        $plan    = $planner->build_execution_plan( $settings, 'gform_after_submission' );
        $mapping_ids = [];

        foreach ( $plan['order'] as $mapping_id )
        {
            $node = $plan['nodes'][ $mapping_id ] ?? null;
            if ( ! is_array( $node ) || ! isset( $node['mapping'] ) || ! is_array( $node['mapping'] ) )
            {
                continue;
            }

            if ( empty( $node['enabled'] ) || empty( $node['hook_enabled'] ) )
            {
                continue;
            }

            $mapping = $node['mapping'];
            $mapping['local_mapping_id'] = $mapping['local_mapping_id'] ?? $mapping_id;

            if ( ! $this->should_defer_notifications_for_mapping( $mapping, $this->is_mapping_async( $mapping ) ) )
            {
                continue;
            }

            if ( ! $this->plugin->get_condition_evaluator()->should_execute( $mapping, $entry ) )
            {
                continue;
            }

            $mapping_ids[] = (string) $mapping_id;
        }

        $mapping_ids = $this->normalize_deferred_notification_ids( $mapping_ids );
        $this->async_spam_notification_gate_cache[ $cache_key ] = $mapping_ids;

        return $mapping_ids;
    }

    /**
     * Persist a deferred notification ID for later replay.
     *
     * @param int    $entry_id         The entry ID.
     * @param string $notification_id  The Gravity Forms notification ID.
     *
     * @return void
     */
    private function store_deferred_notification_id( int $entry_id, string $notification_id ): void
    {
        $notification_ids = $this->get_deferred_notification_ids( $entry_id );
        $notification_ids[] = $notification_id;
        $notification_ids   = $this->normalize_deferred_notification_ids( $notification_ids );

        $this->update_entry_meta( $entry_id, self::DEFERRED_NOTIFICATION_IDS_META_KEY, $notification_ids );
        $this->update_entry_meta( $entry_id, self::DEFERRED_NOTIFICATION_DECISION_META_KEY, self::DEFERRED_NOTIFICATION_DECISION_PENDING );
    }

    /**
     * Reconcile deferred notification state after after-submission mappings are queued.
     *
     * @param array $entry               The entry object.
     * @param array $form                The form object.
     * @param array $queued_mapping_ids  Mapping IDs that were actually queued.
     *
     * @return void
     */
    private function reconcile_deferred_notifications_after_submission( array $entry, array $form, array $queued_mapping_ids ): void
    {
        $entry_id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
        if ( $entry_id <= 0 )
        {
            return;
        }

        $notification_ids = $this->get_deferred_notification_ids( $entry_id );
        if ( empty( $notification_ids ) )
        {
            return;
        }

        $queued_mapping_ids = $this->normalize_deferred_notification_ids( $queued_mapping_ids );

        if ( empty( $queued_mapping_ids ) )
        {
            $this->replay_deferred_notifications( $entry_id, absint( $form['id'] ?? 0 ) );
            return;
        }

        $this->update_entry_meta( $entry_id, self::DEFERRED_NOTIFICATION_MAPPING_IDS_META_KEY, $queued_mapping_ids );
        $this->update_entry_meta( $entry_id, self::DEFERRED_NOTIFICATION_DECISION_META_KEY, self::DEFERRED_NOTIFICATION_DECISION_PENDING );
    }

    /**
     * Resolve deferred notification state after a spam-classification async job completes.
     *
     * @param array $context          Async job context.
     * @param bool  $should_suppress  Whether notifications should stay suppressed.
     *
     * @return void
     */
    private function resolve_deferred_notifications_after_async_completion( array $context, bool $should_suppress ): void
    {
        $entry_id = isset( $context['entry_id'] ) ? absint( $context['entry_id'] ) : 0;
        if ( $entry_id <= 0 )
        {
            return;
        }

        $pending_mapping_ids = $this->get_deferred_notification_mapping_ids( $entry_id );
        if ( empty( $pending_mapping_ids ) )
        {
            return;
        }

        $current_mapping_id = $this->resolve_deferred_notification_mapping_id( $context );
        if ( '' === $current_mapping_id )
        {
            return;
        }

        if ( $should_suppress )
        {
            $this->update_entry_meta(
                $entry_id,
                self::DEFERRED_NOTIFICATION_DECISION_META_KEY,
                self::DEFERRED_NOTIFICATION_DECISION_SUPPRESS,
            );
        }

        $remaining_mapping_ids = array_values(
            array_diff(
                $pending_mapping_ids,
                [ $current_mapping_id ],
            )
        );

        if ( ! empty( $remaining_mapping_ids ) )
        {
            $this->update_entry_meta( $entry_id, self::DEFERRED_NOTIFICATION_MAPPING_IDS_META_KEY, $remaining_mapping_ids );
            return;
        }

        $decision = $this->get_deferred_notification_decision( $entry_id );
        if ( self::DEFERRED_NOTIFICATION_DECISION_SUPPRESS === $decision )
        {
            $this->clear_deferred_notification_state( $entry_id );
            return;
        }

        $this->replay_deferred_notifications( $entry_id, absint( $context['form_id'] ?? 0 ) );
    }

    /**
     * Decide whether the async spam result should permanently suppress deferred notifications.
     *
     * @param array $context Async job context.
     * @param array $result  Async result payload.
     *
     * @return bool
     */
    private function should_suppress_deferred_notifications_from_result( array $context, array $result ): bool
    {
        if ( empty( $context['mark_as_spam'] ) && ! $this->local_spam_effect_enabled( $context ) )
        {
            return false;
        }

        $classification = $this->extract_spam_classification( $result );
        if ( ! in_array( $classification, [ 'spam', 'likely_spam' ], true ) )
        {
            return false;
        }

        $confidence = $this->extract_spam_confidence( $result );
        $threshold  = isset( $context['spam_confidence_threshold'] )
            ? (float) $context['spam_confidence_threshold']
            : 0.80;

        return ( $confidence ?? 1.0 ) >= $threshold;
    }

    /**
     * Replay deferred notifications for a non-spam or errored async submission.
     *
     * @param int $entry_id The entry ID.
     * @param int $form_id  The form ID.
     *
     * @return void
     */
    private function replay_deferred_notifications( int $entry_id, int $form_id ): void
    {
        $notification_ids = $this->get_deferred_notification_ids( $entry_id );
        if ( empty( $notification_ids ) )
        {
            $this->clear_deferred_notification_state( $entry_id );
            return;
        }

        $form = $this->get_form_object( $form_id );
        $entry = $this->get_entry_record( $entry_id );

        if ( ! is_array( $form ) || ! is_array( $entry ) )
        {
            sentient_forms_debug_log(
                'Sentient Forms could not replay deferred notifications.',
                [
                    'entry_id' => $entry_id,
                    'form_id'  => $form_id,
                ]
            );
            return;
        }

        $this->dispatch_entry_notifications( $form, $entry, $notification_ids );
        $this->clear_deferred_notification_state( $entry_id );
    }

    /**
     * Dispatch deferred notifications through Gravity Forms using replay-only IDs.
     *
     * @param array<int|string, mixed> $form              The form object.
     * @param array<int|string, mixed> $entry             The entry object.
     * @param array<int, string>       $notification_ids  The notification IDs to replay.
     *
     * @return array<int, mixed>
     */
    protected function dispatch_entry_notifications( array $form, array $entry, array $notification_ids ): array
    {
        if ( ! class_exists( 'GFAPI' ) || ! is_callable( [ 'GFAPI', 'send_notifications' ] ) )
        {
            return [];
        }

        $notification_ids = $this->normalize_deferred_notification_ids( $notification_ids );
        if ( empty( $notification_ids ) )
        {
            return [];
        }

        $result = GFAPI::send_notifications(
            $form,
            $entry,
            'form_submission',
            [
                self::DEFERRED_NOTIFICATION_REPLAY_FLAG => true,
                self::DEFERRED_NOTIFICATION_ALLOWED_IDS => $notification_ids,
            ],
        );

        return is_array( $result ) ? $result : [];
    }

    /**
     * Load a Gravity Forms entry for deferred notification replay.
     *
     * @param int $entry_id The entry ID.
     *
     * @return array|null
     */
    protected function get_entry_record( int $entry_id ): ?array
    {
        if ( ! class_exists( 'GFAPI' ) )
        {
            return null;
        }

        $entry = GFAPI::get_entry( $entry_id );

        return is_wp_error( $entry ) || ! is_array( $entry )
            ? null
            : $entry;
    }

    /**
     * Resolve the mapping ID used to track deferred notification state.
     *
     * @param array $context Async job context.
     *
     * @return string
     */
    private function resolve_deferred_notification_mapping_id( array $context ): string
    {
        foreach ( [ 'mapping_id', 'local_mapping_id', 'action_id' ] as $candidate_key )
        {
            if ( isset( $context[ $candidate_key ] ) && is_scalar( $context[ $candidate_key ] ) )
            {
                $value = (string) $context[ $candidate_key ];
                if ( '' !== $value )
                {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * Retrieve deferred notification IDs for an entry.
     *
     * @param int $entry_id The entry ID.
     *
     * @return array<int, string>
     */
    private function get_deferred_notification_ids( int $entry_id ): array
    {
        return $this->normalize_deferred_notification_ids(
            $this->get_entry_meta( $entry_id, self::DEFERRED_NOTIFICATION_IDS_META_KEY ) ?? [],
        );
    }

    /**
     * Retrieve pending deferred notification mapping IDs for an entry.
     *
     * @param int $entry_id The entry ID.
     *
     * @return array<int, string>
     */
    private function get_deferred_notification_mapping_ids( int $entry_id ): array
    {
        return $this->normalize_deferred_notification_ids(
            $this->get_entry_meta( $entry_id, self::DEFERRED_NOTIFICATION_MAPPING_IDS_META_KEY ) ?? [],
        );
    }

    /**
     * Retrieve the current deferred notification decision state.
     *
     * @param int $entry_id The entry ID.
     *
     * @return string
     */
    private function get_deferred_notification_decision( int $entry_id ): string
    {
        $decision = $this->get_entry_meta( $entry_id, self::DEFERRED_NOTIFICATION_DECISION_META_KEY );

        return is_scalar( $decision ) && '' !== (string) $decision
            ? (string) $decision
            : self::DEFERRED_NOTIFICATION_DECISION_PENDING;
    }

    /**
     * Clear all deferred notification state for an entry.
     *
     * @param int $entry_id The entry ID.
     *
     * @return void
     */
    private function clear_deferred_notification_state( int $entry_id ): void
    {
        $this->update_entry_meta( $entry_id, self::DEFERRED_NOTIFICATION_IDS_META_KEY, [] );
        $this->update_entry_meta( $entry_id, self::DEFERRED_NOTIFICATION_MAPPING_IDS_META_KEY, [] );
        $this->update_entry_meta( $entry_id, self::DEFERRED_NOTIFICATION_DECISION_META_KEY, self::DEFERRED_NOTIFICATION_DECISION_PENDING );
    }

    /**
     * Normalize a stored notification/mapping ID list to unique strings.
     *
     * @param mixed $values Raw value.
     *
     * @return array<int, string>
     */
    private function normalize_deferred_notification_ids( mixed $values ): array
    {
        if ( is_scalar( $values ) )
        {
            $values = [ (string) $values ];
        }

        if ( ! is_array( $values ) )
        {
            return [];
        }

        $normalized = [];
        foreach ( $values as $value )
        {
            if ( ! is_scalar( $value ) )
            {
                continue;
            }

            $string_value = trim( (string) $value );
            if ( '' === $string_value )
            {
                continue;
            }

            $normalized[] = $string_value;
        }

        return array_values( array_unique( $normalized ) );
    }

    private function format_async_result_excerpt( array $result ): string
    {
        // CA-EXEC-001: Prefer structured_output for richer excerpts.
        if ( ! empty( $result['result_data']['structured_output_valid'] )
             && isset( $result['result_data']['structured_output'] )
             && is_array( $result['result_data']['structured_output'] ) )
        {
            return wp_trim_words( wp_json_encode( $result['result_data']['structured_output'] ), 40 );
        }

        if ( isset( $result['result_data']['llm_output'] ) && is_scalar( $result['result_data']['llm_output'] ) )
        {
            return wp_trim_words( wp_kses_post( (string) $result['result_data']['llm_output'] ), 40 );
        }

        if ( isset( $result['result_data'] ) )
        {
            return wp_trim_words( wp_json_encode( $result['result_data'] ), 40 );
        }

        return wp_trim_words( wp_json_encode( $result ), 40 );
    }

    public function filter_async_evaluation_jobs( array $jobs, array $job, array $result ): array
    {
        $context = $job['context'] ?? [];
        if ( ( $context['form_source'] ?? '' ) !== $this->get_id() )
        {
            return $jobs;
        }

        $payload = $result['evaluation_payload'] ?? null;
        if ( ! is_array( $payload ) || empty( $payload ) )
        {
            return $jobs;
        }

        $entry_id = $context['entry_id'] ?? null;
        if ( empty( $entry_id ) )
        {
            return $jobs;
        }

        if ( $this->is_spam_detection_async_result( $context, $result ) )
        {
            return $jobs;
        }

        $jobs[] = [
            'adapter_id' => $this->get_id(),
            'entry_id'   => $entry_id,
            'form_id'    => $context['form_id'] ?? null,
            'action_id'  => $context['action_id'] ?? null,
            'payload'    => $payload,
            'context'    => [
                'action_name_label' => $context['action_name_label'] ?? '',
            ],
        ];

        /**
         * Fires when the Gravity Forms adapter inspects an evaluation payload.
         * Used for temporary logging/diagnostics in staging.
         */
        do_action( 'sentient_forms_debug_evaluation_payload', $payload, $job, $result );

        return $jobs;
    }
}
