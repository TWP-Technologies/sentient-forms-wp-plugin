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
    private const FORM_ACTIONS_OPTION_BASE = 'sentient_forms_actions_';
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

    /**
     * @return array<string, mixed>
     */
    public function get_form_settings( mixed $form_id ): array
    {
        $form_id = absint( $form_id );
        if ( $form_id <= 0 )
        {
            return [];
        }

        $settings = get_option( $this->get_form_actions_option_key( $form_id ), [] );
        if ( ! is_array( $settings ) )
        {
            $settings = [];
        }

        return $this->merge_local_first_form_mappings( $this->normalize_action_wrapper( $settings ), $form_id );
    }

    public function update_form_settings( mixed $form_id, array $settings ): bool
    {
        return update_option( $this->get_form_actions_option_key( absint( $form_id ) ), $settings, false );
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

        $record_form_id = absint( $record['form_id'] ?? 0 );
        $snapshot_form  = $record_form_id > 0 ? $this->get_form_object( $record_form_id ) : null;

        return $this->ledger_entry_snapshot( $record, $submission_uuid, $snapshot_form );
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

    public function get_provider_edit_url( mixed $form_id ): ?string
    {
        $form_id = absint( $form_id );
        if ( $form_id <= 0 )
        {
            return null;
        }

        return add_query_arg(
            [
                'view'    => 'fields',
                'form_id' => $form_id,
            ],
            admin_url( 'admin.php?page=wpforms-builder' )
        );
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
            'provider_edit_url'  => $this->get_provider_edit_url( $form_id ),
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
     * @param array<string, mixed> $fields
     * @return array<int, array<string, mixed>>
     */
    private function normalize_fields( array $fields ): array
    {
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function field_manifest_from_form_data( mixed $form_data ): array
    {
        $data   = $this->form_data( $form_data );
        $fields = is_array( $data['fields'] ?? null ) ? $data['fields'] : [];

        return $this->normalize_fields( $fields );
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
        $form            = $this->form_snapshot( $form_data, $form_id );
        $entry           = $this->ledger_entry_snapshot( $captured, $submission_uuid, $form_data );
        $native_entry_id = isset( $entry['id'] ) && is_scalar( $entry['id'] ) && '' !== (string) $entry['id']
            ? sanitize_text_field( (string) $entry['id'] )
            : null;
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

            $action_settings = $node['mapping'];
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
                ]
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

            $action_settings = $node['mapping'];
            $action_settings['local_mapping_id'] = $action_settings['local_mapping_id'] ?? $mapping_id;

            $central_action_id = isset( $action_settings['central_action_id'] ) && is_scalar( $action_settings['central_action_id'] )
                ? sanitize_key( (string) $action_settings['central_action_id'] )
                : '';
            if ( '' === $central_action_id )
            {
                $mapping_outcomes[ (string) $mapping_id ] = 'failed';
                continue;
            }

            $dependency_ids          = is_array( $node['dependency_ids'] ?? null ) ? $node['dependency_ids'] : [];
            $blocked_by_dependency  = $this->resolve_dependency_blocking_mapping( $dependency_ids, $mapping_outcomes );
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
                    'form_id'           => (string) $form_id,
                    'entry_id'          => $native_entry_id,
                    'submission_uuid'   => $submission_uuid,
                    'central_action_id' => $central_action_id,
                    'action_name_label' => $action_settings['action_name_label'] ?? $central_action_id,
                ] + $dependency_context
            );
            $mapping_outcomes[ (string) $mapping_id ] = $scheduled ? 'queued' : 'failed';
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
            'fields'      => $this->field_manifest_from_form_data( $form_data ),
        ];
    }

    private function get_form_actions_option_key( int $form_id ): string
    {
        return self::FORM_ACTIONS_OPTION_BASE . $this->get_id() . '_' . absint( $form_id );
    }

    /**
     * Merge local-first custom-table mappings into the runtime shape consumed by the planner.
     *
     * @param array<string, mixed> $settings Current form settings.
     * @param mixed                $form_id  WPForms form ID.
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
     * @param array<string, mixed> $form            WPForms form snapshot.
     * @param array<string, mixed> $entry           Sentient Forms ledger entry snapshot.
     * @param string               $mapping_id      Runtime planner mapping ID.
     * @param array<string, mixed> $action_settings Mapping settings.
     * @param string               $submission_uuid Sentient Forms submission ledger UUID.
     * @param array<string, mixed> $async_context   Dependency/runtime context.
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
     * @param array<int, mixed>    $dependency_ids  Dependency mapping ids.
     * @param array<string,string> $mapping_outcomes Known outcomes keyed by mapping id.
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
            'sf_disabled'        => $sf_disabled,
            'global_disabled'    => $global_disabled,
            'provider_disabled'  => $provider_disabled,
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
    private function logical_fields_from_process_fields( mixed $fields ): array
    {
        if ( ! is_array( $fields ) )
        {
            return [];
        }

        $logical_fields = [];
        foreach ( $fields as $field_key => $field )
        {
            if ( ! is_array( $field ) || $this->is_storage_ineligible_process_field( $field ) )
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

    private function is_storage_ineligible_process_field( array $field ): bool
    {
        $type = isset( $field['type'] ) && is_scalar( $field['type'] )
            ? sanitize_key( (string) $field['type'] )
            : '';

        return 'hidden' === $type || $this->is_file_upload_process_field( $field );
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
    private function ledger_entry_snapshot( array $record, string $submission_uuid, mixed $form_data = null ): array
    {
        $entry = [];

        if ( isset( $record['logical_fields_json'] ) && is_array( $record['logical_fields_json'] ) )
        {
            $entry = array_merge( $entry, $record['logical_fields_json'] );
        }

        if ( null !== $form_data )
        {
            $entry = $this->add_field_id_aliases_to_entry( $entry, $form_data );
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
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function add_field_id_aliases_to_entry( array $entry, mixed $form_data ): array
    {
        foreach ( $this->field_manifest_from_form_data( $form_data ) as $field )
        {
            if ( empty( $field['storage_eligible'] ) )
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

            foreach ( $this->submission_ledger_field_keys_from_manifest( $field, $field_id ) as $ledger_key )
            {
                if ( array_key_exists( $ledger_key, $entry ) )
                {
                    $entry[ $field_id ] = $entry[ $ledger_key ];
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
    private function submission_ledger_field_keys_from_manifest( array $field, string $field_id ): array
    {
        $labels = [];
        foreach ( [ 'label', 'name', 'admin_label', 'adminLabel' ] as $key )
        {
            if ( ! isset( $field[ $key ] ) || ! is_scalar( $field[ $key ] ) )
            {
                continue;
            }

            $label = sanitize_text_field( (string) $field[ $key ] );
            if ( '' === $label )
            {
                continue;
            }

            if ( 'adminLabel' === $key && str_starts_with( $label, 'WPForms: ' ) )
            {
                continue;
            }

            $labels[] = $label;
        }

        $base_keys = [];
        foreach ( $labels as $label )
        {
            $ledger_key = sanitize_key( str_replace( [ ' ', '.', '-' ], '_', strtolower( $label ) ) );
            if ( '' !== $ledger_key )
            {
                $base_keys[] = $ledger_key;
            }
        }

        $field_id_suffix = str_replace( '.', '_', $field_id );
        $keys            = [];
        foreach ( $base_keys as $ledger_key )
        {
            $keys[] = sanitize_key( $ledger_key . '_field_' . $field_id_suffix );
        }

        $keys = array_merge( $keys, $base_keys );
        $keys[] = sanitize_key( 'field_' . $field_id_suffix );

        return array_values( array_unique( array_filter( $keys ) ) );
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

        return $this->deduplicate_file_values( $values );
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
     * @param array<int, mixed> $values
     *
     * @return array<int, mixed>
     */
    private function deduplicate_file_values( array $values ): array
    {
        $deduplicated = [];
        $seen         = [];

        foreach ( $values as $value )
        {
            $key = $this->file_value_dedupe_key( $value );
            if ( '' !== $key )
            {
                if ( isset( $seen[ $key ] ) )
                {
                    continue;
                }

                $seen[ $key ] = true;
            }

            $deduplicated[] = $value;
        }

        return $deduplicated;
    }

    private function file_value_dedupe_key( mixed $file_value ): string
    {
        $url = $this->uploaded_file_url( $file_value );
        if ( '' !== $url )
        {
            return 'url:' . strtolower( $url );
        }

        $filename = $this->uploaded_file_name( $file_value );
        if ( '' !== $filename )
        {
            return 'filename:' . strtolower( $filename );
        }

        return '';
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
