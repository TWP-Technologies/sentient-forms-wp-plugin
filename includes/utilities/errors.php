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
     * This function emits opt-in diagnostic context through the plugin debug hook
     * and avoids direct production log writes.
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
        $auto_captured_debug_context = [
            'sentient_forms_version' => SENTIENT_FORMS_VERSION ?? 'unknown_version',
        ];

        $combined_debug_info = array_merge( $auto_captured_debug_context, $debug_info );

        if ( function_exists( 'sentient_forms_debug_log' ) )
        {
            sentient_forms_debug_log(
                'Sentient Forms critical error.',
                array_merge(
                    $combined_debug_info,
                    [
                        'message' => $error_message,
                    ]
                )
            );
        }

        $error_title = is_string( $error_title ) ? $error_title : $error_title->get_error_title();

        // Determine how to handle the error based on the request type
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST )
        {
            // Validate and prepare the exception class
            if ( !class_exists( $exception_class ) || !is_subclass_of( $exception_class, \Exception::class ) )
            {
                if ( function_exists( 'sentient_forms_debug_log' ) )
                {
                    sentient_forms_debug_log(
                        'Sentient Forms invalid exception class fallback.',
                        [
                            'exception_class' => $exception_class,
                            'message'         => $error_message,
                        ]
                    );
                }
                $exception_class = \RuntimeException::class; // Fallback to a known safe exception
            }
            throw new $exception_class( esc_html( $error_message ), absint( $status_code ) );
        }
        else
        {
            // For non-REST requests, use wp_die to display an error page.
            $translated_error_title   = esc_html( $error_title );
            $translated_error_message = esc_html( $error_message );

            $wp_die_html_message = sprintf(
                "<h1>%s</h1><p>%s</p>",
                $translated_error_title,
                $translated_error_message,
            );

            wp_die(
                wp_kses_post( $wp_die_html_message ),
                esc_html( $translated_error_title ),
                [ 'response' => absint( $status_code ), 'back_link' => true ],
            );
        }
    }
}
