<?php
/**
 * Submission ledger settings repository.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Submission ledger settings live in a plugin-owned custom table. WordPress core has no native CRUD/cache API for these rows; SQL is prepared and table names are escaped at each call site.
class Sentient_Forms_Submission_Ledger_Settings_Repository extends Sentient_Forms_Local_Repository
{
    protected function table_name(): string
    {
        return $this->wpdb->prefix . 'sentient_submission_ledger_settings';
    }

    public function get_or_default( string $form_source, string $form_id ): array
    {
        $form_source = sanitize_key( $form_source );
        $form_id     = sanitize_text_field( $form_id );
        $row         = $this->get_for_form( $form_source, $form_id );

        if ( null !== $row )
        {
            return $row;
        }

        return [
            'id'                  => null,
            'form_source'         => $form_source,
            'form_id'             => $form_id,
            'enabled'             => false,
            'enabled_at'          => null,
            'enabled_by_user_id'  => null,
            'disabled_at'         => null,
            'disabled_by_user_id' => null,
            'created_at'          => null,
            'updated_at'          => null,
        ];
    }

    public function set_enabled( string $form_source, string $form_id, bool $enabled, ?int $user_id = null ): array | WP_Error
    {
        $form_source = sanitize_key( $form_source );
        $form_id     = sanitize_text_field( $form_id );
        if ( '' === $form_source || '' === $form_id )
        {
            return new WP_Error( 'sentient_forms_invalid_ledger_settings_scope', __( 'Ledger settings require a form source and form ID.', 'sentient-forms' ) );
        }

        $now       = $this->now();
        $actor_id  = null !== $user_id && $user_id > 0 ? absint( $user_id ) : null;
        $existing  = $this->get_for_form( $form_source, $form_id );
        $base_data = [
            'enabled'             => $enabled ? 1 : 0,
            'enabled_at'          => $enabled ? $now : ( $existing['enabled_at'] ?? null ),
            'enabled_by_user_id'  => $enabled ? $actor_id : ( $existing['enabled_by_user_id'] ?? null ),
            'disabled_at'         => $enabled ? null : $now,
            'disabled_by_user_id' => $enabled ? null : $actor_id,
            'updated_at'          => $now,
        ];

        if ( null === $existing )
        {
            $inserted = $this->wpdb->insert(
                $this->table_name(),
                array_merge(
                    [
                        'form_source' => $form_source,
                        'form_id'     => $form_id,
                        'created_at'  => $now,
                    ],
                    $base_data
                ),
                [ '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s' ]
            );

            if ( false === $inserted )
            {
                return new WP_Error( 'sentient_forms_db_insert_failed', __( 'Ledger settings could not be created.', 'sentient-forms' ) );
            }
        }
        else
        {
            $updated = $this->wpdb->update(
                $this->table_name(),
                $base_data,
                [
                    'form_source' => $form_source,
                    'form_id'     => $form_id,
                ],
                [ '%d', '%s', '%d', '%s', '%d', '%s' ],
                [ '%s', '%s' ]
            );

            if ( false === $updated )
            {
                return new WP_Error( 'sentient_forms_db_update_failed', __( 'Ledger settings could not be updated.', 'sentient-forms' ) );
            }
        }

        $row = $this->get_for_form( $form_source, $form_id );
        if ( null === $row )
        {
            return new WP_Error( 'sentient_forms_ledger_settings_not_found', __( 'Ledger settings could not be found after update.', 'sentient-forms' ) );
        }

        return $row;
    }

    public function count_enabled(): int
    {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE enabled = 1',
                $this->table_name()
            )
        );
    }

    private function get_for_form( string $form_source, string $form_id ): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM %i WHERE form_source = %s AND form_id = %s LIMIT 1',
                $this->table_name(),
                sanitize_key( $form_source ),
                sanitize_text_field( $form_id )
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $this->decode_row( $row ) : null;
    }

    private function decode_row( array $row ): array
    {
        $row['id']                  = absint( $row['id'] ?? 0 );
        $row['enabled']             = ! empty( $row['enabled'] );
        $row['enabled_by_user_id']  = null !== ( $row['enabled_by_user_id'] ?? null ) ? absint( $row['enabled_by_user_id'] ) : null;
        $row['disabled_by_user_id'] = null !== ( $row['disabled_by_user_id'] ?? null ) ? absint( $row['disabled_by_user_id'] ) : null;

        return $row;
    }
}
