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
        $submission_token = self::submission_token( $form, $entry, $context );

        return self::resolve_execution_request_id( $action_code, $form, $entry, $context, $submission_token );
    }

    /**
     * Resolve a provider-neutral token for one native submission.
     *
     * Form Source adapters may supply a source-specific correlation value through
     * the normalized native_submission_token context key. This service never
     * reads provider globals directly.
     *
     * @param array<string, mixed> $form    Normalized form data.
     * @param array<string, mixed> $entry   Normalized entry data.
     * @param array<string, mixed> $context Execution context.
     */
    public static function submission_token( array $form, array $entry, array $context = [] ): string
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

        if ( isset( $context['native_submission_token'] ) && is_scalar( $context['native_submission_token'] ) )
        {
            $native_submission_token = sanitize_text_field( (string) $context['native_submission_token'] );
            if ( 1 === preg_match( '/\A[A-Za-z0-9]{1,128}\z/', $native_submission_token ) )
            {
                return 'submission:' . $native_submission_token;
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
