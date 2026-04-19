<?php
/**
 * Local-first CPS export bundle import planner.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Local_Import_Service
{
    public const SCHEMA_VERSION = 'sentient_forms_cps_export_v1';

    private const SOURCE = 'cps_export';

    private Sentient_Forms_Action_Templates_Repository $templates;
    private Sentient_Forms_Local_Custom_Actions_Repository $custom_actions;
    private Sentient_Forms_Form_Mappings_Repository $mappings;
    private Sentient_Forms_Execution_Events_Repository $events;
    private Sentient_Forms_Migration_Runs_Repository $migration_runs;

    public function __construct(
        ?wpdb $database = null,
        ?Sentient_Forms_Action_Templates_Repository $templates = null,
        ?Sentient_Forms_Local_Custom_Actions_Repository $custom_actions = null,
        ?Sentient_Forms_Form_Mappings_Repository $mappings = null,
        ?Sentient_Forms_Execution_Events_Repository $events = null,
        ?Sentient_Forms_Migration_Runs_Repository $migration_runs = null
    )
    {
        global $wpdb;

        $database             = $database ?? $wpdb;
        $this->templates      = $templates ?? new Sentient_Forms_Action_Templates_Repository( $database );
        $this->custom_actions = $custom_actions ?? new Sentient_Forms_Local_Custom_Actions_Repository( $database );
        $this->mappings       = $mappings ?? new Sentient_Forms_Form_Mappings_Repository( $database );
        $this->events         = $events ?? new Sentient_Forms_Execution_Events_Repository( $database );
        $this->migration_runs = $migration_runs ?? new Sentient_Forms_Migration_Runs_Repository( $database );
    }

    /**
     * Validate a CPS export bundle and record a non-mutating import plan.
     *
     * @param array<string, mixed> $bundle
     * @return array<string, mixed>|WP_Error
     */
    public function dry_run( array $bundle, ?int $actor_user_id = null ): array | WP_Error
    {
        if ( self::SCHEMA_VERSION !== (string) ( $bundle['schema_version'] ?? '' ) )
        {
            return new WP_Error(
                'sentient_forms_invalid_import_bundle_schema',
                __( 'The import bundle schema version is not supported.', 'sentient-forms' ),
                [
                    'status'          => 400,
                    'expected_schema' => self::SCHEMA_VERSION,
                ]
            );
        }

        $collections = $this->normalize_collections( $bundle );
        if ( is_wp_error( $collections ) )
        {
            return $collections;
        }

        $report = $this->build_report( $bundle, $collections );
        $run_id = $this->migration_runs->create(
            [
                'source'         => self::SOURCE,
                'source_version' => $this->source_version( $bundle ),
                'status'         => 'dry_run',
                'dry_run'        => true,
                'summary_json'   => $this->summarize_report( $report ),
                'conflicts_json' => [
                    'conflicts' => $report['conflicts'],
                    'warnings'  => $report['warnings'],
                ],
                'mapping_json'   => $report['mapping'],
                'actor_user_id'  => $actor_user_id,
            ]
        );

        if ( is_wp_error( $run_id ) )
        {
            return $run_id;
        }

        $finished = $this->migration_runs->mark_finished(
            $run_id,
            'dry_run_complete',
            array_merge(
                $this->summarize_report( $report ),
                [
                    'run_id' => $run_id,
                ]
            )
        );

        if ( is_wp_error( $finished ) )
        {
            return $finished;
        }

        if ( true !== $finished )
        {
            return new WP_Error(
                'sentient_forms_migration_run_update_failed',
                __( 'The import dry-run could not be finalized.', 'sentient-forms' ),
                [ 'status' => 500 ]
            );
        }

        return [
            'run_id' => $run_id,
            'status' => 'dry_run_complete',
            'dry_run' => true,
            'report' => $report,
        ];
    }

    /**
     * @param array<string, mixed> $bundle
     * @return array<string, array<int, array<string, mixed>>>|WP_Error
     */
    private function normalize_collections( array $bundle ): array | WP_Error
    {
        $collections = [];
        foreach ( [ 'action_templates', 'custom_actions', 'form_mappings', 'execution_events' ] as $key )
        {
            $items = $this->collection( $bundle, $key );
            if ( is_wp_error( $items ) )
            {
                return $items;
            }

            $collections[ $key ] = $items;
        }

        return $collections;
    }

    /**
     * @param array<string, mixed>                                  $bundle
     * @param array<string, array<int, array<string, mixed>>>       $collections
     * @return array<string, mixed>
     */
    private function build_report( array $bundle, array $collections ): array
    {
        $conflicts = [];
        $warnings  = [];
        $mapping   = [
            'action_templates' => [],
            'custom_actions'   => [],
            'form_mappings'    => [],
            'execution_events' => [],
            'settings'         => [],
        ];
        $changes   = [
            'action_templates' => [ 'create' => 0, 'update' => 0, 'blocked' => 0 ],
            'custom_actions'   => [ 'create' => 0, 'update' => 0, 'blocked' => 0 ],
            'form_mappings'    => [ 'create' => 0, 'update' => 0, 'blocked' => 0 ],
            'execution_events' => [ 'create' => 0, 'update' => 0, 'blocked' => 0 ],
            'settings'         => [ 'review' => 0, 'blocked' => 0 ],
            'total_writes'     => 0,
        ];

        $template_indexes = $this->template_indexes( $collections['action_templates'] );
        $action_indexes   = $this->custom_action_indexes( $collections['custom_actions'] );
        $conflicts        = array_merge(
            $conflicts,
            $this->duplicate_conflicts( $template_indexes['codes'], 'action_template', 'code' ),
            $this->duplicate_conflicts( $action_indexes['codes'], 'custom_action', 'code' )
        );

        $this->plan_templates( $collections['action_templates'], $template_indexes, $mapping, $changes, $warnings );
        $this->plan_custom_actions( $collections['custom_actions'], $template_indexes, $mapping, $changes, $conflicts, $warnings );
        $this->plan_form_mappings( $collections['form_mappings'], $action_indexes, $mapping, $changes, $conflicts );
        $this->plan_execution_events( $collections['execution_events'], $mapping, $changes, $conflicts );
        $this->plan_settings( $bundle['settings'] ?? [], $mapping, $changes );

        $changes['total_writes'] = $this->total_planned_writes( $changes );

        return [
            'schema_version'  => self::SCHEMA_VERSION,
            'source'          => self::SOURCE,
            'source_version'  => $this->source_version( $bundle ),
            'generated_at'    => gmdate( 'c' ),
            'exported_at'     => isset( $bundle['exported_at'] ) ? sanitize_text_field( (string) $bundle['exported_at'] ) : null,
            'ready_to_import' => [] === $conflicts,
            'counts'          => [
                'action_templates' => count( $collections['action_templates'] ),
                'custom_actions'   => count( $collections['custom_actions'] ),
                'form_mappings'    => count( $collections['form_mappings'] ),
                'execution_events' => count( $collections['execution_events'] ),
                'settings_keys'    => is_array( $bundle['settings'] ?? null ) ? count( $bundle['settings'] ) : 0,
            ],
            'changes'         => $changes,
            'conflicts'       => $conflicts,
            'warnings'        => $warnings,
            'mapping'         => $mapping,
        ];
    }

    /**
     * @param array<string, mixed> $bundle
     * @return array<int, array<string, mixed>>|WP_Error
     */
    private function collection( array $bundle, string $key ): array | WP_Error
    {
        if ( ! array_key_exists( $key, $bundle ) || null === $bundle[ $key ] )
        {
            return [];
        }

        if ( ! is_array( $bundle[ $key ] ) )
        {
            return new WP_Error(
                'sentient_forms_invalid_import_collection',
                sprintf(
                    /* translators: %s: Import collection key. */
                    __( '%s must be an array in the import bundle.', 'sentient-forms' ),
                    $key
                ),
                [ 'status' => 400 ]
            );
        }

        $items = [];
        foreach ( $bundle[ $key ] as $index => $item )
        {
            if ( ! is_array( $item ) )
            {
                return new WP_Error(
                    'sentient_forms_invalid_import_collection_item',
                    sprintf(
                        /* translators: 1: Import collection key. 2: Item index. */
                        __( '%1$s item %2$s must be an object.', 'sentient-forms' ),
                        $key,
                        (string) $index
                    ),
                    [ 'status' => 400 ]
                );
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $templates
     * @return array{codes: array<string, int>, external_ids: array<string, string>}
     */
    private function template_indexes( array $templates ): array
    {
        $codes        = [];
        $external_ids = [];
        foreach ( $templates as $template )
        {
            $code = $this->code( $template, 'code' );
            if ( '' !== $code )
            {
                $codes[ $code ] = ( $codes[ $code ] ?? 0 ) + 1;
            }

            $external_id = $this->text( $template, 'external_id' );
            if ( '' !== $external_id && '' !== $code )
            {
                $external_ids[ $external_id ] = $code;
            }
        }

        return [
            'codes'        => $codes,
            'external_ids' => $external_ids,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $actions
     * @return array{codes: array<string, int>, external_ids: array<string, string>}
     */
    private function custom_action_indexes( array $actions ): array
    {
        $codes        = [];
        $external_ids = [];
        foreach ( $actions as $action )
        {
            $code = $this->code( $action, 'code' );
            if ( '' !== $code )
            {
                $codes[ $code ] = ( $codes[ $code ] ?? 0 ) + 1;
            }

            $external_id = $this->text( $action, 'external_id' );
            if ( '' !== $external_id && '' !== $code )
            {
                $external_ids[ $external_id ] = $code;
            }
        }

        return [
            'codes'        => $codes,
            'external_ids' => $external_ids,
        ];
    }

    /**
     * @param array<string, int> $values
     * @return array<int, array<string, mixed>>
     */
    private function duplicate_conflicts( array $values, string $entity, string $field ): array
    {
        $conflicts = [];
        foreach ( $values as $value => $count )
        {
            if ( $count > 1 )
            {
                $conflicts[] = [
                    'code'     => 'duplicate_' . $entity . '_' . $field,
                    'entity'   => $entity,
                    'field'    => $field,
                    'value'    => $value,
                    'severity' => 'error',
                    'message'  => sprintf(
                        /* translators: 1: Entity name. 2: Field value. */
                        __( 'The import bundle contains more than one %1$s with %2$s.', 'sentient-forms' ),
                        $entity,
                        $value
                    ),
                ];
            }
        }

        return $conflicts;
    }

    /**
     * @param array<int, array<string, mixed>>                        $templates
     * @param array{codes: array<string, int>, external_ids: array<string, string>} $template_indexes
     * @param array<string, mixed>                                    $mapping
     * @param array<string, mixed>                                    $changes
     * @param array<int, array<string, mixed>>                        $warnings
     */
    private function plan_templates( array $templates, array $template_indexes, array &$mapping, array &$changes, array &$warnings ): void
    {
        foreach ( $templates as $index => $template )
        {
            $code        = $this->code( $template, 'code' );
            $external_id = $this->text( $template, 'external_id' );
            $key         = '' !== $external_id ? $external_id : 'template:' . (string) $index;

            if ( '' === $code || ( $template_indexes['codes'][ $code ] ?? 0 ) > 1 )
            {
                ++$changes['action_templates']['blocked'];
                $mapping['action_templates'][ $key ] = [
                    'operation'   => 'blocked',
                    'code'        => $code,
                    'external_id' => $external_id,
                    'reason'      => '' === $code ? 'missing_code' : 'duplicate_code',
                ];
                continue;
            }

            $existing  = $this->templates->get_by_code( $code );
            $operation = $existing ? 'update' : 'create';
            ++$changes['action_templates'][ $operation ];

            if ( $existing && '' !== $external_id && ! empty( $existing['external_id'] ) && $external_id !== (string) $existing['external_id'] )
            {
                $warnings[] = [
                    'code'     => 'template_external_id_changed',
                    'entity'   => 'action_template',
                    'field'    => 'external_id',
                    'value'    => $external_id,
                    'severity' => 'warning',
                    'message'  => __( 'An imported template code already exists locally with a different external ID.', 'sentient-forms' ),
                ];
            }

            $mapping['action_templates'][ $key ] = [
                'operation'   => $operation,
                'code'        => $code,
                'external_id' => $external_id,
                'local_id'    => $existing ? (int) $existing['id'] : null,
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>>                        $actions
     * @param array{codes: array<string, int>, external_ids: array<string, string>} $template_indexes
     * @param array<string, mixed>                                    $mapping
     * @param array<string, mixed>                                    $changes
     * @param array<int, array<string, mixed>>                        $conflicts
     * @param array<int, array<string, mixed>>                        $warnings
     */
    private function plan_custom_actions( array $actions, array $template_indexes, array &$mapping, array &$changes, array &$conflicts, array &$warnings ): void
    {
        $action_indexes = $this->custom_action_indexes( $actions );
        foreach ( $actions as $index => $action )
        {
            $code        = $this->code( $action, 'code' );
            $external_id = $this->text( $action, 'external_id' );
            $key         = '' !== $external_id ? $external_id : 'custom_action:' . (string) $index;

            if ( '' === $code || ( $action_indexes['codes'][ $code ] ?? 0 ) > 1 )
            {
                ++$changes['custom_actions']['blocked'];
                $mapping['custom_actions'][ $key ] = [
                    'operation'   => 'blocked',
                    'code'        => $code,
                    'external_id' => $external_id,
                    'reason'      => '' === $code ? 'missing_code' : 'duplicate_code',
                ];
                continue;
            }

            $template_ref = $this->resolve_template_reference( $action, $template_indexes );
            if ( is_wp_error( $template_ref ) )
            {
                ++$changes['custom_actions']['blocked'];
                $conflicts[] = $this->reference_conflict( 'custom_action_template_missing', 'custom_action', $code, $template_ref );
                $mapping['custom_actions'][ $key ] = [
                    'operation'   => 'blocked',
                    'code'        => $code,
                    'external_id' => $external_id,
                    'reason'      => 'missing_template_reference',
                ];
                continue;
            }

            $existing  = $this->custom_actions->get_by_code( $code );
            $operation = $existing ? 'update' : 'create';
            ++$changes['custom_actions'][ $operation ];

            if ( $existing && '' !== $external_id && ! empty( $existing['external_id'] ) && $external_id !== (string) $existing['external_id'] )
            {
                $warnings[] = [
                    'code'     => 'custom_action_external_id_changed',
                    'entity'   => 'custom_action',
                    'field'    => 'external_id',
                    'value'    => $external_id,
                    'severity' => 'warning',
                    'message'  => __( 'An imported custom action code already exists locally with a different external ID.', 'sentient-forms' ),
                ];
            }

            $mapping['custom_actions'][ $key ] = [
                'operation'          => $operation,
                'code'               => $code,
                'external_id'        => $external_id,
                'template_operation' => $template_ref['operation'],
                'template_code'      => $template_ref['code'],
                'local_id'           => $existing ? (int) $existing['id'] : null,
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>>                        $form_mappings
     * @param array{codes: array<string, int>, external_ids: array<string, string>} $action_indexes
     * @param array<string, mixed>                                    $mapping
     * @param array<string, mixed>                                    $changes
     * @param array<int, array<string, mixed>>                        $conflicts
     */
    private function plan_form_mappings( array $form_mappings, array $action_indexes, array &$mapping, array &$changes, array &$conflicts ): void
    {
        foreach ( $form_mappings as $index => $form_mapping )
        {
            $external_id = $this->text( $form_mapping, 'external_id' );
            $key         = '' !== $external_id ? $external_id : 'form_mapping:' . (string) $index;
            $action_ref  = $this->resolve_action_reference( $form_mapping, $action_indexes );
            $form_source = $this->code( $form_mapping, 'form_source', 'gravity_forms' );
            $form_id     = $this->text( $form_mapping, 'form_id' );

            if ( is_wp_error( $action_ref ) || '' === $form_source || '' === $form_id )
            {
                ++$changes['form_mappings']['blocked'];
                if ( is_wp_error( $action_ref ) )
                {
                    $conflicts[] = $this->reference_conflict( 'form_mapping_action_missing', 'form_mapping', $key, $action_ref );
                }
                else
                {
                    $conflicts[] = [
                        'code'     => 'form_mapping_missing_form_identity',
                        'entity'   => 'form_mapping',
                        'value'    => $key,
                        'severity' => 'error',
                        'message'  => __( 'An imported form mapping is missing form_source or form_id.', 'sentient-forms' ),
                    ];
                }

                $mapping['form_mappings'][ $key ] = [
                    'operation'   => 'blocked',
                    'external_id' => $external_id,
                    'reason'      => is_wp_error( $action_ref ) ? 'missing_action_reference' : 'missing_form_identity',
                ];
                continue;
            }

            $existing  = $this->existing_mapping( $form_mapping, $action_ref );
            $operation = $existing ? 'update' : 'create';
            ++$changes['form_mappings'][ $operation ];

            $mapping['form_mappings'][ $key ] = [
                'operation'   => $operation,
                'external_id' => $external_id,
                'form_source' => $form_source,
                'form_id'     => $form_id,
                'hook'        => $this->code( $form_mapping, 'hook' ),
                'action_code' => $action_ref['code'],
                'local_id'    => $existing ? (int) $existing['id'] : null,
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $execution_events
     * @param array<string, mixed>             $mapping
     * @param array<string, mixed>             $changes
     * @param array<int, array<string, mixed>> $conflicts
     */
    private function plan_execution_events( array $execution_events, array &$mapping, array &$changes, array &$conflicts ): void
    {
        foreach ( $execution_events as $index => $event )
        {
            $request_id = $this->text( $event, 'execution_request_id' );
            $key        = '' !== $request_id ? $request_id : 'execution_event:' . (string) $index;

            if ( '' === $request_id )
            {
                ++$changes['execution_events']['blocked'];
                $conflicts[] = [
                    'code'     => 'execution_event_missing_request_id',
                    'entity'   => 'execution_event',
                    'value'    => $key,
                    'severity' => 'error',
                    'message'  => __( 'An imported execution event is missing execution_request_id.', 'sentient-forms' ),
                ];
                $mapping['execution_events'][ $key ] = [
                    'operation' => 'blocked',
                    'reason'    => 'missing_execution_request_id',
                ];
                continue;
            }

            $existing  = $this->events->get_by_request_id( $request_id );
            $operation = $existing ? 'update' : 'create';
            ++$changes['execution_events'][ $operation ];

            $mapping['execution_events'][ $key ] = [
                'operation'            => $operation,
                'execution_request_id' => $request_id,
                'local_id'             => $existing ? (int) $existing['id'] : null,
            ];
        }
    }

    /**
     * @param mixed                $settings
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $changes
     */
    private function plan_settings( mixed $settings, array &$mapping, array &$changes ): void
    {
        if ( ! is_array( $settings ) || [] === $settings )
        {
            return;
        }

        $keys = array_values( array_map( 'sanitize_key', array_keys( $settings ) ) );
        $changes['settings']['review'] = count( $keys );
        $mapping['settings'] = [
            'operation' => 'review',
            'keys'      => $keys,
            'reason'    => 'settings_import_deferred_until_apply_step',
        ];
    }

    /**
     * @param array<string, mixed>                                    $action
     * @param array{codes: array<string, int>, external_ids: array<string, string>} $template_indexes
     * @return array{operation: string, code: string, local_id: int|null}|WP_Error
     */
    private function resolve_template_reference( array $action, array $template_indexes ): array | WP_Error
    {
        $template_code = $this->code( $action, 'template_code' );
        if ( '' === $template_code )
        {
            $template_external_id = $this->text( $action, 'template_external_id' );
            $template_code        = $template_indexes['external_ids'][ $template_external_id ] ?? '';
        }

        if ( '' === $template_code )
        {
            return new WP_Error( 'sentient_forms_missing_template_reference', __( 'A custom action is missing a template reference.', 'sentient-forms' ) );
        }

        if ( isset( $template_indexes['codes'][ $template_code ] ) )
        {
            $existing = $this->templates->get_by_code( $template_code );
            return [
                'operation' => 'create_or_update',
                'code'      => $template_code,
                'local_id'  => $existing ? (int) $existing['id'] : null,
            ];
        }

        $existing = $this->templates->get_by_code( $template_code );
        if ( ! $existing )
        {
            return new WP_Error( 'sentient_forms_template_reference_not_found', __( 'The referenced template is not in the bundle or local table.', 'sentient-forms' ) );
        }

        return [
            'operation' => 'existing',
            'code'      => $template_code,
            'local_id'  => (int) $existing['id'],
        ];
    }

    /**
     * @param array<string, mixed>                                    $form_mapping
     * @param array{codes: array<string, int>, external_ids: array<string, string>} $action_indexes
     * @return array{operation: string, code: string, local_id: int|null}|WP_Error
     */
    private function resolve_action_reference( array $form_mapping, array $action_indexes ): array | WP_Error
    {
        $action_code = $this->code( $form_mapping, 'action_code' );
        if ( '' === $action_code )
        {
            $action_external_id = $this->text( $form_mapping, 'action_external_id' );
            $action_code        = $action_indexes['external_ids'][ $action_external_id ] ?? '';
        }

        if ( '' === $action_code )
        {
            return new WP_Error( 'sentient_forms_missing_action_reference', __( 'A form mapping is missing a custom action reference.', 'sentient-forms' ) );
        }

        if ( isset( $action_indexes['codes'][ $action_code ] ) )
        {
            $existing = $this->custom_actions->get_by_code( $action_code );
            return [
                'operation' => 'create_or_update',
                'code'      => $action_code,
                'local_id'  => $existing ? (int) $existing['id'] : null,
            ];
        }

        $existing = $this->custom_actions->get_by_code( $action_code );
        if ( ! $existing )
        {
            return new WP_Error( 'sentient_forms_action_reference_not_found', __( 'The referenced custom action is not in the bundle or local table.', 'sentient-forms' ) );
        }

        return [
            'operation' => 'existing',
            'code'      => $action_code,
            'local_id'  => (int) $existing['id'],
        ];
    }

    /**
     * @param array<string, mixed>                                   $form_mapping
     * @param array{operation: string, code: string, local_id: int|null} $action_ref
     * @return array<string, mixed>|null
     */
    private function existing_mapping( array $form_mapping, array $action_ref ): ?array
    {
        $form_source = $this->code( $form_mapping, 'form_source', 'gravity_forms' );
        $form_id     = $this->text( $form_mapping, 'form_id' );
        $external_id = $this->text( $form_mapping, 'external_id' );
        $hook        = $this->code( $form_mapping, 'hook' );

        foreach ( $this->mappings->list_for_form( $form_source, $form_id ) as $candidate )
        {
            if ( '' !== $external_id && $external_id === (string) ( $candidate['external_id'] ?? '' ) )
            {
                return $candidate;
            }

            if (
                null !== $action_ref['local_id']
                && $hook === (string) ( $candidate['hook'] ?? '' )
                && (string) ( $candidate['action_kind'] ?? '' ) === $this->code( $form_mapping, 'action_kind', 'custom_action' )
                && (int) ( $candidate['action_id'] ?? 0 ) === (int) $action_ref['local_id']
            )
            {
                return $candidate;
            }
        }

        return null;
    }

    private function reference_conflict( string $code, string $entity, string $value, WP_Error $error ): array
    {
        return [
            'code'       => $code,
            'entity'     => $entity,
            'value'      => $value,
            'severity'   => 'error',
            'wp_error'   => $error->get_error_code(),
            'message'    => $error->get_error_message(),
        ];
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function source_version( array $bundle ): string
    {
        $source_version = $bundle['source_version'] ?? $bundle['export_version'] ?? self::SCHEMA_VERSION;
        return sanitize_text_field( (string) $source_version );
    }

    /**
     * @param array<string, mixed> $item
     */
    private function code( array $item, string $key, string $default = '' ): string
    {
        if ( ! array_key_exists( $key, $item ) || null === $item[ $key ] )
        {
            return sanitize_key( $default );
        }

        return sanitize_key( (string) $item[ $key ] );
    }

    /**
     * @param array<string, mixed> $item
     */
    private function text( array $item, string $key ): string
    {
        if ( ! array_key_exists( $key, $item ) || null === $item[ $key ] )
        {
            return '';
        }

        return sanitize_text_field( (string) $item[ $key ] );
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function total_planned_writes( array $changes ): int
    {
        $total = 0;
        foreach ( [ 'action_templates', 'custom_actions', 'form_mappings', 'execution_events' ] as $key )
        {
            $total += (int) ( $changes[ $key ]['create'] ?? 0 );
            $total += (int) ( $changes[ $key ]['update'] ?? 0 );
        }

        return $total;
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function summarize_report( array $report ): array
    {
        return [
            'schema_version'  => $report['schema_version'],
            'source'          => $report['source'],
            'source_version'  => $report['source_version'],
            'ready_to_import' => $report['ready_to_import'],
            'counts'          => $report['counts'],
            'changes'         => $report['changes'],
            'conflict_count'  => count( $report['conflicts'] ),
            'warning_count'   => count( $report['warnings'] ),
        ];
    }
}
