<?php
/**
 * Base helpers for local-first custom table repositories.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Local-first records live in plugin-owned custom tables. WordPress core has no native CRUD/cache API for these tables; SQL is prepared and table names are escaped at each call site.
abstract class Sentient_Forms_Local_Repository
{
    public function __construct( protected wpdb $wpdb )
    {
    }

    abstract protected function table_name(): string;

    protected function now(): string
    {
        return current_time( 'mysql', true );
    }

    /**
     * Serialize local-state mutations with authority migration and approved reset.
     *
     * @template T
     * @param callable():T $operation
     * @return T|WP_Error
     */
    protected function with_local_state_write_lock( callable $operation ): mixed
    {
        return Sentient_Forms_Legacy_Action_Authority_Migrator::with_option_write_lock( $operation );
    }

    protected function encode_json_field( mixed $value, string $field_name, bool $required = false ): string | null | WP_Error
    {
        if ( null === $value )
        {
            if ( $required )
            {
                return new WP_Error(
                    'sentient_forms_missing_json_field',
                    sprintf(
                        /* translators: %s: JSON field name. */
                        __( '%s is required.', 'sentient-forms' ),
                        $field_name
                    )
                );
            }

            return null;
        }

        if ( ! is_array( $value ) )
        {
            return new WP_Error(
                'sentient_forms_invalid_json_field',
                sprintf(
                    /* translators: %s: JSON field name. */
                    __( '%s must be an array.', 'sentient-forms' ),
                    $field_name
                )
            );
        }

        $encoded = wp_json_encode( $value );
        if ( false === $encoded )
        {
            return new WP_Error(
                'sentient_forms_json_encode_failed',
                sprintf(
                    /* translators: %s: JSON field name. */
                    __( '%s could not be encoded as JSON.', 'sentient-forms' ),
                    $field_name
                )
            );
        }

        return $encoded;
    }

    protected function decode_json_field( ?string $value ): ?array
    {
        if ( null === $value || '' === $value )
        {
            return null;
        }

        $decoded = json_decode( $value, true );
        return is_array( $decoded ) ? $decoded : null;
    }

    protected function get_by_id( int $id ): ?array
    {
        $wpdb = $this->wpdb;
        $row  = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE id = %d',
                $this->table_name(),
                $id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }
}
