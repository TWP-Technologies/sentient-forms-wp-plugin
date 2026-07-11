<?php
/**
 * Public-safe managed infrastructure capability policy shared by routing and transport.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

final class Sentient_Forms_Managed_Capability_Policy
{
    public const SCHEMA = 'sentient_forms_managed_capability_policy.v1';

    private const ALLOWED_CAPABILITIES = [
        'server_tools',
        'web_search',
        'privacy_zdr',
        'bounded_output',
    ];

    /**
     * @return array<int, string>
     */
    public static function allowed_capabilities(): array
    {
        return self::ALLOWED_CAPABILITIES;
    }

    /**
     * @return array<int, string>|WP_Error
     */
    public static function normalize_required_capabilities( mixed $required ): array | WP_Error
    {
        if ( ! is_array( $required ) || ! array_is_list( $required ) || count( $required ) > 4 )
        {
            return self::invalid_policy_error();
        }

        $normalized = [];
        foreach ( $required as $capability )
        {
            if ( ! is_string( $capability ) || $capability !== trim( $capability ) )
            {
                return self::invalid_policy_error();
            }
            if ( ! in_array( $capability, self::ALLOWED_CAPABILITIES, true ) )
            {
                return new WP_Error(
                    'sentient_managed_unknown_capability',
                    __( 'Managed execution requires an unsupported infrastructure capability.', 'sentient-forms' ),
                    [ 'status' => 422 ]
                );
            }
            if ( in_array( $capability, $normalized, true ) )
            {
                return self::invalid_policy_error();
            }

            $normalized[] = $capability;
        }

        return $normalized;
    }

    /**
     * Build the strict CPS envelope only for non-empty effective requirements.
     *
     * @param array<string, mixed> $managed_request
     * @return array{schema:string,required_capabilities:array<int,string>}|null|WP_Error
     */
    public static function build_for_request( mixed $required, array $managed_request ): array | null | WP_Error
    {
        $required = self::normalize_required_capabilities( $required );
        if ( is_wp_error( $required ) || [] === $required )
        {
            return is_wp_error( $required ) ? $required : null;
        }

        foreach ( $required as $capability )
        {
            if ( ! self::request_satisfies( $managed_request, $capability ) )
            {
                return new WP_Error(
                    'sentient_managed_unsatisfied_capability',
                    __( 'The managed request does not enable a required infrastructure capability.', 'sentient-forms' ),
                    [
                        'status'     => 422,
                        'capability' => $capability,
                    ]
                );
            }
        }

        return [
            'schema'                => self::SCHEMA,
            'required_capabilities' => $required,
        ];
    }

    /**
     * Validate a caller-supplied CPS envelope against the actual managed request.
     *
     * @param array<string, mixed> $managed_request
     * @return array{schema:string,required_capabilities:array<int,string>}|null|WP_Error
     */
    public static function normalize_envelope( mixed $policy, array $managed_request ): array | null | WP_Error
    {
        $keys = is_array( $policy ) ? array_keys( $policy ) : [];
        sort( $keys, SORT_STRING );
        if (
            ! is_array( $policy )
            || array_is_list( $policy )
            || [ 'required_capabilities', 'schema' ] !== $keys
            || self::SCHEMA !== ( $policy['schema'] ?? null )
        )
        {
            return self::invalid_policy_error();
        }

        $normalized = self::build_for_request( $policy['required_capabilities'] ?? null, $managed_request );
        if ( is_wp_error( $normalized ) )
        {
            return $normalized;
        }
        if ( null === $normalized )
        {
            return null;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $request
     */
    private static function request_satisfies( array $request, string $capability ): bool
    {
        $tools = is_array( $request['tools'] ?? null ) ? $request['tools'] : [];
        $server_tools_enabled = [] !== $tools
            && 'none' !== ( $request['tool_choice'] ?? null );

        return match ( $capability )
        {
            'server_tools'  => $server_tools_enabled,
            'web_search'    => $server_tools_enabled && self::contains_web_search_tool( $tools ),
            'privacy_zdr'   => isset( $request['privacy_route_policy'] ),
            'bounded_output' => isset( $request['max_output_tokens'] ),
            default         => false,
        };
    }

    /**
     * @param array<int, mixed> $tools
     */
    private static function contains_web_search_tool( array $tools ): bool
    {
        foreach ( $tools as $tool )
        {
            if ( is_array( $tool ) && 'openrouter:web_search' === ( $tool['type'] ?? null ) )
            {
                return true;
            }
        }

        return false;
    }

    private static function invalid_policy_error(): WP_Error
    {
        return new WP_Error(
            'sentient_managed_invalid_capability_policy',
            __( 'Managed execution capability policy is invalid.', 'sentient-forms' ),
            [ 'status' => 422 ]
        );
    }
}
