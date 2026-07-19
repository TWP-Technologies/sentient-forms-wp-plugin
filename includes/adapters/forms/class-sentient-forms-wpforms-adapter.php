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
class Sentient_Forms_WPForms_Adapter implements Sentient_Forms_Adapter_Interface, Sentient_Forms_Async_Capable_Adapter_Interface, Sentient_Forms_Historical_Entries_Adapter_Interface, Sentient_Forms_Accepted_Submission_Adapter_Interface, Sentient_Forms_Validation_Adapter_Interface, Sentient_Forms_Native_Validation_Effects_Adapter_Interface, Sentient_Forms_Native_Entry_Capabilities_Adapter_Interface
{
    private const NATIVE_AFTER_SUBMISSION_HOOK = 'wpforms_process_complete';
    private const NATIVE_VALIDATION_HOOK = 'wpforms_process';
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

    private ?Sentient_Forms_Form_Source_Workflow_Runner $workflow_runner;

    public function __construct(
        Sentient_Forms_Plugin $plugin,
        ?Sentient_Forms_Form_Source_Workflow_Runner $workflow_runner = null
    )
    {
        $this->plugin          = $plugin;
        $this->workflow_runner = $workflow_runner;
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
     * Describe WPForms native entry capabilities independent of installation state.
     *
     * @return array<string, bool>
     */
    public function get_structural_native_entry_capabilities(): array
    {
        return [
            'id'    => true,
            'link'  => true,
            'read'  => false,
            'write' => false,
        ];
    }

    /**
     * Describe WPForms validation effects independent of installation state.
     *
     * @return array<string, bool>
     */
    public function get_structural_validation_effect_capabilities(): array
    {
        return [
            'field_errors'    => true,
            'form_errors'     => true,
            'submission_spam' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get_capability_descriptor(): array
    {
        $is_active                      = $this->is_active();
        $native_entry_storage_available = $is_active && $this->native_entry_storage_available();
        $native_entry                   = $this->get_structural_native_entry_capabilities();
        $native_entry['id']             = $native_entry_storage_available;
        $native_entry['link']           = $native_entry_storage_available;
        $validation_effects             = $this->get_structural_validation_effect_capabilities();

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
                    'supported'          => true,
                    'label'              => __( 'Validation', 'sentient-forms' ),
                    'native_hook'        => self::NATIVE_VALIDATION_HOOK,
                    'execution_mode'     => 'blocking',
                    'requires_ledger'    => false,
                    'unsupported_reason' => null,
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
            'native_entry'         => $native_entry,
            'native_enrichment'    => [
                'notes'                 => false,
                'status'                => false,
                'spam'                  => false,
                'notification_controls' => false,
                'webhook_controls'      => false,
            ],
            'validation_effects'   => $validation_effects,
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

        add_action( self::NATIVE_VALIDATION_HOOK, [ $this, 'handle_validation' ], 1, 3 );
        add_action( self::NATIVE_AFTER_SUBMISSION_HOOK, [ $this, 'handle_process_complete' ], 10, 4 );
    }

    public function get_validation_native_hook(): string
    {
        return self::NATIVE_VALIDATION_HOOK;
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function normalize_validation( mixed $native_validation, mixed $native_context = null ): array | WP_Error
    {
        if ( ! is_array( $native_validation ) )
        {
            return new WP_Error( 'sentient_forms_wpforms_invalid_validation', __( 'WPForms validation data is unavailable.', 'sentient-forms' ) );
        }

        $fields    = is_array( $native_validation['fields'] ?? null ) ? $native_validation['fields'] : [];
        $entry     = is_array( $native_validation['entry'] ?? null ) ? $native_validation['entry'] : [];
        $form_data = is_array( $native_validation['form_data'] ?? null ) ? $native_validation['form_data'] : [];
        $form_id   = $this->form_id( $form_data );
        if ( $form_id <= 0 )
        {
            return new WP_Error( 'sentient_forms_wpforms_invalid_validation', __( 'WPForms validation is missing its form ID.', 'sentient-forms' ) );
        }

        $entry_keys = [];
        foreach ( array_keys( $entry ) as $entry_key )
        {
            if ( ! is_scalar( $entry_key ) || 'fields' === (string) $entry_key )
            {
                continue;
            }

            $entry_key = sanitize_key( (string) $entry_key );
            if ( '' !== $entry_key )
            {
                $entry_keys[] = $entry_key;
            }
        }

        return [
            'form_id'        => (string) $form_id,
            'form'           => $this->form_snapshot( $form_data, $form_id ),
            'entry'          => $this->logical_fields_from_process_fields( $fields, $form_data ),
            'native_context' => [ 'entry_keys' => array_values( array_unique( $entry_keys ) ) ],
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

        $form_data = is_array( $native_validation['form_data'] ?? null ) ? $native_validation['form_data'] : [];
        $form_id   = $this->form_id( $form_data );
        $process   = $this->wpforms_object( 'process' );
        if ( $form_id <= 0 || ! is_object( $process ) || ! isset( $process->errors ) || ! is_array( $process->errors ) )
        {
            return $native_validation;
        }

        $fields        = is_array( $native_validation['fields'] ?? null ) ? $native_validation['fields'] : [];
        $field_ids     = [];
        foreach ( $fields as $field_key => $field )
        {
            $field_id = is_array( $field ) && isset( $field['id'] ) && is_scalar( $field['id'] )
                ? sanitize_text_field( (string) $field['id'] )
                : sanitize_text_field( (string) $field_key );
            if ( '' !== $field_id )
            {
                $field_ids[ $field_id ] = ctype_digit( $field_id ) ? (int) $field_id : $field_id;
            }
        }

        foreach ( $result->get_field_errors() as $field_error )
        {
            if ( ! is_array( $field_error ) )
            {
                continue;
            }

            $field_id = isset( $field_error['field_id'] ) && is_scalar( $field_error['field_id'] )
                ? sanitize_text_field( (string) $field_error['field_id'] )
                : '';
            $message = isset( $field_error['message'] ) && is_scalar( $field_error['message'] )
                ? sanitize_text_field( (string) $field_error['message'] )
                : '';
            if ( '' !== $field_id && '' !== $message && isset( $field_ids[ $field_id ] ) )
            {
                $process->errors[ $form_id ][ $field_ids[ $field_id ] ] = $message;
            }
        }

        $header_messages = [];
        $form_error      = $result->get_form_error();
        if ( null !== $form_error && '' !== $form_error )
        {
            $header_messages[] = sanitize_text_field( $form_error );
        }
        foreach ( $result->get_spam_classifications() as $classification )
        {
            if ( in_array( $classification, [ 'spam', 'likely_spam' ], true ) )
            {
                $header_messages[] = __( 'This submission could not be processed. Please review it and try again.', 'sentient-forms' );
                break;
            }
        }

        if ( [] !== $header_messages )
        {
            $existing_header = isset( $process->errors[ $form_id ]['header'] ) && is_scalar( $process->errors[ $form_id ]['header'] )
                ? trim( (string) $process->errors[ $form_id ]['header'] )
                : '';
            $new_header      = implode( '<br>', array_values( array_unique( $header_messages ) ) );
            $process->errors[ $form_id ]['header'] = '' === $existing_header
                ? $new_header
                : $existing_header . '<br>' . $new_header;
        }

        return $native_validation;
    }

    public function apply_validation_entry_effects(
        array $entry,
        array $form,
        Sentient_Forms_Validation_Run_Result $result
    ): void
    {
    }

    public function handle_validation( mixed $fields, mixed $entry, mixed $form_data ): void
    {
        $native_validation = [
            'fields'    => is_array( $fields ) ? $fields : [],
            'entry'     => is_array( $entry ) ? $entry : [],
            'form_data' => is_array( $form_data ) ? $form_data : [],
        ];
        $result = $this->get_workflow_runner()->run_validation( $this, $native_validation );
        $this->apply_validation_result( $native_validation, $result );
    }

    public function get_accepted_submission_native_hook(): string
    {
        return self::NATIVE_AFTER_SUBMISSION_HOOK;
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function normalize_accepted_submission( mixed $native_submission ): array | WP_Error
    {
        if ( ! is_array( $native_submission ) )
        {
            return new WP_Error( 'sentient_forms_wpforms_invalid_accepted_submission', __( 'WPForms accepted submission payload is invalid.', 'sentient-forms' ) );
        }

        $form_data = $native_submission['form_data'] ?? null;
        $form_id   = $this->form_id( $form_data );
        if ( $form_id <= 0 )
        {
            return new WP_Error( 'sentient_forms_wpforms_invalid_accepted_submission', __( 'WPForms accepted submission is missing its form ID.', 'sentient-forms' ) );
        }

        $normalized = [
            'form_id'        => (string) $form_id,
            'form'           => $this->form_snapshot( $form_data, $form_id ),
            'logical_fields' => $this->logical_fields_from_process_fields( $native_submission['fields'] ?? [], $form_data ),
            'files'          => $this->file_references_from_process_fields( $native_submission['fields'] ?? [] ),
        ];
        $native_entry_id = absint( $native_submission['entry_id'] ?? 0 );
        if ( $native_entry_id > 0 )
        {
            $normalized['native_entry_id'] = (string) $native_entry_id;
            $native_entry_url = $this->build_native_entry_url_if_available( $form_id, $native_entry_id );
            if ( null !== $native_entry_url )
            {
                $normalized['native_entry_url'] = $native_entry_url;
            }
        }

        return $normalized;
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

        $form_data = $this->form_data( $form );
        $fields = function_exists( 'wpforms_get_form_fields' ) ? wpforms_get_form_fields( $form ) : false;
        if ( ! is_array( $fields ) )
        {
            $fields    = is_array( $form_data['fields'] ?? null ) ? $form_data['fields'] : [];
        }

        return $this->normalize_fields( $fields, $form_data );
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

        return $this->get_workflow_runner()->get_form_settings( $this->get_id(), $form_id );
    }

    public function update_form_settings( mixed $form_id, array $settings ): bool | WP_Error
    {
        return Sentient_Forms_Legacy_Action_Authority_Migrator::update_action_option(
            $this->get_form_actions_option_key( absint( $form_id ) ),
            $settings
        );
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
            return new WP_Error( 'sentient_forms_wpforms_form_invalid', __( 'WPForms form IDs must be numeric.', 'sentient-forms' ), [ 'status' => 400 ] );
        }

        if ( ! $this->is_active() || ! $this->native_entry_storage_available() )
        {
            return $this->historical_native_unavailable( $form_id, 'wpforms_native_entry_storage_unavailable' );
        }

        $pre = apply_filters( 'sentient_forms_wpforms_historical_entries_pre', null, $form_id, $query, $limit, $status, $this );
        if ( is_wp_error( $pre ) )
        {
            return $pre;
        }
        if ( is_array( $pre ) )
        {
            return $pre;
        }

        $rows    = $this->wpforms_native_entry_rows( $form_id, max( 50, $limit ), $status, $query );
        $results = [];
        foreach ( $rows as $row )
        {
            $entry = $this->format_historical_wpforms_entry( $form_id, $row );
            if ( '' !== $query && ! str_contains( strtolower( wp_json_encode( $entry['field_summary'] ?? [] ) ?: '' ), $query ) )
            {
                continue;
            }

            $results[] = $entry;
            if ( count( $results ) >= $limit )
            {
                break;
            }
        }

        return [
            'entries'      => $results,
            'form_source'  => $this->get_id(),
            'form_id'      => (string) $form_id,
            'availability' => [
                'source'                => 'native',
                'native_read'           => true,
                'ledger_read'           => false,
                'unavailable_reason'    => null,
                'allow_ledger_fallback' => false,
            ],
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
            return new WP_Error(
                'sentient_forms_wpforms_native_entry_not_found',
                __( 'WPForms entry could not be found for this form.', 'sentient-forms' ),
                [ 'status' => 404, 'allow_ledger_fallback' => true, 'unavailable_reason' => 'wpforms_native_entry_not_found' ]
            );
        }

        if ( ! $this->is_active() || ! $this->native_entry_storage_available() )
        {
            return new WP_Error(
                'sentient_forms_wpforms_native_entry_storage_unavailable',
                __( 'WPForms native entries are unavailable. Sentient Forms can use captured Submission Ledger records instead.', 'sentient-forms' ),
                [ 'status' => 404, 'allow_ledger_fallback' => true, 'unavailable_reason' => 'wpforms_native_entry_storage_unavailable' ]
            );
        }

        $row = $this->wpforms_native_entry_row( $form_id, $entry_id );
        if ( null === $row )
        {
            return new WP_Error(
                'sentient_forms_wpforms_native_entry_not_found',
                __( 'WPForms entry could not be found for this form.', 'sentient-forms' ),
                [ 'status' => 404, 'allow_ledger_fallback' => true, 'unavailable_reason' => 'wpforms_native_entry_not_found' ]
            );
        }

        return $this->format_historical_wpforms_entry( $form_id, $row );
    }

    public function handle_process_complete( mixed $fields, mixed $entry, mixed $form_data, mixed $entry_id ): ?string
    {
        return $this->get_workflow_runner()->run_accepted_submission(
            $this,
            [
                'fields'    => $fields,
                'entry'     => $entry,
                'form_data' => $form_data,
                'entry_id'  => $entry_id,
            ]
        );
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

        if ( Sentient_Forms_Form_Source_Lifecycles::VALIDATION === $lifecycle_id )
        {
            return self::NATIVE_VALIDATION_HOOK;
        }

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
    private function normalize_field( mixed $field, mixed $field_key, mixed $form_data = null ): ?array
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
        $storage_eligible = ! $is_file && ( ! $is_hidden || $this->wpforms_hidden_field_storage_allowed( $field, $field_id, $form_data ) );

        return [
            'id'                      => $field_id,
            'label'                   => $label,
            'type'                    => $type,
            'adminLabel'              => $this->field_admin_label( $field, $label ),
            'visibility'              => $is_hidden ? 'hidden' : 'visible',
            'storage_eligible'        => $storage_eligible,
            'file_reference_eligible' => $is_file,
            'required'                => $this->field_required( $field ),
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<int, array<string, mixed>>
     */
    private function normalize_fields( array $fields, mixed $form_data = null ): array
    {
        $manifest = [];
        foreach ( $fields as $field_key => $field )
        {
            $normalized = $this->normalize_field( $field, $field_key, $form_data );
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

        return $this->normalize_fields( $fields, $data );
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
        $object = null;
        if ( function_exists( 'wpforms' ) )
        {
            $wpforms = wpforms();
            if ( is_object( $wpforms ) && method_exists( $wpforms, 'obj' ) )
            {
                $object = $wpforms->obj( $name );
            }
        }

        return apply_filters( 'sentient_forms_wpforms_object', $object, $name, $this );
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

    /**
     * @return array<string,mixed>
     */
    private function historical_native_unavailable( int $form_id, string $reason ): array
    {
        return [
            'entries'      => [],
            'form_source'  => $this->get_id(),
            'form_id'      => (string) $form_id,
            'availability' => [
                'source'                => 'native',
                'native_read'           => false,
                'ledger_read'           => false,
                'unavailable_reason'    => $reason,
                'allow_ledger_fallback' => true,
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function wpforms_native_entry_rows( int $form_id, int $limit, string $status, string $query = '' ): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wpforms_entries';
        if ( ! $this->table_exists( $table ) )
        {
            return [];
        }

        $columns = $this->wpforms_entry_table_columns( $table );
        if ( ! in_array( 'entry_id', $columns, true ) || ! in_array( 'form_id', $columns, true ) )
        {
            return [];
        }

        $select_columns = array_values( array_intersect( [ 'entry_id', 'form_id', 'fields', 'date', 'date_created', 'date_modified', 'created_at', 'status', 'type' ], $columns ) );
        $select_sql     = implode( ', ', array_map( static fn ( string $column ): string => '`' . esc_sql( $column ) . '`', $select_columns ) );
        $order_column   = in_array( 'date', $columns, true ) ? 'date' : 'entry_id';
        $where_status   = '';
        $where_query    = '';
        $args           = [ $table, $form_id ];

        if ( 'all' !== $status && in_array( 'status', $columns, true ) )
        {
            $where_status = ' AND `status` = %s';
            $args[]       = $status;
        }
        if ( '' !== $query && in_array( 'fields', $columns, true ) )
        {
            $where_query = ' AND LOWER(COALESCE(`fields`, \'\')) LIKE %s';
            $args[]      = '%' . $wpdb->esc_like( $query ) . '%';
        }

        $args[] = max( 1, min( 100, $limit ) );

        // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Column names are whitelisted from SHOW COLUMNS above; variadic args preserve optional filters.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads provider native entry storage for explicit webmaster curation.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$select_sql} FROM %i WHERE `form_id` = %d{$where_status}{$where_query} ORDER BY `{$order_column}` DESC LIMIT %d",
                ...$args
            ),
            ARRAY_A
        );
        // phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return is_array( $rows ) ? $rows : [];
    }

    private function wpforms_native_entry_row( int $form_id, int $entry_id ): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wpforms_entries';
        if ( ! $this->table_exists( $table ) )
        {
            return null;
        }

        $columns = $this->wpforms_entry_table_columns( $table );
        if ( ! in_array( 'entry_id', $columns, true ) || ! in_array( 'form_id', $columns, true ) )
        {
            return null;
        }

        $select_columns = array_values( array_intersect( [ 'entry_id', 'form_id', 'fields', 'date', 'date_created', 'date_modified', 'created_at', 'status', 'type' ], $columns ) );
        $select_sql     = implode( ', ', array_map( static fn ( string $column ): string => '`' . esc_sql( $column ) . '`', $select_columns ) );

        // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column names are whitelisted from SHOW COLUMNS above.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads provider native entry storage for explicit webmaster curation.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT {$select_sql} FROM %i WHERE `entry_id` = %d AND `form_id` = %d LIMIT 1",
                $table,
                $entry_id,
                $form_id
            ),
            ARRAY_A
        );
        // phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array( $row ) ? $row : null;
    }

    /**
     * @return array<int,string>
     */
    private function wpforms_entry_table_columns( string $table ): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Provider table shape varies by WPForms version/license.
        $rows = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
        if ( ! is_array( $rows ) || [] === $rows )
        {
            $wpdb->last_error = '';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Provider table shape varies by WPForms version/license.
            $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT 0', $table ), ARRAY_A );
            if ( '' !== $wpdb->last_error )
            {
                return [];
            }

            $columns = $wpdb->get_col_info( 'name' );
            return is_array( $columns )
                ? array_values( array_filter( array_map( static fn ( mixed $column ): string => is_scalar( $column ) ? sanitize_key( (string) $column ) : '', $columns ) ) )
                : [];
        }

        return array_values(
            array_filter(
                array_map(
                    static fn ( array $row ): string => isset( $row['Field'] ) && is_scalar( $row['Field'] ) ? sanitize_key( (string) $row['Field'] ) : '',
                    $rows
                )
            )
        );
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function format_historical_wpforms_entry( int $form_id, array $row ): array
    {
        $entry_id = isset( $row['entry_id'] ) && is_scalar( $row['entry_id'] )
            ? sanitize_text_field( (string) $row['entry_id'] )
            : '';
        $summary  = $this->wpforms_field_summary_from_native_row( $row );

        if ( [] === $summary && '' !== $entry_id && class_exists( 'Sentient_Forms_Submission_Ledger_Repository' ) )
        {
            global $wpdb;
            $ledger = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
            $record = $this->ledger_record_for_native_entry_id( $ledger, $entry_id, (string) $form_id );
            if ( is_array( $record ) )
            {
                $summary = $this->historical_field_summary_from_logical_fields( $record['logical_fields_json'] ?? [] );
            }
        }

        return [
            'id'               => $entry_id,
            'source_type'      => 'native',
            'submission_uuid'  => null,
            'native_entry_id'  => '' !== $entry_id ? $entry_id : null,
            'native_entry_url' => '' !== $entry_id ? $this->build_native_entry_url_if_available( $form_id, absint( $entry_id ) ) : null,
            'date_created'     => $this->first_scalar_value( [ $row['date'] ?? null, $row['date_created'] ?? null, $row['created_at'] ?? null, $row['date_modified'] ?? null ] ),
            'status'           => isset( $row['status'] ) && is_scalar( $row['status'] ) && '' !== trim( (string) $row['status'] )
                ? sanitize_key( (string) $row['status'] )
                : 'active',
            'field_summary'    => $summary,
        ];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<int,array{field_id:string,label:string,value:string}>
     */
    private function wpforms_field_summary_from_native_row( array $row ): array
    {
        $fields = $row['fields'] ?? null;
        if ( is_string( $fields ) )
        {
            $decoded = json_decode( $fields, true );
            if ( ! is_array( $decoded ) )
            {
                $decoded = maybe_unserialize( $fields );
            }
            $fields = $decoded;
        }

        if ( ! is_array( $fields ) )
        {
            return [];
        }

        $summary = [];
        foreach ( $fields as $field_key => $field )
        {
            if ( count( $summary ) >= 12 )
            {
                break;
            }

            if ( ! is_array( $field ) )
            {
                if ( is_scalar( $field ) && '' !== trim( (string) $field ) )
                {
                    $summary[] = [
                        'field_id' => sanitize_text_field( (string) $field_key ),
                        'label'    => sanitize_text_field( ucwords( str_replace( [ '_', '-' ], ' ', (string) $field_key ) ) ),
                        'value'    => mb_substr( sanitize_textarea_field( (string) $field ), 0, 300 ),
                    ];
                }
                continue;
            }

            $value = $field['value'] ?? $field['value_raw'] ?? null;
            if ( is_array( $value ) )
            {
                $value = wp_json_encode( $value );
            }
            if ( ! is_scalar( $value ) || '' === trim( (string) $value ) )
            {
                continue;
            }

            $field_id = isset( $field['id'] ) && is_scalar( $field['id'] ) ? (string) $field['id'] : (string) $field_key;
            $label    = isset( $field['name'] ) && is_scalar( $field['name'] )
                ? (string) $field['name']
                : ( isset( $field['label'] ) && is_scalar( $field['label'] ) ? (string) $field['label'] : $field_id );
            $summary[] = [
                'field_id' => sanitize_text_field( $field_id ),
                'label'    => sanitize_text_field( $label ),
                'value'    => mb_substr( sanitize_textarea_field( (string) $value ), 0, 300 ),
            ];
        }

        return $summary;
    }

    /**
     * @return array<int,array{field_id:string,label:string,value:string}>
     */
    private function historical_field_summary_from_logical_fields( mixed $fields ): array
    {
        if ( ! is_array( $fields ) )
        {
            return [];
        }

        $summary = [];
        foreach ( $fields as $field_id => $value )
        {
            if ( count( $summary ) >= 12 )
            {
                break;
            }
            if ( is_array( $value ) )
            {
                $value = wp_json_encode( $value );
            }
            if ( ! is_scalar( $value ) || '' === trim( (string) $value ) )
            {
                continue;
            }

            $field_id = sanitize_key( (string) $field_id );
            $summary[] = [
                'field_id' => $field_id,
                'label'    => sanitize_text_field( ucwords( str_replace( [ '_', '-' ], ' ', $field_id ) ) ),
                'value'    => mb_substr( sanitize_textarea_field( (string) $value ), 0, 300 ),
            ];
        }

        return $summary;
    }

    private function first_scalar_value( array $values ): ?string
    {
        foreach ( $values as $value )
        {
            if ( is_scalar( $value ) && '' !== trim( (string) $value ) )
            {
                return sanitize_text_field( (string) $value );
            }
        }

        return null;
    }

    private function normalize_historical_status_filter( string $status ): string
    {
        $status = sanitize_key( $status );
        return in_array( $status, [ 'all', 'active', 'spam' ], true ) ? $status : 'active';
    }

    private function native_entry_exists( int $form_id, int $entry_id ): bool
    {
        $filtered = apply_filters( 'sentient_forms_wpforms_native_entry_available', null, $form_id, $entry_id, $this );
        if ( is_bool( $filtered ) )
        {
            return $filtered;
        }

        if ( ! $this->native_entry_storage_available() )
        {
            return false;
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

        if ( ! $this->wpforms_native_entry_api_available() && ! $this->paid_wpforms_plugin_active() )
        {
            return false;
        }

        return $this->table_exists( $wpdb->prefix . 'wpforms_entries' );
    }

    private function wpforms_native_entry_api_available(): bool
    {
        $entry_handler = $this->wpforms_object( 'entry' );

        return is_object( $entry_handler ) && method_exists( $entry_handler, 'get' );
    }

    private function paid_wpforms_plugin_active(): bool
    {
        if ( defined( 'WPFORMS_PLUGIN_FILE' ) )
        {
            $plugin_file = str_replace( '\\', '/', plugin_basename( (string) WPFORMS_PLUGIN_FILE ) );
            if ( 'wpforms/wpforms.php' === $plugin_file )
            {
                return true;
            }
        }

        if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) )
        {
            $plugin_functions = ABSPATH . 'wp-admin/includes/plugin.php';
            if ( is_readable( $plugin_functions ) )
            {
                require_once $plugin_functions;
            }
        }

        if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'wpforms/wpforms.php' ) )
        {
            return true;
        }

        $active_plugins = get_option( 'active_plugins', [] );
        if ( ! is_array( $active_plugins ) )
        {
            return false;
        }

        return in_array( 'wpforms/wpforms.php', $active_plugins, true );
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
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( is_string( $found ) && 0 === strcasecmp( $found, $table ) )
        {
            return true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fallback for DB drivers whose SHOW TABLES result formatting differs.
        $count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(1) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            )
        );
        if ( (int) $count > 0 )
        {
            return true;
        }

        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Capability detection needs to inspect the provider table surface.
        $wpdb->get_results( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 0', $table ), ARRAY_A );

        return '' === $wpdb->last_error;
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

    private function get_workflow_runner(): Sentient_Forms_Form_Source_Workflow_Runner
    {
        if ( null === $this->workflow_runner )
        {
            $this->workflow_runner = new Sentient_Forms_Form_Source_Workflow_Runner( $this->plugin );
        }

        return $this->workflow_runner;
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
     * @return array<string, mixed>
     */
    private function logical_fields_from_process_fields( mixed $fields, mixed $form_data = null ): array
    {
        if ( ! is_array( $fields ) )
        {
            return [];
        }

        $logical_fields = [];
        foreach ( $fields as $field_key => $field )
        {
            if ( ! is_array( $field ) || $this->is_storage_ineligible_process_field( $field, $form_data, $field_key ) )
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

    private function is_storage_ineligible_process_field( array $field, mixed $form_data = null, mixed $field_key = null ): bool
    {
        $type = isset( $field['type'] ) && is_scalar( $field['type'] )
            ? sanitize_key( (string) $field['type'] )
            : '';

        if ( $this->is_file_upload_process_field( $field ) )
        {
            return true;
        }

        if ( 'hidden' !== $type )
        {
            return false;
        }

        $field_id = $this->process_field_id( $field, $field_key );
        return ! $this->wpforms_hidden_field_storage_allowed( $field, $field_id, $form_data );
    }

    private function wpforms_hidden_field_storage_allowed( array $field, string $field_id, mixed $form_data ): bool
    {
        $allowlist = [];
        if ( is_array( $form_data ) )
        {
            $settings = is_array( $form_data['settings'] ?? null ) ? $form_data['settings'] : [];
            foreach ( [ 'sentient_forms_hidden_field_allowlist', 'hidden_field_storage_allowlist', 'sentient_forms_hidden_fields' ] as $settings_key )
            {
                if ( isset( $settings[ $settings_key ] ) )
                {
                    $allowlist = array_merge( $allowlist, $this->normalize_hidden_field_storage_allowlist( $settings[ $settings_key ] ) );
                }
            }
        }

        $allowlist = apply_filters(
            'sentient_forms_wpforms_hidden_field_storage_allowlist',
            $allowlist,
            $field,
            $form_data,
            $this
        );
        $allowlist = $this->normalize_hidden_field_storage_allowlist( $allowlist );
        if ( [] === $allowlist )
        {
            return false;
        }

        $ledger_key = $this->submission_ledger_field_key( $field, $field_id );
        $candidates = array_filter(
            [
                sanitize_key( str_replace( [ '.', '-' ], '_', $field_id ) ),
                sanitize_key( $field_id ),
                $ledger_key,
            ]
        );

        return [] !== array_intersect( $allowlist, array_values( array_unique( $candidates ) ) );
    }

    /**
     * @return array<int, string>
     */
    private function normalize_hidden_field_storage_allowlist( mixed $allowlist ): array
    {
        if ( is_string( $allowlist ) )
        {
            $allowlist = array_filter( array_map( 'trim', explode( ',', $allowlist ) ) );
        }

        if ( ! is_array( $allowlist ) )
        {
            return [];
        }

        $normalized = [];
        foreach ( $allowlist as $item )
        {
            if ( ! is_scalar( $item ) )
            {
                continue;
            }

            $key = sanitize_key( str_replace( [ ' ', '.', '-' ], '_', strtolower( trim( (string) $item ) ) ) );
            if ( '' !== $key )
            {
                $normalized[] = $key;
            }
        }

        return array_values( array_unique( $normalized ) );
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
