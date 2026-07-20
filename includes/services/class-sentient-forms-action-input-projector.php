<?php
/**
 * Provider-bound Action input projection.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Applies source-neutral field-selection policy before a provider sees form data.
 */
final class Sentient_Forms_Action_Input_Projector
{
    private const MODES = [ 'all', 'selected', 'exclude' ];

    private const ENTRY_METADATA_KEYS = [
        'id',
        'entry_id',
        'form_id',
        'post_id',
        'user_id',
        'created_by',
        'created_at',
        'updated_at',
        'date_created',
        'date_updated',
        'source_url',
        'ip',
        'user_agent',
        'status',
        'currency',
        'payment_status',
        'payment_date',
        'payment_amount',
        'payment_method',
        'transaction_id',
        'is_fulfilled',
    ];

    /**
     * @param array<string, mixed>|null $input_policy Explicit field-projection policy, or null for legacy full input.
     * @param array<string, mixed>      $bindings     Prompt-variable bindings stored independently from projection policy.
     * @param array<string, mixed>      $form         Source-normalized form metadata.
     * @param array<string, mixed>      $entry        Source-normalized entry values.
     *
     * @return array{form:array<string,mixed>,entry:array<string,mixed>,bindings:array<string,mixed>,manifest:array<string,mixed>}|WP_Error
     */
    public function project( ?array $input_policy, array $bindings, array $form, array $entry ): array | WP_Error
    {
        if ( null === $input_policy )
        {
            return [
                'form'     => $form,
                'entry'    => $entry,
                'bindings' => $bindings,
                'manifest' => [
                    'mapping_source'         => 'variable_bindings',
                    'mode'                   => 'all',
                    'include_metadata'       => true,
                    'full_entry_sent'        => true,
                    'requested_field_ids'    => [],
                    'applied_entry_keys'     => array_values( array_map( 'strval', array_keys( $entry ) ) ),
                    'applied_form_field_ids' => $this->form_field_ids( $form ),
                ],
            ];
        }

        $mode = isset( $input_policy['mode'] ) && is_scalar( $input_policy['mode'] )
            ? sanitize_key( (string) $input_policy['mode'] )
            : '';
        if ( ! in_array( $mode, self::MODES, true ) )
        {
            return new WP_Error(
                'sentient_forms_invalid_input_mapping_mode',
                __( 'Action input mapping mode is invalid.', 'sentient-forms' ),
                [ 'status' => 422 ]
            );
        }

        if ( array_key_exists( 'include_metadata', $input_policy ) && ! is_bool( $input_policy['include_metadata'] ) )
        {
            return new WP_Error(
                'sentient_forms_invalid_input_mapping_metadata_control',
                __( 'Action input mapping metadata control must be a boolean.', 'sentient-forms' ),
                [ 'status' => 422 ]
            );
        }

        $include_metadata = ! array_key_exists( 'include_metadata', $input_policy )
            || $input_policy['include_metadata'];
        $requested_field_ids = $this->normalize_field_ids( $input_policy['field_ids'] ?? [] );
        if ( is_wp_error( $requested_field_ids ) )
        {
            return $requested_field_ids;
        }
        $form_field_ids       = $this->form_field_ids( $form );
        $projected_entry      = [];

        foreach ( $entry as $key => $value )
        {
            $entry_key = (string) $key;
            $is_field  = $this->entry_key_matches_any_field( $entry_key, $form_field_ids );
            if ( [] === $form_field_ids && ! in_array( $entry_key, self::ENTRY_METADATA_KEYS, true ) )
            {
                $is_field = true;
            }

            $requested = $this->entry_key_matches_any_field( $entry_key, $requested_field_ids );
            $include   = match ( $mode )
            {
                'selected' => $requested,
                'exclude'  => $is_field && ! $requested,
                default    => $is_field,
            };

            if ( $include || ( $include_metadata && ! $is_field ) )
            {
                $projected_entry[ $key ] = $value;
            }
        }

        $applied_field_ids = array_values(
            array_filter(
                $form_field_ids,
                static fn( string $field_id ): bool => match ( $mode )
                {
                    'selected' => in_array( $field_id, $requested_field_ids, true ),
                    'exclude'  => ! in_array( $field_id, $requested_field_ids, true ),
                    default    => true,
                }
            )
        );
        $projected_form = $this->project_form( $form, $applied_field_ids, $include_metadata );
        if ( ! $include_metadata )
        {
            $applied_field_ids = [];
        }

        if ( [] === $projected_form && [] === $projected_entry )
        {
            return new WP_Error(
                'sentient_forms_empty_input_projection',
                __( 'Action input mapping must send at least one field or form metadata.', 'sentient-forms' ),
                [ 'status' => 422 ]
            );
        }

        return [
            'form'     => $projected_form,
            'entry'    => $projected_entry,
            'bindings' => $bindings,
            'manifest' => [
                'mapping_source'         => 'explicit_mapping',
                'mode'                   => $mode,
                'include_metadata'       => $include_metadata,
                'full_entry_sent'        => 'all' === $mode && $include_metadata,
                'requested_field_ids'    => $requested_field_ids,
                'applied_entry_keys'     => array_values( array_map( 'strval', array_keys( $projected_entry ) ) ),
                'applied_form_field_ids' => $applied_field_ids,
            ],
        ];
    }

    /**
     * @return array<int, string>|WP_Error
     */
    private function normalize_field_ids( mixed $value ): array | WP_Error
    {
        if ( ! is_array( $value ) )
        {
            return new WP_Error(
                'sentient_forms_invalid_input_mapping_field_ids',
                __( 'Action input mapping field IDs must be a list.', 'sentient-forms' ),
                [ 'status' => 422 ]
            );
        }

        $field_ids = [];
        foreach ( $value as $field_id )
        {
            if ( is_bool( $field_id ) || ! is_scalar( $field_id ) )
            {
                return new WP_Error(
                    'sentient_forms_invalid_input_mapping_field_ids',
                    __( 'Action input mapping field IDs must contain only scalar identifiers.', 'sentient-forms' ),
                    [ 'status' => 422 ]
                );
            }

            $normalized = trim( sanitize_text_field( (string) $field_id ) );
            if ( '' === $normalized )
            {
                return new WP_Error(
                    'sentient_forms_invalid_input_mapping_field_ids',
                    __( 'Action input mapping field IDs must not be empty.', 'sentient-forms' ),
                    [ 'status' => 422 ]
                );
            }

            $field_ids[] = $normalized;
        }

        return array_values( array_unique( $field_ids ) );
    }

    /**
     * @param array<string, mixed> $form
     * @return array<int, string>
     */
    private function form_field_ids( array $form ): array
    {
        $field_ids = [];
        foreach ( is_array( $form['fields'] ?? null ) ? $form['fields'] : [] as $field )
        {
            $field_id = '';
            if ( is_array( $field ) && isset( $field['id'] ) && is_scalar( $field['id'] ) )
            {
                $field_id = trim( (string) $field['id'] );
            }
            elseif ( is_object( $field ) && isset( $field->id ) && is_scalar( $field->id ) )
            {
                $field_id = trim( (string) $field->id );
            }

            if ( '' !== $field_id )
            {
                $field_ids[] = $field_id;
            }
        }

        return array_values( array_unique( $field_ids ) );
    }

    /**
     * @param array<int, string> $field_ids
     */
    private function entry_key_matches_any_field( string $entry_key, array $field_ids ): bool
    {
        foreach ( $field_ids as $field_id )
        {
            if (
                $entry_key === $field_id
                || str_starts_with( $entry_key, $field_id . '.' )
                || $entry_key === 'field_' . $field_id
                || str_starts_with( $entry_key, 'field_' . $field_id . '.' )
            )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $form
     * @param array<int, string>   $applied_field_ids
     * @return array<string, mixed>
     */
    private function project_form( array $form, array $applied_field_ids, bool $include_metadata ): array
    {
        $projected_fields = [];
        foreach ( is_array( $form['fields'] ?? null ) ? $form['fields'] : [] as $field )
        {
            $field_id = $this->form_field_ids( [ 'fields' => [ $field ] ] )[0] ?? '';
            if ( '' !== $field_id && in_array( $field_id, $applied_field_ids, true ) )
            {
                $projected_fields[] = $field;
            }
        }

        if ( $include_metadata )
        {
            $projected = $form;
            if ( array_key_exists( 'fields', $form ) )
            {
                $projected['fields'] = $projected_fields;
            }

            return $projected;
        }

        return [];
    }
}
