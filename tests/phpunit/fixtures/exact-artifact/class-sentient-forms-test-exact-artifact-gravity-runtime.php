<?php

/**
 * Minimal Gravity Forms runtime boundary for isolated exact-artifact tests.
 *
 * @package Sentient_Forms
 */

if ( ! class_exists( 'Sentient_Forms_Test_Exact_Artifact_Gravity_Meta_Store' ) )
{
    final class Sentient_Forms_Test_Exact_Artifact_Gravity_Meta_Store
    {
        /** @var array<int, array<string, mixed>> */
        private static array $meta = [];

        public static function get( int $entry_id, string $key ): mixed
        {
            return self::$meta[ $entry_id ][ $key ] ?? null;
        }

        public static function set( int $entry_id, string $key, mixed $value ): void
        {
            self::$meta[ $entry_id ][ $key ] = $value;
        }
    }
}

if ( ! function_exists( 'gform_get_meta' ) )
{
    function gform_get_meta( mixed $entry_id, mixed $meta_key ): mixed
    {
        return Sentient_Forms_Test_Exact_Artifact_Gravity_Meta_Store::get( (int) $entry_id, (string) $meta_key );
    }
}

if ( ! function_exists( 'gform_update_meta' ) )
{
    function gform_update_meta( mixed $entry_id, mixed $meta_key, mixed $value ): bool
    {
        Sentient_Forms_Test_Exact_Artifact_Gravity_Meta_Store::set( (int) $entry_id, (string) $meta_key, $value );

        return true;
    }
}

if ( ! class_exists( 'GFForms' ) )
{
    class GFForms
    {
    }
}

if ( ! class_exists( 'GFFormsModel' ) )
{
    class GFFormsModel
    {
        /** @var array<int, array<string, mixed>> */
        public static array $notes = [];

        public static function add_note(
            mixed $entry_id,
            mixed $user_id,
            mixed $user_name,
            mixed $note,
            mixed $note_type = ''
        ): bool
        {
            self::$notes[] = [
                'entry_id'  => (int) $entry_id,
                'user_id'   => (int) $user_id,
                'user_name' => (string) $user_name,
                'note'      => (string) $note,
                'note_type' => (string) $note_type,
            ];

            return true;
        }

        /** @return array<int, object> */
        public static function get_lead_notes( mixed $entry_id ): array
        {
            return array_map(
                static fn( array $note ): object => (object) $note,
                array_values(
                    array_filter(
                        self::$notes,
                        static fn( array $note ): bool => (int) $entry_id === (int) ( $note['entry_id'] ?? 0 )
                    )
                )
            );
        }
    }
}

if ( ! class_exists( 'GFAPI' ) )
{
    class GFAPI
    {
        /** @var array<int, array<string, mixed>> */
        public static array $entries = [];

        /** @var array<int, array<string, mixed>> */
        public static array $forms = [];

        public static function get_entry( mixed $entry_id ): array | WP_Error
        {
            return self::$entries[ (int) $entry_id ]
                ?? new WP_Error( 'rest_entry_not_found', 'Entry not found.' );
        }

        public static function get_form( mixed $form_id ): array | false
        {
            return self::$forms[ (int) $form_id ] ?? false;
        }

        /** @return array<int, array<string, mixed>> */
        public static function get_forms(): array
        {
            return array_values( self::$forms );
        }

        public static function update_form( mixed $form, mixed $form_id = null ): bool | WP_Error
        {
            $resolved_id = null === $form_id && is_array( $form )
                ? absint( $form['id'] ?? 0 )
                : absint( $form_id );
            if ( $resolved_id <= 0 || ! is_array( $form ) )
            {
                return new WP_Error( 'missing_form_id', 'Missing form id.' );
            }
            $form['id'] = $resolved_id;
            self::$forms[ $resolved_id ] = $form;

            return true;
        }

        public static function update_entry( mixed $entry ): bool | WP_Error
        {
            if ( ! is_array( $entry ) || absint( $entry['id'] ?? 0 ) <= 0 )
            {
                return new WP_Error( 'missing_entry_id', 'Missing entry id.' );
            }
            self::$entries[ (int) $entry['id'] ] = $entry;

            return true;
        }

        public static function update_entry_field( mixed $entry_id, mixed $field_id, mixed $value ): bool | WP_Error
        {
            $entry_id = absint( $entry_id );
            if ( ! isset( self::$entries[ $entry_id ] ) )
            {
                return new WP_Error( 'rest_entry_not_found', 'Entry not found.' );
            }
            self::$entries[ $entry_id ][ (string) $field_id ] = $value;

            return true;
        }

        public static function update_entry_property( mixed $entry_id, mixed $property, mixed $value ): bool | WP_Error
        {
            $entry_id = absint( $entry_id );
            if ( ! isset( self::$entries[ $entry_id ] ) )
            {
                return new WP_Error( 'rest_entry_not_found', 'Entry not found.' );
            }
            self::$entries[ $entry_id ][ (string) $property ] = $value;

            return true;
        }
    }
}
