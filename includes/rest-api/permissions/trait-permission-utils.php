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
 * Trait Sentient_Forms_Permission_Utils_Trait
 * Contains common permission checking methods.
 */
trait Sentient_Forms_Permission_Utils_Trait
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
            /* translators: %s: required WordPress capability. */
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
     * Verifies a WordPress nonce using the standard wp_rest action.
     *
     * The nonce can be supplied either via the `X-WP-Nonce` header or the
     * `_wpnonce` request parameter. This mirrors WordPress core behaviour for
     * REST requests.
     *
     * @param WP_REST_Request $request The current REST request object.
     *
     * @return bool True when the nonce is valid, false otherwise.
     */
    public function verify_nonce( WP_REST_Request $request ): bool
    {
        $nonce = $request->get_header_as_array( 'X-WP-Nonce' )[0] ?? $request->get_param( '_wpnonce' );

        return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
    }

    /**
     * Combined permission check for admin capability and nonce validation.
     *
     * This method enforces that only users with `manage_options` capability can
     * access the endpoint. For mutating requests (POST, PUT, PATCH, DELETE) a
     * valid nonce is required.
     *
     * @param WP_REST_Request $r The REST request being processed.
     *
     * @return bool True if permission is granted, false otherwise.
     */
    public function permission_callback_with_nonce( WP_REST_Request $r ): bool
    {
        if ( ! current_user_can( 'manage_options' ) )
        {
            return false;
        }

        if ( in_array( $r->get_method(), [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) )
        {
            return $this->verify_nonce( $r );
        }

        return true;
    }
}
