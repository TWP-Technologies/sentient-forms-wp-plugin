<?php
/**
 * Local-first action model selection repair and credential resolution.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Local_Action_Model_Selection_Service
{
    private const READY_CREDENTIAL_STATUSES = [ 'valid', 'limited' ];
    private const EXECUTION_MODES           = [ 'sync', 'async', 'real_time' ];
    private const MANAGED_DEFAULT_MODEL     = 'gemini-3-flash-preview';

    public function __construct(
        private ?Sentient_Forms_Local_Custom_Actions_Repository $custom_actions = null,
        private ?Sentient_Forms_Provider_Credentials_Repository $credentials = null,
        private ?Sentient_Forms_Form_Mappings_Repository $form_mappings = null,
        private ?Sentient_Forms_Model_Cache_Repository $model_cache = null
    )
    {
        global $wpdb;

        $this->custom_actions = $this->custom_actions ?? new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $this->credentials    = $this->credentials ?? new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $this->form_mappings  = $this->form_mappings ?? new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $this->model_cache    = $this->model_cache ?? new Sentient_Forms_Model_Cache_Repository( $wpdb );
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function prepare_model_selection_for_action( array $action ): array
    {
        $definition = is_array( $action['definition_json'] ?? null ) ? $action['definition_json'] : [];
        $selection  = is_array( $action['model_selection_json'] ?? null ) ? $action['model_selection_json'] : [];
        $provider   = sanitize_key( (string) ( $selection['provider'] ?? $definition['provider'] ?? 'openrouter' ) );
        if ( '' === $provider )
        {
            $provider = 'openrouter';
        }

        $selection['provider'] = $provider;
        $template_code         = $this->resolve_bundled_template_code_for_action( $action, $definition );
        if ( '' !== $template_code )
        {
            $definition = Sentient_Forms_Bundled_Action_Templates::get( $template_code ) ?: $definition;
            $model      = isset( $selection['model'] ) && is_scalar( $selection['model'] )
                ? trim( (string) $selection['model'] )
                : '';
            $default_model = sanitize_text_field( (string) ( $definition['default_model'] ?? 'openrouter/auto' ) );

            if ( $this->should_repair_realtime_auto_default( $template_code, $selection ) )
            {
                $selection['model'] = '' !== $default_model ? $default_model : 'sf_realtime';
                $managed_credential = $this->find_single_ready_credential_for_provider( 'sentient_managed' );
                if ( is_array( $managed_credential ) )
                {
                    $provider                   = 'sentient_managed';
                    $selection['provider']      = 'sentient_managed';
                    $selection['credential_id'] = absint( $managed_credential['id'] ?? 0 );
                }
            }
            elseif ( '' === $model || ! str_contains( $model, '/' ) )
            {
                $selection['model'] = '' !== $default_model ? $default_model : 'openrouter/auto';
            }
        }
        elseif ( empty( $selection['model'] ) )
        {
            $selection['model'] = sanitize_text_field( (string) ( $definition['model'] ?? 'openrouter/auto' ) );
        }

        $saved_selection = is_array( $selection['selection'] ?? null ) ? $selection['selection'] : null;
        $saved_model     = isset( $selection['model'] ) && is_scalar( $selection['model'] )
            ? trim( sanitize_text_field( (string) $selection['model'] ) )
            : '';
        if ( is_array( $saved_selection ) )
        {
            $resolved_model = $this->resolve_runtime_model_selection( $saved_selection );
            if ( '' !== $resolved_model )
            {
                $selection['model'] = $resolved_model;
            }
        }
        elseif ( str_starts_with( $saved_model, 'sf_' ) )
        {
            $resolved_model = $this->resolve_local_preset_model_id( sanitize_key( $saved_model ) );
            if ( '' !== $resolved_model )
            {
                $selection['model'] = $resolved_model;
                $selection['selection'] = [
                    'primary'   => $saved_model,
                    'is_preset' => true,
                ];
            }
        }

        if ( empty( $selection['credential_id'] ) )
        {
            $credential = $this->find_single_ready_credential_for_provider( $provider );
            if ( is_array( $credential ) )
            {
                $credential_id = absint( $credential['id'] ?? 0 );
                if ( $credential_id > 0 )
                {
                    $selection['credential_id'] = $credential_id;
                }
            }
        }

        $reasoning = $this->sanitize_reasoning_effort( $selection['reasoning'] ?? null );
        if ( '' !== $reasoning && $this->model_supports_reasoning( (string) ( $selection['model'] ?? '' ), $provider ) )
        {
            $selection['reasoning'] = $reasoning;
        }
        elseif ( isset( $selection['reasoning'] ) )
        {
            unset( $selection['reasoning'] );
        }

        $tools = $this->sanitize_tool_settings( $selection['tools'] ?? ( is_array( $saved_selection ) ? ( $saved_selection['tools'] ?? null ) : null ) );
        if ( [] !== $tools )
        {
            $selection['tools'] = $tools;
        }
        elseif ( isset( $selection['tools'] ) )
        {
            unset( $selection['tools'] );
        }

        return $selection;
    }

    /**
     * Visitor-facing realtime suggestions must not inherit OpenRouter Auto. On older installs the
     * bundled clarification action was seeded with openrouter/auto, which can select slow routes
     * and surface as gateway timeouts in form previews.
     *
     * @param array<string, mixed> $selection
     */
    private function should_repair_realtime_auto_default( string $template_code, array $selection ): bool
    {
        if ( 'clarification_assistant_v1' !== $template_code )
        {
            return false;
        }

        if ( is_array( $selection['selection'] ?? null ) )
        {
            return false;
        }

        $model = isset( $selection['model'] ) && is_scalar( $selection['model'] )
            ? trim( sanitize_text_field( (string) $selection['model'] ) )
            : '';

        return '' === $model || 'openrouter/auto' === $model;
    }

    /**
     * Apply resolved runtime model overrides without mutating the saved action default.
     *
     * @param array<string, mixed> $action
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function prepare_model_selection_for_execution( array $action, array $context = [] ): array
    {
        $selection = $this->prepare_model_selection_for_action( $action );
        $settings  = is_array( $context['settings'] ?? null ) ? $context['settings'] : [];
        $runtime   = is_array( $settings['model_selection'] ?? null ) ? $settings['model_selection'] : null;

        if ( null === $runtime )
        {
            return $selection;
        }

        if ( isset( $runtime['provider'] ) && is_scalar( $runtime['provider'] ) )
        {
            $provider = sanitize_key( (string) $runtime['provider'] );
            if ( in_array( $provider, [ 'openrouter', 'sentient_managed' ], true ) )
            {
                $selection['provider'] = $provider;

                if (
                    isset( $selection['credential_id'] )
                    && ( ! isset( $runtime['credential_id'] ) || absint( $runtime['credential_id'] ) <= 0 )
                    && isset( $action['model_selection_json']['provider'] )
                    && sanitize_key( (string) $action['model_selection_json']['provider'] ) !== $provider
                )
                {
                    unset( $selection['credential_id'] );
                }
            }
        }

        if ( isset( $runtime['credential_id'] ) && is_scalar( $runtime['credential_id'] ) )
        {
            $credential_id = absint( $runtime['credential_id'] );
            if ( $credential_id > 0 )
            {
                $selection['credential_id'] = $credential_id;
            }
        }

        $resolved_model = $this->resolve_runtime_model_selection( $runtime );
        if ( '' !== $resolved_model )
        {
            $selection['model']             = $resolved_model;
            $selection['selection']         = $this->sanitize_runtime_selection( $runtime );
            $selection['resolution_source'] = 'runtime_settings';
        }

        $backup_model = isset( $runtime['backup'] ) && is_scalar( $runtime['backup'] )
            ? trim( sanitize_text_field( (string) $runtime['backup'] ) )
            : '';
        if ( '' !== $backup_model )
        {
            $selection['backup_model'] = $backup_model;
        }

        $reasoning = $this->sanitize_reasoning_effort( $runtime['reasoning'] ?? null );
        if ( '' !== $reasoning && $this->model_supports_reasoning( (string) ( $selection['model'] ?? '' ), (string) ( $selection['provider'] ?? 'openrouter' ) ) )
        {
            $selection['reasoning'] = $reasoning;
        }
        elseif ( isset( $selection['reasoning'] ) )
        {
            unset( $selection['reasoning'] );
        }

        $tools = $this->sanitize_tool_settings( $runtime['tools'] ?? ( $selection['tools'] ?? null ) );
        if ( [] !== $tools )
        {
            $selection['tools'] = $tools;
        }
        elseif ( isset( $selection['tools'] ) )
        {
            unset( $selection['tools'] );
        }

        return $selection;
    }

    /**
     * @param array<string, mixed> $action
     */
    public function repair_action_model_selection( array $action ): bool | WP_Error
    {
        $action_id = absint( $action['id'] ?? 0 );
        if ( $action_id <= 0 )
        {
            return false;
        }

        $current  = is_array( $action['model_selection_json'] ?? null ) ? $action['model_selection_json'] : [];
        $prepared = $this->prepare_model_selection_for_action( $action );
        if ( $prepared === $current )
        {
            return false;
        }

        $updated = $this->custom_actions->update(
            $action_id,
            [
                'model_selection_json' => $prepared,
            ]
        );

        if ( is_wp_error( $updated ) )
        {
            return $updated;
        }

        return true;
    }

    /**
     * Refresh imported built-in custom-action definitions before execution.
     *
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function prepare_bundled_action_for_execution( array $action ): array
    {
        $action_id   = absint( $action['id'] ?? 0 );
        $definition  = is_array( $action['definition_json'] ?? null ) ? $action['definition_json'] : [];
        $template_code = $this->resolve_bundled_template_code_for_action( $action, $definition );
        if ( '' === $template_code )
        {
            return $action;
        }

        $template = Sentient_Forms_Bundled_Action_Templates::get( $template_code );
        if ( ! is_array( $template ) )
        {
            return $action;
        }

        $prepared = $this->prepare_bundled_action_definition( $action, $definition, $template_code, $template );
        if ( $prepared === $definition )
        {
            return $action;
        }

        $action['definition_json'] = $prepared;

        if ( $action_id <= 0 )
        {
            return $action;
        }

        $updated = $this->custom_actions->update(
            $action_id,
            [
                'definition_json' => $prepared,
            ]
        );

        return is_wp_error( $updated ) ? $action : $updated;
    }

    /**
     * Repair missing bundled defaults on imported local form mappings.
     *
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function prepare_mapping_for_action( array $mapping, array $action ): array
    {
        $mapping_id = absint( $mapping['id'] ?? 0 );
        if ( $mapping_id <= 0 )
        {
            return $mapping;
        }

        $definition    = is_array( $action['definition_json'] ?? null ) ? $action['definition_json'] : [];
        $template_code = $this->resolve_bundled_template_code_for_action( $action, $definition );
        if ( '' === $template_code )
        {
            return $mapping;
        }

        $template = Sentient_Forms_Bundled_Action_Templates::get( $template_code );
        if ( ! is_array( $template ) )
        {
            return $mapping;
        }

        $updates = [];

        $current_effects = is_array( $mapping['effect_mapping_json'] ?? null ) ? $mapping['effect_mapping_json'] : null;
        $default_effects = $this->default_effect_mapping_for_action( $template_code, $template, $action, $definition );
        if ( [] !== $default_effects )
        {
            if ( null === $current_effects || [] === $current_effects )
            {
                $updates['effect_mapping_json'] = $default_effects;
            }
            elseif ( $this->is_imported_bundled_action( $action, $definition ) )
            {
                $merged_effects = array_replace_recursive( $default_effects, $current_effects );
                if ( $merged_effects !== $current_effects )
                {
                    $updates['effect_mapping_json'] = $merged_effects;
                }
            }
        }

        $execution_mode         = sanitize_key( (string) ( $mapping['execution_mode'] ?? '' ) );
        $default_execution_mode = $this->default_execution_mode_for_mapping( $mapping, $template );
        if ( ! in_array( $execution_mode, self::EXECUTION_MODES, true ) )
        {
            $updates['execution_mode'] = $default_execution_mode;
        }

        if ( [] === $updates )
        {
            return $mapping;
        }

        $updated = $this->form_mappings->update( $mapping_id, $updates );
        return is_wp_error( $updated ) ? $mapping : $updated;
    }

    /**
     * @return array{checked: int, repaired: int, errors: array<int, array{action_id: int, code: string, message: string}>}
     */
    public function repair_all_custom_actions(): array
    {
        $summary = [
            'checked'  => 0,
            'repaired' => 0,
            'errors'   => [],
        ];

        foreach ( $this->custom_actions->list_filtered( [ 'status' => 'active' ] ) as $action )
        {
            ++$summary['checked'];
            $prepared_action = $this->prepare_bundled_action_for_execution( $action );
            if ( $prepared_action !== $action )
            {
                ++$summary['repaired'];
                $action = $prepared_action;
            }

            $repaired = $this->repair_action_model_selection( $action );
            if ( is_wp_error( $repaired ) )
            {
                $summary['errors'][] = [
                    'action_id' => absint( $action['id'] ?? 0 ),
                    'code'      => $repaired->get_error_code(),
                    'message'   => $repaired->get_error_message(),
                ];
                continue;
            }

            if ( true === $repaired )
            {
                ++$summary['repaired'];
            }
        }

        return $summary;
    }

    /**
     * @return array{checked: int, repaired: int, errors: array<int, array{mapping_id: int, code: string, message: string}>}
     */
    public function repair_all_bundled_form_mappings(): array
    {
        $summary = [
            'checked'  => 0,
            'repaired' => 0,
            'errors'   => [],
        ];

        foreach ( $this->list_custom_action_mappings() as $mapping )
        {
            ++$summary['checked'];

            $action = $this->custom_actions->get( absint( $mapping['action_id'] ?? 0 ) );
            if ( ! is_array( $action ) )
            {
                continue;
            }

            $prepared = $this->prepare_mapping_for_action( $mapping, $action );
            if ( $prepared !== $mapping )
            {
                ++$summary['repaired'];
            }
        }

        return $summary;
    }

    public function resolve_execution_credential( string $provider, int $credential_id = 0 ): array | WP_Error
    {
        $provider      = sanitize_key( $provider );
        $credential_id = absint( $credential_id );

        if ( $credential_id > 0 )
        {
            $credential = $this->credentials->get( $credential_id );
            if ( null === $credential )
            {
                return new WP_Error(
                    'sentient_forms_provider_credential_not_found',
                    __( 'Provider credential could not be found.', 'sentient-forms' ),
                    [
                        'provider'              => $provider,
                        'requested_credential_id' => $credential_id,
                    ]
                );
            }

            return $this->validate_credential_for_provider( $provider, $credential );
        }

        $credential = $this->find_single_ready_credential_for_provider( $provider );
        if ( is_wp_error( $credential ) )
        {
            return $credential;
        }

        if ( null === $credential )
        {
            return new WP_Error(
                'sentient_forms_provider_credential_not_found',
                __( 'No ready provider credential could be found. Validate a provider key before running local actions.', 'sentient-forms' ),
                [ 'provider' => $provider ]
            );
        }

        return $credential;
    }

    public function find_single_ready_credential_for_provider( string $provider ): array | WP_Error | null
    {
        $provider    = sanitize_key( $provider );
        $credentials = [];

        foreach ( $this->credentials->list( [ 'limit' => 100 ] ) as $credential )
        {
            if ( $provider !== sanitize_key( (string) ( $credential['provider'] ?? '' ) ) )
            {
                continue;
            }

            if ( ! in_array( sanitize_key( (string) ( $credential['status'] ?? '' ) ), self::READY_CREDENTIAL_STATUSES, true ) )
            {
                continue;
            }

            $credential_id = absint( $credential['id'] ?? 0 );
            if ( $credential_id > 0 )
            {
                $credentials[] = $credential;
            }
        }

        if ( 0 === count( $credentials ) )
        {
            return null;
        }

        if ( count( $credentials ) > 1 )
        {
            return new WP_Error(
                'sentient_forms_provider_credential_ambiguous',
                __( 'Multiple ready provider credentials exist. Choose a credential for this local action before execution.', 'sentient-forms' ),
                [ 'provider' => $provider ]
            );
        }

        return $credentials[0];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function resolve_bundled_template_code_for_action( array $action, array $definition ): string
    {
        foreach ( [ 'template_code', 'action_template_code', 'central_action_id' ] as $key )
        {
            if ( isset( $definition[ $key ] ) && is_scalar( $definition[ $key ] ) )
            {
                $template_code = sanitize_key( (string) $definition[ $key ] );
                if ( Sentient_Forms_Bundled_Action_Templates::has( $template_code ) )
                {
                    return $template_code;
                }
            }
        }

        if ( isset( $action['code'] ) && is_scalar( $action['code'] ) )
        {
            return Sentient_Forms_Bundled_Action_Templates::extract_template_code_from_custom_action_code(
                (string) $action['code']
            );
        }

        return '';
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $template
     * @return array<string, mixed>
     */
    private function prepare_bundled_action_definition( array $action, array $definition, string $template_code, array $template ): array
    {
        $prepared                  = $definition;
        $prepared['template_code'] = $template_code;

        if ( ! $this->is_imported_bundled_action( $action, $definition ) )
        {
            return $prepared;
        }

        $template_definition = is_array( $template['definition_json'] ?? null ) ? $template['definition_json'] : [];
        if ( [] !== $template_definition )
        {
            $prepared = array_replace_recursive( $template_definition, $prepared );
        }

        if ( isset( $template['prompt_template'] ) && is_scalar( $template['prompt_template'] ) )
        {
            $template_prompt = trim( (string) $template['prompt_template'] );
            if ( '' !== $template_prompt )
            {
                $prepared['prompt_template'] = $template_prompt;
            }
        }

        $prepared['template_code'] = $template_code;
        return $prepared;
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $action
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function default_effect_mapping_for_action( string $template_code, array $template, array $action, array $definition ): array
    {
        $effects = is_array( $template['effect_mapping_json'] ?? null ) ? $template['effect_mapping_json'] : [];

        if (
            'entry_summary_v1' === $template_code
            && $this->is_imported_bundled_action( $action, $definition )
            && ! isset( $effects['entry_note'] )
        )
        {
            $effects['entry_note'] = [
                'path'   => 'content',
                'prefix' => __( 'Sentient Forms entry summary:', 'sentient-forms' ),
            ];
        }

        return $effects;
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $template
     */
    private function default_execution_mode_for_mapping( array $mapping, array $template ): string
    {
        if ( 'gform_validation' === sanitize_key( (string) ( $mapping['hook'] ?? '' ) ) )
        {
            return 'sync';
        }

        if ( 'real_time' === sanitize_key( (string) ( $mapping['hook'] ?? '' ) ) )
        {
            return 'real_time';
        }

        $default = sanitize_key( (string) ( $template['default_execution_mode'] ?? 'async' ) );
        return in_array( $default, self::EXECUTION_MODES, true ) ? $default : 'async';
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $definition
     */
    private function is_imported_bundled_action( array $action, array $definition ): bool
    {
        if ( 'cps_template_mapping_import' === sanitize_key( (string) ( $definition['source'] ?? '' ) ) )
        {
            return true;
        }

        $code = sanitize_key( (string) ( $action['code'] ?? '' ) );
        return str_starts_with( $code, 'imported_' );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function list_custom_action_mappings(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'sentient_form_mappings';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Repair service scans plugin-owned local-first mappings during upgrade/runtime repair.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE action_kind = %s ORDER BY id ASC',
                $table,
                'custom_action'
            ),
            ARRAY_A
        ) ?: [];

        $mappings = [];
        foreach ( $rows as $row )
        {
            $mapping = $this->form_mappings->get( absint( $row['id'] ?? 0 ) );
            if ( is_array( $mapping ) )
            {
                $mappings[] = $mapping;
            }
        }

        return $mappings;
    }

    private function validate_credential_for_provider( string $provider, array $credential ): array | WP_Error
    {
        if ( $provider !== sanitize_key( (string) ( $credential['provider'] ?? '' ) ) )
        {
            return new WP_Error(
                'sentient_forms_provider_credential_mismatch',
                __( 'Provider credential does not match the action provider.', 'sentient-forms' )
            );
        }

        if ( in_array( sanitize_key( (string) ( $credential['status'] ?? '' ) ), [ 'disabled', 'invalid' ], true ) )
        {
            return new WP_Error(
                'sentient_forms_provider_credential_unavailable',
                __( 'Provider credential is not available for execution.', 'sentient-forms' )
            );
        }

        return $credential;
    }

    /**
     * @param array<string, mixed> $selection
     */
    private function resolve_runtime_model_selection( array $selection ): string
    {
        $primary = isset( $selection['primary'] ) && is_scalar( $selection['primary'] )
            ? trim( sanitize_text_field( (string) $selection['primary'] ) )
            : '';
        if ( '' === $primary )
        {
            return '';
        }

        $is_preset = ! empty( $selection['is_preset'] ) || str_starts_with( $primary, 'sf_' );
        if ( ! $is_preset )
        {
            return $primary;
        }

        return $this->resolve_local_preset_model_id( sanitize_key( $primary ) );
    }

    private function resolve_local_preset_model_id( string $preset_code ): string
    {
        $models      = $this->list_local_openrouter_models();
        $recommended = $this->pick_default_model_id( $models );

        $evidence_model = $this->pick_evidence_model_id( $models, $preset_code );
        if ( null !== $evidence_model )
        {
            return $evidence_model;
        }

        return match ( $preset_code ) {
            'sf_default',
            'sf_general'    => $recommended,
            'sf_quality'    => $this->pick_preferred_model_id( $models, [ 'openai/gpt-5.5-pro', 'anthropic/claude-opus-4.7', 'anthropic/claude-sonnet-4.6', 'openai/gpt-5.5' ] ) ?: $recommended,
            'sf_free'       => isset( $models['openrouter/free'] )
                ? 'openrouter/free'
                : ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => 'free' === (string) ( $model['cost_tier'] ?? '' ) ) ?: $recommended ),
            'sf_structured' => $this->pick_preferred_model_id( $models, [ 'openai/gpt-5.5', 'google/gemini-3-flash-preview', 'nvidia/nemotron-3-super-120b-a12b:free', 'openrouter/free' ] )
                ?: ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => in_array( 'structured-output', $model['tags'] ?? [], true ) ) ?: $recommended ),
            'sf_fast'       => $this->pick_preferred_model_id( $models, [ 'google/gemini-3-flash-preview', 'google/gemini-3.1-flash-lite-preview', 'openai/gpt-5.4', 'openai/gpt-5.4-mini' ] )
                ?: ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => in_array( (string) ( $model['speed_tier'] ?? '' ), [ 'fastest', 'fast' ], true ) ) ?: $recommended ),
            'sf_low_cost'   => $this->pick_preferred_model_id( $models, [ 'deepseek/deepseek-v4-flash', 'google/gemini-3.1-flash-lite-preview', 'deepseek/deepseek-v4-pro', 'openai/gpt-5.4-mini' ] )
                ?: ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => 'free' !== (string) ( $model['cost_tier'] ?? '' ) && in_array( (string) ( $model['cost_tier'] ?? '' ), [ 'low', 'medium' ], true ) )
                ?: ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => 'free' === (string) ( $model['cost_tier'] ?? '' ) ) ?: $recommended ) ),
            'sf_long_context' => $this->pick_preferred_model_id( $models, [ 'openai/gpt-5.5', 'google/gemini-3.1-pro-preview', 'anthropic/claude-opus-4.7', 'moonshotai/kimi-k2.6' ] )
                ?: ( $this->pick_long_context_model_id( $models ) ?: $recommended ),
            'sf_reasoning'  => $this->pick_preferred_model_id( $models, [ 'openai/gpt-5.5-pro', 'openai/gpt-5.5', 'anthropic/claude-opus-4.7', 'z-ai/glm-5.1', 'google/gemini-3.1-pro-preview' ] )
                ?: ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => ! empty( $model['capabilities']['reasoning'] ) ) ?: $recommended ),
            'sf_code'       => $this->pick_preferred_model_id( $models, [ 'moonshotai/kimi-k2.6', 'anthropic/claude-opus-4.7', 'anthropic/claude-sonnet-4.6', 'qwen/qwen3.6-max-preview', 'openai/gpt-5.5' ] )
                ?: ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => ! empty( $model['capabilities']['code'] ) ) ?: $recommended ),
            'sf_legal'      => $this->pick_preferred_model_id( $models, [ 'google/gemini-3.1-pro-preview', 'anthropic/claude-sonnet-4.6', 'openai/gpt-5.5', 'anthropic/claude-opus-4.7' ] ) ?: $recommended,
            'sf_financial'  => $this->pick_preferred_model_id( $models, [ 'anthropic/claude-sonnet-4.6', 'anthropic/claude-opus-4.7', 'openai/gpt-5.5' ] ) ?: $recommended,
            'sf_privacy'    => $this->pick_preferred_model_id( $models, [ 'openai/gpt-5.5', 'anthropic/claude-sonnet-4.6', 'google/gemini-3.1-pro-preview' ] ) ?: $recommended,
            'sf_realtime'   => $this->pick_preferred_model_id( $models, [ 'google/gemini-3-flash-preview', 'google/gemini-3.1-flash-lite-preview', 'deepseek/deepseek-v4-flash' ] ) ?: $recommended,
            'sf_multimodal' => $this->pick_preferred_model_id( $models, [ 'google/gemini-3.1-pro-preview', 'google/gemini-3-flash-preview', 'openai/gpt-5.5' ] ) ?: $recommended,
            'sf_research'   => $this->pick_preferred_model_id( $models, [ 'openai/gpt-5.5', 'anthropic/claude-opus-4.7', 'google/gemini-3.1-pro-preview' ] ) ?: $recommended,
            'sf_agentic'    => $this->pick_preferred_model_id( $models, [ 'google/gemini-3.1-pro-preview', 'openai/gpt-5.5', 'anthropic/claude-opus-4.7' ] ) ?: $recommended,
            default         => '',
        };
    }

    private function pick_evidence_model_id( array $models, string $preset_code ): ?string
    {
        $evidence = $this->model_preset_evidence();
        if ( ! isset( $evidence[ $preset_code ]['preferred_model_ids'] ) || ! is_array( $evidence[ $preset_code ]['preferred_model_ids'] ) )
        {
            return null;
        }

        return $this->pick_preferred_model_id( $models, $this->sanitize_model_id_list( $evidence[ $preset_code ]['preferred_model_ids'] ) );
    }

    private function model_preset_evidence(): array
    {
        static $preset_evidence = null;

        if ( null !== $preset_evidence )
        {
            return $preset_evidence;
        }

        $evidence_file = __DIR__ . '/../data/model-selector-preset-evidence.php';
        if ( ! file_exists( $evidence_file ) )
        {
            $preset_evidence = [];
            return [];
        }

        $evidence        = require $evidence_file;
        $preset_evidence = is_array( $evidence ) ? $evidence : [];

        return $preset_evidence;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function list_local_openrouter_models(): array
    {
        $rows   = $this->model_cache->list( 'openrouter', true, 1000 );
        $models = [];

        foreach ( $rows as $row )
        {
            $model = $this->format_openrouter_model_info( $row );
            if ( '' !== $model['id'] )
            {
                $models[ $model['id'] ] = $model;
            }
        }

        foreach ( Sentient_Forms_OpenRouter_Model_Recommendations::all() as $model_id => $metadata )
        {
            if ( isset( $models[ $model_id ] ) )
            {
                continue;
            }

            $model = $this->format_openrouter_model_info(
                [
                    'model_id'      => $model_id,
                    'metadata_json' => $metadata,
                ]
            );
            if ( '' !== $model['id'] )
            {
                $model['tags'][] = 'bundled-recommendation';
                $models[ $model['id'] ] = $model;
            }
        }

        uasort(
            $models,
            static function ( array $a, array $b ): int {
                $cost_order = [ 'free' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'premium' => 4, 'unknown' => 5 ];
                $a_cost     = $cost_order[ $a['cost_tier'] ?? 'unknown' ] ?? 5;
                $b_cost     = $cost_order[ $b['cost_tier'] ?? 'unknown' ] ?? 5;

                if ( $a_cost !== $b_cost )
                {
                    return $a_cost <=> $b_cost;
                }

                return strcasecmp( (string) $a['display_name'], (string) $b['display_name'] );
            }
        );

        return $models;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format_openrouter_model_info( array $row ): array
    {
        $metadata             = is_array( $row['metadata_json'] ?? null ) ? $row['metadata_json'] : [];
        $model_id             = sanitize_text_field( (string) ( $row['model_id'] ?? $metadata['id'] ?? '' ) );
        $name                 = isset( $metadata['name'] ) ? sanitize_text_field( (string) $metadata['name'] ) : $model_id;
        $pricing              = is_array( $metadata['pricing'] ?? null ) ? $metadata['pricing'] : [];
        $architecture         = is_array( $metadata['architecture'] ?? null ) ? $metadata['architecture'] : [];
        $supported_parameters = $this->sanitize_string_list( $metadata['supported_parameters'] ?? [] );
        $input_modalities     = $this->sanitize_string_list( $metadata['input_modalities'] ?? $architecture['input_modalities'] ?? [] );
        $output_modalities    = $this->sanitize_string_list( $metadata['output_modalities'] ?? $architecture['output_modalities'] ?? [] );
        $context_window       = isset( $metadata['context_length'] ) ? absint( $metadata['context_length'] ) : 0;
        $is_free              = ! empty( $metadata['free'] );

        $capabilities = [
            'reasoning'    => $this->model_has_reasoning( $model_id, $name, $supported_parameters ),
            'code'         => (bool) preg_match( '/code|coder|coding/i', $model_id . ' ' . $name ),
            'vision'       => in_array( 'image', $input_modalities, true ),
            'files'        => in_array( 'file', $input_modalities, true ),
            'audio'        => in_array( 'audio', $input_modalities, true ),
            'video'        => in_array( 'video', $input_modalities, true ),
            'tools'        => (bool) array_intersect( $supported_parameters, [ 'tools', 'tool_choice', 'function_call' ] ),
            'structured'   => (bool) array_intersect( $supported_parameters, [ 'response_format', 'structured_outputs' ] ),
            'web_search'   => array_key_exists( 'web_search', $pricing ) || in_array( 'web_search_options', $supported_parameters, true ),
            'long_context' => $context_window >= 128000,
        ];

        $tags = array_values(
            array_filter(
                [
                    $is_free ? 'free' : null,
                    $capabilities['structured'] ? 'structured-output' : null,
                    $capabilities['tools'] ? 'tools' : null,
                    $capabilities['reasoning'] ? 'reasoning' : null,
                    $capabilities['code'] ? 'code' : null,
                    $capabilities['web_search'] ? 'web-search' : null,
                    $capabilities['vision'] ? 'vision' : null,
                    $capabilities['long_context'] ? 'long-context' : null,
                ]
            )
        );

        return [
            'id'             => $model_id,
            'display_name'   => '' !== $name ? $name : $model_id,
            'speed_tier'     => $this->infer_speed_tier( $model_id, $name ),
            'cost_tier'      => $is_free ? 'free' : $this->infer_cost_tier( $pricing ),
            'cost_symbol'    => $is_free ? 'Free' : $this->cost_symbol_for_pricing( $pricing ),
            'capabilities'   => $capabilities,
            'context_window' => $context_window,
            'tags'           => $tags,
            'supported_parameters' => $supported_parameters,
            'input_modalities' => $input_modalities,
            'output_modalities' => $output_modalities,
            'recommended_for' => $this->sanitize_string_label_list( $metadata['recommended_for'] ?? [] ),
            'category_rankings' => $this->sanitize_category_rankings( $metadata['category_rankings'] ?? [] ),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $models
     */
    private function pick_default_model_id( array $models ): string
    {
        $preferred_default = $this->pick_preferred_model_id( $models, [ 'openai/gpt-5.5', 'anthropic/claude-sonnet-4.6', 'google/gemini-3-flash-preview', 'openai/gpt-5.4' ] );
        if ( $preferred_default )
        {
            return $preferred_default;
        }

        $paid_general = $this->pick_first_model_id(
            $models,
            static fn ( array $model ): bool => 'free' !== (string) ( $model['cost_tier'] ?? '' )
                && in_array( 'General purpose', $model['recommended_for'] ?? [], true )
        );
        if ( $paid_general )
        {
            return $paid_general;
        }

        $structured_free = $this->pick_first_model_id(
            $models,
            static fn ( array $model ): bool => 'free' === (string) ( $model['cost_tier'] ?? '' )
                && in_array( 'structured-output', $model['tags'] ?? [], true )
        );

        return $structured_free ?: ( $this->pick_first_model_id( $models, static fn (): bool => true ) ?: 'openrouter/auto' );
    }

    /**
     * @param array<string, array<string, mixed>> $models
     */
    private function pick_first_model_id( array $models, callable $matches ): ?string
    {
        foreach ( $models as $model )
        {
            if ( $matches( $model ) )
            {
                return (string) $model['id'];
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $models
     * @param array<int, string>                  $preferred_model_ids
     */
    private function pick_preferred_model_id( array $models, array $preferred_model_ids ): ?string
    {
        foreach ( $preferred_model_ids as $model_id )
        {
            if ( isset( $models[ $model_id ] ) )
            {
                return $model_id;
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $models
     */
    private function pick_long_context_model_id( array $models ): ?string
    {
        $winner = null;
        foreach ( $models as $model )
        {
            if ( null === $winner || (int) $model['context_window'] > (int) $winner['context_window'] )
            {
                $winner = $model;
            }
        }

        return is_array( $winner ) ? (string) $winner['id'] : null;
    }

    private function sanitize_reasoning_effort( mixed $value ): string
    {
        $value = sanitize_key( (string) $value );
        return in_array( $value, [ 'none', 'minimal', 'low', 'medium', 'high', 'xhigh' ], true ) ? $value : '';
    }

    /**
     * @param array<string, mixed> $selection
     * @return array<string, mixed>
     */
    private function sanitize_runtime_selection( array $selection ): array
    {
        $sanitized = [];
        foreach ( [ 'primary', 'backup', 'reasoning', 'provider' ] as $key )
        {
            if ( isset( $selection[ $key ] ) && is_scalar( $selection[ $key ] ) )
            {
                $sanitized[ $key ] = 'provider' === $key
                    ? sanitize_key( (string) $selection[ $key ] )
                    : sanitize_text_field( (string) $selection[ $key ] );
            }
        }
        if ( isset( $selection['credential_id'] ) && is_scalar( $selection['credential_id'] ) )
        {
            $credential_id = absint( $selection['credential_id'] );
            if ( $credential_id > 0 )
            {
                $sanitized['credential_id'] = $credential_id;
            }
        }
        $tools = $this->sanitize_tool_settings( $selection['tools'] ?? null );
        if ( [] !== $tools )
        {
            $sanitized['tools'] = $tools;
        }
        $sanitized['is_preset'] = ! empty( $selection['is_preset'] );

        return $sanitized;
    }

    private function model_has_reasoning( string $model_id, string $name, array $supported_parameters ): bool
    {
        return (bool) array_intersect( $supported_parameters, [ 'reasoning', 'reasoning_effort' ] );
    }

    private function model_supports_reasoning( string $model_id, string $provider ): bool
    {
        if ( 'sentient_managed' === sanitize_key( $provider ) )
        {
            return true;
        }

        $model = $this->list_local_openrouter_models()[ $model_id ] ?? null;
        if ( ! is_array( $model ) )
        {
            return false;
        }

        return ! empty( $model['capabilities']['reasoning'] );
    }

    private function infer_speed_tier( string $model_id, string $name ): string
    {
        return preg_match( '/flash|mini|lite|fast|turbo|gpt-oss/i', $model_id . ' ' . $name )
            ? 'fast'
            : 'balanced';
    }

    private function cost_symbol_for_pricing( array $pricing ): string
    {
        if ( [] === $pricing )
        {
            return 'N/A';
        }

        $prompt     = isset( $pricing['prompt'] ) ? (float) $pricing['prompt'] : 0.0;
        $completion = isset( $pricing['completion'] ) ? (float) $pricing['completion'] : 0.0;
        $max        = max( $prompt, $completion );

        if ( $prompt < 0 || $completion < 0 )
        {
            return 'Varies';
        }

        if ( 0.0 === $max )
        {
            return 'Free';
        }

        if ( $max <= 0.000001 )
        {
            return '$';
        }

        if ( $max <= 0.00001 )
        {
            return '$$';
        }

        if ( $max <= 0.00005 )
        {
            return '$$$';
        }

        return '$$$$';
    }

    /**
     * @param array<string, mixed> $pricing
     */
    private function infer_cost_tier( array $pricing ): string
    {
        if ( [] === $pricing )
        {
            return 'unknown';
        }

        $prompt     = isset( $pricing['prompt'] ) ? (float) $pricing['prompt'] : 0.0;
        $completion = isset( $pricing['completion'] ) ? (float) $pricing['completion'] : 0.0;
        $max        = max( $prompt, $completion );

        if ( $prompt < 0 || $completion < 0 )
        {
            return 'unknown';
        }

        if ( 0.0 === $max )
        {
            return 'free';
        }

        if ( $max <= 0.000001 )
        {
            return 'low';
        }

        if ( $max <= 0.00001 )
        {
            return 'medium';
        }

        if ( $max <= 0.00005 )
        {
            return 'high';
        }

        return 'premium';
    }

    private function sanitize_string_label_list( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $labels = [];
        foreach ( $value as $item )
        {
            if ( is_scalar( $item ) )
            {
                $label = sanitize_text_field( (string) $item );
                if ( '' !== $label )
                {
                    $labels[] = $label;
                }
            }
        }

        return array_values( array_unique( $labels ) );
    }

    private function sanitize_category_rankings( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $rankings = [];
        foreach ( $value as $category => $rank )
        {
            $category_key = sanitize_key( (string) $category );
            $rank_value   = absint( $rank );
            if ( '' !== $category_key && $rank_value > 0 )
            {
                $rankings[ $category_key ] = $rank_value;
            }
        }

        return $rankings;
    }

    private function sanitize_tool_settings( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $settings = [];
        foreach ( [ 'web_search', 'web_fetch', 'datetime' ] as $tool_key )
        {
            if ( ! is_array( $value[ $tool_key ] ?? null ) )
            {
                continue;
            }

            $mode = sanitize_key( (string) ( $value[ $tool_key ]['mode'] ?? 'inherit' ) );
            if ( ! in_array( $mode, [ 'inherit', 'off', 'auto', 'required' ], true ) )
            {
                $mode = 'inherit';
            }

            if ( 'inherit' === $mode )
            {
                continue;
            }

            $settings[ $tool_key ] = [ 'mode' => $mode ];

            if ( 'web_search' === $tool_key )
            {
                $max_results = absint( $value[ $tool_key ]['max_results'] ?? 0 );
                if ( $max_results > 0 )
                {
                    $settings[ $tool_key ]['max_results'] = min( 10, $max_results );
                }
            }
        }

        $tool_choice = sanitize_key( (string) ( $value['tool_choice'] ?? 'inherit' ) );
        if ( in_array( $tool_choice, [ 'off', 'auto', 'required' ], true ) )
        {
            $settings['tool_choice'] = $tool_choice;
        }

        return $settings;
    }

    private function sanitize_string_list( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        return array_values( array_filter( array_map( 'sanitize_key', $value ) ) );
    }

    private function sanitize_model_id_list( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $model_ids = [];
        foreach ( $value as $item )
        {
            if ( ! is_scalar( $item ) )
            {
                continue;
            }

            $model_id = sanitize_text_field( (string) $item );
            if ( '' !== $model_id )
            {
                $model_ids[] = $model_id;
            }
        }

        return array_values( array_unique( $model_ids ) );
    }
}
