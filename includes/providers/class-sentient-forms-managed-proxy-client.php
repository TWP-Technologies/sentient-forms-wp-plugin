<?php
/**
 * Sentient Forms managed-service client for paid local-first execution.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Managed_Proxy_Client
{
    private const DEFAULT_BASE_URL = 'https://api.sentientforms.com/v2';
    private const MANAGED_PROVIDER = 'sentient_managed';
    private const ALLOWED_EXECUTE_PAYLOAD_KEYS = [
        'site_id'              => true,
        'execution_request_id' => true,
        'provider'             => true,
        'model'                => true,
        'action_code'          => true,
        'prompt'               => true,
        'output_contract'      => true,
        'metadata'             => true,
        'temperature'          => true,
        'max_output_tokens'    => true,
        'timeout_seconds'      => true,
        'reasoning'            => true,
        'tools'                => true,
        'tool_choice'          => true,
    ];

    private string $base_url;

    private Sentient_Forms_Api_Client $client;

    public function __construct(
        ?string $base_url = null,
        int $timeout = 30,
        ?Sentient_Forms_Api_Client $client = null
    )
    {
        $this->base_url = $this->resolve_base_url( $base_url );
        $this->client   = $client ?? new Sentient_Forms_Api_Client( $this->base_url, $timeout );
    }

    /**
     * Execute a Sentient Forms managed paid request through the minimal service.
     *
     * @param string               $proxy_api_key Site credential key issued by Sentient Forms.
     * @param array<string, mixed> $payload       Managed execution payload.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function execute( string $proxy_api_key, array $payload ): array | WP_Error
    {
        $proxy_api_key = trim( $proxy_api_key );
        if ( '' === $proxy_api_key )
        {
            return new WP_Error(
                'sentient_managed_missing_proxy_key',
                __( 'Sentient Forms managed execution requires a managed-service credential.', 'sentient-forms' )
            );
        }

        $payload = $this->normalize_execute_payload( $payload );
        if ( is_wp_error( $payload ) )
        {
            return $payload;
        }

        return $this->client->post(
            '/managed/execute',
            $payload,
            [
                'bearer_token' => $proxy_api_key,
            ]
        );
    }

    /**
     * Fetch server-side metering totals for the authenticated managed site.
     *
     * @param string      $proxy_api_key Site proxy key issued by Sentient.
     * @param string|null $site_id       Optional site UUID for unauthenticated/dev service contexts.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function get_metering_summary( string $proxy_api_key, ?string $site_id = null ): array | WP_Error
    {
        $proxy_api_key = trim( $proxy_api_key );
        if ( '' === $proxy_api_key )
        {
            return new WP_Error(
                'sentient_managed_missing_proxy_key',
                __( 'Sentient metering requires a proxy key.', 'sentient-forms' )
            );
        }

        $path = '/metering/summary';
        if ( null !== $site_id )
        {
            $site_id = sanitize_text_field( trim( $site_id ) );
            if ( '' === $site_id )
            {
                return new WP_Error(
                    'sentient_managed_missing_site_id',
                    __( 'A site ID is required when requesting a scoped metering summary.', 'sentient-forms' )
                );
            }

            $path .= '?' . http_build_query( [ 'site_id' => $site_id ], '', '&', PHP_QUERY_RFC3986 );
        }

        return $this->client->get(
            $path,
            [
                'bearer_token' => $proxy_api_key,
            ]
        );
    }

    /**
     * Check the minimal service health endpoint.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function health(): array | WP_Error
    {
        return $this->client->get( '/health' );
    }

    public function get_base_url(): string
    {
        return $this->base_url;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|WP_Error
     */
    private function normalize_execute_payload( array $payload ): array | WP_Error
    {
        $unsupported_keys = array_values( array_diff( array_keys( $payload ), array_keys( self::ALLOWED_EXECUTE_PAYLOAD_KEYS ) ) );
        if ( [] !== $unsupported_keys )
        {
            return new WP_Error(
                'sentient_managed_unsupported_payload_field',
                sprintf(
                    /* translators: %s: comma-separated request field names. */
                    __( 'Managed execution payload includes unsupported field(s): %s.', 'sentient-forms' ),
                    implode( ', ', array_map( 'sanitize_key', $unsupported_keys ) )
                ),
                [
                    'unsupported_fields' => $unsupported_keys,
                ]
            );
        }

        foreach ( [ 'site_id', 'execution_request_id', 'model', 'prompt' ] as $required_key )
        {
            if ( ! isset( $payload[ $required_key ] ) || ! is_scalar( $payload[ $required_key ] ) )
            {
                return new WP_Error(
                    'sentient_managed_invalid_payload',
                    sprintf(
                        /* translators: %s: request field name. */
                        __( 'Managed execution payload is missing %s.', 'sentient-forms' ),
                        $required_key
                    )
                );
            }
        }

        $normalized = $payload;
        $normalized['site_id']              = sanitize_text_field( (string) $payload['site_id'] );
        $normalized['execution_request_id'] = sanitize_text_field( (string) $payload['execution_request_id'] );
        $normalized['model']                = sanitize_text_field( (string) $payload['model'] );
        $normalized['prompt']               = (string) $payload['prompt'];
        $normalized['provider']             = isset( $payload['provider'] )
            ? sanitize_key( (string) $payload['provider'] )
            : self::MANAGED_PROVIDER;

        if ( '' === trim( $normalized['site_id'] ) )
        {
            return new WP_Error(
                'sentient_managed_missing_site_id',
                __( 'Managed execution requires a site ID.', 'sentient-forms' )
            );
        }

        if ( '' === trim( $normalized['execution_request_id'] ) || strlen( $normalized['execution_request_id'] ) > 128 )
        {
            return new WP_Error(
                'sentient_managed_invalid_execution_request_id',
                __( 'Managed execution requires a request ID of 128 characters or fewer.', 'sentient-forms' )
            );
        }

        if ( '' === trim( $normalized['model'] ) || strlen( $normalized['model'] ) > 191 )
        {
            return new WP_Error(
                'sentient_managed_invalid_model',
                __( 'Managed execution requires a model of 191 characters or fewer.', 'sentient-forms' )
            );
        }

        if ( '' === trim( $normalized['prompt'] ) )
        {
            return new WP_Error(
                'sentient_managed_missing_prompt',
                __( 'Managed execution requires a prompt.', 'sentient-forms' )
            );
        }

        if ( self::MANAGED_PROVIDER !== $normalized['provider'] )
        {
            return new WP_Error(
                'sentient_managed_provider_required',
                __( 'Managed execution requests must use the Sentient Forms managed-service provider.', 'sentient-forms' )
            );
        }

        if ( isset( $payload['action_code'] ) )
        {
            if ( ! is_scalar( $payload['action_code'] ) )
            {
                return new WP_Error(
                    'sentient_managed_invalid_action_code',
                    __( 'Managed execution action code must be a string.', 'sentient-forms' )
                );
            }

            $normalized['action_code'] = sanitize_text_field( (string) $payload['action_code'] );
            if ( '' === trim( $normalized['action_code'] ) || strlen( $normalized['action_code'] ) > 191 )
            {
                return new WP_Error(
                    'sentient_managed_invalid_action_code',
                    __( 'Managed execution action code must be non-empty and at most 191 characters.', 'sentient-forms' )
                );
            }
        }

        if ( isset( $payload['max_output_tokens'] ) )
        {
            $normalized['max_output_tokens'] = (int) $payload['max_output_tokens'];
            if ( $normalized['max_output_tokens'] < 1 || $normalized['max_output_tokens'] > 16384 )
            {
                return new WP_Error(
                    'sentient_managed_invalid_max_output_tokens',
                    __( 'Managed execution max output tokens must be between 1 and 16384.', 'sentient-forms' )
                );
            }
        }

        if ( isset( $payload['temperature'] ) )
        {
            if ( ! is_numeric( $payload['temperature'] ) )
            {
                return new WP_Error(
                    'sentient_managed_invalid_temperature',
                    __( 'Managed execution temperature must be numeric.', 'sentient-forms' )
                );
            }

            $normalized['temperature'] = (float) $payload['temperature'];
        }

        if ( isset( $payload['timeout_seconds'] ) )
        {
            $normalized['timeout_seconds'] = absint( $payload['timeout_seconds'] );
            if ( $normalized['timeout_seconds'] < 1 || $normalized['timeout_seconds'] > 300 )
            {
                return new WP_Error(
                    'sentient_managed_invalid_timeout_seconds',
                    __( 'Managed execution timeout must be between 1 and 300 seconds.', 'sentient-forms' )
                );
            }
        }

        if ( isset( $payload['metadata'] ) )
        {
            $metadata = $this->normalize_identifier_metadata( $payload['metadata'] );
            if ( is_wp_error( $metadata ) )
            {
                return $metadata;
            }

            $normalized['metadata'] = $metadata;
        }

        if ( isset( $payload['reasoning'] ) )
        {
            $reasoning = $this->normalize_reasoning_payload( $payload['reasoning'] );
            if ( is_wp_error( $reasoning ) )
            {
                return $reasoning;
            }

            $normalized['reasoning'] = $reasoning;
        }

        if ( isset( $payload['tools'] ) )
        {
            $tools = $this->normalize_openrouter_tools( $payload['tools'] );
            if ( is_wp_error( $tools ) )
            {
                return $tools;
            }
            if ( [] !== $tools )
            {
                $normalized['tools'] = $tools;
            }
        }

        if ( isset( $payload['tool_choice'] ) && is_scalar( $payload['tool_choice'] ) )
        {
            $tool_choice = sanitize_key( (string) $payload['tool_choice'] );
            if ( in_array( $tool_choice, [ 'auto', 'required', 'none' ], true ) )
            {
                $normalized['tool_choice'] = $tool_choice;
            }
        }

        return $normalized;
    }

    /**
     * @param mixed $tools
     *
     * @return array<int, array<string, mixed>>|WP_Error
     */
    private function normalize_openrouter_tools( mixed $tools ): array | WP_Error
    {
        if ( ! is_array( $tools ) )
        {
            return new WP_Error(
                'sentient_managed_invalid_tools',
                __( 'Managed execution tools must be an array.', 'sentient-forms' )
            );
        }

        $normalized = [];
        foreach ( $tools as $tool )
        {
            if ( ! is_array( $tool ) || ! isset( $tool['type'] ) || ! is_scalar( $tool['type'] ) )
            {
                return new WP_Error(
                    'sentient_managed_invalid_tools',
                    __( 'Managed execution tools must include a tool type.', 'sentient-forms' )
                );
            }

            $type = sanitize_text_field( (string) $tool['type'] );
            if ( ! in_array( $type, [ 'openrouter:web_search', 'openrouter:web_fetch', 'openrouter:datetime' ], true ) )
            {
                return new WP_Error(
                    'sentient_managed_invalid_tools',
                    __( 'Managed execution only supports OpenRouter server tools.', 'sentient-forms' )
                );
            }

            $next = [ 'type' => $type ];
            if ( isset( $tool['parameters'] ) )
            {
                if ( ! is_array( $tool['parameters'] ) )
                {
                    return new WP_Error(
                        'sentient_managed_invalid_tools',
                        __( 'Managed execution tool parameters must be an object.', 'sentient-forms' )
                    );
                }
                $parameters = [];
                foreach ( $tool['parameters'] as $key => $value )
                {
                    $key = sanitize_key( (string) $key );
                    if ( '' === $key || is_array( $value ) || is_object( $value ) )
                    {
                        continue;
                    }
                    $parameters[ $key ] = is_numeric( $value ) ? (int) $value : sanitize_text_field( (string) $value );
                }
                if ( [] !== $parameters )
                {
                    $next['parameters'] = $parameters;
                }
            }

            $normalized[] = $next;
        }

        return $normalized;
    }

    /**
     * @param mixed $reasoning
     *
     * @return array{effort: string, exclude: bool}|WP_Error
     */
    private function normalize_reasoning_payload( mixed $reasoning ): array | WP_Error
    {
        if ( ! is_array( $reasoning ) || array_is_list( $reasoning ) )
        {
            return new WP_Error(
                'sentient_managed_invalid_reasoning',
                __( 'Managed execution reasoning must be an object.', 'sentient-forms' )
            );
        }

        $effort = isset( $reasoning['effort'] ) && is_scalar( $reasoning['effort'] )
            ? sanitize_key( (string) $reasoning['effort'] )
            : '';
        if ( ! in_array( $effort, [ 'none', 'minimal', 'low', 'medium', 'high', 'xhigh' ], true ) )
        {
            return new WP_Error(
                'sentient_managed_invalid_reasoning',
                __( 'Managed execution reasoning effort must be none, minimal, low, medium, high, or xhigh.', 'sentient-forms' )
            );
        }

        return [
            'effort'  => $effort,
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- OpenRouter reasoning payload key, not a WP_Query parameter.
            'exclude' => true,
        ];
    }

    /**
     * @param mixed $metadata
     *
     * @return array<string, scalar|null>|WP_Error
     */
    private function normalize_identifier_metadata( mixed $metadata ): array | WP_Error
    {
        if ( ! is_array( $metadata ) || array_is_list( $metadata ) )
        {
            return new WP_Error(
                'sentient_managed_metadata_not_identifier_only',
                __( 'Managed execution metadata must be an object of identifier values.', 'sentient-forms' )
            );
        }

        $normalized = [];
        foreach ( $metadata as $key => $value )
        {
            $key = sanitize_key( (string) $key );
            if ( '' === $key || strlen( $key ) > 64 )
            {
                return new WP_Error(
                    'sentient_managed_invalid_metadata_key',
                    __( 'Managed execution metadata keys must be non-empty identifiers of 64 characters or fewer.', 'sentient-forms' )
                );
            }

            if ( is_array( $value ) || is_object( $value ) )
            {
                return new WP_Error(
                    'sentient_managed_metadata_not_identifier_only',
                    __( 'Managed execution metadata must not include nested payloads or local action definitions.', 'sentient-forms' ),
                    [
                        'metadata_key' => $key,
                    ]
                );
            }

            if ( null !== $value && ! is_scalar( $value ) )
            {
                return new WP_Error(
                    'sentient_managed_metadata_not_identifier_only',
                    __( 'Managed execution metadata values must be scalar identifiers.', 'sentient-forms' ),
                    [
                        'metadata_key' => $key,
                    ]
                );
            }

            $normalized[ $key ] = $value;
        }

        return $normalized;
    }

    private function resolve_base_url( ?string $base_url ): string
    {
        $url = $base_url;
        if ( ! is_string( $url ) || '' === trim( $url ) )
        {
            $url = $this->resolve_managed_service_override();
        }

        if ( ! is_string( $url ) || '' === trim( $url ) )
        {
            $url = $this->resolve_proxy_api_override();
        }

        if ( ! is_string( $url ) || '' === trim( $url ) )
        {
            $url = $this->resolve_cps_base_url_default();
        }

        if ( ! is_string( $url ) || '' === trim( $url ) )
        {
            $url = self::DEFAULT_BASE_URL;
        }

        $url = untrailingslashit( trim( $url ) );
        if ( str_ends_with( $url, '/v1' ) )
        {
            return substr( $url, 0, -3 ) . '/v2';
        }

        if ( ! str_ends_with( $url, '/v2' ) )
        {
            $url .= '/v2';
        }

        return $url;
    }

    private function resolve_managed_service_override(): ?string
    {
        $url = null;

        if ( defined( 'SENTIENT_FORMS_MANAGED_SERVICE_URL' ) && is_string( constant( 'SENTIENT_FORMS_MANAGED_SERVICE_URL' ) ) )
        {
            $url = constant( 'SENTIENT_FORMS_MANAGED_SERVICE_URL' );
        }
        elseif ( getenv( 'SENTIENT_FORMS_MANAGED_SERVICE_URL' ) )
        {
            $url = (string) getenv( 'SENTIENT_FORMS_MANAGED_SERVICE_URL' );
        }

        $filtered = apply_filters( 'sentient_forms_managed_service_url', $url );
        if ( is_string( $filtered ) && '' !== trim( $filtered ) )
        {
            return $filtered;
        }

        return is_string( $url ) && '' !== trim( $url ) ? $url : null;
    }

    private function resolve_proxy_api_override(): ?string
    {
        $url = null;

        if ( defined( 'SENTIENT_FORMS_PROXY_API_URL' ) && is_string( constant( 'SENTIENT_FORMS_PROXY_API_URL' ) ) )
        {
            $url = constant( 'SENTIENT_FORMS_PROXY_API_URL' );
        }
        elseif ( getenv( 'SENTIENT_FORMS_PROXY_API_URL' ) )
        {
            $url = (string) getenv( 'SENTIENT_FORMS_PROXY_API_URL' );
        }

        $filtered = apply_filters( 'sentient_forms_proxy_api_url', $url );
        if ( is_string( $filtered ) && '' !== trim( $filtered ) )
        {
            return $filtered;
        }

        return is_string( $url ) && '' !== trim( $url ) ? $url : null;
    }

    private function resolve_cps_base_url_default(): string
    {
        $options  = get_option( 'sentient_forms_settings', [] );
        $options  = is_array( $options ) ? $options : [];
        $base_url = $options['cps_base_url'] ?? null;
        $base_url = apply_filters( 'sentient_forms_cps_base_url', $base_url, $options );

        if ( is_string( $base_url ) && '' !== trim( $base_url ) )
        {
            return $base_url;
        }

        return defined( 'SENTIENT_FORMS_DEFAULT_CPS_BASE_URL' )
            ? SENTIENT_FORMS_DEFAULT_CPS_BASE_URL
            : 'https://api.sentientforms.com/v1';
    }
}
