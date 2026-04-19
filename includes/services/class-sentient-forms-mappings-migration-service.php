<?php
/**
 * CPS mapping migration service.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The one-time mapping migration discovers legacy plugin option keys directly from the options table. SQL is prepared and the table name is escaped at each call site.
class Sentient_Forms_Mappings_Migration_Service
{
    private const OPTION_PREFIX = 'sentient_forms_actions_';

    private Sentient_Forms_Api_Client $client;

    private string $api_key;

    private string $site_id;

    public function __construct(
        ?Sentient_Forms_Api_Client $client = null,
        ?string $api_key = null,
        ?string $site_id = null
    )
    {
        $plugin       = Sentient_Forms_Plugin::instance();
        $license_data = $plugin->get_license_data();
        $license_site = isset( $license_data['site_id'] ) && is_scalar( $license_data['site_id'] )
            ? sanitize_text_field( (string) $license_data['site_id'] )
            : '';
        $option_site  = sanitize_text_field( (string) get_option( 'sentient_forms_site_id', '' ) );

        $this->client  = $client ?: $plugin->get_cps_api_client();
        $this->api_key = null !== $api_key
            ? sanitize_text_field( $api_key )
            : sanitize_text_field( $plugin->get_proxy_api_key() );

        $candidate_site_id = '';
        if ( null !== $site_id && '' !== trim( $site_id ) )
        {
            $candidate_site_id = sanitize_text_field( $site_id );
        }
        elseif ( '' !== $license_site )
        {
            $candidate_site_id = $license_site;
        }
        elseif ( '' !== $option_site )
        {
            $candidate_site_id = $option_site;
        }

        $this->site_id = $this->is_uuid( $candidate_site_id ) ? $candidate_site_id : '';
    }

    /**
     * @param array{
     *   apply?: bool,
     *   include_disabled?: bool,
     *   form_source?: string|null,
     *   form_id?: int|null
     * } $args
     * @return array|WP_Error
     */
    public function migrate( array $args = [] )
    {
        $apply            = ! empty( $args['apply'] );
        $include_disabled = ! empty( $args['include_disabled'] );
        $form_source      = isset( $args['form_source'] ) && is_scalar( $args['form_source'] )
            ? sanitize_key( (string) $args['form_source'] )
            : null;
        $form_id          = isset( $args['form_id'] ) && null !== $args['form_id']
            ? absint( $args['form_id'] )
            : null;

        if ( ( null === $form_source ) xor ( null === $form_id ) )
        {
            return new WP_Error(
                'invalid_scope',
                __( 'Provide both form_source and form_id when targeting a single form.', 'sentient-forms' )
            );
        }

        if ( null !== $form_id && $form_id <= 0 )
        {
            return new WP_Error(
                'invalid_form_id',
                __( 'form_id must be a positive integer.', 'sentient-forms' )
            );
        }

        $configuration_error = $this->validate_configuration();
        if ( is_wp_error( $configuration_error ) )
        {
            return $configuration_error;
        }

        $targets = $this->discover_form_targets( $form_source, $form_id );
        if ( is_wp_error( $targets ) )
        {
            return $targets;
        }

        $remote_mappings = $this->fetch_remote_mappings();
        if ( is_wp_error( $remote_mappings ) )
        {
            return $remote_mappings;
        }

        $resolved_site_id = $this->resolve_site_id( $remote_mappings );
        if ( is_wp_error( $resolved_site_id ) )
        {
            return $resolved_site_id;
        }

        $summary = [
            'apply'            => $apply,
            'include_disabled' => $include_disabled,
            'site_id'          => $this->site_id,
            'forms'            => [],
            'totals'           => $this->build_empty_counts(),
        ];

        foreach ( $targets as $target )
        {
            $form_summary = $this->migrate_single_form(
                $target,
                $remote_mappings,
                $apply,
                $include_disabled
            );
            $summary['forms'][] = $form_summary;
            $summary['totals']  = $this->merge_counts( $summary['totals'], $form_summary['counts'] );
        }

        return $summary;
    }

    /**
     * @return true|WP_Error
     */
    private function validate_configuration()
    {
        if ( '' === $this->api_key )
        {
            return new WP_Error(
                'missing_proxy_api_key',
                __( 'Proxy API key is required before running mapping migration.', 'sentient-forms' )
            );
        }

        return true;
    }

    /**
     * Resolve a UUID site_id for migration requests.
     *
     * @param array<int, array<string, mixed>> $remote_mappings
     * @return true|WP_Error
     */
    private function resolve_site_id( array $remote_mappings )
    {
        if ( '' !== $this->site_id && $this->is_uuid( $this->site_id ) )
        {
            return true;
        }

        $candidates = [];
        foreach ( $remote_mappings as $mapping )
        {
            if ( ! is_array( $mapping ) )
            {
                continue;
            }

            $candidate = isset( $mapping['site_id'] ) && is_scalar( $mapping['site_id'] )
                ? sanitize_text_field( (string) $mapping['site_id'] )
                : '';
            if ( '' === $candidate || ! $this->is_uuid( $candidate ) )
            {
                continue;
            }

            $candidates[ $candidate ] = true;
        }

        if ( 1 === count( $candidates ) )
        {
            $resolved      = array_keys( $candidates );
            $this->site_id = sanitize_text_field( (string) $resolved[0] );
            return true;
        }

        if ( count( $candidates ) > 1 )
        {
            return new WP_Error(
                'ambiguous_site_id',
                __(
                    'Could not infer site_id for migration because this license has mappings for multiple site IDs.',
                    'sentient-forms'
                )
            );
        }

        return new WP_Error(
            'missing_site_id',
            __(
                'Site ID must be a UUID before migration. Re-activate the license or set a UUID site_id.',
                'sentient-forms'
            )
        );
    }

    /**
     * @return array<int, array{
     *   form_source: string,
     *   form_id: int,
     *   option_key: string
     * }>|WP_Error
     */
    private function discover_form_targets( ?string $form_source, ?int $form_id )
    {
        global $wpdb;

        if ( null !== $form_source && null !== $form_id )
        {
            return [
                [
                    'form_source' => $form_source,
                    'form_id'     => $form_id,
                    'option_key'  => $this->build_option_key( $form_source, $form_id ),
                ],
            ];
        }

        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! property_exists( $wpdb, 'options' ) )
        {
            return new WP_Error(
                'missing_wpdb',
                __( 'Unable to access WordPress options table for migration discovery.', 'sentient-forms' )
            );
        }

        $like = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
        $keys = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT option_name FROM ' . esc_sql( $wpdb->options ) . ' WHERE option_name LIKE %s',
                $like
            )
        );
        if ( ! is_array( $keys ) )
        {
            return [];
        }

        $targets = [];
        foreach ( $keys as $option_key )
        {
            if ( ! is_string( $option_key ) )
            {
                continue;
            }

            $parsed = $this->parse_option_key( $option_key );
            if ( null === $parsed )
            {
                continue;
            }

            $targets[] = [
                'form_source' => $parsed['form_source'],
                'form_id'     => $parsed['form_id'],
                'option_key'  => $option_key,
            ];
        }

        usort(
            $targets,
            static function ( array $left, array $right ): int {
                if ( $left['form_source'] === $right['form_source'] )
                {
                    return $left['form_id'] <=> $right['form_id'];
                }

                return strcmp( $left['form_source'], $right['form_source'] );
            }
        );

        return $targets;
    }

    private function build_option_key( string $form_source, int $form_id ): string
    {
        return self::OPTION_PREFIX . sanitize_key( $form_source ) . '_' . absint( $form_id );
    }

    /**
     * @return array{form_source: string, form_id: int}|null
     */
    private function parse_option_key( string $option_key ): ?array
    {
        $pattern = '/^sentient_forms_actions_(.+)_(\d+)$/';
        if ( 1 !== preg_match( $pattern, $option_key, $matches ) )
        {
            return null;
        }

        $form_source = sanitize_key( $matches[1] ?? '' );
        $form_id     = absint( $matches[2] ?? 0 );

        if ( '' === $form_source || $form_id <= 0 )
        {
            return null;
        }

        return [
            'form_source' => $form_source,
            'form_id'     => $form_id,
        ];
    }

    /**
     * @return array|WP_Error
     */
    private function fetch_remote_mappings()
    {
        $response = $this->client->get(
            '/mappings',
            [ 'bearer_token' => $this->api_key ]
        );
        if ( is_wp_error( $response ) )
        {
            return $response;
        }

        return $this->normalize_mapping_list_response( $response );
    }

    /**
     * @param array|mixed $response
     * @return array<int, array<string, mixed>>
     */
    private function normalize_mapping_list_response( $response ): array
    {
        if ( ! is_array( $response ) )
        {
            return [];
        }

        if ( isset( $response['mappings'] ) && is_array( $response['mappings'] ) )
        {
            return array_values(
                array_filter(
                    $response['mappings'],
                    static function ( $mapping ): bool {
                        return is_array( $mapping );
                    }
                )
            );
        }

        if ( isset( $response['data'] ) && is_array( $response['data'] ) )
        {
            return array_values(
                array_filter(
                    $response['data'],
                    static function ( $mapping ): bool {
                        return is_array( $mapping );
                    }
                )
            );
        }

        if ( isset( $response['id'] ) )
        {
            return [ $response ];
        }

        if ( array_is_list( $response ) )
        {
            return array_values(
                array_filter(
                    $response,
                    static function ( $mapping ): bool {
                        return is_array( $mapping );
                    }
                )
            );
        }

        return [];
    }

    /**
     * @param array{form_source: string, form_id: int, option_key: string} $target
     * @param array<int, array<string, mixed>> $remote_mappings
     * @return array{
     *   form_source: string,
     *   form_id: int,
     *   option_key: string,
     *   operations: array<int, array<string, mixed>>,
     *   counts: array{create: int, update: int, skip: int, error: int}
     * }
     */
    private function migrate_single_form(
        array $target,
        array $remote_mappings,
        bool $apply,
        bool $include_disabled
    ): array
    {
        $form_summary = [
            'form_source' => $target['form_source'],
            'form_id'     => $target['form_id'],
            'option_key'  => $target['option_key'],
            'operations'  => [],
            'counts'      => $this->build_empty_counts(),
        ];

        $stored = get_option( $target['option_key'], [] );
        if ( ! is_array( $stored ) )
        {
            $this->append_operation(
                $form_summary,
                [
                    'operation' => 'skip',
                    'reason'    => 'invalid_option_payload',
                    'message'   => __( 'Form option payload is not an array.', 'sentient-forms' ),
                ]
            );

            return $form_summary;
        }

        $mapping_entries = $this->extract_mapping_entries( $stored );
        $existing = $this->index_remote_form_mappings(
            $remote_mappings,
            $target['form_source'],
            $target['form_id']
        );
        $seen_local_ids = [];

        foreach ( $mapping_entries as $option_entry_key => $raw_mapping )
        {
            if ( 'sf_disabled' === (string) $option_entry_key )
            {
                continue;
            }

            if ( ! is_array( $raw_mapping ) )
            {
                $this->append_operation(
                    $form_summary,
                    [
                        'operation'        => 'skip',
                        'local_mapping_id' => sanitize_text_field( (string) $option_entry_key ),
                        'reason'           => 'non_mapping_entry',
                    ]
                );
                continue;
            }

            $normalized = $this->normalize_local_mapping( $raw_mapping, (string) $option_entry_key );
            if ( is_wp_error( $normalized ) )
            {
                $this->append_operation(
                    $form_summary,
                    [
                        'operation'        => 'skip',
                        'local_mapping_id' => sanitize_text_field( (string) $option_entry_key ),
                        'reason'           => $normalized->get_error_code(),
                        'message'          => $normalized->get_error_message(),
                    ]
                );
                continue;
            }

            $local_mapping_id = $normalized['local_mapping_id'];
            if ( isset( $seen_local_ids[ $local_mapping_id ] ) )
            {
                $this->append_operation(
                    $form_summary,
                    [
                        'operation'        => 'skip',
                        'local_mapping_id' => $local_mapping_id,
                        'central_action_id'=> $normalized['central_action_id'],
                        'action_type'      => $normalized['action_type_indicator'],
                        'reason'           => 'duplicate_local_mapping_id',
                    ]
                );
                continue;
            }
            $seen_local_ids[ $local_mapping_id ] = true;

            if ( ! $include_disabled && ! $normalized['is_enabled'] )
            {
                $this->append_operation(
                    $form_summary,
                    [
                        'operation'        => 'skip',
                        'local_mapping_id' => $local_mapping_id,
                        'central_action_id'=> $normalized['central_action_id'],
                        'action_type'      => $normalized['action_type_indicator'],
                        'reason'           => 'disabled_mapping',
                    ]
                );
                continue;
            }

            $action_reference = $this->build_action_reference( $normalized );
            if ( is_wp_error( $action_reference ) )
            {
                $this->append_operation(
                    $form_summary,
                    [
                        'operation'        => 'skip',
                        'local_mapping_id' => $local_mapping_id,
                        'central_action_id'=> $normalized['central_action_id'],
                        'action_type'      => $normalized['action_type_indicator'],
                        'reason'           => $action_reference->get_error_code(),
                        'message'          => $action_reference->get_error_message(),
                    ]
                );
                continue;
            }

            if ( isset( $existing[ $local_mapping_id ] ) )
            {
                $remote_mapping = $existing[ $local_mapping_id ];
                $mapping_id     = sanitize_text_field( (string) ( $remote_mapping['id'] ?? '' ) );
                if ( '' === $mapping_id )
                {
                    $this->append_operation(
                        $form_summary,
                        [
                            'operation'        => 'error',
                            'local_mapping_id' => $local_mapping_id,
                            'central_action_id'=> $normalized['central_action_id'],
                            'action_type'      => $normalized['action_type_indicator'],
                            'reason'           => 'missing_remote_mapping_id',
                        ]
                    );
                    continue;
                }

                $payload        = [
                    'display_name' => $normalized['display_name'],
                    'settings'     => $normalized['settings'],
                    'is_template'  => false,
                ];

                if ( ! $apply )
                {
                    $this->append_operation(
                        $form_summary,
                        [
                            'operation'        => 'update',
                            'local_mapping_id' => $local_mapping_id,
                            'central_action_id'=> $normalized['central_action_id'],
                            'action_type'      => $normalized['action_type_indicator'],
                            'cps_mapping_id'   => $mapping_id,
                        ]
                    );
                    continue;
                }

                $response = $this->client->put(
                    '/mappings/' . rawurlencode( $mapping_id ),
                    $payload,
                    [ 'bearer_token' => $this->api_key ]
                );
                if ( is_wp_error( $response ) )
                {
                    $this->append_operation(
                        $form_summary,
                        [
                            'operation'        => 'error',
                            'local_mapping_id' => $local_mapping_id,
                            'central_action_id'=> $normalized['central_action_id'],
                            'action_type'      => $normalized['action_type_indicator'],
                            'cps_mapping_id'   => $mapping_id,
                            'reason'           => $response->get_error_code(),
                            'message'          => $response->get_error_message(),
                        ]
                    );
                    continue;
                }

                $this->append_operation(
                    $form_summary,
                    [
                        'operation'        => 'update',
                        'local_mapping_id' => $local_mapping_id,
                        'central_action_id'=> $normalized['central_action_id'],
                        'action_type'      => $normalized['action_type_indicator'],
                        'cps_mapping_id'   => $mapping_id,
                    ]
                );
                continue;
            }

            $payload = array_merge(
                [
                    'site_id'      => $this->site_id,
                    'form_source'  => $target['form_source'],
                    'form_id'      => $target['form_id'],
                    'display_name' => $normalized['display_name'],
                    'settings'     => $normalized['settings'],
                    'is_template'  => false,
                ],
                $action_reference
            );

            if ( ! $apply )
            {
                $this->append_operation(
                    $form_summary,
                    [
                        'operation'        => 'create',
                        'local_mapping_id' => $local_mapping_id,
                        'central_action_id'=> $normalized['central_action_id'],
                        'action_type'      => $normalized['action_type_indicator'],
                    ]
                );
                continue;
            }

            $response = $this->client->post(
                '/mappings',
                $payload,
                [ 'bearer_token' => $this->api_key ]
            );
            if ( is_wp_error( $response ) )
            {
                $this->append_operation(
                    $form_summary,
                    [
                        'operation'        => 'error',
                        'local_mapping_id' => $local_mapping_id,
                        'central_action_id'=> $normalized['central_action_id'],
                        'action_type'      => $normalized['action_type_indicator'],
                        'reason'           => $response->get_error_code(),
                        'message'          => $response->get_error_message(),
                    ]
                );
                continue;
            }

            $cps_mapping_id = isset( $response['id'] ) && is_scalar( $response['id'] )
                ? sanitize_text_field( (string) $response['id'] )
                : '';

            if ( '' !== $cps_mapping_id )
            {
                $existing[ $local_mapping_id ] = [ 'id' => $cps_mapping_id ];
            }

            $this->append_operation(
                $form_summary,
                [
                    'operation'        => 'create',
                    'local_mapping_id' => $local_mapping_id,
                    'central_action_id'=> $normalized['central_action_id'],
                    'action_type'      => $normalized['action_type_indicator'],
                    'cps_mapping_id'   => $cps_mapping_id,
                ]
            );
        }

        return $form_summary;
    }

    /**
     * Normalizes stored form option payload into mapping entries.
     *
     * Supports both modern layout:
     * - sentient_forms_actions_<source>_<id>[<local_mapping_id>] = mapping object
     *
     * And legacy wrapper layout:
     * - sentient_forms_actions_<source>_<id>['actions'][<legacy_key>] = mapping object
     *
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private function extract_mapping_entries( array $stored ): array
    {
        $has_legacy_actions_wrapper = isset( $stored['actions'] ) && is_array( $stored['actions'] );
        if ( ! $has_legacy_actions_wrapper )
        {
            return $stored;
        }

        $has_legacy_shape_markers =
            array_key_exists( 'enabled', $stored )
            || array_key_exists( 'sf_disabled', $stored )
            || array_key_exists( 'actions', $stored );
        if ( ! $has_legacy_shape_markers )
        {
            return $stored;
        }

        return $stored['actions'];
    }

    /**
     * @param array<int, array<string, mixed>> $remote_mappings
     * @return array<string, array<string, mixed>>
     */
    private function index_remote_form_mappings(
        array $remote_mappings,
        string $form_source,
        int $form_id
    ): array
    {
        $indexed = [];

        foreach ( $remote_mappings as $mapping )
        {
            if ( ! is_array( $mapping ) )
            {
                continue;
            }

            if ( ! empty( $mapping['is_template'] ) )
            {
                continue;
            }

            $mapping_site_id = isset( $mapping['site_id'] ) && is_scalar( $mapping['site_id'] )
                ? sanitize_text_field( (string) $mapping['site_id'] )
                : '';
            if ( $mapping_site_id !== $this->site_id )
            {
                continue;
            }

            $mapping_source = isset( $mapping['form_source'] ) && is_scalar( $mapping['form_source'] )
                ? sanitize_key( (string) $mapping['form_source'] )
                : '';
            if ( $mapping_source !== $form_source )
            {
                continue;
            }

            $mapping_form_id = isset( $mapping['form_id'] ) ? absint( $mapping['form_id'] ) : 0;
            if ( $mapping_form_id !== $form_id )
            {
                continue;
            }

            $settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] )
                ? $mapping['settings']
                : [];
            $local_mapping_id = isset( $settings['local_mapping_id'] ) && is_scalar( $settings['local_mapping_id'] )
                ? sanitize_text_field( (string) $settings['local_mapping_id'] )
                : '';
            if ( '' === $local_mapping_id )
            {
                continue;
            }

            if ( ! isset( $indexed[ $local_mapping_id ] ) )
            {
                $indexed[ $local_mapping_id ] = $mapping;
            }
        }

        return $indexed;
    }

    /**
     * @return array|WP_Error
     */
    private function normalize_local_mapping( array $raw_mapping, string $fallback_id )
    {
        $local_mapping_id = isset( $raw_mapping['local_mapping_id'] ) && is_scalar( $raw_mapping['local_mapping_id'] )
            ? sanitize_text_field( (string) $raw_mapping['local_mapping_id'] )
            : sanitize_text_field( $fallback_id );
        if ( '' === $local_mapping_id )
        {
            return new WP_Error(
                'invalid_local_mapping_id',
                __( 'Local mapping is missing local_mapping_id.', 'sentient-forms' )
            );
        }

        $central_action_id = isset( $raw_mapping['central_action_id'] ) && is_scalar( $raw_mapping['central_action_id'] )
            ? sanitize_text_field( (string) $raw_mapping['central_action_id'] )
            : '';
        if ( '' === $central_action_id )
        {
            return new WP_Error(
                'invalid_central_action_id',
                __( 'Local mapping is missing central_action_id.', 'sentient-forms' )
            );
        }

        $action_type = isset( $raw_mapping['action_type_indicator'] ) && is_scalar( $raw_mapping['action_type_indicator'] )
            ? sanitize_key( (string) $raw_mapping['action_type_indicator'] )
            : 'master';
        if ( '' === $action_type )
        {
            $action_type = 'master';
        }

        $raw_settings = isset( $raw_mapping['settings'] ) && is_array( $raw_mapping['settings'] )
            ? $raw_mapping['settings']
            : [];
        $trigger_hook_source = $raw_mapping['trigger_hooks']
            ?? ( $raw_mapping['hooks']
            ?? ( $raw_settings['trigger_hooks']
            ?? ( $raw_settings['hooks'] ?? [] ) ) );
        $trigger_hooks = $this->sanitize_trigger_hooks( $trigger_hook_source );
        $is_enabled    = $this->normalize_bool(
            $raw_mapping['is_action_enabled_for_form']
            ?? ( $raw_mapping['enabled']
            ?? ( $raw_settings['is_action_enabled_for_form']
            ?? ( $raw_settings['enabled'] ?? true ) ) )
        );
        $priority      = isset( $raw_mapping['execution_priority'] )
            ? (int) $raw_mapping['execution_priority']
            : ( isset( $raw_settings['execution_priority'] ) ? (int) $raw_settings['execution_priority'] : 10 );

        $settings = $raw_settings;
        $settings['local_mapping_id']           = $local_mapping_id;
        $settings['trigger_hooks']              = $trigger_hooks;
        $settings['is_action_enabled_for_form'] = $is_enabled;
        $settings['execution_priority']         = $priority;

        $display_name = isset( $raw_mapping['action_name_label'] ) && is_scalar( $raw_mapping['action_name_label'] )
            ? sanitize_text_field( (string) $raw_mapping['action_name_label'] )
            : '';
        if ( '' === $display_name && isset( $raw_settings['display_name'] ) && is_scalar( $raw_settings['display_name'] ) )
        {
            $display_name = sanitize_text_field( (string) $raw_settings['display_name'] );
        }
        if ( '' === $display_name )
        {
            $display_name = $central_action_id;
        }

        return [
            'local_mapping_id'      => $local_mapping_id,
            'central_action_id'     => $central_action_id,
            'action_type_indicator' => $action_type,
            'display_name'          => $display_name,
            'settings'              => $settings,
            'is_enabled'            => $is_enabled,
        ];
    }

    /**
     * @param array<string, mixed> $normalized_mapping
     * @return array|WP_Error
     */
    private function build_action_reference( array $normalized_mapping )
    {
        $action_type       = $normalized_mapping['action_type_indicator'];
        $central_action_id = $normalized_mapping['central_action_id'];

        if ( 'custom' === $action_type )
        {
            if ( ! $this->is_uuid( $central_action_id ) )
            {
                return new WP_Error(
                    'unsupported_custom_action_id',
                    __( 'Custom mapping central_action_id must be a UUID for CPS migration.', 'sentient-forms' )
                );
            }

            return [ 'custom_action_id' => $central_action_id ];
        }

        return [ 'action_template_code' => $central_action_id ];
    }

    private function is_uuid( string $candidate ): bool
    {
        return 1 === preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $candidate
        );
    }

    /**
     * @param mixed $value
     */
    private function normalize_bool( $value ): bool
    {
        if ( is_bool( $value ) )
        {
            return $value;
        }

        return rest_sanitize_boolean( $value );
    }

    /**
     * @param mixed $hooks
     * @return array<int, string>
     */
    private function sanitize_trigger_hooks( $hooks ): array
    {
        if ( ! is_array( $hooks ) )
        {
            return [];
        }

        $normalized = [];
        foreach ( $hooks as $hook )
        {
            if ( ! is_scalar( $hook ) )
            {
                continue;
            }

            $hook = sanitize_key( (string) $hook );
            if ( '' === $hook )
            {
                continue;
            }

            $normalized[] = $hook;
        }

        return array_values( array_unique( $normalized ) );
    }

    /**
     * @param array{
     *   form_source: string,
     *   form_id: int,
     *   option_key: string,
     *   operations: array<int, array<string, mixed>>,
     *   counts: array{create: int, update: int, skip: int, error: int}
     * } $summary
     * @param array<string, mixed> $operation
     */
    private function append_operation( array &$summary, array $operation ): void
    {
        $operation_type = isset( $operation['operation'] ) && is_scalar( $operation['operation'] )
            ? sanitize_key( (string) $operation['operation'] )
            : 'skip';

        $operation['operation'] = $operation_type;
        $summary['operations'][] = $operation;

        if ( isset( $summary['counts'][ $operation_type ] ) )
        {
            $summary['counts'][ $operation_type ]++;
        }
        else
        {
            $summary['counts']['skip']++;
        }
    }

    /**
     * @return array{create: int, update: int, skip: int, error: int}
     */
    private function build_empty_counts(): array
    {
        return [
            'create' => 0,
            'update' => 0,
            'skip'   => 0,
            'error'  => 0,
        ];
    }

    /**
     * @param array{create: int, update: int, skip: int, error: int} $left
     * @param array{create: int, update: int, skip: int, error: int} $right
     * @return array{create: int, update: int, skip: int, error: int}
     */
    private function merge_counts( array $left, array $right ): array
    {
        return [
            'create' => (int) $left['create'] + (int) $right['create'],
            'update' => (int) $left['update'] + (int) $right['update'],
            'skip'   => (int) $left['skip'] + (int) $right['skip'],
            'error'  => (int) $left['error'] + (int) $right['error'],
        ];
    }
}
