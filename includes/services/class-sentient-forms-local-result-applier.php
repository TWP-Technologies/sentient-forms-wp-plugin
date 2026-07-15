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
     * @param array<string, mixed> $action           Local custom action row.
     *
     * @return array{applied: array<int, string>, skipped: array<int, array<string, string>>, failed: array<int, array<string, string>>, unsupported: array<int, array<string, string>>}|WP_Error
     */
    public function apply( array $mapping, array $form, array $entry, array $execution_result, array $action = [] ): array | WP_Error
    {
        $effects                = is_array( $mapping['effect_mapping_json'] ?? null ) ? $mapping['effect_mapping_json'] : [];
        $post_execution_actions = $this->get_configured_post_execution_actions( $effects, $action );
        if ( [] === $effects && [] === $post_execution_actions )
        {
            return [
                'applied' => [],
                'skipped' => [
                    [
                        'effect' => 'all',
                        'reason' => 'no_effect_mapping',
                    ],
                ],
                'failed'      => [],
                'unsupported' => [],
            ];
        }

        $form_source      = $this->normalize_form_source( (string) ( $mapping['form_source'] ?? 'gravity_forms' ) );
        $is_gravity_forms = $this->is_gravity_forms_source( $form_source );
        $entry_id         = absint( $entry['id'] ?? 0 );
        $applied          = [];
        $skipped          = [];
        $failed           = [];
        $unsupported      = [];
        $result           = is_array( $execution_result['result'] ?? null ) ? $execution_result['result'] : [];

        if ( $is_gravity_forms && $entry_id > 0 && function_exists( 'gform_update_meta' ) )
        {
            gform_update_meta( $entry_id, 'sentient_forms_last_error', '' );
            gform_update_meta( $entry_id, 'sentient_forms_last_processed_at', current_time( 'mysql' ) );
        }

        if ( $this->bool_effect( $effects, [ 'store_result', 'store_result_meta' ] ) )
        {
            if ( ! $is_gravity_forms )
            {
                $applied[] = 'store_result';
            }
            elseif ( $entry_id <= 0 )
            {
                $skipped[] = [
                    'effect' => 'store_result',
                    'reason' => 'missing_entry_id',
                ];
            }
            elseif ( function_exists( 'gform_update_meta' ) )
            {
                $stored_execution_result = Sentient_Forms_Local_Data_Governance::sanitize_execution_payload_for_storage( $execution_result );
                $stored_result           = Sentient_Forms_Local_Data_Governance::sanitize_execution_result_for_storage( $result );
                gform_update_meta( $entry_id, 'sentient_forms_last_response', wp_json_encode( $stored_execution_result ) );
                gform_update_meta( $entry_id, '_sentient_forms_local_result', $stored_result );
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
            $value = $this->resolve_effect_value( $result, $path );
            if ( null === $value )
            {
                $skipped[] = [
                    'effect' => 'meta:' . $meta_key,
                    'reason' => 'path_not_found',
                ];
                continue;
            }

            if ( ! $is_gravity_forms )
            {
                $unsupported[] = [
                    'effect' => 'meta:' . $meta_key,
                    'reason' => 'native_meta_unsupported',
                ];
            }
            elseif ( $entry_id <= 0 )
            {
                $skipped[] = [
                    'effect' => 'meta:' . $meta_key,
                    'reason' => 'missing_entry_id',
                ];
            }
            elseif ( function_exists( 'gform_update_meta' ) )
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
            if ( ! $is_gravity_forms )
            {
                $unsupported[] = [
                    'effect' => 'entry_note',
                    'reason' => 'native_note_unsupported',
                ];
            }
            elseif ( $entry_id <= 0 )
            {
                $skipped[] = [
                    'effect' => 'entry_note',
                    'reason' => 'missing_entry_id',
                ];
            }
            else
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
        }

        if ( $this->spam_effect_enabled( $effects ) )
        {
            if ( ! $is_gravity_forms )
            {
                $classification = $this->extract_configured_spam_classification( $effects, $result );
                if ( '' === $classification )
                {
                    $skipped[] = [
                        'effect' => 'spam_classification',
                        'reason' => 'classification_not_found',
                    ];
                }
                else
                {
                    $applied[] = 'spam_classification';
                }

                $spam_status_reason = $this->non_native_spam_status_skip_reason( $effects, $result );
                if ( str_ends_with( $spam_status_reason, '_unsupported' ) )
                {
                    $unsupported[] = [
                        'effect' => 'mark_as_spam',
                        'reason' => $spam_status_reason,
                    ];
                }
                else
                {
                    $skipped[] = [
                        'effect' => 'mark_as_spam',
                        'reason' => $spam_status_reason,
                    ];
                }

                if ( $this->non_native_spam_note_requested( $effects, $result ) )
                {
                    $unsupported[] = [
                        'effect' => 'spam_note',
                        'reason' => 'native_note_unsupported',
                    ];
                }
            }
            elseif ( $entry_id <= 0 )
            {
                if ( $this->non_native_spam_note_requested( $effects, $result ) )
                {
                    $skipped[] = [
                        'effect' => 'spam_note',
                        'reason' => 'missing_entry_id',
                    ];
                }

                $skipped[] = [
                    'effect' => 'mark_as_spam',
                    'reason' => 'missing_entry_id',
                ];
            }
            else
            {
                $spam_note_result = $this->apply_spam_note( $entry_id, $effects, $result );
                if ( true === $spam_note_result )
                {
                    $applied[] = 'spam_note';
                }
                elseif ( 'not_configured' !== $spam_note_result && 'disabled' !== $spam_note_result && 'classification_hidden' !== $spam_note_result )
                {
                    $skipped[] = [
                        'effect' => 'spam_note',
                        'reason' => $spam_note_result,
                    ];
                }

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
        }

        if ( [] !== $post_execution_actions )
        {
            $post_execution_results = $this->run_post_execution_actions(
                $entry_id,
                $mapping,
                $form,
                $entry,
                $execution_result,
                $action,
                $post_execution_actions
            );
            if ( $is_gravity_forms && $entry_id > 0 )
            {
                $this->record_post_execution_action_results( $entry_id, $post_execution_results );
            }

            foreach ( $post_execution_results as $post_execution_result )
            {
                $effect_name = 'post_execution:' . sanitize_key( (string) ( $post_execution_result['type'] ?? 'unknown' ) );
                if ( 'success' === (string) ( $post_execution_result['status'] ?? '' ) )
                {
                    $applied[] = $effect_name;
                    continue;
                }

                $status = sanitize_key( (string) ( $post_execution_result['status'] ?? 'failed' ) );
                if ( str_ends_with( $status, '_unsupported' ) )
                {
                    $unsupported[] = [
                        'effect' => $effect_name,
                        'reason' => $status,
                    ];
                }
                elseif ( str_starts_with( $status, 'skipped' ) || 'missing_entry_id' === $status )
                {
                    $skipped[] = [
                        'effect' => $effect_name,
                        'reason' => $status,
                    ];
                }
                else
                {
                    $failed[] = [
                        'effect' => $effect_name,
                        'reason' => $status,
                    ];
                }
            }
        }

        return [
            'applied' => $applied,
            'skipped' => $skipped,
            'failed'      => $failed,
            'unsupported' => $unsupported,
        ];
    }

    private function normalize_form_source( string $form_source ): string
    {
        return match ( sanitize_key( $form_source ) ) {
            'gravity-forms' => 'gravity_forms',
            ''              => 'unknown',
            default         => sanitize_key( $form_source ),
        };
    }

    private function is_gravity_forms_source( string $form_source ): bool
    {
        return 'gravity_forms' === $this->normalize_form_source( $form_source );
    }

    private function non_native_spam_status_skip_reason( array $effects, array $result ): string
    {
        $config          = is_array( $effects['spam'] ?? null ) ? $effects['spam'] : [];
        $confidence_path = (string) ( $config['confidence_path'] ?? 'structured.confidence' );
        $threshold       = is_numeric( $config['min_confidence'] ?? null ) ? (float) $config['min_confidence'] : 0.8;
        $classification  = $this->extract_configured_spam_classification( $effects, $result );

        if ( '' === $classification )
        {
            return 'classification_not_found';
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

        return 'native_spam_status_unsupported';
    }

    private function non_native_spam_note_requested( array $effects, array $result ): bool
    {
        $config = is_array( $effects['spam'] ?? null ) ? $effects['spam'] : [];
        $note   = is_array( $config['note'] ?? null ) ? $config['note'] : null;
        if ( ! is_array( $note ) )
        {
            return false;
        }

        $display_mode = $this->normalize_spam_result_display_mode( $note['result_display_mode'] ?? 'all_results' );
        if ( 'none' === $display_mode )
        {
            return false;
        }

        $classification = $this->extract_configured_spam_classification( $effects, $result );
        if ( '' === $classification )
        {
            return true;
        }

        return in_array( $classification, [ 'spam', 'likely_spam' ], true ) || 'all_results' === $display_mode;
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
        $body = $this->resolve_effect_value( $result, $path );
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

    /**
     * Resolve an effect path and prefer the useful scalar inside JSON model output
     * when a text-oriented built-in action receives structured content.
     *
     * @param array<string, mixed> $result Normalized provider result.
     */
    private function resolve_effect_value( array $result, string $path ): mixed
    {
        $value = $this->resolve_path( $result, $path );
        if ( null === $value || 'content' !== trim( $path ) )
        {
            return $value;
        }

        $structured_summary = $this->resolve_path( $result, 'structured.summary' );
        if ( is_scalar( $structured_summary ) && '' !== trim( (string) $structured_summary ) )
        {
            return $structured_summary;
        }

        if ( ! is_scalar( $value ) )
        {
            return $value;
        }

        $decoded = json_decode( (string) $value, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) )
        {
            return $value;
        }

        foreach ( [ 'summary', 'message', 'justification', 'reasoning' ] as $summary_key )
        {
            if ( isset( $decoded[ $summary_key ] ) && is_scalar( $decoded[ $summary_key ] ) && '' !== trim( (string) $decoded[ $summary_key ] ) )
            {
                return $decoded[ $summary_key ];
            }
        }

        return $value;
    }

    private function apply_spam_note( int $entry_id, array $effects, array $result ): true | string
    {
        $config = is_array( $effects['spam'] ?? null ) ? $effects['spam'] : [];
        $note   = is_array( $config['note'] ?? null ) ? $config['note'] : null;
        if ( ! is_array( $note ) )
        {
            return 'not_configured';
        }

        if ( ! class_exists( 'GFFormsModel' ) || ! method_exists( 'GFFormsModel', 'add_note' ) )
        {
            return 'gf_notes_unavailable';
        }

        $display_mode = $this->normalize_spam_result_display_mode( $note['result_display_mode'] ?? 'all_results' );
        if ( 'none' === $display_mode )
        {
            return 'disabled';
        }

        $classification = $this->extract_configured_spam_classification( $effects, $result );
        if ( '' === $classification )
        {
            return 'classification_not_found';
        }

        if ( ! in_array( $classification, [ 'spam', 'likely_spam' ], true ) && 'all_results' !== $display_mode )
        {
            return 'classification_hidden';
        }

        $note_body = $this->format_spam_note( $result, $classification, $note['indicators_display'] ?? 'simple' );
        if ( '' === trim( $note_body ) )
        {
            return 'empty_note';
        }

        if ( $this->entry_note_exists( $entry_id, 'Sentient Forms AI', $note_body ) )
        {
            return true;
        }

        GFFormsModel::add_note( $entry_id, 0, 'Sentient Forms AI', sanitize_textarea_field( $note_body ), 'sentient_forms_local_action' );
        return true;
    }

    private function apply_spam_status( int $entry_id, array $effects, array $result ): true | string
    {
        $config          = is_array( $effects['spam'] ?? null ) ? $effects['spam'] : [];
        $confidence_path = (string) ( $config['confidence_path'] ?? 'structured.confidence' );
        $threshold       = is_numeric( $config['min_confidence'] ?? null ) ? (float) $config['min_confidence'] : 0.8;
        $classification  = $this->extract_configured_spam_classification( $effects, $result );

        if ( ! in_array( $classification, [ 'spam', 'likely_spam' ], true ) )
        {
            if ( function_exists( 'gform_update_meta' ) && '' !== $classification )
            {
                gform_update_meta( $entry_id, 'sentient_forms_spam_classification', $classification );
            }
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

    private function normalize_spam_result_display_mode( mixed $value ): string
    {
        $value = sanitize_key( (string) $value );

        return match ( $value ) {
            'none',
            'spam_only',
            'all_results' => $value,
            default      => 'all_results',
        };
    }

    private function normalize_spam_indicators_display( mixed $value ): string
    {
        return 'detailed' === sanitize_key( (string) $value ) ? 'detailed' : 'simple';
    }

    private function extract_configured_spam_classification( array $effects, array $result ): string
    {
        $config              = is_array( $effects['spam'] ?? null ) ? $effects['spam'] : [];
        $classification_path = (string) ( $config['classification_path'] ?? 'structured.classification' );
        $classification      = $this->normalize_spam_classification_value( $this->resolve_path( $result, $classification_path ) );

        return '' !== $classification ? $classification : $this->extract_spam_classification( $result );
    }

    private function extract_spam_classification( array $result ): string
    {
        $classification = $this->normalize_spam_classification_value(
            $this->resolve_path( $result, 'structured.classification' )
            ?? $this->resolve_path( $result, 'classification' )
            ?? ''
        );

        if ( '' !== $classification )
        {
            return $classification;
        }

        $is_spam = $this->resolve_path( $result, 'structured.is_spam' );
        if ( true === $is_spam || 'true' === strtolower( (string) $is_spam ) )
        {
            return 'spam';
        }

        return '';
    }

    private function normalize_spam_classification_value( mixed $value ): string
    {
        if ( ! is_scalar( $value ) )
        {
            return '';
        }

        $normalized = preg_replace( '/[\s-]+/', '_', strtolower( trim( (string) $value ) ) );

        return sanitize_key( (string) ( $normalized ?? '' ) );
    }

    private function extract_spam_confidence( array $result ): ?float
    {
        $confidence = $this->resolve_path( $result, 'structured.confidence' );
        if ( is_numeric( $confidence ) )
        {
            return (float) $confidence;
        }

        $confidence = $this->resolve_path( $result, 'confidence' );
        return is_numeric( $confidence ) ? (float) $confidence : null;
    }

    private function extract_spam_justification( array $result ): string
    {
        $justification = $this->resolve_path( $result, 'structured.justification' );
        if ( is_scalar( $justification ) )
        {
            return sanitize_textarea_field( (string) $justification );
        }

        $justification = $this->resolve_path( $result, 'justification' );
        return is_scalar( $justification ) ? sanitize_textarea_field( (string) $justification ) : '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extract_spam_indicators( array $result ): array
    {
        $indicators = $this->resolve_path( $result, 'structured.indicators' );
        if ( is_array( $indicators ) )
        {
            return $indicators;
        }

        $indicators = $this->resolve_path( $result, 'indicators' );
        return is_array( $indicators ) ? $indicators : [];
    }

    private function format_spam_note( array $result, string $classification, mixed $indicators_display ): string
    {
        $confidence     = $this->extract_spam_confidence( $result );
        $justification  = $this->extract_spam_justification( $result );
        $indicators     = $this->extract_spam_indicators( $result );
        $display_mode   = $this->normalize_spam_indicators_display( $indicators_display );
        $confidence_pct = null !== $confidence ? round( $confidence * 100 ) . '%' : 'N/A';
        $is_spam        = in_array( $classification, [ 'spam', 'likely_spam' ], true );
        $status_label   = $is_spam ? __( 'SPAM', 'sentient-forms' ) : __( 'HAM', 'sentient-forms' );
        $icon           = $is_spam ? '🚫' : '✅';

        $note = sprintf(
            /* translators: 1: icon, 2: classification label, 3: confidence percentage */
            __( '%1$s Sentient Forms AI classified this entry as %2$s (%3$s confidence)', 'sentient-forms' ),
            $icon,
            $status_label,
            $confidence_pct,
        );

        if ( '' !== $justification )
        {
            $note .= "\n\n" . $justification;
        }

        if ( 'detailed' === $display_mode && [] !== $indicators )
        {
            $note .= "\n\n" . __( 'Signals Detected:', 'sentient-forms' );
            foreach ( $indicators as $indicator )
            {
                if ( ! is_array( $indicator ) )
                {
                    continue;
                }

                $type     = sanitize_text_field( (string) ( $indicator['type'] ?? __( 'Signal', 'sentient-forms' ) ) );
                $evidence = sanitize_text_field( (string) ( $indicator['evidence'] ?? '' ) );
                $weight   = sanitize_text_field( (string) ( $indicator['weight'] ?? '' ) );
                $note    .= sprintf( "\n- %s%s%s",
                    $type,
                    '' !== $weight ? ' (' . $weight . ')' : '',
                    '' !== $evidence ? ': ' . $evidence : ''
                );
            }
        }

        return $note;
    }

    private function entry_note_exists( int $entry_id, string $note_author, string $note_content ): bool
    {
        $notes = [];

        if ( class_exists( 'GFFormsModel' ) && is_callable( [ 'GFFormsModel', 'get_lead_notes' ] ) )
        {
            try
            {
                $notes = GFFormsModel::get_lead_notes( $entry_id );
            } catch ( Throwable $throwable )
            {
                $notes = [];
            }
        }

        if ( ! is_array( $notes ) )
        {
            return false;
        }

        foreach ( $notes as $note )
        {
            if ( is_array( $note ) )
            {
                $stored_author = (string) ( $note['user_name'] ?? $note['note_author'] ?? $note['author'] ?? '' );
                $stored_value  = (string) ( $note['value'] ?? $note['note'] ?? $note['content'] ?? '' );
            }
            elseif ( is_object( $note ) )
            {
                $stored_author = (string) ( $note->user_name ?? $note->note_author ?? $note->author ?? '' );
                $stored_value  = (string) ( $note->value ?? $note->note ?? $note->content ?? '' );
            }
            else
            {
                continue;
            }

            if ( $stored_author === $note_author && $stored_value === $note_content )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $effects Local effect mapping JSON.
     * @param array<string, mixed> $action  Local custom action row.
     *
     * @return array<int, array<string, mixed>>
     */
    private function get_configured_post_execution_actions( array $effects, array $action ): array
    {
        $definition = isset( $action['definition_json'] ) && is_array( $action['definition_json'] )
            ? $action['definition_json']
            : [];
        $defaults = isset( $definition['execution_defaults'] ) && is_array( $definition['execution_defaults'] )
            ? $definition['execution_defaults']
            : [];

        $candidates = [
            $effects['post_execution_actions'] ?? null,
            $effects['custom_effects'] ?? null,
            $effects['effects'] ?? null,
            $defaults['post_execution_actions'] ?? null,
        ];

        foreach ( $candidates as $candidate )
        {
            $actions = $this->normalize_post_execution_actions( $candidate );
            if ( [] !== $actions )
            {
                return $actions;
            }
        }

        return [];
    }

    /**
     * @param mixed $candidate Effect action config or list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalize_post_execution_actions( mixed $candidate ): array
    {
        if ( ! is_array( $candidate ) || [] === $candidate )
        {
            return [];
        }

        if ( isset( $candidate['type'] ) || isset( $candidate['kind'] ) )
        {
            $candidate = [ $candidate ];
        }

        $actions = [];
        foreach ( $candidate as $action )
        {
            if ( ! is_array( $action ) )
            {
                continue;
            }

            if ( isset( $action['enabled'] ) && false === rest_sanitize_boolean( $action['enabled'] ) )
            {
                continue;
            }

            if ( ! isset( $action['type'] ) && isset( $action['kind'] ) )
            {
                $action['type'] = $action['kind'];
            }

            if ( isset( $action['type'] ) && is_scalar( $action['type'] ) )
            {
                $actions[] = $action;
            }
        }

        return array_values( $actions );
    }

    /**
     * @param array<string, mixed>               $mapping Local form mapping row.
     * @param array<string, mixed>               $form Form metadata.
     * @param array<string, mixed>               $entry Form entry values.
     * @param array<string, mixed>               $execution_result Normalized execution result.
     * @param array<string, mixed>               $action Local custom action row.
     * @param array<int, array<string, mixed>>   $actions Post-execution actions.
     *
     * @return array<int, array<string, mixed>>
     */
    private function run_post_execution_actions(
        int $entry_id,
        array $mapping,
        array $form,
        array $entry,
        array $execution_result,
        array $action,
        array $actions
    ): array
    {
        $results = [];
        foreach ( $actions as $index => $post_execution_action )
        {
            $type = isset( $post_execution_action['type'] ) && is_scalar( $post_execution_action['type'] )
                ? sanitize_key( (string) $post_execution_action['type'] )
                : 'unknown';

            try
            {
                $results[] = $this->run_post_execution_action(
                    $entry_id,
                    $mapping,
                    $form,
                    $entry,
                    $execution_result,
                    $action,
                    $post_execution_action,
                    $index
                );
            }
            catch ( Throwable $throwable )
            {
                $results[] = [
                    'index'   => $index,
                    'type'    => $type,
                    'status'  => 'failed',
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $mapping Local form mapping row.
     * @param array<string, mixed> $form Form metadata.
     * @param array<string, mixed> $entry Form entry values.
     * @param array<string, mixed> $execution_result Normalized execution result.
     * @param array<string, mixed> $action Local custom action row.
     * @param array<string, mixed> $post_execution_action Effect configuration.
     *
     * @return array<string, mixed>
     */
    private function run_post_execution_action(
        int $entry_id,
        array $mapping,
        array $form,
        array $entry,
        array $execution_result,
        array $action,
        array $post_execution_action,
        int $index
    ): array
    {
        $type = isset( $post_execution_action['type'] ) && is_scalar( $post_execution_action['type'] )
            ? sanitize_key( (string) $post_execution_action['type'] )
            : '';

        if ( '' === $type )
        {
            return [
                'index'   => $index,
                'type'    => 'unknown',
                'status'  => 'failed',
                'message' => __( 'Missing post-execution action type.', 'sentient-forms' ),
            ];
        }

        $grade_gate = $this->resolve_post_execution_grade_gate( $post_execution_action, $execution_result );
        if ( null !== $grade_gate )
        {
            return [
                'index'          => $index,
                'type'           => $type,
                'status'         => 'skipped_grade_filter',
                'grade'          => $grade_gate['grade'],
                'allowed_grades' => $grade_gate['allowed_grades'],
            ];
        }

        return match ( $type )
        {
            'entry_note' => $this->run_post_execution_entry_note_action( $entry_id, $mapping, $form, $entry, $execution_result, $action, $post_execution_action, $index, $type ),
            'send_email' => $this->run_post_execution_email_action( $entry_id, $mapping, $form, $entry, $execution_result, $action, $post_execution_action, $index, $type ),
            'wp_hook' => $this->run_post_execution_hook_action( $entry_id, $mapping, $form, $entry, $execution_result, $action, $post_execution_action, $index, $type ),
            'webhook' => $this->run_post_execution_webhook_action( $entry_id, $mapping, $form, $entry, $execution_result, $action, $post_execution_action, $index, $type ),
            default => [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => sprintf(
                    /* translators: %s is the unsupported local post-execution action type. */
                    __( 'Unsupported post-execution action type: %s', 'sentient-forms' ),
                    $type
                ),
            ],
        };
    }

    /**
     * @param array<string, mixed> $post_execution_action Effect configuration.
     * @param array<string, mixed> $execution_result Normalized execution result.
     *
     * @return array{grade: string, allowed_grades: array<int, string>}|null
     */
    private function resolve_post_execution_grade_gate( array $post_execution_action, array $execution_result ): ?array
    {
        $allowed = $post_execution_action['grade_filter'] ?? $post_execution_action['grades'] ?? $post_execution_action['allowed_grades'] ?? null;
        if ( null === $allowed )
        {
            return null;
        }

        if ( is_string( $allowed ) )
        {
            $allowed = array_filter( array_map( 'trim', explode( ',', $allowed ) ) );
        }

        if ( ! is_array( $allowed ) )
        {
            return null;
        }

        $allowed_grades = [];
        foreach ( $allowed as $grade )
        {
            if ( ! is_scalar( $grade ) )
            {
                continue;
            }

            $normalized = $this->normalize_lead_grade( (string) $grade );
            if ( '' !== $normalized )
            {
                $allowed_grades[] = $normalized;
            }
        }

        $allowed_grades = array_values( array_unique( $allowed_grades ) );
        if ( [] === $allowed_grades )
        {
            return null;
        }

        $result     = is_array( $execution_result['result'] ?? null ) ? $execution_result['result'] : [];
        $structured = is_array( $result['structured'] ?? null ) ? $result['structured'] : [];
        $grade      = $this->normalize_lead_grade(
            (string) (
                $structured['grade']
                ?? $result['grade']
                ?? $this->resolve_path( $result, 'lead.grade' )
                ?? ''
            )
        );

        if ( '' !== $grade && in_array( $grade, $allowed_grades, true ) )
        {
            return null;
        }

        return [
            'grade'          => $grade,
            'allowed_grades' => $allowed_grades,
        ];
    }

    private function normalize_lead_grade( string $grade ): string
    {
        $grade = strtoupper( trim( $grade ) );
        if ( in_array( $grade, [ 'A', 'B', 'C' ], true ) )
        {
            return $grade;
        }

        if ( in_array( $grade, [ 'F', 'REJECT', 'REJECTED' ], true ) )
        {
            return 'Reject';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $mapping Local form mapping row.
     * @param array<string, mixed> $form Form metadata.
     * @param array<string, mixed> $entry Form entry values.
     * @param array<string, mixed> $execution_result Normalized execution result.
     * @param array<string, mixed> $action Local custom action row.
     * @param array<string, mixed> $post_execution_action Effect configuration.
     *
     * @return array<string, mixed>
     */
    private function run_post_execution_entry_note_action(
        int $entry_id,
        array $mapping,
        array $form,
        array $entry,
        array $execution_result,
        array $action,
        array $post_execution_action,
        int $index,
        string $type
    ): array
    {
        if ( ! $this->is_gravity_forms_source( (string) ( $mapping['form_source'] ?? 'gravity_forms' ) ) )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'native_note_unsupported',
                'message' => __( 'Native entry notes are unavailable for this form source.', 'sentient-forms' ),
            ];
        }

        if ( $entry_id <= 0 )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'missing_entry_id',
                'message' => __( 'A saved native entry is required before adding an entry note.', 'sentient-forms' ),
            ];
        }

        if ( ! class_exists( 'GFFormsModel' ) || ! method_exists( 'GFFormsModel', 'add_note' ) )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => __( 'Gravity Forms notes are unavailable.', 'sentient-forms' ),
            ];
        }

        $message = isset( $post_execution_action['message'] ) && is_scalar( $post_execution_action['message'] )
            ? (string) $post_execution_action['message']
            : (string) ( $post_execution_action['template'] ?? __( 'Sentient Forms completed {{action_label}}. Result: {{llm_output}}', 'sentient-forms' ) );
        $note = $this->render_post_execution_template( $message, $mapping, $form, $entry, $execution_result, $action );

        if ( '' === trim( $note ) )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => __( 'Entry note template rendered empty.', 'sentient-forms' ),
            ];
        }

        GFFormsModel::add_note(
            $entry_id,
            0,
            'Sentient Forms AI',
            sanitize_textarea_field( $note ),
            'sentient_forms_local_post_execution'
        );

        return [
            'index'   => $index,
            'type'    => $type,
            'status'  => 'success',
            'message' => $note,
        ];
    }

    /**
     * @param array<string, mixed> $mapping Local form mapping row.
     * @param array<string, mixed> $form Form metadata.
     * @param array<string, mixed> $entry Form entry values.
     * @param array<string, mixed> $execution_result Normalized execution result.
     * @param array<string, mixed> $action Local custom action row.
     * @param array<string, mixed> $post_execution_action Effect configuration.
     *
     * @return array<string, mixed>
     */
    private function run_post_execution_email_action(
        int $entry_id,
        array $mapping,
        array $form,
        array $entry,
        array $execution_result,
        array $action,
        array $post_execution_action,
        int $index,
        string $type
    ): array
    {
        if ( ! function_exists( 'wp_mail' ) )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => __( 'WordPress mail is unavailable.', 'sentient-forms' ),
            ];
        }

        $raw_recipients = $post_execution_action['to'] ?? $post_execution_action['recipients'] ?? '';
        if ( is_string( $raw_recipients ) )
        {
            $raw_recipients = array_filter( array_map( 'trim', explode( ',', $raw_recipients ) ) );
        }
        elseif ( ! is_array( $raw_recipients ) )
        {
            $raw_recipients = [];
        }

        if ( [] === $raw_recipients )
        {
            $raw_recipients[] = get_option( 'admin_email' );
        }

        $recipients = [];
        foreach ( $raw_recipients as $recipient )
        {
            if ( ! is_scalar( $recipient ) )
            {
                continue;
            }

            $email = sanitize_email(
                $this->render_post_execution_template( (string) $recipient, $mapping, $form, $entry, $execution_result, $action )
            );
            if ( is_email( $email ) )
            {
                $recipients[] = $email;
            }
        }

        if ( [] === $recipients )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => __( 'No valid email recipients were configured.', 'sentient-forms' ),
            ];
        }

        $subject_template = isset( $post_execution_action['subject'] ) && is_scalar( $post_execution_action['subject'] )
            ? (string) $post_execution_action['subject']
            : __( 'Sentient Forms completed {{action_label}}', 'sentient-forms' );
        $body_template = isset( $post_execution_action['body'] ) && is_scalar( $post_execution_action['body'] )
            ? (string) $post_execution_action['body']
            : (string) ( $post_execution_action['message'] ?? "{{llm_output}}\n\n{{justification}}" );

        $sent = wp_mail(
            $recipients,
            $this->render_post_execution_template( $subject_template, $mapping, $form, $entry, $execution_result, $action ),
            $this->render_post_execution_template( $body_template, $mapping, $form, $entry, $execution_result, $action ),
            [ 'Content-Type: text/plain; charset=UTF-8' ]
        );

        return [
            'index'      => $index,
            'type'       => $type,
            'status'     => $sent ? 'success' : 'failed',
            'recipients' => $recipients,
        ];
    }

    /**
     * @param array<string, mixed> $mapping Local form mapping row.
     * @param array<string, mixed> $form Form metadata.
     * @param array<string, mixed> $entry Form entry values.
     * @param array<string, mixed> $execution_result Normalized execution result.
     * @param array<string, mixed> $action Local custom action row.
     * @param array<string, mixed> $post_execution_action Effect configuration.
     *
     * @return array<string, mixed>
     */
    private function run_post_execution_hook_action(
        int $entry_id,
        array $mapping,
        array $form,
        array $entry,
        array $execution_result,
        array $action,
        array $post_execution_action,
        int $index,
        string $type
    ): array
    {
        $hook_template = isset( $post_execution_action['hook_name'] ) && is_scalar( $post_execution_action['hook_name'] )
            ? (string) $post_execution_action['hook_name']
            : '';
        $hook_name = preg_replace(
            '/[^A-Za-z0-9_.-]/',
            '',
            $this->render_post_execution_template( $hook_template, $mapping, $form, $entry, $execution_result, $action )
        );

        if ( '' === $hook_name )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => __( 'Missing WordPress hook name.', 'sentient-forms' ),
            ];
        }

        $context = $this->build_post_execution_context( $mapping, $form, $entry, $execution_result, $action );

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The hook name is an admin-configured local result effect and is sanitized before dispatch.
        do_action( $hook_name, $context, $execution_result, $post_execution_action, $entry_id );

        return [
            'index'     => $index,
            'type'      => $type,
            'status'    => 'success',
            'hook_name' => $hook_name,
        ];
    }

    /**
     * @param array<string, mixed> $mapping Local form mapping row.
     * @param array<string, mixed> $form Form metadata.
     * @param array<string, mixed> $entry Form entry values.
     * @param array<string, mixed> $execution_result Normalized execution result.
     * @param array<string, mixed> $action Local custom action row.
     * @param array<string, mixed> $post_execution_action Effect configuration.
     *
     * @return array<string, mixed>
     */
    private function run_post_execution_webhook_action(
        int $entry_id,
        array $mapping,
        array $form,
        array $entry,
        array $execution_result,
        array $action,
        array $post_execution_action,
        int $index,
        string $type
    ): array
    {
        if ( ! function_exists( 'wp_remote_request' ) )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => __( 'WordPress HTTP API is unavailable.', 'sentient-forms' ),
            ];
        }

        $url_template = isset( $post_execution_action['url'] ) && is_scalar( $post_execution_action['url'] )
            ? (string) $post_execution_action['url']
            : '';
        $url = esc_url_raw( $this->render_post_execution_template( $url_template, $mapping, $form, $entry, $execution_result, $action ) );
        $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => __( 'Webhook URL must use http or https.', 'sentient-forms' ),
            ];
        }

        $validated_url = Sentient_Forms_Url_Policy::validate_outbound_url( $url, 'webhook' );
        if ( is_wp_error( $validated_url ) )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => $validated_url->get_error_message(),
            ];
        }

        $method = isset( $post_execution_action['method'] ) && is_scalar( $post_execution_action['method'] )
            ? strtoupper( sanitize_key( (string) $post_execution_action['method'] ) )
            : 'POST';
        $headers = $this->sanitize_webhook_headers( $post_execution_action['headers'] ?? [] );
        $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';

        $response = Sentient_Forms_Url_Policy::remote_request(
            $validated_url,
            [
                'method'             => in_array( $method, [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) ? $method : 'POST',
                'timeout'            => 5,
                'headers'            => $headers,
                'body'               => wp_json_encode(
                    [
                        'entry_id' => $entry_id,
                        'context'  => $this->build_post_execution_context( $mapping, $form, $entry, $execution_result, $action ),
                        'result'   => $execution_result,
                        'action'   => $post_execution_action,
                    ]
                ),
            ],
            'webhook'
        );

        if ( is_wp_error( $response ) )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => $response->get_error_message(),
            ];
        }

        $status_code = function_exists( 'wp_remote_retrieve_response_code' )
            ? (int) wp_remote_retrieve_response_code( $response )
            : 0;

        return [
            'index'       => $index,
            'type'        => $type,
            'status'      => $status_code >= 200 && $status_code < 400 ? 'success' : 'failed',
            'status_code' => $status_code,
        ];
    }

    /**
     * @param mixed $headers Raw webhook headers.
     *
     * @return array<string, string>
     */
    private function sanitize_webhook_headers( mixed $headers ): array
    {
        if ( ! is_array( $headers ) )
        {
            return [];
        }

        $sanitized = [];
        foreach ( $headers as $key => $value )
        {
            if ( ! is_scalar( $key ) || ! is_scalar( $value ) )
            {
                continue;
            }

            $name = sanitize_text_field( (string) $key );
            if ( '' === $name )
            {
                continue;
            }

            $sanitized[ $name ] = sanitize_text_field( (string) $value );
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $mapping Local form mapping row.
     * @param array<string, mixed> $form Form metadata.
     * @param array<string, mixed> $entry Form entry values.
     * @param array<string, mixed> $execution_result Normalized execution result.
     * @param array<string, mixed> $action Local custom action row.
     *
     * @return array<string, mixed>
     */
    private function build_post_execution_context( array $mapping, array $form, array $entry, array $execution_result, array $action ): array
    {
        return [
            'form_source'          => $mapping['form_source'] ?? 'gravity_forms',
            'form_id'              => $mapping['form_id'] ?? ( $form['id'] ?? null ),
            'local_form_mapping_id'=> isset( $mapping['id'] ) ? (int) $mapping['id'] : null,
            'action_kind'          => $mapping['action_kind'] ?? 'custom_action',
            'action_id'            => isset( $mapping['action_id'] ) ? (int) $mapping['action_id'] : null,
            'action_label'         => $this->get_action_label( $action ),
            'provider'             => $execution_result['provider'] ?? null,
            'model'                => $execution_result['model'] ?? null,
            'execution_request_id' => $execution_result['execution_request_id'] ?? null,
            'grade'                => $this->resolve_result_grade( $execution_result ),
            'form'                 => $form,
            'entry'                => $entry,
        ];
    }

    /**
     * @param array<string, mixed> $execution_result Normalized execution result.
     */
    private function resolve_result_grade( array $execution_result ): string
    {
        $result     = is_array( $execution_result['result'] ?? null ) ? $execution_result['result'] : [];
        $structured = is_array( $result['structured'] ?? null ) ? $result['structured'] : [];

        return $this->normalize_lead_grade(
            (string) (
                $structured['grade']
                ?? $result['grade']
                ?? $this->resolve_path( $result, 'lead.grade' )
                ?? ''
            )
        );
    }

    /**
     * @param array<string, mixed> $mapping Local form mapping row.
     * @param array<string, mixed> $form Form metadata.
     * @param array<string, mixed> $entry Form entry values.
     * @param array<string, mixed> $execution_result Normalized execution result.
     * @param array<string, mixed> $action Local custom action row.
     */
    private function render_post_execution_template(
        string $template,
        array $mapping,
        array $form,
        array $entry,
        array $execution_result,
        array $action
    ): string
    {
        $result = is_array( $execution_result['result'] ?? null ) ? $execution_result['result'] : [];

        return (string) preg_replace_callback(
            '/{{\s*([^{}]+?)\s*}}/',
            function ( array $matches ) use ( $mapping, $form, $entry, $execution_result, $action, $result ): string
            {
                $key = strtolower( trim( (string) $matches[1] ) );

                if ( str_starts_with( $key, 'field:' ) )
                {
                    $field_selector = substr( $key, strlen( 'field:' ) );
                    return $this->resolve_field_placeholder_value( $field_selector, $entry, $form );
                }

                $structured = is_array( $result['structured'] ?? null ) ? $result['structured'] : [];
                $values     = [
                    'entry_id'             => $entry['id'] ?? '',
                    'form_id'              => $mapping['form_id'] ?? ( $form['id'] ?? '' ),
                    'form_title'           => $form['title'] ?? '',
                    'local_form_mapping_id'=> $mapping['id'] ?? '',
                    'action_label'         => $this->get_action_label( $action ),
                    'provider'             => $execution_result['provider'] ?? '',
                    'model'                => $execution_result['model'] ?? '',
                    'execution_request_id' => $execution_result['execution_request_id'] ?? '',
                    'llm_output'           => $result['content'] ?? '',
                    'content'              => $result['content'] ?? '',
                    'classification'       => $structured['classification'] ?? $result['classification'] ?? '',
                    'confidence'           => $structured['confidence'] ?? $result['confidence'] ?? '',
                    'grade'                => $structured['grade'] ?? $result['grade'] ?? '',
                    'priority'             => $structured['recommended_priority'] ?? $structured['priority'] ?? $result['priority'] ?? '',
                    'next_best_action'     => $structured['next_best_action'] ?? $result['next_best_action'] ?? '',
                    'suggested_reply_draft'=> $structured['suggested_reply_draft'] ?? $result['suggested_reply_draft'] ?? '',
                    'summary'              => $structured['summary'] ?? $result['summary'] ?? '',
                    'justification'        => $structured['justification'] ?? $result['justification'] ?? '',
                    'structured_output'    => wp_json_encode( $structured ),
                    'result_json'          => wp_json_encode( $result ),
                ];

                if ( array_key_exists( $key, $values ) )
                {
                    return $this->stringify_value( $values[ $key ] );
                }

                foreach ( [ $result, $execution_result, $mapping, $form, $entry ] as $source )
                {
                    if ( ! is_array( $source ) )
                    {
                        continue;
                    }

                    $value = $this->resolve_path( $source, $key );
                    if ( null !== $value )
                    {
                        return $this->stringify_value( $value );
                    }
                }

                return '';
            },
            $template
        );
    }

    /**
     * Resolve field merge tags that target either a concrete field id or a human-friendly selector.
     *
     * @param string               $selector Field selector after the "field:" prefix.
     * @param array<string, mixed> $entry    Form entry values.
     * @param array<string, mixed> $form     Form metadata.
     */
    private function resolve_field_placeholder_value( string $selector, array $entry, array $form ): string
    {
        $selector = trim( strtolower( $selector ) );
        if ( '' === $selector )
        {
            return '';
        }

        if ( str_starts_with( $selector, 'type:' ) )
        {
            return $this->resolve_field_selector_by_type( substr( $selector, strlen( 'type:' ) ), $entry, $form );
        }

        if ( str_starts_with( $selector, 'label_contains:' ) )
        {
            return $this->resolve_field_selector_by_label(
                substr( $selector, strlen( 'label_contains:' ) ),
                $entry,
                $form
            );
        }

        return $this->stringify_value( $entry[ $selector ] ?? '' );
    }

    /**
     * @param array<string, mixed> $entry Form entry values.
     * @param array<string, mixed> $form  Form metadata.
     */
    private function resolve_field_selector_by_type( string $selector, array $entry, array $form ): string
    {
        $parts = explode( '.', strtolower( trim( $selector ) ), 2 );
        $type  = sanitize_key( $parts[0] ?? '' );
        $part  = sanitize_key( $parts[1] ?? '' );
        if ( '' === $type )
        {
            return '';
        }

        foreach ( $this->form_fields( $form ) as $field )
        {
            if ( $this->field_type( $field ) !== $type )
            {
                continue;
            }

            return $this->field_value_from_entry( $this->field_id( $field ), $entry, $type, $part );
        }

        return '';
    }

    /**
     * @param array<string, mixed> $entry Form entry values.
     * @param array<string, mixed> $form  Form metadata.
     */
    private function resolve_field_selector_by_label( string $label_fragment, array $entry, array $form ): string
    {
        $needle = strtolower( trim( $label_fragment ) );
        if ( '' === $needle )
        {
            return '';
        }

        foreach ( $this->form_fields( $form ) as $field )
        {
            $label = strtolower( $this->field_label( $field ) );
            if ( '' === $label || ! str_contains( $label, $needle ) )
            {
                continue;
            }

            return $this->field_value_from_entry(
                $this->field_id( $field ),
                $entry,
                $this->field_type( $field ),
                ''
            );
        }

        return '';
    }

    /**
     * @param array<string, mixed> $form Form metadata.
     * @return array<int, mixed>
     */
    private function form_fields( array $form ): array
    {
        return isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : [];
    }

    private function field_id( mixed $field ): string
    {
        if ( is_array( $field ) )
        {
            return isset( $field['id'] ) ? (string) $field['id'] : '';
        }

        return is_object( $field ) && isset( $field->id ) ? (string) $field->id : '';
    }

    private function field_type( mixed $field ): string
    {
        if ( is_array( $field ) )
        {
            return isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : '';
        }

        return is_object( $field ) && isset( $field->type ) ? sanitize_key( (string) $field->type ) : '';
    }

    private function field_label( mixed $field ): string
    {
        if ( is_array( $field ) )
        {
            return isset( $field['label'] ) ? (string) $field['label'] : '';
        }

        return is_object( $field ) && isset( $field->label ) ? (string) $field->label : '';
    }

    /**
     * @param array<string, mixed> $entry Form entry values.
     */
    private function field_value_from_entry( string $field_id, array $entry, string $field_type, string $part ): string
    {
        if ( '' === $field_id )
        {
            return '';
        }

        if ( 'name' === $field_type )
        {
            $first = $this->stringify_value( $entry[ $field_id . '.3' ] ?? $entry[ $field_id . '.1' ] ?? '' );
            $last  = $this->stringify_value( $entry[ $field_id . '.6' ] ?? $entry[ $field_id . '.2' ] ?? '' );
            if ( 'first' === $part )
            {
                return $first;
            }
            if ( 'last' === $part )
            {
                return $last;
            }
            $full = trim( $this->stringify_value( $entry[ $field_id ] ?? '' ) );
            return '' !== $full ? $full : trim( $first . ' ' . $last );
        }

        return $this->stringify_value( $entry[ $field_id ] ?? '' );
    }

    /**
     * @param array<string, mixed> $action Local custom action row.
     */
    private function get_action_label( array $action ): string
    {
        if ( isset( $action['display_name'] ) && is_scalar( $action['display_name'] ) )
        {
            return sanitize_text_field( (string) $action['display_name'] );
        }

        if ( isset( $action['code'] ) && is_scalar( $action['code'] ) )
        {
            return sanitize_text_field( (string) $action['code'] );
        }

        return __( 'Local OpenRouter action', 'sentient-forms' );
    }

    /**
     * Persist post-execution effect audit entries on the Gravity Forms entry.
     *
     * @param array<int, array<string, mixed>> $results Effect results.
     */
    private function record_post_execution_action_results( int $entry_id, array $results ): void
    {
        if ( $entry_id <= 0 || [] === $results || ! function_exists( 'gform_update_meta' ) )
        {
            return;
        }

        $existing = function_exists( 'gform_get_meta' )
            ? gform_get_meta( $entry_id, 'sentient_forms_post_execution_actions' )
            : null;
        if ( is_string( $existing ) )
        {
            $decoded  = json_decode( $existing, true );
            $existing = is_array( $decoded ) ? $decoded : [];
        }

        if ( ! is_array( $existing ) )
        {
            $existing = [];
        }

        $existing[] = [
            'ran_at'  => current_time( 'mysql' ),
            'results' => $results,
        ];

        gform_update_meta( $entry_id, 'sentient_forms_post_execution_actions', wp_json_encode( $existing ) );
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
