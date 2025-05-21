<?php
/**
 * Utility class for custom REST API argument validation and sanitization.
 *
 * @package    SentientForms
 * @subpackage REST_API\Utils
 * @since      0.1.0
 */

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_REST_Argument_Utils
{

    /**
     * Sanitizes an array of strings, expecting each to be a key.
     * Ensures the input is an array and applies sanitize_key to each element.
     *
     * @param mixed           $value   The value to sanitize. Expected to be an array.
     * @param WP_REST_Request $request The request object. (Unused here, but part of callback signature)
     * @param string          $param   The parameter name. (Unused here, but part of callback signature)
     *
     * @return array Sanitized array of keys. Returns an empty array if input is not an array.
     */
    public static function sanitize_array_of_keys( mixed $value, WP_REST_Request $request, string $param ): array
    {
        if ( !is_array( $value ) )
        {
            return [];
        }

        return array_map( 'sanitize_key', $value );
    }

    /**
     * Validates that a value is an array and that all its items are strings.
     *
     * @param mixed           $value   The value to validate.
     * @param WP_REST_Request $request The request object. (Unused here, but part of callback signature)
     * @param string          $param   The parameter name.
     *
     * @return true|WP_Error True if valid, WP_Error otherwise.
     */
    public static function validate_array_of_strings( mixed $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        if ( !is_array( $value ) )
        {
            return new WP_Error(
                'rest_invalid_type',
                sprintf( esc_html__( '%s must be an array.', 'sentient-forms' ), $param ),
                [ 'status' => 400, 'param' => $param ],
            );
        }

        foreach ( $value as $index => $item )
        {
            if ( !is_string( $item ) )
            {
                $message = sprintf(
                    esc_html__( 'Item at index %1$d in %2$s must be a string.', 'sentient-forms' ),
                    $index,
                    $param,
                );

                return new WP_Error( 'rest_invalid_item_type', $message, [ 'status' => 400, 'param' => $param ], );
            }
        }
        
        return true;
    }
}
