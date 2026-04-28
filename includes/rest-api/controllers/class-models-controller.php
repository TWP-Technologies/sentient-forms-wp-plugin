<?php
/**
 * REST API Models Controller class for the Sentient Forms plugin.
 * Handles routes related to model catalog and selection.
 *
 * @package    SentientForms
 * @subpackage REST_API\Controllers
 * @since      0.1.0
 */

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Models_Controller
 * Manages REST API endpoints for model catalog and selection (CB-MODEL-*).
 */
class Sentient_Forms_Models_Controller extends Abstract_Sentient_Forms_Base_Controller
{
    use Trait_Sentient_Forms_Permission_Utils;

    /**
     * The base of this controller's routes.
     *
     * @var string
     * @since 0.1.0
     */
    protected string $rest_base = 'models';

    private const MANAGED_PROVIDER      = 'sentient_managed';
    private const MANAGED_DEFAULT_MODEL = 'gemini-3-flash-preview';

    /**
     * Local OpenRouter model metadata cache.
     *
     * @var Sentient_Forms_Model_Cache_Repository
     */
    private Sentient_Forms_Model_Cache_Repository $model_cache;

    /**
     * Constructor.
     *
     * @param Sentient_Forms_Model_Cache_Repository|null $model_cache Optional repository for tests.
     */
    public function __construct( ?Sentient_Forms_Model_Cache_Repository $model_cache = null )
    {
        parent::__construct();

        global $wpdb;
        $this->model_cache = $model_cache ?? new Sentient_Forms_Model_Cache_Repository( $wpdb );
    }

    /**
     * Registers the routes for the models controller.
     *
     * @since 0.1.0
     */
    public function register_routes(): void
    {
        // GET /models - List available models and presets
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_models' ],
                'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
            ],
        );

        // POST /models/resolve - Resolve model based on override chain
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/resolve',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'resolve_model' ],
                'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                'args'                => [
                    'global_selection' => [
                        'description'       => __( 'Global model selection.', 'sentient-forms' ),
                        'type'              => 'object',
                        'sanitize_callback' => 'rest_sanitize_request_arg',
                    ],
                    'action_selection' => [
                        'description'       => __( 'Action-level model selection.', 'sentient-forms' ),
                        'type'              => 'object',
                        'sanitize_callback' => 'rest_sanitize_request_arg',
                    ],
                    'form_selection' => [
                        'description'       => __( 'Form-level model selection.', 'sentient-forms' ),
                        'type'              => 'object',
                        'sanitize_callback' => 'rest_sanitize_request_arg',
                    ],
                    'mapping_selection' => [
                        'description'       => __( 'Mapping-level model selection.', 'sentient-forms' ),
                        'type'              => 'object',
                        'sanitize_callback' => 'rest_sanitize_request_arg',
                    ],
                    'template_model_hint' => [
                        'description'       => __( 'Template-level model hint fallback.', 'sentient-forms' ),
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        );

        // POST /models/estimate - Resolve model and return a pricing estimate
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/estimate',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'estimate_model' ],
                'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                'args'                => [
                    'action_id' => [
                        'description'       => __( 'Action identifier used for pricing lookup.', 'sentient-forms' ),
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'template_model_hint' => [
                        'description'       => __( 'Template-level model hint fallback.', 'sentient-forms' ),
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'base_credit_cost' => [
                        'description'       => __( 'Fallback base credit cost for the action pricing estimate.', 'sentient-forms' ),
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'global_selection' => [
                        'description'       => __( 'Global model selection.', 'sentient-forms' ),
                        'type'              => 'object',
                        'sanitize_callback' => 'rest_sanitize_request_arg',
                    ],
                    'action_selection' => [
                        'description'       => __( 'Action-level model selection.', 'sentient-forms' ),
                        'type'              => 'object',
                        'sanitize_callback' => 'rest_sanitize_request_arg',
                    ],
                    'form_selection' => [
                        'description'       => __( 'Form-level model selection.', 'sentient-forms' ),
                        'type'              => 'object',
                        'sanitize_callback' => 'rest_sanitize_request_arg',
                    ],
                    'mapping_selection' => [
                        'description'       => __( 'Mapping-level model selection.', 'sentient-forms' ),
                        'type'              => 'object',
                        'sanitize_callback' => 'rest_sanitize_request_arg',
                    ],
                ],
            ],
        );
    }

    /**
     * Lists available models and presets from the local provider cache.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function list_models( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $models = $this->list_local_openrouter_models();

        return $this->prepare_item_for_response(
            [
                'models'                 => array_values( $models ),
                'presets'                => $this->build_presets( $models ),
                'pricing_policy_version' => 'local-openrouter-v1',
            ]
        );
    }

    /**
     * Resolves model based on override chain.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function resolve_model( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $resolution = $this->resolve_local_model(
            [
                'global_selection'    => $request->get_param( 'global_selection' ),
                'action_selection'    => $request->get_param( 'action_selection' ),
                'form_selection'      => $request->get_param( 'form_selection' ),
                'mapping_selection'   => $request->get_param( 'mapping_selection' ),
                'template_model_hint' => $request->get_param( 'template_model_hint' ),
            ]
        );

        return $this->prepare_item_for_response( $resolution );
    }

    /**
     * Resolve pricing estimate based on the override chain.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function estimate_model( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $resolution = $this->resolve_local_model(
            [
                'global_selection'    => $request->get_param( 'global_selection' ),
                'action_selection'    => $request->get_param( 'action_selection' ),
                'form_selection'      => $request->get_param( 'form_selection' ),
                'mapping_selection'   => $request->get_param( 'mapping_selection' ),
                'template_model_hint' => $request->get_param( 'template_model_hint' ),
            ]
        );

        $model    = $this->get_model_by_id( (string) $resolution['model_id'] );
        $is_free  = is_array( $model ) && 'free' === (string) ( $model['cost_tier'] ?? '' );
        $base     = absint( $request->get_param( 'base_credit_cost' ) );
        $estimate = $is_free ? 0 : $base;

        return $this->prepare_item_for_response(
            [
                'resolved_model'   => $resolution,
                'pricing_estimate' => [
                    'action_id'                  => sanitize_key( (string) $request->get_param( 'action_id' ) ),
                    'resolved_model_id'          => (string) $resolution['model_id'],
                    'base_floor_credits'         => $base,
                    'normalized_actual_credits'  => $estimate,
                    'estimated_debit_credits'    => 0,
                    'pricing_policy_version'     => 'local-openrouter-v1',
                    'estimate_source'            => 'local_cache_no_sentient_debit',
                ],
            ]
        );
    }

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

        if ( [] === $models )
        {
            foreach ( Sentient_Forms_OpenRouter_Model_Recommendations::all() as $model_id => $metadata )
            {
                $model = $this->format_openrouter_model_info(
                    [
                        'model_id'      => $model_id,
                        'metadata_json' => $metadata,
                        'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + MONTH_IN_SECONDS ),
                    ]
                );

                if ( '' !== $model['id'] )
                {
                    $model['tags'][] = 'bundled-recommendation';
                    $models[ $model['id'] ] = $model;
                }
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

    private function format_openrouter_model_info( array $row ): array
    {
        $metadata             = is_array( $row['metadata_json'] ?? null ) ? $row['metadata_json'] : [];
        $model_id             = sanitize_text_field( (string) ( $row['model_id'] ?? $metadata['id'] ?? '' ) );
        $name                 = isset( $metadata['name'] ) ? sanitize_text_field( (string) $metadata['name'] ) : $model_id;
        $pricing              = isset( $metadata['pricing'] ) && is_array( $metadata['pricing'] ) ? $metadata['pricing'] : [];
        $input_modalities     = $this->sanitize_string_list( $metadata['input_modalities'] ?? [] );
        $output_modalities    = $this->sanitize_string_list( $metadata['output_modalities'] ?? [] );
        $supported_parameters = $this->sanitize_string_list( $metadata['supported_parameters'] ?? [] );
        $context_window       = isset( $metadata['context_length'] ) ? absint( $metadata['context_length'] ) : 0;
        $is_free              = ! empty( $metadata['free'] );
        $is_preview           = str_contains( strtolower( $model_id . ' ' . $name ), 'preview' );
        $is_stale             = isset( $row['expires_at'] ) && (string) $row['expires_at'] < current_time( 'mysql', true );

        $capabilities = [
            'reasoning'    => $this->model_has_reasoning( $model_id, $name, $supported_parameters ),
            'code'         => (bool) preg_match( '/code|coder|coding/i', $model_id . ' ' . $name ),
            'vision'       => in_array( 'image', $input_modalities, true ),
            'tools'        => (bool) array_intersect( $supported_parameters, [ 'tools', 'tool_choice', 'function_call' ] ),
            'structured'   => (bool) array_intersect( $supported_parameters, [ 'response_format', 'structured_outputs' ] ),
            'web_search'   => array_key_exists( 'web_search', $pricing ),
            'long_context' => $context_window >= 128000,
        ];

        $tags = array_values(
            array_filter(
                [
                    $is_free ? 'free' : null,
                    $is_preview ? 'preview' : null,
                    $is_stale ? 'stale-cache' : null,
                    $capabilities['structured'] ? 'structured-output' : null,
                    $capabilities['tools'] ? 'tools' : null,
                    $capabilities['reasoning'] ? 'reasoning' : null,
                    $capabilities['code'] ? 'code' : null,
                    $capabilities['web_search'] ? 'web-search' : null,
                    in_array( 'text', $output_modalities, true ) ? 'text-output' : null,
                    $capabilities['vision'] ? 'vision' : null,
                    $capabilities['long_context'] ? 'long-context' : null,
                ]
            )
        );

        return [
            'id'              => $model_id,
            'display_name'    => '' !== $name ? $name : $model_id,
            'provider'        => 'openrouter',
            'speed_tier'      => $this->infer_speed_tier( $model_id, $name ),
            'cost_tier'       => $is_free ? 'free' : $this->infer_cost_tier( $pricing ),
            'capabilities'    => $capabilities,
            'context_window'  => $context_window,
            'is_preview'      => $is_preview,
            'tags'            => $tags,
            'supported_parameters' => $supported_parameters,
            'pricing'         => $this->sanitize_pricing_map( $pricing ),
            'recommended_for' => $this->recommended_for( $is_free, $capabilities, $supported_parameters, $metadata ),
        ];
    }

    private function build_presets( array $models ): array
    {
        $recommended_model = $this->pick_default_model_id( $models );
        $quality_model     = $this->pick_first_model_id( $models, static fn ( array $model ): bool => in_array( 'High-quality analysis', $model['recommended_for'] ?? [], true ) ) ?: $recommended_model;
        $free_model        = isset( $models['openrouter/free'] )
            ? 'openrouter/free'
            : ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => 'free' === $model['cost_tier'] ) ?: $recommended_model );
        $structured_model  = $this->pick_first_model_id( $models, static fn ( array $model ): bool => in_array( 'structured-output', $model['tags'], true ) ) ?: $recommended_model;
        $fast_model        = $this->pick_first_model_id( $models, static fn ( array $model ): bool => in_array( (string) ( $model['speed_tier'] ?? '' ), [ 'fastest', 'fast' ], true ) ) ?: $recommended_model;
        $low_cost_model    = $this->pick_first_model_id( $models, static fn ( array $model ): bool => 'free' !== (string) ( $model['cost_tier'] ?? '' ) && in_array( (string) ( $model['cost_tier'] ?? '' ), [ 'low', 'medium' ], true ) ) ?: $free_model;
        $long_model        = $this->pick_long_context_model_id( $models ) ?: $recommended_model;
        $reasoning_model   = $this->pick_first_model_id( $models, static fn ( array $model ): bool => ! empty( $model['capabilities']['reasoning'] ) ) ?: $recommended_model;
        $code_model        = $this->pick_first_model_id( $models, static fn ( array $model ): bool => ! empty( $model['capabilities']['code'] ) ) ?: $recommended_model;

        return [
            [
                'code'              => 'sf_default',
                'display_name'      => __( 'Recommended', 'sentient-forms' ),
                'description'       => __( 'Uses the best cached OpenRouter model for general Sentient Forms actions, preferring free structured-output models when present.', 'sentient-forms' ),
                'category'          => 'local',
                'resolved_model_id' => $recommended_model,
                'auto_upgrade'      => true,
            ],
            [
                'code'              => 'sf_general',
                'display_name'      => __( 'General purpose', 'sentient-forms' ),
                'description'       => __( 'Balanced fallback for summaries, classification, and ordinary form automation.', 'sentient-forms' ),
                'category'          => 'local',
                'resolved_model_id' => $recommended_model,
                'auto_upgrade'      => true,
            ],
            [
                'code'              => 'sf_quality',
                'display_name'      => __( 'Higher quality', 'sentient-forms' ),
                'description'       => __( 'Prefers a stronger paid model for important production workflows where output quality matters more than raw cost.', 'sentient-forms' ),
                'category'          => 'local',
                'resolved_model_id' => $quality_model,
                'auto_upgrade'      => true,
            ],
            [
                'code'              => 'sf_free',
                'display_name'      => __( 'Free model', 'sentient-forms' ),
                'description'       => __( 'Uses a cached free OpenRouter model when one is available.', 'sentient-forms' ),
                'category'          => 'local',
                'resolved_model_id' => $free_model,
                'auto_upgrade'      => true,
            ],
            [
                'code'              => 'sf_structured',
                'display_name'      => __( 'Structured output', 'sentient-forms' ),
                'description'       => __( 'Prefers a model that advertises response_format support for JSON-style result validation.', 'sentient-forms' ),
                'category'          => 'local',
                'resolved_model_id' => $structured_model,
                'auto_upgrade'      => true,
            ],
            [
                'code'              => 'sf_fast',
                'display_name'      => __( 'Speed', 'sentient-forms' ),
                'description'       => __( 'Prefers cached models identified as fast or lightweight for low-latency form handling.', 'sentient-forms' ),
                'category'          => 'local',
                'resolved_model_id' => $fast_model,
                'auto_upgrade'      => true,
            ],
            [
                'code'              => 'sf_low_cost',
                'display_name'      => __( 'Low cost', 'sentient-forms' ),
                'description'       => __( 'Prefers free or low-cost cached models before paid premium models.', 'sentient-forms' ),
                'category'          => 'local',
                'resolved_model_id' => $low_cost_model,
                'auto_upgrade'      => true,
            ],
            [
                'code'              => 'sf_long_context',
                'display_name'      => __( 'Long context', 'sentient-forms' ),
                'description'       => __( 'Uses the cached model with the largest context window.', 'sentient-forms' ),
                'category'          => 'local',
                'resolved_model_id' => $long_model,
                'auto_upgrade'      => true,
            ],
            [
                'code'              => 'sf_reasoning',
                'display_name'      => __( 'Reasoning', 'sentient-forms' ),
                'description'       => __( 'Prefers a cached model that advertises reasoning or thinking controls.', 'sentient-forms' ),
                'category'          => 'local',
                'resolved_model_id' => $reasoning_model,
                'auto_upgrade'      => true,
            ],
            [
                'code'              => 'sf_code',
                'display_name'      => __( 'Code generation', 'sentient-forms' ),
                'description'       => __( 'Prefers cached models whose metadata indicates coding strength.', 'sentient-forms' ),
                'category'          => 'local',
                'resolved_model_id' => $code_model,
                'auto_upgrade'      => true,
            ],
        ];
    }

    private function resolve_local_model( array $payload ): array
    {
        $models  = $this->list_local_openrouter_models();
        $presets = $this->build_presets( $models );
        $chain   = [];

        $template_hint = isset( $payload['template_model_hint'] ) ? sanitize_text_field( (string) $payload['template_model_hint'] ) : '';
        $chain[] = $this->build_resolution_step( 'template', $template_hint, false, $presets );

        foreach ( [ 'global', 'action', 'form', 'mapping' ] as $level )
        {
            $selection = $payload[ $level . '_selection' ] ?? null;
            $chain[]   = $this->build_resolution_step( $level, $selection, true, $presets );
        }

        $applied_index = null;
        for ( $index = count( $chain ) - 1; $index >= 0; $index-- )
        {
            if ( '' !== ( $chain[ $index ]['model_id'] ?? '' ) )
            {
                $applied_index = $index;
                break;
            }
        }

        if ( null === $applied_index )
        {
            $fallback_model_id = $this->pick_default_model_id( $models );
            $chain[] = [
                'level'           => 'fallback',
                'selection'       => 'sf_default',
                'model_id'        => $fallback_model_id,
                'backup_model_id' => null,
                'applied'         => true,
                'reason'          => __( 'No override was configured, so the local default preset was used.', 'sentient-forms' ),
            ];
            $applied_index = count( $chain ) - 1;
        }

        foreach ( $chain as $index => $step )
        {
            $chain[ $index ]['applied'] = $index === $applied_index;
        }

        $applied = $chain[ $applied_index ];
        $model   = $this->get_model_by_id( (string) $applied['model_id'], $models );

        return [
            'model_id'          => (string) $applied['model_id'],
            'display_name'      => $model['display_name']
                ?? ( self::MANAGED_DEFAULT_MODEL === (string) $applied['model_id']
                    ? __( 'Sentient Forms managed default', 'sentient-forms' )
                    : (string) $applied['model_id'] ),
            'resolution_source' => (string) $applied['level'],
            'override_chain'    => array_map(
                static function ( array $step ): array {
                    return [
                        'level'     => (string) $step['level'],
                        'selection' => '' !== (string) ( $step['selection'] ?? '' ) ? (string) $step['selection'] : null,
                        'applied'   => ! empty( $step['applied'] ),
                        'reason'    => (string) $step['reason'],
                    ];
                },
                $chain
            ),
            'backup_model_id'   => $applied['backup_model_id'] ?: null,
        ];
    }

    private function build_resolution_step( string $level, mixed $selection, bool $allow_presets, array $presets ): array
    {
        $primary       = '';
        $backup        = null;
        $is_preset     = false;
        $selection_key = null;

        if ( is_array( $selection ) )
        {
            $primary       = isset( $selection['primary'] ) ? sanitize_text_field( (string) $selection['primary'] ) : '';
            $backup        = isset( $selection['backup'] ) && '' !== (string) $selection['backup'] ? sanitize_text_field( (string) $selection['backup'] ) : null;
            $is_preset     = ! empty( $selection['is_preset'] );
            $selection_key = $primary;

            if (
                isset( $selection['provider'] )
                && is_scalar( $selection['provider'] )
                && self::MANAGED_PROVIDER === sanitize_key( (string) $selection['provider'] )
            )
            {
                $model_id = $is_preset && $allow_presets ? self::MANAGED_DEFAULT_MODEL : $primary;

                return [
                    'level'           => $level,
                    'selection'       => $selection_key,
                    'model_id'        => $model_id,
                    'backup_model_id' => $backup,
                    'applied'         => false,
                    'reason'          => '' !== $model_id
                        ? __( 'Selection resolves through the Sentient Forms managed service route.', 'sentient-forms' )
                        : __( 'No managed model selection configured at this level.', 'sentient-forms' ),
                ];
            }
        }
        elseif ( is_scalar( $selection ) )
        {
            $primary       = sanitize_text_field( (string) $selection );
            $selection_key = $primary;
        }

        if ( '' === $primary )
        {
            return [
                'level'           => $level,
                'selection'       => null,
                'model_id'        => '',
                'backup_model_id' => null,
                'applied'         => false,
                'reason'          => __( 'No model selection configured at this level.', 'sentient-forms' ),
            ];
        }

        $model_id = $is_preset && $allow_presets ? $this->resolve_preset_model_id( $primary, $presets ) : $primary;
        $reason   = $is_preset && $allow_presets
            ? __( 'Preset resolved from the local OpenRouter model cache.', 'sentient-forms' )
            : __( 'Explicit model selection configured locally.', 'sentient-forms' );

        return [
            'level'           => $level,
            'selection'       => $selection_key,
            'model_id'        => $model_id,
            'backup_model_id' => $backup,
            'applied'         => false,
            'reason'          => '' !== $model_id ? $reason : __( 'The selected preset is not available in the local model policy.', 'sentient-forms' ),
        ];
    }

    private function resolve_preset_model_id( string $preset_code, array $presets ): string
    {
        foreach ( $presets as $preset )
        {
            if ( $preset_code === (string) ( $preset['code'] ?? '' ) )
            {
                return (string) ( $preset['resolved_model_id'] ?? '' );
            }
        }

        return '';
    }

    private function pick_default_model_id( array $models ): string
    {
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
            static fn ( array $model ): bool => 'free' === $model['cost_tier'] && in_array( 'structured-output', $model['tags'], true )
        );

        return $structured_free ?: ( $this->pick_first_model_id( $models, static fn (): bool => true ) ?: 'openrouter/auto' );
    }

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

    private function get_model_by_id( string $model_id, ?array $models = null ): ?array
    {
        $models = $models ?? $this->list_local_openrouter_models();
        return $models[ $model_id ] ?? null;
    }

    private function fallback_openrouter_auto_model(): array
    {
        return [
            'id'                   => 'openrouter/auto',
            'display_name'         => 'OpenRouter Auto',
            'provider'             => 'openrouter',
            'speed_tier'           => 'balanced',
            'cost_tier'            => 'unknown',
            'capabilities'         => [
                'reasoning'    => false,
                'code'         => false,
                'vision'       => false,
                'tools'        => false,
                'structured'   => false,
                'web_search'   => false,
                'long_context' => false,
            ],
            'context_window'       => 0,
            'is_preview'           => false,
            'tags'                 => [ 'fallback' ],
            'supported_parameters' => [],
            'pricing'              => [],
            'recommended_for'      => [ __( 'Fallback until the OpenRouter catalog is refreshed', 'sentient-forms' ) ],
        ];
    }

    private function sanitize_pricing_map( array $pricing ): array
    {
        $sanitized = [];
        foreach ( $pricing as $key => $value )
        {
            if ( is_scalar( $value ) )
            {
                $sanitized[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
            }
        }

        return $sanitized;
    }

    private function sanitize_string_list( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        return array_values( array_filter( array_map( 'sanitize_key', $value ) ) );
    }

    private function model_has_reasoning( string $model_id, string $name, array $supported_parameters ): bool
    {
        return (bool) array_intersect( $supported_parameters, [ 'reasoning', 'reasoning_effort' ] );
    }

    private function infer_speed_tier( string $model_id, string $name ): string
    {
        $haystack = strtolower( $model_id . ' ' . $name );
        if ( preg_match( '/flash|mini|lite|fast|turbo|gpt-oss/', $haystack ) )
        {
            return 'fast';
        }

        if ( preg_match( '/pro|opus|sonnet|reason|thinking/', $haystack ) )
        {
            return 'balanced';
        }

        return 'balanced';
    }

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

    private function recommended_for( bool $is_free, array $capabilities, array $supported_parameters, array $metadata = [] ): array
    {
        $recommendations = $this->sanitize_string_label_list( $metadata['recommended_for'] ?? [] );

        if ( $is_free )
        {
            $recommendations[] = __( 'Free testing', 'sentient-forms' );
        }

        if ( in_array( 'response_format', $supported_parameters, true ) )
        {
            $recommendations[] = __( 'Structured JSON actions', 'sentient-forms' );
        }

        if ( ! empty( $capabilities['long_context'] ) )
        {
            $recommendations[] = __( 'Large forms', 'sentient-forms' );
        }

        if ( ! empty( $capabilities['vision'] ) )
        {
            $recommendations[] = __( 'Image-capable workflows', 'sentient-forms' );
        }

        return $recommendations;
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

    /**
     * Retrieves the schema for the models response.
     *
     * @return array|null Item schema data.
     */
    public function get_item_schema(): ?array
    {
        if ( $this->schema )
        {
            return $this->schema;
        }

        $this->schema = [
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => $this->rest_base,
            'type'       => 'object',
            'properties' => [
                'models' => [
                    'description' => __( 'Available models.', 'sentient-forms' ),
                    'type'        => 'array',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'id'           => [ 'type' => 'string' ],
                            'display_name' => [ 'type' => 'string' ],
                            'provider'     => [ 'type' => 'string' ],
                            'speed_tier'   => [ 'type' => 'string' ],
                            'cost_tier'    => [ 'type' => 'string' ],
                        ],
                    ],
                ],
                'presets' => [
                    'description' => __( 'Available presets.', 'sentient-forms' ),
                    'type'        => 'array',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'code'              => [ 'type' => 'string' ],
                            'display_name'      => [ 'type' => 'string' ],
                            'description'       => [ 'type' => 'string' ],
                            'resolved_model_id' => [ 'type' => 'string' ],
                        ],
                    ],
                ],
            ],
        ];

        return $this->schema;
    }
}
