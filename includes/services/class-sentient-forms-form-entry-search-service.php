<?php
/**
 * Shared local form entry search for historical admin workflows.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Form_Entry_Search_Service
{
    private ?Sentient_Forms_Submission_Ledger_Repository $ledger = null;
    private ?Sentient_Forms_Submission_Ledger_Settings_Repository $ledger_settings = null;
    private ?Sentient_Forms_Form_Adapter_Registry $adapter_registry = null;

    public function __construct(
        ?Sentient_Forms_Submission_Ledger_Repository $ledger = null,
        ?Sentient_Forms_Submission_Ledger_Settings_Repository $ledger_settings = null,
        ?Sentient_Forms_Form_Adapter_Registry $adapter_registry = null
    ) {
        $this->ledger           = $ledger;
        $this->ledger_settings  = $ledger_settings;
        $this->adapter_registry = $adapter_registry;
    }

    /**
     * @return array{entries: array<int,array<string,mixed>>, form_source: string, form_id: string, availability: array<string,mixed>}|WP_Error
     */
    public function search( string $form_source, string $form_id, string $query = '', int $limit = 10, string $status = 'active' ): array | WP_Error
    {
        $form_source = sanitize_key( $form_source );
        $form_id     = sanitize_text_field( rawurldecode( $form_id ) );
        $query       = strtolower( trim( sanitize_text_field( $query ) ) );
        $limit       = max( 1, min( 50, $limit ) );
        $status      = $this->normalize_status_filter( $status );

        if ( '' === $form_source || '' === $form_id )
        {
            return new WP_Error( 'sentient_forms_entry_search_invalid_scope', __( 'Entry search requires a form source and form ID.', 'sentient-forms' ), [ 'status' => 400 ] );
        }

        $adapter = $this->adapter_for_source( $form_source );
        if ( $adapter instanceof Sentient_Forms_Historical_Entries_Adapter_Interface )
        {
            $adapter_result = $adapter->search_historical_entries( $form_id, $query, $limit, $status );
            if ( is_wp_error( $adapter_result ) )
            {
                if ( ! $this->adapter_error_allows_ledger_fallback( $adapter_result ) )
                {
                    return $adapter_result;
                }

                return $this->search_submission_ledger_entries( $form_source, $form_id, $query, $limit, $this->availability_from_error( $adapter_result ) );
            }

            $adapter_result = $this->normalize_adapter_search_response( $adapter_result, $form_source, $form_id );
            $availability   = $adapter_result['availability'];
            if ( ! empty( $availability['native_read'] ) || empty( $availability['allow_ledger_fallback'] ) )
            {
                return $adapter_result;
            }

            return $this->search_submission_ledger_entries( $form_source, $form_id, $query, $limit, $availability );
        }

        return $this->search_submission_ledger_entries( $form_source, $form_id, $query, $limit );
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function resolve_entry( string $form_source, string $form_id, string $entry_id ): array | WP_Error
    {
        $form_source = sanitize_key( $form_source );
        $form_id     = sanitize_text_field( rawurldecode( $form_id ) );
        $entry_id    = sanitize_text_field( $entry_id );
        if ( '' === $entry_id )
        {
            return new WP_Error( 'sentient_forms_entry_search_missing_entry', __( 'Entry ID is required.', 'sentient-forms' ), [ 'status' => 400 ] );
        }

        $ledger_record = $this->submission_ledger_record_for_entry_identifier( $form_source, $form_id, $entry_id );
        if ( null !== $ledger_record && $this->entry_identifier_is_submission_uuid( $entry_id ) )
        {
            return $this->format_submission_ledger_record( $ledger_record );
        }

        $adapter = $this->adapter_for_source( $form_source );
        if ( $adapter instanceof Sentient_Forms_Historical_Entries_Adapter_Interface )
        {
            $entry = $adapter->get_historical_entry( $form_id, $entry_id );
            if ( is_wp_error( $entry ) )
            {
                if ( ! $this->adapter_error_allows_ledger_fallback( $entry ) )
                {
                    return $entry;
                }
            }
            else
            {
                return $this->normalize_adapter_entry( $entry );
            }
        }

        if ( null === $ledger_record )
        {
            return new WP_Error( 'sentient_forms_submission_ledger_entry_not_found', __( 'Submission ledger entry could not be found for this form.', 'sentient-forms' ), [ 'status' => 404 ] );
        }

        return $this->format_submission_ledger_record( $ledger_record );
    }

    /**
     * @return array{entries: array<int,array<string,mixed>>, form_source: string, form_id: string, availability: array<string,mixed>}|WP_Error
     */
    private function search_submission_ledger_entries( string $form_source, string $form_id, string $query, int $limit, ?array $native_availability = null ): array | WP_Error
    {
        $ledger = $this->submission_ledger_repository();
        if ( null === $ledger )
        {
            return [
                'entries'      => [],
                'form_source'  => $form_source,
                'form_id'      => $form_id,
                'availability' => [
                    'source'             => 'ledger',
                    'native_read'        => false,
                    'ledger_read'        => false,
                    'ledger_enabled'     => false,
                    'unavailable_reason' => 'submission_ledger_unavailable',
                    'native_unavailable_reason' => isset( $native_availability['unavailable_reason'] ) && is_scalar( $native_availability['unavailable_reason'] )
                        ? sanitize_key( (string) $native_availability['unavailable_reason'] )
                        : null,
                ],
            ];
        }

        $results   = [];
        $page_size = 100;
        $offset    = 0;

        do
        {
            $records = $ledger->list_for_form( $form_source, $form_id, $page_size, $offset );
            foreach ( $records as $record )
            {
                if ( '' !== $query && ! $this->submission_ledger_record_matches_query( $record, $query ) )
                {
                    continue;
                }

                $results[] = $this->format_submission_ledger_record( $record );
                if ( count( $results ) >= $limit )
                {
                    break 2;
                }
            }

            $offset += $page_size;
        }
        while ( count( $records ) === $page_size );

        $settings = $this->submission_ledger_settings_repository()?->get_or_default( $form_source, $form_id ) ?? [];
        $enabled  = ! empty( $settings['enabled'] );
        $reason   = null;
        if ( [] === $results && ! $enabled )
        {
            $reason = 'submission_ledger_disabled';
        }

        return [
            'entries'      => $results,
            'form_source'  => $form_source,
            'form_id'      => $form_id,
                'availability' => [
                    'source'             => 'ledger',
                    'native_read'        => false,
                    'ledger_read'        => true,
                    'ledger_enabled'     => $enabled,
                    'unavailable_reason' => $reason,
                    'native_unavailable_reason' => isset( $native_availability['unavailable_reason'] ) && is_scalar( $native_availability['unavailable_reason'] )
                        ? sanitize_key( (string) $native_availability['unavailable_reason'] )
                        : null,
                ],
            ];
    }

    /**
     * @return array<string,mixed>
     */
    private function format_submission_ledger_record( array $record ): array
    {
        $submission_uuid = isset( $record['submission_uuid'] ) && is_scalar( $record['submission_uuid'] )
            ? sanitize_text_field( (string) $record['submission_uuid'] )
            : '';
        $native_entry_id = isset( $record['native_entry_id'] ) && is_scalar( $record['native_entry_id'] ) && '' !== trim( (string) $record['native_entry_id'] )
            ? sanitize_text_field( (string) $record['native_entry_id'] )
            : null;
        $native_entry_url = isset( $record['native_entry_url'] ) && is_scalar( $record['native_entry_url'] ) && '' !== trim( (string) $record['native_entry_url'] )
            ? esc_url_raw( (string) $record['native_entry_url'] )
            : null;

        return [
            'id'               => $submission_uuid,
            'source_type'      => 'ledger',
            'submission_uuid'  => $submission_uuid,
            'native_entry_id'  => $native_entry_id,
            'native_entry_url' => $native_entry_url,
            'date_created'     => isset( $record['source_submitted_at'] ) && is_scalar( $record['source_submitted_at'] ) && '' !== trim( (string) $record['source_submitted_at'] )
                ? sanitize_text_field( (string) $record['source_submitted_at'] )
                : ( isset( $record['captured_at'] ) && is_scalar( $record['captured_at'] ) ? sanitize_text_field( (string) $record['captured_at'] ) : null ),
            'status'           => null,
            'field_summary'    => $this->summarize_submission_ledger_fields( $record ),
        ];
    }

    private function normalize_status_filter( string $status ): string
    {
        $status = sanitize_key( $status );
        return in_array( $status, [ 'all', 'active', 'spam' ], true ) ? $status : 'active';
    }

    private function submission_ledger_record_matches_query( array $record, string $query ): bool
    {
        $logical_fields = is_array( $record['logical_fields_json'] ?? null ) ? $record['logical_fields_json'] : [];
        return str_contains( strtolower( wp_json_encode( $logical_fields ) ?: '' ), strtolower( $query ) );
    }

    /**
     * @return array<int, array{field_id: string, label: string, value: string}>
     */
    private function summarize_submission_ledger_fields( array $record ): array
    {
        $logical_fields = is_array( $record['logical_fields_json'] ?? null ) ? $record['logical_fields_json'] : [];
        $summary        = [];
        foreach ( $logical_fields as $field_id => $value )
        {
            if ( count( $summary ) >= 12 )
            {
                break;
            }

            if ( is_array( $value ) )
            {
                $value = wp_json_encode( $value );
            }

            if ( ! is_scalar( $value ) || '' === trim( (string) $value ) )
            {
                continue;
            }

            $field_id = sanitize_key( (string) $field_id );
            if ( '' === $field_id )
            {
                continue;
            }

            $summary[] = [
                'field_id' => $field_id,
                'label'    => sanitize_text_field( ucwords( str_replace( [ '_', '-' ], ' ', $field_id ) ) ),
                'value'    => mb_substr( sanitize_textarea_field( (string) $value ), 0, 300 ),
            ];
        }

        return $summary;
    }

    private function submission_ledger_record_for_entry_identifier( string $form_source, string $form_id, string $entry_id ): ?array
    {
        $ledger = $this->submission_ledger_repository();
        if ( null === $ledger )
        {
            return null;
        }

        $record = $ledger->get_by_submission_uuid( $entry_id );
        if ( is_array( $record ) && $this->submission_ledger_record_matches_form( $record, $form_source, $form_id ) )
        {
            return $record;
        }

        $record = $ledger->get_by_native_entry_id( $form_source, $form_id, $entry_id );
        if ( is_array( $record ) && $this->submission_ledger_record_matches_form( $record, $form_source, $form_id ) )
        {
            return $record;
        }

        return null;
    }

    private function entry_identifier_is_submission_uuid( string $entry_id ): bool
    {
        return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', strtolower( $entry_id ) );
    }

    private function submission_ledger_record_matches_form( array $record, string $form_source, string $form_id ): bool
    {
        return sanitize_key( (string) ( $record['form_source'] ?? '' ) ) === sanitize_key( $form_source )
            && $this->form_ids_match( (string) ( $record['form_id'] ?? '' ), $form_id );
    }

    private function form_ids_match( string $stored_form_id, string $requested_form_id ): bool
    {
        $stored_candidates    = $this->form_id_match_candidates( $stored_form_id );
        $requested_candidates = $this->form_id_match_candidates( $requested_form_id );

        return [] !== array_intersect( $stored_candidates, $requested_candidates );
    }

    /**
     * @return array<int,string>
     */
    private function form_id_match_candidates( string $form_id ): array
    {
        $candidates = [];
        $current    = sanitize_text_field( $form_id );
        for ( $i = 0; $i < 3; ++$i )
        {
            if ( '' !== $current )
            {
                $candidates[] = $current;
            }

            if ( ! str_contains( $current, '%' ) )
            {
                break;
            }

            $decoded = sanitize_text_field( rawurldecode( $current ) );
            if ( $decoded === $current )
            {
                break;
            }

            $current = $decoded;
        }

        if ( class_exists( 'Sentient_Forms_Provider_Form_Id_Keys' ) )
        {
            foreach ( $candidates as $candidate )
            {
                $normalized = Sentient_Forms_Provider_Form_Id_Keys::normalize( $candidate );
                if ( '' !== $normalized )
                {
                    $candidates[] = $normalized;
                }
            }
        }

        return array_values( array_unique( $candidates ) );
    }

    private function adapter_for_source( string $form_source ): ?Sentient_Forms_Adapter_Interface
    {
        $registry = $this->adapter_registry();
        if ( null === $registry )
        {
            return null;
        }

        return $registry->get_adapter_by_id( sanitize_key( $form_source ) );
    }

    private function adapter_registry(): ?Sentient_Forms_Form_Adapter_Registry
    {
        if ( $this->adapter_registry )
        {
            return $this->adapter_registry;
        }

        if ( ! class_exists( 'Sentient_Forms_Plugin' ) )
        {
            return null;
        }

        $plugin = Sentient_Forms_Plugin::instance();
        if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'get_form_adapter_registry' ) )
        {
            return null;
        }

        $this->adapter_registry = $plugin->get_form_adapter_registry();

        return $this->adapter_registry;
    }

    /**
     * @param array<string,mixed> $result
     *
     * @return array{entries: array<int,array<string,mixed>>, form_source: string, form_id: string, availability: array<string,mixed>}
     */
    private function normalize_adapter_search_response( array $result, string $form_source, string $form_id ): array
    {
        $entries = [];
        foreach ( is_array( $result['entries'] ?? null ) ? $result['entries'] : [] as $entry )
        {
            if ( ! is_array( $entry ) )
            {
                continue;
            }

            $normalized = $this->normalize_adapter_entry( $entry );
            if ( '' !== $normalized['id'] )
            {
                $entries[] = $normalized;
            }
        }

        return [
            'entries'      => $entries,
            'form_source'  => sanitize_key( (string) ( $result['form_source'] ?? $form_source ) ),
            'form_id'      => sanitize_text_field( (string) ( $result['form_id'] ?? $form_id ) ),
            'availability' => $this->normalize_adapter_availability( is_array( $result['availability'] ?? null ) ? $result['availability'] : [] ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function normalize_adapter_entry( array $entry ): array
    {
        $id = isset( $entry['id'] ) && is_scalar( $entry['id'] )
            ? sanitize_text_field( (string) $entry['id'] )
            : '';

        $source_type = isset( $entry['source_type'] ) && is_scalar( $entry['source_type'] )
            ? sanitize_key( (string) $entry['source_type'] )
            : 'native';
        if ( ! in_array( $source_type, [ 'native', 'ledger' ], true ) )
        {
            $source_type = 'native';
        }

        return [
            'id'               => $id,
            'source_type'      => $source_type,
            'submission_uuid'  => isset( $entry['submission_uuid'] ) && is_scalar( $entry['submission_uuid'] ) && '' !== trim( (string) $entry['submission_uuid'] )
                ? sanitize_text_field( (string) $entry['submission_uuid'] )
                : null,
            'native_entry_id'  => isset( $entry['native_entry_id'] ) && is_scalar( $entry['native_entry_id'] ) && '' !== trim( (string) $entry['native_entry_id'] )
                ? sanitize_text_field( (string) $entry['native_entry_id'] )
                : null,
            'native_entry_url' => isset( $entry['native_entry_url'] ) && is_scalar( $entry['native_entry_url'] ) && '' !== trim( (string) $entry['native_entry_url'] )
                ? esc_url_raw( (string) $entry['native_entry_url'] )
                : null,
            'date_created'     => isset( $entry['date_created'] ) && is_scalar( $entry['date_created'] ) && '' !== trim( (string) $entry['date_created'] )
                ? sanitize_text_field( (string) $entry['date_created'] )
                : null,
            'status'           => isset( $entry['status'] ) && is_scalar( $entry['status'] ) && '' !== trim( (string) $entry['status'] )
                ? sanitize_key( (string) $entry['status'] )
                : null,
            'field_summary'    => $this->sanitize_field_summary( $entry['field_summary'] ?? [] ),
        ];
    }

    /**
     * @return array<int, array{field_id: string, label: string, value: string}>
     */
    private function sanitize_field_summary( mixed $summary ): array
    {
        if ( ! is_array( $summary ) )
        {
            return [];
        }

        $sanitized = [];
        foreach ( $summary as $field )
        {
            if ( ! is_array( $field ) )
            {
                continue;
            }

            $value = isset( $field['value'] ) && is_scalar( $field['value'] )
                ? trim( (string) $field['value'] )
                : '';
            if ( '' === $value )
            {
                continue;
            }

            $field_id = isset( $field['field_id'] ) && is_scalar( $field['field_id'] )
                ? sanitize_text_field( (string) $field['field_id'] )
                : '';
            $label = isset( $field['label'] ) && is_scalar( $field['label'] )
                ? sanitize_text_field( (string) $field['label'] )
                : $field_id;

            $sanitized[] = [
                'field_id' => '' !== $field_id ? $field_id : 'field_' . ( count( $sanitized ) + 1 ),
                'label'    => '' !== $label ? $label : __( 'Field', 'sentient-forms' ),
                'value'    => mb_substr( sanitize_textarea_field( $value ), 0, 300 ),
            ];

            if ( count( $sanitized ) >= 12 )
            {
                break;
            }
        }

        return $sanitized;
    }

    /**
     * @param array<string,mixed> $availability
     *
     * @return array<string,mixed>
     */
    private function normalize_adapter_availability( array $availability ): array
    {
        $source = isset( $availability['source'] ) && is_scalar( $availability['source'] )
            ? sanitize_key( (string) $availability['source'] )
            : 'native';
        if ( ! in_array( $source, [ 'native', 'ledger' ], true ) )
        {
            $source = 'native';
        }

        return [
            'source'                => $source,
            'native_read'           => ! empty( $availability['native_read'] ),
            'ledger_read'           => ! empty( $availability['ledger_read'] ),
            'ledger_enabled'        => ! empty( $availability['ledger_enabled'] ),
            'unavailable_reason'    => isset( $availability['unavailable_reason'] ) && is_scalar( $availability['unavailable_reason'] ) && '' !== trim( (string) $availability['unavailable_reason'] )
                ? sanitize_key( (string) $availability['unavailable_reason'] )
                : null,
            'allow_ledger_fallback' => ! empty( $availability['allow_ledger_fallback'] ),
        ];
    }

    private function adapter_error_allows_ledger_fallback( WP_Error $error ): bool
    {
        $data = $error->get_error_data();
        return is_array( $data ) && ! empty( $data['allow_ledger_fallback'] );
    }

    /**
     * @return array<string,mixed>
     */
    private function availability_from_error( WP_Error $error ): array
    {
        $data = $error->get_error_data();
        $data = is_array( $data ) ? $data : [];

        return $this->normalize_adapter_availability(
            [
                'source'             => 'native',
                'native_read'        => false,
                'ledger_read'        => false,
                'unavailable_reason' => isset( $data['unavailable_reason'] ) && is_scalar( $data['unavailable_reason'] )
                    ? sanitize_key( (string) $data['unavailable_reason'] )
                    : $error->get_error_code(),
                'allow_ledger_fallback' => ! empty( $data['allow_ledger_fallback'] ),
            ]
        );
    }

    private function submission_ledger_repository(): ?Sentient_Forms_Submission_Ledger_Repository
    {
        if ( $this->ledger )
        {
            return $this->ledger;
        }

        if ( ! class_exists( 'Sentient_Forms_Submission_Ledger_Repository' ) )
        {
            return null;
        }

        global $wpdb;
        $this->ledger = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        return $this->ledger;
    }

    private function submission_ledger_settings_repository(): ?Sentient_Forms_Submission_Ledger_Settings_Repository
    {
        if ( $this->ledger_settings )
        {
            return $this->ledger_settings;
        }

        if ( ! class_exists( 'Sentient_Forms_Submission_Ledger_Settings_Repository' ) )
        {
            return null;
        }

        global $wpdb;
        $this->ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        return $this->ledger_settings;
    }

}
