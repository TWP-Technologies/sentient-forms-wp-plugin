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
class Sentient_Forms_Gravity_Forms_Adapter implements Sentient_Forms_Adapter_Interface, Sentient_Forms_Async_Capable_Adapter_Interface, Sentient_Forms_Historical_Entries_Adapter_Interface, Sentient_Forms_Accepted_Submission_Adapter_Interface, Sentient_Forms_Validation_Adapter_Interface, Sentient_Forms_Native_Validation_Effects_Adapter_Interface, Sentient_Forms_Native_Entry_Capabilities_Adapter_Interface, Sentient_Forms_Native_Effects_Capabilities_Adapter_Interface, Sentient_Forms_Realtime_Adapter_Interface
{
    private const NATIVE_AFTER_SUBMISSION_HOOK = 'gform_after_submission';
    private const REALTIME_ACTION_ID = 'clarification_assistant_v1';
    private const REALTIME_DEFAULT_DEBOUNCE_MS = 900;
    private const REALTIME_DEFAULT_COOLDOWN_MS = 8000;
    private const REALTIME_MIN_DEBOUNCE_MS = 250;
    private const REALTIME_MAX_DEBOUNCE_MS = 5000;
    private const REALTIME_MIN_COOLDOWN_MS = 0;
    private const REALTIME_MAX_COOLDOWN_MS = 60000;
    private const REALTIME_DEFAULT_PAGE_CHECKPOINT_TIMEOUT_MS = 2500;
    private const REALTIME_DEFAULT_PRE_SUBMIT_TIMEOUT_MS = 2500;
    private const REALTIME_MIN_PRE_SUBMIT_TIMEOUT_MS = 500;
    private const REALTIME_MAX_PRE_SUBMIT_TIMEOUT_MS = 10000;
    private const REALTIME_PAGE_CHECKPOINT_MODES = [
        'all_pages',
        'include_pages',
        'exclude_pages',
    ];
    private const REALTIME_HIDDEN_FIELD_EXPOSURE_MODES = [
        'omit_hidden',
        'label_hidden',
        'label_hidden_value',
        'label_value',
    ];
    private const FORM_ACTION_CONFIG_OPTION_PREFIX = 'sentient_forms_form_config_';
    private const ACTION_DEFAULTS_OPTION_PREFIX = 'sentient_forms_action_defaults_';
    private const SPAM_NOTIFICATION_PREFERENCE_META_KEY = 'spam_notification_preference';
    private const SPAM_NOTIFICATION_PREFERENCE_SUPPRESS = 'suppress';
    private const SPAM_NOTIFICATION_PREFERENCE_ALLOW = 'allow';
    private const SPAM_WEBHOOK_PREFERENCE_META_KEY = 'spam_webhook_preference';
    private const DEFERRED_NOTIFICATION_IDS_META_KEY = 'deferred_notification_ids';
    private const DEFERRED_NOTIFICATION_MAPPING_IDS_META_KEY = 'deferred_notification_mapping_ids';
    private const DEFERRED_NOTIFICATION_DECISION_META_KEY = 'deferred_notification_decision';
    private const DEFERRED_WEBHOOK_FEED_IDS_META_KEY = 'deferred_webhook_feed_ids';
    private const DEFERRED_WEBHOOK_MAPPING_IDS_META_KEY = 'deferred_webhook_mapping_ids';
    private const DEFERRED_WEBHOOK_DECISION_META_KEY = 'deferred_webhook_decision';
    private const DEFERRED_NOTIFICATION_DECISION_PENDING = 'pending';
    private const DEFERRED_NOTIFICATION_DECISION_SUPPRESS = 'suppress';
    private const DEFERRED_NOTIFICATION_REPLAY_FLAG = 'sentient_forms_async_spam_notification_replay';
    private const DEFERRED_NOTIFICATION_ALLOWED_IDS = 'sentient_forms_allowed_notification_ids';
    private const GRAVITY_FORMS_WEBHOOKS_ADDON_SLUG = 'gravityformswebhooks';
    private const REALTIME_QNA_STORAGE_FIELD_LABEL = 'Sentient Forms Realtime Q&A';
    private const REALTIME_QNA_STORAGE_FIELD_INPUT_NAME = 'sentient_forms_realtime_qna';
    private const REALTIME_QNA_STORAGE_FIELD_CLASS = 'sentient-forms-realtime-qna-storage';

    /**
     * Plugin instance
     */
    private Sentient_Forms_Plugin $plugin;

    private ?Sentient_Forms_Form_Source_Workflow_Runner $workflow_runner;

    private ?Sentient_Forms_Action_Runtime_Settings_Resolver $runtime_settings_resolver = null;

    /**
     * Cache async spam notification mapping checks per form/entry.
     *
     * @var array<string, array<int, string>>
     */
    private array $async_spam_notification_gate_cache = [];

    /**
     * Cache async spam Webhooks feed checks per form/entry.
     *
     * @var array<string, array<int, string>>
     */
    private array $async_spam_webhook_gate_cache = [];

    /**
     * Replay-only feed IDs while held Gravity Forms Webhooks are reprocessed.
     *
     * @var array<int, string>|null
     */
    private ?array $webhook_replay_allowed_feed_ids = null;

    /**
     * Validation-hook audit request ids that can be linked once Gravity Forms saves the entry.
     *
     * @var array<int, array<int, string>>
     */
    private array $validation_execution_request_ids_by_form = [];

    /** @var array<int, Sentient_Forms_Validation_Run_Result> */
    private array $validation_run_results_by_form = [];

    /**
     * Shared-runner outcomes created at Gravity's pre-delivery saved-entry seam.
     *
     * @var array<string, Sentient_Forms_Accepted_Submission_Run_Result>
     */
    private array $accepted_submission_outcomes = [];

    /**
     * Constructor
     *
     * @param Sentient_Forms_Plugin $plugin Plugin instance.
     */
    public function __construct(
        Sentient_Forms_Plugin $plugin,
        ?Sentient_Forms_Form_Source_Workflow_Runner $workflow_runner = null
    )
    {
        $this->plugin          = $plugin;
        $this->workflow_runner = $workflow_runner;
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

    public function get_accepted_submission_native_hook(): string
    {
        return self::NATIVE_AFTER_SUBMISSION_HOOK;
    }

    public function get_validation_native_hook(): string
    {
        return 'gform_validation';
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function normalize_validation( mixed $native_validation, mixed $native_context = null ): array | WP_Error
    {
        if ( ! is_array( $native_validation ) || ! isset( $native_validation['form'] ) || ! is_array( $native_validation['form'] ) )
        {
            return new WP_Error( 'sentient_forms_gravity_forms_invalid_validation', __( 'Gravity Forms validation payload is invalid.', 'sentient-forms' ) );
        }

        $form    = $native_validation['form'];
        $form_id = absint( $form['id'] ?? 0 );
        if ( $form_id <= 0 )
        {
            return new WP_Error( 'sentient_forms_gravity_forms_invalid_validation', __( 'Gravity Forms validation payload is missing its form identity.', 'sentient-forms' ) );
        }

        return [
            'form_id'        => (string) $form_id,
            'form'           => $form,
            'entry'          => $this->prepare_entry_from_submission(),
            'native_context' => $native_context,
        ];
    }

    public function apply_validation_result(
        mixed $native_validation,
        Sentient_Forms_Validation_Run_Result $result
    ): mixed
    {
        if ( ! is_array( $native_validation ) )
        {
            return $native_validation;
        }

        $form_error = $result->get_form_error();
        if ( null !== $form_error && '' !== $form_error )
        {
            $native_validation = $this->inject_validation_message( $native_validation, $form_error, [] );
        }
        if ( [] !== $result->get_field_errors() )
        {
            $native_validation = $this->inject_field_validation_messages( $native_validation, $result->get_field_errors() );
        }

        return $native_validation;
    }

    public function apply_validation_entry_effects(
        array $entry,
        array $form,
        Sentient_Forms_Validation_Run_Result $result
    ): void
    {
        $entry_id = absint( $entry['id'] ?? 0 );
        if ( $entry_id <= 0 )
        {
            return;
        }

        foreach ( $result->get_spam_classifications() as $mapping_id => $classification )
        {
            if ( ! in_array( $classification, [ 'spam', 'likely_spam' ], true ) )
            {
                continue;
            }

            $mappings = $result->get_resolved_mappings();
            $mapping  = $mappings[ $mapping_id ] ?? null;
            $spam_payload = $result->get_spam_payload( $mapping_id );
            if (
                ! is_array( $mapping )
                || ! is_array( $spam_payload )
                || 'local_first' === sanitize_key( (string) ( $mapping['action_type_indicator'] ?? '' ) )
            )
            {
                continue;
            }

            $canonical_result = [ 'result_data' => $spam_payload ];

            $settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] )
                ? $mapping['settings']
                : [];
            $context  = $mapping;
            if ( ! array_key_exists( 'mark_as_spam', $context ) )
            {
                $context['mark_as_spam'] = ! empty( $settings['mark_as_spam'] );
            }
            foreach ( [ 'spam_confidence_threshold', 'spam_indicators_display', 'spam_result_display_mode' ] as $setting_key )
            {
                if ( ! array_key_exists( $setting_key, $context ) && array_key_exists( $setting_key, $settings ) )
                {
                    $context[ $setting_key ] = $settings[ $setting_key ];
                }
            }
            $context['settings'] = $settings;

            $this->record_blocking_spam_notification_state( $entry_id, $context, $canonical_result );
            $this->maybe_mark_entry_as_spam_from_result( $entry_id, $context, $canonical_result );
        }
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function normalize_accepted_submission( mixed $native_submission ): array | WP_Error
    {
        if ( ! is_array( $native_submission ) )
        {
            return new WP_Error( 'sentient_forms_gravity_forms_invalid_accepted_submission', __( 'Gravity Forms accepted submission payload is invalid.', 'sentient-forms' ) );
        }

        $entry   = $native_submission['entry'] ?? null;
        $form    = $native_submission['form'] ?? null;
        $form_id = is_array( $form ) ? absint( $form['id'] ?? 0 ) : 0;
        $entry_id = is_array( $entry ) ? absint( $entry['id'] ?? 0 ) : 0;
        if ( ! is_array( $entry ) || ! is_array( $form ) || $form_id <= 0 || $entry_id <= 0 )
        {
            return new WP_Error( 'sentient_forms_gravity_forms_invalid_accepted_submission', __( 'Gravity Forms accepted submission is missing its form or entry identity.', 'sentient-forms' ) );
        }

        return [
            'form_id'             => (string) $form_id,
            'form'                => $this->gravity_form_snapshot( $form, $form_id ),
            'logical_fields'      => $this->build_submission_ledger_logical_fields( $entry, $form ),
            'files'               => $this->build_submission_ledger_file_references( $entry, $form ),
            'native_entry_id'     => (string) $entry_id,
            'native_entry_url'    => $this->build_submission_ledger_entry_url( $form_id, $entry_id ),
            'source_submitted_at' => isset( $entry['date_created'] ) && is_scalar( $entry['date_created'] )
                ? sanitize_text_field( (string) $entry['date_created'] )
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $form
     *
     * @return array<string, mixed>
     */
    private function gravity_form_snapshot( array $form, int $form_id ): array
    {
        $fields = [];
        foreach ( is_array( $form['fields'] ?? null ) ? $form['fields'] : [] as $field )
        {
            $field_id = $this->extract_gravity_field_property( $field, 'id' );
            if ( '' === $field_id )
            {
                continue;
            }

            $field_type  = sanitize_key( strtolower( $this->extract_gravity_field_property( $field, 'type' ) ) );
            $admin_label = $this->extract_gravity_field_property( $field, 'adminLabel' );
            $label       = '' !== $admin_label ? $admin_label : $this->extract_gravity_field_property( $field, 'label' );
            $fields[]    = [
                'id'               => $field_id,
                'label'            => $label,
                'admin_label'      => $admin_label,
                'type'             => '' !== $field_type ? $field_type : 'unknown',
                'storage_eligible' => 'fileupload' !== $field_type,
            ];
        }

        return [
            'id'          => (string) $form_id,
            'title'       => isset( $form['title'] ) && is_scalar( $form['title'] )
                ? sanitize_text_field( (string) $form['title'] )
                : sprintf(
                    /* translators: %d: Gravity Forms form ID. */
                    __( 'Gravity Form %d', 'sentient-forms' ),
                    $form_id
                ),
            'form_source' => $this->get_id(),
            'fields'      => $fields,
        ];
    }

    /**
     * Describe Gravity Forms native entry capabilities independent of installation state.
     *
     * @return array<string, bool>
     */
    public function get_structural_native_entry_capabilities(): array
    {
        return [
            'id'    => true,
            'link'  => true,
            'read'  => true,
            'write' => true,
        ];
    }

    /**
     * Describe Gravity Forms native effects independent of installation state.
     *
     * @return array<string, bool>
     */
    public function get_structural_native_effect_capabilities(): array
    {
        return [
            'notes'                 => true,
            'status'                => true,
            'spam'                  => true,
            'notification_controls' => true,
            'webhook_controls'      => true,
        ];
    }

    /**
     * Describe Gravity Forms validation effects independent of installation state.
     *
     * @return array<string, bool>
     */
    public function get_structural_validation_effect_capabilities(): array
    {
        return [
            'field_errors'    => true,
            'form_errors'     => true,
            'submission_spam' => true,
        ];
    }

    /**
     * Get the source-neutral realtime lifecycle identifier.
     *
     * @return string
     */
    public function get_realtime_native_hook(): string
    {
        return 'real_time';
    }

    /**
     * Describe Gravity Forms realtime capabilities independent of installation state.
     *
     * @return array<string, bool>
     */
    public function get_structural_realtime_capabilities(): array
    {
        return [ 'qna_storage' => true ];
    }

    /**
     * Describe Gravity Forms capabilities using Sentient Forms Form Source terms.
     *
     * @return array<string, mixed>
     */
    public function get_capability_descriptor(): array
    {
        $is_active          = $this->is_active();
        $native_entry       = $this->get_structural_native_entry_capabilities();
        $native_enrichment  = $this->get_structural_native_effect_capabilities();
        $native_enrichment['webhook_controls'] = $this->gravity_forms_webhooks_feed_controls_available();
        $validation_effects = $this->get_structural_validation_effect_capabilities();

        return [
            'slug'                 => 'gravity_forms',
            'label'                => __( 'Gravity Forms', 'sentient-forms' ),
            'availability'         => $is_active ? 'available' : 'inactive',
            'availability_message' => $is_active
                ? __( 'Gravity Forms is active and ready for Sentient Forms actions.', 'sentient-forms' )
                : __( 'Activate Gravity Forms to configure Sentient Forms actions for Gravity forms.', 'sentient-forms' ),
            'forms_discovery'      => [
                'supported' => $is_active,
                'reason'    => $is_active ? null : __( 'Gravity Forms must be active before forms can be listed.', 'sentient-forms' ),
            ],
            'field_manifest'       => [
                'supported' => $is_active,
                'reason'    => $is_active ? null : __( 'Gravity Forms must be active before fields can be inspected.', 'sentient-forms' ),
            ],
            'lifecycles'           => [
                'validation'       => [
                    'supported'          => true,
                    'label'              => __( 'Validation', 'sentient-forms' ),
                    'native_hook'        => 'gform_validation',
                    'execution_mode'     => 'blocking',
                    'requires_ledger'    => false,
                    'unsupported_reason' => null,
                ],
                'after_submission' => [
                    'supported'          => true,
                    'label'              => __( 'After submission', 'sentient-forms' ),
                    'native_hook'        => 'gform_after_submission',
                    'execution_mode'     => 'async',
                    'requires_ledger'    => false,
                    'unsupported_reason' => null,
                ],
                'real_time'        => [
                    'supported'          => true,
                    'label'              => __( 'Real time', 'sentient-forms' ),
                    'native_hook'        => 'real_time',
                    'execution_mode'     => 'real_time',
                    'requires_ledger'    => false,
                    'unsupported_reason' => null,
                ],
            ],
            'native_entry'         => $native_entry,
            'native_enrichment'    => $native_enrichment,
            'validation_effects'   => $validation_effects,
            'ledger'               => [
                'required_for_parity' => false,
                'enabled'             => false,
                'settings_source'     => 'sentient_submission_ledger_settings',
                'unavailable_reason'  => null,
            ],
            'requirements'         => [
                'plugin'         => 'gravityforms/gravityforms.php',
                'module'         => null,
                'requires_pro'   => false,
                'requires_addon' => false,
            ],
        ];
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
        add_filter( 'gform_validation', [ $this, 'handle_validation' ], 10, 2 );
        add_action( self::NATIVE_AFTER_SUBMISSION_HOOK, [ $this, 'handle_accepted_submission' ], 10, 2 );
        add_filter( 'gform_entry_post_save', [ $this, 'handle_validation_entry_post_save' ], 10, 2 );

        // FR-003: Notification interception hook - suppress notifications for blocking spam entries only
        add_filter( 'gform_notification', [ $this, 'maybe_suppress_spam_notification' ], 10, 3 );
        add_filter( 'gform_disable_notification', [ $this, 'maybe_defer_async_spam_notification' ], 10, 5 );
        if ( $this->gravity_forms_webhooks_feed_controls_available() )
        {
            add_filter( 'gform_' . self::GRAVITY_FORMS_WEBHOOKS_ADDON_SLUG . '_pre_process_feeds', [ $this, 'maybe_defer_async_spam_webhooks' ], 10, 3 );
        }

        // Add settings to the form editor
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_editor_assets' ] );
        add_filter( 'gform_tooltips', [ $this, 'add_tooltips' ] );
        add_action( 'gform_field_standard_settings', [ $this, 'field_settings' ], 10, 2 );
        add_filter( 'gform_pre_render', [ $this, 'ensure_realtime_storage_field_for_rendered_form' ], 9, 1 );
        add_filter( 'gform_pre_validation', [ $this, 'ensure_realtime_storage_field_for_rendered_form' ], 9, 1 );
        add_filter( 'gform_pre_submission_filter', [ $this, 'ensure_realtime_storage_field_for_rendered_form' ], 9, 1 );
        add_action( 'gform_enqueue_scripts', [ $this, 'enqueue_realtime_suggestions_runtime' ], 20, 2 );

        if ( class_exists( 'Sentient_Forms_Realtime_Qna_Admin_Display' ) )
        {
            ( new Sentient_Forms_Realtime_Qna_Admin_Display() )->register_hooks();
        }

        add_filter( 'sentient_forms_async_evaluation_jobs', [ $this, 'filter_async_evaluation_jobs' ], 10, 3 );
    }

    public function handle_accepted_submission( array $entry, array $form ): ?string
    {
        $cache_key = $this->accepted_submission_cache_key( $entry, $form );
        $outcome   = $this->accepted_submission_outcomes[ $cache_key ] ?? null;
        if ( ! $outcome instanceof Sentient_Forms_Accepted_Submission_Run_Result )
        {
            $outcome = $this->run_accepted_submission_workflow( $entry, $form );
            $this->accepted_submission_outcomes[ $cache_key ] = $outcome;
            $this->apply_synchronous_accepted_submission_results( $entry, $form, $outcome );
        }

        $submission_uuid = $outcome->get_submission_uuid();
        $this->backfill_validation_action_log_entry_ids( $entry, $form, $submission_uuid );
        $this->reconcile_deferred_notifications_after_submission( $entry, $form, $outcome );
        $this->reconcile_deferred_webhooks_after_submission( $entry, $form, $outcome );

        return $submission_uuid;
    }

    private function apply_synchronous_accepted_submission_results(
        array $entry,
        array $form,
        Sentient_Forms_Accepted_Submission_Run_Result $outcome
    ): void
    {
        $entry_id = absint( $entry['id'] ?? 0 );
        $this->record_accepted_submission_spam_delivery_state( $entry_id, $outcome );
        foreach ( $outcome->get_mapping_outcomes() as $mapping_id => $mapping_outcome )
        {
            if ( 'succeeded' !== $mapping_outcome )
            {
                continue;
            }

            $mapping = $outcome->get_resolved_mapping( (string) $mapping_id );
            $result  = $outcome->get_execution_result( (string) $mapping_id );
            if ( ! is_array( $mapping ) || ! is_array( $result ) )
            {
                continue;
            }

            if ( 'local_first' === sanitize_key( (string) ( $mapping['action_type_indicator'] ?? '' ) ) )
            {
                continue;
            }

            $settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] )
                ? $mapping['settings']
                : [];
            $context = [
                'hook'                      => self::NATIVE_AFTER_SUBMISSION_HOOK,
                'form_source'               => $this->get_id(),
                'action_id'                 => (string) $mapping_id,
                'mapping_id'                => (string) $mapping_id,
                'local_mapping_id'          => (string) $mapping_id,
                'form_id'                   => $form['id'] ?? null,
                'entry_id'                  => $entry['id'] ?? null,
                'submission_uuid'           => $outcome->get_submission_uuid(),
                'central_action_id'         => $mapping['central_action_id'] ?? null,
                'mark_as_spam'              => ! empty( $mapping['mark_as_spam'] ) || ! empty( $settings['mark_as_spam'] ),
                'spam_confidence_threshold' => $settings['spam_confidence_threshold'] ?? $mapping['spam_confidence_threshold'] ?? 0.80,
                'spam_indicators_display'   => $settings['spam_indicators_display'] ?? $mapping['spam_indicators_display'] ?? 'simple',
                'spam_result_display_mode'  => $settings['spam_result_display_mode'] ?? $mapping['spam_result_display_mode'] ?? 'all_results',
                'settings'                  => $settings,
            ];
            if ( $this->mapping_has_spam_semantics( $mapping ) )
            {
                $this->maybe_mark_entry_as_spam_from_result( $entry_id, $context, $result );
            }

            $this->run_post_execution_actions(
                $entry_id,
                $context,
                $result
            );
        }
    }

    /**
     * Apply validation-time native effects and run accepted-submission work as
     * soon as Gravity saves the entry. Gravity evaluates delivery filters before
     * gform_after_submission, so the exact accepted hook reuses this outcome.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $form
     *
     * @return array<string, mixed>
     */
    public function handle_validation_entry_post_save( array $entry, array $form ): array
    {
        $form_id = absint( $form['id'] ?? 0 );
        $validation_result = $form_id > 0 ? ( $this->validation_run_results_by_form[ $form_id ] ?? null ) : null;
        if ( $validation_result instanceof Sentient_Forms_Validation_Run_Result )
        {
            unset( $this->validation_run_results_by_form[ $form_id ] );
            $this->apply_validation_entry_effects( $entry, $form, $validation_result );
        }
        $execution_request_ids = $form_id > 0
            ? ( $this->validation_execution_request_ids_by_form[ $form_id ] ?? [] )
            : [];

        $this->apply_validation_local_execution_results_to_entry( $execution_request_ids, $entry, $form );

        $cache_key = $this->accepted_submission_cache_key( $entry, $form );
        if ( ! isset( $this->accepted_submission_outcomes[ $cache_key ] ) )
        {
            $outcome = $this->run_accepted_submission_workflow( $entry, $form );
            $this->accepted_submission_outcomes[ $cache_key ] = $outcome;
            $this->backfill_validation_action_log_entry_ids( $entry, $form, $outcome->get_submission_uuid() );
            $this->apply_synchronous_accepted_submission_results( $entry, $form, $outcome );
        }

        return $entry;
    }

    private function run_accepted_submission_workflow(
        array $entry,
        array $form
    ): Sentient_Forms_Accepted_Submission_Run_Result
    {
        return $this->get_workflow_runner()->run_accepted_submission_with_outcome(
            $this,
            [
                'entry' => $entry,
                'form'  => $form,
            ]
        );
    }

    private function accepted_submission_cache_key( array $entry, array $form ): string
    {
        return absint( $form['id'] ?? ( $entry['form_id'] ?? 0 ) ) . ':' . absint( $entry['id'] ?? 0 );
    }

    private function get_workflow_runner(): Sentient_Forms_Form_Source_Workflow_Runner
    {
        if ( null === $this->workflow_runner )
        {
            $this->workflow_runner = new Sentient_Forms_Form_Source_Workflow_Runner( $this->plugin );
        }

        return $this->workflow_runner;
    }

    protected function gravity_forms_webhooks_feed_controls_available(): bool
    {
        $addon_available = class_exists( 'GF_Webhooks' )
            || class_exists( 'Gravity_Forms_Webhooks' )
            || defined( 'GF_WEBHOOKS_VERSION' );

        return $addon_available
            && class_exists( 'GFAPI' )
            && is_callable( [ 'GFAPI', 'maybe_process_feeds' ] );
    }

    private function build_submission_ledger_entry_url( int $form_id, int $entry_id ): string
    {
        return admin_url(
            add_query_arg(
                [
                    'page' => 'gf_entries',
                    'view' => 'entry',
                    'id'   => $form_id,
                    'lid'  => $entry_id,
                ],
                'admin.php'
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function build_submission_ledger_logical_fields( array $entry, array $form ): array
    {
        $fields = isset( $form['fields'] ) && is_array( $form['fields'] )
            ? $form['fields']
            : [];
        $logical_fields = [];

        foreach ( $fields as $field )
        {
            if ( 'fileupload' === strtolower( $this->extract_gravity_field_property( $field, 'type' ) ) )
            {
                continue;
            }

            $field_id = $this->extract_gravity_field_property( $field, 'id' );
            if ( '' === $field_id )
            {
                continue;
            }

            $field_key = $this->submission_ledger_field_key( $field, $field_id );
            if ( array_key_exists( $field_key, $logical_fields ) )
            {
                $field_key = sanitize_key( $field_key . '_field_' . str_replace( '.', '_', $field_id ) );
            }
            $value     = $this->submission_ledger_entry_value_for_field( $entry, $field );
            if ( $this->submission_ledger_value_is_empty( $value ) )
            {
                continue;
            }

            $logical_fields[ $field_key ] = $value;
        }

        return $logical_fields;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function build_submission_ledger_file_references( array $entry, array $form ): array
    {
        $fields = isset( $form['fields'] ) && is_array( $form['fields'] )
            ? $form['fields']
            : [];
        $file_references = [];

        foreach ( $fields as $field )
        {
            $field_type = strtolower( $this->extract_gravity_field_property( $field, 'type' ) );
            if ( 'fileupload' !== $field_type )
            {
                continue;
            }

            $field_id = $this->extract_gravity_field_property( $field, 'id' );
            if ( '' === $field_id )
            {
                continue;
            }

            foreach ( $this->submission_ledger_file_values( $entry[ $field_id ] ?? null ) as $file_value )
            {
                $path     = wp_parse_url( $file_value, PHP_URL_PATH );
                $filename = is_string( $path ) && '' !== $path ? basename( $path ) : basename( $file_value );

                $file_references[] = [
                    'field_id' => $field_id,
                    'filename' => $filename,
                    'url'      => $file_value,
                ];
            }
        }

        return $file_references;
    }

    private function submission_ledger_field_key( mixed $field, string $field_id ): string
    {
        $label = $this->extract_gravity_field_property( $field, 'adminLabel' );
        if ( '' === $label )
        {
            $label = $this->extract_gravity_field_property( $field, 'label' );
        }

        $key = sanitize_key( str_replace( [ ' ', '.', '-' ], '_', strtolower( $label ) ) );

        return '' !== $key
            ? $key
            : sanitize_key( 'field_' . str_replace( '.', '_', $field_id ) );
    }

    private function submission_ledger_entry_value_for_field( array $entry, mixed $field ): mixed
    {
        $field_id = $this->extract_gravity_field_property( $field, 'id' );
        if ( '' !== $field_id && array_key_exists( $field_id, $entry ) )
        {
            $direct_value = $entry[ $field_id ];
            if ( ! $this->submission_ledger_value_is_empty( $direct_value ) )
            {
                return $direct_value;
            }

            $input_values = $this->submission_ledger_entry_input_values_for_field( $entry, $field );

            return [] !== $input_values ? $input_values : $direct_value;
        }

        return $this->submission_ledger_entry_input_values_for_field( $entry, $field );
    }

    private function submission_ledger_value_is_empty( mixed $value ): bool
    {
        return null === $value || '' === $value || [] === $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function submission_ledger_entry_input_values_for_field( array $entry, mixed $field ): array
    {
        $input_values = [];
        foreach ( $this->submission_ledger_field_input_ids( $field ) as $input_id )
        {
            if ( array_key_exists( $input_id, $entry ) && ! $this->submission_ledger_value_is_empty( $entry[ $input_id ] ) )
            {
                $input_values[ str_replace( '.', '_', $input_id ) ] = $entry[ $input_id ];
            }
        }

        return $input_values;
    }

    /**
     * @return array<int, string>
     */
    private function submission_ledger_field_input_ids( mixed $field ): array
    {
        $inputs = [];
        if ( is_array( $field ) && isset( $field['inputs'] ) && is_array( $field['inputs'] ) )
        {
            $inputs = $field['inputs'];
        }
        elseif ( is_object( $field ) && isset( $field->inputs ) && is_array( $field->inputs ) )
        {
            $inputs = $field->inputs;
        }

        $input_ids = [];
        foreach ( $inputs as $input )
        {
            $input_id = '';
            if ( is_array( $input ) && isset( $input['id'] ) && is_scalar( $input['id'] ) )
            {
                $input_id = (string) $input['id'];
            }
            elseif ( is_object( $input ) && isset( $input->id ) && is_scalar( $input->id ) )
            {
                $input_id = (string) $input->id;
            }

            if ( '' !== trim( $input_id ) )
            {
                $input_ids[] = $input_id;
            }
        }

        return $input_ids;
    }

    /**
     * @return array<int, string>
     */
    private function submission_ledger_file_values( mixed $value ): array
    {
        if ( is_array( $value ) )
        {
            $values = [];
            array_walk_recursive(
                $value,
                static function ( mixed $item ) use ( &$values ): void {
                    if ( is_scalar( $item ) )
                    {
                        $item = trim( (string) $item );
                        if ( '' !== $item )
                        {
                            $values[] = $item;
                        }
                    }
                }
            );

            return array_values( array_unique( $values ) );
        }

        if ( ! is_scalar( $value ) )
        {
            return [];
        }

        $value = trim( (string) $value );
        if ( '' === $value )
        {
            return [];
        }

        $decoded = json_decode( $value, true );
        if ( is_array( $decoded ) )
        {
            return $this->submission_ledger_file_values( $decoded );
        }

        return array_values(
            array_filter(
                array_map( 'trim', explode( ',', $value ) ),
                static fn ( string $item ): bool => '' !== $item
            )
        );
    }

    private function backfill_validation_action_log_entry_ids( array $entry, array $form, ?string $submission_uuid = null ): void
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
            $submission_uuid
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
            $mapping_hook = is_array( $mapping )
                ? Sentient_Forms_Form_Source_Lifecycles::normalize_id( (string) ( $mapping['hook'] ?? '' ) )
                : '';
            if ( ! is_array( $mapping ) || Sentient_Forms_Form_Source_Lifecycles::VALIDATION !== $mapping_hook )
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
                $action = [];
                if ( 'custom_action' === sanitize_key( (string) ( $mapping['action_kind'] ?? '' ) ) )
                {
                    $action = $custom_actions->get( absint( $mapping['action_id'] ?? 0 ) ) ?? [];
                }

                $action_label = isset( $action['display_name'] ) && is_scalar( $action['display_name'] )
                    ? sanitize_text_field( (string) $action['display_name'] )
                    : $this->get_async_action_label( $mapping );
                $reason = isset( $event['error_message'] ) && '' !== trim( (string) $event['error_message'] )
                    ? trim( (string) $event['error_message'] )
                    : __( 'Unknown local provider error.', 'sentient-forms' );
                $note = sprintf(
                    /* translators: 1: action label, 2: local action failure reason */
                    __( 'Sentient Forms could not complete %1$s. Reason: %2$s', 'sentient-forms' ),
                    $action_label,
                    $reason
                );
                $this->add_local_action_entry_note_if_missing( $entry_id, 'Sentient Forms AI', $note );
                $runtime_mapping = $this->normalize_local_first_form_mapping( $mapping ) ?? $mapping;
                $this->record_failed_spam_delivery_state( $entry_id, $runtime_mapping, $form_id );
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
    public function handle_validation( array $validation_result, mixed $native_context = null ): array
    {
        $result  = $this->get_workflow_runner()->run_validation( $this, $validation_result, $native_context );
        $form_id = absint( $validation_result['form']['id'] ?? 0 );
        if ( $form_id > 0 )
        {
            $this->validation_run_results_by_form[ $form_id ] = $result;
            foreach ( $result->get_execution_request_ids() as $execution_request_id )
            {
                $this->remember_validation_execution_request_id( $form_id, $execution_request_id );
            }
        }

        return $this->apply_validation_result( $validation_result, $result );
    }

    /**
     * Determine whether a planner node was explicitly detached from its hook root.
     *
     * @param array<string, mixed> $node Planner node.
     */
    private function is_plan_node_trigger_unbound( array $node, string $hook ): bool
    {
        $hook = sanitize_key( $hook );
        if ( '' === $hook )
        {
            return false;
        }

        $lifecycle_hook = Sentient_Forms_Form_Source_Lifecycles::normalize_id( $hook );
        $hook_keys      = array_values( array_unique( array_filter( [ $hook, $lifecycle_hook ] ) ) );

        $trigger_sources = is_array( $node['trigger_sources'] ?? null )
            ? $node['trigger_sources']
            : [];

        foreach ( $hook_keys as $hook_key )
        {
            $source = is_array( $trigger_sources[ $hook_key ] ?? null )
                ? $trigger_sources[ $hook_key ]
                : null;

            if ( 'unbound' === sanitize_key( (string) ( $source['type'] ?? '' ) ) )
            {
                return true;
            }
        }

        return false;
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
                    $field_id = (string) $field->id;

                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Gravity Forms owns frontend submission verification before this hook.
                    $posted_value = $this->get_posted_gravity_input_value( $field_id );
                    if ( null !== $posted_value )
                    {
                        $entry[ $field_id ] = $posted_value;
                    }

                    $complex_values = $this->get_posted_gravity_complex_input_values( $field );
                    foreach ( $complex_values as $input_id => $input_value )
                    {
                        $entry[ $input_id ] = $input_value;
                    }

                    if ( [] !== $complex_values )
                    {
                        $aggregate = $this->aggregate_gravity_complex_input_values( $complex_values );
                        if ( '' !== $aggregate && ! isset( $entry[ $field_id ] ) )
                        {
                            $entry[ $field_id ] = $aggregate;
                        }
                    }
                }
            }
        }

        return $entry;
    }

    private function get_posted_gravity_input_value( string $field_id ): mixed
    {
        $input_name = 'input_' . str_replace( '.', '_', $field_id );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Gravity Forms owns frontend submission verification before this hook.
        if ( ! isset( $_POST[ $input_name ] ) )
        {
            return null;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Gravity Forms owns frontend submission verification before this hook; the value is sanitized recursively by sanitize_posted_gravity_input_value().
        return $this->sanitize_posted_gravity_input_value( wp_unslash( $_POST[ $input_name ] ) );
    }

    /**
     * @return array<string, mixed>
     */
    private function get_posted_gravity_complex_input_values( object $field ): array
    {
        $inputs = isset( $field->inputs ) && is_array( $field->inputs ) ? $field->inputs : [];
        if ( [] === $inputs )
        {
            return [];
        }

        $values = [];
        foreach ( $inputs as $input )
        {
            $input_id = null;
            if ( is_array( $input ) && isset( $input['id'] ) && is_scalar( $input['id'] ) )
            {
                $input_id = (string) $input['id'];
            }
            elseif ( is_object( $input ) && isset( $input->id ) && is_scalar( $input->id ) )
            {
                $input_id = (string) $input->id;
            }

            if ( null === $input_id || '' === trim( $input_id ) )
            {
                continue;
            }

            $posted_value = $this->get_posted_gravity_input_value( $input_id );
            if ( null !== $posted_value )
            {
                $values[ $input_id ] = $posted_value;
            }
        }

        return $values;
    }

    private function sanitize_posted_gravity_input_value( mixed $value ): mixed
    {
        if ( is_array( $value ) )
        {
            $sanitized = [];
            foreach ( $value as $key => $item )
            {
                if ( is_array( $item ) )
                {
                    $sanitized[ sanitize_key( (string) $key ) ] = $this->sanitize_posted_gravity_input_value( $item );
                    continue;
                }

                if ( is_scalar( $item ) )
                {
                    $sanitized[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $item );
                }
            }

            return $sanitized;
        }

        return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : null;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function aggregate_gravity_complex_input_values( array $values ): string
    {
        $parts = [];
        array_walk_recursive(
            $values,
            static function ( mixed $value ) use ( &$parts ): void {
                if ( ! is_scalar( $value ) )
                {
                    return;
                }

                $value = trim( (string) $value );
                if ( '' !== $value )
                {
                    $parts[] = $value;
                }
            }
        );

        return implode( ' ', $parts );
    }

    /**
     * Persist spam classification state for blocking after-submission execution before notifications are evaluated.
     *
     * Gravity Forms passes the in-memory entry to notification filters immediately after gform_entry_post_save.
     * When a blocking action classifies spam before notifications, we persist the classification meta here so the
     * notification filters can suppress delivery even if the in-memory entry status has not been refreshed yet.
     *
     * @param int                                           $entry_id Gravity Forms entry id.
     * @param Sentient_Forms_Accepted_Submission_Run_Result $outcome  Accepted-submission outcome.
     *
     * @return void
     */
    private function record_accepted_submission_spam_delivery_state(
        int $entry_id,
        Sentient_Forms_Accepted_Submission_Run_Result $outcome
    ): void
    {
        if ( $entry_id <= 0 )
        {
            return;
        }

        $has_classification      = false;
        $has_qualifying_spam     = false;
        $suppress_notifications  = false;
        $suppress_webhooks       = false;
        $fallback_classification = 'ham';

        foreach ( $outcome->get_mapping_outcomes() as $mapping_id => $mapping_outcome )
        {
            if ( 'succeeded' !== $mapping_outcome )
            {
                continue;
            }

            $mapping = $outcome->get_resolved_mapping( (string) $mapping_id );
            $result  = $outcome->get_execution_result( (string) $mapping_id );
            if ( ! is_array( $mapping ) || ! is_array( $result ) )
            {
                continue;
            }
            if ( ! $this->mapping_has_spam_semantics( $mapping ) )
            {
                continue;
            }

            $classification = $this->extract_spam_classification( $result );
            if ( ! is_string( $classification ) || '' === $classification )
            {
                continue;
            }

            if ( in_array( $classification, [ 'ham', 'legitimate' ], true ) )
            {
                $has_classification = true;
                continue;
            }

            if ( !in_array( $classification, [ 'spam', 'likely_spam' ], true ) )
            {
                continue;
            }

            $has_classification = true;
            $confidence         = $this->extract_spam_confidence( $result );
            $threshold          = $this->get_spam_confidence_threshold_for_context( $mapping );
            if ( ( $confidence ?? 1.0 ) < $threshold )
            {
                $fallback_classification = 'reviewed';
                continue;
            }

            $has_qualifying_spam    = true;
            $suppress_notifications = $suppress_notifications || $this->should_suppress_notifications_on_spam( $mapping );
            $suppress_webhooks      = $suppress_webhooks || $this->should_suppress_webhooks_on_spam( $mapping );
        }

        if ( ! $has_classification )
        {
            return;
        }

        $this->update_entry_meta(
            $entry_id,
            'spam_classification',
            $has_qualifying_spam ? 'spam' : $fallback_classification
        );
        $this->update_entry_meta(
            $entry_id,
            self::SPAM_NOTIFICATION_PREFERENCE_META_KEY,
            $has_qualifying_spam && $suppress_notifications
                ? self::SPAM_NOTIFICATION_PREFERENCE_SUPPRESS
                : self::SPAM_NOTIFICATION_PREFERENCE_ALLOW
        );
        $this->update_entry_meta(
            $entry_id,
            self::SPAM_WEBHOOK_PREFERENCE_META_KEY,
            $has_qualifying_spam && $suppress_webhooks
                ? self::SPAM_NOTIFICATION_PREFERENCE_SUPPRESS
                : self::SPAM_NOTIFICATION_PREFERENCE_ALLOW
        );
    }

    /**
     * Persist spam delivery state for one blocking execution result.
     *
     * Accepted-submission workflows with multiple mappings use
     * record_accepted_submission_spam_delivery_state() so their effects are
     * reduced monotonically. Validation and completed local-provider paths
     * still produce one result at a time and use this helper directly.
     *
     * @param int                  $entry_id        Gravity Forms entry id.
     * @param array<string, mixed> $action_settings Runtime mapping settings.
     * @param array<string, mixed> $result          Blocking action result payload.
     *
     * @return void
     */
    private function record_blocking_spam_notification_state(
        int $entry_id,
        array $action_settings,
        array $result
    ): void
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
            $confidence = $this->extract_spam_confidence( $result );
            $threshold  = $this->get_spam_confidence_threshold_for_context( $action_settings );
            if ( ( $confidence ?? 1.0 ) < $threshold )
            {
                $this->update_entry_meta( $entry_id, 'spam_classification', 'reviewed' );
                $this->update_entry_meta( $entry_id, self::SPAM_NOTIFICATION_PREFERENCE_META_KEY, self::SPAM_NOTIFICATION_PREFERENCE_ALLOW );
                $this->update_entry_meta( $entry_id, self::SPAM_WEBHOOK_PREFERENCE_META_KEY, self::SPAM_NOTIFICATION_PREFERENCE_ALLOW );

                return;
            }

            $this->update_entry_meta(
                $entry_id,
                self::SPAM_NOTIFICATION_PREFERENCE_META_KEY,
                $this->should_suppress_notifications_on_spam( $action_settings )
                    ? self::SPAM_NOTIFICATION_PREFERENCE_SUPPRESS
                    : self::SPAM_NOTIFICATION_PREFERENCE_ALLOW
            );
            $this->update_entry_meta(
                $entry_id,
                self::SPAM_WEBHOOK_PREFERENCE_META_KEY,
                $this->should_suppress_webhooks_on_spam( $action_settings )
                    ? self::SPAM_NOTIFICATION_PREFERENCE_SUPPRESS
                    : self::SPAM_NOTIFICATION_PREFERENCE_ALLOW
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
            $this->update_entry_meta( $entry_id, self::SPAM_WEBHOOK_PREFERENCE_META_KEY, self::SPAM_NOTIFICATION_PREFERENCE_ALLOW );
        }
    }

    /**
     * Fail closed for delivery side effects when a spam action cannot produce a classification.
     *
     * @param int                  $entry_id        Gravity Forms entry id.
     * @param array<string, mixed> $mapping         Runtime mapping or local custom-table mapping row.
     * @param int                  $form_id         Gravity Forms form id for inherited settings resolution.
     *
     * @return void
     */
    private function record_failed_spam_delivery_state( int $entry_id, array $mapping, int $form_id = 0 ): void
    {
        if ( $entry_id <= 0 )
        {
            return;
        }

        $action_id = isset( $mapping['central_action_id'] ) && is_scalar( $mapping['central_action_id'] )
            ? sanitize_key( (string) $mapping['central_action_id'] )
            : '';

        if ( ! $this->local_spam_effect_enabled( $mapping ) && ! $this->is_spam_action_id( $action_id ) )
        {
            return;
        }

        if ( $form_id > 0 )
        {
            $mapping = $this->resolve_mapping_runtime_settings( $mapping, $form_id );
        }

        if ( $this->should_suppress_notifications_on_spam( $mapping ) )
        {
            $this->update_entry_meta( $entry_id, self::SPAM_NOTIFICATION_PREFERENCE_META_KEY, self::SPAM_NOTIFICATION_PREFERENCE_SUPPRESS );
        }

        if ( $this->should_suppress_webhooks_on_spam( $mapping ) )
        {
            $this->update_entry_meta( $entry_id, self::SPAM_WEBHOOK_PREFERENCE_META_KEY, self::SPAM_NOTIFICATION_PREFERENCE_SUPPRESS );
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
     * Determine whether a spam mapping should suppress Gravity Forms Webhooks when it classifies spam.
     *
     * @param array<string, mixed> $mapping Mapping payload.
     */
    private function should_suppress_webhooks_on_spam( array $mapping ): bool
    {
        $settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) ? $mapping['settings'] : [];
        if ( array_key_exists( 'suppress_webhooks_on_spam', $settings ) )
        {
            return rest_sanitize_boolean( $settings['suppress_webhooks_on_spam'] );
        }

        $effect_mapping = $this->get_local_effect_mapping( $mapping );
        if ( array_key_exists( 'suppress_webhooks_on_spam', $effect_mapping ) )
        {
            return rest_sanitize_boolean( $effect_mapping['suppress_webhooks_on_spam'] );
        }

        if (
            isset( $effect_mapping['spam'] )
            && is_array( $effect_mapping['spam'] )
            && array_key_exists( 'suppress_webhooks_on_spam', $effect_mapping['spam'] )
        )
        {
            return rest_sanitize_boolean( $effect_mapping['spam']['suppress_webhooks_on_spam'] );
        }

        return $this->local_spam_effect_enabled( $mapping );
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
     * Determine whether a mapping is authorized to interpret Action output as spam state.
     *
     * @param array<string, mixed> $mapping Runtime mapping or repository row.
     */
    private function mapping_has_spam_semantics( array $mapping ): bool
    {
        $action_id = isset( $mapping['central_action_id'] ) && is_scalar( $mapping['central_action_id'] )
            ? sanitize_key( (string) $mapping['central_action_id'] )
            : '';
        $settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] )
            ? $mapping['settings']
            : [];

        return $this->is_spam_action_id( $action_id )
            || $this->local_spam_effect_enabled( $mapping )
            || ! empty( $mapping['mark_as_spam'] )
            || ! empty( $settings['mark_as_spam'] );
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

    public function enqueue_editor_assets( string $hook_suffix ): void
    {
        if ( ! $this->is_gravity_forms_editor_screen( $hook_suffix ) )
        {
            return;
        }

        wp_enqueue_script(
            'sentient-forms-gravity-forms-editor',
            SENTIENT_FORMS_PLUGIN_URL . 'assets/js/gravity-forms-editor.js',
            [ 'jquery' ],
            SENTIENT_FORMS_VERSION,
            true
        );
    }

    private function is_gravity_forms_editor_screen( string $hook_suffix ): bool
    {
        if ( 'gf_edit_forms' === $hook_suffix || str_ends_with( $hook_suffix, '_page_gf_edit_forms' ) )
        {
            return true;
        }

        if ( ! function_exists( 'get_current_screen' ) )
        {
            return false;
        }

        $screen = get_current_screen();
        if ( ! is_object( $screen ) )
        {
            return false;
        }

        foreach ( [ 'id', 'base' ] as $property )
        {
            $value = isset( $screen->{$property} ) ? (string) $screen->{$property} : '';
            if ( 'gf_edit_forms' === $value || str_ends_with( $value, '_page_gf_edit_forms' ) )
            {
                return true;
            }
        }

        return false;
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
                '<li class="sentient_forms_setting field_setting" id="sentient_forms_field_setting"><input type="checkbox" id="sentient_forms_enabled"/><label for="sentient_forms_enabled" class="inline">%s%s</label></li>',
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
     * @return array<string,mixed>|WP_Error
     */
    public function search_historical_entries( mixed $form_id, string $query = '', int $limit = 10, string $status = 'active' ): array | WP_Error
    {
        $form_id = absint( $form_id );
        $query   = strtolower( trim( sanitize_text_field( $query ) ) );
        $limit   = max( 1, min( 50, $limit ) );
        $status  = $this->normalize_historical_status_filter( $status );

        if ( $form_id <= 0 )
        {
            return new WP_Error( 'sentient_forms_gf_form_invalid', __( 'Gravity Forms form IDs must be numeric.', 'sentient-forms' ), [ 'status' => 400 ] );
        }

        if (
            ! class_exists( 'GFAPI' )
            || ( ! is_callable( [ 'GFAPI', 'get_entries' ] ) && ( ! property_exists( 'GFAPI', 'entries' ) || ! is_array( GFAPI::$entries ) ) )
        )
        {
            return [
                'entries'      => [],
                'form_source'  => $this->get_id(),
                'form_id'      => (string) $form_id,
                'availability' => $this->historical_native_availability( false, 'gravity_forms_unavailable' ),
            ];
        }

        $form          = is_callable( [ 'GFAPI', 'get_form' ] ) ? GFAPI::get_form( $form_id ) : null;
        $status_values = 'all' === $status ? [ 'active', 'spam' ] : [ $status ];
        $entries       = [];

        foreach ( $status_values as $status_value )
        {
            $status_matches = 0;
            if ( is_callable( [ 'GFAPI', 'get_entries' ] ) )
            {
                $page_size = max( 50, $limit );
                $offset    = 0;
                do
                {
                    $batch = GFAPI::get_entries(
                        $form_id,
                        [ 'status' => $status_value ],
                        [ 'key' => 'date_created', 'direction' => 'DESC' ],
                        [ 'offset' => $offset, 'page_size' => $page_size ]
                    );
                    if ( is_wp_error( $batch ) )
                    {
                        return $batch;
                    }

                    $batch      = is_array( $batch ) ? $batch : [];
                    $batch_size = count( $batch );
                    $offset    += $page_size;

                    foreach ( $batch as $entry )
                    {
                        if ( ! is_array( $entry ) )
                        {
                            continue;
                        }

                        $entry_id = (string) ( $entry['id'] ?? '' );
                        if ( '' === $entry_id )
                        {
                            continue;
                        }

                        $entry_status = $this->normalize_historical_status_filter( (string) ( $entry['status'] ?? $status_value ) );
                        if ( 'all' !== $status && $entry_status !== $status )
                        {
                            continue;
                        }

                        $formatted = $this->format_historical_gravity_entry( (string) $form_id, is_array( $form ) ? $form : [], $entry );
                        if ( '' !== $query && ! str_contains( strtolower( wp_json_encode( $formatted['field_summary'] ?? [] ) ?: '' ), $query ) )
                        {
                            continue;
                        }

                        $entries[ $entry_id ] = $formatted;
                        ++$status_matches;
                        if ( $status_matches >= $limit )
                        {
                            break 2;
                        }
                    }
                }
                while ( $batch_size === $page_size );
            }
            else
            {
                foreach ( GFAPI::$entries as $entry )
                {
                    if ( ! is_array( $entry ) || (int) ( $entry['form_id'] ?? 0 ) !== $form_id )
                    {
                        continue;
                    }

                    $entry_id = (string) ( $entry['id'] ?? '' );
                    if ( '' === $entry_id )
                    {
                        continue;
                    }

                    $entry_status = $this->normalize_historical_status_filter( (string) ( $entry['status'] ?? 'active' ) );
                    if ( $entry_status !== $status_value )
                    {
                        continue;
                    }

                    $formatted = $this->format_historical_gravity_entry( (string) $form_id, is_array( $form ) ? $form : [], $entry );
                    if ( '' !== $query && ! str_contains( strtolower( wp_json_encode( $formatted['field_summary'] ?? [] ) ?: '' ), $query ) )
                    {
                        continue;
                    }

                    $entries[ $entry_id ] = $formatted;
                    ++$status_matches;
                    if ( $status_matches >= $limit )
                    {
                        break;
                    }
                }
            }
        }

        usort(
            $entries,
            static fn ( array $a, array $b ): int => strcmp( (string) ( $b['date_created'] ?? '' ), (string) ( $a['date_created'] ?? '' ) )
        );

        return [
            'entries'      => array_slice( $entries, 0, $limit ),
            'form_source'  => $this->get_id(),
            'form_id'      => (string) $form_id,
            'availability' => $this->historical_native_availability( true ),
        ];
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function get_historical_entry( mixed $form_id, string $entry_id ): array | WP_Error
    {
        $form_id  = absint( $form_id );
        $entry_id = absint( $entry_id );
        if ( $form_id <= 0 || $entry_id <= 0 )
        {
            return new WP_Error( 'sentient_forms_entry_search_invalid_entry', __( 'Entry could not be found for this form.', 'sentient-forms' ), [ 'status' => 404 ] );
        }

        if ( ! class_exists( 'GFAPI' ) || ! is_callable( [ 'GFAPI', 'get_entry' ] ) )
        {
            return new WP_Error( 'sentient_forms_gfapi_unavailable', __( 'Gravity Forms entry search is unavailable.', 'sentient-forms' ), [ 'status' => 503 ] );
        }

        $entry = GFAPI::get_entry( $entry_id );
        if ( is_wp_error( $entry ) )
        {
            return $entry;
        }
        if ( ! is_array( $entry ) || (int) ( $entry['form_id'] ?? 0 ) !== $form_id )
        {
            return new WP_Error( 'sentient_forms_entry_search_entry_not_found', __( 'Entry could not be found for this form.', 'sentient-forms' ), [ 'status' => 404 ] );
        }

        $form = is_callable( [ 'GFAPI', 'get_form' ] ) ? GFAPI::get_form( $form_id ) : null;

        return $this->format_historical_gravity_entry( (string) $form_id, is_array( $form ) ? $form : [], $entry );
    }

    /**
     * @return array<string,mixed>
     */
    private function format_historical_gravity_entry( string $form_id, array $form, array $entry ): array
    {
        $entry_id = sanitize_text_field( (string) ( $entry['id'] ?? '' ) );

        return [
            'id'               => $entry_id,
            'source_type'      => 'native',
            'submission_uuid'  => null,
            'native_entry_id'  => '' !== $entry_id ? $entry_id : null,
            'native_entry_url' => $this->build_submission_ledger_entry_url( absint( $form_id ), absint( $entry_id ) ),
            'date_created'     => isset( $entry['date_created'] ) && is_scalar( $entry['date_created'] )
                ? sanitize_text_field( (string) $entry['date_created'] )
                : null,
            'status'           => $this->normalize_historical_status_filter( (string) ( $entry['status'] ?? 'active' ) ),
            'field_summary'    => $this->summarize_historical_entry_fields( $form, $entry ),
        ];
    }

    /**
     * @return array<int,array{field_id:string,label:string,value:string}>
     */
    private function summarize_historical_entry_fields( array $form, array $entry ): array
    {
        $summary = [];
        foreach ( is_array( $form['fields'] ?? null ) ? $form['fields'] : [] as $field )
        {
            $id = is_object( $field ) && isset( $field->id ) ? (string) $field->id : ( is_array( $field ) ? (string) ( $field['id'] ?? '' ) : '' );
            if ( '' === $id )
            {
                continue;
            }

            $value = $entry[ $id ] ?? '';
            if ( ! is_scalar( $value ) || '' === trim( (string) $value ) )
            {
                continue;
            }

            $label = is_object( $field ) && isset( $field->label ) ? (string) $field->label : ( is_array( $field ) ? (string) ( $field['label'] ?? $id ) : $id );
            $summary[] = [
                'field_id' => sanitize_text_field( $id ),
                'label'    => sanitize_text_field( $label ),
                'value'    => mb_substr( sanitize_textarea_field( (string) $value ), 0, 300 ),
            ];
        }

        if ( [] === $summary )
        {
            foreach ( $entry as $key => $value )
            {
                if ( count( $summary ) >= 8 || ! is_scalar( $value ) || '' === trim( (string) $value ) )
                {
                    continue;
                }

                $summary[] = [
                    'field_id' => sanitize_text_field( (string) $key ),
                    'label'    => sanitize_text_field( (string) $key ),
                    'value'    => mb_substr( sanitize_textarea_field( (string) $value ), 0, 300 ),
                ];
            }
        }

        return array_slice( $summary, 0, 12 );
    }

    /**
     * @return array<string,mixed>
     */
    private function historical_native_availability( bool $available, ?string $reason = null ): array
    {
        return [
            'source'                => 'native',
            'native_read'           => $available,
            'ledger_read'           => false,
            'unavailable_reason'    => $available ? null : $reason,
            'allow_ledger_fallback' => false,
        ];
    }

    private function normalize_historical_status_filter( string $status ): string
    {
        $status = sanitize_key( $status );
        return in_array( $status, [ 'all', 'active', 'spam' ], true ) ? $status : 'active';
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
     * Ensure realtime assistant virtual Q&A has a native Gravity Forms field.
     *
     * @param array<string,mixed> $form Gravity Forms form object as an array.
     *
     * @return array<string,mixed>
     */
    public function ensure_realtime_storage_field_for_rendered_form( array $form ): array
    {
        $form_id = isset( $form['id'] ) ? absint( $form['id'] ) : 0;
        if ( $form_id <= 0 )
        {
            return $form;
        }

        $settings = $this->get_form_settings( $form_id );
        $actions  = isset( $settings['actions'] ) && is_array( $settings['actions'] )
            ? $settings['actions']
            : [];

        $mappings = $this->collect_realtime_mappings( $actions );
        if ( empty( $mappings ) )
        {
            return $form;
        }

        return $this->resolve_realtime_mapping_storage_fields( $form, $mappings )['form'];
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
            $this->get_frontend_asset_version( 'assets/js/realtime-suggestions.js' ),
            true
        );
        wp_register_style(
            $style_handle,
            SENTIENT_FORMS_PLUGIN_URL . 'assets/css/realtime-suggestions.css',
            [],
            $this->get_frontend_asset_version( 'assets/css/realtime-suggestions.css' )
        );

        wp_enqueue_script( $script_handle );
        wp_enqueue_style( $style_handle );

        $json_config = wp_json_encode( $this->build_realtime_runtime_bootstrap( $form_id ) );
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
     * Build a fresh visitor runtime config for REST delivery.
     *
     * @return array<string,mixed>|null
     */
    public function get_realtime_runtime_config( int $form_id ): ?array
    {
        if ( $form_id <= 0 )
        {
            return null;
        }

        $form = $this->get_form_data( $form_id );
        if ( ! is_array( $form ) )
        {
            return null;
        }

        $form = $this->apply_realtime_runtime_display_filters( $form );

        return $this->build_realtime_runtime_config( $form, $this->get_form_settings( $form_id ) );
    }

    /**
     * Rebuild the no-store runtime config against the same form-display filter
     * surface Gravity Forms uses before rendering visitor fields.
     *
     * @param array<string,mixed> $form Gravity Forms form object as an array.
     *
     * @return array<string,mixed>
     */
    private function apply_realtime_runtime_display_filters( array $form ): array
    {
        $form_id = isset( $form['id'] ) ? absint( $form['id'] ) : 0;
        if ( $form_id <= 0 )
        {
            return $form;
        }

        if ( function_exists( 'gf_apply_filters' ) )
        {
            $filtered = gf_apply_filters(
                [ 'gform_pre_render', $form_id ],
                $form,
                false,
                [],
                'form_display'
            );
        }
        else
        {
            // Gravity Forms defines these third-party hooks; prefixing them would break adapter compatibility.
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Gravity Forms display hook.
            $filtered = apply_filters( 'gform_pre_render', $form, false, [], 'form_display' );
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Gravity Forms form-specific display hook.
            $filtered = apply_filters( 'gform_pre_render_' . $form_id, $filtered, false, [], 'form_display' );
        }

        return is_array( $filtered ) ? $filtered : $form;
    }

    private function get_frontend_asset_version( string $relative_path ): string
    {
        $fallback = defined( 'SENTIENT_FORMS_VERSION' ) ? SENTIENT_FORMS_VERSION : '0.1.0';
        $path     = trailingslashit( SENTIENT_FORMS_PLUGIN_DIR ) . ltrim( $relative_path, '/\\' );
        if ( ! file_exists( $path ) )
        {
            return $fallback;
        }

        $mtime = filemtime( $path );
        if ( false === $mtime )
        {
            return $fallback;
        }

        return $fallback . '-' . base_convert( (string) $mtime, 10, 36 );
    }

    /**
     * Build cache-safe page bootstrap. Dynamic runtime settings are fetched separately.
     *
     * @return array<string,mixed>
     */
    private function build_realtime_runtime_bootstrap( int $form_id ): array
    {
        return [
            'form_id'                     => $form_id,
            'source'                      => $this->get_id(),
            'runtime_config_endpoint_url' => rest_url(
                sprintf( 'sentient-forms/v1/%s/forms/%d/actions/runtime-config', $this->get_id(), $form_id )
            ),
            'runtime_config_token'        => self::build_realtime_runtime_config_token( $this->get_id(), $form_id ),
        ];
    }

    /**
     * Build a stable, cache-safe token proving a page rendered this form bootstrap.
     *
     * @internal
     */
    public static function build_realtime_runtime_config_token( string $form_source_slug, int $form_id ): string
    {
        $form_source_slug = sanitize_key( $form_source_slug );
        if ( '' === $form_source_slug || $form_id <= 0 )
        {
            return '';
        }

        return hash_hmac(
            'sha256',
            sprintf(
                'sentient_forms_realtime_runtime_config|%d|%s|%s|%d',
                function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
                untrailingslashit( home_url( '/' ) ),
                $form_source_slug,
                $form_id
            ),
            wp_salt( 'nonce' )
        );
    }

    /**
     * Verify a runtime config bootstrap token without exposing form config by ID alone.
     *
     * @internal
     */
    public static function is_valid_realtime_runtime_config_token( string $form_source_slug, int $form_id, string $token ): bool
    {
        $expected = self::build_realtime_runtime_config_token( $form_source_slug, $form_id );

        return '' !== $expected && '' !== $token && hash_equals( $expected, $token );
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
        $actions = array_map(
            function ( $action ) use ( $form_id ) {
                return is_array( $action ) ? $this->resolve_mapping_runtime_settings( $action, $form_id ) : $action;
            },
            $actions
        );
        $mappings = $this->collect_realtime_mappings( $actions );
        if ( empty( $mappings ) )
        {
            return null;
        }
        $storage_resolution = $this->resolve_realtime_mapping_storage_fields( $form, $mappings );
        $form               = $storage_resolution['form'];
        $mappings           = $storage_resolution['mappings'];

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

        $config_generated_at = time();
        $config_ttl_seconds  = (int) apply_filters(
            'sentient_forms_realtime_runtime_config_ttl_seconds',
            12 * HOUR_IN_SECONDS,
            $form_id
        );
        $config_ttl_seconds  = max( MINUTE_IN_SECONDS, min( DAY_IN_SECONDS, $config_ttl_seconds ) );

        return [
            'form_id'                     => $form_id,
            'source'                      => $this->get_id(),
            'total_pages'                 => $total_pages,
            'runtime_config_endpoint_url' => rest_url(
                sprintf( 'sentient-forms/v1/%s/forms/%d/actions/runtime-config', $this->get_id(), $form_id )
            ),
            'suggest_endpoint_url'        => rest_url( sprintf( 'sentient-forms/v1/gravity_forms/forms/%d/actions/suggest', $form_id ) ),
            'nonce'                       => wp_create_nonce( 'sentient_forms_realtime_suggest_' . $form_id ),
            'config_generated_at'         => $config_generated_at,
            'config_expires_at'           => $config_generated_at + $config_ttl_seconds,
            'initial_panel_state'         => $this->resolve_realtime_initial_panel_state( $mappings ),
            'mappings'                    => $mappings,
            'field_manifest'              => $field_manifest,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $mappings
     */
    private function resolve_realtime_initial_panel_state( array $mappings ): string
    {
        $has_hidden_until_interaction = false;

        foreach ( $mappings as $mapping )
        {
            $state = isset( $mapping['initial_panel_state'] ) && is_scalar( $mapping['initial_panel_state'] )
                ? sanitize_key( (string) $mapping['initial_panel_state'] )
                : 'minimized';

            if ( 'open' === $state )
            {
                return 'open';
            }

            if ( 'hidden_until_interaction' === $state )
            {
                $has_hidden_until_interaction = true;
            }
        }

        return $has_hidden_until_interaction ? 'hidden_until_interaction' : 'minimized';
    }

    /**
     * @param array<string,mixed>              $form
     * @param array<int,array<string,mixed>>   $mappings
     *
     * @return array{form: array<string,mixed>, mappings: array<int,array<string,mixed>>}
     */
    private function resolve_realtime_mapping_storage_fields( array $form, array $mappings ): array
    {
        $needs_auto_storage = false;
        foreach ( $mappings as $mapping )
        {
            $target_field_id = isset( $mapping['storage_target_field_id'] ) && is_scalar( $mapping['storage_target_field_id'] )
                ? sanitize_text_field( (string) $mapping['storage_target_field_id'] )
                : '';

            if ( '' === $target_field_id || ! $this->form_has_realtime_storage_target( $form, $target_field_id ) )
            {
                $needs_auto_storage = true;
                break;
            }
        }

        if ( ! $needs_auto_storage )
        {
            return [
                'form'     => $form,
                'mappings' => $mappings,
            ];
        }

        $storage = $this->ensure_realtime_storage_field( $form );
        $form    = $storage['form'];

        if ( '' === $storage['field_id'] )
        {
            return [
                'form'     => $form,
                'mappings' => $mappings,
            ];
        }

        foreach ( $mappings as $index => $mapping )
        {
            $target_field_id = isset( $mapping['storage_target_field_id'] ) && is_scalar( $mapping['storage_target_field_id'] )
                ? sanitize_text_field( (string) $mapping['storage_target_field_id'] )
                : '';

            if ( '' !== $target_field_id && $this->form_has_realtime_storage_target( $form, $target_field_id ) )
            {
                continue;
            }

            $mappings[ $index ]['storage_target_field_id'] = $storage['field_id'];
        }

        return [
            'form'     => $form,
            'mappings' => $mappings,
        ];
    }

    /**
     * @param array<string,mixed> $form
     *
     * @return array{form: array<string,mixed>, field_id: string, created: bool}
     */
    private function ensure_realtime_storage_field( array $form ): array
    {
        $existing_field_id = $this->find_realtime_storage_field_id( $form );
        if ( '' !== $existing_field_id )
        {
            return [
                'form'     => $form,
                'field_id' => $existing_field_id,
                'created'  => false,
            ];
        }

        $form_id = isset( $form['id'] ) ? absint( $form['id'] ) : 0;
        if ( $form_id <= 0 )
        {
            return [
                'form'     => $form,
                'field_id' => '',
                'created'  => false,
            ];
        }

        $fields       = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : [];
        $new_field_id = $this->next_realtime_storage_field_id( $fields );
        $fields[]     = $this->create_realtime_storage_field( $new_field_id, $form );
        $form['fields'] = $fields;

        if ( class_exists( 'GFAPI' ) && method_exists( 'GFAPI', 'update_form' ) )
        {
            $updated = GFAPI::update_form( $form, $form_id );
            if ( is_wp_error( $updated ) )
            {
                do_action(
                    'sentient_forms_realtime_storage_field_update_failed',
                    $form_id,
                    $new_field_id,
                    $updated
                );
            }
        }

        return [
            'form'     => $form,
            'field_id' => (string) $new_field_id,
            'created'  => true,
        ];
    }

    /**
     * @param array<string,mixed> $form
     */
    private function find_realtime_storage_field_id( array $form ): string
    {
        $fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : [];
        foreach ( $fields as $field )
        {
            $field_id = $this->extract_gravity_field_property( $field, 'id' );
            if ( '' === $field_id || ! $this->is_realtime_storage_field( $field ) )
            {
                continue;
            }

            return $field_id;
        }

        return '';
    }

    private function is_realtime_storage_field( mixed $field ): bool
    {
        $field_type = strtolower( $this->extract_gravity_field_property( $field, 'type' ) );
        if ( ! in_array( $field_type, [ 'hidden', 'textarea' ], true ) )
        {
            return false;
        }

        $input_name = $this->extract_gravity_field_property( $field, 'inputName' );
        if ( self::REALTIME_QNA_STORAGE_FIELD_INPUT_NAME === $input_name )
        {
            return true;
        }

        if ( $this->extract_gravity_field_property( $field, 'sentientFormsRealtimeStorage' ) === '1' )
        {
            return true;
        }

        $css_class = $this->extract_gravity_field_property( $field, 'cssClass' );
        if ( str_contains( ' ' . $css_class . ' ', ' ' . self::REALTIME_QNA_STORAGE_FIELD_CLASS . ' ' ) )
        {
            return true;
        }

        $admin_label = $this->extract_gravity_field_property( $field, 'adminLabel' );
        $label       = $this->extract_gravity_field_property( $field, 'label' );

        return self::REALTIME_QNA_STORAGE_FIELD_LABEL === $admin_label
            || self::REALTIME_QNA_STORAGE_FIELD_LABEL === $label;
    }

    /**
     * @param array<string,mixed> $form
     */
    private function form_has_realtime_storage_target( array $form, string $field_id ): bool
    {
        if ( '' === trim( $field_id ) )
        {
            return false;
        }

        $fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : [];
        foreach ( $fields as $field )
        {
            if ( $field_id !== $this->extract_gravity_field_property( $field, 'id' ) )
            {
                continue;
            }

            return in_array(
                strtolower( $this->extract_gravity_field_property( $field, 'type' ) ),
                [ 'hidden', 'textarea' ],
                true
            );
        }

        return false;
    }

    /**
     * @param array<int,mixed> $fields
     */
    private function next_realtime_storage_field_id( array $fields ): int
    {
        $max_id = 0;
        foreach ( $fields as $field )
        {
            $field_id = $this->extract_gravity_field_property( $field, 'id' );
            if ( '' === $field_id || ! is_numeric( $field_id ) )
            {
                continue;
            }

            $max_id = max( $max_id, (int) floor( (float) $field_id ) );
        }

        return $max_id + 1;
    }

    /**
     * @param array<string,mixed> $form
     */
    private function create_realtime_storage_field( int $field_id, array $form ): mixed
    {
        $page_number = 1;
        $fields      = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : [];
        foreach ( $fields as $field )
        {
            $field_page_number = $this->extract_gravity_field_property( $field, 'pageNumber' );
            if ( '' !== $field_page_number && is_numeric( $field_page_number ) )
            {
                $page_number = max( $page_number, (int) $field_page_number );
            }
        }

        $properties = [
            'id'                           => $field_id,
            'type'                         => 'hidden',
            'label'                        => self::REALTIME_QNA_STORAGE_FIELD_LABEL,
            'adminLabel'                   => self::REALTIME_QNA_STORAGE_FIELD_LABEL,
            'inputName'                    => self::REALTIME_QNA_STORAGE_FIELD_INPUT_NAME,
            'defaultValue'                 => '',
            'description'                  => __( 'Stores Sentient Forms realtime clarification questions and visitor answers as native Gravity Forms entry data.', 'sentient-forms' ),
            'isRequired'                   => false,
            'allowsPrepopulate'            => false,
            'visibility'                   => 'visible',
            'cssClass'                     => self::REALTIME_QNA_STORAGE_FIELD_CLASS,
            'pageNumber'                   => $page_number,
            'sentientFormsRealtimeStorage' => true,
        ];

        if ( class_exists( 'GF_Fields' ) && method_exists( 'GF_Fields', 'create' ) )
        {
            return GF_Fields::create( $properties );
        }

        return (object) $properties;
    }

    private function extract_gravity_field_property( mixed $field, string $property ): string
    {
        if ( is_array( $field ) && isset( $field[ $property ] ) && is_scalar( $field[ $property ] ) )
        {
            if ( is_bool( $field[ $property ] ) )
            {
                return $field[ $property ] ? '1' : '0';
            }

            return sanitize_text_field( (string) $field[ $property ] );
        }

        if ( is_object( $field ) && isset( $field->{$property} ) && is_scalar( $field->{$property} ) )
        {
            if ( is_bool( $field->{$property} ) )
            {
                return $field->{$property} ? '1' : '0';
            }

            return sanitize_text_field( (string) $field->{$property} );
        }

        return '';
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
            if ( self::REALTIME_ACTION_ID !== sanitize_key( $central_action_id ) )
            {
                continue;
            }

            $mapping_id = '';
            foreach ( [ 'id', 'local_mapping_id' ] as $mapping_id_key )
            {
                if ( isset( $action[ $mapping_id_key ] ) && is_scalar( $action[ $mapping_id_key ] ) )
                {
                    $mapping_id = sanitize_text_field( (string) $action[ $mapping_id_key ] );
                    break;
                }
            }
            if ( '' === $mapping_id )
            {
                $mapping_id = substr( hash( 'sha256', wp_json_encode( $action ) ), 0, 16 );
            }

            $realtime_settings = $this->normalize_realtime_runtime_settings(
                isset( $settings['realtime_settings'] ) && is_array( $settings['realtime_settings'] )
                    ? $settings['realtime_settings']
                    : []
            );

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
                'auto_refresh_enabled'  => $realtime_settings['auto_refresh_enabled'],
                'field_checkpoints_enabled' => $realtime_settings['field_checkpoints_enabled'],
                'manual_refresh_enabled'=> $realtime_settings['manual_refresh_enabled'],
                'checkpoint_field_ids'  => $realtime_settings['checkpoint_field_ids'],
                'page_checkpoints_enabled' => $realtime_settings['page_checkpoints_enabled'],
                'page_checkpoint_mode'  => $realtime_settings['page_checkpoint_mode'],
                'page_checkpoint_pages' => $realtime_settings['page_checkpoint_pages'],
                'page_checkpoint_timeout_ms' => $realtime_settings['page_checkpoint_timeout_ms'],
                'storage_target_field_id' => $realtime_settings['storage_target_field_id'],
                'blocking_mode'         => $realtime_settings['blocking_mode'],
                'refresh_mode'          => $realtime_settings['refresh_mode'],
                'initial_panel_state'   => $realtime_settings['initial_panel_state'],
                'hidden_field_exposure_mode' => $realtime_settings['hidden_field_exposure_mode'],
                'pre_submit_run_enabled'=> $realtime_settings['pre_submit_run_enabled'],
                'pre_submit_timeout_ms' => $realtime_settings['pre_submit_timeout_ms'],
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

	            $input_ids = [];
	            if ( isset( $field->inputs ) && is_array( $field->inputs ) )
	            {
	                foreach ( $field->inputs as $input )
	                {
	                    $input_id = is_array( $input ) && isset( $input['id'] )
	                        ? sanitize_text_field( (string) $input['id'] )
	                        : ( is_object( $input ) && isset( $input->id ) ? sanitize_text_field( (string) $input->id ) : '' );
	                    if ( '' !== $input_id )
	                    {
	                        $input_ids[] = $input_id;
	                    }
	                }
	            }

	            $manifest[] = [
	                'field_id'   => $field_id,
	                'label'      => isset( $field->label ) ? sanitize_text_field( (string) $field->label ) : '',
	                'type'       => $field_type,
	                'input_ids'  => array_values( array_unique( $input_ids ) ),
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

    /**
     * @param array<string,mixed> $settings
     *
     * @return array<string,mixed>
     */
    private function normalize_realtime_runtime_settings( array $settings ): array
    {
        $legacy_refresh_mode = isset( $settings['refresh_mode'] ) && is_scalar( $settings['refresh_mode'] )
            ? sanitize_key( (string) $settings['refresh_mode'] )
            : 'auto';
        if ( ! in_array( $legacy_refresh_mode, [ 'auto', 'checkpoint', 'manual' ], true ) )
        {
            $legacy_refresh_mode = 'auto';
        }

        $auto_refresh_enabled = array_key_exists( 'auto_refresh_enabled', $settings )
            ? rest_sanitize_boolean( $settings['auto_refresh_enabled'] )
            : 'auto' === $legacy_refresh_mode;
        $field_checkpoints_enabled = array_key_exists( 'field_checkpoints_enabled', $settings )
            ? rest_sanitize_boolean( $settings['field_checkpoints_enabled'] )
            : 'checkpoint' === $legacy_refresh_mode;
        $page_checkpoints_enabled = array_key_exists( 'page_checkpoints_enabled', $settings )
            ? rest_sanitize_boolean( $settings['page_checkpoints_enabled'] )
            : false;
        $refresh_mode = $auto_refresh_enabled
            ? 'auto'
            : ( $field_checkpoints_enabled || $page_checkpoints_enabled ? 'checkpoint' : 'manual' );

        $initial_panel_state = isset( $settings['initial_panel_state'] ) && is_scalar( $settings['initial_panel_state'] )
            ? sanitize_key( (string) $settings['initial_panel_state'] )
            : 'minimized';
        if ( ! in_array( $initial_panel_state, [ 'open', 'minimized', 'hidden_until_interaction' ], true ) )
        {
            $initial_panel_state = 'minimized';
        }

        $hidden_field_exposure_mode = isset( $settings['hidden_field_exposure_mode'] ) && is_scalar( $settings['hidden_field_exposure_mode'] )
            ? sanitize_key( (string) $settings['hidden_field_exposure_mode'] )
            : 'label_hidden';
        if ( ! in_array( $hidden_field_exposure_mode, self::REALTIME_HIDDEN_FIELD_EXPOSURE_MODES, true ) )
        {
            $hidden_field_exposure_mode = 'label_hidden';
        }

        $blocking_mode = isset( $settings['blocking_mode'] )
            && 'require_answers' === sanitize_key( (string) $settings['blocking_mode'] )
            ? 'require_answers'
            : 'advisory';

        return [
            'auto_refresh_enabled'       => $auto_refresh_enabled,
            'field_checkpoints_enabled'  => $field_checkpoints_enabled,
            'checkpoint_field_ids'       => $this->sanitize_realtime_string_list( $settings['checkpoint_field_ids'] ?? [] ),
            'page_checkpoints_enabled'   => $page_checkpoints_enabled,
            'page_checkpoint_mode'       => $this->normalize_realtime_page_checkpoint_mode( $settings['page_checkpoint_mode'] ?? '' ),
            'page_checkpoint_pages'      => $this->sanitize_realtime_positive_int_list( $settings['page_checkpoint_pages'] ?? [] ),
            'page_checkpoint_timeout_ms' => $this->normalize_realtime_millis(
                $settings['page_checkpoint_timeout_ms'] ?? self::REALTIME_DEFAULT_PAGE_CHECKPOINT_TIMEOUT_MS,
                self::REALTIME_MIN_PRE_SUBMIT_TIMEOUT_MS,
                self::REALTIME_MAX_PRE_SUBMIT_TIMEOUT_MS
            ),
            'storage_target_field_id'    => isset( $settings['storage_target_field_id'] ) && is_scalar( $settings['storage_target_field_id'] )
                ? sanitize_text_field( (string) $settings['storage_target_field_id'] )
                : '',
            'debounce_ms'                => $this->normalize_realtime_millis(
                $settings['debounce_ms'] ?? self::REALTIME_DEFAULT_DEBOUNCE_MS,
                self::REALTIME_MIN_DEBOUNCE_MS,
                self::REALTIME_MAX_DEBOUNCE_MS
            ),
            'cooldown_ms'                => $this->normalize_realtime_millis(
                $settings['cooldown_ms'] ?? self::REALTIME_DEFAULT_COOLDOWN_MS,
                self::REALTIME_MIN_COOLDOWN_MS,
                self::REALTIME_MAX_COOLDOWN_MS
            ),
            'manual_refresh_enabled'     => array_key_exists( 'manual_refresh_enabled', $settings )
                ? rest_sanitize_boolean( $settings['manual_refresh_enabled'] )
                : true,
            'blocking_mode'              => $blocking_mode,
            'refresh_mode'               => $refresh_mode,
            'initial_panel_state'        => $initial_panel_state,
            'hidden_field_exposure_mode' => $hidden_field_exposure_mode,
            'pre_submit_run_enabled'     => array_key_exists( 'pre_submit_run_enabled', $settings )
                ? rest_sanitize_boolean( $settings['pre_submit_run_enabled'] )
                : false,
            'pre_submit_timeout_ms'      => $this->normalize_realtime_millis(
                $settings['pre_submit_timeout_ms'] ?? self::REALTIME_DEFAULT_PRE_SUBMIT_TIMEOUT_MS,
                self::REALTIME_MIN_PRE_SUBMIT_TIMEOUT_MS,
                self::REALTIME_MAX_PRE_SUBMIT_TIMEOUT_MS
            ),
        ];
    }

    private function normalize_realtime_page_checkpoint_mode( mixed $value ): string
    {
        $mode = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';

        return in_array( $mode, self::REALTIME_PAGE_CHECKPOINT_MODES, true ) ? $mode : 'all_pages';
    }

    /**
     * @return array<int,string>
     */
    private function sanitize_realtime_string_list( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $items = [];
        foreach ( $value as $item )
        {
            if ( ! is_scalar( $item ) )
            {
                continue;
            }

            $normalized = trim( sanitize_text_field( (string) $item ) );
            if ( '' !== $normalized )
            {
                $items[] = $normalized;
            }
        }

        return array_values( array_unique( $items ) );
    }

    /**
     * @return array<int,int>
     */
    private function sanitize_realtime_positive_int_list( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $items = [];
        foreach ( $value as $item )
        {
            if ( ! is_scalar( $item ) )
            {
                continue;
            }

            $page = absint( $item );
            if ( $page > 0 )
            {
                $items[] = min( 200, $page );
            }
        }

        $items = array_values( array_unique( $items ) );
        sort( $items );

        return $items;
    }

    /**
     * Sanitize an inherited realtime config without turning missing keys into overrides.
     *
     * @param array<string,mixed> $settings
     *
     * @return array<string,mixed>
     */
    private function sanitize_realtime_runtime_settings_partial( array $settings ): array
    {
        $normalized = $this->normalize_realtime_runtime_settings( $settings );
        $allowed_keys = [
            'auto_refresh_enabled',
            'field_checkpoints_enabled',
            'checkpoint_field_ids',
            'page_checkpoints_enabled',
            'page_checkpoint_mode',
            'page_checkpoint_pages',
            'page_checkpoint_timeout_ms',
            'storage_target_field_id',
            'debounce_ms',
            'cooldown_ms',
            'manual_refresh_enabled',
            'blocking_mode',
            'refresh_mode',
            'initial_panel_state',
            'hidden_field_exposure_mode',
            'pre_submit_run_enabled',
            'pre_submit_timeout_ms',
        ];

        $partial = [];
        foreach ( $allowed_keys as $key )
        {
            if ( array_key_exists( $key, $settings ) )
            {
                $partial[ $key ] = $normalized[ $key ];
            }
        }

        return $partial;
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
        $missing     = new stdClass();
        $settings    = get_option( $option_name, $missing );

        if ( $missing === $settings )
        {
            // Fallback to legacy option naming for backwards compatibility.
            $settings = get_option( 'sentient_forms_gravity_forms_' . $form_id, $missing );
        }

        if ( ! is_array( $settings ) )
        {
            $settings = [];
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

        $settings = $this->normalize_option_backed_action_wrapper( $settings );

        return $this->merge_local_first_form_mappings( $settings, $form_id );
    }

    /**
     * Keep the legacy top-level mapping shape and the wrapped `actions` shape in sync for reads.
     *
     * Older admin flows can leave duplicate mappings in both locations. The REST list endpoint
     * overlays top-level mappings last, so mirror that policy for runtime reads before the
     * execution planner or realtime frontend config consumes `actions`.
     *
     * @param array<string,mixed> $settings Stored form settings.
     *
     * @return array<string,mixed>
     */
    private function normalize_option_backed_action_wrapper( array $settings ): array
    {
        $actions = isset( $settings['actions'] ) && is_array( $settings['actions'] )
            ? $settings['actions']
            : [];

        foreach ( $settings as $mapping_key => $mapping )
        {
            if ( in_array( (string) $mapping_key, [ 'actions', 'enabled', 'sf_disabled' ], true ) )
            {
                continue;
            }

            if ( ! is_array( $mapping ) || ! isset( $mapping['central_action_id'] ) )
            {
                continue;
            }

            $mapping_id = isset( $mapping['local_mapping_id'] ) && is_scalar( $mapping['local_mapping_id'] )
                ? sanitize_text_field( (string) $mapping['local_mapping_id'] )
                : sanitize_text_field( (string) $mapping_key );
            if ( '' === $mapping_id )
            {
                continue;
            }

            $actions[ $mapping_id ] = $mapping;
        }

        if ( ! empty( $actions ) )
        {
            $settings['actions'] = $actions;
        }

        return $settings;
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

        $resolved         = $this->get_runtime_settings_resolver()->resolve_mapping( $mapping, $this->get_id(), $form_id );
        $mapping_settings = isset( $resolved['settings'] ) && is_array( $resolved['settings'] ) ? $resolved['settings'] : [];

        if ( self::REALTIME_ACTION_ID === $action_id )
        {
            $action_defaults = $this->get_action_defaults_config( $action_id );
            $form_config     = $this->get_form_action_config( $this->get_id(), $form_id, $action_id );
            $mapping_settings = $this->merge_realtime_settings(
                $mapping_settings,
                $form_config,
                $action_defaults
            );
        }

        $resolved['settings'] = $mapping_settings;

        return $resolved;
    }

    private function get_runtime_settings_resolver(): Sentient_Forms_Action_Runtime_Settings_Resolver
    {
        if ( null === $this->runtime_settings_resolver )
        {
            $this->runtime_settings_resolver = new Sentient_Forms_Action_Runtime_Settings_Resolver();
        }

        return $this->runtime_settings_resolver;
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

        $raw_hook = sanitize_key( (string) ( $row['hook'] ?? '' ) );
        $lifecycle_id = Sentient_Forms_Form_Source_Lifecycles::normalize_id( $raw_hook );
        if ( null === $lifecycle_id )
        {
            return null;
        }

        $hook = match ( $lifecycle_id ) {
            Sentient_Forms_Form_Source_Lifecycles::VALIDATION => 'gform_validation',
            Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION => 'gform_after_submission',
            default => 'real_time',
        };

        if ( 'custom_action' !== sanitize_key( (string) ( $row['action_kind'] ?? '' ) ) )
        {
            return null;
        }

        $custom_action = $this->get_local_first_custom_action( absint( $row['action_id'] ?? 0 ) );
        $identity      = $this->resolve_local_first_action_identity( $custom_action );
        $row_execution_mode = sanitize_key( (string) ( $row['execution_mode'] ?? '' ) );
        $execution_mode     = match ( true ) {
            Sentient_Forms_Form_Source_Lifecycles::REAL_TIME === $lifecycle_id || 'real_time' === $row_execution_mode => 'real_time',
            Sentient_Forms_Form_Source_Lifecycles::VALIDATION === $lifecycle_id || 'sync' === $row_execution_mode => 'validation',
            default => 'after_submission',
        };

        $settings = is_array( $row['settings_json'] ?? null )
            ? $row['settings_json']
            : [];

        $settings['local_form_mapping_id'] = $id;
        $settings['execution_mode']        = $execution_mode;
        $settings['input_mapping']         = is_array( $row['input_bindings_json'] ?? null )
            ? $row['input_bindings_json']
            : [];
        if ( ! isset( $settings['trigger_sources'] ) || ! is_array( $settings['trigger_sources'] ) )
        {
            $settings['trigger_sources'] = [
                $hook => [ 'type' => 'hook_root' ],
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

        foreach ( [ 'suppress_notifications_on_spam', 'suppress_webhooks_on_spam', 'skip_downstream_on_spam' ] as $field )
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

        if ( array_key_exists( 'realtime_settings', $config ) )
        {
            $config['realtime_settings'] = $this->sanitize_realtime_runtime_settings_partial(
                is_array( $config['realtime_settings'] ) ? $config['realtime_settings'] : []
            );
        }

        return $config;
    }

    private function normalize_spam_result_display_mode( mixed $value ): string
    {
        $value = sanitize_key( (string) $value );

        return match ( $value ) {
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
     * Merge realtime settings from action defaults, form defaults, and mapping overrides.
     *
     * @param array<string, mixed> $resolved        Current resolved mapping settings.
     * @param array<string, mixed> $form_config     Form-level action config.
     * @param array<string, mixed> $action_defaults Action-level defaults.
     *
     * @return array<string, mixed>
     */
    private function merge_realtime_settings( array $resolved, array $form_config, array $action_defaults ): array
    {
        $action_settings = isset( $action_defaults['realtime_settings'] ) && is_array( $action_defaults['realtime_settings'] )
            ? $action_defaults['realtime_settings']
            : [];
        $form_settings = isset( $form_config['realtime_settings'] ) && is_array( $form_config['realtime_settings'] )
            ? $form_config['realtime_settings']
            : [];
        $mapping_settings = isset( $resolved['realtime_settings'] ) && is_array( $resolved['realtime_settings'] )
            ? $resolved['realtime_settings']
            : [];

        $resolved['realtime_settings'] = $this->normalize_realtime_runtime_settings(
            array_merge( $action_settings, $form_settings, $mapping_settings )
        );

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

        if ( $this->is_local_mapping_completion_context( $context ) )
        {
            $this->resolve_deferred_delivery_after_async_completion(
                $context,
                fn (): bool => $this->should_suppress_deferred_notifications_from_result( $context, $result ),
                fn (): bool => $this->should_suppress_deferred_webhooks_from_result( $context, $result ),
            );
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
        $structured_valid = $this->extract_structured_output_valid( $result );
        $this->persist_entry_runtime_meta( $entry_id, 'sentient_forms_structured_output_valid', $structured_valid ? '1' : '0' );

        $this->maybe_persist_realtime_clarification_late_result( $entry_id, $context, $result );

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

        $this->resolve_deferred_delivery_after_async_completion(
            $context,
            fn (): bool => $this->should_suppress_deferred_notifications_from_result( $context, $result ),
            fn (): bool => $this->should_suppress_deferred_webhooks_from_result( $context, $result ),
        );
    }

    /**
     * Resolve deferred delivery channels independently after async completion.
     *
     * @param array<string, mixed> $context                      Async job context.
     * @param callable():bool      $should_suppress_notifications Notification suppression decision.
     * @param callable():bool      $should_suppress_webhooks      Webhook suppression decision.
     */
    private function resolve_deferred_delivery_after_async_completion(
        array $context,
        callable $should_suppress_notifications,
        callable $should_suppress_webhooks
    ): void
    {
        try
        {
            $this->resolve_deferred_notifications_after_async_completion( $context, $should_suppress_notifications() );
        } catch ( Throwable $throwable )
        {
            $this->log_deferred_delivery_resolution_exception( 'notifications', $context, $throwable );
        }

        try
        {
            $this->resolve_deferred_webhooks_after_async_completion( $context, $should_suppress_webhooks() );
        } catch ( Throwable $throwable )
        {
            $this->log_deferred_delivery_resolution_exception( 'webhooks', $context, $throwable );
        }
    }

    /**
     * Log an identifier-only deferred delivery resolver failure.
     *
     * @param string                   $channel   Deferred delivery channel.
     * @param array<string, mixed>     $context   Async job context.
     * @param Throwable                $throwable Resolver failure.
     */
    private function log_deferred_delivery_resolution_exception(
        string $channel,
        array $context,
        Throwable $throwable
    ): void
    {
        $this->plugin->get_logger()->error(
            'deferred async delivery resolution failed',
            [
                'channel'        => sanitize_key( $channel ),
                'form_id'        => absint( $context['form_id'] ?? 0 ),
                'entry_id'       => absint( $context['entry_id'] ?? 0 ),
                'mapping_id'     => $this->resolve_deferred_notification_mapping_id( $context ),
                'exception_type' => get_class( $throwable ),
            ]
        );
    }

    /**
     * Determine whether adapter finalization follows a completed local-first mapping.
     *
     * Local mappings apply configured native effects before adapter finalization. The
     * adapter owns only deferred delivery resolution for this completion path.
     *
     * @param array<string, mixed> $context Async job context.
     */
    private function is_local_mapping_completion_context( array $context ): bool
    {
        return 'local_mapping' === sanitize_key( (string) ( $context['job_type'] ?? '' ) );
    }

    private function maybe_persist_realtime_clarification_late_result( int $entry_id, array $context, array $result ): void
    {
        if ( $entry_id <= 0 || ! $this->is_realtime_clarification_result( $context, $result ) )
        {
            return;
        }

        $output = $this->extract_realtime_clarification_output( $result );
        if ( [] === $output )
        {
            return;
        }

        $timing   = $this->build_realtime_clarification_timing_meta( $entry_id, $context, $result );
        $questions = $this->normalize_realtime_clarification_questions(
            $output['virtual_questions'] ?? [],
            $timing
        );
        $has_decisions = array_key_exists( 'conditional_decisions', $output );
        $decisions     = $has_decisions
            ? $this->normalize_realtime_clarification_decisions( $output['conditional_decisions'] )
            : [];
        if ( [] === $questions && [] === $decisions )
        {
            return;
        }

        $entry = $this->get_entry_record( $entry_id );
        if ( ! is_array( $entry ) )
        {
            return;
        }

        $storage_field_id = $this->resolve_realtime_qna_storage_field_id( $entry, $context );
        if ( '' === $storage_field_id )
        {
            return;
        }

        $existing_payload = $this->decode_realtime_qna_payload( $entry[ $storage_field_id ] ?? null );
        $mapping          = array_merge(
            [
                'mapping_id'        => $this->resolve_realtime_context_identifier( $context, [ 'mapping_id', 'local_mapping_id', 'action_id' ] ),
                'central_action_id' => self::REALTIME_ACTION_ID,
                'action_name_label' => $this->resolve_realtime_action_label( $context ),
                'questions'         => $questions,
            ],
            $timing
        );
        if ( $has_decisions )
        {
            $mapping['conditional_decisions'] = $decisions;
        }

        $payload = $this->merge_realtime_qna_mapping(
            $existing_payload,
            $mapping,
            (string) ( $entry['form_id'] ?? $context['form_id'] ?? '' ),
            $timing['returned_at'] ?? ''
        );

        $encoded = wp_json_encode( $payload );
        if ( ! is_string( $encoded ) || '' === $encoded )
        {
            return;
        }

        $entry[ $storage_field_id ] = $encoded;
        $this->persist_realtime_qna_entry_field( $entry_id, $storage_field_id, $entry, $encoded );
    }

    private function persist_realtime_qna_entry_field( int $entry_id, string $storage_field_id, array $entry, string $encoded ): void
    {
        if ( ! class_exists( 'GFAPI' ) )
        {
            return;
        }

        if ( is_callable( [ 'GFAPI', 'update_entry_field' ] ) )
        {
            try
            {
                $field_result = GFAPI::update_entry_field( $entry_id, $storage_field_id, $encoded );
                if ( ! is_wp_error( $field_result ) && false !== $field_result )
                {
                    return;
                }

                if ( is_wp_error( $field_result ) )
                {
                    sentient_forms_debug_log(
                        'Sentient Forms could not persist late realtime clarification questions with update_entry_field.',
                        [
                            'entry_id' => $entry_id,
                            'field_id' => $storage_field_id,
                            'error'    => $field_result->get_error_message(),
                        ]
                    );
                }
            }
            catch ( Exception $e )
            {
                sentient_forms_debug_log(
                    'Sentient Forms could not persist late realtime clarification questions with update_entry_field.',
                    [
                        'entry_id' => $entry_id,
                        'field_id' => $storage_field_id,
                        'error'    => $e->getMessage(),
                    ]
                );
            }
        }

        if ( ! is_callable( [ 'GFAPI', 'update_entry' ] ) )
        {
            return;
        }

        try
        {
            $entry_result = GFAPI::update_entry( $entry );
            if ( is_wp_error( $entry_result ) )
            {
                sentient_forms_debug_log(
                    'Sentient Forms could not persist late realtime clarification questions with update_entry.',
                    [
                        'entry_id' => $entry_id,
                        'field_id' => $storage_field_id,
                        'error'    => $entry_result->get_error_message(),
                    ]
                );
            }
        }
        catch ( Exception $e )
        {
            sentient_forms_debug_log(
                'Sentient Forms could not persist late realtime clarification questions with update_entry.',
                [
                    'entry_id' => $entry_id,
                    'field_id' => $storage_field_id,
                    'error'    => $e->getMessage(),
                ]
            );
        }
    }

    private function is_realtime_clarification_result( array $context, array $result ): bool
    {
        $candidates = [
            $context['central_action_id'] ?? null,
            $context['action_id'] ?? null,
            $result['central_action_id'] ?? null,
            $result['meta']['action_template_code'] ?? null,
            $result['evaluation_payload']['meta']['action_template_code'] ?? null,
        ];

        foreach ( $candidates as $candidate )
        {
            if ( is_scalar( $candidate ) && self::REALTIME_ACTION_ID === sanitize_key( (string) $candidate ) )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function extract_realtime_clarification_output( array $result ): array
    {
        $candidates = [
            $result,
            $result['result'] ?? null,
            $result['result']['structured_output'] ?? null,
            $result['result_data'] ?? null,
            $result['result_data']['structured_output'] ?? null,
            $result['evaluation_payload'] ?? null,
            $result['evaluation_payload']['result'] ?? null,
            $result['evaluation_payload']['result']['structured_output'] ?? null,
            $result['evaluation_payload']['result_data'] ?? null,
            $result['evaluation_payload']['result_data']['structured_output'] ?? null,
        ];

        foreach (
            [
                $result['llm_output'] ?? null,
                $result['result']['llm_output'] ?? null,
                $result['result_data']['llm_output'] ?? null,
                $result['evaluation_payload']['llm_output'] ?? null,
                $result['evaluation_payload']['result']['llm_output'] ?? null,
                $result['evaluation_payload']['result_data']['llm_output'] ?? null,
            ] as $llm_output
        )
        {
            $decoded = $this->decode_realtime_clarification_llm_output( $llm_output );
            if ( [] !== $decoded )
            {
                $candidates[] = $decoded;
            }
        }

        foreach ( $candidates as $candidate )
        {
            if (
                is_array( $candidate )
                && (
                    isset( $candidate['virtual_questions'] )
                    || isset( $candidate['conditional_decisions'] )
                )
            )
            {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @return array<string,mixed>
     */
    private function decode_realtime_clarification_llm_output( mixed $value ): array
    {
        if ( ! is_scalar( $value ) )
        {
            return [];
        }

        $raw = trim( (string) $value );
        if ( '' === $raw )
        {
            return [];
        }

        if ( str_starts_with( $raw, '```' ) )
        {
            $raw = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $raw );
            $raw = is_string( $raw ) ? trim( $raw ) : '';
        }

        $decoded = json_decode( $raw, true );

        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * @param mixed $raw_questions
     * @param array<string,mixed> $timing
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalize_realtime_clarification_questions( mixed $raw_questions, array $timing ): array
    {
        if ( ! is_array( $raw_questions ) )
        {
            return [];
        }

        $questions = [];
        foreach ( $raw_questions as $question )
        {
            if ( ! is_array( $question ) )
            {
                continue;
            }

            $question_text = $this->stringify_post_execution_value( $question['question'] ?? '' );
            if ( '' === trim( $question_text ) )
            {
                continue;
            }

            $question_id = $this->stringify_post_execution_value( $question['question_id'] ?? '' );
            if ( '' === $question_id )
            {
                $question_id = substr( hash( 'sha256', $question_text ), 0, 16 );
            }

            $questions[] = array_merge(
                [
                    'question_id'     => sanitize_text_field( $question_id ),
                    'question'        => sanitize_textarea_field( $question_text ),
                    'reason'          => sanitize_textarea_field( $this->stringify_post_execution_value( $question['reason'] ?? '' ) ),
                    'target_field_id' => sanitize_text_field( $this->stringify_post_execution_value( $question['target_field_id'] ?? '' ) ),
                    'required'        => rest_sanitize_boolean( $question['required'] ?? false ),
                    'answer_type'     => sanitize_key( $this->stringify_post_execution_value( $question['answer_type'] ?? 'long_text' ) ),
                    'choices'         => $this->sanitize_realtime_question_choices( $question['choices'] ?? [] ),
                    'answer'          => sanitize_textarea_field( $this->stringify_post_execution_value( $question['answer'] ?? '' ) ),
                    'completed'       => rest_sanitize_boolean( $question['completed'] ?? false ),
                ],
                $timing
            );
        }

        return $questions;
    }

    /**
     * @return array<int,string>
     */
    private function sanitize_realtime_question_choices( mixed $choices ): array
    {
        if ( ! is_array( $choices ) )
        {
            return [];
        }

        $sanitized = [];
        foreach ( $choices as $choice )
        {
            if ( ! is_scalar( $choice ) )
            {
                continue;
            }

            $value = sanitize_text_field( (string) $choice );
            if ( '' !== $value )
            {
                $sanitized[] = $value;
            }
        }

        return array_values( array_unique( $sanitized ) );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalize_realtime_clarification_decisions( mixed $raw_decisions ): array
    {
        if ( ! is_array( $raw_decisions ) )
        {
            return [];
        }

        $decisions = [];
        foreach ( $raw_decisions as $decision )
        {
            if ( is_array( $decision ) )
            {
                $decisions[] = Sentient_Forms_Local_Data_Governance::sanitize_execution_payload_for_storage( $decision );
            }
        }

        return $decisions;
    }

    /**
     * @return array<string,mixed>
     */
    private function build_realtime_clarification_timing_meta( int $entry_id, array $context, array $result ): array
    {
        $entry        = $this->get_entry_record( $entry_id ) ?? [];
        $submitted_at = $this->first_scalar_value(
            [
                $context['submitted_at'] ?? null,
                $context['submission_submitted_at'] ?? null,
                $context['suggestion_context']['submitted_at'] ?? null,
                $entry['date_created'] ?? null,
            ]
        );
        $returned_at  = $this->first_scalar_value(
            [
                $result['returned_at'] ?? null,
                $result['meta']['returned_at'] ?? null,
                $result['evaluation_payload']['returned_at'] ?? null,
                $result['evaluation_payload']['meta']['returned_at'] ?? null,
            ]
        );
        if ( '' === $returned_at )
        {
            $returned_at = gmdate( 'c' );
        }

        $submitted_ms      = $this->parse_realtime_timestamp_ms( $submitted_at );
        $returned_ms       = $this->parse_realtime_timestamp_ms( $returned_at );
        $returned_after_ms = null;
        if ( null !== $submitted_ms && null !== $returned_ms )
        {
            $returned_after_ms = max( 0, $returned_ms - $submitted_ms );
        }

        $meta = [
            'submitted_at'          => sanitize_text_field( $submitted_at ),
            'returned_at'           => sanitize_text_field( $returned_at ),
            'returned_after_ms'     => $returned_after_ms,
            'late_after_submission' => null !== $returned_after_ms && $returned_after_ms > 0,
            'execution_request_id'  => $this->extract_execution_request_id_from_log( $context, $result ) ?? '',
            'timeout_source'        => sanitize_key(
                $this->first_scalar_value(
                    [
                        $context['timeout_source'] ?? null,
                        $context['request_reason'] ?? null,
                        $context['suggestion_context']['request_reason'] ?? null,
                    ]
                )
            ),
        ];

        $pre_submit_timeout_ms = $this->extract_realtime_pre_submit_timeout_ms( $context );
        if ( null !== $pre_submit_timeout_ms )
        {
            $meta['pre_submit_timeout_ms'] = $pre_submit_timeout_ms;
        }

        return $meta;
    }

    private function parse_realtime_timestamp_ms( string $timestamp ): ?int
    {
        if ( '' === trim( $timestamp ) )
        {
            return null;
        }

        try
        {
            $date = new DateTimeImmutable( $timestamp );
        }
        catch ( Exception $e )
        {
            return null;
        }

        return ( (int) $date->format( 'U' ) * 1000 ) + (int) floor( (int) $date->format( 'u' ) / 1000 );
    }

    /**
     * @param array<int,mixed> $candidates
     */
    private function first_scalar_value( array $candidates ): string
    {
        foreach ( $candidates as $candidate )
        {
            if ( is_scalar( $candidate ) )
            {
                $value = trim( (string) $candidate );
                if ( '' !== $value )
                {
                    return $value;
                }
            }
        }

        return '';
    }

    private function extract_realtime_pre_submit_timeout_ms( array $context ): ?int
    {
        $candidates = [
            $context['pre_submit_timeout_ms'] ?? null,
            $context['realtime_settings']['pre_submit_timeout_ms'] ?? null,
            $context['settings']['realtime_settings']['pre_submit_timeout_ms'] ?? null,
            $context['suggestion_context']['pre_submit_timeout_ms'] ?? null,
        ];

        foreach ( $candidates as $candidate )
        {
            if ( is_numeric( $candidate ) )
            {
                return max( 0, (int) $candidate );
            }
        }

        return null;
    }

    private function resolve_realtime_qna_storage_field_id( array $entry, array $context ): string
    {
        $form_id = isset( $entry['form_id'] ) ? absint( $entry['form_id'] ) : 0;
        if ( $form_id <= 0 || ! class_exists( 'GFAPI' ) || ! is_callable( [ 'GFAPI', 'get_form' ] ) )
        {
            return '';
        }

        $form = GFAPI::get_form( $form_id );
        if ( ! is_array( $form ) )
        {
            return '';
        }

        $candidates = [
            $context['storage_target_field_id'] ?? null,
            $context['realtime_settings']['storage_target_field_id'] ?? null,
            $context['settings']['realtime_settings']['storage_target_field_id'] ?? null,
            $context['suggestion_context']['storage_target_field_id'] ?? null,
        ];
        foreach ( $candidates as $candidate )
        {
            if ( ! is_scalar( $candidate ) )
            {
                continue;
            }

            $field_id = sanitize_text_field( (string) $candidate );
            if ( '' !== $field_id && $this->form_has_realtime_qna_storage_target( $form, $field_id ) )
            {
                return $field_id;
            }
        }

        return $this->find_realtime_storage_field_id( $form );
    }

    /**
     * @param array<string,mixed> $form
     */
    private function form_has_realtime_qna_storage_target( array $form, string $field_id ): bool
    {
        if ( '' === trim( $field_id ) )
        {
            return false;
        }

        $fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : [];
        foreach ( $fields as $field )
        {
            if ( $field_id !== $this->extract_gravity_field_property( $field, 'id' ) )
            {
                continue;
            }

            return $this->is_realtime_storage_field( $field );
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function decode_realtime_qna_payload( mixed $raw_value ): array
    {
        if ( ! is_string( $raw_value ) || '' === trim( $raw_value ) )
        {
            return [];
        }

        $decoded = json_decode( $raw_value, true );
        if ( ! is_array( $decoded ) || ( $decoded['schema'] ?? '' ) !== 'sentient_forms_realtime_clarification_qna.v1' )
        {
            return [];
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $mapping
     *
     * @return array<string,mixed>
     */
    private function merge_realtime_qna_mapping( array $payload, array $mapping, string $form_id, string $updated_at ): array
    {
        if ( [] === $payload )
        {
            $payload = [
                'schema'     => 'sentient_forms_realtime_clarification_qna.v1',
                'form_id'    => $form_id,
                'source'     => $this->get_id(),
                'updated_at' => $updated_at,
                'mappings'   => [],
            ];
        }

        $payload['schema']     = 'sentient_forms_realtime_clarification_qna.v1';
        $payload['form_id']    = $this->stringify_post_execution_value( $payload['form_id'] ?? $form_id );
        $payload['source']     = $this->stringify_post_execution_value( $payload['source'] ?? $this->get_id() );
        $payload['updated_at'] = $updated_at;
        $payload['mappings']   = isset( $payload['mappings'] ) && is_array( $payload['mappings'] )
            ? array_values( $payload['mappings'] )
            : [];

        $target_mapping_id = $this->stringify_post_execution_value( $mapping['mapping_id'] ?? '' );
        $replaced          = false;
        foreach ( $payload['mappings'] as $index => $existing_mapping )
        {
            if ( ! is_array( $existing_mapping ) )
            {
                continue;
            }

            $existing_mapping_id = $this->stringify_post_execution_value( $existing_mapping['mapping_id'] ?? '' );
            if ( '' === $target_mapping_id || $existing_mapping_id !== $target_mapping_id )
            {
                continue;
            }

            $mapping['questions'] = $this->merge_realtime_qna_questions(
                isset( $existing_mapping['questions'] ) && is_array( $existing_mapping['questions'] )
                    ? $existing_mapping['questions']
                    : [],
                isset( $mapping['questions'] ) && is_array( $mapping['questions'] )
                    ? $mapping['questions']
                    : []
            );
            $payload['mappings'][ $index ] = array_merge( $existing_mapping, $mapping );
            $replaced = true;
            break;
        }

        if ( ! $replaced )
        {
            $payload['mappings'][] = $mapping;
        }

        return $payload;
    }

    /**
     * @param array<int,mixed> $existing_questions
     * @param array<int,mixed> $new_questions
     *
     * @return array<int,array<string,mixed>>
     */
    private function merge_realtime_qna_questions( array $existing_questions, array $new_questions ): array
    {
        $merged_by_id = [];
        foreach ( $existing_questions as $question )
        {
            if ( ! is_array( $question ) )
            {
                continue;
            }

            $question_id = $this->stringify_post_execution_value( $question['question_id'] ?? '' );
            if ( '' !== $question_id )
            {
                $merged_by_id[ $question_id ] = $question;
            }
        }

        foreach ( $new_questions as $question )
        {
            if ( ! is_array( $question ) )
            {
                continue;
            }

            $question_id = $this->stringify_post_execution_value( $question['question_id'] ?? '' );
            if ( '' === $question_id )
            {
                continue;
            }

            $existing = $merged_by_id[ $question_id ] ?? [];
            if (
                is_array( $existing )
                && '' === $this->stringify_post_execution_value( $question['answer'] ?? '' )
                && '' !== $this->stringify_post_execution_value( $existing['answer'] ?? '' )
            )
            {
                $question['answer']    = $existing['answer'];
                $question['completed'] = $existing['completed'] ?? $question['completed'];
            }

            $merged_by_id[ $question_id ] = array_merge( is_array( $existing ) ? $existing : [], $question );
        }

        return array_values( $merged_by_id );
    }

    private function resolve_realtime_context_identifier( array $context, array $keys ): string
    {
        foreach ( $keys as $key )
        {
            if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) )
            {
                $value = sanitize_text_field( (string) $context[ $key ] );
                if ( '' !== $value )
                {
                    return $value;
                }
            }
        }

        return '';
    }

    private function resolve_realtime_action_label( array $context ): string
    {
        $label = $this->resolve_realtime_context_identifier( $context, [ 'action_name_label', 'action_label' ] );

        return '' === $label
            ? __( 'Real-time Clarification Assistant', 'sentient-forms' )
            : $label;
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
        if ( ! function_exists( 'wp_safe_remote_request' ) )
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

        $validated_url = Sentient_Forms_Url_Policy::validate_outbound_url( $url, 'webhook' );
        if ( is_wp_error( $validated_url ) )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => $validated_url->get_error_message(),
            ];
        }

        $method = isset( $action['method'] ) && is_scalar( $action['method'] )
            ? strtoupper( sanitize_key( (string) $action['method'] ) )
            : 'POST';
        $headers = isset( $action['headers'] ) && is_array( $action['headers'] )
            ? array_map( 'sanitize_text_field', $action['headers'] )
            : [];
        $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';

        $response = Sentient_Forms_Url_Policy::remote_request(
            $validated_url,
            [
                'method'             => in_array( $method, [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) ? $method : 'POST',
                'timeout'            => 5,
                'headers'            => $headers,
                'body'               => wp_json_encode(
                    [
                        'entry_id' => $entry_id,
                        'context'  => $context,
                        'result'   => $result,
                        'action'   => $action,
                    ]
                ),
            ],
            'webhook'
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
            '/{{\s*([^{}]+?)\s*}}/',
            function ( array $matches ) use ( $entry_id, $entry, $context, $result ): string
            {
                $key = strtolower( trim( (string) $matches[1] ) );

	                if ( str_starts_with( $key, 'field:' ) )
	                {
	                    $field_selector = substr( $key, strlen( 'field:' ) );
	                    return $this->resolve_post_execution_field_placeholder( $field_selector, $entry, $context );
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
	     * Resolve field merge tags from either a concrete Gravity Forms field id or a selector.
	     *
	     * @param string $selector Field selector after the "field:" prefix.
	     * @param array  $entry    Gravity Forms entry values.
	     * @param array  $context  Runtime action context.
	     *
	     * @return string
	     */
	    private function resolve_post_execution_field_placeholder( string $selector, array $entry, array $context ): string
	    {
	        $selector = trim( strtolower( $selector ) );
	        if ( '' === $selector )
	        {
	            return '';
	        }

	        $form = $this->resolve_post_execution_form_context( $context );
	        if ( str_starts_with( $selector, 'type:' ) )
	        {
	            return $this->resolve_post_execution_field_by_type(
	                substr( $selector, strlen( 'type:' ) ),
	                $entry,
	                $form
	            );
	        }

	        if ( str_starts_with( $selector, 'label_contains:' ) )
	        {
	            return $this->resolve_post_execution_field_by_label(
	                substr( $selector, strlen( 'label_contains:' ) ),
	                $entry,
	                $form
	            );
	        }

	        return $this->stringify_post_execution_value( $entry[ $selector ] ?? '' );
	    }

	    /**
	     * @param array $context Runtime action context.
	     *
	     * @return array<string, mixed>
	     */
	    private function resolve_post_execution_form_context( array $context ): array
	    {
	        if ( isset( $context['form'] ) && is_array( $context['form'] ) )
	        {
	            return $context['form'];
	        }

	        $form_id = absint( $context['form_id'] ?? 0 );
	        if ( $form_id > 0 && class_exists( 'GFAPI' ) && is_callable( [ 'GFAPI', 'get_form' ] ) )
	        {
	            $form = GFAPI::get_form( $form_id );
	            return is_array( $form ) ? $form : [];
	        }

	        return [];
	    }

	    /**
	     * @param array<string, mixed> $entry Gravity Forms entry values.
	     * @param array<string, mixed> $form  Gravity Forms form metadata.
	     */
	    private function resolve_post_execution_field_by_type( string $selector, array $entry, array $form ): string
	    {
	        $parts = explode( '.', strtolower( trim( $selector ) ), 2 );
	        $type  = sanitize_key( $parts[0] ?? '' );
	        $part  = sanitize_key( $parts[1] ?? '' );
	        if ( '' === $type )
	        {
	            return '';
	        }

	        foreach ( $this->post_execution_form_fields( $form ) as $field )
	        {
	            if ( $this->post_execution_field_type( $field ) !== $type )
	            {
	                continue;
	            }

	            return $this->post_execution_field_value_from_entry(
	                $this->post_execution_field_id( $field ),
	                $entry,
	                $type,
	                $part
	            );
	        }

	        return '';
	    }

	    /**
	     * @param array<string, mixed> $entry Gravity Forms entry values.
	     * @param array<string, mixed> $form  Gravity Forms form metadata.
	     */
	    private function resolve_post_execution_field_by_label( string $label_fragment, array $entry, array $form ): string
	    {
	        $needle = strtolower( trim( $label_fragment ) );
	        if ( '' === $needle )
	        {
	            return '';
	        }

	        foreach ( $this->post_execution_form_fields( $form ) as $field )
	        {
	            $label = strtolower( $this->post_execution_field_label( $field ) );
	            if ( '' === $label || ! str_contains( $label, $needle ) )
	            {
	                continue;
	            }

	            return $this->post_execution_field_value_from_entry(
	                $this->post_execution_field_id( $field ),
	                $entry,
	                $this->post_execution_field_type( $field ),
	                ''
	            );
	        }

	        return '';
	    }

	    /**
	     * @param array<string, mixed> $form Gravity Forms form metadata.
	     * @return array<int, mixed>
	     */
	    private function post_execution_form_fields( array $form ): array
	    {
	        return isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : [];
	    }

	    private function post_execution_field_id( mixed $field ): string
	    {
	        if ( is_array( $field ) )
	        {
	            return isset( $field['id'] ) ? (string) $field['id'] : '';
	        }

	        return is_object( $field ) && isset( $field->id ) ? (string) $field->id : '';
	    }

	    private function post_execution_field_type( mixed $field ): string
	    {
	        if ( is_array( $field ) )
	        {
	            return isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : '';
	        }

	        return is_object( $field ) && isset( $field->type ) ? sanitize_key( (string) $field->type ) : '';
	    }

	    private function post_execution_field_label( mixed $field ): string
	    {
	        if ( is_array( $field ) )
	        {
	            return isset( $field['label'] ) ? (string) $field['label'] : '';
	        }

	        return is_object( $field ) && isset( $field->label ) ? (string) $field->label : '';
	    }

	    /**
	     * @param array<string, mixed> $entry Gravity Forms entry values.
	     */
	    private function post_execution_field_value_from_entry( string $field_id, array $entry, string $field_type, string $part ): string
	    {
	        if ( '' === $field_id )
	        {
	            return '';
	        }

	        if ( 'name' === $field_type )
	        {
	            $first = $this->stringify_post_execution_value( $entry[ $field_id . '.3' ] ?? $entry[ $field_id . '.1' ] ?? '' );
	            $last  = $this->stringify_post_execution_value( $entry[ $field_id . '.6' ] ?? $entry[ $field_id . '.2' ] ?? '' );
	            if ( 'first' === $part )
	            {
	                return $first;
	            }
	            if ( 'last' === $part )
	            {
	                return $last;
	            }
	            $full = trim( $this->stringify_post_execution_value( $entry[ $field_id ] ?? '' ) );
	            return '' !== $full ? $full : trim( $first . ' ' . $last );
	        }

	        return $this->stringify_post_execution_value( $entry[ $field_id ] ?? '' );
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
                if ( 'spam' !== $this->get_entry_meta( $entry_id, 'spam_classification' ) )
                {
                    $this->update_entry_meta( $entry_id, 'sentient_forms_spam_classification', 'ham' );
                }
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
                    __( '✅ Sentient Forms AI reviewed this result (%1$s%% spam confidence - below %2$s%% threshold). This result did not meet the configured spam threshold.', 'sentient-forms' ),
                    round( $effective_confidence * 100 ),
                    round( $threshold * 100 ),
                );
                $this->add_entry_note_if_missing( $entry_id, 'Sentient Forms AI', $note );
            }
            if ( 'spam' !== $this->get_entry_meta( $entry_id, 'spam_classification' ) )
            {
                $this->update_entry_meta( $entry_id, 'sentient_forms_spam_classification', 'reviewed' );
            }
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
        $attested_output = $this->extract_attested_spam_structured_output( $result );
        if ( is_array( $attested_output ) )
        {
            return (float) $attested_output['confidence'];
        }

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
        $attested_output = $this->extract_attested_spam_structured_output( $result );
        if ( is_array( $attested_output ) )
        {
            return $attested_output['indicators'];
        }

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
        $attested_output = $this->extract_attested_spam_structured_output( $result );
        if ( is_array( $attested_output ) )
        {
            return sanitize_key( (string) $attested_output['classification'] );
        }

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
        $attested_output = $this->extract_attested_spam_structured_output( $result );
        if ( is_array( $attested_output ) )
        {
            return (string) $attested_output['justification'];
        }

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
     * Read only schema-attested catalog output from Action result wrappers.
     *
     * @param array<string, mixed> $result Action result.
     * @return array<string, mixed>|null
     */
    private function extract_attested_spam_structured_output( array $result ): ?array
    {
        $containers = [
            $result['evaluation_payload']['result_data'] ?? null,
            $result['result_data'] ?? null,
        ];

        foreach ( $containers as $container )
        {
            if (
                ! is_array( $container )
                || true !== ( $container['structured_output_valid'] ?? false )
                || ! is_array( $container['structured_output'] ?? null )
            )
            {
                continue;
            }

            $structured_output = $container['structured_output'];
            if ( Sentient_Forms_Bundled_Action_Templates::is_structured_output_valid( 'spam_detection_v1', $structured_output ) )
            {
                return $structured_output;
            }
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
            'submission_uuid'          => isset( $context['submission_uuid'] ) && is_scalar( $context['submission_uuid'] )
                ? sanitize_text_field( (string) $context['submission_uuid'] )
                : null,
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
        if ( $this->is_local_mapping_completion_context( $context ) )
        {
            return $this->should_suppress_notifications_on_spam( $context );
        }

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

    private function should_suppress_webhooks_on_spam_for_context( array $context ): bool
    {
        if ( $this->is_local_mapping_completion_context( $context ) )
        {
            return $this->should_suppress_webhooks_on_spam( $context );
        }

        $settings = isset( $context['settings'] ) && is_array( $context['settings'] ) ? $context['settings'] : [];
        if ( array_key_exists( 'suppress_webhooks_on_spam', $settings ) )
        {
            return rest_sanitize_boolean( $settings['suppress_webhooks_on_spam'] );
        }

        $action_id = isset( $context['central_action_id'] ) && is_scalar( $context['central_action_id'] )
            ? sanitize_key( (string) $context['central_action_id'] )
            : '';

        return $this->is_spam_action_id( $action_id );
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
        if ( ! empty( $result['structured_output_valid'] ) )
        {
            return true;
        }

        if ( ! empty( $result['result']['structured_output_valid'] ) )
        {
            return true;
        }

        if ( ! empty( $result['result_data']['structured_output_valid'] ) )
        {
            return true;
        }

        if ( ! empty( $result['evaluation_payload']['result']['structured_output_valid'] ) )
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

        if ( $this->should_suppress_notifications_for_entry( $entry ) )
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

        $entry_id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
        if (
            $entry_id > 0
            && self::SPAM_NOTIFICATION_PREFERENCE_ALLOW === $this->get_entry_spam_notification_preference( $entry_id )
        )
        {
            return false;
        }

        $deferred_mapping_ids = $this->get_deferred_notification_mapping_ids_for_async_spam_submission( $form, $entry );
        if ( empty( $deferred_mapping_ids ) )
        {
            return false;
        }

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
     * Hold or suppress Gravity Forms Webhooks feeds while async spam detection is unresolved.
     *
     * @param array<int, array<string, mixed>> $feeds Webhooks add-on feeds.
     * @param array<string, mixed>             $entry Gravity Forms entry.
     * @param array<string, mixed>             $form  Gravity Forms form.
     * @return array<int, array<string, mixed>>
     */
    public function maybe_defer_async_spam_webhooks( array $feeds, array $entry, array $form ): array
    {
        if ( empty( $feeds ) )
        {
            return $feeds;
        }

        if ( null !== $this->webhook_replay_allowed_feed_ids )
        {
            return $this->filter_webhook_feeds_by_ids( $feeds, $this->webhook_replay_allowed_feed_ids );
        }

        $entry_id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
        if (
            $entry_id > 0
            && self::SPAM_NOTIFICATION_PREFERENCE_ALLOW === $this->get_entry_spam_webhook_preference( $entry_id )
        )
        {
            return $feeds;
        }

        if ( $this->should_suppress_webhooks_for_entry( $entry ) )
        {
            return [];
        }

        $deferred_mapping_ids = $this->get_deferred_webhook_mapping_ids_for_async_spam_submission( $form, $entry );
        if ( empty( $deferred_mapping_ids ) )
        {
            return $feeds;
        }

        if ( $entry_id <= 0 )
        {
            return $feeds;
        }

        $feed_ids = [];
        foreach ( $feeds as $feed )
        {
            if ( is_array( $feed ) && isset( $feed['id'] ) && is_scalar( $feed['id'] ) )
            {
                $feed_ids[] = (string) $feed['id'];
            }
        }
        $feed_ids = $this->normalize_deferred_notification_ids( $feed_ids );
        if ( empty( $feed_ids ) )
        {
            return $feeds;
        }

        $this->update_entry_meta( $entry_id, self::DEFERRED_WEBHOOK_FEED_IDS_META_KEY, $feed_ids );
        $this->update_entry_meta( $entry_id, self::DEFERRED_WEBHOOK_MAPPING_IDS_META_KEY, $deferred_mapping_ids );
        $this->update_entry_meta( $entry_id, self::DEFERRED_WEBHOOK_DECISION_META_KEY, self::DEFERRED_NOTIFICATION_DECISION_PENDING );

        sentient_forms_debug_log(
            'Sentient Forms held Gravity Forms Webhooks feeds while spam classification is pending.',
            [
                'entry_id'  => $entry_id,
                'form_id'   => $form['id'] ?? 0,
                'feed_ids'  => $feed_ids,
            ]
        );

        return [];
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
     * Determine whether Gravity Forms Webhooks should be suppressed for this entry.
     *
     * @param array<string, mixed> $entry Entry data.
     */
    private function should_suppress_webhooks_for_entry( array $entry ): bool
    {
        $entry_id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
        if ( $entry_id > 0 )
        {
            $preference = $this->get_entry_spam_webhook_preference( $entry_id );
            if ( self::SPAM_NOTIFICATION_PREFERENCE_SUPPRESS === $preference )
            {
                return true;
            }

            if ( self::SPAM_NOTIFICATION_PREFERENCE_ALLOW === $preference )
            {
                return false;
            }
        }

        return $this->is_entry_spam( $entry );
    }

    /**
     * @param array<int, array<string, mixed>> $feeds
     * @param array<int, string>               $allowed_feed_ids
     * @return array<int, array<string, mixed>>
     */
    private function filter_webhook_feeds_by_ids( array $feeds, array $allowed_feed_ids ): array
    {
        $allowed_feed_ids = $this->normalize_deferred_notification_ids( $allowed_feed_ids );
        if ( empty( $allowed_feed_ids ) )
        {
            return [];
        }

        return array_values(
            array_filter(
                $feeds,
                static function ( array $feed ) use ( $allowed_feed_ids ): bool {
                    $feed_id = isset( $feed['id'] ) && is_scalar( $feed['id'] ) ? (string) $feed['id'] : '';
                    return '' !== $feed_id && in_array( $feed_id, $allowed_feed_ids, true );
                }
            )
        );
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

    private function get_entry_spam_webhook_preference( int $entry_id ): ?string
    {
        if ( $entry_id <= 0 )
        {
            return null;
        }

        $preference = $this->get_entry_meta( $entry_id, self::SPAM_WEBHOOK_PREFERENCE_META_KEY );
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

        $is_local_mapping_error = $this->is_local_mapping_completion_context( $context );

        $this->resolve_deferred_delivery_after_async_completion(
            $context,
            fn (): bool => ! $is_local_mapping_error && $this->should_suppress_notifications_on_spam_for_context( $context ),
            fn (): bool => ! $is_local_mapping_error && $this->should_suppress_webhooks_on_spam_for_context( $context ),
        );
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
        $form_id = isset( $context['form_id'] ) && is_scalar( $context['form_id'] )
            ? absint( $context['form_id'] )
            : 0;
        $this->record_failed_spam_delivery_state(
            $entry_id,
            ! empty( $action_settings ) ? $action_settings : $context,
            $form_id
        );
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
            && ! empty( $mapping['mark_as_spam'] )
            && $this->should_suppress_notifications_on_spam( $mapping );
    }

    /**
     * Determine whether a mapping should hold Gravity Forms Webhooks feeds.
     *
     * @param array<string, mixed> $mapping Mapping payload.
     * @param bool                 $should_async Whether the mapping executes asynchronously.
     */
    private function should_defer_webhooks_for_mapping( array $mapping, bool $should_async ): bool
    {
        if ( ! $should_async )
        {
            return false;
        }

        if ( $this->local_spam_effect_enabled( $mapping ) )
        {
            return $this->should_suppress_webhooks_on_spam( $mapping );
        }

        return ( $mapping['central_action_id'] ?? '' ) === 'spam_detection_v1'
            && ! empty( $mapping['mark_as_spam'] )
            && $this->should_suppress_webhooks_on_spam( $mapping );
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

            if ( $this->is_plan_node_trigger_unbound( $node, 'gform_after_submission' ) )
            {
                continue;
            }

            $mapping = $this->resolve_mapping_runtime_settings( $node['mapping'], $form_id );
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
     * Resolve async spam mapping IDs that should hold Gravity Forms Webhooks feeds.
     *
     * @param array $form  The form object.
     * @param array $entry The entry object.
     * @return array<int, string>
     */
    private function get_deferred_webhook_mapping_ids_for_async_spam_submission( array $form, array $entry ): array
    {
        $form_id  = isset( $form['id'] ) ? absint( $form['id'] ) : 0;
        $entry_id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
        $cache_key = $form_id . ':' . $entry_id;

        if ( array_key_exists( $cache_key, $this->async_spam_webhook_gate_cache ) )
        {
            return $this->async_spam_webhook_gate_cache[ $cache_key ];
        }

        $settings = $this->get_form_settings( $form_id );
        $disable_flags = $this->get_execution_disable_flags( $settings );
        if ( ! empty( $disable_flags['effective_disabled'] ) )
        {
            $this->async_spam_webhook_gate_cache[ $cache_key ] = [];
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

            if ( $this->is_plan_node_trigger_unbound( $node, 'gform_after_submission' ) )
            {
                continue;
            }

            $mapping = $this->resolve_mapping_runtime_settings( $node['mapping'], $form_id );
            $mapping['local_mapping_id'] = $mapping['local_mapping_id'] ?? $mapping_id;

            if ( ! $this->should_defer_webhooks_for_mapping( $mapping, $this->is_mapping_async( $mapping ) ) )
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
        $this->async_spam_webhook_gate_cache[ $cache_key ] = $mapping_ids;

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

    private function reconcile_deferred_notifications_after_submission(
        array $entry,
        array $form,
        Sentient_Forms_Accepted_Submission_Run_Result $outcome
    ): void
    {
        $entry_id = absint( $entry['id'] ?? 0 );
        if ( $entry_id <= 0 || [] === $this->get_deferred_notification_ids( $entry_id ) )
        {
            return;
        }

        $queued_mapping_ids = $this->queued_native_effect_mapping_ids( $outcome, 'notifications' );
        $queued_mapping_ids = array_values(
            array_intersect( $this->get_deferred_notification_mapping_ids( $entry_id ), $queued_mapping_ids )
        );
        if ( [] === $queued_mapping_ids )
        {
            $this->replay_deferred_notifications( $entry_id, absint( $form['id'] ?? 0 ) );
            return;
        }

        $this->update_entry_meta( $entry_id, self::DEFERRED_NOTIFICATION_MAPPING_IDS_META_KEY, $queued_mapping_ids );
        $this->update_entry_meta( $entry_id, self::DEFERRED_NOTIFICATION_DECISION_META_KEY, self::DEFERRED_NOTIFICATION_DECISION_PENDING );
    }

    private function reconcile_deferred_webhooks_after_submission(
        array $entry,
        array $form,
        Sentient_Forms_Accepted_Submission_Run_Result $outcome
    ): void
    {
        $entry_id = absint( $entry['id'] ?? 0 );
        if ( $entry_id <= 0 || [] === $this->get_deferred_webhook_feed_ids( $entry_id ) )
        {
            return;
        }

        $queued_mapping_ids = $this->queued_native_effect_mapping_ids( $outcome, 'webhooks' );
        $queued_mapping_ids = array_values(
            array_intersect( $this->get_deferred_webhook_mapping_ids( $entry_id ), $queued_mapping_ids )
        );
        if ( [] === $queued_mapping_ids )
        {
            $this->replay_deferred_webhooks( $entry_id, absint( $form['id'] ?? 0 ) );
            return;
        }

        $this->update_entry_meta( $entry_id, self::DEFERRED_WEBHOOK_MAPPING_IDS_META_KEY, $queued_mapping_ids );
        $this->update_entry_meta( $entry_id, self::DEFERRED_WEBHOOK_DECISION_META_KEY, self::DEFERRED_NOTIFICATION_DECISION_PENDING );
    }

    /**
     * Use the same resolved mapping settings that were routed by the shared
     * runner when identifying queued native-effect work.
     *
     * @return array<int, string>
     */
    private function queued_native_effect_mapping_ids(
        Sentient_Forms_Accepted_Submission_Run_Result $outcome,
        string $effect
    ): array
    {
        $mapping_ids = [];
        foreach ( $outcome->get_queued_mapping_ids() as $mapping_id )
        {
            $mapping = $outcome->get_resolved_mapping( $mapping_id );
            if ( ! is_array( $mapping ) )
            {
                continue;
            }

            $matches = 'webhooks' === $effect
                ? $this->should_defer_webhooks_for_mapping( $mapping, true )
                : $this->should_defer_notifications_for_mapping( $mapping, true );
            if ( $matches )
            {
                $mapping_ids[] = $mapping_id;
            }
        }

        return $this->normalize_deferred_notification_ids( $mapping_ids );
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
     * Resolve deferred Gravity Forms Webhooks after a spam-classification async job completes.
     *
     * @param array<string, mixed> $context Async job context.
     * @param bool                 $should_suppress Whether Webhooks should stay suppressed.
     */
    private function resolve_deferred_webhooks_after_async_completion( array $context, bool $should_suppress ): void
    {
        $entry_id = isset( $context['entry_id'] ) ? absint( $context['entry_id'] ) : 0;
        if ( $entry_id <= 0 )
        {
            return;
        }

        $pending_mapping_ids = $this->get_deferred_webhook_mapping_ids( $entry_id );
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
            $this->update_entry_meta( $entry_id, self::DEFERRED_WEBHOOK_DECISION_META_KEY, self::DEFERRED_NOTIFICATION_DECISION_SUPPRESS );
        }

        $remaining_mapping_ids = array_values( array_diff( $pending_mapping_ids, [ $current_mapping_id ] ) );
        if ( ! empty( $remaining_mapping_ids ) )
        {
            $this->update_entry_meta( $entry_id, self::DEFERRED_WEBHOOK_MAPPING_IDS_META_KEY, $remaining_mapping_ids );
            return;
        }

        $decision = $this->get_deferred_webhook_decision( $entry_id );
        if ( self::DEFERRED_NOTIFICATION_DECISION_SUPPRESS === $decision )
        {
            $this->clear_deferred_webhook_state( $entry_id );
            return;
        }

        $this->replay_deferred_webhooks( $entry_id, absint( $context['form_id'] ?? 0 ) );
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
        if ( ! $this->should_suppress_notifications_on_spam_for_context( $context ) )
        {
            return false;
        }

        if ( empty( $context['mark_as_spam'] ) && ! $this->local_spam_effect_enabled( $context ) )
        {
            return false;
        }

        $classification = $this->extract_spam_classification_for_context( $context, $result );
        if ( ! in_array( $classification, [ 'spam', 'likely_spam' ], true ) )
        {
            return false;
        }

        $confidence = $this->extract_spam_confidence_for_context( $context, $result );
        $threshold  = $this->get_spam_confidence_threshold_for_context( $context );

        return ( $confidence ?? 1.0 ) >= $threshold;
    }

    /**
     * Decide whether the async spam result should permanently suppress deferred Webhooks.
     *
     * @param array<string, mixed> $context Async job context.
     * @param array<string, mixed> $result  Async result payload.
     */
    private function should_suppress_deferred_webhooks_from_result( array $context, array $result ): bool
    {
        if ( ! $this->should_suppress_webhooks_on_spam_for_context( $context ) )
        {
            return false;
        }

        if ( empty( $context['mark_as_spam'] ) && ! $this->local_spam_effect_enabled( $context ) )
        {
            return false;
        }

        $classification = $this->extract_spam_classification_for_context( $context, $result );
        if ( ! in_array( $classification, [ 'spam', 'likely_spam' ], true ) )
        {
            return false;
        }

        $confidence = $this->extract_spam_confidence_for_context( $context, $result );
        $threshold  = $this->get_spam_confidence_threshold_for_context( $context );

        return ( $confidence ?? 1.0 ) >= $threshold;
    }

    /**
     * Resolve the spam confidence threshold used for deferred delivery decisions.
     *
     * @param array<string, mixed> $context Async job context.
     */
    private function get_spam_confidence_threshold_for_context( array $context ): float
    {
        $spam_effect = $this->get_spam_effect_config_for_context( $context );
        if ( $this->is_local_mapping_completion_context( $context ) && is_numeric( $spam_effect['min_confidence'] ?? null ) )
        {
            return (float) $spam_effect['min_confidence'];
        }

        if ( is_numeric( $context['spam_confidence_threshold'] ?? null ) )
        {
            return (float) $context['spam_confidence_threshold'];
        }

        $settings = isset( $context['settings'] ) && is_array( $context['settings'] ) ? $context['settings'] : [];
        if ( is_numeric( $settings['spam_confidence_threshold'] ?? null ) )
        {
            return (float) $settings['spam_confidence_threshold'];
        }

        return is_numeric( $spam_effect['min_confidence'] ?? null ) ? (float) $spam_effect['min_confidence'] : 0.80;
    }

    /**
     * Extract the configured local spam classification when available.
     *
     * @param array<string, mixed> $context Async job context.
     * @param array<string, mixed> $result  Async result payload.
     */
    private function extract_spam_classification_for_context( array $context, array $result ): ?string
    {
        $configured = $this->extract_local_spam_effect_value( $context, $result, 'classification_path' );
        $normalized = $this->normalize_spam_classification_value( $configured );
        if ( '' !== $normalized )
        {
            return $normalized;
        }

        $fallback = $this->extract_spam_classification( $result );
        if ( ! $this->is_local_mapping_completion_context( $context ) )
        {
            return $fallback;
        }

        $normalized = $this->normalize_spam_classification_value( $fallback );
        if ( '' !== $normalized )
        {
            return $normalized;
        }

        $provider_result = isset( $result['result'] ) && is_array( $result['result'] ) ? $result['result'] : $result;
        $normalized      = $this->normalize_spam_classification_value( $this->extract_spam_classification( $provider_result ) );
        if ( '' !== $normalized )
        {
            return $normalized;
        }

        $is_spam = $this->extract_nested_post_execution_value( $provider_result, 'structured.is_spam' );

        return true === $is_spam || 'true' === strtolower( (string) $is_spam ) ? 'spam' : null;
    }

    /**
     * Extract the configured local spam confidence when available.
     *
     * @param array<string, mixed> $context Async job context.
     * @param array<string, mixed> $result  Async result payload.
     */
    private function extract_spam_confidence_for_context( array $context, array $result ): ?float
    {
        $configured = $this->extract_local_spam_effect_value( $context, $result, 'confidence_path' );
        if ( is_numeric( $configured ) )
        {
            return (float) $configured;
        }

        $spam_effect = $this->get_spam_effect_config_for_context( $context );
        if (
            $this->is_local_mapping_completion_context( $context )
            && isset( $spam_effect['confidence_path'] )
            && is_scalar( $spam_effect['confidence_path'] )
            && '' !== trim( (string) $spam_effect['confidence_path'] )
        )
        {
            return null;
        }

        return $this->extract_spam_confidence( $result );
    }

    /**
     * Normalize a spam classification using the local effect applier's semantics.
     */
    private function normalize_spam_classification_value( mixed $value ): string
    {
        if ( ! is_scalar( $value ) )
        {
            return '';
        }

        $normalized = preg_replace( '/[\s-]+/', '_', strtolower( trim( (string) $value ) ) );

        return sanitize_key( (string) ( $normalized ?? '' ) );
    }

    /**
     * Resolve one configured local spam-effect value from the provider result.
     *
     * @param array<string, mixed> $context  Async job context.
     * @param array<string, mixed> $result   Async result payload.
     * @param string               $path_key Spam effect path setting.
     *
     * @return mixed|null
     */
    private function extract_local_spam_effect_value( array $context, array $result, string $path_key )
    {
        if ( ! $this->is_local_mapping_completion_context( $context ) )
        {
            return null;
        }

        $spam_effect = $this->get_spam_effect_config_for_context( $context );
        $path        = isset( $spam_effect[ $path_key ] ) && is_scalar( $spam_effect[ $path_key ] )
            ? trim( (string) $spam_effect[ $path_key ] )
            : '';
        if ( '' === $path )
        {
            return null;
        }

        $provider_result = isset( $result['result'] ) && is_array( $result['result'] ) ? $result['result'] : $result;
        return $this->extract_nested_post_execution_value( $provider_result, $path );
    }

    /**
     * Resolve the local spam-effect configuration from an async context.
     *
     * @param array<string, mixed> $context Async job context.
     *
     * @return array<string, mixed>
     */
    private function get_spam_effect_config_for_context( array $context ): array
    {
        $effects = $this->get_local_effect_mapping( $context );

        return isset( $effects['spam'] ) && is_array( $effects['spam'] ) ? $effects['spam'] : [];
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

    private function replay_deferred_webhooks( int $entry_id, int $form_id ): void
    {
        $feed_ids = $this->get_deferred_webhook_feed_ids( $entry_id );
        if ( empty( $feed_ids ) )
        {
            $this->clear_deferred_webhook_state( $entry_id );
            return;
        }

        $form = $this->get_form_object( $form_id );
        $entry = $this->get_entry_record( $entry_id );
        if ( ! is_array( $form ) || ! is_array( $entry ) )
        {
            $this->log_deferred_webhook_replay_failure( $entry_id, $form_id, $feed_ids, 'form_or_entry_unavailable' );
            return;
        }

        try
        {
            $dispatched = $this->dispatch_deferred_webhooks( $entry, $form, $feed_ids );
        } catch ( Throwable $throwable )
        {
            $this->log_deferred_webhook_replay_failure(
                $entry_id,
                $form_id,
                $feed_ids,
                'dispatch_exception',
                $throwable
            );
            return;
        }

        if ( ! $dispatched )
        {
            $this->log_deferred_webhook_replay_failure( $entry_id, $form_id, $feed_ids, 'dispatch_returned_false' );
            return;
        }

        $this->clear_deferred_webhook_state( $entry_id );
    }

    /**
     * Log a deferred Webhook replay failure without form or entry payload data.
     *
     * @param int                    $entry_id  Gravity Forms entry ID.
     * @param int                    $form_id   Gravity Forms form ID.
     * @param array<int, string>     $feed_ids  Deferred Webhook feed IDs.
     * @param string                 $reason    Stable failure reason.
     * @param Throwable|null         $throwable Optional dispatch failure.
     */
    private function log_deferred_webhook_replay_failure(
        int $entry_id,
        int $form_id,
        array $feed_ids,
        string $reason,
        ?Throwable $throwable = null
    ): void
    {
        $context = [
            'entry_id' => $entry_id,
            'form_id'  => $form_id,
            'feed_ids' => array_values( array_map( 'sanitize_key', $feed_ids ) ),
            'reason'   => sanitize_key( $reason ),
        ];
        if ( null !== $throwable )
        {
            $context['exception_type'] = get_class( $throwable );
        }

        $this->plugin->get_logger()->error( 'deferred Gravity Forms Webhook replay failed', $context );
    }

    /**
     * Dispatch deferred Webhooks through Gravity Forms using replay-only feed IDs.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $form
     * @param array<int, string>   $feed_ids
     */
    protected function dispatch_deferred_webhooks( array $entry, array $form, array $feed_ids ): bool
    {
        if ( ! $this->gravity_forms_webhooks_feed_controls_available() )
        {
            return false;
        }

        $previous_allowed_feed_ids = $this->webhook_replay_allowed_feed_ids;
        $this->webhook_replay_allowed_feed_ids = $feed_ids;
        try
        {
            $processed_feeds = GFAPI::maybe_process_feeds( $entry, $form, self::GRAVITY_FORMS_WEBHOOKS_ADDON_SLUG );
            return is_array( $processed_feeds );
        } finally
        {
            $this->webhook_replay_allowed_feed_ids = $previous_allowed_feed_ids;
        }
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
     * @return array<int, string>
     */
    private function get_deferred_webhook_feed_ids( int $entry_id ): array
    {
        return $this->normalize_deferred_notification_ids(
            $this->get_entry_meta( $entry_id, self::DEFERRED_WEBHOOK_FEED_IDS_META_KEY ) ?? [],
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
     * @return array<int, string>
     */
    private function get_deferred_webhook_mapping_ids( int $entry_id ): array
    {
        return $this->normalize_deferred_notification_ids(
            $this->get_entry_meta( $entry_id, self::DEFERRED_WEBHOOK_MAPPING_IDS_META_KEY ) ?? [],
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

    private function get_deferred_webhook_decision( int $entry_id ): string
    {
        $decision = $this->get_entry_meta( $entry_id, self::DEFERRED_WEBHOOK_DECISION_META_KEY );

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

    private function clear_deferred_webhook_state( int $entry_id ): void
    {
        $this->update_entry_meta( $entry_id, self::DEFERRED_WEBHOOK_FEED_IDS_META_KEY, [] );
        $this->update_entry_meta( $entry_id, self::DEFERRED_WEBHOOK_MAPPING_IDS_META_KEY, [] );
        $this->update_entry_meta( $entry_id, self::DEFERRED_WEBHOOK_DECISION_META_KEY, self::DEFERRED_NOTIFICATION_DECISION_PENDING );
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
