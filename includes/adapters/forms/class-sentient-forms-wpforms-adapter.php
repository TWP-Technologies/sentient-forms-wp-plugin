<?php
/**
 * WPForms adapter.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * First-party WPForms Form Source adapter.
 */
class Sentient_Forms_WPForms_Adapter implements Sentient_Forms_Adapter_Interface, Sentient_Forms_Async_Capable_Adapter_Interface
{
    private const NATIVE_AFTER_SUBMISSION_HOOK = 'wpforms_process_complete';
    private const FORM_POST_TYPE = 'wpforms';
    private const NON_POSTING_FIELD_TYPES = [
        'captcha',
        'divider',
        'entry-preview',
        'html',
        'internal-information',
        'layout',
        'pagebreak',
        'repeater',
    ];

    private Sentient_Forms_Plugin $plugin;

    private ?Sentient_Forms_Submission_Ledger_Capture_Service $submission_ledger_capture_service = null;

    public function __construct( Sentient_Forms_Plugin $plugin )
    {
        $this->plugin = $plugin;
    }

    public function get_id(): string
    {
        return 'wpforms';
    }

    public function get_name(): string
    {
        return __( 'WPForms', 'sentient-forms' );
    }

    public function is_active(): bool
    {
        $is_active = function_exists( 'wpforms' )
            || defined( 'WPFORMS_VERSION' )
            || class_exists( 'WPForms' );

        return (bool) apply_filters( 'sentient_forms_wpforms_is_active', $is_active, $this );
    }

    /**
     * @return array<string, mixed>
     */
    public function get_capability_descriptor(): array
    {
        $is_active                      = $this->is_active();
        $native_entry_storage_available = $is_active && $this->native_entry_storage_available();

        return [
            'slug'                 => 'wpforms',
            'label'                => __( 'WPForms', 'sentient-forms' ),
            'availability'         => $is_active ? 'available' : 'not_installed',
            'availability_message' => $is_active
                ? __( 'WPForms is active. Sentient Forms can run after-submission actions after ledger opt-in.', 'sentient-forms' )
                : __( 'Install and activate WPForms to configure Sentient Forms after-submission actions.', 'sentient-forms' ),
            'forms_discovery'      => [
                'supported' => $is_active,
                'reason'    => $is_active ? null : __( 'WPForms must be active before forms can be listed.', 'sentient-forms' ),
            ],
            'field_manifest'       => [
                'supported' => $is_active,
                'reason'    => $is_active ? null : __( 'WPForms must be active before fields can be inspected.', 'sentient-forms' ),
            ],
            'lifecycles'           => [
                'validation'       => [
                    'supported'          => false,
                    'label'              => __( 'Validation', 'sentient-forms' ),
                    'native_hook'        => null,
                    'execution_mode'     => 'blocking',
                    'requires_ledger'    => false,
                    'unsupported_reason' => __( 'WPForms validation blocking is not supported in this release.', 'sentient-forms' ),
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
                    'unsupported_reason' => __( 'Realtime WPForms support is not available in this release.', 'sentient-forms' ),
                ],
            ],
            'native_entry'         => [
                'id'    => $native_entry_storage_available,
                'link'  => $native_entry_storage_available,
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
                'unavailable_reason'  => __( 'Enable the Sentient Forms Submission Ledger before reviewing WPForms submissions in Sentient Forms.', 'sentient-forms' ),
            ],
            'requirements'         => [
                'plugin'         => 'wpforms-lite/wpforms.php',
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

        add_action( self::NATIVE_AFTER_SUBMISSION_HOOK, [ $this, 'handle_process_complete' ], 10, 4 );
    }

    public function get_forms(): array
    {
        if ( ! $this->is_active() )
        {
            return [];
        }

        $forms  = $this->find_wpforms_posts();
        $result = [];

        foreach ( $forms as $form )
        {
            $summary = $this->form_summary( $form );
            if ( null !== $summary )
            {
                $result[] = $summary;
            }
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

        $fields = function_exists( 'wpforms_get_form_fields' ) ? wpforms_get_form_fields( $form ) : false;
        if ( ! is_array( $fields ) )
        {
            $form_data = $this->form_data( $form );
            $fields    = is_array( $form_data['fields'] ?? null ) ? $form_data['fields'] : [];
        }

        $manifest = [];
        foreach ( $fields as $field_key => $field )
        {
            $normalized = $this->normalize_field( $field, $field_key );
            if ( null !== $normalized )
            {
                $manifest[] = $normalized;
            }
        }

        return $manifest;
    }

    public function get_entry_data( $entry_id, $form_id = null )
    {
        if ( ! class_exists( 'Sentient_Forms_Submission_Ledger_Repository' ) )
        {
            return null;
        }

        global $wpdb;
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $submission_uuid = $this->normalize_submission_uuid( $entry_id );
        $record          = null;

        if ( null !== $submission_uuid )
        {
            $record = $ledger->get_by_submission_uuid( $submission_uuid );
        }
        else
        {
            $record = $this->ledger_record_for_native_entry_id( $ledger, $entry_id, $form_id );
            if ( is_array( $record ) && isset( $record['submission_uuid'] ) && is_scalar( $record['submission_uuid'] ) )
            {
                $submission_uuid = $this->normalize_submission_uuid( $record['submission_uuid'] );
            }
        }

        if ( ! is_array( $record ) || $this->get_id() !== sanitize_key( (string) ( $record['form_source'] ?? '' ) ) )
        {
            return null;
        }

        $requested_form_id = is_scalar( $form_id ) ? sanitize_text_field( (string) $form_id ) : '';
        if ( '' !== $requested_form_id && $requested_form_id !== sanitize_text_field( (string) ( $record['form_id'] ?? '' ) ) )
        {
            return null;
        }

        if ( null === $submission_uuid )
        {
            return null;
        }

        return $this->ledger_entry_snapshot( $record, $submission_uuid );
    }

    public function handle_process_complete( mixed $fields, mixed $entry, mixed $form_data, mixed $entry_id ): ?string
    {
        if ( ! $this->is_active() )
        {
            return null;
        }

        $form_id = $this->form_id( $form_data );
        if ( $form_id <= 0 )
        {
            return null;
        }

        $capture_service = $this->get_submission_ledger_capture_service();
        if ( null === $capture_service )
        {
            return null;
        }

        $payload         = [
            'form_source'    => $this->get_id(),
            'form_id'        => (string) $form_id,
            'logical_fields' => $this->logical_fields_from_process_fields( $fields ),
            'files'          => $this->file_references_from_process_fields( $fields ),
        ];
        $native_entry_id = absint( $entry_id );
        if ( $native_entry_id > 0 )
        {
            $payload['native_entry_id'] = (string) $native_entry_id;

            $native_entry_url = $this->build_native_entry_url_if_available( $form_id, $native_entry_id );
            if ( null !== $native_entry_url )
            {
                $payload['native_entry_url'] = $native_entry_url;
            }
        }

        $captured = $capture_service->capture( $payload );
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

        $this->schedule_after_submission_actions( $form_data, $submission_uuid, $captured );

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

        $form_handler = $this->wpforms_object( 'form' );
        if ( is_object( $form_handler ) && method_exists( $form_handler, 'get' ) )
        {
            $form = $form_handler->get( $form_id, [ 'cap' => false ] );
            if ( is_object( $form ) || is_array( $form ) )
            {
                return $form;
            }
        }

        $form = get_post( $form_id );

        if ( $form instanceof WP_Post && self::FORM_POST_TYPE === $form->post_type )
        {
            return $form;
        }

        return null;
    }

    /**
     * Return a prompt-ready form snapshot for deferred local-first execution.
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
     * @return array<int, mixed>
     */
    private function find_wpforms_posts(): array
    {
        $form_handler = $this->wpforms_object( 'form' );
        if ( is_object( $form_handler ) && method_exists( $form_handler, 'get' ) )
        {
            $forms = $form_handler->get(
                '',
                [
                    'post_type'              => self::FORM_POST_TYPE,
                    'post_status'            => 'publish',
                    'orderby'                => 'id',
                    'order'                  => 'ASC',
                    'cap'                    => false,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                ]
            );

            if ( is_array( $forms ) )
            {
                return array_values( $forms );
            }
        }

        return get_posts(
            [
                'post_type'              => self::FORM_POST_TYPE,
                'post_status'            => 'publish',
                'orderby'                => 'ID',
                'order'                  => 'ASC',
                'numberposts'            => -1,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function form_summary( mixed $form ): ?array
    {
        $form_id = $this->form_id( $form );
        if ( $form_id <= 0 )
        {
            return null;
        }

        return [
            'id'                 => $form_id,
            'title'              => $this->form_title( $form, $form_id ),
            'adapter'            => $this->get_id(),
            'adapter_name'       => $this->get_name(),
            'provider_is_active' => true,
            'provider_edit_url'  => add_query_arg(
                [
                    'view'    => 'fields',
                    'form_id' => $form_id,
                ],
                admin_url( 'admin.php?page=wpforms-builder' )
            ),
            'settings'           => null,
        ];
    }

    private function form_id( mixed $form ): int
    {
        if ( $form instanceof WP_Post )
        {
            return absint( $form->ID );
        }

        if ( is_object( $form ) && isset( $form->ID ) && is_scalar( $form->ID ) )
        {
            return absint( $form->ID );
        }

        if ( is_array( $form ) && isset( $form['id'] ) && is_scalar( $form['id'] ) )
        {
            return absint( $form['id'] );
        }

        return 0;
    }

    private function form_title( mixed $form, int $form_id ): string
    {
        if ( $form instanceof WP_Post )
        {
            $title = get_the_title( $form );
            if ( is_string( $title ) && '' !== trim( $title ) )
            {
                return sanitize_text_field( $title );
            }
        }

        if ( is_object( $form ) && isset( $form->post_title ) && is_scalar( $form->post_title ) )
        {
            $title = sanitize_text_field( (string) $form->post_title );
            if ( '' !== $title )
            {
                return $title;
            }
        }

        if ( is_array( $form ) )
        {
            $title = isset( $form['settings']['form_title'] ) && is_scalar( $form['settings']['form_title'] )
                ? sanitize_text_field( (string) $form['settings']['form_title'] )
                : '';

            if ( '' !== $title )
            {
                return $title;
            }
        }

        return sprintf(
            /* translators: %d: WPForms form ID. */
            __( 'WPForms form #%d', 'sentient-forms' ),
            $form_id
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function form_data( mixed $form ): array
    {
        if ( is_array( $form ) )
        {
            return $form;
        }

        $post_content = null;
        if ( $form instanceof WP_Post )
        {
            $post_content = $form->post_content;
        }
        elseif ( is_object( $form ) && isset( $form->post_content ) && is_scalar( $form->post_content ) )
        {
            $post_content = (string) $form->post_content;
        }

        if ( null === $post_content || '' === $post_content )
        {
            return [];
        }

        if ( function_exists( 'wpforms_decode' ) )
        {
            $decoded = wpforms_decode( $post_content );
            if ( is_array( $decoded ) )
            {
                return $decoded;
            }
        }

        $decoded = json_decode( (string) $post_content, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) )
        {
            return [];
        }

        return wp_unslash( $decoded );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalize_field( mixed $field, mixed $field_key ): ?array
    {
        if ( ! is_array( $field ) )
        {
            return null;
        }

        $type = isset( $field['type'] ) && is_scalar( $field['type'] )
            ? sanitize_key( (string) $field['type'] )
            : '';

        if ( '' === $type || in_array( $type, self::NON_POSTING_FIELD_TYPES, true ) )
        {
            return null;
        }

        $field_id = isset( $field['id'] ) && is_scalar( $field['id'] )
            ? sanitize_text_field( (string) $field['id'] )
            : sanitize_text_field( (string) $field_key );

        if ( '' === $field_id )
        {
            return null;
        }

        $label     = $this->field_label( $field, $field_id );
        $is_hidden = 'hidden' === $type;
        $is_file   = 'file-upload' === $type;

        return [
            'id'                      => $field_id,
            'label'                   => $label,
            'type'                    => $type,
            'adminLabel'              => $this->field_admin_label( $field, $label ),
            'visibility'              => $is_hidden ? 'hidden' : 'visible',
            'storage_eligible'        => ! $is_hidden && ! $is_file,
            'file_reference_eligible' => $is_file,
            'required'                => $this->field_required( $field ),
        ];
    }

    /**
     * @param array<string, mixed> $field
     */
    private function field_label( array $field, string $field_id ): string
    {
        if ( isset( $field['label'] ) && is_scalar( $field['label'] ) )
        {
            $label = sanitize_text_field( (string) $field['label'] );
            if ( '' !== $label )
            {
                return $label;
            }
        }

        return sprintf(
            /* translators: %s: WPForms field ID. */
            __( 'Field %s', 'sentient-forms' ),
            $field_id
        );
    }

    /**
     * @param array<string, mixed> $field
     */
    private function field_admin_label( array $field, string $label ): string
    {
        foreach ( [ 'admin_label', 'adminLabel' ] as $key )
        {
            if ( isset( $field[ $key ] ) && is_scalar( $field[ $key ] ) )
            {
                $admin_label = sanitize_text_field( (string) $field[ $key ] );
                if ( '' !== $admin_label )
                {
                    return $admin_label;
                }
            }
        }

        return sprintf(
            /* translators: %s: WPForms field label. */
            __( 'WPForms: %s', 'sentient-forms' ),
            $label
        );
    }

    /**
     * @param array<string, mixed> $field
     */
    private function field_required( array $field ): bool
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

        if ( is_scalar( $required ) )
        {
            return ! in_array( strtolower( (string) $required ), [ '', '0', 'false', 'no' ], true );
        }

        return false;
    }

    private function wpforms_object( string $name ): mixed
    {
        if ( ! function_exists( 'wpforms' ) )
        {
            return null;
        }

        $wpforms = wpforms();
        if ( ! is_object( $wpforms ) || ! method_exists( $wpforms, 'obj' ) )
        {
            return null;
        }

        return $wpforms->obj( $name );
    }

    private function build_native_entry_url_if_available( int $form_id, int $entry_id ): ?string
    {
        if ( $form_id <= 0 || $entry_id <= 0 || ! $this->native_entry_exists( $form_id, $entry_id ) )
        {
            return null;
        }

        return admin_url(
            add_query_arg(
                [
                    'page'     => 'wpforms-entries',
                    'view'     => 'details',
                    'entry_id' => $entry_id,
                ],
                'admin.php'
            )
        );
    }

    private function native_entry_exists( int $form_id, int $entry_id ): bool
    {
        $filtered = apply_filters( 'sentient_forms_wpforms_native_entry_available', null, $form_id, $entry_id, $this );
        if ( is_bool( $filtered ) )
        {
            return $filtered;
        }

        $entry_object = $this->native_entry_from_wpforms_object( $form_id, $entry_id );
        if ( null !== $entry_object )
        {
            return true;
        }

        return $this->native_entry_exists_in_wpforms_table( $form_id, $entry_id );
    }

    private function native_entry_storage_available(): bool
    {
        $filtered = apply_filters( 'sentient_forms_wpforms_native_entry_storage_available', null, $this );
        if ( is_bool( $filtered ) )
        {
            return $filtered;
        }

        global $wpdb;

        return $this->table_exists( $wpdb->prefix . 'wpforms_entries' );
    }

    private function native_entry_from_wpforms_object( int $form_id, int $entry_id ): mixed
    {
        $entry_handler = $this->wpforms_object( 'entry' );
        if ( ! is_object( $entry_handler ) || ! method_exists( $entry_handler, 'get' ) )
        {
            return null;
        }

        $entry = $entry_handler->get( $entry_id, [ 'cap' => false ] );
        if ( ! is_array( $entry ) && ! is_object( $entry ) )
        {
            return null;
        }

        $entry_form_id = $this->value_from_array_or_object( $entry, 'form_id' );
        if ( null !== $entry_form_id && absint( $entry_form_id ) !== $form_id )
        {
            return null;
        }

        return $entry;
    }

    private function native_entry_exists_in_wpforms_table( int $form_id, int $entry_id ): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wpforms_entries';

        if ( ! $this->table_exists( $table ) )
        {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Provider entry verification must inspect WPForms' native entry table.
        $count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(1) FROM %i WHERE entry_id = %d AND form_id = %d',
                $table,
                $entry_id,
                $form_id
            )
        );

        return (int) $count > 0;
    }

    private function table_exists( string $table ): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Capability detection needs to inspect the provider table surface.
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }

    private function value_from_array_or_object( mixed $source, string $key ): mixed
    {
        if ( is_array( $source ) && array_key_exists( $key, $source ) )
        {
            return $source[ $key ];
        }

        if ( is_object( $source ) && isset( $source->{$key} ) )
        {
            return $source->{$key};
        }

        return null;
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
     * @param array<string, mixed> $captured
     */
    private function schedule_after_submission_actions( mixed $form_data, string $submission_uuid, array $captured ): void
    {
        $form_id = $this->form_id( $form_data );
        if ( $form_id <= 0 || ! class_exists( 'Sentient_Forms_Form_Mappings_Repository' ) )
        {
            return;
        }

        global $wpdb;
        $repository = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $mappings   = $repository->list_for_form( $this->get_id(), (string) $form_id );
        if ( [] === $mappings )
        {
            return;
        }

        $form  = $this->form_snapshot( $form_data, $form_id );
        $entry = $this->ledger_entry_snapshot( $captured, $submission_uuid );

        foreach ( $mappings as $mapping )
        {
            $mapping_id = absint( $mapping['id'] ?? 0 );
            if (
                $mapping_id <= 0
                || empty( $mapping['enabled'] )
                || 'custom_action' !== sanitize_key( (string) ( $mapping['action_kind'] ?? '' ) )
                || Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION !== Sentient_Forms_Form_Source_Lifecycles::normalize_id( $mapping['hook'] ?? '' )
            )
            {
                continue;
            }

            $this->plugin->get_async_handler()->schedule_local_mapping(
                $mapping_id,
                $form,
                $entry,
                [
                    'hook'              => self::NATIVE_AFTER_SUBMISSION_HOOK,
                    'form_source'       => $this->get_id(),
                    'form_id'           => (string) $form_id,
                    'entry_id'          => null,
                    'submission_uuid'   => $submission_uuid,
                    'mapping_id'        => (string) $mapping_id,
                    'local_mapping_id'  => 'local_first_' . $mapping_id,
                    'central_action_id' => 'sentient_forms_local_custom_action',
                    'action_name_label' => $this->local_mapping_action_label( $mapping ),
                ]
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function form_snapshot( mixed $form_data, int $form_id ): array
    {
        return [
            'id'          => (string) $form_id,
            'title'       => $this->form_title( $form_data, $form_id ),
            'form_source' => $this->get_id(),
        ];
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private function local_mapping_action_label( array $mapping ): string
    {
        if ( isset( $mapping['display_name'] ) && is_scalar( $mapping['display_name'] ) )
        {
            $label = sanitize_text_field( (string) $mapping['display_name'] );
            if ( '' !== $label )
            {
                return $label;
            }
        }

        return __( 'WPForms local action', 'sentient-forms' );
    }

    /**
     * @return array<string, mixed>
     */
    private function logical_fields_from_process_fields( mixed $fields ): array
    {
        if ( ! is_array( $fields ) )
        {
            return [];
        }

        $logical_fields = [];
        foreach ( $fields as $field_key => $field )
        {
            if ( ! is_array( $field ) || $this->is_file_upload_process_field( $field ) )
            {
                continue;
            }

            $field_id = $this->process_field_id( $field, $field_key );
            if ( '' === $field_id )
            {
                continue;
            }

            $ledger_key = $this->submission_ledger_field_key( $field, $field_id );
            if ( array_key_exists( $ledger_key, $logical_fields ) )
            {
                $ledger_key = sanitize_key( $ledger_key . '_field_' . str_replace( '.', '_', $field_id ) );
            }

            $value = $this->process_field_value( $field );
            if ( $this->submission_ledger_value_is_empty( $value ) )
            {
                continue;
            }

            $logical_fields[ $ledger_key ] = $value;
        }

        return $logical_fields;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function file_references_from_process_fields( mixed $fields ): array
    {
        if ( ! is_array( $fields ) )
        {
            return [];
        }

        $references = [];
        foreach ( $fields as $field_key => $field )
        {
            if ( ! is_array( $field ) || ! $this->is_file_upload_process_field( $field ) )
            {
                continue;
            }

            $field_id = $this->process_field_id( $field, $field_key );
            if ( '' === $field_id )
            {
                continue;
            }

            foreach ( $this->process_field_file_values( $field ) as $file_value )
            {
                $reference = $this->file_reference_from_value( $field_id, $file_value );
                if ( [] !== $reference )
                {
                    $references[] = $reference;
                }
            }
        }

        return $references;
    }

    private function is_file_upload_process_field( array $field ): bool
    {
        $type = isset( $field['type'] ) && is_scalar( $field['type'] )
            ? sanitize_key( (string) $field['type'] )
            : '';

        return 'file-upload' === $type;
    }

    private function process_field_id( array $field, mixed $field_key ): string
    {
        if ( isset( $field['id'] ) && is_scalar( $field['id'] ) )
        {
            return sanitize_text_field( (string) $field['id'] );
        }

        return is_scalar( $field_key ) ? sanitize_text_field( (string) $field_key ) : '';
    }

    private function submission_ledger_field_key( array $field, string $field_id ): string
    {
        $label = '';
        foreach ( [ 'admin_label', 'adminLabel', 'name', 'label' ] as $key )
        {
            if ( isset( $field[ $key ] ) && is_scalar( $field[ $key ] ) )
            {
                $label = sanitize_text_field( (string) $field[ $key ] );
                if ( '' !== $label )
                {
                    break;
                }
            }
        }

        $ledger_key = sanitize_key( str_replace( [ ' ', '.', '-' ], '_', strtolower( $label ) ) );

        return '' !== $ledger_key
            ? $ledger_key
            : sanitize_key( 'field_' . str_replace( '.', '_', $field_id ) );
    }

    private function process_field_value( array $field ): mixed
    {
        if ( array_key_exists( 'value', $field ) )
        {
            return $field['value'];
        }

        if ( array_key_exists( 'value_raw', $field ) )
        {
            return $field['value_raw'];
        }

        return null;
    }

    private function submission_ledger_value_is_empty( mixed $value ): bool
    {
        return null === $value || '' === $value || [] === $value;
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

    private function ledger_record_for_native_entry_id( Sentient_Forms_Submission_Ledger_Repository $ledger, mixed $entry_id, mixed $form_id ): ?array
    {
        if ( ! is_scalar( $entry_id ) || ! is_scalar( $form_id ) )
        {
            return null;
        }

        $native_entry_id = sanitize_text_field( (string) $entry_id );
        $form_id         = sanitize_text_field( (string) $form_id );
        if ( '' === $native_entry_id || '' === $form_id )
        {
            return null;
        }

        return $ledger->get_by_native_entry_id( $this->get_id(), $form_id, $native_entry_id );
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function ledger_entry_snapshot( array $record, string $submission_uuid ): array
    {
        $entry = [];

        if ( isset( $record['logical_fields_json'] ) && is_array( $record['logical_fields_json'] ) )
        {
            $entry = array_merge( $entry, $record['logical_fields_json'] );
        }

        if ( isset( $record['file_refs_json'] ) && is_array( $record['file_refs_json'] ) )
        {
            $entry['file_refs'] = $record['file_refs_json'];
        }

        $native_entry_id          = isset( $record['native_entry_id'] ) && is_scalar( $record['native_entry_id'] ) && '' !== (string) $record['native_entry_id']
            ? sanitize_text_field( (string) $record['native_entry_id'] )
            : null;
        $entry['id']              = $native_entry_id;
        $entry['submission_uuid'] = $submission_uuid;
        $entry['form_source']     = $this->get_id();
        $entry['form_id']         = sanitize_text_field( (string) ( $record['form_id'] ?? '' ) );
        $entry['native_entry_url'] = isset( $record['native_entry_url'] ) && is_scalar( $record['native_entry_url'] ) && '' !== (string) $record['native_entry_url']
            ? esc_url_raw( (string) $record['native_entry_url'] )
            : null;

        return $entry;
    }

    /**
     * @return array<int, mixed>
     */
    private function process_field_file_values( array $field ): array
    {
        $values = [];
        foreach ( [ 'value_raw', 'value', 'file', 'files' ] as $key )
        {
            if ( array_key_exists( $key, $field ) )
            {
                $values = array_merge( $values, $this->flatten_file_values( $field[ $key ] ) );
            }
        }

        return $values;
    }

    /**
     * @return array<int, mixed>
     */
    private function flatten_file_values( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [ $value ];
        }

        $values = [];
        foreach ( $value as $item )
        {
            if ( is_array( $item ) && $this->array_looks_like_file_reference( $item ) )
            {
                $values[] = $item;
                continue;
            }

            $values = array_merge( $values, $this->flatten_file_values( $item ) );
        }

        return $values;
    }

    private function array_looks_like_file_reference( array $value ): bool
    {
        foreach ( [ 'filename', 'file_name', 'name', 'url', 'file', 'value' ] as $key )
        {
            if ( array_key_exists( $key, $value ) )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function file_reference_from_value( string $field_id, mixed $file_value ): array
    {
        $filename = $this->uploaded_file_name( $file_value );
        if ( '' === $filename )
        {
            return [];
        }

        $reference = [
            'field_id' => $field_id,
            'filename' => $filename,
        ];
        $url       = $this->uploaded_file_url( $file_value );
        if ( '' !== $url )
        {
            $reference['url'] = $url;
        }

        return $reference;
    }

    private function uploaded_file_name( mixed $file_value ): string
    {
        if ( is_array( $file_value ) )
        {
            foreach ( [ 'filename', 'file_name', 'name' ] as $key )
            {
                if ( isset( $file_value[ $key ] ) && is_scalar( $file_value[ $key ] ) )
                {
                    $filename = sanitize_file_name( (string) $file_value[ $key ] );
                    if ( '' !== $filename )
                    {
                        return $filename;
                    }
                }
            }

            foreach ( [ 'url', 'file', 'value' ] as $key )
            {
                if ( isset( $file_value[ $key ] ) && is_scalar( $file_value[ $key ] ) )
                {
                    $filename = $this->uploaded_file_name( $file_value[ $key ] );
                    if ( '' !== $filename )
                    {
                        return $filename;
                    }
                }
            }

            return '';
        }

        if ( ! is_scalar( $file_value ) )
        {
            return '';
        }

        $value = trim( wp_strip_all_tags( (string) $file_value ) );
        if ( '' === $value )
        {
            return '';
        }

        $path     = wp_parse_url( $value, PHP_URL_PATH );
        $basename = is_string( $path ) && '' !== $path
            ? basename( $path )
            : basename( str_replace( '\\', '/', $value ) );

        return sanitize_file_name( $basename );
    }

    private function uploaded_file_url( mixed $file_value ): string
    {
        if ( is_array( $file_value ) )
        {
            foreach ( [ 'url', 'file', 'value' ] as $key )
            {
                if ( isset( $file_value[ $key ] ) && is_scalar( $file_value[ $key ] ) )
                {
                    $url = $this->uploaded_file_url( $file_value[ $key ] );
                    if ( '' !== $url )
                    {
                        return $url;
                    }
                }
            }

            return '';
        }

        if ( ! is_scalar( $file_value ) )
        {
            return '';
        }

        $value  = trim( wp_strip_all_tags( (string) $file_value ) );
        $scheme = wp_parse_url( $value, PHP_URL_SCHEME );
        $host   = wp_parse_url( $value, PHP_URL_HOST );

        return is_string( $scheme ) && in_array( strtolower( $scheme ), [ 'http', 'https' ], true ) && is_string( $host ) && '' !== $host
            ? esc_url_raw( $value )
            : '';
    }
}
