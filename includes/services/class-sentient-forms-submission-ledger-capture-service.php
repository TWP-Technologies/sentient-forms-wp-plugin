<?php
/**
 * Submission ledger capture service.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Submission_Ledger_Capture_Service
{
    private Sentient_Forms_Submission_Ledger_Settings_Repository $settings;

    private Sentient_Forms_Submission_Ledger_Repository $ledger;

    public function __construct(
        private wpdb $wpdb,
        ?Sentient_Forms_Submission_Ledger_Settings_Repository $settings = null,
        ?Sentient_Forms_Submission_Ledger_Repository $ledger = null
    )
    {
        $this->settings = $settings ?? new Sentient_Forms_Submission_Ledger_Settings_Repository( $this->wpdb );
        $this->ledger   = $ledger ?? new Sentient_Forms_Submission_Ledger_Repository( $this->wpdb );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function capture( array $payload ): array | WP_Error
    {
        $form_source = sanitize_key( (string) ( $payload['form_source'] ?? '' ) );
        $form_id     = sanitize_text_field( (string) ( $payload['form_id'] ?? '' ) );

        if ( '' === $form_source || '' === $form_id )
        {
            return new WP_Error( 'sentient_forms_invalid_submission_ledger_scope', __( 'Submission ledger capture requires a form source and form ID.', 'sentient-forms' ) );
        }

        $settings = $this->settings->get_or_default( $form_source, $form_id );
        if ( empty( $settings['enabled'] ) )
        {
            return new WP_Error( 'sentient_forms_submission_ledger_disabled', __( 'Submission ledger storage is disabled for this form.', 'sentient-forms' ) );
        }

        $redacted_fields = [];
        $logical_fields  = isset( $payload['logical_fields'] ) && is_array( $payload['logical_fields'] )
            ? $this->redact_array( $payload['logical_fields'], $redacted_fields )
            : [];
        $metadata        = isset( $payload['provider_metadata'] ) && is_array( $payload['provider_metadata'] )
            ? $this->redact_array( $payload['provider_metadata'], $redacted_fields )
            : null;
        $file_refs       = isset( $payload['files'] ) && is_array( $payload['files'] )
            ? $this->sanitize_file_references( $payload['files'] )
            : null;

        $supplied_submission_uuid = $this->normalize_submission_uuid( $payload['submission_uuid'] ?? null );
        $submission_uuid          = $supplied_submission_uuid ?? wp_generate_uuid4();
        $native_entry_id          = isset( $payload['native_entry_id'] ) && is_scalar( $payload['native_entry_id'] )
            ? sanitize_text_field( (string) $payload['native_entry_id'] )
            : '';
        if ( null !== $supplied_submission_uuid )
        {
            $existing = $this->ledger->get_by_submission_uuid( $supplied_submission_uuid );
            if ( is_array( $existing ) )
            {
                return $this->resolve_idempotent_replay( $existing, $form_source, $form_id, $payload );
            }
        }

        if ( '' !== $native_entry_id )
        {
            $native_correlation_hash = Sentient_Forms_Submission_Ledger_Repository::native_correlation_hash( $form_source, $form_id, $native_entry_id );
            if ( null === $native_correlation_hash )
            {
                return new WP_Error( 'sentient_forms_invalid_native_correlation', __( 'Submission ledger correlation identity could not be created.', 'sentient-forms' ) );
            }

            $existing = $this->ledger->get_by_native_correlation_hash( $native_correlation_hash );
            if ( is_array( $existing ) )
            {
                return $this->resolve_idempotent_replay( $existing, $form_source, $form_id, $payload );
            }

            $legacy = $this->ledger->get_earliest_by_native_entry_id( $form_source, $form_id, $native_entry_id );
            if ( is_array( $legacy ) )
            {
                $assigned = $this->ledger->assign_native_correlation_hash( (int) ( $legacy['id'] ?? 0 ), $native_correlation_hash );
                $winner   = $this->ledger->get_by_native_correlation_hash( $native_correlation_hash );
                if ( is_array( $winner ) )
                {
                    return $this->resolve_idempotent_replay( $winner, $form_source, $form_id, $payload );
                }
                if ( is_wp_error( $assigned ) )
                {
                    return $assigned;
                }

                return new WP_Error( 'sentient_forms_native_correlation_missing', __( 'Submission ledger correlation identity could not be read after repair.', 'sentient-forms' ) );
            }
        }
        else
        {
            $native_correlation_hash = null;
        }

        $created = $this->ledger->create(
            [
                'submission_uuid'        => $submission_uuid,
                'form_source'            => $form_source,
                'form_id'                => $form_id,
                'native_entry_id'        => '' !== $native_entry_id ? $native_entry_id : null,
                'native_entry_url'       => isset( $payload['native_entry_url'] ) ? esc_url_raw( (string) $payload['native_entry_url'] ) : null,
                'source_submitted_at'    => isset( $payload['source_submitted_at'] ) ? sanitize_text_field( (string) $payload['source_submitted_at'] ) : null,
                'logical_fields_json'    => $logical_fields,
                'provider_metadata_json' => $metadata,
                'file_refs_json'         => $file_refs,
                'redaction_summary_json' => [
                    'redacted_fields' => array_values( array_unique( $redacted_fields ) ),
                    'file_ref_count'  => is_array( $file_refs ) ? count( $file_refs ) : 0,
                ],
                'expires_at'             => isset( $payload['expires_at'] ) ? sanitize_text_field( (string) $payload['expires_at'] ) : null,
            ]
        );

        if ( is_wp_error( $created ) )
        {
            if ( null !== $supplied_submission_uuid )
            {
                $existing = $this->ledger->get_by_submission_uuid( $supplied_submission_uuid );
                if ( is_array( $existing ) )
                {
                    return $this->resolve_idempotent_replay( $existing, $form_source, $form_id, $payload );
                }
            }

            if ( null !== $native_correlation_hash )
            {
                $existing = $this->ledger->get_by_native_correlation_hash( $native_correlation_hash );
                if ( is_array( $existing ) )
                {
                    return $this->resolve_idempotent_replay( $existing, $form_source, $form_id, $payload );
                }
            }

            return $created;
        }

        $stored = $this->ledger->get_by_submission_uuid( $submission_uuid );
        if ( null === $stored )
        {
            return new WP_Error( 'sentient_forms_submission_ledger_capture_missing', __( 'Submission ledger record could not be read after capture.', 'sentient-forms' ) );
        }

        return $stored;
    }

    /**
     * Return a previously captured record only when its stable identity agrees
     * with the replaying request. UUID reuse across scopes or native entries is
     * an explicit conflict rather than a masked database duplicate.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|WP_Error
     */
    private function resolve_idempotent_replay(
        array $existing,
        string $form_source,
        string $form_id,
        array $payload
    ): array | WP_Error
    {
        $existing_form_source = sanitize_key( (string) ( $existing['form_source'] ?? '' ) );
        $existing_form_id     = sanitize_text_field( (string) ( $existing['form_id'] ?? '' ) );
        $existing_entry_id    = isset( $existing['native_entry_id'] ) && is_scalar( $existing['native_entry_id'] )
            ? sanitize_text_field( (string) $existing['native_entry_id'] )
            : '';
        $requested_entry_id   = isset( $payload['native_entry_id'] ) && is_scalar( $payload['native_entry_id'] )
            ? sanitize_text_field( (string) $payload['native_entry_id'] )
            : '';
        $scope_matches        = $existing_form_source === $form_source && $existing_form_id === $form_id;
        $entry_matches        = $existing_entry_id === $requested_entry_id;

        if ( ! $scope_matches || ! $entry_matches )
        {
            return new WP_Error(
                'sentient_forms_submission_ledger_replay_conflict',
                __( 'Submission ledger correlation conflicts with an existing submission.', 'sentient-forms' )
            );
        }

        return $existing;
    }

    private function normalize_submission_uuid( mixed $submission_uuid ): ?string
    {
        if ( ! is_scalar( $submission_uuid ) )
        {
            return null;
        }

        $submission_uuid = strtolower( sanitize_text_field( (string) $submission_uuid ) );
        return wp_is_uuid( $submission_uuid ) ? $submission_uuid : null;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, string>   $redacted_fields
     *
     * @return array<string, mixed>
     */
    private function redact_array( array $values, array &$redacted_fields ): array
    {
        $redacted = [];

        foreach ( $values as $key => $value )
        {
            $field_key = is_scalar( $key ) ? sanitize_key( (string) $key ) : '';
            if ( '' === $field_key )
            {
                continue;
            }

            if ( $this->is_sensitive_key( $field_key ) )
            {
                $redacted[ $field_key ] = '[redacted]';
                $redacted_fields[]      = $field_key;
                continue;
            }

            if ( is_object( $value ) )
            {
                $value = $this->normalize_object_value( $value );
            }

            if ( is_array( $value ) )
            {
                $redacted[ $field_key ] = $this->redact_array( $value, $redacted_fields );
                continue;
            }

            if ( is_scalar( $value ) )
            {
                $redacted[ $field_key ] = is_string( $value )
                    ? sanitize_textarea_field( $value )
                    : $value;
            }
        }

        return $redacted;
    }

    private function normalize_object_value( object $value ): mixed
    {
        if ( $value instanceof JsonSerializable )
        {
            return $value->jsonSerialize();
        }

        return get_object_vars( $value );
    }

    private function is_sensitive_key( string $key ): bool
    {
        foreach ( [ 'captcha', 'csrf', 'nonce', 'honeypot', 'token', 'secret', 'password', 'payment', 'card', 'webhook', 'raw_request', 'raw_provider', 'internal' ] as $needle )
        {
            if ( false !== strpos( $key, $needle ) )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $files
     *
     * @return array<int, array<string, mixed>>
     */
    private function sanitize_file_references( array $files ): array
    {
        $references = [];

        foreach ( $files as $file )
        {
            if ( ! is_array( $file ) )
            {
                continue;
            }

            $reference = [];
            foreach ( [ 'field_id', 'filename', 'name', 'url', 'mime_type', 'type', 'size' ] as $key )
            {
                if ( ! isset( $file[ $key ] ) || ! is_scalar( $file[ $key ] ) )
                {
                    continue;
                }

                $target_key = match ( $key ) {
                    'name' => 'filename',
                    'type' => 'mime_type',
                    default => $key,
                };

                $reference[ $target_key ] = 'url' === $target_key
                    ? esc_url_raw( (string) $file[ $key ] )
                    : sanitize_text_field( (string) $file[ $key ] );
            }

            if ( [] !== $reference )
            {
                $references[] = $reference;
            }
        }

        return $references;
    }
}
