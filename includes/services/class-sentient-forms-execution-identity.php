<?php
/** Provider-neutral execution request identity. */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

final class Sentient_Forms_Execution_Identity
{
    /**
     * @param array<string,mixed> $form
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $context
     */
    public static function generate( string $action_code, array $form, array $entry, array $context = [] ): string
    {
        $submission_token = self::derive_submission_token( $form, $entry );
        return self::resolve_execution_request_id( $action_code, $form, $entry, $context, $submission_token );
    }

    /**
     * Resolve safe provider identity without treating an unknown run as Direct OpenRouter.
     *
     * @param array<string, mixed>      $payload              Local mapping payload.
     * @param array<string, mixed>      $context              Normalized execution context.
     * @param array<string, mixed>|null $result               Execution result when available.
     * @param string                    $execution_request_id Stable execution request id.
     *
     * @return array{provider: string, model: string|null}
     */
    public static function resolve_provider_identity(
        array $payload,
        array $context = [],
        ?array $result = null,
        string $execution_request_id = ''
    ): array
    {
        $result_provider = self::normalize_provider( $result['provider'] ?? null );
        if ( null !== $result_provider )
        {
            return [
                'provider' => $result_provider,
                'model'    => self::normalize_model( $result['model'] ?? null ),
            ];
        }

        if ( '' !== $execution_request_id && class_exists( 'Sentient_Forms_Execution_Events_Repository' ) )
        {
            global $wpdb;
            $existing = ( new Sentient_Forms_Execution_Events_Repository( $wpdb ) )->get_by_request_id( $execution_request_id );
            $existing_provider = is_array( $existing )
                ? self::normalize_provider( $existing['provider'] ?? null )
                : null;
            if ( null !== $existing_provider )
            {
                return [
                    'provider' => $existing_provider,
                    'model'    => self::normalize_model( $existing['model'] ?? null ),
                ];
            }
        }

        $context_provider = self::normalize_provider( $context['provider'] ?? null );
        if ( null !== $context_provider )
        {
            return [
                'provider' => $context_provider,
                'model'    => self::normalize_model( $context['model'] ?? null ),
            ];
        }

        $settings        = isset( $context['settings'] ) && is_array( $context['settings'] ) ? $context['settings'] : [];
        $model_selection = isset( $settings['model_selection'] ) && is_array( $settings['model_selection'] )
            ? $settings['model_selection']
            : [];
        $settings_provider = self::normalize_provider( $model_selection['provider'] ?? null );
        if ( null !== $settings_provider )
        {
            return [
                'provider' => $settings_provider,
                'model'    => self::normalize_model( $model_selection['model'] ?? $model_selection['primary'] ?? null ),
            ];
        }

        $mapping_id = absint( $payload['local_mapping_id'] ?? $context['local_form_mapping_id'] ?? 0 );
        if (
            $mapping_id > 0
            && class_exists( 'Sentient_Forms_Form_Mappings_Repository' )
            && class_exists( 'Sentient_Forms_Local_Custom_Actions_Repository' )
        )
        {
            global $wpdb;
            $mapping = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->get( $mapping_id );
            if ( is_array( $mapping ) && 'custom_action' === ( $mapping['action_kind'] ?? null ) )
            {
                $action = ( new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb ) )->get( absint( $mapping['action_id'] ?? 0 ) );
                $selection = is_array( $action['model_selection_json'] ?? null ) ? $action['model_selection_json'] : [];
                $persisted_provider = self::normalize_provider( $selection['provider'] ?? null );
                if ( null !== $persisted_provider )
                {
                    return [
                        'provider' => $persisted_provider,
                        'model'    => self::normalize_model( $selection['model'] ?? $selection['primary'] ?? null ),
                    ];
                }
            }
        }

        return [
            'provider' => 'unclassified',
            'model'    => null,
        ];
    }

    private static function resolve_execution_request_id( string $action_code, array $form, array $entry, array $context, string $submission_token ): string
    {
        $generated_request_id = self::build_execution_request_id( $action_code, $form, $entry, $context, $submission_token );
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

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Form Source verification runs before identity generation.
        if ( isset( $_POST['gform_unique_id'] ) )
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Form Source verification runs before identity generation.
            $unique_id = sanitize_text_field( wp_unslash( (string) $_POST['gform_unique_id'] ) );
            if ( ! empty( $unique_id ) )
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

    private static function build_execution_request_id( string $action_code, array $form, array $entry, array $context, string $submission_token ): string
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
        if ( ! empty( $entry ) )
        {
            $components[] = hash( 'sha256', wp_json_encode( $entry ) );
        }

        return substr( hash( 'sha256', implode( '|', $components ) ), 0, 32 );
    }

    private static function normalize_provider( mixed $provider ): ?string
    {
        if ( ! is_scalar( $provider ) )
        {
            return null;
        }

        $provider = sanitize_key( (string) $provider );

        return in_array( $provider, [ 'openrouter', 'sentient_managed' ], true ) ? $provider : null;
    }

    private static function normalize_model( mixed $model ): ?string
    {
        if ( ! is_scalar( $model ) )
        {
            return null;
        }

        $model = sanitize_text_field( (string) $model );

        return '' !== $model ? $model : null;
    }
}
