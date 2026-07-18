<?php
/**
 * Provider-neutral execution request identity.
 *
 * @package SentientForms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

final class Sentient_Forms_Execution_Identity
{
    /**
     * @param array<string, mixed> $form Form Source form data.
     * @param array<string, mixed> $entry Normalized submission data.
     * @param array<string, mixed> $context Execution context.
     */
    public static function generate( string $action_code, array $form, array $entry, array $context = [] ): string
    {
        $submission_token = self::derive_submission_token( $form, $entry );

        return self::resolve_execution_request_id( $action_code, $form, $entry, $context, $submission_token );
    }

    private static function resolve_execution_request_id(
        string $action_code,
        array $form,
        array $entry,
        array $context,
        string $submission_token
    ): string
    {
        $generated_request_id = self::build_execution_request_id(
            $action_code,
            $form,
            $entry,
            $context,
            $submission_token
        );
        $execution_request_id = $generated_request_id;

        if ( self::is_local_or_development_environment() )
        {
            $forced = get_option( 'sentient_forms_forced_execution_request_id', '' );
            if ( is_scalar( $forced ) )
            {
                $forced = sanitize_text_field( (string) $forced );
                if ( '' !== $forced )
                {
                    $execution_request_id = $forced;
                }
            }
        }

        $execution_request_id = apply_filters(
            'sentient_forms_execution_request_id',
            $execution_request_id,
            $action_code,
            $form,
            $entry,
            $context
        );

        if ( ! is_string( $execution_request_id ) )
        {
            return $generated_request_id;
        }

        $execution_request_id = sanitize_text_field( trim( $execution_request_id ) );

        return '' === $execution_request_id ? $generated_request_id : $execution_request_id;
    }

    private static function is_local_or_development_environment(): bool
    {
        $environment = function_exists( 'wp_get_environment_type' )
            ? wp_get_environment_type()
            : getenv( 'WP_ENVIRONMENT_TYPE' );
        $environment = strtolower( trim( (string) $environment ) );

        return in_array( $environment, [ 'local', 'development' ], true );
    }

    private static function derive_submission_token( array $form, array $entry ): string
    {
        if ( isset( $entry['submission_uuid'] ) && is_scalar( $entry['submission_uuid'] ) )
        {
            $submission_uuid = strtolower( sanitize_text_field( (string) $entry['submission_uuid'] ) );
            if ( wp_is_uuid( $submission_uuid ) )
            {
                return 'submission:' . $submission_uuid;
            }
        }

        if ( isset( $entry['id'] ) && $entry['id'] )
        {
            return 'entry:' . (string) $entry['id'];
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Gravity Forms owns submission verification; this boundary still validates the token shape.
        if ( isset( $_POST['gform_unique_id'] ) )
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Gravity Forms owns submission verification; this boundary still validates the token shape.
            $unique_id = wp_unslash( $_POST['gform_unique_id'] );
            if ( is_string( $unique_id ) && 1 === preg_match( '/\A[A-Za-z0-9]+\z/', $unique_id ) )
            {
                return 'submission:' . $unique_id;
            }
        }

        $form_id      = isset( $form['id'] ) ? (string) $form['id'] : '';
        $current_user = get_current_user_id();
        $payload_hash = hash(
            'sha256',
            wp_json_encode(
                [
                    'form_id' => $form_id,
                    'entry'   => $entry,
                    'user'    => $current_user,
                ]
            )
        );

        return 'hash:' . $payload_hash;
    }

    private static function build_execution_request_id(
        string $action_code,
        array $form,
        array $entry,
        array $context,
        string $submission_token
    ): string
    {
        $components = [ strtolower( trim( $action_code ) ), $submission_token ];

        if ( isset( $context['hook'] ) )
        {
            $components[] = (string) $context['hook'];
        }
        if ( isset( $context['action_id'] ) )
        {
            $components[] = (string) $context['action_id'];
        }
        if ( isset( $form['id'] ) )
        {
            $components[] = (string) $form['id'];
        }
        if ( ! self::submission_token_has_stable_uuid( $submission_token ) && ! empty( $entry ) )
        {
            $components[] = hash( 'sha256', wp_json_encode( $entry ) );
        }

        return substr( hash( 'sha256', implode( '|', $components ) ), 0, 32 );
    }

    private static function submission_token_has_stable_uuid( string $submission_token ): bool
    {
        $prefix = 'submission:';
        if ( ! str_starts_with( $submission_token, $prefix ) )
        {
            return false;
        }

        return wp_is_uuid( substr( $submission_token, strlen( $prefix ) ) );
    }
}
