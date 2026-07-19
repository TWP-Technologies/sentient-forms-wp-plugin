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
class Sentient_Forms_Contact_Form_7_Adapter implements Sentient_Forms_Adapter_Interface, Sentient_Forms_Async_Capable_Adapter_Interface, Sentient_Forms_Historical_Entries_Adapter_Interface, Sentient_Forms_Accepted_Submission_Adapter_Interface, Sentient_Forms_Validation_Adapter_Interface, Sentient_Forms_Native_Validation_Effects_Adapter_Interface
{
    private const FORM_ACTIONS_OPTION_BASE = 'sentient_forms_actions_';

    private const NATIVE_AFTER_SUBMISSION_HOOK = 'wpcf7_mail_sent';

    private const NATIVE_VALIDATION_HOOK = 'wpcf7_validate';

    private Sentient_Forms_Plugin $plugin;

    private ?Sentient_Forms_Form_Source_Workflow_Runner $workflow_runner;

    /** @var array<string, Sentient_Forms_Validation_Run_Result> */
    private array $validation_results_by_submission = [];

    /** @var array<string, array<int, mixed>> */
    private array $validation_tags_by_submission = [];

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
     * Describe Contact Form 7 validation effects independent of installation state.
     *
     * @return array<string, bool>
     */
    public function get_structural_validation_effect_capabilities(): array
    {
        return [
            'field_errors'    => true,
            'form_errors'     => false,
            'submission_spam' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get_capability_descriptor(): array
    {
        $is_active          = $this->is_active();
        $validation_effects = $this->get_structural_validation_effect_capabilities();

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
            'validation_effects'   => $validation_effects,
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

        add_filter( self::NATIVE_VALIDATION_HOOK, [ $this, 'handle_validation' ], 10, 2 );
        add_filter( 'wpcf7_spam', [ $this, 'handle_spam' ], 10, 2 );
        add_action( self::NATIVE_AFTER_SUBMISSION_HOOK, [ $this, 'handle_mail_sent' ], 10, 1 );
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
        if ( ! is_object( $native_validation ) || ! method_exists( $native_validation, 'invalidate' ) )
        {
            return new WP_Error( 'sentient_forms_cf7_invalid_validation', __( 'Contact Form 7 validation data is unavailable.', 'sentient-forms' ) );
        }

        $submission = $this->current_submission( $native_validation );
        $form       = $this->submission_contact_form( $submission );
        $form_id    = $this->contact_form_id( $form );
        if ( null === $submission || null === $form || $form_id <= 0 )
        {
            return new WP_Error( 'sentient_forms_cf7_invalid_validation', __( 'Contact Form 7 validation is missing its submission context.', 'sentient-forms' ) );
        }

        $tags      = is_array( $native_context ) ? array_values( $native_context ) : [];
        $cache_key = $this->validation_submission_cache_key( $submission );
        if ( null !== $cache_key )
        {
            $this->validation_tags_by_submission[ $cache_key ] = $tags;
        }

        return [
            'form_id'        => (string) $form_id,
            'form'           => $this->form_snapshot( $form, $form_id ),
            'entry'          => $this->logical_fields_from_submission( $submission ),
            'native_context' => [
                'tag_names' => array_values(
                    array_filter(
                        array_map( fn( mixed $tag ): string => $this->form_tag_value( $tag, 'name' ), $tags )
                    )
                ),
            ],
        ];
    }

    public function apply_validation_result(
        mixed $native_validation,
        Sentient_Forms_Validation_Run_Result $result
    ): mixed
    {
        if ( ! is_object( $native_validation ) || ! method_exists( $native_validation, 'invalidate' ) )
        {
            return $native_validation;
        }

        $submission = $this->current_submission( $native_validation );
        $cache_key  = $this->validation_submission_cache_key( $submission );
        $tags       = null !== $cache_key ? ( $this->validation_tags_by_submission[ $cache_key ] ?? [] ) : [];
        $tags_by_name = [];
        foreach ( $tags as $tag )
        {
            $name = sanitize_key( $this->form_tag_value( $tag, 'name' ) );
            if ( '' !== $name )
            {
                $tags_by_name[ $name ] = $tag;
            }
        }

        foreach ( $result->get_field_errors() as $field_error )
        {
            if ( ! is_array( $field_error ) )
            {
                continue;
            }

            $field_name = isset( $field_error['field_id'] ) && is_scalar( $field_error['field_id'] )
                ? sanitize_key( (string) $field_error['field_id'] )
                : '';
            $message = isset( $field_error['message'] ) && is_scalar( $field_error['message'] )
                ? sanitize_text_field( (string) $field_error['message'] )
                : '';
            if ( '' === $field_name || ! isset( $tags_by_name[ $field_name ] ) )
            {
                continue;
            }

            if ( '' !== $message )
            {
                $native_validation->invalidate( $tags_by_name[ $field_name ], $message );
            }
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

    public function handle_validation( mixed $validation_result, mixed $tags ): mixed
    {
        $result     = $this->get_workflow_runner()->run_validation( $this, $validation_result, $tags );
        $submission = $this->current_submission( $validation_result );
        $cache_key  = $this->validation_submission_cache_key( $submission );
        if ( null !== $cache_key )
        {
            $this->validation_results_by_submission[ $cache_key ] = $result;
        }

        return $this->apply_validation_result( $validation_result, $result );
    }

    public function handle_spam( bool $spam, object $submission ): bool
    {
        $cache_key = $this->validation_submission_cache_key( $submission );
        $result    = null !== $cache_key ? ( $this->validation_results_by_submission[ $cache_key ] ?? null ) : null;
        if ( ! $result instanceof Sentient_Forms_Validation_Run_Result )
        {
            return $spam;
        }

        foreach ( $result->get_spam_classifications() as $classification )
        {
            if ( in_array( $classification, [ 'spam', 'likely_spam' ], true ) )
            {
                return true;
            }
        }

        return $spam;
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
        $form_id = $this->contact_form_id( $native_submission );
        if ( $form_id <= 0 )
        {
            return new WP_Error( 'sentient_forms_cf7_invalid_accepted_submission', __( 'Contact Form 7 accepted submission is missing its form ID.', 'sentient-forms' ) );
        }

        $submission = $this->current_submission( $native_submission );
        if ( null === $submission )
        {
            return new WP_Error( 'sentient_forms_cf7_submission_unavailable', __( 'Contact Form 7 accepted submission data is unavailable.', 'sentient-forms' ) );
        }

        return [
            'form_id'        => (string) $form_id,
            'form'           => $this->form_snapshot( $native_submission, $form_id ),
            'logical_fields' => $this->logical_fields_from_submission( $submission ),
            'files'          => $this->file_references_from_submission( $submission ),
        ];
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

        return $this->field_manifest_from_form( $form );
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

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function search_historical_entries( mixed $form_id, string $query = '', int $limit = 10, string $status = 'active' ): array | WP_Error
    {
        return [
            'entries'      => [],
            'form_source'  => $this->get_id(),
            'form_id'      => sanitize_text_field( (string) $form_id ),
            'availability' => [
                'source'                => 'native',
                'native_read'           => false,
                'ledger_read'           => false,
                'unavailable_reason'    => 'native_entry_storage_unavailable',
                'allow_ledger_fallback' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function get_historical_entry( mixed $form_id, string $entry_id ): array | WP_Error
    {
        return new WP_Error(
            'sentient_forms_contact_form_7_native_entry_unavailable',
            __( 'Contact Form 7 core does not store historical native submissions. Enable the Sentient Forms Submission Ledger to curate captured submissions.', 'sentient-forms' ),
            [
                'status'                => 404,
                'unavailable_reason'    => 'native_entry_storage_unavailable',
                'allow_ledger_fallback' => true,
            ]
        );
    }

    public function handle_mail_sent( mixed $contact_form ): ?string
    {
        return $this->get_workflow_runner()->run_accepted_submission( $this, $contact_form );
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
        return $this->get_workflow_runner()->get_form_settings( $this->get_id(), $form_id );
    }

    public function update_form_settings( mixed $form_id, array $settings ): bool | WP_Error
    {
        return Sentient_Forms_Legacy_Action_Authority_Migrator::update_action_option(
            $this->get_form_actions_option_key( absint( $form_id ) ),
            $settings
        );
    }

    private function get_form_actions_option_key( int $form_id ): string
    {
        return self::FORM_ACTIONS_OPTION_BASE . $this->get_id() . '_' . absint( $form_id );
    }




    private function get_workflow_runner(): Sentient_Forms_Form_Source_Workflow_Runner
    {
        if ( null === $this->workflow_runner )
        {
            $this->workflow_runner = new Sentient_Forms_Form_Source_Workflow_Runner( $this->plugin );
        }

        return $this->workflow_runner;
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

    private function submission_contact_form( mixed $submission ): object | array | null
    {
        if ( is_object( $submission ) && method_exists( $submission, 'get_contact_form' ) )
        {
            $form = $submission->get_contact_form();

            return is_object( $form ) || is_array( $form ) ? $form : null;
        }

        return null;
    }

    private function validation_submission_cache_key( mixed $submission ): ?string
    {
        return is_object( $submission ) ? 'submission:' . spl_object_id( $submission ) : null;
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
            'fields'      => $this->field_manifest_from_form( $contact_form ),
        ];
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
     * @return array<int, array<string, mixed>>
     */
    private function field_manifest_from_form( object | array $form ): array
    {
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
