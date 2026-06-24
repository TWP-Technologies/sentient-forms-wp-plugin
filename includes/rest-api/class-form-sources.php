<?php
/**
 * Form Sources Manager for the Sentient Forms plugin.
 * Defines and manages recognized form source slugs (e.g., for different form plugins).
 *
 * @package    SentientForms
 * @subpackage Core
 * @since      0.1.0
 */

// Ensure this file is loaded within WordPress.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Form_Sources
 * Provides a centralized way to define and validate form source (plugin) identifiers.
 */
final class Sentient_Forms_Form_Sources
{

    /**
     * Slug for Gravity Forms.
     *
     * @var string
     * @since 0.1.0
     */
    const GRAVITY_FORMS = 'gravity_forms';

    /**
     * Slug for Contact Form 7.
     *
     * @var string
     * @since 0.3.18
     */
    const CONTACT_FORM_7 = 'contact_form_7';

    /**
     * Slug for WPForms (example for future).
     *
     * @var string
     * @since 0.1.0
     */
    // const WPFORMS = 'wpforms';

    /**
     * Gets an array of all known and supported form source slugs.
     * This list should be extensible, ideally allowing form adapters to register
     * their slugs. For now, it's defined here and made filterable.
     *
     * @return array<string> An array of supported form source slugs.
     * @since 0.1.0
     */
    public static function get_supported_sources()
    {
        $core_sources = [
            self::GRAVITY_FORMS,
            self::CONTACT_FORM_7,
            // self::WPFORMS,        // Uncomment when WPForms adapter is added
        ];

        /**
         * Filters the list of supported form source slugs.
         * Allows other adapters or extensions to register their unique source slugs.
         * Each slug should be a lowercase string, typically the plugin's slug or a derivative.
         *
         * @param array<string> $core_sources Array of core-supported form source slugs.
         *
         * @since 0.1.0
         */
        return apply_filters( 'sentient_forms_supported_form_sources', $core_sources );
    }

    /**
     * Checks if a given form source slug is valid and supported.
     *
     * @param string $source_slug The form source slug to validate.
     *
     * @return bool True if the source slug is supported, false otherwise.
     * @since 0.1.0
     */
    public static function is_supported_source( string $source_slug ): bool
    {
        if ( empty( $source_slug ) )
        {
            return false;
        }
        
        $supported_sources = self::get_supported_sources();
        return in_array( strtolower( $source_slug ), $supported_sources, true );
    }

    /**
     * Validates a form source slug parameter for REST API arguments.
     * To be used as a 'validate_callback'.
     *
     * @param string          $value   The value of the parameter.
     * @param WP_REST_Request $request The current REST API request object.
     * @param string          $param   The name of the parameter.
     *
     * @return true|WP_Error True if validation passed, WP_Error otherwise.
     * @since 0.1.0
     */
    public static function rest_validate_form_source_slug( string $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        if ( !self::is_supported_source( $value ) )
        {
            /* translators: 1: invalid form source slug, 2: comma-separated supported form source slugs. */
            $translated_text     = __( 'Invalid form source provided: "%1$s". Supported sources are: %2$s.', 'sentient-forms' );
            $invalid_form_source = esc_html( $value );
            $supported_sources   = implode( ', ', array_map( 'esc_html', self::get_supported_sources() ) );

            return new WP_Error(
                'rest_invalid_form_source',
                sprintf( $translated_text, $invalid_form_source, $supported_sources ),
                [ 'status' => 400, 'param' => $param ],
            );
        }
        return true;
    }

    /**
     * Sanitizes a form source slug parameter for REST API arguments.
     * To be used as a 'sanitize_callback'.
     *
     * @param string          $value   The value of the parameter.
     * @param WP_REST_Request $request The current REST API request object.
     * @param string          $param   The name of the parameter.
     *
     * @return string Sanitized value.
     * @since 0.1.0
     */
    public static function rest_sanitize_form_source_slug( string $value, WP_REST_Request $request, string $param ): string
    {
        return sanitize_key( strtolower( $value ) );
    }
}
