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

    /**
     * Plugin instance
     */
    private Sentient_Forms_Plugin $plugin;

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
        add_action( 'gform_after_submission', [ $this, 'handle_after_submission' ], 10, 2 );

        // Add settings to the form editor
        add_action( 'gform_editor_js', [ $this, 'editor_js' ] );
        add_filter( 'gform_tooltips', [ $this, 'add_tooltips' ] );
        add_action( 'gform_field_standard_settings', [ $this, 'field_settings' ], 10, 2 );

        add_filter( 'sentient_forms_async_evaluation_jobs', [ $this, 'filter_async_evaluation_jobs' ], 10, 3 );
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

        // Check if any actions are enabled for validation
        $actions = $this->plugin->get_action_registry()->get_all_actions();
        foreach ( $actions as $action )
        {
            $action_id       = $action->get_id();
            $action_settings = $settings[ 'actions' ][ $action_id ] ?? [];

            // Skip if action is not enabled for this form or not configured for validation
            if ( empty( $action_settings[ 'enabled' ] ) ||
                 empty( $action_settings[ 'hooks' ] ) ||
                 !in_array( 'gform_validation', $action_settings[ 'hooks' ] ) )
            {
                continue;
            }

            $logger->info(
                'validation start',
                [
                    'hook'           => 'gform_validation',
                    'action_id'      => $action_id,
                    'form_id'        => $form_id,
                    'correlation_id' => $correlation_id,
                ]
            );

            // Prepare data for the action
            $entry = $this->prepare_entry_from_submission();
            $data  = [
                'form'              => $form,
                'entry'             => $entry,
                'validation_result' => $validation_result,
            ];

            // Execute the action synchronously for validation.
            $entry_id = $entry['id'] ?? ( $data['entry']['id'] ?? 0 );
            $form_id  = $form['id'] ?? 0;
            $result   = $action->execute( $data, $action_settings, $entry_id, $form_id );

            if ( is_wp_error( $result ) )
            {
                $logger->info(
                    'validation wp_error',
                    [
                        'hook'           => 'gform_validation',
                        'action_id'      => $action_id,
                        'form_id'        => $form_id,
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

            $validation_result = $this->maybe_execute_cps_validation(
                $validation_result,
                $form,
                $entry,
                $action_id,
                $action_settings,
            );

            $logger->info(
                'validation complete',
                [
                    'hook'           => 'gform_validation',
                    'action_id'      => $action_id,
                    'form_id'        => $form_id,
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
        error_log( sprintf( '[sentient-forms] handle_after_submission invoked for entry %s', $entry['id'] ?? 'unknown' ) );
        $form_id = $form[ 'id' ];
        $logger  = $this->plugin->get_logger();
        $correlation_id = $logger->correlation_id( $entry['id'] ?? null );

        // Get form settings
        $settings = $this->get_form_settings( $form_id );

        // Check if any actions are enabled for after submission
        $actions = $this->plugin->get_action_registry()->get_all_actions();
        foreach ( $actions as $action )
        {
            $action_id       = $action->get_id();
            $action_settings = $settings[ 'actions' ][ $action_id ] ?? [];

            // Skip if action is not enabled for this form or not configured for after submission
            if ( empty( $action_settings[ 'enabled' ] ) ||
                 empty( $action_settings[ 'hooks' ] ) ||
                 !in_array( 'gform_after_submission', $action_settings[ 'hooks' ] ) )
            {
                continue;
            }

            // Prepare data for the action
            $data = [
                'form'  => $form,
                'entry' => $entry,
            ];

            // Check if we should process asynchronously
            if ( !empty( $action_settings[ 'async' ] ) )
            {
                // Process the action asynchronously
                $logger->info(
                    'async action enqueued',
                    [
                        'hook'           => 'gform_after_submission',
                        'action_id'      => $action_id,
                        'form_id'        => $form_id,
                        'entry_id'       => $entry['id'] ?? null,
                        'correlation_id' => $correlation_id,
                    ]
                );
                $this->plugin->process_action_async(
                    $action_id,
                    $data,
                    $action_settings,
                    [
                        'hook'        => 'gform_after_submission',
                        'form_source' => $this->get_id(),
                        'action_id'   => $action_id,
                        'form_id'     => $form_id,
                        'entry_id'    => $entry['id'] ?? null,
                        'action_name_label' => $action_settings['action_name_label'] ?? ($action_settings['central_action_id'] ?? $action_id),
                    ],
                );
            }
            else
            {
                // Execute the action immediately (synchronous mode).
                $entry_id = $entry['id'] ?? 0;
                $form_id  = $form['id'] ?? 0;
                $action->execute( $data, $action_settings, $entry_id, $form_id );
            }
        }
    }

    /**
     * Prepare entry data from form submission
     *
     * @return array The entry data.
     */
    private function prepare_entry_from_submission(): array
    {
        $entry = [];

        // Get form data from $_POST
        if ( isset( $_POST[ 'gform_submit' ] ) )
        {
            $form_id = absint( $_POST[ 'gform_submit' ] );
            $form    = GFAPI::get_form( $form_id );

            if ( $form )
            {
                foreach ( $form[ 'fields' ] as $field )
                {
                    $field_id   = $field->id;
                    $input_name = 'input_' . str_replace( '.', '_', $field_id );

                    if ( isset( $_POST[ $input_name ] ) )
                    {
                        $entry[ $field_id ] = sanitize_text_field( $_POST[ $input_name ] );
                    }
                }
            }
        }

        return $entry;
    }

    private function maybe_execute_cps_validation( array $validation_result, array $form, array $entry, string $action_id, array $action_settings ): array
    {
        $central_action_id = $action_settings['central_action_id'] ?? '';
        if ( empty( $central_action_id ) )
        {
            return $validation_result;
        }

        $context = [
            'hook'        => 'gform_validation',
            'form_source' => $this->get_id(),
            'action_id'   => $action_id,
            'form_id'     => $form['id'] ?? null,
            'action_name_label' => $action_settings['action_name_label'] ?? $central_action_id,
        ];

        $response = $this->plugin->get_action_executor()->execute(
            $central_action_id,
            $form,
            $entry,
            $context,
        );

        return $this->apply_cps_validation_response( $validation_result, $response, $action_settings );
    }

    private function apply_cps_validation_response( array $validation_result, $response, array $action_settings ): array
    {
        if ( is_wp_error( $response ) )
        {
            return $this->inject_validation_message( $validation_result, $response->get_error_message(), $action_settings );
        }

        if ( isset( $response['validation'] ) && is_array( $response['validation'] ) )
        {
            $validation = $response['validation'];
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
            echo <<<HTML
            <li class='sentient_forms_setting field_setting' id='sentient_forms_field_setting'>
                <input type='checkbox' id='sentient_forms_enabled' onclick="SetFieldProperty('sentientFormsEnabled', this.checked);"/>
                <label for='sentient_forms_enabled' class='inline'>
                    $enable_sentient_forms
                    $gform_tooltip
                </label>
            </li>
HTML;
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

    private function get_form_option_name( int $form_id ): string
    {
        return sprintf( 'sentient_forms_actions_%s_%d', $this->get_id(), absint( $form_id ) );
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
        return wp_parse_args(
            $settings,
            [
                'enabled' => $global_settings[ 'auto_apply_actions' ] ?? false,
                'actions' => [],
            ],
        );
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
                'id'           => $form[ 'id' ],
                'title'        => $form[ 'title' ],
                'adapter'      => $this->get_id(),
                'adapter_name' => $this->get_name(),
                'settings'     => $this->get_form_settings( $form[ 'id' ] ),
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
            error_log( 'Sentient Forms: Error getting entry meta: ' . $e->getMessage() );
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
            error_log( 'Sentient Forms: Error updating entry meta: ' . $e->getMessage() );
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
                error_log( 'Sentient Forms: Could not find entry ' . $entry_id . ': ' . $entry->get_error_message() );
                return false;
            }

            // Update the is_spam property to 1 (true)
            $result = GFAPI::update_entry_property( $entry_id, 'is_spam', 1 );

            // Add a note about the spam marking
            if ( $result && !is_wp_error( $result ) )
            {
                $this->add_entry_note(
                    $entry_id,
                    'Sentient Forms AI',
                    __( 'This entry has been marked as spam by Sentient Forms AI.', 'sentient-forms' ),
                );

                return true;
            }

            return false;
        } catch ( Exception $e )
        {
            error_log( 'Sentient Forms: Error marking entry as spam: ' . $e->getMessage() );
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
                error_log( 'Sentient Forms: Could not find entry ' . $entry_id . ': ' . $entry->get_error_message() );
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
                        __( 'This submission was rejected by Sentient Forms AI for the following reason: %s', 'sentient-forms' ),
                        $message,
                    ),
                );

                return true;
            }

            return false;
        } catch ( Exception $e )
        {
            error_log( 'Sentient Forms: Error rejecting submission: ' . $e->getMessage() );
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

        // Verify entry exists
        try
        {
            $entry = GFAPI::get_entry( $entry_id );
            if ( is_wp_error( $entry ) )
            {
                error_log( 'Sentient Forms: Could not find entry ' . $entry_id . ': ' . $entry->get_error_message() );
                return false;
            }
        } catch ( Exception $e )
        {
            error_log( 'Sentient Forms: Error getting entry: ' . $e->getMessage() );
            return false;
        }

        // Check if GF Notes API function exists
        if ( !function_exists( 'GFFormsModel::add_note' ) )
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

            return $result !== false;
        } catch ( Exception $e )
        {
            error_log( 'Sentient Forms: Error adding note: ' . $e->getMessage() );
            return false;
        }
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
            error_log( 'Sentient Forms: Error retrieving form object: ' . $e->getMessage() );
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

        $excerpt = $this->format_async_result_excerpt( $result );
        $this->update_entry_meta( $entry_id, 'sentient_forms_last_response', wp_json_encode( $result ) );
        $this->add_entry_note(
            $entry_id,
            'Sentient Forms AI',
            sprintf(
                /* translators: %s is the action label */
                __( 'Sentient Forms finished %s. Result: %s', 'sentient-forms' ),
                $this->get_async_action_label( $context ),
                $excerpt,
            ),
        );
    }

    public function finalize_async_error( array $context, WP_Error $error ): void
    {
        $entry_id = isset( $context['entry_id'] ) ? absint( $context['entry_id'] ) : 0;
        $message  = sprintf(
            /* translators: 1: action label, 2: error reason */
            __( 'Sentient Forms could not complete %1$s. Reason: %2$s', 'sentient-forms' ),
            $this->get_async_action_label( $context ),
            $error->get_error_message(),
        );

        if ( $entry_id > 0 )
        {
            $this->add_entry_note( $entry_id, 'Sentient Forms AI', $message );
        }
        else
        {
            error_log( $message );
        }
    }

    public function finalize_async_evaluation( array $context, array $result ): void
    {
        $entry_id = isset( $context['entry_id'] ) ? absint( $context['entry_id'] ) : 0;
        if ( $entry_id <= 0 )
        {
            return;
        }

        $excerpt = $this->format_async_result_excerpt( $result );
        $this->add_entry_note(
            $entry_id,
            'Sentient Forms AI',
            sprintf(
                /* translators: %s is the action label */
                __( 'Evaluation updated for %s: %s', 'sentient-forms' ),
                $this->get_async_action_label( $context ),
                $excerpt,
            ),
        );
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

    private function format_async_result_excerpt( array $result ): string
    {
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
