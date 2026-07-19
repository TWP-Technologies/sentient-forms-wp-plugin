<?php
/**
 * Durable option-backed Action mapping cutover.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Materializes executable legacy mappings in the plugin-owned Action store.
 */
final class Sentient_Forms_Legacy_Action_Authority_Migrator
{
    private const OPTION_PREFIX = 'sentient_forms_actions_';
    private const RELEASED_GRAVITY_OPTION_PREFIX = 'sentient_forms_gravity_forms_';
    private const JOURNAL_OPTION = 'sentient_forms_action_authority_migration_journal';
    private const LOCK_OPTION = 'sentient_forms_action_authority_migration_lock';
    private const LOCK_TTL_SECONDS = 300;

    /** @var array<int, string> */
    private const FORM_SOURCES = [
        'elementor_pro_forms',
        'contact_form_7',
        'gravity_forms',
        'wpforms',
    ];

    private static string $lock_token = '';

    /**
     * @return array<string, int>
     */
    public static function migrate(): array
    {
        $summary = [
            'options_scanned'    => 0,
            'mappings_found'     => 0,
            'mappings_migrated'  => 0,
            'mappings_failed'    => 0,
            'rows_created'       => 0,
            'rows_reused'        => 0,
            'migration_complete' => 0,
        ];

        if ( ! self::acquire_lock() )
        {
            return $summary;
        }

        try
        {
            if ( ! self::resume_journal() )
            {
                return $summary;
            }

            foreach ( self::option_keys() as $option_key )
            {
                $summary['options_scanned']++;
                self::migrate_option( $option_key, $summary );
                if ( false !== get_option( self::JOURNAL_OPTION, false ) )
                {
                    return $summary;
                }
            }

            $summary['migration_complete'] = 0 === $summary['mappings_failed'] ? 1 : 0;
            return $summary;
        }
        finally
        {
            self::release_lock();
        }
    }

    /**
     * @param array<string, int> $summary
     */
    private static function migrate_option( string $option_key, array &$summary ): void
    {
        $identity = self::option_identity( $option_key );
        $stored   = get_option( $option_key, null );
        if ( null === $identity || ! is_array( $stored ) )
        {
            return;
        }

        $wrapped    = isset( $stored['actions'] ) && is_array( $stored['actions'] );
        $collection = $wrapped ? $stored['actions'] : $stored;
        $mappings   = [];
        foreach ( $collection as $key => $candidate )
        {
            if ( ! is_array( $candidate ) || ! self::looks_like_mapping( $candidate ) )
            {
                continue;
            }

            $summary['mappings_found']++;
            $mapping_id = self::mapping_id( $key, $candidate );
            $prepared   = self::prepare_mapping( $mapping_id, $candidate );
            if ( is_wp_error( $prepared ) )
            {
                $summary['mappings_failed']++;
                continue;
            }

            $mappings[ $mapping_id ] = [
                'key'      => $key,
                'payload'  => $candidate,
                'prepared' => $prepared,
            ];
        }

        if ( [] === $mappings )
        {
            return;
        }

        $dependency_error_ids = self::dependency_error_ids( $mappings );
        foreach ( $dependency_error_ids as $mapping_id )
        {
            unset( $mappings[ $mapping_id ] );
            $summary['mappings_failed']++;
        }
        if ( [] === $mappings )
        {
            return;
        }

        global $wpdb;
        $templates = new Sentient_Forms_Action_Templates_Repository( $wpdb );
        $actions   = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $rows      = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $row_index = [];

        foreach ( $mappings as $mapping_id => &$mapping )
        {
            $definition = $mapping['prepared']['definition'];
            $action     = self::ensure_action( $definition, $mapping['prepared']['settings'], $templates, $actions );
            if ( is_wp_error( $action ) )
            {
                $summary['mappings_failed']++;
                unset( $mappings[ $mapping_id ] );
                continue;
            }

            $mapping['action'] = $action;
            foreach ( $mapping['prepared']['hooks'] as $hook )
            {
                $external_id = self::external_id( $option_key, $mapping_id, $hook );
                $existing    = self::find_row_by_external_id( $rows, $identity['form_source'], $identity['form_id'], $external_id );
                $payload     = self::row_payload(
                    $identity,
                    $external_id,
                    $hook,
                    $mapping['prepared'],
                    absint( $action['id'] ?? 0 ),
                    false
                );
                $row = is_array( $existing )
                    ? $rows->update( absint( $existing['id'] ?? 0 ), $payload )
                    : $rows->create( $payload );
                if ( is_wp_error( $row ) )
                {
                    $summary['mappings_failed']++;
                    unset( $mappings[ $mapping_id ] );
                    continue 2;
                }

                $row_id = is_array( $row ) ? absint( $row['id'] ?? 0 ) : absint( $row );
                if ( $row_id <= 0 )
                {
                    $summary['mappings_failed']++;
                    unset( $mappings[ $mapping_id ] );
                    continue 2;
                }

                $summary[ is_array( $existing ) ? 'rows_reused' : 'rows_created' ]++;
                $row_index[ $mapping_id ][ $hook ] = $row_id;
            }
        }
        unset( $mapping );

        if ( [] === $mappings )
        {
            return;
        }

        foreach ( $mappings as $mapping_id => $mapping )
        {
            foreach ( $mapping['prepared']['hooks'] as $hook )
            {
                $row_id   = absint( $row_index[ $mapping_id ][ $hook ] ?? 0 );
                $settings = self::runtime_settings( $mapping['prepared']['settings'], $hook, $row_index );
                if ( is_wp_error( $settings ) || $row_id <= 0 )
                {
                    $summary['mappings_failed']++;
                    unset( $mappings[ $mapping_id ] );
                    continue 2;
                }

                $updated = $rows->update( $row_id, [ 'settings_json' => $settings, 'enabled' => false ] );
                if ( is_wp_error( $updated ) )
                {
                    $summary['mappings_failed']++;
                    unset( $mappings[ $mapping_id ] );
                    continue 2;
                }
            }
        }

        if ( [] === $mappings )
        {
            return;
        }

        $journal_rows = [];
        foreach ( $mappings as $mapping_id => $mapping )
        {
            foreach ( $mapping['prepared']['hooks'] as $hook )
            {
                $row_id = absint( $row_index[ $mapping_id ][ $hook ] ?? 0 );
                if ( $row_id > 0 )
                {
                    $journal_rows[ $row_id ] = ! empty( $mapping['prepared']['enabled'] );
                }
            }
        }

        $journal = [
            'option_key' => $option_key,
            'wrapped'    => $wrapped,
            'mappings'   => [],
            'rows'       => $journal_rows,
        ];
        foreach ( $mappings as $mapping )
        {
            $journal['mappings'][] = [
                'key'  => $mapping['key'],
                'hash' => hash( 'sha256', wp_json_encode( $mapping['payload'] ) ),
            ];
        }

        if ( ! update_option( self::JOURNAL_OPTION, $journal, false ) && $journal !== get_option( self::JOURNAL_OPTION, null ) )
        {
            return;
        }

        if ( self::resume_journal() )
        {
            $summary['mappings_migrated'] += count( $mappings );
        }
    }

    /**
     * Complete the option-swap/row-enable phase after an interrupted request.
     */
    private static function resume_journal(): bool
    {
        $journal = get_option( self::JOURNAL_OPTION, false );
        if ( false === $journal )
        {
            return true;
        }
        if ( ! is_array( $journal ) || ! is_string( $journal['option_key'] ?? null ) )
        {
            return false;
        }

        global $wpdb;
        $rows = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $row_targets = is_array( $journal['rows'] ?? null ) ? $journal['rows'] : [];
        foreach ( $row_targets as $row_id => $enabled )
        {
            if ( ! is_array( $rows->get( absint( $row_id ) ) ) )
            {
                return false;
            }
        }

        $option_key = $journal['option_key'];
        $stored     = get_option( $option_key, null );
        if ( ! is_array( $stored ) )
        {
            return false;
        }

        $wrapped    = ! empty( $journal['wrapped'] );
        $collection = $wrapped && is_array( $stored['actions'] ?? null ) ? $stored['actions'] : $stored;
        foreach ( is_array( $journal['mappings'] ?? null ) ? $journal['mappings'] : [] as $mapping )
        {
            $key = $mapping['key'] ?? null;
            if ( null === $key || ! array_key_exists( $key, $collection ) )
            {
                continue;
            }
            if ( ! is_array( $collection[ $key ] ) )
            {
                return false;
            }
            $current_hash = hash( 'sha256', wp_json_encode( $collection[ $key ] ) );
            if ( ! hash_equals( (string) ( $mapping['hash'] ?? '' ), $current_hash ) )
            {
                return false;
            }
            unset( $collection[ $key ] );
        }

        if ( $wrapped )
        {
            $stored['actions'] = $collection;
        }
        else
        {
            $stored = $collection;
        }
        if ( ! update_option( $option_key, $stored, false ) && $stored !== get_option( $option_key, null ) )
        {
            return false;
        }

        foreach ( $row_targets as $row_id => $enabled )
        {
            if ( is_wp_error( $rows->update( absint( $row_id ), [ 'enabled' => rest_sanitize_boolean( $enabled ) ] ) ) )
            {
                return false;
            }
        }

        return delete_option( self::JOURNAL_OPTION ) || false === get_option( self::JOURNAL_OPTION, false );
    }

    /**
     * @param array<string, array<string, mixed>> $mappings
     * @return array<int, string>
     */
    private static function dependency_error_ids( array $mappings ): array
    {
        $errors = [];
        foreach ( $mappings as $mapping_id => $mapping )
        {
            $settings = $mapping['prepared']['settings'];
            foreach ( $mapping['prepared']['hooks'] as $hook )
            {
                $dependencies = is_array( $settings['dependency_ids'] ?? null ) ? $settings['dependency_ids'] : [];
                $source = is_array( $settings['trigger_sources'][ $hook ] ?? null )
                    ? $settings['trigger_sources'][ $hook ]
                    : [];
                if ( 'mapping' === sanitize_key( (string) ( $source['type'] ?? '' ) ) )
                {
                    $dependencies[] = $source['mapping_id'] ?? '';
                }

                foreach ( $dependencies as $dependency_id )
                {
                    $dependency_id = sanitize_text_field( (string) $dependency_id );
                    if ( '' === $dependency_id || str_starts_with( $dependency_id, 'local_first_' ) )
                    {
                        continue;
                    }
                    if ( ! isset( $mappings[ $dependency_id ] ) || ! in_array( $hook, $mappings[ $dependency_id ]['prepared']['hooks'], true ) )
                    {
                        $errors[] = $mapping_id;
                        break 2;
                    }
                }
            }
        }

        return array_values( array_unique( $errors ) );
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private static function prepare_mapping( string $mapping_id, array $mapping ): array | WP_Error
    {
        $action_code = sanitize_key( (string) ( $mapping['central_action_id'] ?? '' ) );
        $definition  = Sentient_Forms_Bundled_Action_Templates::get( $action_code );
        if ( '' === $mapping_id || ! is_array( $definition ) )
        {
            return new WP_Error( 'sentient_forms_unconvertible_legacy_action' );
        }

        $settings = is_array( $mapping['settings'] ?? null ) ? $mapping['settings'] : [];
        $input_policy = self::legacy_input_projection_policy( $settings['input_mapping'] ?? null );
        if ( is_wp_error( $input_policy ) )
        {
            return $input_policy;
        }
        $hooks = Sentient_Forms_Form_Source_Lifecycles::normalize_many(
            is_array( $mapping['trigger_hooks'] ?? null )
                ? $mapping['trigger_hooks']
                : ( is_array( $settings['trigger_hooks'] ?? null ) ? $settings['trigger_hooks'] : [] )
        );
        if ( [] === $hooks )
        {
            $hooks = Sentient_Forms_Form_Source_Lifecycles::normalize_many(
                is_array( $definition['hooks'] ?? null ) ? $definition['hooks'] : []
            );
        }
        if ( [] === $hooks )
        {
            return new WP_Error( 'sentient_forms_unconvertible_legacy_hooks' );
        }

        $eligible = Sentient_Forms_Form_Source_Lifecycles::normalize_many(
            is_array( $definition['hooks'] ?? null ) ? $definition['hooks'] : []
        );
        if ( [] !== array_diff( $hooks, $eligible ) )
        {
            return new WP_Error( 'sentient_forms_ineligible_legacy_hooks' );
        }

        $trigger_sources = is_array( $settings['trigger_sources'] ?? null ) ? $settings['trigger_sources'] : [];
        $settings['trigger_sources'] = Sentient_Forms_Form_Source_Lifecycles::normalize_keyed_array( $trigger_sources );

        return [
            'definition' => $definition,
            'settings'   => $settings,
            'hooks'      => $hooks,
            'enabled'    => array_key_exists( 'is_action_enabled_for_form', $mapping )
                ? rest_sanitize_boolean( $mapping['is_action_enabled_for_form'] )
                : true,
        ];
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private static function ensure_action(
        array $definition,
        array $settings,
        Sentient_Forms_Action_Templates_Repository $templates,
        Sentient_Forms_Local_Custom_Actions_Repository $actions
    ): array | WP_Error
    {
        $code = sanitize_key( (string) ( $definition['code'] ?? '' ) );
        $template_id = $templates->upsert_by_code(
            [
                'source'                   => $definition['source'] ?? 'bundled',
                'code'                     => $code,
                'display_name'             => $definition['display_name'] ?? $code,
                'description'              => $definition['description'] ?? null,
                'prompt_template'          => $definition['prompt_template'] ?? '',
                'default_model'            => $definition['default_model'] ?? 'openrouter/auto',
                'structured_output_schema' => $definition['structured_output_schema'] ?? null,
                'override_schema'          => $definition['override_schema'] ?? null,
                'version'                  => $definition['version'] ?? '1',
                'is_active'                => ! empty( $definition['is_active'] ),
            ]
        );
        if ( is_wp_error( $template_id ) )
        {
            return $template_id;
        }

        $managed_code = Sentient_Forms_Bundled_Action_Templates::build_managed_custom_action_code( $code );
        $existing = $actions->get_by_code( $managed_code );
        if ( is_array( $existing ) )
        {
            return $existing;
        }

        $selection = is_array( $settings['model_selection'] ?? null ) ? $settings['model_selection'] : null;
        if ( null === $selection )
        {
            $selection = ( new Sentient_Forms_Provider_Path_Policy_Service() )->build_bundled_action_model_selection( $definition );
            if ( is_wp_error( $selection ) )
            {
                $selection = null;
            }
        }

        $action_definition = is_array( $definition['definition_json'] ?? null ) ? $definition['definition_json'] : [];
        $action_definition['code'] = $code;
        if ( isset( $definition['description'] ) && is_scalar( $definition['description'] ) )
        {
            $action_definition['description'] = sanitize_textarea_field( (string) $definition['description'] );
        }
        foreach ( [ 'action_policy', 'allowed_facets', 'enabled_facets' ] as $field )
        {
            if ( is_array( $definition[ $field ] ?? null ) )
            {
                $action_definition[ $field ] = $definition[ $field ];
            }
        }

        $action_id = $actions->upsert_by_code(
            [
                'template_id'          => absint( $template_id ),
                'code'                 => $managed_code,
                'display_name'         => $definition['display_name'] ?? $code,
                'definition_json'      => $action_definition,
                'model_selection_json' => $selection,
                'status'               => 'active',
            ]
        );
        if ( is_wp_error( $action_id ) )
        {
            return $action_id;
        }

        return $actions->get( absint( $action_id ) )
            ?? new WP_Error( 'sentient_forms_migrated_action_missing' );
    }

    /**
     * @param array{form_source:string,form_id:string} $identity
     * @param array<string, mixed> $prepared
     * @return array<string, mixed>
     */
    private static function row_payload(
        array $identity,
        string $external_id,
        string $hook,
        array $prepared,
        int $action_id,
        bool $enabled
    ): array
    {
        $settings   = $prepared['settings'];
        $definition = $prepared['definition'];
        $input_mapping = is_array( $settings['input_mapping'] ?? null ) ? $settings['input_mapping'] : [];
        $input_policy  = self::legacy_input_projection_policy( $input_mapping );
        $effects = is_array( $definition['effect_mapping_json'] ?? null ) ? $definition['effect_mapping_json'] : [];
        if ( is_array( $settings['effect_mapping_json'] ?? null ) )
        {
            $effects = array_replace_recursive( $effects, $settings['effect_mapping_json'] );
        }

        return [
            'external_id'         => $external_id,
            'form_source'         => $identity['form_source'],
            'form_id'             => $identity['form_id'],
            'hook'                => $hook,
            'action_kind'         => 'custom_action',
            'action_id'           => $action_id,
            'conditions_json'     => is_array( $settings['conditions'] ?? null ) ? $settings['conditions'] : null,
            'input_bindings_json' => is_array( $input_policy ) ? [] : $input_mapping,
            'execution_mode'      => self::execution_mode( $hook, $settings, $definition ),
            'effect_mapping_json' => $effects,
            'settings_json'       => self::runtime_settings_without_dependencies( $settings ),
            'enabled'             => $enabled,
        ];
    }

    /**
     * @param array<string, array<string, int>> $row_index
     * @return array<string, mixed>|WP_Error
     */
    private static function runtime_settings( array $settings, string $hook, array $row_index ): array | WP_Error
    {
        $runtime = self::runtime_settings_without_dependencies( $settings );
        $source  = is_array( $settings['trigger_sources'][ $hook ] ?? null )
            ? $settings['trigger_sources'][ $hook ]
            : [ 'type' => 'hook_root' ];
        $remapped_source = self::remap_source( $source, $hook, $row_index );
        if ( is_wp_error( $remapped_source ) )
        {
            return $remapped_source;
        }
        $runtime['trigger_sources'] = [ $hook => $remapped_source ];

        if ( is_array( $settings['dependency_ids'] ?? null ) )
        {
            $dependency_ids = [];
            foreach ( $settings['dependency_ids'] as $dependency_id )
            {
                $dependency_id = sanitize_text_field( (string) $dependency_id );
                if ( str_starts_with( $dependency_id, 'local_first_' ) )
                {
                    $dependency_ids[] = $dependency_id;
                    continue;
                }
                $row_id = absint( $row_index[ $dependency_id ][ $hook ] ?? 0 );
                if ( $row_id <= 0 )
                {
                    return new WP_Error( 'sentient_forms_unresolved_legacy_dependency' );
                }
                $dependency_ids[] = 'local_first_' . $row_id;
            }
            $runtime['dependency_ids'] = array_values( array_unique( $dependency_ids ) );
        }

        return $runtime;
    }

    /**
     * @param array<string, array<string, int>> $row_index
     * @return array<string, mixed>|WP_Error
     */
    private static function remap_source( array $source, string $hook, array $row_index ): array | WP_Error
    {
        if ( 'mapping' !== sanitize_key( (string) ( $source['type'] ?? '' ) ) )
        {
            return [ 'type' => 'hook_root' ];
        }
        $mapping_id = sanitize_text_field( (string) ( $source['mapping_id'] ?? '' ) );
        if ( str_starts_with( $mapping_id, 'local_first_' ) )
        {
            return [ 'type' => 'mapping', 'mapping_id' => $mapping_id ];
        }
        $row_id = absint( $row_index[ $mapping_id ][ $hook ] ?? 0 );
        if ( $row_id <= 0 )
        {
            return new WP_Error( 'sentient_forms_unresolved_legacy_trigger_source' );
        }

        return [ 'type' => 'mapping', 'mapping_id' => 'local_first_' . $row_id ];
    }

    /** @return array<string, mixed> */
    private static function runtime_settings_without_dependencies( array $settings ): array
    {
        $input_policy = self::legacy_input_projection_policy( $settings['input_mapping'] ?? null );
        foreach (
            [
                'conditions',
                'effect_mapping_json',
                'execution_mode',
                'input_mapping',
                'is_action_enabled_for_form',
                'local_form_mapping_id',
                'trigger_hooks',
                'trigger_sources',
                'dependency_ids',
            ] as $key
        )
        {
            unset( $settings[ $key ] );
        }

        if ( is_array( $input_policy ) )
        {
            $settings['input_mapping'] = $input_policy;
        }

        return $settings;
    }

    /** @return array<string, mixed>|WP_Error|null */
    private static function legacy_input_projection_policy( mixed $value ): array | WP_Error | null
    {
        if ( ! is_array( $value ) || ! array_key_exists( 'mode', $value ) )
        {
            return null;
        }

        $mode = is_scalar( $value['mode'] ) ? sanitize_key( (string) $value['mode'] ) : '';
        if ( ! in_array( $mode, [ 'all', 'selected', 'exclude' ], true ) )
        {
            return null;
        }

        $has_field_ids        = array_key_exists( 'field_ids', $value );
        $has_include_metadata = array_key_exists( 'include_metadata', $value );
        $extra_keys           = array_diff( array_keys( $value ), [ 'mode', 'field_ids', 'include_metadata' ] );

        if ( ! $has_field_ids && ! $has_include_metadata )
        {
            return [] === $extra_keys ? $value : null;
        }

        if ( $has_field_ids && ! is_array( $value['field_ids'] ) && ! $has_include_metadata )
        {
            return null;
        }

        if (
            [] !== $extra_keys
            || ( $has_field_ids && ! is_array( $value['field_ids'] ) )
            || ( $has_include_metadata && ! is_bool( $value['include_metadata'] ) )
        )
        {
            return new WP_Error( 'sentient_forms_malformed_legacy_input_projection' );
        }

        foreach ( $value['field_ids'] ?? [] as $field_id )
        {
            if ( is_bool( $field_id ) || ! is_scalar( $field_id ) || '' === trim( (string) $field_id ) )
            {
                return new WP_Error( 'sentient_forms_malformed_legacy_input_projection' );
            }
        }

        return $value;
    }

    private static function execution_mode( string $hook, array $settings, array $definition ): string
    {
        if ( Sentient_Forms_Form_Source_Lifecycles::VALIDATION === $hook )
        {
            return 'sync';
        }
        if ( Sentient_Forms_Form_Source_Lifecycles::REAL_TIME === $hook )
        {
            return 'real_time';
        }
        if ( array_key_exists( 'async', $settings ) )
        {
            return rest_sanitize_boolean( $settings['async'] ) ? 'async' : 'sync';
        }
        $configured = sanitize_key( (string) ( $settings['execution_mode'] ?? $definition['default_execution_mode'] ?? 'async' ) );

        return in_array( $configured, [ 'validation', 'sync' ], true ) ? 'sync' : 'async';
    }

    /** @return array<string, mixed>|null */
    private static function option_identity( string $option_key ): ?array
    {
        if ( str_starts_with( $option_key, self::RELEASED_GRAVITY_OPTION_PREFIX ) )
        {
            $form_id = Sentient_Forms_Provider_Form_Id_Keys::decode_option_suffix(
                'gravity_forms',
                substr( $option_key, strlen( self::RELEASED_GRAVITY_OPTION_PREFIX ) )
            );

            return Sentient_Forms_Provider_Form_Id_Keys::is_valid( $form_id )
                ? [ 'form_source' => 'gravity_forms', 'form_id' => $form_id ]
                : null;
        }

        if ( ! str_starts_with( $option_key, self::OPTION_PREFIX ) )
        {
            return null;
        }

        $tail = substr( $option_key, strlen( self::OPTION_PREFIX ) );
        foreach ( self::FORM_SOURCES as $form_source )
        {
            $prefix = $form_source . '_';
            if ( ! str_starts_with( $tail, $prefix ) )
            {
                continue;
            }
            $form_id = Sentient_Forms_Provider_Form_Id_Keys::decode_option_suffix( $form_source, substr( $tail, strlen( $prefix ) ) );
            if ( ! Sentient_Forms_Provider_Form_Id_Keys::is_valid( $form_id ) )
            {
                return null;
            }

            return [ 'form_source' => $form_source, 'form_id' => $form_id ];
        }

        return null;
    }

    private static function mapping_id( int | string $key, array $mapping ): string
    {
        foreach ( [ 'local_mapping_id', 'id' ] as $id_key )
        {
            if ( isset( $mapping[ $id_key ] ) && is_scalar( $mapping[ $id_key ] ) )
            {
                $id = sanitize_text_field( (string) $mapping[ $id_key ] );
                if ( '' !== $id )
                {
                    return $id;
                }
            }
        }

        return sanitize_text_field( (string) $key );
    }

    private static function looks_like_mapping( array $mapping ): bool
    {
        return isset( $mapping['central_action_id'] )
            && ! isset( $mapping['local_form_mapping_id'] );
    }

    private static function external_id( string $option_key, string $mapping_id, string $hook ): string
    {
        return 'legacy_action_authority_' . substr( hash( 'sha256', $option_key . '|' . $mapping_id . '|' . $hook ), 0, 40 );
    }

    private static function find_row_by_external_id(
        Sentient_Forms_Form_Mappings_Repository $rows,
        string $form_source,
        string $form_id,
        string $external_id
    ): ?array
    {
        foreach ( $rows->list_for_form( $form_source, $form_id ) as $row )
        {
            if ( $external_id === ( $row['external_id'] ?? null ) )
            {
                return $row;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private static function option_keys(): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time legacy migration must enumerate current plugin-owned option names by prefix; WordPress exposes no option-name query API and cached results could omit concurrent legacy rows.
        $keys = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name ASC',
                $wpdb->options,
                $wpdb->esc_like( self::OPTION_PREFIX ) . '%',
                $wpdb->esc_like( self::RELEASED_GRAVITY_OPTION_PREFIX ) . '%'
            )
        );

        if ( ! is_array( $keys ) )
        {
            return [];
        }

        $filtered = [];
        foreach ( array_filter( $keys, 'is_string' ) as $option_key )
        {
            if ( str_starts_with( $option_key, self::RELEASED_GRAVITY_OPTION_PREFIX ) )
            {
                $identity = self::option_identity( $option_key );
                if ( is_array( $identity ) )
                {
                    $canonical_key = self::OPTION_PREFIX
                        . 'gravity_forms_'
                        . Sentient_Forms_Provider_Form_Id_Keys::option_suffix( $identity['form_id'] );
                    if ( ! empty( get_option( $canonical_key, [] ) ) )
                    {
                        continue;
                    }
                }
            }

            $filtered[] = $option_key;
        }

        return array_values( $filtered );
    }

    private static function acquire_lock(): bool
    {
        $now        = time();
        $existing   = get_option( self::LOCK_OPTION, null );
        $created    = is_array( $existing ) ? absint( $existing['created_at'] ?? 0 ) : 0;
        $lock_token = wp_generate_uuid4();
        $candidate  = [ 'token' => $lock_token, 'created_at' => $now ];
        if ( $created > 0 && $created + self::LOCK_TTL_SECONDS > $now )
        {
            return false;
        }

        if ( null === $existing )
        {
            if ( ! add_option( self::LOCK_OPTION, $candidate, '', false ) )
            {
                return false;
            }

            self::$lock_token = $lock_token;
            return true;
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic byte-exact compare-and-swap is required so concurrent migration workers cannot replace each other's plugin-owned lock value; the option cache is invalidated after success.
        $updated = $wpdb->update(
            $wpdb->options,
            [ 'option_value' => maybe_serialize( $candidate ) ],
            [
                'option_name'  => self::LOCK_OPTION,
                'option_value' => maybe_serialize( $existing ),
            ],
            [ '%s' ],
            [ '%s', '%s' ]
        );
        if ( 1 !== $updated )
        {
            return false;
        }

        wp_cache_delete( self::LOCK_OPTION, 'options' );
        self::$lock_token = $lock_token;
        return true;
    }

    private static function release_lock(): void
    {
        $lock = get_option( self::LOCK_OPTION, null );
        if ( is_array( $lock ) && hash_equals( self::$lock_token, (string) ( $lock['token'] ?? '' ) ) )
        {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic byte-exact delete is required so an expired worker cannot release a newer worker's plugin-owned lock; the option cache is invalidated immediately afterward.
            $wpdb->delete(
                $wpdb->options,
                [
                    'option_name'  => self::LOCK_OPTION,
                    'option_value' => maybe_serialize( $lock ),
                ],
                [ '%s', '%s' ]
            );
            wp_cache_delete( self::LOCK_OPTION, 'options' );
        }
        self::$lock_token = '';
    }
}
