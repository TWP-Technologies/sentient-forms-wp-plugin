<?php
/**
 * Elementor Forms adapter.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * First-party Elementor Pro Forms Form Source adapter.
 */
class Sentient_Forms_Elementor_Forms_Adapter implements Sentient_Forms_Adapter_Interface, Sentient_Forms_Async_Capable_Adapter_Interface
{
    private const FORM_ACTIONS_OPTION_BASE = 'sentient_forms_actions_';

    private const NATIVE_AFTER_SUBMISSION_HOOK = 'elementor_pro/forms/new_record';

    private Sentient_Forms_Plugin $plugin;

    private ?Sentient_Forms_Submission_Ledger_Capture_Service $submission_ledger_capture_service = null;

    /**
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $discovered_elementor_forms = null;

    public function __construct( Sentient_Forms_Plugin $plugin )
    {
        $this->plugin = $plugin;
    }

    public function get_id(): string
    {
        return 'elementor_forms';
    }

    public function get_name(): string
    {
        return __( 'Elementor Forms', 'sentient-forms' );
    }

    public function is_active(): bool
    {
        return $this->has_elementor_pro_forms_api();
    }

    /**
     * @return array<string, mixed>
     */
    public function get_capability_descriptor(): array
    {
        $has_elementor        = $this->has_elementor();
        $has_pro_forms        = $this->has_elementor_pro_forms_api();
        $has_form_submissions = $has_pro_forms && $this->has_elementor_pro_form_submissions_api();

        if ( ! $has_elementor )
        {
            $availability = 'not_installed';
            $message      = __( 'Install and activate Elementor Pro to configure Sentient Forms for Elementor Forms.', 'sentient-forms' );
            $reason       = __( 'Elementor is not active on this site.', 'sentient-forms' );
        }
        elseif ( ! $has_pro_forms )
        {
            $availability = 'requires_pro';
            $message      = __( 'Elementor Forms support requires Elementor Pro Forms APIs.', 'sentient-forms' );
            $reason       = __( 'Elementor is active, but Elementor Pro Forms APIs are unavailable.', 'sentient-forms' );
        }
        else
        {
            $availability = 'available';
            $message      = __( 'Elementor Pro Forms APIs are available. Sentient Forms can run after-submission actions after ledger opt-in.', 'sentient-forms' );
            $reason       = null;
        }

        if ( ! $has_pro_forms )
        {
            $native_submission_parity_reason = __( 'Elementor Pro Forms APIs are unavailable, so native Elementor Form Submissions parity cannot be claimed.', 'sentient-forms' );
        }
        elseif ( ! $has_form_submissions )
        {
            $native_submission_parity_reason = __( 'Elementor Pro Forms APIs are available, but Elementor Form Submissions APIs are unavailable. Treat this as paid but insufficient for native submission-link parity.', 'sentient-forms' );
        }
        else
        {
            $native_submission_parity_reason = __( 'Elementor Form Submissions APIs are detected, but native submission links remain disabled until the required Elementor Pro Advanced Solo-or-higher fixture proves reliable IDs and links through Browser and Chrome dogfood.', 'sentient-forms' );
        }

        return [
            'slug'                 => $this->get_id(),
            'label'                => $this->get_name(),
            'availability'         => $availability,
            'availability_message' => $message,
            'forms_discovery'      => [
                'supported' => $has_pro_forms,
                'reason'    => $has_pro_forms ? null : $reason,
            ],
            'field_manifest'       => [
                'supported' => $has_pro_forms,
                'reason'    => $has_pro_forms ? null : $reason,
            ],
            'lifecycles'           => [
                Sentient_Forms_Form_Source_Lifecycles::VALIDATION        => [
                    'supported'          => false,
                    'label'              => __( 'Validation', 'sentient-forms' ),
                    'native_hook'        => null,
                    'execution_mode'     => 'blocking',
                    'requires_ledger'    => false,
                    'unsupported_reason' => __( 'Elementor Forms validation blocking is not supported in this release.', 'sentient-forms' ),
                ],
                Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION => [
                    'supported'          => $has_pro_forms,
                    'label'              => __( 'After submission', 'sentient-forms' ),
                    'native_hook'        => $has_pro_forms ? self::NATIVE_AFTER_SUBMISSION_HOOK : null,
                    'execution_mode'     => 'async',
                    'requires_ledger'    => true,
                    'unsupported_reason' => $has_pro_forms ? null : $reason,
                ],
                Sentient_Forms_Form_Source_Lifecycles::REAL_TIME        => [
                    'supported'          => false,
                    'label'              => __( 'Real time', 'sentient-forms' ),
                    'native_hook'        => null,
                    'execution_mode'     => 'real_time',
                    'requires_ledger'    => false,
                    'unsupported_reason' => __( 'Realtime Elementor Forms support is not available in this release.', 'sentient-forms' ),
                ],
            ],
            'native_entry'         => [
                'id'    => false,
                'link'  => false,
                'read'  => false,
                'write' => false,
            ],
            'native_enrichment'    => [
                'notes'                 => false,
                'status'                => false,
                'spam'                  => false,
                'notification_controls' => false,
                'webhook_controls'      => false,
            ],
            'ledger'               => [
                'required_for_parity' => true,
                'enabled'             => false,
                'settings_source'     => 'sentient_submission_ledger_settings',
                'unavailable_reason'  => __( 'Enable the Sentient Forms Submission Ledger before reviewing Elementor Forms submissions in Sentient Forms.', 'sentient-forms' ),
            ],
            'requirements'         => [
                'plugin'                     => 'elementor/elementor.php',
                'module'                     => 'elementor-pro-forms',
                'requires_pro'               => true,
                'requires_addon'             => false,
                'is_elementor_active'        => $has_elementor,
                'is_pro_forms_api_available' => $has_pro_forms,
                'is_form_submissions_api_available' => $has_form_submissions,
                'native_submission_parity'    => $has_form_submissions ? 'fixture_required' : 'unavailable',
                'native_submission_parity_reason' => $native_submission_parity_reason,
                'native_submission_fixture_required' => true,
                'minimum_native_submission_plan' => 'elementor_pro_advanced_solo_or_higher',
            ],
        ];
    }

    public function init(): void
    {
        if ( ! $this->is_active() )
        {
            return;
        }

        add_action( self::NATIVE_AFTER_SUBMISSION_HOOK, [ $this, 'handle_new_record' ], 10, 2 );
    }

    public function get_forms(): array
    {
        if ( ! $this->is_active() )
        {
            return [];
        }

        $forms = [];
        foreach ( $this->discover_elementor_forms() as $form )
        {
            $post_id   = absint( $form['post_id'] ?? 0 );
            $widget_id = isset( $form['widget_id'] ) && is_scalar( $form['widget_id'] )
                ? sanitize_key( (string) $form['widget_id'] )
                : '';

            if ( $post_id <= 0 || '' === $widget_id )
            {
                continue;
            }

            $forms[] = [
                'id'                 => $this->format_form_id( $post_id, $widget_id ),
                'title'              => $this->form_title( $form ),
                'adapter'            => $this->get_id(),
                'adapter_name'       => $this->get_name(),
                'provider_is_active' => true,
                'provider_edit_url'  => admin_url(
                    add_query_arg(
                        [
                            'post'   => $post_id,
                            'action' => 'elementor',
                        ],
                        'post.php'
                    )
                ),
                'settings'           => null,
            ];
        }

        return $forms;
    }

    public function reset_discovery_cache(): void
    {
        $this->discovered_elementor_forms = null;
    }

    public function get_form_fields( $form_id ): array
    {
        if ( ! $this->is_active() )
        {
            return [];
        }

        $form = $this->find_form_by_id( $form_id );
        if ( null === $form )
        {
            return [];
        }

        $settings = isset( $form['settings'] ) && is_array( $form['settings'] )
            ? $form['settings']
            : [];

        $form_fields = isset( $settings['form_fields'] ) && is_array( $settings['form_fields'] )
            ? $settings['form_fields']
            : [];

        $fields = [];
        foreach ( $form_fields as $field )
        {
            $normalized = $this->normalize_elementor_field( $field );
            if ( null !== $normalized )
            {
                $fields[] = $normalized;
            }
        }

        return $this->mark_ambiguous_field_ids( $fields );
    }

    /**
     * Return a provider-native form snapshot for async local-first execution.
     *
     * @return array<string, mixed>|null
     */
    public function get_form_data( mixed $form_id ): ?array
    {
        if ( ! $this->is_active() )
        {
            return null;
        }

        $form_id_string = is_scalar( $form_id ) ? sanitize_text_field( (string) $form_id ) : '';
        if ( '' === $form_id_string || null === $this->find_form_by_id( $form_id_string ) )
        {
            return null;
        }

        return $this->form_snapshot( $form_id_string );
    }

    public function get_entry_data( $entry_id, $form_id = null )
    {
        $submission_uuid = $this->normalize_submission_uuid( $entry_id );
        if ( null === $submission_uuid || ! class_exists( 'Sentient_Forms_Submission_Ledger_Repository' ) )
        {
            return null;
        }

        global $wpdb;
        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $record = $ledger->get_by_submission_uuid( $submission_uuid );
        if ( ! is_array( $record ) || $this->get_id() !== sanitize_key( (string) ( $record['form_source'] ?? '' ) ) )
        {
            return null;
        }

        $requested_form_id = is_scalar( $form_id ) ? sanitize_text_field( (string) $form_id ) : '';
        if ( '' !== $requested_form_id && $requested_form_id !== sanitize_text_field( (string) ( $record['form_id'] ?? '' ) ) )
        {
            return null;
        }

        $entry = [];

        if ( isset( $record['logical_fields_json'] ) && is_array( $record['logical_fields_json'] ) )
        {
            $entry = array_merge( $entry, $record['logical_fields_json'] );
        }

        if ( isset( $record['file_refs_json'] ) && is_array( $record['file_refs_json'] ) )
        {
            $entry['file_refs'] = $record['file_refs_json'];
        }

        $entry['id']              = null;
        $entry['submission_uuid'] = $submission_uuid;
        $entry['form_source']     = $this->get_id();
        $entry['form_id']         = sanitize_text_field( (string) ( $record['form_id'] ?? '' ) );

        return $entry;
    }

    public function handle_new_record( mixed $record, mixed $handler = null ): ?string
    {
        if ( ! $this->is_active() )
        {
            return null;
        }

        $form_id = $this->resolve_form_id_from_record( $record, $handler );
        if ( '' === $form_id )
        {
            return null;
        }

        $capture_service = $this->get_submission_ledger_capture_service();
        if ( null === $capture_service )
        {
            return null;
        }

        $captured = $capture_service->capture(
            [
                'form_source'       => $this->get_id(),
                'form_id'           => $form_id,
                'logical_fields'    => $this->logical_fields_from_record( $record, $form_id ),
                'files'             => $this->file_references_from_record( $record, $form_id ),
                'provider_metadata' => $this->provider_metadata_from_record( $record, $form_id ),
            ]
        );

        if ( is_wp_error( $captured ) )
        {
            return null;
        }

        $submission_uuid = isset( $captured['submission_uuid'] ) && is_scalar( $captured['submission_uuid'] )
            ? sanitize_text_field( (string) $captured['submission_uuid'] )
            : '';

        if ( '' === $submission_uuid )
        {
            return null;
        }

        $this->schedule_after_submission_actions( $form_id, $submission_uuid, $captured );

        return $submission_uuid;
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
        if ( ! $this->has_elementor_pro_forms_api() )
        {
            return null;
        }

        $lifecycle_id = Sentient_Forms_Form_Source_Lifecycles::normalize_id( $event_name );

        return Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION === $lifecycle_id ? self::NATIVE_AFTER_SUBMISSION_HOOK : null;
    }

    public function get_form_object( int $form_id ): object | array | null
    {
        return null;
    }

    public function form_exists( mixed $form_id ): bool
    {
        return $this->is_active() && null !== $this->find_form_by_id( $form_id );
    }

    /**
     * @return array<string, mixed>
     */
    public function get_form_settings( mixed $form_id ): array
    {
        $settings = $this->get_form_actions_option( $form_id );
        if ( ! is_array( $settings ) )
        {
            return [];
        }

        return $this->merge_local_first_form_mappings( $this->normalize_action_wrapper( $settings ), $form_id );
    }

    public function update_form_settings( mixed $form_id, array $settings ): bool
    {
        return update_option( $this->get_form_actions_option_key( $form_id ), $settings, false );
    }

    public function finalize_async_success( array $context, array $result ): void
    {
    }

    public function finalize_async_error( array $context, WP_Error $error ): void
    {
    }

    public function finalize_async_evaluation( array $context, array $result ): void
    {
    }

    private function has_elementor(): bool
    {
        $is_active = class_exists( '\Elementor\Plugin' ) || defined( 'ELEMENTOR_VERSION' ) || did_action( 'elementor/loaded' );

        return (bool) apply_filters( 'sentient_forms_elementor_is_active', $is_active, $this );
    }

    private function has_elementor_pro_forms_api(): bool
    {
        $has_api = class_exists( '\ElementorPro\Modules\Forms\Classes\Form_Record' )
            || class_exists( '\ElementorPro\Modules\Forms\Module' );

        return (bool) apply_filters( 'sentient_forms_elementor_pro_forms_api_available', $has_api, $this );
    }

    private function has_elementor_pro_form_submissions_api(): bool
    {
        $has_api = class_exists( '\ElementorPro\Modules\Forms\Submissions\Component' )
            || class_exists( '\ElementorPro\Modules\Forms\Submissions\Database\Query' );

        return (bool) apply_filters( 'sentient_forms_elementor_pro_form_submissions_api_available', $has_api, $this );
    }

    /**
     * @param array<string, mixed> $captured
     */
    private function schedule_after_submission_actions( string $form_id, string $submission_uuid, array $captured ): void
    {
        $settings = $this->get_form_settings( $form_id );
        if ( [] === $settings )
        {
            return;
        }

        if ( ! empty( $this->get_execution_disable_flags( $settings )['effective_disabled'] ) )
        {
            return;
        }

        $plan  = $this->plugin->get_mapping_dependency_planner()->build_execution_plan(
            $settings,
            Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION
        );
        $form  = $this->form_snapshot( $form_id );
        $entry = $this->ledger_entry_snapshot( $captured, $submission_uuid );
        $mapping_outcomes      = [];
        $execution_request_ids = [];

        foreach ( $plan['order'] as $mapping_id )
        {
            $node = $plan['nodes'][ $mapping_id ] ?? null;
            if ( ! is_array( $node ) || ! isset( $node['mapping'] ) || ! is_array( $node['mapping'] ) )
            {
                continue;
            }

            if (
                empty( $node['enabled'] )
                || empty( $node['hook_enabled'] )
                || $this->is_plan_node_trigger_unbound( $node, Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION )
            )
            {
                continue;
            }

            $action_settings = $this->filter_option_backed_runtime_settings_for_native_capabilities( $node['mapping'] );
            $action_settings['local_mapping_id'] = $action_settings['local_mapping_id'] ?? $mapping_id;

            $central_action_id = isset( $action_settings['central_action_id'] ) && is_scalar( $action_settings['central_action_id'] )
                ? sanitize_key( (string) $action_settings['central_action_id'] )
                : '';
            if ( '' === $central_action_id )
            {
                continue;
            }

            $execution_request_ids[ (string) $mapping_id ] = Sentient_Forms_Action_Executor::generate_execution_request_id(
                $central_action_id,
                $form,
                $entry,
                [
                    'hook'      => self::NATIVE_AFTER_SUBMISSION_HOOK,
                    'action_id' => (string) $mapping_id,
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

            if (
                empty( $node['enabled'] )
                || empty( $node['hook_enabled'] )
                || $this->is_plan_node_trigger_unbound( $node, Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION )
            )
            {
                $mapping_outcomes[ (string) $mapping_id ] = 'skipped';
                continue;
            }

            $action_settings = $this->filter_option_backed_runtime_settings_for_native_capabilities( $node['mapping'] );
            $action_settings['local_mapping_id'] = $action_settings['local_mapping_id'] ?? $mapping_id;

            $central_action_id = isset( $action_settings['central_action_id'] ) && is_scalar( $action_settings['central_action_id'] )
                ? sanitize_key( (string) $action_settings['central_action_id'] )
                : '';
            if ( '' === $central_action_id )
            {
                $mapping_outcomes[ (string) $mapping_id ] = 'failed';
                continue;
            }

            $dependency_ids = is_array( $node['dependency_ids'] ?? null ) ? $node['dependency_ids'] : [];
            $blocked_by_dependency = $this->resolve_dependency_blocking_mapping( $dependency_ids, $mapping_outcomes );
            if ( null !== $blocked_by_dependency )
            {
                $mapping_outcomes[ (string) $mapping_id ] = 'skipped';
                continue;
            }

            if ( ! $this->plugin->get_condition_evaluator()->should_execute( $action_settings, $entry ) )
            {
                $mapping_outcomes[ (string) $mapping_id ] = 'skipped';
                continue;
            }

            $dependency_initial_outcomes      = [];
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

            $dependency_context = [
                'execution_request_id'             => $execution_request_ids[ (string) $mapping_id ] ?? null,
                'dependency_mapping_ids'           => $dependency_ids,
                'dependency_execution_request_ids' => $dependency_execution_request_ids,
                'dependency_initial_outcomes'      => $dependency_initial_outcomes,
                'dependency_wait_started_at'       => time(),
                'dependency_wait_max_seconds'      => max(
                    30,
                    (int) ( $action_settings['settings']['batch_settings']['max_wait_seconds'] ?? 600 )
                ),
                'dependency_wait_poll_seconds'     => 10,
            ];

            if ( $this->is_local_first_mapping( $action_settings ) )
            {
                $scheduled = $this->schedule_local_first_after_submission_mapping(
                    $form,
                    $entry,
                    (string) $mapping_id,
                    $action_settings,
                    $submission_uuid,
                    $dependency_context
                );
                $mapping_outcomes[ (string) $mapping_id ] = $scheduled ? 'queued' : 'failed';
                continue;
            }

            $scheduled = $this->plugin->process_action_async(
                $central_action_id,
                [
                    'hook'        => self::NATIVE_AFTER_SUBMISSION_HOOK,
                    'form_source' => $this->get_id(),
                    'form'        => $form,
                    'entry'       => $entry,
                ],
                $action_settings,
                [
                    'hook'              => self::NATIVE_AFTER_SUBMISSION_HOOK,
                    'form_source'       => $this->get_id(),
                    'action_id'         => (string) $mapping_id,
                    'mapping_id'        => (string) $mapping_id,
                    'local_mapping_id'  => (string) $mapping_id,
                    'form_id'           => $form_id,
                    'entry_id'          => null,
                    'submission_uuid'   => $submission_uuid,
                    'central_action_id' => $central_action_id,
                    'action_name_label' => $action_settings['action_name_label'] ?? $central_action_id,
                ] + $dependency_context
            );
            $mapping_outcomes[ (string) $mapping_id ] = $scheduled ? 'queued' : 'failed';
        }
    }

    private function get_form_actions_option_key( mixed $form_id ): string
    {
        return self::FORM_ACTIONS_OPTION_BASE . $this->get_id() . '_' . Sentient_Forms_Provider_Form_Id_Keys::option_suffix( $form_id );
    }

    /**
     * @return array<string, mixed>
     */
    private function get_form_actions_option( mixed $form_id ): array
    {
        $settings = get_option( $this->get_form_actions_option_key( $form_id ), null );
        if ( null === $settings )
        {
            foreach ( Sentient_Forms_Provider_Form_Id_Keys::legacy_option_suffixes( $this->get_id(), $form_id ) as $suffix )
            {
                $settings = get_option( self::FORM_ACTIONS_OPTION_BASE . $this->get_id() . '_' . $suffix, null );
                if ( null !== $settings )
                {
                    break;
                }
            }
        }

        return is_array( $settings ) ? $settings : [];
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function normalize_action_wrapper( array $settings ): array
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

            if ( ! is_array( $mapping ) || empty( $mapping['central_action_id'] ) )
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
     * Merge local-first custom-table mappings into the runtime shape consumed by the dependency planner.
     *
     * @param array<string, mixed> $settings Current form settings.
     * @param mixed                $form_id  Elementor Forms opaque form ID.
     *
     * @return array<string, mixed>
     */
    private function merge_local_first_form_mappings( array $settings, mixed $form_id ): array
    {
        if ( ! class_exists( 'Sentient_Forms_Form_Mappings_Repository' ) )
        {
            return $settings;
        }

        $form_id_string = is_scalar( $form_id ) ? sanitize_text_field( (string) $form_id ) : '';
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
            $settings[ $mapping_id ]            = $runtime_mapping;
            $settings['actions'][ $mapping_id ] = $runtime_mapping;
        }

        return $settings;
    }

    /**
     * Convert a local custom-table mapping row into the after-submission runtime mapping shape.
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

        $lifecycle_id = Sentient_Forms_Form_Source_Lifecycles::normalize_id( $row['hook'] ?? '' );
        if ( Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION !== $lifecycle_id )
        {
            return null;
        }

        if ( 'custom_action' !== sanitize_key( (string) ( $row['action_kind'] ?? '' ) ) )
        {
            return null;
        }

        $custom_action = $this->get_local_first_custom_action( absint( $row['action_id'] ?? 0 ) );
        $identity      = $this->resolve_local_first_action_identity( $custom_action );

        $settings = is_array( $row['settings_json'] ?? null )
            ? $row['settings_json']
            : [];

        $settings['local_form_mapping_id'] = $id;
        $settings['execution_mode']        = Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION;
        $settings['input_mapping']         = is_array( $row['input_bindings_json'] ?? null )
            ? $row['input_bindings_json']
            : [];
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

        $settings = $this->filter_runtime_settings_for_native_capabilities( $settings );

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
     * Remove native side-effect settings Elementor cannot currently execute.
     *
     * @param array<string, mixed> $settings Runtime mapping settings.
     *
     * @return array<string, mixed>
     */
    private function filter_option_backed_runtime_settings_for_native_capabilities( array $action_settings ): array
    {
        if ( isset( $action_settings['settings'] ) && is_array( $action_settings['settings'] ) )
        {
            $action_settings['settings'] = $this->filter_runtime_settings_for_native_capabilities( $action_settings['settings'] );
        }

        return $action_settings;
    }

    private function filter_runtime_settings_for_native_capabilities( array $settings ): array
    {
        $descriptor        = $this->get_capability_descriptor();
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
            unset(
                $effect_mapping['store_result'],
                $effect_mapping['store_result_meta'],
                $effect_mapping['meta']
            );
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

            unset(
                $effect_mapping['spam'],
                $effect_mapping['mark_as_spam']
            );

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
     * @param array<string, mixed>|null $custom_action Local custom action row.
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
     * @param array<string, mixed>|null $custom_action Local custom action row.
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
     * Determine whether a runtime mapping is backed by local WordPress tables.
     *
     * @param array<string, mixed> $mapping Mapping settings.
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
     * Queue a local-first after-submission mapping through the plugin async handler.
     *
     * @param array<string, mixed> $form            Elementor Forms form snapshot.
     * @param array<string, mixed> $entry           Sentient Forms ledger entry snapshot.
     * @param string               $mapping_id      Runtime planner mapping ID.
     * @param array<string, mixed> $action_settings Mapping settings.
     * @param string               $submission_uuid Sentient Forms submission ledger UUID.
     */
    private function schedule_local_first_after_submission_mapping(
        array $form,
        array $entry,
        string $mapping_id,
        array $action_settings,
        string $submission_uuid,
        array $async_context = []
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
                'hook'                  => self::NATIVE_AFTER_SUBMISSION_HOOK,
                'form_source'           => $this->get_id(),
                'mapping_id'            => $mapping_id,
                'local_mapping_id'      => $mapping_id,
                'local_form_mapping_id' => $local_mapping_id,
                'form_id'               => $form['id'] ?? null,
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
     * Resolve whether a mapping should be blocked by prerequisite outcomes.
     *
     * @param array<int, mixed>    $dependency_ids   Dependency mapping IDs.
     * @param array<string,string> $mapping_outcomes Known outcomes keyed by mapping ID.
     */
    private function resolve_dependency_blocking_mapping( array $dependency_ids, array $mapping_outcomes ): ?string
    {
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

            $outcome = $mapping_outcomes[ $dependency_id ] ?? null;
            if ( null === $outcome || 'failed' === $outcome || 'skipped' === $outcome )
            {
                return $dependency_id;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $form_settings
     *
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

        $provider_key      = sanitize_key( $this->get_id() );
        $sf_disabled       = ! empty( $form_settings['sf_disabled'] );
        $global_disabled   = ! empty( $plugin_settings['execution_global_disabled'] );
        $provider_disabled = ! empty( $provider_map[ $provider_key ] );

        return [
            'sf_disabled'       => $sf_disabled,
            'global_disabled'   => $global_disabled,
            'provider_disabled' => $provider_disabled,
            'effective_disabled' => $sf_disabled || $global_disabled || $provider_disabled,
        ];
    }

    /**
     * @param array<string, mixed> $node
     */
    private function is_plan_node_trigger_unbound( array $node, string $hook ): bool
    {
        $lifecycle_hook = Sentient_Forms_Form_Source_Lifecycles::normalize_id( $hook );
        $hook_keys      = array_values( array_unique( array_filter( [ sanitize_key( $hook ), $lifecycle_hook ] ) ) );

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
     * @return array<string, mixed>
     */
    private function form_snapshot( string $form_id ): array
    {
        $form = $this->find_form_by_id( $form_id );

        return [
            'id'          => $form_id,
            'title'       => is_array( $form ) ? $this->form_title( $form ) : sprintf(
                /* translators: %s: Elementor Forms form ID. */
                __( 'Elementor form %s', 'sentient-forms' ),
                $form_id
            ),
            'form_source' => $this->get_id(),
            'fields'      => $this->get_form_fields( $form_id ),
        ];
    }

    /**
     * @param array<string, mixed> $captured
     *
     * @return array<string, mixed>
     */
    private function ledger_entry_snapshot( array $captured, string $submission_uuid ): array
    {
        $entry = [];

        if ( isset( $captured['logical_fields_json'] ) && is_array( $captured['logical_fields_json'] ) )
        {
            $entry = array_merge( $entry, $captured['logical_fields_json'] );
        }

        if ( isset( $captured['file_refs_json'] ) && is_array( $captured['file_refs_json'] ) )
        {
            $entry['file_refs'] = $captured['file_refs_json'];
        }

        $entry['id']              = null;
        $entry['submission_uuid'] = $submission_uuid;
        $entry['form_source']     = $this->get_id();
        $entry['form_id']         = sanitize_text_field( (string) ( $captured['form_id'] ?? '' ) );

        return $entry;
    }

    private function normalize_submission_uuid( mixed $submission_uuid ): ?string
    {
        if ( ! is_scalar( $submission_uuid ) )
        {
            return null;
        }

        $submission_uuid = strtolower( sanitize_text_field( (string) $submission_uuid ) );
        if ( 1 !== preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $submission_uuid ) )
        {
            return null;
        }

        return $submission_uuid;
    }

    private function get_submission_ledger_capture_service(): ?Sentient_Forms_Submission_Ledger_Capture_Service
    {
        if ( ! class_exists( 'Sentient_Forms_Submission_Ledger_Capture_Service' ) )
        {
            return null;
        }

        if ( null === $this->submission_ledger_capture_service )
        {
            global $wpdb;
            $this->submission_ledger_capture_service = new Sentient_Forms_Submission_Ledger_Capture_Service( $wpdb );
        }

        return $this->submission_ledger_capture_service;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function discover_elementor_forms(): array
    {
        if ( null !== $this->discovered_elementor_forms )
        {
            return $this->discovered_elementor_forms;
        }

        $forms = [];

        foreach ( $this->find_posts_with_elementor_data() as $post_id )
        {
            $post_id = absint( $post_id );
            if ( $post_id <= 0 )
            {
                continue;
            }

            foreach ( $this->elementor_data_for_post( $post_id ) as $element )
            {
                $forms = array_merge( $forms, $this->find_form_widgets( $element, $post_id ) );
            }
        }

        $this->discovered_elementor_forms = $forms;

        return $this->discovered_elementor_forms;
    }

    private function resolve_form_id_from_record( mixed $record, mixed $handler ): string
    {
        $filtered = apply_filters( 'sentient_forms_elementor_form_id_from_record', '', $record, $handler, $this );
        if ( is_scalar( $filtered ) )
        {
            $filtered = sanitize_text_field( (string) $filtered );
            if ( null !== $this->find_form_by_id( $filtered ) )
            {
                return $filtered;
            }
        }

        $form_name = $this->record_form_setting( $record, 'form_name' );
        $form_name = is_scalar( $form_name ) ? sanitize_text_field( (string) $form_name ) : '';

        $widget_match = $this->resolve_form_id_from_record_widget_id( $record, $form_name );
        if ( '' !== $widget_match )
        {
            return $widget_match;
        }

        if ( '' === $form_name )
        {
            return '';
        }

        $matches = [];
        foreach ( $this->discover_elementor_forms() as $form )
        {
            if ( $form_name === $this->form_title( $form ) )
            {
                $post_id   = absint( $form['post_id'] ?? 0 );
                $widget_id = isset( $form['widget_id'] ) && is_scalar( $form['widget_id'] )
                    ? sanitize_key( (string) $form['widget_id'] )
                    : '';

                if ( $post_id > 0 && '' !== $widget_id )
                {
                    $matches[] = $this->format_form_id( $post_id, $widget_id );
                }
            }
        }

        return 1 === count( $matches ) ? $matches[0] : '';
    }

    private function resolve_form_id_from_record_widget_id( mixed $record, string $form_name = '' ): string
    {
        $widget_id = $this->record_widget_id( $record );
        if ( '' === $widget_id )
        {
            return '';
        }

        $matches = [];
        foreach ( $this->discover_elementor_forms() as $form )
        {
            $form_widget_id = isset( $form['widget_id'] ) && is_scalar( $form['widget_id'] )
                ? sanitize_key( (string) $form['widget_id'] )
                : '';
            if ( $widget_id !== $form_widget_id )
            {
                continue;
            }

            if ( '' !== $form_name && $form_name !== $this->form_title( $form ) )
            {
                continue;
            }

            $post_id = absint( $form['post_id'] ?? 0 );
            if ( $post_id > 0 )
            {
                $matches[] = $this->format_form_id( $post_id, $widget_id );
            }
        }

        return 1 === count( $matches ) ? $matches[0] : '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find_form_by_id( mixed $form_id ): ?array
    {
        $parsed = $this->parse_form_id( $form_id );
        if ( null === $parsed )
        {
            return null;
        }

        foreach ( $this->discover_elementor_forms() as $form )
        {
            if (
                absint( $form['post_id'] ?? 0 ) === $parsed['post_id']
                && sanitize_key( (string) ( $form['widget_id'] ?? '' ) ) === $parsed['widget_id']
            )
            {
                return $form;
            }
        }

        return null;
    }

    /**
     * @return array{post_id: int, widget_id: string}|null
     */
    private function parse_form_id( mixed $form_id ): ?array
    {
        if ( ! is_scalar( $form_id ) )
        {
            return null;
        }

        $form_id = trim( (string) $form_id );
        if ( 1 !== preg_match( '/^([1-9][0-9]*):([A-Za-z0-9_-]+)$/', $form_id, $matches ) )
        {
            return null;
        }

        return [
            'post_id'   => absint( $matches[1] ),
            'widget_id' => sanitize_key( $matches[2] ),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function find_posts_with_elementor_data(): array
    {
        $post_limit = absint( apply_filters( 'sentient_forms_elementor_discovery_post_limit', 500, $this ) );
        $post_limit = min( 1000, max( 1, $post_limit ) );

        $post_ids = get_posts(
            [
                'fields'                 => 'ids',
                'meta_query'             => [
                    [
                        'key'     => '_elementor_data',
                        'compare' => 'EXISTS',
                    ],
                ],
                'order'                  => 'ASC',
                'orderby'                => 'ID',
                'post_status'            => 'any',
                'post_type'              => 'any',
                'posts_per_page'         => $post_limit,
                'numberposts'            => $post_limit,
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]
        );

        $post_ids = apply_filters( 'sentient_forms_elementor_posts_with_data', $post_ids, $this );

        return is_array( $post_ids )
            ? array_values( array_map( 'absint', $post_ids ) )
            : [];
    }

    /**
     * @return array<int, mixed>
     */
    private function elementor_data_for_post( int $post_id ): array
    {
        $raw_data = get_post_meta( $post_id, '_elementor_data', true );
        $raw_data = apply_filters( 'sentient_forms_elementor_data_for_post', $raw_data, $post_id, $this );

        if ( is_array( $raw_data ) )
        {
            return array_values( $raw_data );
        }

        if ( ! is_string( $raw_data ) || '' === trim( $raw_data ) )
        {
            return [];
        }

        $decoded = json_decode( $raw_data, true );
        if ( ! is_array( $decoded ) )
        {
            $decoded = json_decode( wp_unslash( $raw_data ), true );
        }

        return is_array( $decoded ) ? array_values( $decoded ) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function find_form_widgets( mixed $element, int $post_id ): array
    {
        if ( ! is_array( $element ) )
        {
            return [];
        }

        $forms = [];
        if (
            'widget' === sanitize_key( (string) ( $element['elType'] ?? '' ) )
            && 'form' === sanitize_key( (string) ( $element['widgetType'] ?? '' ) )
        )
        {
            $widget_id = isset( $element['id'] ) && is_scalar( $element['id'] )
                ? sanitize_key( (string) $element['id'] )
                : '';

            if ( '' !== $widget_id )
            {
                $forms[] = [
                    'post_id'   => $post_id,
                    'post_title' => get_the_title( $post_id ),
                    'widget_id' => $widget_id,
                    'settings'  => isset( $element['settings'] ) && is_array( $element['settings'] )
                        ? $element['settings']
                        : [],
                ];
            }
        }

        $children = isset( $element['elements'] ) && is_array( $element['elements'] )
            ? $element['elements']
            : [];

        foreach ( $children as $child )
        {
            $forms = array_merge( $forms, $this->find_form_widgets( $child, $post_id ) );
        }

        return $forms;
    }

    private function format_form_id( int $post_id, string $widget_id ): string
    {
        return $post_id . ':' . sanitize_key( $widget_id );
    }

    /**
     * @param array<string, mixed> $form
     */
    private function form_title( array $form ): string
    {
        $settings = isset( $form['settings'] ) && is_array( $form['settings'] )
            ? $form['settings']
            : [];

        $form_name = isset( $settings['form_name'] ) && is_scalar( $settings['form_name'] )
            ? sanitize_text_field( (string) $settings['form_name'] )
            : '';

        if ( '' !== $form_name )
        {
            return $form_name;
        }

        $post_title = isset( $form['post_title'] ) && is_scalar( $form['post_title'] )
            ? sanitize_text_field( (string) $form['post_title'] )
            : '';

        if ( '' !== $post_title )
        {
            return $post_title;
        }

        return __( 'Untitled Elementor form', 'sentient-forms' );
    }

    /**
     * @return array<string, mixed>
     */
    private function logical_fields_from_record( mixed $record, string $form_id ): array
    {
        $manifest       = $this->field_manifest_by_id( $form_id );
        $logical_fields = [];

        foreach ( $this->record_fields( $record ) as $field_key => $field )
        {
            $field_id = $this->record_field_id( $field_key, $field );
            if ( '' === $field_id )
            {
                continue;
            }

            if ( $this->is_non_storable_field_id( $field_id ) )
            {
                continue;
            }

            $field_label = $this->record_field_label( $field );
            if ( '' !== $field_label && $this->is_non_storable_field_label( $field_label ) )
            {
                continue;
            }

            $manifest_field = $manifest[ $field_id ] ?? null;
            if ( is_array( $manifest_field ) && empty( $manifest_field['storage_eligible'] ) )
            {
                continue;
            }

            $field_type = $this->record_field_type( $field );
            if ( $this->is_non_storable_field_type( $field_type ) )
            {
                continue;
            }

            $logical_fields[ $field_id ] = $this->record_field_value( $field );
        }

        return $logical_fields;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function file_references_from_record( mixed $record, string $form_id ): array
    {
        $manifest   = $this->field_manifest_by_id( $form_id );
        $references = [];

        foreach ( $this->record_fields( $record ) as $field_key => $field )
        {
            $field_id = $this->record_field_id( $field_key, $field );
            if ( '' === $field_id )
            {
                continue;
            }

            if ( $this->is_non_storable_field_id( $field_id ) )
            {
                continue;
            }

            $field_label = $this->record_field_label( $field );
            if ( '' !== $field_label && $this->is_non_storable_field_label( $field_label ) )
            {
                continue;
            }

            $manifest_field = $manifest[ $field_id ] ?? null;
            $field_type     = $this->record_field_type( $field );
            if ( is_array( $manifest_field ) )
            {
                if ( empty( $manifest_field['file_reference_eligible'] ) )
                {
                    continue;
                }
            }
            elseif ( ! $this->is_file_field_type( $field_type ) )
            {
                continue;
            }

            foreach ( $this->normalize_uploaded_file_values( $this->record_field_value( $field ) ) as $file )
            {
                $reference = $this->uploaded_file_reference( $file, $field_id );
                if ( null === $reference )
                {
                    continue;
                }

                $references[] = $reference;
            }
        }

        return $references;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function provider_metadata_from_record( mixed $record, string $form_id ): ?array
    {
        $metadata  = [];
        $form_name = $this->record_form_setting( $record, 'form_name' );
        if ( is_scalar( $form_name ) && '' !== trim( (string) $form_name ) )
        {
            $metadata['form_name'] = sanitize_text_field( (string) $form_name );
        }

        $field_manifest = $this->field_manifest_metadata( $form_id );
        $field_manifest = $this->merge_submitted_record_field_manifest_metadata( $field_manifest, $record );
        if ( [] !== $field_manifest )
        {
            $metadata['field_manifest'] = $field_manifest;
        }

        return [] !== $metadata ? $metadata : null;
    }

    /**
     * @param array<string, array<string, mixed>> $field_manifest
     * @return array<string, array<string, mixed>>
     */
    private function merge_submitted_record_field_manifest_metadata( array $field_manifest, mixed $record ): array
    {
        foreach ( $this->record_fields( $record ) as $field_key => $field )
        {
            $field_id = $this->record_field_id( $field_key, $field );
            if ( '' === $field_id || isset( $field_manifest[ $field_id ] ) )
            {
                continue;
            }

            $row = $this->submitted_record_field_manifest_metadata_row( $field_id, $field );
            if ( null !== $row )
            {
                $field_manifest[ $field_id ] = $row;
            }
        }

        return $field_manifest;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function submitted_record_field_manifest_metadata_row( string $field_id, mixed $field ): ?array
    {
        if ( $this->is_non_storable_field_id( $field_id ) )
        {
            return null;
        }

        $label = $this->record_field_label( $field );
        if ( '' === $label )
        {
            $label = $this->field_label_from_name( $field_id );
        }

        if ( $this->is_non_storable_field_label( $label ) )
        {
            return null;
        }

        $field_type = $this->record_field_type( $field );
        if ( '' === $field_type )
        {
            $field_type = 'unknown';
        }

        $is_hidden       = 'hidden' === $field_type;
        $is_file         = $this->is_file_field_type( $field_type );
        $storage_allowed = ! $is_hidden && ! $this->is_non_storable_field_type( $field_type );
        if ( ! $storage_allowed && ! $is_file )
        {
            return null;
        }

        return [
            'label'                   => $label,
            'adminLabel'              => sprintf(
                /* translators: %s: Elementor Forms field id. */
                __( 'Elementor Forms: %s', 'sentient-forms' ),
                $field_id
            ),
            'type'                    => $field_type,
            'visibility'              => 'visible',
            'storage_eligible'        => $storage_allowed,
            'file_reference_eligible' => $is_file,
            'required'                => false,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function field_manifest_metadata( string $form_id ): array
    {
        $metadata = [];

        foreach ( $this->get_form_fields( $form_id ) as $field )
        {
            if ( ! is_array( $field ) || ! isset( $field['id'] ) || ! is_scalar( $field['id'] ) )
            {
                continue;
            }

            $field_id = sanitize_key( (string) $field['id'] );
            if ( '' === $field_id )
            {
                continue;
            }

            if ( $this->is_non_storable_field_id( $field_id ) )
            {
                continue;
            }

            $field_label = isset( $field['label'] ) && is_scalar( $field['label'] )
                ? sanitize_text_field( (string) $field['label'] )
                : '';
            if ( '' !== $field_label && $this->is_non_storable_field_label( $field_label ) )
            {
                continue;
            }

            $field_type = isset( $field['type'] ) && is_scalar( $field['type'] )
                ? sanitize_key( (string) $field['type'] )
                : '';
            if ( '' !== $field_type && ! $this->is_file_field_type( $field_type ) && $this->is_non_storable_field_type( $field_type ) )
            {
                continue;
            }

            $row = [];
            foreach ( [ 'label', 'adminLabel', 'type', 'visibility', 'field_id_scope', 'field_id_ambiguity_reason' ] as $key )
            {
                if ( isset( $field[ $key ] ) && is_scalar( $field[ $key ] ) )
                {
                    $row[ $key ] = sanitize_text_field( (string) $field[ $key ] );
                }
            }

            foreach ( [ 'storage_eligible', 'file_reference_eligible', 'required', 'field_id_ambiguous' ] as $key )
            {
                if ( array_key_exists( $key, $field ) )
                {
                    $row[ $key ] = (bool) $field[ $key ];
                }
            }

            if ( [] !== $row )
            {
                $metadata[ $field_id ] = $row;
            }
        }

        return $metadata;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function field_manifest_by_id( string $form_id ): array
    {
        $fields = [];
        foreach ( $this->get_form_fields( $form_id ) as $field )
        {
            if ( ! is_array( $field ) || ! isset( $field['id'] ) || ! is_scalar( $field['id'] ) )
            {
                continue;
            }

            $field_id = sanitize_key( (string) $field['id'] );
            if ( '' !== $field_id )
            {
                $fields[ $field_id ] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    private function mark_ambiguous_field_ids( array $fields ): array
    {
        $field_id_counts = [];
        foreach ( $fields as $field )
        {
            $field_id = isset( $field['id'] ) && is_scalar( $field['id'] )
                ? sanitize_key( (string) $field['id'] )
                : '';
            if ( '' !== $field_id )
            {
                $field_id_counts[ $field_id ] = ( $field_id_counts[ $field_id ] ?? 0 ) + 1;
            }
        }

        foreach ( $fields as $index => $field )
        {
            $field_id = isset( $field['id'] ) && is_scalar( $field['id'] )
                ? sanitize_key( (string) $field['id'] )
                : '';
            if ( '' === $field_id || ( $field_id_counts[ $field_id ] ?? 0 ) < 2 )
            {
                continue;
            }

            $fields[ $index ]['field_id_ambiguous']        = true;
            $fields[ $index ]['field_id_scope']            = 'elementor_form';
            $fields[ $index ]['field_id_ambiguity_reason'] = 'duplicate_field_id';
            $fields[ $index ]['storage_eligible']          = false;
            $fields[ $index ]['file_reference_eligible']   = false;
        }

        return $fields;
    }

    /**
     * @return array<string|int, mixed>
     */
    private function record_fields( mixed $record ): array
    {
        $fields = null;

        if ( is_object( $record ) && method_exists( $record, 'get' ) )
        {
            try
            {
                $fields = $record->get( 'fields' );
            }
            catch ( Throwable )
            {
                $fields = null;
            }
        }
        elseif ( is_array( $record ) && isset( $record['fields'] ) )
        {
            $fields = $record['fields'];
        }

        return is_array( $fields ) ? $fields : [];
    }

    private function record_form_setting( mixed $record, string $key ): mixed
    {
        if ( is_object( $record ) && method_exists( $record, 'get_form_settings' ) )
        {
            try
            {
                return $record->get_form_settings( $key );
            }
            catch ( Throwable )
            {
                return null;
            }
        }

        if ( is_array( $record ) && isset( $record['settings'] ) && is_array( $record['settings'] ) )
        {
            return $record['settings'][ $key ] ?? null;
        }

        return null;
    }

    private function record_widget_id( mixed $record ): string
    {
        foreach ( [ 'elementor_widget_id', '_elementor_widget_id', '_elementor_form_id', 'element_id' ] as $candidate_id )
        {
            $value = $this->record_field_value_by_id( $record, $candidate_id );
            if ( is_scalar( $value ) )
            {
                $widget_id = sanitize_key( (string) $value );
                if ( '' !== $widget_id )
                {
                    return $widget_id;
                }
            }
        }

        return '';
    }

    private function record_field_value_by_id( mixed $record, string $target_field_id ): mixed
    {
        $target_field_id = sanitize_key( $target_field_id );
        if ( '' === $target_field_id )
        {
            return null;
        }

        foreach ( $this->record_fields( $record ) as $field_key => $field )
        {
            if ( $target_field_id !== $this->record_field_id( $field_key, $field ) )
            {
                continue;
            }

            return $this->record_field_value( $field );
        }

        return null;
    }

    private function record_field_id( mixed $field_key, mixed $field ): string
    {
        if ( is_array( $field ) )
        {
            foreach ( [ 'id', 'custom_id', 'field_id' ] as $key )
            {
                if ( isset( $field[ $key ] ) && is_scalar( $field[ $key ] ) )
                {
                    $field_id = sanitize_key( (string) $field[ $key ] );
                    if ( '' !== $field_id )
                    {
                        return $field_id;
                    }
                }
            }
        }

        if ( ! is_string( $field_key ) )
        {
            return '';
        }

        return sanitize_key( $field_key );
    }

    private function record_field_type( mixed $field ): string
    {
        if ( is_array( $field ) )
        {
            foreach ( [ 'type', 'field_type' ] as $key )
            {
                if ( isset( $field[ $key ] ) && is_scalar( $field[ $key ] ) )
                {
                    return sanitize_key( (string) $field[ $key ] );
                }
            }
        }

        return '';
    }

    private function record_field_label( mixed $field ): string
    {
        if ( is_array( $field ) )
        {
            foreach ( [ 'title', 'label', 'field_label' ] as $key )
            {
                if ( isset( $field[ $key ] ) && is_scalar( $field[ $key ] ) )
                {
                    $field_label = sanitize_text_field( (string) $field[ $key ] );
                    if ( '' !== $field_label )
                    {
                        return $field_label;
                    }
                }
            }
        }

        return '';
    }

    private function record_field_value( mixed $field ): mixed
    {
        if ( is_array( $field ) && array_key_exists( 'value', $field ) )
        {
            return $field['value'];
        }

        return $field;
    }

    /**
     * @return array<int, mixed>
     */
    private function normalize_uploaded_file_values( mixed $files ): array
    {
        if ( is_array( $files ) )
        {
            if ( $this->is_columnar_uploaded_file_reference_array( $files ) )
            {
                return $this->columnar_uploaded_file_reference_values( $files );
            }

            if ( $this->is_uploaded_file_reference_array( $files ) )
            {
                return [ $files ];
            }

            return array_values( $files );
        }

        return [ $files ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function uploaded_file_reference( mixed $file, string $field_id ): ?array
    {
        $filename = $this->uploaded_file_name( $file );
        if ( '' === $filename )
        {
            return null;
        }

        $reference = [
            'field_id' => $field_id,
            'filename' => $filename,
        ];

        if ( is_array( $file ) )
        {
            $url = $this->uploaded_file_url( $file );
            if ( '' !== $url )
            {
                $reference['url'] = $url;
            }

            $mime_type = $this->uploaded_file_mime_type( $file );
            if ( '' !== $mime_type )
            {
                $reference['mime_type'] = $mime_type;
            }

            if ( isset( $file['size'] ) && is_scalar( $file['size'] ) )
            {
                $size = absint( $file['size'] );
                if ( $size > 0 )
                {
                    $reference['size'] = $size;
                }
            }
        }

        return $reference;
    }

    /**
     * @param array<string|int, mixed> $file
     */
    private function is_uploaded_file_reference_array( array $file ): bool
    {
        foreach ( $this->uploaded_file_reference_keys() as $key )
        {
            if ( array_key_exists( $key, $file ) )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string|int, mixed> $file
     */
    private function is_columnar_uploaded_file_reference_array( array $file ): bool
    {
        foreach ( $this->uploaded_file_reference_keys() as $key )
        {
            if ( isset( $file[ $key ] ) && is_array( $file[ $key ] ) )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string|int, mixed> $file
     * @return array<int, array<string, mixed>>
     */
    private function columnar_uploaded_file_reference_values( array $file ): array
    {
        $columns        = [];
        $scalar_columns = [];
        $row_count      = 0;

        foreach ( $this->uploaded_file_reference_keys() as $key )
        {
            if ( ! array_key_exists( $key, $file ) )
            {
                continue;
            }

            if ( is_array( $file[ $key ] ) )
            {
                $columns[ $key ] = array_values( $file[ $key ] );
                $row_count       = max( $row_count, count( $columns[ $key ] ) );
                continue;
            }

            if ( is_scalar( $file[ $key ] ) )
            {
                $scalar_columns[ $key ] = $file[ $key ];
            }
        }

        $rows = [];
        for ( $index = 0; $index < $row_count; $index++ )
        {
            $row = $scalar_columns;
            foreach ( $columns as $key => $values )
            {
                if ( isset( $values[ $index ] ) && is_scalar( $values[ $index ] ) )
                {
                    $row[ $key ] = $values[ $index ];
                }
            }

            if ( [] !== $row )
            {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function uploaded_file_reference_keys(): array
    {
        return [ 'filename', 'name', 'url', 'tmp_name', 'type', 'mime_type', 'size' ];
    }

    private function uploaded_file_name( mixed $file ): string
    {
        if ( is_array( $file ) )
        {
            foreach ( [ 'filename', 'name' ] as $key )
            {
                if ( isset( $file[ $key ] ) && is_scalar( $file[ $key ] ) )
                {
                    $filename = sanitize_file_name( (string) $file[ $key ] );
                    if ( '' !== $filename )
                    {
                        return $filename;
                    }
                }
            }

            $url_path = $this->uploaded_file_url_path( $file );
            if ( '' !== $url_path )
            {
                return sanitize_file_name( wp_basename( $url_path ) );
            }
        }

        if ( is_scalar( $file ) )
        {
            return $this->uploaded_file_name_from_scalar( $file );
        }

        return '';
    }

    private function uploaded_file_name_from_scalar( mixed $file ): string
    {
        if ( ! is_scalar( $file ) )
        {
            return '';
        }

        $path = str_replace( '\\', '/', (string) $file );
        if ( 1 === preg_match( '#^[a-z][a-z0-9+.-]*://#i', $path ) )
        {
            $url_path = wp_parse_url( esc_url_raw( $path ), PHP_URL_PATH );
            if ( is_string( $url_path ) && '' !== $url_path )
            {
                $path = $url_path;
            }
        }

        return sanitize_file_name( wp_basename( $path ) );
    }

    /**
     * @param array<string|int, mixed> $file
     */
    private function uploaded_file_url( array $file ): string
    {
        if ( ! isset( $file['url'] ) || ! is_scalar( $file['url'] ) )
        {
            return '';
        }

        $url   = esc_url_raw( (string) $file['url'] );
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) )
        {
            return '';
        }

        $path = isset( $parts['path'] ) && is_string( $parts['path'] ) ? $parts['path'] : '';
        if ( '' === $path )
        {
            return '';
        }

        $reduced = '';
        if ( isset( $parts['scheme'] ) && is_string( $parts['scheme'] ) && '' !== $parts['scheme'] )
        {
            $reduced .= $parts['scheme'] . '://';
        }

        if ( isset( $parts['host'] ) && is_string( $parts['host'] ) && '' !== $parts['host'] )
        {
            $reduced .= $parts['host'];
        }

        if ( isset( $parts['port'] ) && is_int( $parts['port'] ) )
        {
            $reduced .= ':' . $parts['port'];
        }

        $reduced .= $path;

        return esc_url_raw( $reduced );
    }

    /**
     * @param array<string|int, mixed> $file
     */
    private function uploaded_file_url_path( array $file ): string
    {
        $url = $this->uploaded_file_url( $file );
        if ( '' === $url )
        {
            return '';
        }

        $path = wp_parse_url( $url, PHP_URL_PATH );

        return is_string( $path ) ? $path : '';
    }

    /**
     * @param array<string|int, mixed> $file
     */
    private function uploaded_file_mime_type( array $file ): string
    {
        foreach ( [ 'mime_type', 'type' ] as $key )
        {
            if ( isset( $file[ $key ] ) && is_scalar( $file[ $key ] ) )
            {
                $mime_type = sanitize_mime_type( (string) $file[ $key ] );
                if ( '' !== $mime_type )
                {
                    return $mime_type;
                }
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalize_elementor_field( mixed $field ): ?array
    {
        if ( ! is_array( $field ) )
        {
            return null;
        }

        $field_id = $this->elementor_field_string( $field, [ 'custom_id', 'id', '_id', 'field_id' ] );
        $field_id = sanitize_key( $field_id );
        if ( '' === $field_id )
        {
            return null;
        }

        $field_type = sanitize_key( $this->elementor_field_string( $field, [ 'field_type', 'type' ] ) );
        if ( '' === $field_type )
        {
            $field_type = 'unknown';
        }

        $label = $this->elementor_field_string( $field, [ 'field_label', 'label' ] );
        if ( '' === $label )
        {
            $label = $this->field_label_from_name( $field_id );
        }

        $is_hidden       = 'hidden' === $field_type;
        $is_file         = $this->is_file_field_type( $field_type );
        $is_safe_file_id = ! $this->is_non_storable_field_id( $field_id )
            && ! $this->is_non_storable_field_label( $label );
        $storage_allowed = ! $is_hidden
            && ! $this->is_non_storable_field_type( $field_type )
            && $is_safe_file_id;

        return [
            'id'                      => $field_id,
            'label'                   => $label,
            'type'                    => $field_type,
            'adminLabel'              => sprintf(
                /* translators: %s: Elementor Forms field id. */
                __( 'Elementor Forms: %s', 'sentient-forms' ),
                $field_id
            ),
            'visibility'              => $is_hidden ? 'hidden' : 'visible',
            'storage_eligible'        => $storage_allowed,
            'file_reference_eligible' => $is_file && $is_safe_file_id,
            'required'                => $this->elementor_field_required( $field ),
        ];
    }

    private function is_file_field_type( string $field_type ): bool
    {
        return in_array( $field_type, [ 'upload', 'file' ], true );
    }

    private function is_non_storable_field_type( string $field_type ): bool
    {
        return $this->is_file_field_type( $field_type )
            || in_array(
                $field_type,
                [
                    'hidden',
                    'html',
                    'step',
                    'recaptcha',
                    'recaptcha_v3',
                    'honeypot',
                    'password',
                    'credit-card-number',
                    'credit_card',
                    'credit-card',
                    'payment',
                    'stripe',
                    'paypal',
                ],
                true
            );
    }

    private function is_non_storable_field_id( string $field_id ): bool
    {
        if ( 1 === preg_match( '/^_?elementor_(form|internal|page|post|provider|raw|record|submission|widget)/', $field_id ) )
        {
            return true;
        }

        foreach ( [ 'captcha', 'csrf', 'nonce', 'honeypot', 'token', 'secret', 'password', 'payment', 'card', 'cvv', 'cvc', 'ccv', 'expiry', 'expiration', 'webhook', 'raw_request', 'raw_provider', 'internal' ] as $needle )
        {
            if ( false !== strpos( $field_id, $needle ) )
            {
                return true;
            }
        }

        return false;
    }

    private function is_non_storable_field_label( string $field_label ): bool
    {
        return $this->is_non_storable_field_id( sanitize_key( $field_label ) );
    }

    /**
     * @param array<string, mixed> $field
     * @param array<int, string>   $keys
     */
    private function elementor_field_string( array $field, array $keys ): string
    {
        foreach ( $keys as $key )
        {
            if ( isset( $field[ $key ] ) && is_scalar( $field[ $key ] ) )
            {
                $value = sanitize_text_field( (string) $field[ $key ] );
                if ( '' !== $value )
                {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $field
     */
    private function elementor_field_required( array $field ): bool
    {
        if ( ! array_key_exists( 'required', $field ) )
        {
            return false;
        }

        $required = $field['required'];
        if ( is_bool( $required ) )
        {
            return $required;
        }

        if ( is_numeric( $required ) )
        {
            return (int) $required > 0;
        }

        if ( is_scalar( $required ) )
        {
            return in_array( strtolower( trim( (string) $required ) ), [ '1', 'true', 'yes', 'on', 'required' ], true );
        }

        return false;
    }

    private function field_label_from_name( string $field_id ): string
    {
        return ucwords( str_replace( [ '-', '_' ], ' ', $field_id ) );
    }
}
