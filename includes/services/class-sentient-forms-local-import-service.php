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
     * Validate and apply a CPS export bundle into local-first tables.
     *
     * @param array<string, mixed> $bundle
     * @return array<string, mixed>|WP_Error
     */
    public function apply( array $bundle, ?int $actor_user_id = null ): array | WP_Error
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
        if ( ! $report['ready_to_import'] )
        {
            $run_id = $this->record_apply_run( $bundle, $report, $actor_user_id, 'import_blocked' );
            if ( is_wp_error( $run_id ) )
            {
                return $run_id;
            }

            $this->migration_runs->mark_finished(
                $run_id,
                'import_blocked',
                array_merge(
                    $this->summarize_report( $report ),
                    [
                        'run_id' => $run_id,
                    ]
                ),
                [
                    'conflicts' => $report['conflicts'],
                    'warnings'  => $report['warnings'],
                ],
                $report['mapping']
            );

            return new WP_Error(
                'sentient_forms_import_bundle_not_ready',
                __( 'The import bundle has conflicts or blocked records. Run a dry-run report and resolve them before importing.', 'sentient-forms' ),
                [
                    'status' => 409,
                    'run_id' => $run_id,
                    'report' => $report,
                ]
            );
        }

        $run_id = $this->record_apply_run( $bundle, $report, $actor_user_id, 'importing' );
        if ( is_wp_error( $run_id ) )
        {
            return $run_id;
        }

        $applied = $this->apply_collections( $collections, $report );
        if ( is_wp_error( $applied ) )
        {
            $this->migration_runs->mark_finished(
                $run_id,
                'failed',
                array_merge(
                    $this->summarize_report( $report ),
                    [
                        'run_id'     => $run_id,
                        'error_code' => $applied->get_error_code(),
                    ]
                ),
                [
                    'conflicts' => $report['conflicts'],
                    'warnings'  => $report['warnings'],
                ],
                $report['mapping']
            );
            $applied->add_data(
                array_merge(
                    is_array( $applied->get_error_data() ) ? $applied->get_error_data() : [],
                    [
                        'status' => 500,
                        'run_id' => $run_id,
                    ]
                )
            );
            return $applied;
        }

        $summary = array_merge(
            $this->summarize_report( $report ),
            [
                'run_id'  => $run_id,
                'applied' => $applied,
            ]
        );
        $finished = $this->migration_runs->mark_finished(
            $run_id,
            'completed',
            $summary,
            [
                'conflicts' => $report['conflicts'],
                'warnings'  => $report['warnings'],
            ],
            $report['mapping']
        );

        if ( is_wp_error( $finished ) )
        {
            return $finished;
        }

        if ( true !== $finished )
        {
            return new WP_Error(
                'sentient_forms_migration_run_update_failed',
                __( 'The import run could not be finalized.', 'sentient-forms' ),
                [ 'status' => 500 ]
            );
        }

        return [
            'run_id'  => $run_id,
            'status'  => 'completed',
            'dry_run' => false,
            'report'  => $report,
            'applied' => $applied,
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
            $this->duplicate_conflicts( $action_indexes['codes'], 'custom_action', 'code' ),
            $this->json_shape_conflicts(
                $collections['action_templates'],
                'action_template',
                [
                    'structured_output_schema' => false,
                    'override_schema'          => false,
                ]
            ),
            $this->json_shape_conflicts(
                $collections['custom_actions'],
                'custom_action',
                [
                    'definition_json'      => true,
                    'model_selection_json' => false,
                ]
            ),
            $this->json_shape_conflicts(
                $collections['form_mappings'],
                'form_mapping',
                [
                    'conditions_json'     => false,
                    'input_bindings_json' => true,
                    'effect_mapping_json' => false,
                ]
            ),
            $this->json_shape_conflicts(
                $collections['execution_events'],
                'execution_event',
                [
                    'token_usage_json' => false,
                    'cost_json'        => false,
                    'result_json'      => false,
                ]
            )
        );

        $this->plan_templates( $collections['action_templates'], $template_indexes, $mapping, $changes, $warnings );
        $this->plan_custom_actions( $collections['custom_actions'], $template_indexes, $mapping, $changes, $conflicts, $warnings );
        $this->plan_form_mappings( $collections['form_mappings'], $action_indexes, $mapping, $changes, $conflicts );
        $this->plan_execution_events( $collections['execution_events'], $mapping, $changes, $conflicts );
        $this->plan_settings( $bundle['settings'] ?? [], $mapping, $changes );

        $changes['total_writes'] = $this->total_planned_writes( $changes );

        $ready_to_import = [] === $conflicts && ! $this->has_blocked_changes( $changes );

        return [
            'schema_version'  => self::SCHEMA_VERSION,
            'source'          => self::SOURCE,
            'source_version'  => $this->source_version( $bundle ),
            'generated_at'    => gmdate( 'c' ),
            'exported_at'     => isset( $bundle['exported_at'] ) ? sanitize_text_field( (string) $bundle['exported_at'] ) : null,
            'ready_to_import' => $ready_to_import,
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
     * @param array<string, mixed> $report
     */
    private function record_apply_run( array $bundle, array $report, ?int $actor_user_id, string $status ): int | WP_Error
    {
        return $this->migration_runs->create(
            [
                'source'         => self::SOURCE,
                'source_version' => $this->source_version( $bundle ),
                'status'         => $status,
                'dry_run'        => false,
                'summary_json'   => $this->summarize_report( $report ),
                'conflicts_json' => [
                    'conflicts' => $report['conflicts'],
                    'warnings'  => $report['warnings'],
                ],
                'mapping_json'   => $report['mapping'],
                'actor_user_id'  => $actor_user_id,
            ]
        );
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $collections
     * @param array<string, mixed>                            $report
     * @return array<string, mixed>|WP_Error
     */
    private function apply_collections( array $collections, array &$report ): array | WP_Error
    {
        $applied = [
            'action_templates' => 0,
            'custom_actions'   => 0,
            'form_mappings'    => 0,
            'execution_events' => 0,
            'settings'         => 0,
            'total'            => 0,
        ];

        $template_ids = $this->apply_templates( $collections['action_templates'], $report );
        if ( is_wp_error( $template_ids ) )
        {
            return $template_ids;
        }
        $applied['action_templates'] = count( $template_ids );

        $custom_action_ids = $this->apply_custom_actions( $collections['custom_actions'], $template_ids, $report );
        if ( is_wp_error( $custom_action_ids ) )
        {
            return $custom_action_ids;
        }
        $applied['custom_actions'] = count( $custom_action_ids );

        $mapping_ids = $this->apply_form_mappings( $collections['form_mappings'], $custom_action_ids, $report );
        if ( is_wp_error( $mapping_ids ) )
        {
            return $mapping_ids;
        }
        $applied['form_mappings'] = count( $mapping_ids );

        $event_ids = $this->apply_execution_events( $collections['execution_events'], $mapping_ids, $report );
        if ( is_wp_error( $event_ids ) )
        {
            return $event_ids;
        }
        $applied['execution_events'] = count( $event_ids );
        $applied['total']            = array_sum(
            [
                $applied['action_templates'],
                $applied['custom_actions'],
                $applied['form_mappings'],
                $applied['execution_events'],
            ]
        );

        return $applied;
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
     * @param array<int, array<string, mixed>> $items
     * @param array<string, bool>              $fields Required flags keyed by field name.
     * @return array<int, array<string, mixed>>
     */
    private function json_shape_conflicts( array $items, string $entity, array $fields ): array
    {
        $conflicts = [];
        foreach ( $items as $index => $item )
        {
            $item_id = $this->text( $item, 'external_id' ) ?: $this->code( $item, 'code' ) ?: $entity . ':' . (string) $index;
            foreach ( $fields as $field => $required )
            {
                if ( ! array_key_exists( $field, $item ) || null === $item[ $field ] )
                {
                    if ( $required )
                    {
                        $conflicts[] = [
                            'code'     => $entity . '_missing_' . $field,
                            'entity'   => $entity,
                            'field'    => $field,
                            'value'    => $item_id,
                            'severity' => 'error',
                            'message'  => sprintf(
                                /* translators: 1: Entity name. 2: JSON field name. */
                                __( 'The imported %1$s is missing required JSON field %2$s.', 'sentient-forms' ),
                                $entity,
                                $field
                            ),
                        ];
                    }
                    continue;
                }

                if ( ! is_array( $item[ $field ] ) )
                {
                    $conflicts[] = [
                        'code'     => $entity . '_invalid_' . $field,
                        'entity'   => $entity,
                        'field'    => $field,
                        'value'    => $item_id,
                        'severity' => 'error',
                        'message'  => sprintf(
                            /* translators: 1: Entity name. 2: JSON field name. */
                            __( 'The imported %1$s field %2$s must be an object.', 'sentient-forms' ),
                            $entity,
                            $field
                        ),
                    ];
                }
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
     * @param array<int, array<string, mixed>> $templates
     * @param array<string, mixed>             $report
     * @return array<string, int>|WP_Error
     */
    private function apply_templates( array $templates, array &$report ): array | WP_Error
    {
        $ids_by_code = [];
        foreach ( $templates as $index => $template )
        {
            $key = $this->mapping_key( $template, 'template', $index );
            if ( 'blocked' === ( $report['mapping']['action_templates'][ $key ]['operation'] ?? '' ) )
            {
                continue;
            }

            $code = $this->code( $template, 'code' );
            $id   = $this->templates->upsert_by_code(
                [
                    'source'                   => $this->code( $template, 'source', 'imported' ),
                    'external_id'              => $this->text( $template, 'external_id' ),
                    'code'                     => $code,
                    'display_name'             => $this->text( $template, 'display_name' ) ?: $code,
                    'description'              => $this->text_or_null( $template, 'description' ),
                    'prompt_template'          => (string) ( $template['prompt_template'] ?? '' ),
                    'default_model'            => $this->text_or_null( $template, 'default_model' ),
                    'structured_output_schema' => $this->array_value( $template, 'structured_output_schema' ),
                    'override_schema'          => $this->array_value( $template, 'override_schema' ),
                    'version'                  => $this->text( $template, 'version' ) ?: '1',
                    'is_active'                => $this->bool_value( $template, 'is_active', true ),
                ]
            );

            if ( is_wp_error( $id ) )
            {
                return $id;
            }

            $ids_by_code[ $code ] = $id;
            $report['mapping']['action_templates'][ $key ]['local_id'] = $id;
        }

        return $ids_by_code;
    }

    /**
     * @param array<int, array<string, mixed>> $actions
     * @param array<string, int>               $template_ids
     * @param array<string, mixed>             $report
     * @return array<string, int>|WP_Error
     */
    private function apply_custom_actions( array $actions, array $template_ids, array &$report ): array | WP_Error
    {
        $ids_by_code = [];
        foreach ( $actions as $index => $action )
        {
            $key = $this->mapping_key( $action, 'custom_action', $index );
            if ( 'blocked' === ( $report['mapping']['custom_actions'][ $key ]['operation'] ?? '' ) )
            {
                continue;
            }

            $code          = $this->code( $action, 'code' );
            $template_code = (string) ( $report['mapping']['custom_actions'][ $key ]['template_code'] ?? '' );
            $template_id   = $template_ids[ $template_code ] ?? null;
            if ( null === $template_id )
            {
                $template = $this->templates->get_by_code( $template_code );
                $template_id = $template ? (int) $template['id'] : null;
            }

            if ( null === $template_id )
            {
                return new WP_Error(
                    'sentient_forms_import_template_missing_during_apply',
                    __( 'An imported custom action references a template that could not be resolved during apply.', 'sentient-forms' )
                );
            }

            $id = $this->custom_actions->upsert_by_code(
                [
                    'external_id'          => $this->text( $action, 'external_id' ),
                    'template_id'          => $template_id,
                    'code'                 => $code,
                    'display_name'         => $this->text( $action, 'display_name' ) ?: $code,
                    'definition_json'      => $this->array_value( $action, 'definition_json' ) ?: [],
                    'model_selection_json' => $this->array_value( $action, 'model_selection_json' ),
                    'status'               => $this->code( $action, 'status', 'active' ),
                ]
            );

            if ( is_wp_error( $id ) )
            {
                return $id;
            }

            $ids_by_code[ $code ] = $id;
            $report['mapping']['custom_actions'][ $key ]['local_id'] = $id;
        }

        return $ids_by_code;
    }

    /**
     * @param array<int, array<string, mixed>> $form_mappings
     * @param array<string, int>               $custom_action_ids
     * @param array<string, mixed>             $report
     * @return array<string, int>|WP_Error
     */
    private function apply_form_mappings( array $form_mappings, array $custom_action_ids, array &$report ): array | WP_Error
    {
        $ids_by_key = [];
        foreach ( $form_mappings as $index => $form_mapping )
        {
            $key = $this->mapping_key( $form_mapping, 'form_mapping', $index );
            if ( 'blocked' === ( $report['mapping']['form_mappings'][ $key ]['operation'] ?? '' ) )
            {
                continue;
            }

            $action_code = (string) ( $report['mapping']['form_mappings'][ $key ]['action_code'] ?? '' );
            $action_id   = $custom_action_ids[ $action_code ] ?? null;
            if ( null === $action_id )
            {
                $action = $this->custom_actions->get_by_code( $action_code );
                $action_id = $action ? (int) $action['id'] : null;
            }

            if ( null === $action_id )
            {
                return new WP_Error(
                    'sentient_forms_import_action_missing_during_apply',
                    __( 'An imported form mapping references a custom action that could not be resolved during apply.', 'sentient-forms' )
                );
            }

            $payload = [
                'external_id'         => $this->text_or_null( $form_mapping, 'external_id' ),
                'form_source'         => $this->code( $form_mapping, 'form_source', 'gravity_forms' ),
                'form_id'             => $this->text( $form_mapping, 'form_id' ),
                'hook'                => $this->code( $form_mapping, 'hook' ),
                'action_kind'         => $this->code( $form_mapping, 'action_kind', 'custom_action' ),
                'action_id'           => $action_id,
                'conditions_json'     => $this->array_value( $form_mapping, 'conditions_json' ),
                'input_bindings_json' => $this->array_value( $form_mapping, 'input_bindings_json' ) ?: [],
                'execution_mode'      => $this->code( $form_mapping, 'execution_mode', 'async' ),
                'effect_mapping_json' => $this->array_value( $form_mapping, 'effect_mapping_json' ),
                'enabled'             => $this->bool_value( $form_mapping, 'enabled', true ),
            ];
            $existing = $this->existing_mapping(
                $form_mapping,
                [
                    'operation' => 'applied',
                    'code'      => $action_code,
                    'local_id'  => $action_id,
                ]
            );

            $id = $existing ? $this->mappings->update( (int) $existing['id'], $payload ) : $this->mappings->create( $payload );
            if ( is_wp_error( $id ) )
            {
                return $id;
            }

            $local_id = is_array( $id ) ? (int) $id['id'] : (int) $id;
            $ids_by_key[ $key ] = $local_id;
            $external_id = $this->text( $form_mapping, 'external_id' );
            if ( '' !== $external_id )
            {
                $ids_by_key[ $external_id ] = $local_id;
            }
            $report['mapping']['form_mappings'][ $key ]['local_id'] = $local_id;
        }

        return $ids_by_key;
    }

    /**
     * @param array<int, array<string, mixed>> $execution_events
     * @param array<string, int>               $mapping_ids
     * @param array<string, mixed>             $report
     * @return array<string, int>|WP_Error
     */
    private function apply_execution_events( array $execution_events, array $mapping_ids, array &$report ): array | WP_Error
    {
        $ids_by_request = [];
        foreach ( $execution_events as $index => $event )
        {
            $request_id = $this->text( $event, 'execution_request_id' );
            $key        = '' !== $request_id ? $request_id : 'execution_event:' . (string) $index;
            if ( 'blocked' === ( $report['mapping']['execution_events'][ $key ]['operation'] ?? '' ) )
            {
                continue;
            }

            $mapping_id = $this->resolve_event_mapping_id( $event, $mapping_ids );
            $id         = $this->events->record(
                [
                    'execution_request_id' => $request_id,
                    'mapping_id'           => $mapping_id,
                    'form_source'          => $this->text_or_null( $event, 'form_source' ),
                    'form_id'              => $this->text_or_null( $event, 'form_id' ),
                    'entry_id'             => $this->text_or_null( $event, 'entry_id' ),
                    'provider'             => $this->code( $event, 'provider', 'openrouter' ),
                    'model'                => $this->text_or_null( $event, 'model' ),
                    'status'               => $this->code( $event, 'status', 'succeeded' ),
                    'token_usage_json'     => $this->array_value( $event, 'token_usage_json' ),
                    'cost_json'            => $this->array_value( $event, 'cost_json' ),
                    'result_json'          => $this->array_value( $event, 'result_json' ),
                    'error_code'           => $this->text_or_null( $event, 'error_code' ),
                    'error_message'        => $this->text_or_null( $event, 'error_message' ),
                    'payload_digest'       => $this->text_or_null( $event, 'payload_digest' ),
                    'expires_at'           => $this->text_or_null( $event, 'expires_at' ),
                ]
            );

            if ( is_wp_error( $id ) )
            {
                return $id;
            }

            $ids_by_request[ $request_id ] = $id;
            $report['mapping']['execution_events'][ $key ]['local_id']   = $id;
            $report['mapping']['execution_events'][ $key ]['mapping_id'] = $mapping_id;
        }

        return $ids_by_request;
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

    /**
     * @param array<string, mixed> $event
     * @param array<string, int>   $mapping_ids
     */
    private function resolve_event_mapping_id( array $event, array $mapping_ids ): ?int
    {
        $mapping_external_id = $this->text( $event, 'mapping_external_id' );
        if ( '' !== $mapping_external_id && isset( $mapping_ids[ $mapping_external_id ] ) )
        {
            return (int) $mapping_ids[ $mapping_external_id ];
        }

        $mapping_id = isset( $event['mapping_id'] ) ? absint( $event['mapping_id'] ) : 0;
        return $mapping_id > 0 ? $mapping_id : null;
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
     * @param array<string, mixed> $item
     */
    private function text_or_null( array $item, string $key ): ?string
    {
        $value = $this->text( $item, $key );
        return '' !== $value ? $value : null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function array_value( array $item, string $key ): ?array
    {
        return isset( $item[ $key ] ) && is_array( $item[ $key ] ) ? $item[ $key ] : null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function bool_value( array $item, string $key, bool $default ): bool
    {
        if ( ! array_key_exists( $key, $item ) )
        {
            return $default;
        }

        return rest_sanitize_boolean( $item[ $key ] );
    }

    /**
     * @param array<string, mixed> $item
     */
    private function mapping_key( array $item, string $fallback_prefix, int $index ): string
    {
        $external_id = $this->text( $item, 'external_id' );
        return '' !== $external_id ? $external_id : $fallback_prefix . ':' . (string) $index;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function has_blocked_changes( array $changes ): bool
    {
        foreach ( [ 'action_templates', 'custom_actions', 'form_mappings', 'execution_events', 'settings' ] as $key )
        {
            if ( (int) ( $changes[ $key ]['blocked'] ?? 0 ) > 0 )
            {
                return true;
            }
        }

        return false;
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
