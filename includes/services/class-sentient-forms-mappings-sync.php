<?php
/**
 * CPS Form Mappings Sync Service.
 *
 * Phase 7 CSM: Handles syncing form mappings between CPS and WordPress.
 *
 * @package Sentient_Forms
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Sentient_Forms_Mappings_Sync
 *
 * Syncs form mappings from CPS to local WordPress cache.
 * Provides offline fallback to cached mappings when CPS is unreachable.
 */
class Sentient_Forms_Mappings_Sync {

    private const CACHE_OPTION_KEY = 'sentient_forms_mappings_cache';
    private const CACHE_TTL_SECONDS = 300; // 5 minutes

    /**
     * Get CPS API client instance from the main plugin.
     *
     * @return Sentient_Forms_Api_Client|null
     */
    private function get_cps_client(): ?Sentient_Forms_Api_Client {
        if ( ! class_exists( 'Sentient_Forms_Plugin' ) ) {
            return null;
        }
        return Sentient_Forms_Plugin::instance()->get_cps_api_client();
    }

    /**
     * Get API key for CPS authentication.
     *
     * @return string|null
     */
    private function get_api_key(): ?string {
        if ( ! class_exists( 'Sentient_Forms_Plugin' ) ) {
            return null;
        }
        return Sentient_Forms_Plugin::instance()->get_proxy_api_key();
    }

    /**
     * Fetch mappings from CPS, with local cache fallback.
     *
     * @return array List of mappings.
     */
    public function fetch_mappings(): array {
        // Try CPS first
        $cps_mappings = $this->fetch_from_cps();

        if ( ! is_wp_error( $cps_mappings ) && is_array( $cps_mappings ) ) {
            // Update local cache
            $this->update_cache( $cps_mappings );
            return $cps_mappings;
        }

        // Fall back to local cache
        return $this->get_cached_mappings();
    }

    /**
     * Fetch template mappings (is_template = true) from CPS.
     *
     * @return array List of template mappings.
     */
    public function fetch_templates(): array {
        $client = $this->get_cps_client();
        $api_key = $this->get_api_key();

        if ( ! $client || ! $api_key ) {
            return [];
        }

        $response = $client->get( '/mappings/templates', [
            'bearer_token' => $api_key,
        ] );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        return $this->normalize_mapping_list_response( $response );
    }

    /**
     * Create a new mapping in CPS.
     *
     * @param array $mapping_data Mapping data to create.
     * @return array|WP_Error Created mapping or error.
     */
    public function create_mapping( array $mapping_data ) {
        $client = $this->get_cps_client();
        $api_key = $this->get_api_key();

        if ( ! $client || ! $api_key ) {
            return new WP_Error( 'cps_unavailable', 'CPS is not configured.' );
        }

        return $client->post( '/mappings', $mapping_data, [
            'bearer_token' => $api_key,
        ] );
    }

    /**
     * Update an existing CPS mapping.
     *
     * @param string $mapping_id   CPS mapping UUID.
     * @param array  $mapping_data Fields to update.
     *
     * @return array|WP_Error Updated mapping or error.
     */
    public function update_mapping( string $mapping_id, array $mapping_data ) {
        $client = $this->get_cps_client();
        $api_key = $this->get_api_key();

        if ( ! $client || ! $api_key ) {
            return new WP_Error( 'cps_unavailable', 'CPS is not configured.' );
        }

        return $client->put( '/mappings/' . rawurlencode( $mapping_id ), $mapping_data, [
            'bearer_token' => $api_key,
        ] );
    }

    /**
     * Delete an existing CPS mapping.
     *
     * @param string $mapping_id CPS mapping UUID.
     *
     * @return array|WP_Error Delete response or error.
     */
    public function delete_mapping( string $mapping_id ) {
        $client = $this->get_cps_client();
        $api_key = $this->get_api_key();

        if ( ! $client || ! $api_key ) {
            return new WP_Error( 'cps_unavailable', 'CPS is not configured.' );
        }

        return $client->delete( '/mappings/' . rawurlencode( $mapping_id ), [], [
            'bearer_token' => $api_key,
        ] );
    }

    /**
     * Reconcile one form's local WordPress mappings into CPS.
     *
     * CPS planner reads `form_mappings`; if local mappings are not mirrored there,
     * a healthy CPS will still return an empty plan. This method is deliberately
     * idempotent: it matches remote rows by settings.local_mapping_id and only
     * deletes remote rows that carry a local_mapping_id for this same form.
     *
     * @param string $form_source_slug Form adapter slug.
     * @param int    $form_id          Form identifier.
     * @param array  $local_actions    Local mapping payloads.
     * @param bool   $include_disabled Whether disabled local mappings should stay mirrored.
     *
     * @return array|WP_Error Sync summary or configuration/transport error.
     */
    public function sync_form_mappings_for_form( string $form_source_slug, int $form_id, array $local_actions, bool $include_disabled = true ) {
        $client = $this->get_cps_client();
        $api_key = $this->get_api_key();

        if ( ! $client || ! $api_key ) {
            return new WP_Error( 'cps_unavailable', 'CPS is not configured.' );
        }

        $site_id = $this->get_site_id();
        if ( '' === $site_id ) {
            return new WP_Error( 'missing_site_id', 'CPS site_id is missing. Re-activate the license before syncing mappings.' );
        }

        $remote_mappings = $this->fetch_from_cps();
        if ( is_wp_error( $remote_mappings ) ) {
            return $remote_mappings;
        }

        $remote_form_mappings = $this->filter_remote_mappings_for_form(
            $remote_mappings,
            $site_id,
            sanitize_key( $form_source_slug ),
            absint( $form_id )
        );
        $existing = [];
        $duplicates = [];
        foreach ( $remote_form_mappings as $remote_mapping ) {
            $local_mapping_id = $this->extract_remote_local_mapping_id( $remote_mapping );
            if ( '' === $local_mapping_id ) {
                continue;
            }

            if ( isset( $existing[ $local_mapping_id ] ) ) {
                $duplicates[] = $remote_mapping;
                continue;
            }

            $existing[ $local_mapping_id ] = $remote_mapping;
        }

        $summary = [
            'form_source' => sanitize_key( $form_source_slug ),
            'form_id'     => absint( $form_id ),
            'site_id'     => $site_id,
            'operations'  => [],
            'counts'      => $this->build_empty_counts(),
        ];
        $seen_local_ids = [];

        foreach ( $local_actions as $index => $raw_mapping ) {
            if ( ! is_array( $raw_mapping ) ) {
                $this->append_sync_operation(
                    $summary,
                    [
                        'operation'        => 'skip',
                        'local_mapping_id' => sanitize_text_field( (string) $index ),
                        'reason'           => 'non_mapping_entry',
                    ]
                );
                continue;
            }

            $normalized = $this->normalize_local_mapping_for_cps( $raw_mapping, (string) $index );
            if ( is_wp_error( $normalized ) ) {
                $this->append_sync_operation(
                    $summary,
                    [
                        'operation'        => 'error',
                        'local_mapping_id' => sanitize_text_field( (string) $index ),
                        'reason'           => $normalized->get_error_code(),
                        'message'          => $normalized->get_error_message(),
                    ]
                );
                continue;
            }

            $local_mapping_id = $normalized['local_mapping_id'];
            if ( isset( $seen_local_ids[ $local_mapping_id ] ) ) {
                $this->append_sync_operation(
                    $summary,
                    [
                        'operation'        => 'error',
                        'local_mapping_id' => $local_mapping_id,
                        'central_action_id'=> $normalized['central_action_id'],
                        'reason'           => 'duplicate_local_mapping_id',
                    ]
                );
                continue;
            }
            $seen_local_ids[ $local_mapping_id ] = true;

            if ( ! $include_disabled && ! $normalized['is_enabled'] ) {
                $this->append_sync_operation(
                    $summary,
                    [
                        'operation'        => 'skip',
                        'local_mapping_id' => $local_mapping_id,
                        'central_action_id'=> $normalized['central_action_id'],
                        'reason'           => 'disabled_mapping',
                    ]
                );
                continue;
            }

            $action_reference = $this->build_action_reference( $normalized );
            if ( is_wp_error( $action_reference ) ) {
                $this->append_sync_operation(
                    $summary,
                    [
                        'operation'        => 'error',
                        'local_mapping_id' => $local_mapping_id,
                        'central_action_id'=> $normalized['central_action_id'],
                        'reason'           => $action_reference->get_error_code(),
                        'message'          => $action_reference->get_error_message(),
                    ]
                );
                continue;
            }

            if ( isset( $existing[ $local_mapping_id ] ) ) {
                $remote_mapping_id = isset( $existing[ $local_mapping_id ]['id'] ) && is_scalar( $existing[ $local_mapping_id ]['id'] )
                    ? sanitize_text_field( (string) $existing[ $local_mapping_id ]['id'] )
                    : '';

                if ( '' === $remote_mapping_id ) {
                    $this->append_sync_operation(
                        $summary,
                        [
                            'operation'        => 'error',
                            'local_mapping_id' => $local_mapping_id,
                            'central_action_id'=> $normalized['central_action_id'],
                            'reason'           => 'missing_remote_mapping_id',
                        ]
                    );
                    continue;
                }

                $response = $this->update_mapping(
                    $remote_mapping_id,
                    [
                        'display_name' => $normalized['display_name'],
                        'settings'     => $normalized['settings'],
                        'is_template'  => false,
                    ]
                );

                if ( is_wp_error( $response ) ) {
                    $this->append_sync_operation(
                        $summary,
                        [
                            'operation'        => 'error',
                            'local_mapping_id' => $local_mapping_id,
                            'central_action_id'=> $normalized['central_action_id'],
                            'cps_mapping_id'   => $remote_mapping_id,
                            'reason'           => $response->get_error_code(),
                            'message'          => $response->get_error_message(),
                        ]
                    );
                    continue;
                }

                $this->append_sync_operation(
                    $summary,
                    [
                        'operation'        => 'update',
                        'local_mapping_id' => $local_mapping_id,
                        'central_action_id'=> $normalized['central_action_id'],
                        'cps_mapping_id'   => $remote_mapping_id,
                    ]
                );
                continue;
            }

            $response = $this->create_mapping(
                array_merge(
                    [
                        'site_id'      => $site_id,
                        'form_source'  => sanitize_key( $form_source_slug ),
                        'form_id'      => absint( $form_id ),
                        'display_name' => $normalized['display_name'],
                        'settings'     => $normalized['settings'],
                        'is_template'  => false,
                    ],
                    $action_reference
                )
            );

            if ( is_wp_error( $response ) ) {
                $this->append_sync_operation(
                    $summary,
                    [
                        'operation'        => 'error',
                        'local_mapping_id' => $local_mapping_id,
                        'central_action_id'=> $normalized['central_action_id'],
                        'reason'           => $response->get_error_code(),
                        'message'          => $response->get_error_message(),
                    ]
                );
                continue;
            }

            $cps_mapping_id = isset( $response['id'] ) && is_scalar( $response['id'] )
                ? sanitize_text_field( (string) $response['id'] )
                : '';

            $this->append_sync_operation(
                $summary,
                [
                    'operation'        => 'create',
                    'local_mapping_id' => $local_mapping_id,
                    'central_action_id'=> $normalized['central_action_id'],
                    'cps_mapping_id'   => $cps_mapping_id,
                ]
            );
        }

        foreach ( $duplicates as $duplicate_mapping ) {
            $this->delete_remote_mapping_if_possible( $summary, $duplicate_mapping, 'duplicate_remote_local_mapping_id' );
        }

        foreach ( $existing as $local_mapping_id => $remote_mapping ) {
            if ( isset( $seen_local_ids[ $local_mapping_id ] ) ) {
                continue;
            }

            $this->delete_remote_mapping_if_possible( $summary, $remote_mapping, 'stale_remote_local_mapping_id' );
        }

        if ( 0 === (int) ( $summary['counts']['error'] ?? 0 ) ) {
            $this->invalidate_cache();
        }

        return $summary;
    }

    /**
     * Clone a template mapping to a new site/form.
     *
     * @param string $template_id Template mapping ID.
     * @param array  $clone_data  Clone request data.
     * @return array|WP_Error Cloned mapping or error.
     */
    public function clone_template( string $template_id, array $clone_data ) {
        $client = $this->get_cps_client();
        $api_key = $this->get_api_key();

        if ( ! $client || ! $api_key ) {
            return new WP_Error( 'cps_unavailable', 'CPS is not configured.' );
        }

        return $client->post( "/mappings/{$template_id}/clone", $clone_data, [
            'bearer_token' => $api_key,
        ] );
    }

    /**
     * Request a dependency workflow plan from CPS for a specific form.
     *
     * @param string $form_source_slug Form adapter slug.
     * @param int    $form_id          Form identifier.
     * @param string $hook_scope       Hook scope ("all" or hook slug).
     *
     * @return array|WP_Error
     */
    public function plan_workflow( string $form_source_slug, int $form_id, string $hook_scope = 'all' ) {
        $client = $this->get_cps_client();
        $api_key = $this->get_api_key();

        if ( ! $client || ! $api_key ) {
            return new WP_Error( 'cps_unavailable', 'CPS is not configured.' );
        }

        return $client->post(
            '/workflows/plan',
            [
                'form_source' => sanitize_key( $form_source_slug ),
                'form_id'     => absint( $form_id ),
                'hook_scope'  => sanitize_text_field( $hook_scope ),
            ],
            [
                'bearer_token' => $api_key,
            ],
        );
    }

    /**
     * Fetch mappings from CPS.
     *
     * @return array|WP_Error Mappings array or error.
     */
    private function fetch_from_cps() {
        $client = $this->get_cps_client();
        $api_key = $this->get_api_key();

        if ( ! $client || ! $api_key ) {
            return new WP_Error( 'cps_unavailable', 'CPS is not configured.' );
        }

        $response = $client->get( '/mappings', [
            'bearer_token' => $api_key,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $this->normalize_mapping_list_response( $response );
    }

    /**
     * Resolve the authenticated CPS site UUID stored by licensing.
     */
    public function get_site_id(): string {
        $license_data = [];
        if ( class_exists( 'Sentient_Forms_Plugin' ) ) {
            $license_data = Sentient_Forms_Plugin::instance()->get_license_data();
        }

        $license_site_id = isset( $license_data['site_id'] ) && is_scalar( $license_data['site_id'] )
            ? sanitize_text_field( (string) $license_data['site_id'] )
            : '';
        if ( $this->is_uuid( $license_site_id ) ) {
            return $license_site_id;
        }

        $option_site_id = sanitize_text_field( (string) get_option( 'sentient_forms_site_id', '' ) );
        if ( $this->is_uuid( $option_site_id ) ) {
            return $option_site_id;
        }

        return '';
    }

    /**
     * @param array|mixed $response
     * @return array<int, array<string, mixed>>
     */
    private function normalize_mapping_list_response( $response ): array {
        if ( ! is_array( $response ) ) {
            return [];
        }

        if ( isset( $response['mappings'] ) && is_array( $response['mappings'] ) ) {
            return $this->only_mapping_arrays( $response['mappings'] );
        }

        if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
            return $this->only_mapping_arrays( $response['data'] );
        }

        if ( isset( $response['id'] ) ) {
            return [ $response ];
        }

        if ( array_is_list( $response ) ) {
            return $this->only_mapping_arrays( $response );
        }

        return [];
    }

    /**
     * @param array<int|string, mixed> $mappings
     * @return array<int, array<string, mixed>>
     */
    private function only_mapping_arrays( array $mappings ): array {
        return array_values(
            array_filter(
                $mappings,
                static function ( $mapping ): bool {
                    return is_array( $mapping );
                }
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $remote_mappings
     * @return array<int, array<string, mixed>>
     */
    private function filter_remote_mappings_for_form( array $remote_mappings, string $site_id, string $form_source_slug, int $form_id ): array {
        return array_values(
            array_filter(
                $remote_mappings,
                static function ( array $mapping ) use ( $site_id, $form_source_slug, $form_id ): bool {
                    if ( ! empty( $mapping['is_template'] ) ) {
                        return false;
                    }

                    $mapping_site_id = isset( $mapping['site_id'] ) && is_scalar( $mapping['site_id'] )
                        ? sanitize_text_field( (string) $mapping['site_id'] )
                        : '';
                    if ( $mapping_site_id !== $site_id ) {
                        return false;
                    }

                    $mapping_source = isset( $mapping['form_source'] ) && is_scalar( $mapping['form_source'] )
                        ? sanitize_key( (string) $mapping['form_source'] )
                        : '';
                    if ( $mapping_source !== $form_source_slug ) {
                        return false;
                    }

                    return absint( $mapping['form_id'] ?? 0 ) === $form_id;
                }
            )
        );
    }

    private function extract_remote_local_mapping_id( array $mapping ): string {
        $settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] )
            ? $mapping['settings']
            : [];

        return isset( $settings['local_mapping_id'] ) && is_scalar( $settings['local_mapping_id'] )
            ? sanitize_text_field( (string) $settings['local_mapping_id'] )
            : '';
    }

    /**
     * @return array|WP_Error
     */
    private function normalize_local_mapping_for_cps( array $raw_mapping, string $fallback_id ) {
        $local_mapping_id = isset( $raw_mapping['local_mapping_id'] ) && is_scalar( $raw_mapping['local_mapping_id'] )
            ? sanitize_text_field( (string) $raw_mapping['local_mapping_id'] )
            : sanitize_text_field( $fallback_id );
        if ( '' === $local_mapping_id ) {
            return new WP_Error( 'invalid_local_mapping_id', 'Local mapping is missing local_mapping_id.' );
        }

        $central_action_id = isset( $raw_mapping['central_action_id'] ) && is_scalar( $raw_mapping['central_action_id'] )
            ? sanitize_text_field( (string) $raw_mapping['central_action_id'] )
            : '';
        if ( '' === $central_action_id ) {
            return new WP_Error( 'invalid_central_action_id', 'Local mapping is missing central_action_id.' );
        }

        $action_type = isset( $raw_mapping['action_type_indicator'] ) && is_scalar( $raw_mapping['action_type_indicator'] )
            ? sanitize_key( (string) $raw_mapping['action_type_indicator'] )
            : 'master';
        if ( '' === $action_type ) {
            $action_type = 'master';
        }

        $raw_settings = isset( $raw_mapping['settings'] ) && is_array( $raw_mapping['settings'] )
            ? $raw_mapping['settings']
            : [];
        $trigger_hooks = $this->sanitize_trigger_hooks(
            $raw_mapping['trigger_hooks']
                ?? ( $raw_mapping['hooks']
                ?? ( $raw_settings['trigger_hooks']
                ?? ( $raw_settings['hooks'] ?? [] ) ) )
        );
        $is_enabled = $this->normalize_bool(
            $raw_mapping['is_action_enabled_for_form']
                ?? ( $raw_mapping['enabled']
                ?? ( $raw_settings['is_action_enabled_for_form']
                ?? ( $raw_settings['enabled'] ?? true ) ) )
        );
        $priority = isset( $raw_mapping['execution_priority'] )
            ? (int) $raw_mapping['execution_priority']
            : ( isset( $raw_settings['execution_priority'] ) ? (int) $raw_settings['execution_priority'] : 10 );

        $settings = $raw_settings;
        $settings['local_mapping_id']           = $local_mapping_id;
        $settings['mapping_id']                 = $local_mapping_id;
        $settings['central_action_id']          = $central_action_id;
        $settings['action_type_indicator']      = $action_type;
        $settings['trigger_hooks']              = $trigger_hooks;
        $settings['is_action_enabled_for_form'] = $is_enabled;
        $settings['execution_priority']         = $priority;

        if ( isset( $raw_mapping['dependency_ids'] ) && is_array( $raw_mapping['dependency_ids'] ) ) {
            $settings['dependency_ids'] = $this->sanitize_string_list( $raw_mapping['dependency_ids'] );
        }

        $display_name = isset( $raw_mapping['action_name_label'] ) && is_scalar( $raw_mapping['action_name_label'] )
            ? sanitize_text_field( (string) $raw_mapping['action_name_label'] )
            : '';
        if ( '' === $display_name && isset( $raw_settings['display_name'] ) && is_scalar( $raw_settings['display_name'] ) ) {
            $display_name = sanitize_text_field( (string) $raw_settings['display_name'] );
        }
        if ( '' === $display_name ) {
            $display_name = $central_action_id;
        }
        $settings['display_name'] = $display_name;

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
     * @return array|WP_Error
     */
    private function build_action_reference( array $normalized_mapping ) {
        $action_type = sanitize_key( (string) ( $normalized_mapping['action_type_indicator'] ?? 'master' ) );
        $central_action_id = sanitize_text_field( (string) ( $normalized_mapping['central_action_id'] ?? '' ) );

        if ( 'custom' === $action_type ) {
            if ( ! $this->is_uuid( $central_action_id ) ) {
                return new WP_Error( 'unsupported_custom_action_id', 'Custom mapping central_action_id must be a UUID for CPS sync.' );
            }

            return [ 'custom_action_id' => $central_action_id ];
        }

        return [ 'action_template_code' => $central_action_id ];
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function delete_remote_mapping_if_possible( array &$summary, array $remote_mapping, string $reason ): void {
        $mapping_id = isset( $remote_mapping['id'] ) && is_scalar( $remote_mapping['id'] )
            ? sanitize_text_field( (string) $remote_mapping['id'] )
            : '';
        $local_mapping_id = $this->extract_remote_local_mapping_id( $remote_mapping );

        if ( '' === $mapping_id ) {
            $this->append_sync_operation(
                $summary,
                [
                    'operation'        => 'error',
                    'local_mapping_id' => $local_mapping_id,
                    'reason'           => 'missing_remote_mapping_id',
                ]
            );
            return;
        }

        $response = $this->delete_mapping( $mapping_id );
        if ( is_wp_error( $response ) ) {
            $this->append_sync_operation(
                $summary,
                [
                    'operation'        => 'error',
                    'local_mapping_id' => $local_mapping_id,
                    'cps_mapping_id'   => $mapping_id,
                    'reason'           => $response->get_error_code(),
                    'message'          => $response->get_error_message(),
                ]
            );
            return;
        }

        $this->append_sync_operation(
            $summary,
            [
                'operation'        => 'delete',
                'local_mapping_id' => $local_mapping_id,
                'cps_mapping_id'   => $mapping_id,
                'reason'           => $reason,
            ]
        );
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $operation
     */
    private function append_sync_operation( array &$summary, array $operation ): void {
        $operation_type = isset( $operation['operation'] ) && is_scalar( $operation['operation'] )
            ? sanitize_key( (string) $operation['operation'] )
            : 'skip';

        $operation['operation'] = $operation_type;
        $summary['operations'][] = $operation;

        if ( isset( $summary['counts'][ $operation_type ] ) ) {
            $summary['counts'][ $operation_type ]++;
        } else {
            $summary['counts']['skip']++;
        }
    }

    /**
     * @return array{create: int, update: int, delete: int, skip: int, error: int}
     */
    private function build_empty_counts(): array {
        return [
            'create' => 0,
            'update' => 0,
            'delete' => 0,
            'skip'   => 0,
            'error'  => 0,
        ];
    }

    /**
     * @param mixed $value
     */
    private function normalize_bool( $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }

        return rest_sanitize_boolean( $value );
    }

    /**
     * @param mixed $hooks
     * @return array<int, string>
     */
    private function sanitize_trigger_hooks( $hooks ): array {
        if ( ! is_array( $hooks ) ) {
            return [];
        }

        $normalized = [];
        foreach ( $hooks as $hook ) {
            if ( ! is_scalar( $hook ) ) {
                continue;
            }

            $value = sanitize_key( (string) $hook );
            if ( '' === $value ) {
                continue;
            }

            $normalized[] = $value;
        }

        return array_values( array_unique( $normalized ) );
    }

    /**
     * @param array<int|string, mixed> $items
     * @return array<int, string>
     */
    private function sanitize_string_list( array $items ): array {
        $normalized = [];
        foreach ( $items as $item ) {
            if ( ! is_scalar( $item ) ) {
                continue;
            }

            $value = sanitize_text_field( (string) $item );
            if ( '' === $value ) {
                continue;
            }

            $normalized[] = $value;
        }

        return array_values( array_unique( $normalized ) );
    }

    private function is_uuid( string $candidate ): bool {
        return 1 === preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $candidate
        );
    }

    /**
     * Get cached mappings from WordPress options.
     *
     * @return array
     */
    private function get_cached_mappings(): array {
        $cache = get_option( self::CACHE_OPTION_KEY, [] );

        if ( ! is_array( $cache ) || empty( $cache['mappings'] ) ) {
            return [];
        }

        return $cache['mappings'];
    }

    /**
     * Update local cache with fresh mappings.
     *
     * @param array $mappings Mappings to cache.
     */
    private function update_cache( array $mappings ): void {
        update_option( self::CACHE_OPTION_KEY, [
            'mappings'   => $mappings,
            'updated_at' => time(),
        ], false );
    }

    /**
     * Check if cache is stale.
     *
     * @return bool
     */
    public function is_cache_stale(): bool {
        $cache = get_option( self::CACHE_OPTION_KEY, [] );

        if ( ! is_array( $cache ) || empty( $cache['updated_at'] ) ) {
            return true;
        }

        return ( time() - $cache['updated_at'] ) > self::CACHE_TTL_SECONDS;
    }

    /**
     * Invalidate the local cache.
     */
    public function invalidate_cache(): void {
        delete_option( self::CACHE_OPTION_KEY );
    }
}
