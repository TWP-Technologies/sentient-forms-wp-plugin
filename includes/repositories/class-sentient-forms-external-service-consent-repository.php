<?php
/**
 * Local external-service disclosure consent repository.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Local-first consent records live in a plugin-owned custom table. WordPress core has no native CRUD/cache API for these rows; SQL is prepared and table names are escaped at each call site.
class Sentient_Forms_External_Service_Consent_Repository extends Sentient_Forms_Local_Repository
{
    protected function table_name(): string
    {
        return $this->wpdb->prefix . 'sentient_external_service_consents';
    }

    public function record( string $provider, string $disclosure_version, ?int $user_id = null, ?array $metadata = null ): int | WP_Error
    {
        $provider           = sanitize_key( $provider );
        $disclosure_version = sanitize_text_field( $disclosure_version );

        if ( '' === $provider || '' === $disclosure_version )
        {
            return new WP_Error( 'sentient_forms_invalid_consent', __( 'Provider and disclosure version are required.', 'sentient-forms' ) );
        }

        $metadata_json = $this->encode_json_field( $metadata, 'metadata_json' );
        if ( is_wp_error( $metadata_json ) )
        {
            return $metadata_json;
        }

        $inserted = $this->wpdb->insert(
            $this->table_name(),
            [
                'provider'            => $provider,
                'disclosure_version'  => $disclosure_version,
                'accepted_by_user_id' => $user_id,
                'accepted_at'         => $this->now(),
                'site_url_hash'       => hash( 'sha256', home_url() ),
                'metadata_json'       => $metadata_json,
            ],
            [ '%s', '%s', '%d', '%s', '%s', '%s' ]
        );

        if ( false === $inserted )
        {
            return new WP_Error( 'sentient_forms_db_insert_failed', __( 'Consent could not be recorded.', 'sentient-forms' ) );
        }

        return (int) $this->wpdb->insert_id;
    }

    public function latest_for_provider( string $provider ): ?array
    {
        $wpdb = $this->wpdb;
        $row  = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE provider = %s ORDER BY accepted_at DESC, id DESC LIMIT 1',
                $this->table_name(),
                sanitize_key( $provider )
            ),
            ARRAY_A
        );
        if ( ! $row )
        {
            return null;
        }

        $row['metadata_json'] = $this->decode_json_field( $row['metadata_json'] ?? null );
        return $row;
    }
}
