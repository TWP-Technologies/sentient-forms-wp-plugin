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
}
