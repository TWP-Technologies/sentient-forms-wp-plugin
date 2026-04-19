<?php
/**
 * Applies local execution results back to WordPress/form records.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Local_Result_Applier
{
    /**
     * Apply configured local mapping effects.
     *
     * @param array<string, mixed> $mapping          Local form mapping row.
     * @param array<string, mixed> $form             Form metadata.
     * @param array<string, mixed> $entry            Form entry values.
     * @param array<string, mixed> $execution_result Normalized execution result.
     *
     * @return array{applied: array<int, string>, skipped: array<int, array<string, string>>}|WP_Error
     */
    public function apply( array $mapping, array $form, array $entry, array $execution_result ): array | WP_Error
    {
        $effects = is_array( $mapping['effect_mapping_json'] ?? null ) ? $mapping['effect_mapping_json'] : [];
        if ( [] === $effects )
        {
            return [
                'applied' => [],
                'skipped' => [
                    [
                        'effect' => 'all',
                        'reason' => 'no_effect_mapping',
                    ],
                ],
            ];
        }

        if ( 'gravity_forms' !== sanitize_key( (string) ( $mapping['form_source'] ?? 'gravity_forms' ) ) )
        {
            return [
                'applied' => [],
                'skipped' => [
                    [
                        'effect' => 'all',
                        'reason' => 'unsupported_form_source',
                    ],
                ],
            ];
        }

        $entry_id = absint( $entry['id'] ?? 0 );
        if ( 0 === $entry_id )
        {
            return [
                'applied' => [],
                'skipped' => [
                    [
                        'effect' => 'all',
                        'reason' => 'missing_entry_id',
                    ],
                ],
            ];
        }

        $applied = [];
        $skipped = [];
        $result  = is_array( $execution_result['result'] ?? null ) ? $execution_result['result'] : [];

        if ( $this->bool_effect( $effects, [ 'store_result', 'store_result_meta' ] ) )
        {
            if ( function_exists( 'gform_update_meta' ) )
            {
                gform_update_meta( $entry_id, 'sentient_forms_last_response', wp_json_encode( $execution_result ) );
                gform_update_meta( $entry_id, '_sentient_forms_local_result', $result );
                $applied[] = 'store_result';
            }
            else
            {
                $skipped[] = [
                    'effect' => 'store_result',
                    'reason' => 'gform_update_meta_unavailable',
                ];
            }
        }

        $meta_effects = is_array( $effects['meta'] ?? null ) ? $effects['meta'] : [];
        foreach ( $meta_effects as $meta_key => $path_config )
        {
            $meta_key = sanitize_key( (string) $meta_key );
            if ( '' === $meta_key )
            {
                $skipped[] = [
                    'effect' => 'meta',
                    'reason' => 'invalid_meta_key',
                ];
                continue;
            }

            $path  = is_array( $path_config ) ? (string) ( $path_config['path'] ?? '' ) : (string) $path_config;
            $value = $this->resolve_path( $result, $path );
            if ( null === $value )
            {
                $skipped[] = [
                    'effect' => 'meta:' . $meta_key,
                    'reason' => 'path_not_found',
                ];
                continue;
            }

            if ( function_exists( 'gform_update_meta' ) )
            {
                gform_update_meta( $entry_id, $meta_key, $value );
                $applied[] = 'meta:' . $meta_key;
            }
            else
            {
                $skipped[] = [
                    'effect' => 'meta:' . $meta_key,
                    'reason' => 'gform_update_meta_unavailable',
                ];
            }
        }

        if ( array_key_exists( 'entry_note', $effects ) )
        {
            $note_result = $this->apply_entry_note( $entry_id, $effects['entry_note'], $result );
            if ( true === $note_result )
            {
                $applied[] = 'entry_note';
            }
            else
            {
                $skipped[] = [
                    'effect' => 'entry_note',
                    'reason' => $note_result,
                ];
            }
        }

        if ( $this->spam_effect_enabled( $effects ) )
        {
            $spam_result = $this->apply_spam_status( $entry_id, $effects, $result );
            if ( true === $spam_result )
            {
                $applied[] = 'mark_as_spam';
            }
            else
            {
                $skipped[] = [
                    'effect' => 'mark_as_spam',
                    'reason' => $spam_result,
                ];
            }
        }

        return [
            'applied' => $applied,
            'skipped' => $skipped,
        ];
    }

    private function apply_entry_note( int $entry_id, mixed $config, array $result ): true | string
    {
        if ( false === rest_sanitize_boolean( $config ) && ! is_array( $config ) )
        {
            return 'disabled';
        }

        if ( ! class_exists( 'GFFormsModel' ) || ! method_exists( 'GFFormsModel', 'add_note' ) )
        {
            return 'gf_notes_unavailable';
        }

        $path = is_array( $config ) ? (string) ( $config['path'] ?? $config['content_path'] ?? 'content' ) : 'content';
        $body = $this->resolve_path( $result, $path );
        if ( null === $body )
        {
            return 'path_not_found';
        }

        $body = $this->stringify_value( $body );
        if ( '' === trim( $body ) )
        {
            return 'empty_note';
        }

        $prefix = is_array( $config ) && isset( $config['prefix'] )
            ? sanitize_text_field( (string) $config['prefix'] )
            : __( 'Sentient Forms local action result:', 'sentient-forms' );
        $note = sanitize_textarea_field( $prefix . "\n\n" . $body );

        GFFormsModel::add_note( $entry_id, 0, 'Sentient Forms AI', $note, 'sentient_forms_local_action' );

        return true;
    }

    private function apply_spam_status( int $entry_id, array $effects, array $result ): true | string
    {
        $config              = is_array( $effects['spam'] ?? null ) ? $effects['spam'] : [];
        $classification_path = (string) ( $config['classification_path'] ?? 'structured.classification' );
        $confidence_path     = (string) ( $config['confidence_path'] ?? 'structured.confidence' );
        $threshold           = is_numeric( $config['min_confidence'] ?? null ) ? (float) $config['min_confidence'] : 0.8;
        $classification      = strtolower( sanitize_key( (string) $this->resolve_path( $result, $classification_path ) ) );

        if ( '' === $classification )
        {
            $is_spam = $this->resolve_path( $result, 'structured.is_spam' );
            if ( true === $is_spam || 'true' === strtolower( (string) $is_spam ) )
            {
                $classification = 'spam';
            }
        }

        if ( ! in_array( $classification, [ 'spam', 'likely_spam' ], true ) )
        {
            return 'classification_not_spam';
        }

        $confidence = $this->resolve_path( $result, $confidence_path );
        if ( is_numeric( $confidence ) && (float) $confidence < $threshold )
        {
            return 'confidence_below_threshold';
        }

        $filter_result = apply_filters( 'sentient_forms_local_mark_entry_as_spam', null, $entry_id, $result );
        if ( true === $filter_result )
        {
            if ( function_exists( 'gform_update_meta' ) )
            {
                gform_update_meta( $entry_id, 'sentient_forms_spam_classification', $classification );
            }

            return true;
        }

        if ( false === $filter_result )
        {
            return 'spam_mark_rejected_by_filter';
        }

        if ( ! class_exists( 'GFAPI' ) || ! method_exists( 'GFAPI', 'update_entry_property' ) )
        {
            return 'gfapi_unavailable';
        }

        GFAPI::update_entry_property( $entry_id, 'status', 'spam' );

        if ( function_exists( 'gform_update_meta' ) )
        {
            gform_update_meta( $entry_id, 'sentient_forms_spam_classification', $classification );
        }

        return true;
    }

    private function spam_effect_enabled( array $effects ): bool
    {
        if ( isset( $effects['spam'] ) )
        {
            if ( is_array( $effects['spam'] ) )
            {
                return ! array_key_exists( 'enabled', $effects['spam'] ) || rest_sanitize_boolean( $effects['spam']['enabled'] );
            }

            return rest_sanitize_boolean( $effects['spam'] );
        }

        return ! empty( $effects['mark_as_spam'] );
    }

    /**
     * @param array<int, string> $keys
     */
    private function bool_effect( array $effects, array $keys ): bool
    {
        foreach ( $keys as $key )
        {
            if ( array_key_exists( $key, $effects ) && rest_sanitize_boolean( $effects[ $key ] ) )
            {
                return true;
            }
        }

        return false;
    }

    private function resolve_path( array $root, string $path ): mixed
    {
        $path = trim( $path );
        if ( '' === $path )
        {
            return $root;
        }

        $value = $root;
        foreach ( explode( '.', $path ) as $segment )
        {
            if ( is_array( $value ) && array_key_exists( $segment, $value ) )
            {
                $value = $value[ $segment ];
                continue;
            }

            return null;
        }

        return $value;
    }

    private function stringify_value( mixed $value ): string
    {
        if ( null === $value )
        {
            return '';
        }

        if ( is_scalar( $value ) )
        {
            return (string) $value;
        }

        $encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return is_string( $encoded ) ? $encoded : '';
    }
}
