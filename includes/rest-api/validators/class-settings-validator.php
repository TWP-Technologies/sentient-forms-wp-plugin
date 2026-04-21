<?php
/**
 * Settings Validator Class for the Sentient Forms plugin.
 * Contains specific validation logic for REST API parameters related to plugin settings.
 * These methods are typically used as 'validate_callback' in the 'args' definition
 * of `register_rest_route`.
 *
 * @package    SentientForms
 * @subpackage REST_API\Validators
 * @since      0.1.0
 */

// Ensure this file is loaded within WordPress.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Settings_Validator
 * Handles validation for settings-related REST API requests.
 */
class Sentient_Forms_Settings_Validator
{

    use Trait_Sentient_Forms_Validation_Utils;

    /**
     * Validates the 'api_key' parameter.
     *
     * @param mixed           $value   The value of the parameter.
     * @param WP_REST_Request $request The current REST API request object.
     * @param string          $param   The name of the parameter ('api_key').
     *
     * @return true|WP_Error True if validation passed, WP_Error otherwise.
     * @since 0.1.0
     */
    public function validate_api_key_param( mixed $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        if ( '' === $value || null === $value )
        {
            return true;
        }

        $string_check = $this->validate_is_non_empty_string( $value, $param );
        if ( is_wp_error( $string_check ) )
        {
            return $string_check;
        }

        return $this->validate_api_key_format( $value, $param, false );
    }

    /**
     * Validates the 'selected_llm' parameter.
     *
     * @param mixed           $value   The value of the parameter.
     * @param WP_REST_Request $request The current REST API request object.
     * @param string          $param   The name of the parameter ('selected_llm').
     *
     * @return true|WP_Error True if validation passed, WP_Error otherwise.
     * @since 0.1.0
     */
    public function validate_selected_llm_param( mixed $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        if ( empty( $value ) )
        {
            return true;
        }

        if ( !empty( $value ) )
        {
            $string_check = $this->validate_is_non_empty_string( $value, $param );

            if ( is_wp_error( $string_check ) )
            {
                return $string_check;
            }

            if ( !class_exists( 'Sentient_Forms_LLM_Registry' ) )
            {
                return $this->validation_error(
                    $param,
                    __( 'LLM Registry class not found. Please ensure the plugin is properly loaded.', 'sentient-forms' ),
                );
            }

            $llm_registry = Sentient_Forms_LLM_Registry::get_instance();
            $allowed_llms = array_keys( $llm_registry->get_registered_models() );

            return $this->validate_is_in_enum( $value, $param, $allowed_llms );
        }

        return true;
    }

    /**
     * Validates a generic boolean setting parameter.
     *
     * @param mixed           $value   The value of the parameter.
     * @param WP_REST_Request $request The current REST API request object.
     * @param string          $param   The name of the parameter.
     *
     * @return true|WP_Error True if validation passed, WP_Error otherwise.
     * @since 0.1.0
     */
    public function validate_boolean_param( mixed $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        if ( !is_bool( $value ) )
        {
            return $this->validation_error(
                $param,
                __(
                    'Must be a valid boolean (true or false). This should have been coerced by WordPress.',
                    'sentient-forms',
                ),
            );
        }

        return true;
    }

    /**
     * Validates a provider disable map where each key is a provider slug and each value is boolean-like.
     *
     * @param mixed           $value   The value of the parameter.
     * @param WP_REST_Request $request The current REST API request object.
     * @param string          $param   The name of the parameter.
     *
     * @return true|WP_Error
     */
    public function validate_provider_disabled_map_param( mixed $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        if ( null === $value || '' === $value )
        {
            return true;
        }

        if ( ! is_array( $value ) )
        {
            return $this->validation_error(
                $param,
                __( 'Must be an object map of provider slugs to booleans.', 'sentient-forms' ),
            );
        }

        foreach ( $value as $provider_slug => $is_disabled )
        {
            if ( ! is_string( $provider_slug ) && ! is_int( $provider_slug ) )
            {
                return $this->validation_error(
                    $param,
                    __( 'Provider keys must be strings.', 'sentient-forms' ),
                );
            }

            if (
                ! is_bool( $is_disabled ) &&
                ! in_array( $is_disabled, [ 0, 1, '0', '1' ], true ) &&
                ! ( is_string( $is_disabled ) && in_array( strtolower( $is_disabled ), [ 'true', 'false' ], true ) )
            )
            {
                return $this->validation_error(
                    $param,
                    __( 'Provider values must be boolean-like.', 'sentient-forms' ),
                );
            }
        }

        return true;
    }

    /**
     * Validate administrator-configurable local execution-event retention days.
     *
     * @param mixed           $value   The value of the parameter.
     * @param WP_REST_Request $request The current REST API request object.
     * @param string          $param   The name of the parameter.
     *
     * @return true|WP_Error
     */
    public function validate_execution_event_retention_days_param( mixed $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        if ( ! is_numeric( $value ) )
        {
            return $this->validation_error(
                $param,
                __( 'Must be one of the supported local execution log retention values.', 'sentient-forms' ),
            );
        }

        if ( ! in_array( (int) $value, Sentient_Forms_Local_Data_Governance::execution_event_retention_choices(), true ) )
        {
            return $this->validation_error(
                $param,
                __( 'Must be 7, 30, 90, 180, or 0 for manual cleanup only.', 'sentient-forms' ),
            );
        }

        return true;
    }

    /**
     * Validate the recorded privacy/visibility setup profile.
     *
     * @param mixed           $value   The value of the parameter.
     * @param WP_REST_Request $request The current REST API request object.
     * @param string          $param   The parameter name.
     *
     * @return true|WP_Error
     */
    public function validate_privacy_setup_profile_param( mixed $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        if ( ! is_string( $value ) || '' === trim( $value ) )
        {
            return $this->validation_error(
                $param,
                __( 'Must be one of the supported privacy setup profiles.', 'sentient-forms' ),
            );
        }

        if ( ! in_array( sanitize_key( $value ), Sentient_Forms_Local_Data_Governance::privacy_setup_profile_choices(), true ) )
        {
            return $this->validation_error(
                $param,
                __( 'Must be balanced, privacy_focused, maximum_privacy, or maximum_visibility.', 'sentient-forms' ),
            );
        }

        return true;
    }
}
