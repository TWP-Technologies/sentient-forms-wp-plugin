<?php
/**
 * Strict builder for the CPS /v2 managed execution request contract.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

final class Sentient_Forms_Managed_Execute_Request
{
    private const MANAGED_PROVIDER = 'sentient_managed';
    private const PRIVACY_ROUTE_POLICY_SCHEMA = 'sentient_forms_privacy_route_policy.v1';

    private const ALLOWED_KEYS = [
        'site_id'                   => true,
        'execution_request_id'      => true,
        'provider'                  => true,
        'model'                     => true,
        'action_code'               => true,
        'prompt'                    => true,
        'output_contract'           => true,
        'metadata'                  => true,
        'temperature'               => true,
        'max_output_tokens'         => true,
        'timeout_seconds'           => true,
        'reasoning'                 => true,
        'tools'                     => true,
        'tool_choice'               => true,
        'privacy_route_policy'      => true,
        'managed_capability_policy' => true,
    ];

    /**
     * Build a request whose JSON representation satisfies the checked CPS
     * schema and the stricter CPS application invariants.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|WP_Error
     */
    public static function normalize( array $payload ): array | WP_Error
    {
        $unsupported_keys = array_values( array_diff( array_keys( $payload ), array_keys( self::ALLOWED_KEYS ) ) );
        if ( [] !== $unsupported_keys )
        {
            return new WP_Error(
                'sentient_managed_unsupported_payload_field',
                sprintf(
                    /* translators: %s: comma-separated request field names. */
                    __( 'Managed execution payload includes unsupported field(s): %s.', 'sentient-forms' ),
                    implode( ', ', array_map( 'sanitize_key', $unsupported_keys ) )
                ),
                [ 'unsupported_fields' => $unsupported_keys ]
            );
        }

        foreach ( [ 'site_id', 'execution_request_id', 'model', 'prompt' ] as $required_key )
        {
            if ( ! array_key_exists( $required_key, $payload ) || ! is_string( $payload[ $required_key ] ) )
            {
                return self::invalid_payload_error( $required_key );
            }
        }

        $normalized = [
            'site_id'              => $payload['site_id'],
            'execution_request_id' => $payload['execution_request_id'],
            'provider'             => self::MANAGED_PROVIDER,
            'model'                => $payload['model'],
            'prompt'               => $payload['prompt'],
        ];

        if ( ! wp_is_uuid( $normalized['site_id'] ) )
        {
            return self::error(
                'sentient_managed_invalid_site_id',
                __( 'Managed execution requires a valid site UUID.', 'sentient-forms' )
            );
        }

        if ( 1 !== preg_match( '/^[A-Za-z0-9_.:\/@-]{1,128}$/D', $normalized['execution_request_id'] ) )
        {
            return self::error(
                'sentient_managed_invalid_execution_request_id',
                __( 'Managed execution request IDs must use 1 to 128 ASCII letters, numbers, or -_.:/@ characters.', 'sentient-forms' )
            );
        }

        if ( ! preg_match( '/^[\x21-\x7E]{1,191}$/D', $normalized['model'] ) )
        {
            return self::error(
                'sentient_managed_invalid_model',
                __( 'Managed execution models must use 1 to 191 printable ASCII characters without spaces.', 'sentient-forms' )
            );
        }

        if ( '' === trim( $normalized['prompt'] ) )
        {
            return self::error(
                'sentient_managed_missing_prompt',
                __( 'Managed execution requires a prompt.', 'sentient-forms' )
            );
        }

        if ( array_key_exists( 'provider', $payload ) && self::MANAGED_PROVIDER !== $payload['provider'] )
        {
            return self::error(
                'sentient_managed_provider_required',
                __( 'Managed execution requests must use the Sentient Forms managed-service provider.', 'sentient-forms' )
            );
        }

        if ( array_key_exists( 'action_code', $payload ) )
        {
            if (
                ! is_string( $payload['action_code'] )
                || '' === trim( $payload['action_code'] )
                || mb_strlen( $payload['action_code'], 'UTF-8' ) > 191
            )
            {
                return self::error(
                    'sentient_managed_invalid_action_code',
                    __( 'Managed execution action codes must be non-empty strings of at most 191 characters.', 'sentient-forms' )
                );
            }
            $normalized['action_code'] = $payload['action_code'];
        }

        if ( array_key_exists( 'output_contract', $payload ) )
        {
            if ( ! self::is_json_object( $payload['output_contract'] ) || ! self::is_json_value( $payload['output_contract'] ) )
            {
                return self::error(
                    'sentient_managed_invalid_output_contract',
                    __( 'Managed execution output contracts must be JSON objects.', 'sentient-forms' )
                );
            }
            $normalized['output_contract'] = $payload['output_contract'];
        }

        if ( array_key_exists( 'metadata', $payload ) )
        {
            $metadata = self::normalize_metadata( $payload['metadata'] );
            if ( is_wp_error( $metadata ) )
            {
                return $metadata;
            }
            $normalized['metadata'] = $metadata;
        }

        if ( array_key_exists( 'temperature', $payload ) )
        {
            if ( ! self::is_finite_number( $payload['temperature'] ) )
            {
                return self::error(
                    'sentient_managed_invalid_temperature',
                    __( 'Managed execution temperature must be a finite number.', 'sentient-forms' )
                );
            }
            $normalized['temperature'] = $payload['temperature'];
        }

        if ( array_key_exists( 'max_output_tokens', $payload ) )
        {
            $max_output_tokens = $payload['max_output_tokens'];
            if ( ! is_int( $max_output_tokens ) || $max_output_tokens < 1 || $max_output_tokens > 16384 )
            {
                return self::error(
                    'sentient_managed_invalid_max_output_tokens',
                    __( 'Managed execution max output tokens must be an integer between 1 and 16384.', 'sentient-forms' )
                );
            }
            $normalized['max_output_tokens'] = $max_output_tokens;
        }

        if ( array_key_exists( 'timeout_seconds', $payload ) )
        {
            $timeout_seconds = $payload['timeout_seconds'];
            if ( ! is_int( $timeout_seconds ) || $timeout_seconds < 1 || $timeout_seconds > 300 )
            {
                return self::error(
                    'sentient_managed_invalid_timeout_seconds',
                    __( 'Managed execution timeout must be an integer between 1 and 300 seconds.', 'sentient-forms' )
                );
            }
            $normalized['timeout_seconds'] = $timeout_seconds;
        }

        if ( array_key_exists( 'reasoning', $payload ) )
        {
            $reasoning = self::normalize_reasoning( $payload['reasoning'] );
            if ( is_wp_error( $reasoning ) )
            {
                return $reasoning;
            }
            $normalized['reasoning'] = $reasoning;
        }

        if ( array_key_exists( 'tools', $payload ) )
        {
            $tools = self::normalize_tools( $payload['tools'] );
            if ( is_wp_error( $tools ) )
            {
                return $tools;
            }
            $normalized['tools'] = $tools;
        }

        if ( array_key_exists( 'tool_choice', $payload ) )
        {
            if ( ! is_string( $payload['tool_choice'] ) || ! in_array( $payload['tool_choice'], [ 'auto', 'required', 'none' ], true ) )
            {
                return self::invalid_tool_choice_error();
            }
            $normalized['tool_choice'] = $payload['tool_choice'];
        }

        if ( array_key_exists( 'privacy_route_policy', $payload ) )
        {
            $policy = self::normalize_privacy_route_policy( $payload['privacy_route_policy'] );
            if ( is_wp_error( $policy ) )
            {
                return $policy;
            }
            $normalized['privacy_route_policy'] = $policy;
        }

        if (
            'required' === ( $normalized['tool_choice'] ?? null )
            && ( ! isset( $normalized['tools'] ) || [] === $normalized['tools'] )
        )
        {
            return self::invalid_tool_choice_error();
        }

        if ( array_key_exists( 'managed_capability_policy', $payload ) )
        {
            $policy = Sentient_Forms_Managed_Capability_Policy::normalize_envelope(
                $payload['managed_capability_policy'],
                $normalized
            );
            if ( is_wp_error( $policy ) )
            {
                return $policy;
            }
            $normalized['managed_capability_policy'] = $policy;
        }

        return $normalized;
    }

    /**
     * @return array<string, scalar|null>|stdClass|WP_Error
     */
    private static function normalize_metadata( mixed $metadata ): array | stdClass | WP_Error
    {
        if ( ! self::is_json_object( $metadata ) )
        {
            return self::error(
                'sentient_managed_metadata_not_identifier_only',
                __( 'Managed execution metadata must be an object of identifier values.', 'sentient-forms' )
            );
        }

        $values = $metadata instanceof stdClass ? get_object_vars( $metadata ) : $metadata;
        foreach ( $values as $key => $value )
        {
            $key = (string) $key;
            if ( 1 !== preg_match( '/\S/u', $key ) || strlen( $key ) > 64 )
            {
                return self::error(
                    'sentient_managed_invalid_metadata_key',
                    __( 'Managed execution metadata keys must be non-empty identifiers of 64 characters or fewer.', 'sentient-forms' )
                );
            }
            if ( ! is_null( $value ) && ! is_string( $value ) && ! is_bool( $value ) && ! self::is_finite_number( $value ) )
            {
                return new WP_Error(
                    'sentient_managed_metadata_not_identifier_only',
                    __( 'Managed execution metadata must not include nested payloads or local action definitions.', 'sentient-forms' ),
                    [ 'metadata_key' => $key ]
                );
            }
        }

        return $metadata;
    }

    /**
     * @return array{effort:string,exclude:bool}|WP_Error
     */
    private static function normalize_reasoning( mixed $reasoning ): array | WP_Error
    {
        if (
            ! is_array( $reasoning )
            || array_is_list( $reasoning )
            || ! self::has_required_and_optional_keys( $reasoning, [ 'effort' ], [ 'exclude' ] )
            || ! is_string( $reasoning['effort'] ?? null )
            || ! in_array( $reasoning['effort'], [ 'none', 'minimal', 'low', 'medium', 'high', 'xhigh' ], true )
            || ( array_key_exists( 'exclude', $reasoning ) && true !== $reasoning['exclude'] )
        )
        {
            return self::error(
                'sentient_managed_invalid_reasoning',
                __( 'Managed execution reasoning must use a supported effort and exclude hidden reasoning.', 'sentient-forms' )
            );
        }

        return [
            'effort'  => $reasoning['effort'],
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Provider request field, not WP_Query.
            'exclude' => true,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>|WP_Error
     */
    private static function normalize_tools( mixed $tools ): array | WP_Error
    {
        if ( ! is_array( $tools ) || ! array_is_list( $tools ) || count( $tools ) > 4 )
        {
            return self::invalid_tools_error();
        }

        foreach ( $tools as $tool )
        {
            if (
                ! is_array( $tool )
                || array_is_list( $tool )
                || ! is_string( $tool['type'] ?? null )
                || ! in_array( $tool['type'], [ 'openrouter:web_search', 'openrouter:web_fetch', 'openrouter:datetime' ], true )
                || ! self::is_json_value( $tool )
            )
            {
                return self::invalid_tools_error();
            }
        }

        return $tools;
    }

    /**
     * @return array{schema:string,require_zdr:bool,data_collection:string}|WP_Error
     */
    private static function normalize_privacy_route_policy( mixed $policy ): array | WP_Error
    {
        if (
            ! is_array( $policy )
            || array_is_list( $policy )
            || ! self::has_required_and_optional_keys(
                $policy,
                [ 'schema', 'require_zdr', 'data_collection' ],
                []
            )
            || self::PRIVACY_ROUTE_POLICY_SCHEMA !== $policy['schema']
            || true !== $policy['require_zdr']
            || 'deny' !== $policy['data_collection']
        )
        {
            return self::error(
                'sentient_managed_invalid_privacy_route_policy',
                __( 'Managed execution privacy route policy must require ZDR and deny provider data collection.', 'sentient-forms' )
            );
        }

        return $policy;
    }

    private static function is_json_object( mixed $value ): bool
    {
        return $value instanceof stdClass || ( is_array( $value ) && ! array_is_list( $value ) );
    }

    private static function is_json_value( mixed $value ): bool
    {
        if ( null === $value || is_string( $value ) || is_bool( $value ) || is_int( $value ) )
        {
            return true;
        }
        if ( is_float( $value ) )
        {
            return is_finite( $value );
        }
        if ( $value instanceof stdClass )
        {
            $value = get_object_vars( $value );
        }
        if ( ! is_array( $value ) )
        {
            return false;
        }
        foreach ( $value as $item )
        {
            if ( ! self::is_json_value( $item ) )
            {
                return false;
            }
        }
        return true;
    }

    private static function is_finite_number( mixed $value ): bool
    {
        return is_int( $value ) || ( is_float( $value ) && is_finite( $value ) );
    }

    /**
     * @param array<string, mixed> $value
     * @param array<int, string>   $required
     * @param array<int, string>   $optional
     */
    private static function has_required_and_optional_keys( array $value, array $required, array $optional ): bool
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

    private static function invalid_payload_error( string $field ): WP_Error
    {
        return new WP_Error(
            'sentient_managed_invalid_payload',
            sprintf(
                /* translators: %s: request field name. */
                __( 'Managed execution payload is missing or has an invalid %s.', 'sentient-forms' ),
                $field
            ),
            [ 'field' => $field ]
        );
    }

    private static function invalid_tools_error(): WP_Error
    {
        return self::error(
            'sentient_managed_invalid_tools',
            __( 'Managed execution supports at most four valid OpenRouter server tools.', 'sentient-forms' )
        );
    }

    private static function invalid_tool_choice_error(): WP_Error
    {
        return self::error(
            'sentient_managed_invalid_tool_choice',
            __( 'Managed execution tool choice must be auto, required, or none, and required needs a tool.', 'sentient-forms' )
        );
    }

    private static function error( string $code, string $message ): WP_Error
    {
        return new WP_Error( $code, $message );
    }
}
