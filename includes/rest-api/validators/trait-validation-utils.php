<?php
/**
 * Validation Utilities Trait for the Sentient Forms plugin.
 * Provides reusable helper methods for validating data within REST API controllers or validator classes.
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
 * Trait Sentient_Forms_Validation_Utils_Trait
 * Contains common validation methods.
 */
trait Sentient_Forms_Validation_Utils_Trait
{

    /**
     * Prepares a validation error response.
     *
     * @param string $param_name  The name of the parameter that failed validation.
     * @param string $message     The specific validation error message.
     * @param string $error_code  Optional. A specific error code. Defaults to 'rest_invalid_param'.
     * @param int    $status_code Optional. HTTP status code. Defaults to 400.
     *
     * @return WP_Error The formatted WP_Error object.
     * @since 0.1.0
     */
    protected function validation_error(
        string $param_name,
        string $message,
        string $error_code = 'rest_invalid_param',
        int    $status_code = 400,
    ): WP_Error {
        /* translators: 1: parameter name, 2: validation error message. */
        $translated_text = __( 'Invalid parameter: %1$s. %2$s', 'sentient-forms' );
        $html            = esc_html( $param_name );
        $error_message   = sprintf( $translated_text, $html, $message );

        return new WP_Error( $error_code, $error_message, [ 'status' => $status_code, 'param' => $param_name ] );
    }

    /**
     * Validates if a value is a non-empty string.
     *
     * @param mixed  $value      The value to check.
     * @param string $param_name The name of the parameter being validated.
     *
     * @return true|WP_Error True if valid, WP_Error otherwise.
     * @since 0.1.0
     */
    protected function validate_is_non_empty_string( mixed $value, string $param_name ): true | WP_Error
    {
        if ( !is_string( $value ) || '' === trim( $value ) )
        {
            return $this->validation_error( $param_name, __( 'Must be a non-empty string.', 'sentient-forms' ) );
        }

        return true;
    }

    /**
     * Validates if a value is a positive integer (greater than 0).
     *
     * @param mixed  $value      The value to check.
     * @param string $param_name The name of the parameter being validated.
     *
     * @return true|WP_Error True if valid, WP_Error otherwise.
     * @since 0.1.0
     */
    protected function validate_is_positive_integer( mixed $value, string $param_name ): true | WP_Error
    {
        if ( ( is_string( $value ) && !ctype_digit( $value ) ) )
        {
            return $this->validation_error( $param_name, __( 'Must be an integer.', 'sentient-forms' ) );
        }

        $int_val = intval( $value );

        if ( $int_val <= 0 )
        {
            return $this->validation_error( $param_name, __( 'Must be a positive integer (greater than 0).', 'sentient-forms' ) );
        }

        return true;
    }

    /**
     * Validates if a value is a boolean or a string/integer representation of a boolean.
     *
     * @param mixed  $value      The value to check.
     * @param string $param_name The name of the parameter being validated.
     *
     * @return true|WP_Error True if valid, WP_Error otherwise.
     * @since 0.1.0
     */
    protected function validate_is_boolean_like( mixed $value, string $param_name ): true | WP_Error
    {
        if ( is_bool( $value ) )
        {
            return true;
        }

        if ( in_array( $value, [ 'true', 'false', '1', '0', 1, 0 ], true ) )
        {
            return true;
        }

        return $this->validation_error( $param_name, __( 'Must be a boolean value (true, false, 1, or 0).', 'sentient-forms' ) );
    }

    /**
     * Validates if a value is a valid email address.
     *
     * @param mixed  $value      The value to check.
     * @param string $param_name The name of the parameter being validated.
     *
     * @return true|WP_Error True if valid, WP_Error otherwise.
     * @since 0.1.0
     */
    protected function validate_is_email( mixed $value, string $param_name ): true | WP_Error
    {
        if ( !is_string( $value ) || !is_email( $value ) )
        {
            return $this->validation_error( $param_name, __( 'Must be a valid email address.', 'sentient-forms' ) );
        }

        return true;
    }

    /**
     * Validates if a value is within an allowed set of enum values.
     *
     * @param mixed  $value          The value to check.
     * @param string $param_name     The name of the parameter being validated.
     * @param array  $allowed_values An array of allowed values.
     *
     * @return true|WP_Error True if valid, WP_Error otherwise.
     * @since 0.1.0
     */
    protected function validate_is_in_enum( mixed $value, string $param_name, array $allowed_values ): true | WP_Error
    {
        if ( !in_array( $value, $allowed_values, true ) )
        {
            return $this->validation_error(
                $param_name,
                sprintf( /* translators: %s: comma-separated list of allowed values */
                    __( 'Invalid value. Allowed values are: %s.', 'sentient-forms' ),
                    implode( ', ', array_map( 'esc_html', $allowed_values ) ),
                ),
            );
        }

        return true;
    }

    /**
     * Validates an API key format (example: non-empty, alphanumeric and hyphens).
     *
     * @param string $api_key     The API key to validate.
     * @param string $param_name  The name of the API key parameter.
     * @param bool   $is_required Whether the API key is required to be non-empty.
     *
     * @return true|WP_Error True if valid, WP_Error otherwise.
     * @since 0.1.0
     */
    protected function validate_api_key_format( string $api_key, string $param_name, bool $is_required = false ): true | WP_Error
    {
        if ( $is_required && empty( trim( $api_key ) ) )
        {
            return $this->validation_error( $param_name, __( 'API key is required and cannot be empty.', 'sentient-forms' ) );
        }

        if ( !empty( $api_key ) && !preg_match( '/^[a-zA-Z0-9\-]+$/', $api_key ) )
        {
            return $this->validation_error(
                $param_name,
                __(
                    'API key contains invalid characters. Only alphanumeric characters and hyphens are allowed.',
                    'sentient-forms',
                ),
            );
        }

        return true;
    }

    /**
     * Validates if a value is a valid URL.
     *
     * @param mixed  $value      The value to check.
     * @param string $param_name The name of the parameter being validated.
     * @param array  $protocols  Optional. Array of allowed protocols. Defaults to http, https.
     *
     * @return true|WP_Error True if valid, WP_Error otherwise.
     * @since 0.1.0
     */
    protected function validate_is_url( mixed $value, string $param_name, array $protocols = [ 'http', 'https' ] ): true | WP_Error
    {
        if ( !is_string( $value ) || filter_var( $value, FILTER_VALIDATE_URL ) === false )
        {
            return $this->validation_error( $param_name, __( 'Must be a valid URL.', 'sentient-forms' ) );
        }

        if ( !empty( $protocols ) )
        {
            $parsed_url = wp_parse_url( $value );

            if ( !isset( $parsed_url[ 'scheme' ] ) || !in_array( strtolower( $parsed_url[ 'scheme' ] ), $protocols, true ) )
            {
                return $this->validation_error(
                    $param_name,
                    sprintf(
                        /* translators: %s: comma-separated list of allowed protocols. */
                        __( 'URL must use one of the following protocols: %s.', 'sentient-forms' ),
                        implode( ', ', array_map( 'esc_html', $protocols ) ),
                    ),
                );
            }
        }

        return true;
    }
}
