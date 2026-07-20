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
    private const LOCK_MODE_MIGRATION = 'migration';
    private const LOCK_MODE_RESET = 'reset';
    private const LOCK_MODE_WRITER = 'writer';

    /** @var array<int, string> */
    private const FORM_SOURCES = [
        'elementor_pro_forms',
        'contact_form_7',
        'gravity_forms',
        'wpforms',
    ];

    private static string $lock_token = '';

    private static string $lock_mode = '';

    private static int $lock_connection_id = 0;

    private static ?wpdb $dedicated_lock_database = null;

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

        if ( ! self::acquire_lock( self::LOCK_MODE_MIGRATION ) )
        {
            return $summary;
        }

        try
        {
            if ( ! self::owns_database_lock() )
            {
                return $summary;
            }

            if ( ! self::resume_journal( true ) )
            {
                return $summary;
            }
            if ( ! self::owns_database_lock() )
            {
                return $summary;
            }

            foreach ( self::option_keys() as $option_key )
            {
                if ( ! self::owns_database_lock() )
                {
                    return $summary;
                }
                $summary['options_scanned']++;
                self::migrate_option( $option_key, $summary );
                if ( ! self::owns_database_lock() || false !== get_option( self::JOURNAL_OPTION, false ) )
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
     * Serialize plugin-owned legacy option writers with the authority cutover.
     *
     * @template T
     * @param callable():T $operation
     * @return T|WP_Error
     */
    public static function with_option_write_lock( callable $operation ): mixed
    {
        if ( in_array( self::$lock_mode, [ self::LOCK_MODE_MIGRATION, self::LOCK_MODE_WRITER ], true ) && '' !== self::$lock_token )
        {
            if ( ! self::owns_database_lock() || ( self::LOCK_MODE_WRITER === self::$lock_mode && self::has_pending_journal() ) )
            {
                self::clear_lock_state();
                return self::write_locked_error();
            }

            $result = $operation();
            return self::owns_database_lock() ? $result : self::write_locked_error();
        }

        if ( ! self::acquire_lock( self::LOCK_MODE_WRITER ) )
        {
            return self::write_locked_error();
        }

        try
        {
            if ( ! self::owns_database_lock() )
            {
                return self::write_locked_error();
            }
            if ( self::has_pending_journal() )
            {
                return self::write_locked_error();
            }

            $result = $operation();
            return self::owns_database_lock() ? $result : self::write_locked_error();
        }
        finally
        {
            self::release_lock();
        }
    }

    /**
     * Run a destructive reset while denying every normal local-state writer.
     *
     * @template T
     * @param callable():T $operation
     * @return T|WP_Error
     */
    public static function with_exclusive_reset_lock( callable $operation ): mixed
    {
        if ( '' !== self::$lock_token || ! self::acquire_lock( self::LOCK_MODE_RESET ) )
        {
            return self::write_locked_error();
        }

        try
        {
            if ( ! self::owns_database_lock() )
            {
                return self::write_locked_error();
            }

            $result = $operation();
            return self::owns_database_lock() ? $result : self::write_locked_error();
        }
        finally
        {
            self::release_lock();
        }
    }

    /**
     * Persist one legacy Action option at the concrete adapter write boundary.
     *
     * @param array<string, mixed> $value Option payload.
     */
    public static function update_action_option( string $option_key, array $value ): bool | WP_Error
    {
        if ( ! str_starts_with( $option_key, self::OPTION_PREFIX ) )
        {
            return new WP_Error(
                'sentient_forms_invalid_action_option_key',
                __( 'The Action configuration option key is invalid.', 'sentient-forms' ),
                [ 'status' => 500 ]
            );
        }

        return self::with_option_write_lock(
            static fn(): bool => update_option( $option_key, $value, false )
        );
    }

    /**
     * Whether an interrupted option swap still owns the named option key.
     */
    public static function has_pending_journal(): bool
    {
        return false !== get_option( self::JOURNAL_OPTION, false );
    }

    /**
     * @param array<string, int> $summary
     */
    private static function migrate_option( string $option_key, array &$summary ): void
    {
        $identity = self::option_identity( $option_key );
        $snapshot = self::read_option_snapshot( $option_key );
        $stored   = is_array( $snapshot ) ? $snapshot['value'] : null;
        if ( null === $identity || ! is_array( $snapshot ) || ! is_array( $stored ) )
        {
            return;
        }

        global $wpdb;
        $templates = new Sentient_Forms_Action_Templates_Repository( $wpdb );
        $actions   = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $rows      = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $wrapped   = isset( $stored['actions'] ) && is_array( $stored['actions'] );
        $candidates = self::logical_mapping_candidates( $stored, $wrapped );
        $mappings   = [];
        foreach ( $candidates as $mapping_id => $candidate )
        {
            $summary['mappings_found']++;
            $payload    = $candidate['payload'];
            $prepared   = self::prepare_mapping( $mapping_id, $payload, $actions );
            if ( is_wp_error( $prepared ) )
            {
                $summary['mappings_failed']++;
                continue;
            }

            $mappings[ $mapping_id ] = [
                'key'      => $candidate['key'],
                'payload'  => $payload,
                'copies'   => $candidate['copies'],
                'prepared' => $prepared,
            ];
        }

        if ( [] === $mappings )
        {
            return;
        }

        self::prune_dependency_errors( $mappings, $summary );
        if ( [] === $mappings )
        {
            return;
        }

        $row_index = [];

        foreach ( $mappings as $mapping_id => &$mapping )
        {
            $definition = $mapping['prepared']['definition'];
            $action     = is_array( $mapping['prepared']['existing_action'] ?? null )
                ? $mapping['prepared']['existing_action']
                : self::ensure_action( $definition, $mapping['prepared']['settings'], $templates, $actions );
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

        self::prune_dependency_errors( $mappings, $summary );
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

        self::prune_dependency_errors( $mappings, $summary );
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
            'option_key'   => $option_key,
            'option_value' => $snapshot['raw'],
            'wrapped'      => $wrapped,
            'mappings'     => [],
            'rows'         => $journal_rows,
        ];
        foreach ( $mappings as $mapping )
        {
            $journal['mappings'][] = [
                'key'    => $mapping['key'],
                'hash'   => hash( 'sha256', wp_json_encode( $mapping['payload'] ) ),
                'copies' => $mapping['copies'],
            ];
        }

        if ( ! update_option( self::JOURNAL_OPTION, $journal, false ) && $journal !== get_option( self::JOURNAL_OPTION, null ) )
        {
            return;
        }

        if ( self::resume_journal( false ) )
        {
            $summary['mappings_migrated'] += count( $mappings );
        }
    }

    /**
     * Complete the option-swap/row-enable phase after an interrupted request.
     */
    private static function resume_journal( bool $abandon_stale = false ): bool
    {
        if ( ! self::owns_database_lock() )
        {
            return false;
        }

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
        $snapshot   = self::read_option_snapshot( $option_key );
        if ( ! is_array( $snapshot ) )
        {
            return $abandon_stale ? self::abandon_journal( $journal ) : false;
        }

        $legacy_journal = ! is_string( $journal['option_value'] ?? null );
        $stored         = $legacy_journal
            ? $snapshot['value']
            : maybe_unserialize( $journal['option_value'] );
        if ( ! is_array( $stored ) )
        {
            return false;
        }

        if ( $legacy_journal && self::legacy_wrapped_journal_has_root_alias( $journal, $stored ) )
        {
            return $abandon_stale ? self::abandon_journal( $journal ) : false;
        }

        $expected_option_value = $legacy_journal ? $snapshot['raw'] : $journal['option_value'];

        $wrapped = ! empty( $journal['wrapped'] );
        foreach ( is_array( $journal['mappings'] ?? null ) ? $journal['mappings'] : [] as $mapping )
        {
            $copies = is_array( $mapping['copies'] ?? null )
                ? $mapping['copies']
                : [
                    [
                        'scope' => $wrapped ? 'actions' : 'root',
                        'key'   => $mapping['key'] ?? null,
                        'hash'  => $mapping['hash'] ?? '',
                    ],
                ];

            foreach ( $copies as $copy )
            {
                $scope = sanitize_key( (string) ( $copy['scope'] ?? '' ) );
                $key   = $copy['key'] ?? null;
                if ( null === $key )
                {
                    return false;
                }

                if ( 'actions' === $scope )
                {
                    if ( ! isset( $stored['actions'] ) || ! is_array( $stored['actions'] ) )
                    {
                        continue;
                    }
                    if ( ! array_key_exists( $key, $stored['actions'] ) )
                    {
                        continue;
                    }
                    if ( ! is_array( $stored['actions'][ $key ] ) )
                    {
                        return false;
                    }
                    $current_hash = hash( 'sha256', wp_json_encode( $stored['actions'][ $key ] ) );
                    if ( ! hash_equals( (string) ( $copy['hash'] ?? '' ), $current_hash ) )
                    {
                        return false;
                    }
                    unset( $stored['actions'][ $key ] );
                    continue;
                }

                if ( 'root' !== $scope )
                {
                    return false;
                }
                if ( ! array_key_exists( $key, $stored ) )
                {
                    continue;
                }
                if ( ! is_array( $stored[ $key ] ) )
                {
                    return false;
                }
                $current_hash = hash( 'sha256', wp_json_encode( $stored[ $key ] ) );
                if ( ! hash_equals( (string) ( $copy['hash'] ?? '' ), $current_hash ) )
                {
                    return false;
                }
                unset( $stored[ $key ] );
            }
        }

        $target_option_value = maybe_serialize( $stored );
        $current_option_value = $snapshot['raw'];
        if ( ! hash_equals( $expected_option_value, $current_option_value ) )
        {
            if ( ! hash_equals( $target_option_value, $current_option_value ) )
            {
                return $abandon_stale ? self::abandon_journal( $journal ) : false;
            }
        }
        elseif ( ! hash_equals( $target_option_value, $current_option_value ) )
        {
            if ( ! self::owns_database_lock() )
            {
                return false;
            }
            if ( ! self::compare_and_swap_option( $option_key, $expected_option_value, $target_option_value ) )
            {
                return false;
            }
            if ( ! self::owns_database_lock() )
            {
                return false;
            }
        }

        foreach ( $row_targets as $row_id => $enabled )
        {
            if ( ! self::owns_database_lock() )
            {
                return false;
            }
            if ( is_wp_error( $rows->update( absint( $row_id ), [ 'enabled' => rest_sanitize_boolean( $enabled ) ] ) ) )
            {
                return false;
            }
            if ( ! self::owns_database_lock() )
            {
                return false;
            }
        }

        if ( ! self::owns_database_lock() )
        {
            return false;
        }
        $deleted = self::compare_and_delete_option( self::JOURNAL_OPTION, maybe_serialize( $journal ) )
            || false === get_option( self::JOURNAL_OPTION, false );

        return $deleted && self::owns_database_lock();
    }

    /**
     * Read the exact persisted option bytes without trusting the object cache.
     *
     * @return array{raw:string,value:mixed}|null
     */
    private static function read_option_snapshot( string $option_key ): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact persisted bytes are required for the migration journal compare-and-swap boundary; a cached option value is not authoritative here.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT option_value FROM %i WHERE option_name = %s',
                $wpdb->options,
                $option_key
            ),
            ARRAY_A
        );
        if ( ! is_array( $row ) || ! is_string( $row['option_value'] ?? null ) )
        {
            return null;
        }

        return [
            'raw'   => $row['option_value'],
            'value' => maybe_unserialize( $row['option_value'] ),
        ];
    }

    /**
     * Replace an option only when its persisted name and value bytes still
     * match the snapshot observed by the caller.
     */
    private static function compare_and_swap_option( string $option_key, string $expected, string $replacement ): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The option-backed control-plane cutover requires one byte-exact compare-and-swap so concurrent writers cannot be overwritten.
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET `option_value` = %s WHERE BINARY `option_name` = BINARY %s AND BINARY `option_value` = BINARY %s',
                $wpdb->options,
                $replacement,
                $option_key,
                $expected
            )
        );
        if ( 1 !== $updated )
        {
            return false;
        }

        wp_cache_delete( $option_key, 'options' );
        return true;
    }

    /** Delete only the exact persisted option row observed by the caller. */
    private static function compare_and_delete_option( string $option_key, string $expected ): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Journal and lock ownership require an exact conditional delete rather than a collation-aware options API write.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE BINARY `option_name` = BINARY %s AND BINARY `option_value` = BINARY %s',
                $wpdb->options,
                $option_key,
                $expected
            )
        );
        if ( 1 !== $deleted )
        {
            return false;
        }

        wp_cache_delete( $option_key, 'options' );
        return true;
    }

    /**
     * Old wrapped journals did not record top-level aliases. Rebuild them from
     * current top-level-wins truth instead of transiently enabling stale rows.
     */
    private static function legacy_wrapped_journal_has_root_alias( array $journal, array $stored ): bool
    {
        if ( empty( $journal['wrapped'] ) )
        {
            return false;
        }

        $mapping_ids = [];
        foreach ( is_array( $journal['mappings'] ?? null ) ? $journal['mappings'] : [] as $mapping )
        {
            $mapping_key = $mapping['key'] ?? '';
            $payload     = isset( $stored['actions'][ $mapping_key ] ) && is_array( $stored['actions'][ $mapping_key ] )
                ? $stored['actions'][ $mapping_key ]
                : [];
            $mapping_id  = self::mapping_id( $mapping_key, $payload );
            if ( '' !== $mapping_id )
            {
                $mapping_ids[ $mapping_id ] = true;
            }
        }

        foreach ( $stored as $key => $payload )
        {
            if ( 'actions' === $key || ! is_array( $payload ) )
            {
                continue;
            }
            $mapping_id = self::mapping_id( $key, $payload );
            if ( isset( $mapping_ids[ $mapping_id ] ) )
            {
                return true;
            }
        }

        return false;
    }

    private static function abandon_journal( array $journal ): bool
    {
        if ( ! self::owns_database_lock() )
        {
            return false;
        }

        global $wpdb;
        $rows = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        foreach ( is_array( $journal['rows'] ?? null ) ? array_keys( $journal['rows'] ) : [] as $row_id )
        {
            if ( ! self::owns_database_lock() )
            {
                return false;
            }

            $disabled = $rows->update( absint( $row_id ), [ 'enabled' => false ] );
            if ( is_wp_error( $disabled ) || ! self::owns_database_lock() )
            {
                return false;
            }
        }

        return self::compare_and_delete_option( self::JOURNAL_OPTION, maybe_serialize( $journal ) );
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
                    if (
                        ! isset( $mappings[ $dependency_id ] )
                        || null === self::dependency_hook_for( $hook, $mappings[ $dependency_id ]['prepared']['hooks'] )
                    )
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
     * Remove invalid dependency chains to a stable fixpoint while rows are disabled.
     *
     * @param array<string, array<string, mixed>> $mappings
     * @param array<string, int>                  $summary
     */
    private static function prune_dependency_errors( array &$mappings, array &$summary ): void
    {
        do
        {
            $dependency_error_ids = self::dependency_error_ids( $mappings );
            foreach ( $dependency_error_ids as $mapping_id )
            {
                if ( ! isset( $mappings[ $mapping_id ] ) )
                {
                    continue;
                }

                unset( $mappings[ $mapping_id ] );
                $summary['mappings_failed']++;
            }
        }
        while ( [] !== $dependency_error_ids && [] !== $mappings );
    }

    /**
     * Resolve the dependency lifecycle that satisfies a required runtime hook.
     *
     * Validation dependencies can satisfy after-submission dependants because
     * synchronous validation completes before asynchronous submission work begins.
     * An exact lifecycle match always takes precedence when both rows exist.
     *
     * @param array<int, mixed> $dependency_hooks
     */
    private static function dependency_hook_for( string $required_hook, array $dependency_hooks ): ?string
    {
        $required_hook = sanitize_key( $required_hook );
        $normalized_dependency_hooks = Sentient_Forms_Form_Source_Lifecycles::normalize_many( $dependency_hooks );

        if ( in_array( $required_hook, $normalized_dependency_hooks, true ) )
        {
            return $required_hook;
        }

        if (
            Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION === $required_hook
            && in_array( Sentient_Forms_Form_Source_Lifecycles::VALIDATION, $normalized_dependency_hooks, true )
        )
        {
            return Sentient_Forms_Form_Source_Lifecycles::VALIDATION;
        }

        return null;
    }

    /**
     * @param array<string, array<string, int>> $row_index
     */
    private static function dependency_row_id( array $row_index, string $mapping_id, string $required_hook ): int
    {
        if ( ! isset( $row_index[ $mapping_id ] ) || ! is_array( $row_index[ $mapping_id ] ) )
        {
            return 0;
        }

        $dependency_hook = self::dependency_hook_for( $required_hook, array_keys( $row_index[ $mapping_id ] ) );
        if ( null === $dependency_hook )
        {
            return 0;
        }

        return absint( $row_index[ $mapping_id ][ $dependency_hook ] ?? 0 );
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private static function prepare_mapping(
        string $mapping_id,
        array $mapping,
        Sentient_Forms_Local_Custom_Actions_Repository $actions
    ): array | WP_Error
    {
        $action_code = sanitize_key( (string) ( $mapping['central_action_id'] ?? '' ) );
        $definition  = Sentient_Forms_Bundled_Action_Templates::get( $action_code );
        $existing_action = null;
        if ( '' === $mapping_id )
        {
            return new WP_Error( 'sentient_forms_unconvertible_legacy_action' );
        }

        if ( ! is_array( $definition ) )
        {
            $action_type = sanitize_key( (string) ( $mapping['action_type_indicator'] ?? '' ) );
            if ( 'custom' !== $action_type )
            {
                return new WP_Error( 'sentient_forms_unconvertible_legacy_action' );
            }

            $action_id = absint( $mapping['action_id'] ?? 0 );
            if ( $action_id > 0 )
            {
                $existing_action = $actions->get( $action_id );
            }
            if ( ! is_array( $existing_action ) && '' !== $action_code )
            {
                $existing_action = $actions->get_by_code( $action_code );
            }
            $status = is_array( $existing_action )
                ? sanitize_key( (string) ( $existing_action['status'] ?? '' ) )
                : '';
            $mapping_enabled = ! empty( $mapping['is_action_enabled_for_form'] );
            if (
                ! is_array( $existing_action )
                || ( 'active' !== $status && ! ( 'archived' === $status && ! $mapping_enabled ) )
            )
            {
                return new WP_Error( 'sentient_forms_unconvertible_legacy_action' );
            }

            $resolved_code = sanitize_key( (string) ( $existing_action['code'] ?? '' ) );
            if ( '' !== $action_code && $resolved_code !== $action_code )
            {
                return new WP_Error( 'sentient_forms_legacy_custom_action_identity_mismatch' );
            }

            $definition = is_array( $existing_action['definition_json'] ?? null )
                ? $existing_action['definition_json']
                : [];
            $definition['code']         = $resolved_code;
            $definition['display_name'] = sanitize_text_field( (string) ( $existing_action['display_name'] ?? $resolved_code ) );
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
            $hooks = is_array( $existing_action )
                ? [ Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION ]
                : Sentient_Forms_Form_Source_Lifecycles::normalize_many(
                    is_array( $definition['hooks'] ?? null ) ? $definition['hooks'] : []
                );
        }
        if ( [] === $hooks )
        {
            return new WP_Error( 'sentient_forms_unconvertible_legacy_hooks' );
        }

        if ( is_array( $existing_action ) )
        {
            if ( in_array( Sentient_Forms_Form_Source_Lifecycles::REAL_TIME, $hooks, true ) )
            {
                return new WP_Error( 'sentient_forms_ineligible_legacy_hooks' );
            }
        }
        else
        {
            $eligible = Sentient_Forms_Form_Source_Lifecycles::normalize_many(
                is_array( $definition['hooks'] ?? null ) ? $definition['hooks'] : []
            );
            if ( [] !== array_diff( $hooks, $eligible ) )
            {
                return new WP_Error( 'sentient_forms_ineligible_legacy_hooks' );
            }
        }

        $trigger_sources = is_array( $settings['trigger_sources'] ?? null ) ? $settings['trigger_sources'] : [];
        $settings['trigger_sources'] = Sentient_Forms_Form_Source_Lifecycles::normalize_keyed_array( $trigger_sources );

        return [
            'definition' => $definition,
            'settings'   => $settings,
            'hooks'      => $hooks,
            'existing_action' => $existing_action,
            'enabled'    => array_key_exists( 'is_action_enabled_for_form', $mapping )
                ? rest_sanitize_boolean( $mapping['is_action_enabled_for_form'] )
                : true,
        ];
    }

    /**
     * Build one logical mapping set while retaining every physical option alias.
     * Top-level mappings intentionally override stale wrapped copies.
     *
     * @param array<string, mixed> $stored
     * @return array<string, array{key:int|string,payload:array<string,mixed>,copies:array<int,array{scope:string,key:int|string,hash:string}>}>
     */
    private static function logical_mapping_candidates( array $stored, bool $wrapped ): array
    {
        $candidates = [];
        $register = static function ( array &$target, string $scope, int | string $key, array $payload ): void {
            if ( ! self::looks_like_mapping( $payload ) )
            {
                return;
            }

            $mapping_id = self::mapping_id( $key, $payload );
            if ( '' === $mapping_id )
            {
                return;
            }

            if ( ! isset( $target[ $mapping_id ] ) )
            {
                $target[ $mapping_id ] = [
                    'key'     => $key,
                    'payload' => $payload,
                    'copies'  => [],
                ];
            }

            $target[ $mapping_id ]['copies'][] = [
                'scope' => $scope,
                'key'   => $key,
                'hash'  => hash( 'sha256', wp_json_encode( $payload ) ),
            ];
            if ( 'root' === $scope )
            {
                $target[ $mapping_id ]['key']     = $key;
                $target[ $mapping_id ]['payload'] = $payload;
            }
        };

        if ( $wrapped )
        {
            foreach ( $stored['actions'] as $key => $payload )
            {
                if ( is_array( $payload ) )
                {
                    $register( $candidates, 'actions', $key, $payload );
                }
            }
        }

        foreach ( $stored as $key => $payload )
        {
            if ( 'actions' === (string) $key || ! is_array( $payload ) )
            {
                continue;
            }
            $register( $candidates, 'root', $key, $payload );
        }

        return $candidates;
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
                $row_id = self::dependency_row_id( $row_index, $dependency_id, $hook );
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
        $row_id = self::dependency_row_id( $row_index, $mapping_id, $hook );
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

    private static function acquire_lock( string $mode ): bool
    {
        if ( '' !== self::$lock_token || ! in_array( $mode, [ self::LOCK_MODE_MIGRATION, self::LOCK_MODE_RESET, self::LOCK_MODE_WRITER ], true ) )
        {
            return false;
        }

        $lock_database = self::lock_database_for_acquisition( $mode );
        if ( ! $lock_database instanceof wpdb )
        {
            return false;
        }

        $timeout = self::LOCK_MODE_WRITER === $mode
            ? max( 0, min( 5, (int) apply_filters( 'sentient_forms_action_authority_writer_lock_timeout', 1 ) ) )
            : 0;
        $acquired = $lock_database->get_var(
            $lock_database->prepare( 'SELECT GET_LOCK(%s, %d)', self::database_lock_name(), $timeout )
        );
        if ( 1 !== (int) $acquired )
        {
            return false;
        }

        $connection_id = (int) $lock_database->get_var( 'SELECT CONNECTION_ID()' );
        $owner         = (int) $lock_database->get_var(
            $lock_database->prepare( 'SELECT IS_USED_LOCK(%s)', self::database_lock_name() )
        );
        if ( $connection_id <= 0 || $connection_id !== $owner )
        {
            $lock_database->get_var( $lock_database->prepare( 'SELECT RELEASE_LOCK(%s)', self::database_lock_name() ) );
            return false;
        }

        $lock_token = wp_generate_uuid4();
        $candidate  = [
            'token'         => $lock_token,
            'mode'          => $mode,
            'connection_id' => $connection_id,
            'created_at'    => time(),
        ];
        self::$lock_token         = $lock_token;
        self::$lock_mode          = $mode;
        self::$lock_connection_id = $connection_id;

        if ( self::LOCK_MODE_WRITER === $mode )
        {
            return true;
        }

        if ( ! update_option( self::LOCK_OPTION, $candidate, false ) && $candidate !== get_option( self::LOCK_OPTION, null ) )
        {
            self::release_lock();
            return false;
        }

        return true;
    }

    private static function release_lock(): void
    {
        $lock_token = self::$lock_token;
        $lock_mode  = self::$lock_mode;
        $lock       = self::LOCK_MODE_WRITER === $lock_mode ? null : get_option( self::LOCK_OPTION, null );
        if ( '' !== $lock_token && is_array( $lock ) && hash_equals( $lock_token, (string) ( $lock['token'] ?? '' ) ) )
        {
            self::compare_and_delete_option( self::LOCK_OPTION, maybe_serialize( $lock ) );
        }

        $lock_database = self::$dedicated_lock_database;
        if ( $lock_database instanceof wpdb && '' !== $lock_token )
        {
            $lock_database->get_var( $lock_database->prepare( 'SELECT RELEASE_LOCK(%s)', self::database_lock_name() ) );
        }
        self::clear_lock_state();
    }

    private static function owns_database_lock(): bool
    {
        if ( '' === self::$lock_token || self::$lock_connection_id <= 0 )
        {
            return false;
        }

        $lock_database = self::$dedicated_lock_database;
        if ( ! $lock_database instanceof wpdb )
        {
            return false;
        }

        $owner = $lock_database->get_var(
            $lock_database->prepare( 'SELECT IS_USED_LOCK(%s)', self::database_lock_name() )
        );

        return self::$lock_connection_id === (int) $owner;
    }

    private static function database_lock_name(): string
    {
        global $wpdb;
        $database = isset( $wpdb ) && is_object( $wpdb ) && property_exists( $wpdb, 'dbname' )
            ? (string) $wpdb->dbname
            : ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' );
        $options_table = isset( $wpdb ) && is_object( $wpdb ) && property_exists( $wpdb, 'options' )
            ? (string) $wpdb->options
            : '';

        return 'sf_action_authority_' . substr( hash( 'sha256', $database . '|' . $options_table ), 0, 40 );
    }

    private static function lock_database_for_acquisition( string $mode ): ?wpdb
    {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! $wpdb instanceof wpdb )
        {
            return null;
        }

        /**
         * Supply a dedicated lock connection for database-routing or replicated topologies.
         *
         * The returned connection must route writes to the same primary database as the
         * supplied WordPress connection. Returning the primary connection is rejected
         * because reconnecting it would release the named lock mid-mutation.
         *
         * @param wpdb|null $lock_database Existing/default dedicated connection.
         * @param wpdb      $primary       WordPress mutation connection.
         * @param string    $mode          Lock mode.
         */
        $filtered = apply_filters(
            'sentient_forms_action_authority_lock_database',
            self::$dedicated_lock_database,
            $wpdb,
            $mode
        );
        if ( $filtered instanceof wpdb && $filtered !== $wpdb )
        {
            if ( ! $filtered->check_connection( false ) )
            {
                return null;
            }
            self::$dedicated_lock_database = $filtered;
            return self::$dedicated_lock_database;
        }
        if ( null !== $filtered )
        {
            return null;
        }
        if ( self::$dedicated_lock_database instanceof wpdb )
        {
            if ( ! self::$dedicated_lock_database->check_connection( false ) )
            {
                self::$dedicated_lock_database = null;
                return null;
            }
            return self::$dedicated_lock_database;
        }
        if ( wpdb::class !== get_class( $wpdb ) )
        {
            return null;
        }
        if ( ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) || ! defined( 'DB_HOST' ) )
        {
            return null;
        }

        self::$dedicated_lock_database = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
        return self::$dedicated_lock_database;
    }

    private static function clear_lock_state(): void
    {
        self::$lock_token         = '';
        self::$lock_mode          = '';
        self::$lock_connection_id = 0;
    }

    private static function write_locked_error(): WP_Error
    {
        return new WP_Error(
            'sentient_forms_action_authority_write_locked',
            __( 'Sentient Forms local state is being migrated or reset. Please retry the request.', 'sentient-forms' ),
            [ 'status' => 409 ]
        );
    }
}
