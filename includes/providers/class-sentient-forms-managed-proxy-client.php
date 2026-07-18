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
    private const PRIVACY_ROUTE_POLICY_SCHEMA = 'sentient_forms_privacy_route_policy.v1';
    private string $base_url;

    private Sentient_Forms_Api_Client $client;

    private ?WP_Error $configuration_error = null;

    public function __construct(
        ?string $base_url = null,
        int $timeout = 30,
        ?Sentient_Forms_Api_Client $client = null
    )
    {
        try
        {
            $this->base_url = $this->resolve_base_url( $base_url );
        }
        catch ( InvalidArgumentException $exception )
        {
            $this->base_url            = self::DEFAULT_BASE_URL;
            $this->configuration_error = $this->invalid_base_url_error( $exception );
        }

        $this->client = $client ?? new Sentient_Forms_Api_Client( $this->base_url, $timeout );
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
        if ( null !== $this->configuration_error )
        {
            return $this->configuration_error;
        }

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
        $execution_request_id = $payload['execution_request_id'];

        $response = $this->client->post_envelope(
            '/managed/execute',
            $payload,
            [
                'bearer_token' => $proxy_api_key,
            ]
        );

        if ( is_wp_error( $response ) )
        {
            return $this->normalize_execute_error( $response, $execution_request_id );
        }

        $response = $this->normalize_execute_envelope( $response, $execution_request_id );
        if ( is_wp_error( $response ) )
        {
            return $response;
        }

        return Sentient_Forms_Managed_Usage_Sanitizer::sanitize_for_managed_context( $response );
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
        if ( null !== $this->configuration_error )
        {
            return $this->configuration_error;
        }

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

        $response = $this->client->get(
            $path,
            [
                'bearer_token' => $proxy_api_key,
            ]
        );

        return is_wp_error( $response )
            ? $response
            : Sentient_Forms_Managed_Usage_Sanitizer::sanitize_for_managed_context( $response );
    }

    /**
     * Check the minimal service health endpoint.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function health(): array | WP_Error
    {
        if ( null !== $this->configuration_error )
        {
            return $this->configuration_error;
        }

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
    private function invalid_base_url_error( InvalidArgumentException $exception ): WP_Error
    {
        return new WP_Error(
            'sentient_managed_invalid_base_url',
            __( 'The managed service URL is invalid. Configure a bare HTTP(S) origin with an optional exact /v2 path.', 'sentient-forms' ),
            [
                'reason' => $exception->getMessage(),
            ]
        );
    }

    /**
     * Validate and unwrap the complete CPS success envelope.
     *
     * @param array<string, mixed> $envelope Complete decoded CPS response envelope.
     *
     * @return array<string, mixed>|WP_Error
     */
    private function normalize_execute_envelope(
        array $envelope,
        string $expected_execution_request_id
    ): array | WP_Error
    {
        if (
            ! $this->has_exact_keys( $envelope, [ 'success', 'data' ] )
            || true !== $envelope['success']
            || ! is_array( $envelope['data'] )
        )
        {
            return $this->invalid_execute_response_error( null );
        }

        return $this->normalize_execute_response( $envelope['data'], $expected_execution_request_id );
    }

    private function normalize_execute_error( WP_Error $error, string $expected_execution_request_id ): WP_Error
    {
        $code = $error->get_error_code();
        if ( 'managed_privacy_route_unavailable' === $code )
        {
            return $this->normalize_privacy_route_error( $error, $expected_execution_request_id );
        }

        $expected_status = match ( $code )
        {
            'in_progress', 'digest_conflict'             => 409,
            'settlement_pending', 'indeterminate',
            'settled_recovery_unavailable'               => 503,
            default                                      => null,
        };
        if ( null === $expected_status )
        {
            $data = $error->get_error_data();
            if ( is_array( $data ) && array_key_exists( 'payload', $data ) )
            {
                return $this->normalize_generic_execute_error( $error );
            }

            return $error;
        }

        $expected_message = 'settled_recovery_unavailable' === $code
            ? 'Managed execution settled, but its response payload is unavailable for replay.'
            : 'Managed execution has not reached a replayable terminal response.';

        $data    = $error->get_error_data();
        $status  = is_array( $data ) && is_int( $data['status'] ?? null ) ? $data['status'] : null;
        $payload = is_array( $data ) && is_array( $data['payload'] ?? null ) ? $data['payload'] : null;
        $remote  = is_array( $payload ) && is_array( $payload['error'] ?? null ) ? $payload['error'] : null;
        $meta    = is_array( $remote ) && is_array( $remote['meta'] ?? null ) ? $remote['meta'] : null;
        $execution_request_id = is_array( $meta )
            ? $this->bounded_response_string( $meta['execution_request_id'] ?? null, 128 )
            : null;

        if (
            $expected_status !== $status
            || ! is_array( $payload )
            || ! $this->has_exact_keys( $payload, [ 'success', 'error' ] )
            || false !== $payload['success']
            || ! is_array( $remote )
            || ! $this->has_exact_keys( $remote, [ 'code', 'message', 'meta' ] )
            || $code !== $remote['code']
            || $expected_message !== $remote['message']
            || ! is_array( $meta )
            || ! $this->has_exact_keys( $meta, [ 'execution_request_id' ] )
            || null === $execution_request_id
            || $expected_execution_request_id !== $execution_request_id
        )
        {
            return $this->invalid_execute_error( $status );
        }

        return new WP_Error(
            $code,
            $expected_message,
            [
                'status'               => $status,
                'execution_request_id' => $execution_request_id,
            ]
        );
    }

    private function normalize_generic_execute_error( WP_Error $error ): WP_Error
    {
        $data    = $error->get_error_data();
        $status  = is_array( $data ) && is_int( $data['status'] ?? null ) ? $data['status'] : null;
        $payload = is_array( $data ) && is_array( $data['payload'] ?? null ) ? $data['payload'] : null;
        $remote  = is_array( $payload ) && is_array( $payload['error'] ?? null ) ? $payload['error'] : null;
        $payload_object = is_array( $data ) ? ( $data['payload_object'] ?? null ) : null;
        $remote_object  = $payload_object instanceof stdClass && property_exists( $payload_object, 'error' )
            ? $payload_object->error
            : null;
        $code    = is_array( $remote ) && is_string( $remote['code'] ?? null ) && '' !== $remote['code']
            ? $remote['code']
            : null;
        $message = is_array( $remote ) && is_string( $remote['message'] ?? null ) && '' !== $remote['message']
            ? $remote['message']
            : null;

        if (
            null === $status
            || $status < 400
            || $status > 599
            || ! is_array( $payload )
            || ! $this->has_exact_keys( $payload, [ 'success', 'error' ] )
            || false !== $payload['success']
            || ! is_array( $remote )
            || ! ( $payload_object instanceof stdClass )
            || ! ( $remote_object instanceof stdClass )
            || ! $this->has_exact_keys( $remote, [ 'code', 'message' ], [ 'meta' ] )
            || null === $code
            || null === $message
            || $error->get_error_code() !== $code
            || $error->get_error_message() !== $message
            || (
                array_key_exists( 'meta', $remote )
                && (
                    ! is_array( $remote['meta'] )
                    || ! property_exists( $remote_object, 'meta' )
                    || ! ( $remote_object->meta instanceof stdClass )
                    || array_key_exists( 'execution_request_id', $remote['meta'] )
                    || array_key_exists( 'privacy_route_failure', $remote['meta'] )
                )
            )
        )
        {
            return $this->invalid_execute_error( $status );
        }

        return new WP_Error( $code, $message, [ 'status' => $status ] );
    }

    private function normalize_privacy_route_error(
        WP_Error $error,
        string $expected_execution_request_id
    ): WP_Error
    {
        $message = 'No ZDR-safe managed route was available, so Sentient Forms did not run this action without ZDR.';
        $data    = $error->get_error_data();
        $status  = is_array( $data ) && is_int( $data['status'] ?? null ) ? $data['status'] : null;
        $payload = is_array( $data ) && is_array( $data['payload'] ?? null ) ? $data['payload'] : null;
        $remote  = is_array( $payload ) && is_array( $payload['error'] ?? null ) ? $payload['error'] : null;
        $meta    = is_array( $remote ) && is_array( $remote['meta'] ?? null ) ? $remote['meta'] : null;
        $failure = is_array( $meta ) && is_array( $meta['privacy_route_failure'] ?? null )
            ? $meta['privacy_route_failure']
            : null;

        if (
            503 !== $status
            || ! is_array( $payload )
            || ! $this->has_exact_keys( $payload, [ 'success', 'error' ] )
            || false !== $payload['success']
            || ! is_array( $remote )
            || ! $this->has_exact_keys( $remote, [ 'code', 'message', 'meta' ] )
            || 'managed_privacy_route_unavailable' !== $remote['code']
            || $message !== $remote['message']
            || ! is_array( $meta )
            || ! $this->has_exact_keys( $meta, [ 'privacy_route_failure' ] )
            || ! is_array( $failure )
            || ! $this->has_exact_keys(
                $failure,
                [ 'schema', 'policy_version', 'reason_code', 'selected_model' ]
            )
            || 'sentient_forms_privacy_route_failure.v1' !== $failure['schema']
            || ! is_string( $failure['policy_version'] )
            || '' === $failure['policy_version']
            || 'managed_zdr_route_unavailable' !== $failure['reason_code']
            || null === $this->bounded_response_string( $failure['selected_model'], 191 )
        )
        {
            return $this->invalid_execute_error( $status );
        }

        return new WP_Error(
            'managed_privacy_route_unavailable',
            $message,
            [
                'status'                => 503,
                'execution_request_id'  => $expected_execution_request_id,
                'privacy_route_failure' => $failure,
            ]
        );
    }

    /**
     * Parse the strict CPS /v2 managed-execute success contract before callers trust it.
     *
     * @param array<string, mixed> $response Unwrapped CPS success data.
     *
     * @return array<string, mixed>|WP_Error
     */
    private function normalize_execute_response(
        array $response,
        string $expected_execution_request_id
    ): array | WP_Error
    {
        $execution_request_id = $this->bounded_response_string( $response['execution_request_id'] ?? null, 128 );
        $model                = $this->bounded_response_string( $response['model'] ?? null, 191 );
        if ( $expected_execution_request_id !== $execution_request_id )
        {
            return $this->invalid_execute_response_error( null );
        }

        if (
            ! $this->has_exact_keys(
                $response,
                [
                    'execution_request_id',
                    'provider',
                    'model',
                    'status',
                    'output',
                    'token_usage',
                    'metering',
                ],
                [
                    'privacy_route_assertion',
                    'privacy_route_fallback',
                ]
            )
            || self::MANAGED_PROVIDER !== ( $response['provider'] ?? null )
            || null === $model
            || 'succeeded' !== ( $response['status'] ?? null )
            || ! $this->valid_execute_output( $response['output'] ?? null )
            || ! $this->valid_execute_token_usage( $response['token_usage'] ?? null )
            || ! $this->valid_execute_metering( $response['metering'] ?? null )
            || (
                array_key_exists( 'privacy_route_assertion', $response )
                && ! $this->valid_privacy_route_assertion( $response['privacy_route_assertion'] )
            )
            || (
                array_key_exists( 'privacy_route_fallback', $response )
                && ! $this->valid_privacy_route_fallback(
                    $response['privacy_route_fallback'],
                    array_key_exists( 'privacy_route_assertion', $response ),
                    $model
                )
            )
        )
        {
            return $this->invalid_execute_response_error( $execution_request_id );
        }

        return $response;
    }

    private function valid_execute_output( mixed $output ): bool
    {
        return is_array( $output )
            && $this->has_exact_keys( $output, [ 'text' ] )
            && is_string( $output['text'] );
    }

    private function valid_execute_token_usage( mixed $token_usage ): bool
    {
        if (
            ! is_array( $token_usage )
            || ! $this->has_exact_keys( $token_usage, [ 'input_tokens', 'output_tokens', 'total_tokens' ] )
        )
        {
            return false;
        }

        foreach ( [ 'input_tokens', 'output_tokens', 'total_tokens' ] as $key )
        {
            if ( ! is_int( $token_usage[ $key ] ) || $token_usage[ $key ] < 0 )
            {
                return false;
            }
        }

        return true;
    }

    private function valid_execute_metering( mixed $metering ): bool
    {
        return is_array( $metering )
            && $this->has_exact_keys(
                $metering,
                [ 'event_id', 'free_usage', 'pricing_policy_version', 'debited_credits' ]
            )
            && is_string( $metering['event_id'] )
            && wp_is_uuid( $metering['event_id'] )
            && is_bool( $metering['free_usage'] )
            && is_string( $metering['pricing_policy_version'] )
            && is_int( $metering['debited_credits'] )
            && $metering['debited_credits'] >= 0;
    }

    private function valid_privacy_route_assertion( mixed $assertion ): bool
    {
        return is_array( $assertion )
            && $this->has_exact_keys(
                $assertion,
                [ 'schema', 'zdr_enforced', 'data_collection', 'route_policy_schema' ]
            )
            && 'sentient_forms_privacy_route_assertion.v1' === $assertion['schema']
            && true === $assertion['zdr_enforced']
            && 'deny' === $assertion['data_collection']
            && self::PRIVACY_ROUTE_POLICY_SCHEMA === $assertion['route_policy_schema'];
    }

    private function valid_privacy_route_fallback(
        mixed $fallback,
        bool $has_assertion,
        ?string $executed_model
    ): bool
    {
        return $has_assertion
            && is_array( $fallback )
            && $this->has_exact_keys(
                $fallback,
                [
                    'schema',
                    'policy_version',
                    'reason_code',
                    'original_model',
                    'fallback_model',
                    'executed_model',
                    'attempts',
                ]
            )
            && 'sentient_forms_privacy_route_fallback.v1' === $fallback['schema']
            && is_string( $fallback['policy_version'] )
            && '' !== $fallback['policy_version']
            && 'managed_zdr_primary_route_unavailable' === $fallback['reason_code']
            && null !== $this->bounded_response_string( $fallback['original_model'], 191 )
            && null !== $this->bounded_response_string( $fallback['fallback_model'], 191 )
            && null !== $this->bounded_response_string( $fallback['executed_model'], 191 )
            && $executed_model === $fallback['executed_model']
            && is_int( $fallback['attempts'] )
            && $fallback['attempts'] >= 1
            && $fallback['attempts'] <= 16;
    }

    /**
     * @param array<string, mixed> $value
     * @param string[]             $required
     * @param string[]             $optional
     */
    private function has_exact_keys( array $value, array $required, array $optional = [] ): bool
    {
        foreach ( $required as $key )
        {
            if ( ! array_key_exists( $key, $value ) )
            {
                return false;
            }
        }

        return [] === array_diff( array_keys( $value ), array_merge( $required, $optional ) );
    }

    private function bounded_response_string( mixed $value, int $maximum_length ): ?string
    {
        if ( ! is_string( $value ) || '' === $value )
        {
            return null;
        }

        if ( strlen( $value ) > ( $maximum_length * 4 ) )
        {
            return null;
        }

        $matched = preg_match_all( '/./us', $value, $characters );
        if ( false === $matched || $matched > $maximum_length )
        {
            return null;
        }

        return $value;
    }

    private function invalid_execute_response_error( ?string $execution_request_id ): WP_Error
    {
        return new WP_Error(
            'sentient_managed_invalid_execute_response',
            __( 'The managed service returned an invalid execution response.', 'sentient-forms' ),
            null === $execution_request_id ? null : [ 'execution_request_id' => $execution_request_id ]
        );
    }

    private function invalid_execute_error( ?int $status ): WP_Error
    {
        return new WP_Error(
            'sentient_managed_invalid_execute_error',
            __( 'The managed service returned an invalid execution error.', 'sentient-forms' ),
            null === $status ? null : [ 'status' => $status ]
        );
    }

    private function normalize_execute_payload( array $payload ): array | WP_Error
    {
        return Sentient_Forms_Managed_Execute_Request::normalize( $payload );
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
            $url = self::DEFAULT_BASE_URL;
        }

        return Sentient_Forms_Managed_Base_Url::normalize( $url );
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

}
