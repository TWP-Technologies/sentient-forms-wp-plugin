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
     * Slug for Elementor Forms.
     *
     * @var string
     * @since 0.5.1
     */
    const ELEMENTOR_FORMS = 'elementor_forms';

    /**
     * Slug for WPForms.
     *
     * @var string
     * @since 0.5.1
     */
    const WPFORMS = 'wpforms';

    /**
     * Gets canonical identifiers for all registered Form Sources.
     *
     * @param Sentient_Forms_Form_Adapter_Registry|null $registry Optional scoped registry.
     *
     * @return array<string> An array of registered Form Source slugs.
     * @since 0.1.0
     */
    public static function get_supported_sources( ?Sentient_Forms_Form_Adapter_Registry $registry = null ): array
    {
        $registry = self::resolve_registry( $registry );

        return $registry ? $registry->get_registered_source_ids() : [];
    }

    /**
     * Checks if a given form source slug is valid and supported.
     *
     * @param string                                      $source_slug The form source slug to validate.
     * @param Sentient_Forms_Form_Adapter_Registry|null $registry Optional scoped registry.
     *
     * @return bool True if the source slug is supported, false otherwise.
     * @since 0.1.0
     */
    public static function is_supported_source(
        string $source_slug,
        ?Sentient_Forms_Form_Adapter_Registry $registry = null
    ): bool
    {
        if ( empty( $source_slug ) )
        {
            return false;
        }

        $registry = self::resolve_registry( $registry );

        return $registry ? $registry->has_registered_source( $source_slug ) : false;
    }

    /**
     * Retrieve normalized native entry capabilities for a form source.
     *
     * @param string                                      $form_source_slug The form source slug.
     * @param Sentient_Forms_Form_Adapter_Registry|null $registry Optional adapter registry for tests or scoped callers.
     *
     * @return array<string, bool>|null Native entry capabilities, or null when unavailable.
     * @since 0.5.1
     */
    public static function native_entry_capability_for_form_source(
        string $form_source_slug,
        ?Sentient_Forms_Form_Adapter_Registry $registry = null
    ): ?array
    {
        if ( null === $registry )
        {
            $plugin = Sentient_Forms_Plugin::instance();
            if ( ! method_exists( $plugin, 'get_form_adapter_registry' ) )
            {
                return null;
            }

            $registry = $plugin->get_form_adapter_registry();
        }

        if ( ! $registry || ! method_exists( $registry, 'get_capability_descriptor' ) )
        {
            return null;
        }

        $descriptor = $registry->get_capability_descriptor( $form_source_slug );
        if ( ! is_array( $descriptor ) || ! isset( $descriptor['native_entry'] ) || ! is_array( $descriptor['native_entry'] ) )
        {
            return null;
        }

        return $descriptor['native_entry'];
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

    /**
     * Resolve the authoritative registry while allowing focused callers to
     * supply a scoped instance.
     */
    private static function resolve_registry(
        ?Sentient_Forms_Form_Adapter_Registry $registry = null
    ): ?Sentient_Forms_Form_Adapter_Registry
    {
        if ( null !== $registry )
        {
            return $registry;
        }

        if ( ! class_exists( 'Sentient_Forms_Plugin' ) )
        {
            return null;
        }

        $plugin = Sentient_Forms_Plugin::instance();

        return method_exists( $plugin, 'get_form_adapter_registry' )
            ? $plugin->get_form_adapter_registry()
            : null;
    }
}
