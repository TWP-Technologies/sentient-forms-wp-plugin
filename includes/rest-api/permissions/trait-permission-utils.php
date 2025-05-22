<?php
/**
 * Permission Utilities Trait for the Sentient Forms plugin.
 * Provides reusable helper methods for checking user permissions within
 * REST API permission callbacks.
 *
 * @package    SentientForms
 * @subpackage REST_API\Permissions
 * @since      0.1.0
 */

// Ensure this file is loaded within WordPress.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Trait Trait_Sentient_Forms_Permission_Utils
 * Contains common permission checking methods.
 */
trait Trait_Sentient_Forms_Permission_Utils
{

    /**
     * Prepares a permission error response.
     *
     * @param string $error_message    The human-readable error message.
     * @param string $error_code       Optional. A WordPress-style error code. Defaults to 'rest_forbidden'.
     * @param int    $http_status_code Optional. The HTTP status code for the error. Defaults to 403 (Forbidden).
     *
     * @return WP_Error The formatted WP_Error object.
     * @since 0.1.0
     */
    protected function permission_denied_error( string $error_message, string $error_code = 'rest_forbidden', int $http_status_code = 403 ): WP_Error
    {
        return new WP_Error( $error_code, $error_message, [ 'status' => $http_status_code ] );
    }

    /**
     * Checks if the current user has a specific capability.
     *
     * @param string $capability      The capability to check (e.g., 'manage_options').
     * @param string $context_message Optional. A message describing the action being protected. If empty, a default message is used.
     *
     * @return true|WP_Error True if the user has the capability, WP_Error otherwise.
     * @since 0.1.0
     */
    protected function check_current_user_capability( string $capability, string $context_message = '' ): true | WP_Error
    {
        if ( !current_user_can( $capability ) )
        {
            $default_message_format  = __( 'Sorry, you do not have permission to perform this action. Capability required: %s.', 'sentient-forms' );
            $message_capability_part = '`' . esc_html( $capability ) . '`';
            $final_message           = !empty( $context_message ) ? $context_message : sprintf( $default_message_format, $message_capability_part );

            return $this->permission_denied_error( $final_message );
        }

        return true;
    }

    /**
     * Checks if the current user is logged in.
     *
     * @return true|WP_Error True if the user is logged in, WP_Error otherwise.
     * @since 0.1.0
     */
    protected function check_user_is_logged_in(): true | WP_Error
    {
        if ( !is_user_logged_in() )
        {
            return $this->permission_denied_error(
                __( 'Sorry, you must be logged in to perform this action.', 'sentient-forms' ),
                'rest_not_logged_in',
                401, // HTTP 401 Unauthorized
            );
        }

        return true;
    }

    /**
     * Verifies a WordPress nonce.
     *
     * @param WP_REST_Request $request        The REST request object.
     * @param string          $nonce_action   The nonce action string (e.g., 'sentient_forms_custom_action').
     * @param string          $query_arg_name The name of the query argument or header that contains the nonce. Defaults to '_wpnonce'.
     *
     * @return true|WP_Error True if the nonce is valid, WP_Error otherwise.
     * @since 0.1.0
     */
    protected function verify_nonce( WP_REST_Request $request, string $nonce_action, string $query_arg_name = '_wpnonce' ): true | WP_Error
    {
        $nonce = $request->get_param( $query_arg_name );

        if ( !$nonce )
        {
            $nonce_header_names = [ 'X-WP-Nonce', 'x_wp_nonce', 'X-Sentient-Forms-Nonce', 'x_sentient_forms_nonce' ];
            foreach ( $nonce_header_names as $header_name )
            {
                $nonce = $request->get_header( $header_name );
                if ( $nonce )
                {
                    break;
                }
            }

            /* translators: %s: parameter name (_wpnonce) */
            $translated_text = __( 'Nonce is missing from the request (%s). Please include a valid nonce.', 'sentient-forms' );
            $error_message   = sprintf( $translated_text, esc_html( $query_arg_name ) );

            return $this->permission_denied_error( $error_message, 'rest_missing_nonce', 400 );
        }

        $nonce_verified = wp_verify_nonce( $nonce, $nonce_action );

        if ( !$nonce_verified )
        {
            return $this->permission_denied_error(
                __( 'Nonce is invalid or has expired.', 'sentient-forms' ),
                'rest_invalid_nonce',
                403,
            );
        }

        return true;
    }
}
