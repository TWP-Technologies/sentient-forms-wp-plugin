<?php
/**
 * Error utility functions for the Sentient Forms plugin.
 *
 * @package    SentientForms
 * @subpackage Utils
 * @since      0.1.0
 */

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

enum Sentient_Forms_Error_Type: string
{
    case dependency = 'dependency';

    public function get_error_title(): string
    {
        return match ( $this )
        {
            self::dependency => esc_html__( 'Sentient Forms Dependency Error', 'sentient-forms' ),
        };
    }
}

/**
 * Class Sentient_Forms_Error_Utils
 * Provides utility methods for handling errors consistently across the plugin.
 */
class Sentient_Forms_Error_Utils
{
    /**
     * Handles critical errors by either throwing an exception (for REST requests)
     * or calling wp_die (for non-REST requests).
     * This function will always log the primary error message via error_log().
     * If WP_DEBUG is true, it will also log more detailed context including
     * file, line, function, and class of the caller, plus any custom $debug_info provided.
     *
     * @param string                           $error_message   The main error message. This string should be suitable for
     *                                                          translation if it's user-facing.
     * @param string|Sentient_Forms_Error_Type $error_title     Optional title for the wp_die screen. This string should be
     *                                                          suitable for translation if it's user-facing. Defaults to 'Plugin Error'.
     * @param array                            $debug_info      Optional additional information to log when WP_DEBUG is enabled.
     *                                                          This will be merged with automatically captured context, with
     *                                                          these values taking precedence in case of key conflicts.
     * @param int                              $status_code     HTTP status code to use. Defaults to 500.
     * @param string                           $exception_class Exception class to throw for REST requests.
     *                                                          Defaults to RuntimeException. Must be a fully qualified
     *                                                          class name and a subclass of \Exception.
     *
     * @return never
     * @throws Exception Always throws an exception for REST requests if the $exception_class is valid.
     * Falls back to \RuntimeException if $exception_class is invalid.
     * @since 0.1.0
     */
    public static function throw_or_die(
        string                             $error_message,
        string | Sentient_Forms_Error_Type $error_title = 'Plugin Error',
        array                              $debug_info = [],
        int                                $status_code = 500,
        string                             $exception_class = 'RuntimeException',
    ): never {
        // Basic error logging for all cases
        // Consider prefixing with plugin name for easier log filtering
        error_log( 'Sentient Forms Critical Error: ' . $error_message );

        // Gather information about the caller for more detailed logging
        // DEBUG_BACKTRACE_IGNORE_ARGS is used for performance and to avoid logging potentially sensitive argument data by default.
        // We are interested in the immediate caller of throw_or_die, which is at index 1.
        $backtrace    = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 );
        $caller_frame = $backtrace[ 1 ] ?? []; // Frame [1] is the caller

        $auto_captured_debug_context = [
            'error_source_file'      => $caller_frame[ 'file' ] ?? 'unknown_file',
            'error_source_line'      => $caller_frame[ 'line' ] ?? 'unknown_line',
            'error_source_function'  => $caller_frame[ 'function' ] ?? 'unknown_function',
            'sentient_forms_version' => SENTIENT_FORMS_VERSION ?? 'unknown_version',
        ];

        // Add class and call type context if the call was from a class method
        if ( isset( $caller_frame[ 'class' ] ) )
        {
            $auto_captured_debug_context[ 'error_source_class' ] = $caller_frame[ 'class' ];
            if ( isset( $caller_frame[ 'type' ] ) )
            { // '::' for static, '->' for instance method call
                $auto_captured_debug_context[ 'error_source_call_type' ] = $caller_frame[ 'type' ];
            }
        }

        $combined_debug_info = array_merge( $auto_captured_debug_context, $debug_info );

        // Log detailed debug information if WP_DEBUG is enabled.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG )
        {
            $json_encoded_debug_info = json_encode( $combined_debug_info, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

            // Fallback to print_r if json_encode fails
            if ( $json_encoded_debug_info === false )
            {
                $json_error = json_last_error_msg();
                error_log( "Sentient Forms - Debug: Failed to encode debug information for '$error_message'. JSON Error: $json_error" );
                error_log( 'Sentient Forms - Debug Context (raw): ' . print_r( $combined_debug_info, true ) );
            }
            else
            {
                error_log( "Sentient Forms - Debug Details: Message: '$error_message', Context: $json_encoded_debug_info" );
            }
        }

        $error_title = is_string( $error_title ) ? $error_title : $error_title->get_error_title();

        // Determine how to handle the error based on the request type
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST )
        {
            // Validate and prepare the exception class
            if ( !class_exists( $exception_class ) || !is_subclass_of( $exception_class, \Exception::class ) )
            {
                error_log(
                    sprintf(
                        'Sentient Forms - Warning: Invalid exception class "%s" provided for error "%s". Falling back to \RuntimeException.',
                        esc_html( $exception_class ), // Escaping for log, though class names are usually safe
                        $error_message,
                    ),
                );
                $exception_class = \RuntimeException::class; // Fallback to a known safe exception
            }
            throw new $exception_class( $error_message, $status_code );
        }
        else
        {
            // For non-REST requests, use wp_die to display an error page.
            $translated_error_title   = esc_html__( $error_title, 'sentient-forms' );
            $translated_error_message = esc_html__( $error_message, 'sentient-forms' );

            $wp_die_html_message = sprintf(
                "<h1>%s</h1><p>%s</p>",
                $translated_error_title,
                $translated_error_message,
            );

            // This check is extremely defensive.
            if ( !function_exists( 'wp_die' ) || !function_exists( 'esc_html__' ) )
            {
                // Fallback if WordPress core functions are somehow unavailable
                // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
                echo "<h1>" . htmlspecialchars( $error_title, ENT_QUOTES, 'UTF-8' ) . "</h1>";
                echo "<p>" . htmlspecialchars( $error_message, ENT_QUOTES, 'UTF-8' ) . "</p>";

                // phpcs:enable
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG )
                {
                    echo "<pre>Debug Info:\n" . htmlspecialchars( print_r( $combined_debug_info, true ), ENT_QUOTES, 'UTF-8' ) . "</pre>";
                }
                exit;
            }

            wp_die(
                $wp_die_html_message,
                $translated_error_title,
                [ 'response' => $status_code, 'back_link' => true ],
            );
        }
    }
}
