<?php
/**
 * Contact Form 7 adapter.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * First-party Contact Form 7 Form Source adapter.
 */
class Sentient_Forms_Contact_Form_7_Adapter implements Sentient_Forms_Adapter_Interface, Sentient_Forms_Async_Capable_Adapter_Interface
{
    private const FORM_ACTIONS_OPTION_BASE = 'sentient_forms_actions_';

    private const NATIVE_AFTER_SUBMISSION_HOOK = 'wpcf7_mail_sent';

    private Sentient_Forms_Plugin $plugin;

    private ?Sentient_Forms_Submission_Ledger_Capture_Service $submission_ledger_capture_service = null;

    public function __construct( Sentient_Forms_Plugin $plugin )
    {
        $this->plugin = $plugin;
    }

    public function get_id(): string
    {
        return 'contact_form_7';
    }

    public function get_name(): string
    {
        return __( 'Contact Form 7', 'sentient-forms' );
    }

    public function is_active(): bool
    {
        $is_active = class_exists( 'WPCF7_ContactForm' ) && class_exists( 'WPCF7_Submission' );

        return (bool) apply_filters( 'sentient_forms_contact_form_7_is_active', $is_active, $this );
    }

    /**
     * @return array<string, mixed>
     */
    public function get_capability_descriptor(): array
    {
        $is_active = $this->is_active();

        return [
            'slug'                 => 'contact_form_7',
            'label'                => __( 'Contact Form 7', 'sentient-forms' ),
            'availability'         => $is_active ? 'available' : 'not_installed',
            'availability_message' => $is_active
                ? __( 'Contact Form 7 is active. Sentient Forms can run after-submission actions after ledger opt-in.', 'sentient-forms' )
                : __( 'Install and activate Contact Form 7 to configure Sentient Forms after-submission actions.', 'sentient-forms' ),
            'forms_discovery'      => [
                'supported' => $is_active,
                'reason'    => $is_active ? null : __( 'Contact Form 7 must be active before forms can be listed.', 'sentient-forms' ),
            ],
            'field_manifest'       => [
                'supported' => $is_active,
                'reason'    => $is_active ? null : __( 'Contact Form 7 must be active before fields can be inspected.', 'sentient-forms' ),
            ],
            'lifecycles'           => [
                'validation'       => [
                    'supported'          => false,
                    'label'              => __( 'Validation', 'sentient-forms' ),
                    'native_hook'        => null,
                    'execution_mode'     => 'blocking',
                    'requires_ledger'    => false,
                    'unsupported_reason' => __( 'Contact Form 7 validation blocking is not supported in this release.', 'sentient-forms' ),
                ],
                'after_submission' => [
                    'supported'          => true,
                    'label'              => __( 'After submission', 'sentient-forms' ),
                    'native_hook'        => self::NATIVE_AFTER_SUBMISSION_HOOK,
                    'execution_mode'     => 'async',
                    'requires_ledger'    => true,
                    'unsupported_reason' => null,
                ],
                'real_time'        => [
                    'supported'          => false,
                    'label'              => __( 'Real time', 'sentient-forms' ),
                    'native_hook'        => null,
                    'execution_mode'     => 'real_time',
                    'requires_ledger'    => false,
                    'unsupported_reason' => __( 'Realtime Contact Form 7 support is not available in this release.', 'sentient-forms' ),
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
                'unavailable_reason'  => __( 'Enable the Sentient Forms Submission Ledger before reviewing Contact Form 7 submissions in Sentient Forms.', 'sentient-forms' ),
            ],
            'requirements'         => [
                'plugin'         => 'contact-form-7/wp-contact-form-7.php',
                'module'         => null,
                'requires_pro'   => false,
                'requires_addon' => false,
            ],
        ];
    }

    public function init(): void
    {
        if ( ! $this->is_active() )
        {
            return;
        }

        add_action( 'wpcf7_mail_sent', [ $this, 'handle_mail_sent' ], 10, 1 );
    }

    public function get_forms(): array
    {
        if ( ! $this->is_active() )
        {
            return [];
        }

        $forms  = $this->find_contact_forms();
        $result = [];

        foreach ( $forms as $form )
        {
            $form_id = $this->contact_form_id( $form );
            if ( $form_id <= 0 )
            {
                continue;
            }

            $result[] = [
                'id'                 => $form_id,
                'title'              => $this->contact_form_title( $form, $form_id ),
                'adapter'            => $this->get_id(),
                'adapter_name'       => $this->get_name(),
                'provider_is_active' => true,
                'provider_edit_url'  => admin_url(
                    sprintf(
                        'admin.php?page=wpcf7&post=%d&action=edit',
                        $form_id
                    )
                ),
                'settings'           => null,
            ];
        }

        return $result;
    }

    public function get_form_fields( $form_id ): array
    {
        if ( ! $this->is_active() )
        {
            return [];
        }

        $form = $this->get_form_object( absint( $form_id ) );
        if ( null === $form )
        {
            return [];
        }

        $fields = [];
        foreach ( $this->scan_form_tags( $form ) as $tag )
        {
            $field = $this->normalize_form_tag( $tag );
            if ( null !== $field )
            {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Return an async-safe form snapshot instead of exposing raw CF7 objects to queue processors.
     *
     * @return array<string, mixed>|null
     */
    public function get_form_data( mixed $form_id ): ?array
    {
        if ( ! $this->is_active() )
        {
            return null;
        }

        $form_id = absint( $form_id );
        if ( $form_id <= 0 )
        {
            return null;
        }

        $form = $this->get_form_object( $form_id );
        if ( null === $form )
        {
            return null;
        }

        return $this->form_snapshot( $form, $form_id );
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

        $entry = [
            'id'              => null,
            'submission_uuid' => $submission_uuid,
            'form_source'     => $this->get_id(),
            'form_id'         => sanitize_text_field( (string) ( $record['form_id'] ?? '' ) ),
        ];

        if ( isset( $record['logical_fields_json'] ) && is_array( $record['logical_fields_json'] ) )
        {
            $entry = array_merge( $entry, $record['logical_fields_json'] );
        }

        if ( isset( $record['file_refs_json'] ) && is_array( $record['file_refs_json'] ) )
        {
            $entry['file_refs'] = $record['file_refs_json'];
        }

        return $entry;
    }

    public function handle_mail_sent( mixed $contact_form ): ?string
    {
        if ( ! $this->is_active() )
        {
            return null;
        }

        $form_id = $this->contact_form_id( $contact_form );
        if ( $form_id <= 0 )
        {
            return null;
        }

        $submission = $this->current_submission( $contact_form );
        if ( null === $submission )
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
                'form_source'    => $this->get_id(),
                'form_id'        => (string) $form_id,
                'logical_fields' => $this->logical_fields_from_submission( $submission ),
                'files'          => $this->file_references_from_submission( $submission ),
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

        $this->schedule_after_submission_actions( $contact_form, $submission_uuid, $captured );

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
        $lifecycle_id = Sentient_Forms_Form_Source_Lifecycles::normalize_id( $event_name );

        return Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION === $lifecycle_id ? self::NATIVE_AFTER_SUBMISSION_HOOK : null;
    }

    public function get_form_object( int $form_id ): object | array | null
    {
        if ( $form_id <= 0 )
        {
            return null;
        }

        $form = function_exists( 'wpcf7_contact_form' ) ? wpcf7_contact_form( $form_id ) : null;
        $form = apply_filters( 'sentient_forms_contact_form_7_form_object', $form, $form_id, $this );

        return is_object( $form ) || is_array( $form ) ? $form : null;
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

    /**
     * @param array<string, mixed> $captured
     */
    private function schedule_after_submission_actions( mixed $contact_form, string $submission_uuid, array $captured ): void
    {
        $form_id = $this->contact_form_id( $contact_form );
        if ( $form_id <= 0 )
        {
            return;
        }

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
        $form  = $this->form_snapshot( $contact_form, $form_id );
        $entry = $this->ledger_entry_snapshot( $captured, $submission_uuid );

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

            $action_settings = $node['mapping'];
            $action_settings['local_mapping_id'] = $action_settings['local_mapping_id'] ?? $mapping_id;

            $central_action_id = isset( $action_settings['central_action_id'] ) && is_scalar( $action_settings['central_action_id'] )
                ? sanitize_key( (string) $action_settings['central_action_id'] )
                : '';
            if ( '' === $central_action_id )
            {
                continue;
            }

            if ( ! $this->plugin->get_condition_evaluator()->should_execute( $action_settings, $entry ) )
            {
                continue;
            }

            if ( $this->is_local_first_mapping( $action_settings ) )
            {
                $this->schedule_local_first_after_submission_mapping(
                    $form,
                    $entry,
                    (string) $mapping_id,
                    $action_settings,
                    $submission_uuid
                );
                continue;
            }

            $this->plugin->process_action_async(
                $central_action_id,
                [
                    'hook'  => self::NATIVE_AFTER_SUBMISSION_HOOK,
                    'form'  => $form,
                    'entry' => $entry,
                ],
                $action_settings,
                [
                    'hook'              => self::NATIVE_AFTER_SUBMISSION_HOOK,
                    'form_source'       => $this->get_id(),
                    'action_id'         => (string) $mapping_id,
                    'mapping_id'        => (string) $mapping_id,
                    'local_mapping_id'  => (string) $mapping_id,
                    'form_id'           => (string) $form_id,
                    'entry_id'          => null,
                    'submission_uuid'   => $submission_uuid,
                    'central_action_id' => $central_action_id,
                    'action_name_label' => $action_settings['action_name_label'] ?? $central_action_id,
                ]
            );
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function find_contact_forms(): array
    {
        $forms = [];

        if ( class_exists( 'WPCF7_ContactForm' ) && method_exists( 'WPCF7_ContactForm', 'find' ) )
        {
            $forms = WPCF7_ContactForm::find(
                [
                    'posts_per_page' => -1,
                    'orderby'        => 'ID',
                    'order'          => 'ASC',
                ]
            );
        }

        if ( ! is_array( $forms ) )
        {
            $forms = [];
        }

        $forms = apply_filters( 'sentient_forms_contact_form_7_forms', $forms, $this );

        return is_array( $forms ) ? $forms : [];
    }

    private function contact_form_id( mixed $form ): int
    {
        if ( is_object( $form ) && method_exists( $form, 'id' ) )
        {
            return absint( $form->id() );
        }

        if ( is_array( $form ) && isset( $form['id'] ) )
        {
            return absint( $form['id'] );
        }

        return 0;
    }

    private function contact_form_title( mixed $form, int $form_id ): string
    {
        if ( is_object( $form ) && method_exists( $form, 'title' ) )
        {
            $title = sanitize_text_field( (string) $form->title() );
            if ( '' !== $title )
            {
                return $title;
            }
        }

        if ( is_array( $form ) && isset( $form['title'] ) )
        {
            $title = sanitize_text_field( (string) $form['title'] );
            if ( '' !== $title )
            {
                return $title;
            }
        }

        return sprintf(
            /* translators: %d: Contact Form 7 form ID. */
            __( 'Contact Form 7 form #%d', 'sentient-forms' ),
            $form_id
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function get_form_settings( int $form_id ): array
    {
        $settings = get_option( $this->get_form_actions_option_key( $form_id ), [] );
        if ( ! is_array( $settings ) )
        {
            return [];
        }

        return $this->merge_local_first_form_mappings( $this->normalize_action_wrapper( $settings ), $form_id );
    }

    public function update_form_settings( mixed $form_id, array $settings ): bool
    {
        return update_option( $this->get_form_actions_option_key( absint( $form_id ) ), $settings, false );
    }

    private function get_form_actions_option_key( int $form_id ): string
    {
        return self::FORM_ACTIONS_OPTION_BASE . $this->get_id() . '_' . absint( $form_id );
    }

    /**
     * Merge local-first custom-table mappings into the runtime shape consumed by the planner.
     *
     * @param array<string, mixed> $settings Current form settings.
     * @param mixed                $form_id  Contact Form 7 form ID.
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
     * @param array<string, mixed> $form            Contact Form 7 form snapshot.
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
        string $submission_uuid
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
            ]
        );
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
            if ( ! is_array( $mapping ) )
            {
                continue;
            }

            $settings[ (string) $mapping_id ] = $mapping;
        }

        return $settings;
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

    private function current_submission( mixed $contact_form ): mixed
    {
        $submission = null;

        if ( class_exists( 'WPCF7_Submission' ) && method_exists( 'WPCF7_Submission', 'get_instance' ) )
        {
            $submission = WPCF7_Submission::get_instance();
        }

        $submission = apply_filters( 'sentient_forms_contact_form_7_current_submission', $submission, $contact_form, $this );

        return $submission;
    }

    /**
     * @return array<string, mixed>
     */
    private function logical_fields_from_submission( mixed $submission ): array
    {
        $posted_data = [];

        if ( is_object( $submission ) && method_exists( $submission, 'get_posted_data' ) )
        {
            $posted_data = $submission->get_posted_data();
        }
        elseif ( is_array( $submission ) )
        {
            $posted_data = $submission;
        }

        if ( ! is_array( $posted_data ) )
        {
            return [];
        }

        $logical_fields = [];
        foreach ( $posted_data as $key => $value )
        {
            if ( ! is_scalar( $key ) )
            {
                continue;
            }

            $field_key = sanitize_key( (string) $key );
            if ( '' === $field_key || $this->is_system_posted_field( $field_key ) )
            {
                continue;
            }

            $logical_fields[ $field_key ] = $value;
        }

        return $logical_fields;
    }

    private function is_system_posted_field( string $field_key ): bool
    {
        return str_starts_with( $field_key, '_wpcf7' )
            || str_starts_with( $field_key, '_wpnonce' )
            || str_starts_with( $field_key, 'g-recaptcha-response' );
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

    /**
     * @return array<int, array<string, string>>
     */
    private function file_references_from_submission( mixed $submission ): array
    {
        $uploaded_files = [];

        if ( is_object( $submission ) && method_exists( $submission, 'uploaded_files' ) )
        {
            $uploaded_files = $submission->uploaded_files();
        }
        elseif ( is_array( $submission ) && isset( $submission['uploaded_files'] ) && is_array( $submission['uploaded_files'] ) )
        {
            $uploaded_files = $submission['uploaded_files'];
        }

        if ( ! is_array( $uploaded_files ) )
        {
            return [];
        }

        $references = [];
        foreach ( $uploaded_files as $field_id => $files )
        {
            if ( ! is_scalar( $field_id ) )
            {
                continue;
            }

            $field_key = sanitize_key( (string) $field_id );
            if ( '' === $field_key )
            {
                continue;
            }

            foreach ( $this->normalize_uploaded_file_values( $files ) as $file )
            {
                $filename = $this->uploaded_file_name( $file );
                if ( '' === $filename )
                {
                    continue;
                }

                $references[] = [
                    'field_id' => $field_key,
                    'filename' => $filename,
                ];
            }
        }

        return $references;
    }

    /**
     * @return array<int, mixed>
     */
    private function normalize_uploaded_file_values( mixed $files ): array
    {
        if ( is_array( $files ) )
        {
            return array_values( $files );
        }

        return [ $files ];
    }

    private function uploaded_file_name( mixed $file ): string
    {
        if ( is_array( $file ) && isset( $file['name'] ) && is_scalar( $file['name'] ) )
        {
            return sanitize_file_name( (string) $file['name'] );
        }

        if ( is_scalar( $file ) )
        {
            return sanitize_file_name( wp_basename( str_replace( '\\', '/', (string) $file ) ) );
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function form_snapshot( mixed $contact_form, int $form_id ): array
    {
        return [
            'id'          => (string) $form_id,
            'title'       => $this->contact_form_title( $contact_form, $form_id ),
            'form_source' => $this->get_id(),
        ];
    }

    /**
     * @param array<string, mixed> $captured
     *
     * @return array<string, mixed>
     */
    private function ledger_entry_snapshot( array $captured, string $submission_uuid ): array
    {
        $entry = [
            'submission_uuid' => $submission_uuid,
        ];

        if ( isset( $captured['logical_fields_json'] ) && is_array( $captured['logical_fields_json'] ) )
        {
            $entry = array_merge( $entry, $captured['logical_fields_json'] );
        }

        if ( isset( $captured['file_refs_json'] ) && is_array( $captured['file_refs_json'] ) )
        {
            $entry['file_refs'] = $captured['file_refs_json'];
        }

        return $entry;
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
     * @return array<int, mixed>
     */
    private function scan_form_tags( object | array $form ): array
    {
        $tags = [];

        if ( is_object( $form ) && method_exists( $form, 'scan_form_tags' ) )
        {
            $tags = $form->scan_form_tags();
        }
        elseif ( is_array( $form ) && isset( $form['fields'] ) && is_array( $form['fields'] ) )
        {
            $tags = $form['fields'];
        }

        if ( ! is_array( $tags ) )
        {
            $tags = [];
        }

        $tags = apply_filters( 'sentient_forms_contact_form_7_form_tags', $tags, $form, $this );

        return is_array( $tags ) ? $tags : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalize_form_tag( mixed $tag ): ?array
    {
        $name = $this->form_tag_value( $tag, 'name' );
        if ( '' === $name )
        {
            return null;
        }

        $field_id = sanitize_key( $name );
        if ( '' === $field_id )
        {
            return null;
        }

        $raw_type = $this->form_tag_value( $tag, 'type' );
        $basetype = $this->form_tag_value( $tag, 'basetype' );
        if ( '' === $basetype )
        {
            $basetype = rtrim( $raw_type, '*' );
        }

        $basetype = sanitize_key( $basetype );
        if ( '' === $basetype )
        {
            $basetype = 'unknown';
        }

        $is_file   = 'file' === $basetype;
        $is_hidden = 'hidden' === $basetype;
        $is_system = in_array( $basetype, [ 'submit', 'captcha', 'quiz' ], true );

        return [
            'id'                      => $field_id,
            'label'                   => $this->field_label_from_name( $field_id ),
            'type'                    => $basetype,
            'adminLabel'              => sprintf(
                /* translators: %s: Contact Form 7 field name. */
                __( 'Contact Form 7: %s', 'sentient-forms' ),
                $field_id
            ),
            'visibility'              => $is_hidden ? 'hidden' : 'visible',
            'storage_eligible'        => ! $is_hidden && ! $is_file && ! $is_system,
            'file_reference_eligible' => $is_file,
            'required'                => $this->form_tag_is_required( $tag, $raw_type ),
        ];
    }

    private function form_tag_value( mixed $tag, string $key ): string
    {
        if ( is_object( $tag ) && isset( $tag->{$key} ) && is_scalar( $tag->{$key} ) )
        {
            return sanitize_text_field( (string) $tag->{$key} );
        }

        if ( is_array( $tag ) && isset( $tag[ $key ] ) && is_scalar( $tag[ $key ] ) )
        {
            return sanitize_text_field( (string) $tag[ $key ] );
        }

        return '';
    }

    private function form_tag_is_required( mixed $tag, string $raw_type ): bool
    {
        if ( is_object( $tag ) && method_exists( $tag, 'is_required' ) )
        {
            return (bool) $tag->is_required();
        }

        return str_ends_with( $raw_type, '*' );
    }

    private function field_label_from_name( string $field_id ): string
    {
        return ucwords( str_replace( [ '-', '_' ], ' ', $field_id ) );
    }
}
