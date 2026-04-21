<?php
/**
 * REST API Site Context Controller class for the Sentient Forms plugin.
 * Handles routes related to managing site context for personalized spam detection.
 *
 * @package    SentientForms
 * @subpackage REST_API\Controllers
 * @since      0.1.0
 */

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Site_Context_Controller
 * Manages REST API endpoints for site context (CB-SA-001).
 */
class Sentient_Forms_Site_Context_Controller extends Abstract_Sentient_Forms_Base_Controller
{
    use Trait_Sentient_Forms_Permission_Utils;

    private const OPTION_NAME = 'sentient_forms_site_context';
    private const MAX_CONTEXT_LENGTH = 5000;

    /**
     * The base of this controller's routes.
     *
     * @var string
     * @since 0.1.0
     */
    protected string $rest_base = 'site-context';

    /**
     * Registers the routes for the site context controller.
     *
     * @since 0.1.0
     */
    public function register_routes(): void
    {
        // GET /site-context - Get current site context
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_context' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'create_context' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'site_url' => [
                            'description'       => __( 'Site URL for context generation.', 'sentient-forms' ),
                            'type'              => 'string',
                            'sanitize_callback' => 'esc_url_raw',
                        ],
                        'pii_ack' => [
                            'description'       => __( 'Acknowledge PII will be sent to LLM.', 'sentient-forms' ),
                            'type'              => 'boolean',
                            'required'          => true,
                            'sanitize_callback' => 'rest_sanitize_boolean',
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'update_context' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'summary_text' => [
                            'description'       => __( 'Updated summary text.', 'sentient-forms' ),
                            'type'              => 'string',
                            'maxLength'         => 5000,
                            'sanitize_callback' => 'sanitize_textarea_field',
                        ],
                        'auto_include' => [
                            'description'       => __( 'Auto-include in spam prompts.', 'sentient-forms' ),
                            'type'              => 'boolean',
                            'sanitize_callback' => 'rest_sanitize_boolean',
                        ],
                        'pii_ack' => [
                            'description'       => __( 'PII acknowledgment.', 'sentient-forms' ),
                            'type'              => 'boolean',
                            'sanitize_callback' => 'rest_sanitize_boolean',
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Gets the current site context from local WordPress storage.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_context( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $context = $this->get_stored_context();
        if ( null === $context )
        {
            return $this->prepare_item_for_response( [ 'context' => null ] );
        }

        return $this->prepare_item_for_response( $context );
    }

    /**
     * Creates or regenerates site context.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function create_context( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $site_url = $request->get_param( 'site_url' ) ?? get_site_url();
        $pii_ack  = (bool) $request->get_param( 'pii_ack' );
        if ( ! $pii_ack )
        {
            return $this->prepare_error_response(
                'site_context_privacy_ack_required',
                __( 'Accept the site-context privacy acknowledgement before creating context.', 'sentient-forms' ),
                400,
            );
        }

        $context = $this->build_context_record(
            $this->generate_local_summary( is_scalar( $site_url ) ? (string) $site_url : get_site_url() ),
            'local_starter',
            true,
            $pii_ack,
            $this->get_stored_context()
        );

        update_option( self::OPTION_NAME, $context, false );

        return $this->prepare_item_for_response( $context );
    }

    /**
     * Updates site context (manual edit).
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function update_context( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $existing     = $this->get_stored_context();
        $summary_text = is_array( $existing ) ? (string) ( $existing['summary_text'] ?? '' ) : '';
        if ( $request->has_param( 'summary_text' ) )
        {
            $summary_text = $request->get_param( 'summary_text' );
            $summary_text = is_scalar( $summary_text ) ? sanitize_textarea_field( (string) $summary_text ) : '';

            // CB-SA-006: Server-side length guard (defense-in-depth).
            if ( mb_strlen( $summary_text ) > self::MAX_CONTEXT_LENGTH )
            {
                return $this->prepare_error_response(
                    'context_too_long',
                    __( 'Site context must be 5,000 characters or fewer.', 'sentient-forms' ),
                    400,
                );
            }
        }

        $auto_include = $request->has_param( 'auto_include' )
            ? (bool) $request->get_param( 'auto_include' )
            : (bool) ( $existing['auto_include'] ?? true );
        $pii_ack      = $request->has_param( 'pii_ack' )
            ? (bool) $request->get_param( 'pii_ack' )
            : (bool) ( $existing['pii_ack'] ?? false );

        if ( '' === trim( $summary_text ) )
        {
            return $this->prepare_error_response(
                'context_empty',
                __( 'Site context summary cannot be empty.', 'sentient-forms' ),
                400,
            );
        }

        $context = $this->build_context_record( $summary_text, 'manual', $auto_include, $pii_ack, $existing );
        update_option( self::OPTION_NAME, $context, false );

        return $this->prepare_item_for_response( $context );
    }

    /**
     * Retrieves the schema for the site context response.
     *
     * @return array|null Item schema data.
     */
    public function get_item_schema(): ?array
    {
        if ( $this->schema )
        {
            return $this->schema;
        }

        $this->schema = [
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => $this->rest_base,
            'type'       => 'object',
            'properties' => [
                'id' => [
                    'description' => __( 'Site context ID.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'summary_text' => [
                    'description' => __( 'The site context summary text.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                ],
                'source' => [
                    'description' => __( 'How context was created: local_starter, manual, import.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'auto_include' => [
                    'description' => __( 'Include context in spam detection prompts.', 'sentient-forms' ),
                    'type'        => 'boolean',
                    'context'     => [ 'view', 'edit' ],
                ],
                'pii_ack' => [
                    'description' => __( 'PII acknowledgment flag.', 'sentient-forms' ),
                    'type'        => 'boolean',
                    'context'     => [ 'view', 'edit' ],
                ],
                'free_refresh_available' => [
                    'description' => __( 'Whether a free yearly context refresh is currently available.', 'sentient-forms' ),
                    'type'        => 'boolean',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'next_free_refresh_at' => [
                    'description' => __( 'When the next free refresh becomes available; null when currently available.', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
            ],
        ];

        return $this->schema;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function get_stored_context(): ?array
    {
        $context = get_option( self::OPTION_NAME, null );
        if ( ! is_array( $context ) || empty( $context['summary_text'] ) || ! is_scalar( $context['summary_text'] ) )
        {
            return null;
        }

        return $this->normalize_context_record( $context );
    }

    private function generate_local_summary( string $site_url ): string
    {
        $site_name   = trim( (string) get_bloginfo( 'name' ) );
        $tagline     = trim( (string) get_bloginfo( 'description' ) );
        $description = trim( (string) get_option( 'blogdescription', '' ) );
        $host        = wp_parse_url( esc_url_raw( $site_url ), PHP_URL_HOST );

        $parts = [];
        if ( '' !== $site_name )
        {
            $parts[] = sprintf(
                /* translators: %s: Site name. */
                __( 'Site name: %s.', 'sentient-forms' ),
                $site_name
            );
        }
        if ( '' !== $tagline && $tagline !== $description )
        {
            $parts[] = sprintf(
                /* translators: %s: Site tagline. */
                __( 'Tagline: %s.', 'sentient-forms' ),
                $tagline
            );
        }
        elseif ( '' !== $description )
        {
            $parts[] = sprintf(
                /* translators: %s: Site description. */
                __( 'Site description: %s.', 'sentient-forms' ),
                $description
            );
        }
        if ( is_string( $host ) && '' !== $host )
        {
            $parts[] = sprintf(
                /* translators: %s: Site host. */
                __( 'Public host: %s.', 'sentient-forms' ),
                sanitize_text_field( $host )
            );
        }

        $parts[] = __( 'Use this local context with the form title, field labels, and submitted values to decide whether each form submission looks legitimate for this WordPress site.', 'sentient-forms' );

        return sanitize_textarea_field( implode( "\n", array_filter( $parts ) ) );
    }

    /**
     * @param array<string, mixed>|null $existing
     *
     * @return array<string, mixed>
     */
    private function build_context_record( string $summary_text, string $source, bool $auto_include, bool $pii_ack, ?array $existing = null ): array
    {
        $now = current_time( 'mysql' );
        return [
            'id'                     => is_array( $existing ) && isset( $existing['id'] ) ? sanitize_text_field( (string) $existing['id'] ) : 'local-site-context',
            'license_id'             => 'local',
            'summary_text'           => sanitize_textarea_field( $summary_text ),
            'source'                 => sanitize_key( $source ),
            'auto_include'           => $auto_include,
            'pii_ack'                => $pii_ack,
            'free_refresh_available' => true,
            'next_free_refresh_at'   => null,
            'created_at'             => is_array( $existing ) && isset( $existing['created_at'] ) ? sanitize_text_field( (string) $existing['created_at'] ) : $now,
            'updated_at'             => $now,
        ];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function normalize_context_record( array $context ): array
    {
        $normalized = $this->build_context_record(
            (string) $context['summary_text'],
            isset( $context['source'] ) ? (string) $context['source'] : 'manual',
            (bool) ( $context['auto_include'] ?? true ),
            (bool) ( $context['pii_ack'] ?? false ),
            $context
        );
        if ( isset( $context['updated_at'] ) )
        {
            $normalized['updated_at'] = sanitize_text_field( (string) $context['updated_at'] );
        }

        return $normalized;
    }
}
