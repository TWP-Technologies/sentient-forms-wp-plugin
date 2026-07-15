<?php
/**
 * Source-neutral persisted Action runtime-setting resolver.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

final class Sentient_Forms_Action_Runtime_Settings_Resolver
{
    private const ACTION_DEFAULTS_OPTION_PREFIX = 'sentient_forms_action_defaults_';
    private const FORM_ACTION_CONFIG_OPTION_PREFIX = 'sentient_forms_form_config_';

    /**
     * Apply Action defaults and Form Source/form config beneath mapping overrides.
     *
     * @param array<string, mixed> $mapping
     *
     * @return array<string, mixed>
     */
    public function resolve_mapping( array $mapping, string $form_source, mixed $form_id ): array
    {
        $action_id = isset( $mapping['central_action_id'] ) && is_scalar( $mapping['central_action_id'] )
            ? sanitize_key( (string) $mapping['central_action_id'] )
            : '';
        if ( '' === $action_id )
        {
            return $mapping;
        }

        $resolved         = $mapping;
        $mapping_settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) ? $mapping['settings'] : [];
        $action_defaults  = $this->action_defaults( $action_id );
        $form_config      = $this->form_config( $form_source, $form_id, $action_id );

        foreach ( [ 'model_selection', 'include_site_context', 'action_customization' ] as $field )
        {
            $mapping_settings = $this->merge_inherited_field( $mapping_settings, $field, $form_config, $action_defaults );
        }

        if ( in_array( $action_id, [ 'spam_detection_v1', 'spam_analysis' ], true ) )
        {
            foreach ( [ 'spam_positive_examples', 'spam_negative_examples', 'spam_result_display_mode', 'spam_indicators_display' ] as $field )
            {
                $mapping_settings = $this->merge_inherited_field( $mapping_settings, $field, $form_config, $action_defaults );
            }

            foreach ( [ 'suppress_notifications_on_spam', 'suppress_webhooks_on_spam', 'skip_downstream_on_spam' ] as $field )
            {
                $mapping_settings = $this->merge_inherited_boolean_field( $mapping_settings, $field, $form_config, $action_defaults );
            }
        }

        $resolved['settings'] = $mapping_settings;

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private function action_defaults( string $action_id ): array
    {
        return $this->normalize_config(
            get_option( self::ACTION_DEFAULTS_OPTION_PREFIX . sanitize_key( $action_id ), [] )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function form_config( string $form_source, mixed $form_id, string $action_id ): array
    {
        $form_source = sanitize_key( $form_source );
        $suffix      = Sentient_Forms_Provider_Form_Id_Keys::option_suffix( $form_id );
        $configs     = get_option( self::FORM_ACTION_CONFIG_OPTION_PREFIX . $form_source . '_' . $suffix, null );
        if ( null === $configs )
        {
            foreach ( Sentient_Forms_Provider_Form_Id_Keys::legacy_option_suffixes( $form_source, $form_id ) as $legacy_suffix )
            {
                $configs = get_option( self::FORM_ACTION_CONFIG_OPTION_PREFIX . $form_source . '_' . $legacy_suffix, null );
                if ( null !== $configs )
                {
                    break;
                }
            }
        }

        return is_array( $configs )
            ? $this->normalize_config( $configs[ $action_id ] ?? [] )
            : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize_config( mixed $config ): array
    {
        if ( ! is_array( $config ) )
        {
            return [];
        }

        if ( empty( $config['model_selection'] ) && ! empty( $config['model_override'] ) && is_string( $config['model_override'] ) )
        {
            $config['model_selection'] = [
                'primary'   => sanitize_text_field( $config['model_override'] ),
                'backup'    => null,
                'is_preset' => str_starts_with( $config['model_override'], 'sf_' ),
            ];
        }

        foreach ( [ 'suppress_notifications_on_spam', 'suppress_webhooks_on_spam', 'skip_downstream_on_spam' ] as $field )
        {
            if ( array_key_exists( $field, $config ) )
            {
                $config[ $field ] = rest_sanitize_boolean( $config[ $field ] );
            }
        }

        if ( array_key_exists( 'spam_result_display_mode', $config ) )
        {
            $mode = sanitize_key( (string) $config['spam_result_display_mode'] );
            $config['spam_result_display_mode'] = in_array( $mode, [ 'none', 'spam_only', 'all_results' ], true )
                ? $mode
                : 'all_results';
        }

        if ( array_key_exists( 'spam_indicators_display', $config ) )
        {
            $config['spam_indicators_display'] = 'detailed' === sanitize_key( (string) $config['spam_indicators_display'] )
                ? 'detailed'
                : 'simple';
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $resolved
     * @param array<string, mixed> $form_config
     * @param array<string, mixed> $action_defaults
     *
     * @return array<string, mixed>
     */
    private function merge_inherited_field( array $resolved, string $field, array $form_config, array $action_defaults ): array
    {
        if ( $this->has_inherited_value( $resolved, $field ) )
        {
            return $resolved;
        }

        if ( $this->has_inherited_value( $form_config, $field ) )
        {
            $resolved[ $field ] = $form_config[ $field ];
        }
        elseif ( $this->has_inherited_value( $action_defaults, $field ) )
        {
            $resolved[ $field ] = $action_defaults[ $field ];
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $resolved
     * @param array<string, mixed> $form_config
     * @param array<string, mixed> $action_defaults
     *
     * @return array<string, mixed>
     */
    private function merge_inherited_boolean_field( array $resolved, string $field, array $form_config, array $action_defaults ): array
    {
        if ( array_key_exists( $field, $resolved ) )
        {
            $resolved[ $field ] = rest_sanitize_boolean( $resolved[ $field ] );
        }
        elseif ( array_key_exists( $field, $form_config ) )
        {
            $resolved[ $field ] = rest_sanitize_boolean( $form_config[ $field ] );
        }
        elseif ( array_key_exists( $field, $action_defaults ) )
        {
            $resolved[ $field ] = rest_sanitize_boolean( $action_defaults[ $field ] );
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function has_inherited_value( array $values, string $field ): bool
    {
        return array_key_exists( $field, $values )
            && null !== $values[ $field ]
            && '' !== $values[ $field ];
    }
}
