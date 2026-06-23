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
class Sentient_Forms_Models_Controller extends Sentient_Forms_Abstract_Base_Controller
{
    use Sentient_Forms_Permission_Utils_Trait;

    /**
     * The base of this controller's routes.
     *
     * @var string
     * @since 0.1.0
     */
    protected string $rest_base = 'models';

    private const MANAGED_PROVIDER      = 'sentient_managed';
    private const MANAGED_DEFAULT_MODEL = 'gemini-3-flash-preview';
    private const PRICING_POLICY_VERSION = 'local-openrouter-v2';
    private const OPENROUTER_SERVER_TOOL_COMPATIBILITY_OVERRIDES = [
        '~openai/gpt-latest' => [
            'web_search' => false,
        ],
    ];

    /**
     * Local OpenRouter model metadata cache.
     *
     * @var Sentient_Forms_Model_Cache_Repository
     */
    private Sentient_Forms_Model_Cache_Repository $model_cache;

    /**
     * Local execution-event repository used for estimate calibration.
     *
     * @var Sentient_Forms_Execution_Events_Repository
     */
    private Sentient_Forms_Execution_Events_Repository $execution_events;

    /**
     * Constructor.
     *
     * @param Sentient_Forms_Model_Cache_Repository|null $model_cache Optional repository for tests.
     */
    public function __construct(
        ?Sentient_Forms_Model_Cache_Repository $model_cache = null,
        ?Sentient_Forms_Execution_Events_Repository $execution_events = null
    )
    {
        parent::__construct();

        global $wpdb;
        $this->model_cache      = $model_cache ?? new Sentient_Forms_Model_Cache_Repository( $wpdb );
        $this->execution_events = $execution_events ?? new Sentient_Forms_Execution_Events_Repository( $wpdb );
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
                'pricing_policy_version' => self::PRICING_POLICY_VERSION,
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
        $selection_payload = [
            'global_selection'    => $request->get_param( 'global_selection' ),
            'action_selection'    => $request->get_param( 'action_selection' ),
            'form_selection'      => $request->get_param( 'form_selection' ),
            'mapping_selection'   => $request->get_param( 'mapping_selection' ),
            'template_model_hint' => $request->get_param( 'template_model_hint' ),
        ];

        $resolution = $this->resolve_local_model(
            $selection_payload
        );

        $model    = $this->get_model_by_id( (string) $resolution['model_id'] );
        $provider = $this->infer_selected_provider( $selection_payload, (string) ( $resolution['resolution_source'] ?? '' ) );
        $base     = absint( $request->get_param( 'base_credit_cost' ) );

        return $this->prepare_item_for_response(
            [
                'resolved_model'   => $resolution,
                'pricing_estimate' => $this->build_pricing_estimate(
                    sanitize_key( (string) $request->get_param( 'action_id' ) ),
                    (string) $resolution['model_id'],
                    $model,
                    $provider,
                    $base,
                    $selection_payload
                ),
            ]
        );
    }

    private function infer_selected_provider( array $selection_payload, string $resolution_source ): string
    {
        foreach ( [ 'mapping', 'form', 'action', 'global' ] as $level )
        {
            if ( '' !== $resolution_source && $resolution_source !== $level )
            {
                continue;
            }

            $selection = $selection_payload[ $level . '_selection' ] ?? null;
            if ( is_array( $selection ) && isset( $selection['provider'] ) && is_scalar( $selection['provider'] ) )
            {
                $provider = sanitize_key( (string) $selection['provider'] );
                if ( in_array( $provider, [ 'openrouter', self::MANAGED_PROVIDER ], true ) )
                {
                    return $provider;
                }
            }
        }

        return 'openrouter';
    }

    private function build_pricing_estimate( string $action_id, string $model_id, ?array $model, string $provider, int $base_credits, array $selection_payload = [] ): array
    {
        $profile            = $this->action_cost_profile( $action_id );
        $effective_selection = $this->effective_model_selection( $selection_payload );
        $reasoning_effort   = $this->selected_reasoning_effort( $effective_selection );
        $input_tokens       = absint( $profile['input_tokens'] );
        $output_tokens      = absint( $profile['output_tokens'] );
        $reasoning_tokens   = $this->estimated_reasoning_tokens( absint( $profile['reasoning_tokens'] ), $reasoning_effort );

        if ( self::MANAGED_PROVIDER === $provider )
        {
            $base_floor_credits        = max( 1, $base_credits, absint( $profile['base_credits'] ) );
            $normalized_actual_credits = $this->managed_credit_baseline( $model_id, $input_tokens + $output_tokens + $reasoning_tokens, absint( $profile['credit_weight'] ) );
            $estimated_debit_credits   = max( $base_floor_credits, $normalized_actual_credits );
            $calibration               = $this->calibrate_credit_estimate( $model_id, $estimated_debit_credits );
            $estimated_debit_credits   = max( 1, (int) round( $calibration['estimate'] ) );

            return [
                'action_id'                 => $action_id,
                'resolved_model_id'         => $model_id,
                'route'                     => self::MANAGED_PROVIDER,
                'kind'                      => 'sentient_credits',
                'label'                     => sprintf(
                    /* translators: %d: Sentient Forms managed service credit estimate. */
                    __( 'SF: %d credits', 'sentient-forms' ),
                    $estimated_debit_credits
                ),
                'amount_usd'                => null,
                'estimate_range'            => [
                    'low'  => max( 1, (int) floor( $calibration['low'] ) ),
                    'high' => max( 1, (int) ceil( $calibration['high'] ) ),
                    'unit' => 'credits',
                ],
                'estimated_input_tokens'    => $input_tokens,
                'estimated_output_tokens'   => $output_tokens,
                'estimated_reasoning_tokens' => $reasoning_tokens,
                'sample_count'              => $calibration['sample_count'],
                'confidence'                => $calibration['confidence'],
                'calibration_source'        => $calibration['source'],
                'base_floor_credits'        => $base_floor_credits,
                'normalized_actual_credits' => $normalized_actual_credits,
                'estimated_debit_credits'   => $estimated_debit_credits,
                'pricing_policy_version'    => self::PRICING_POLICY_VERSION,
                'estimate_source'           => $calibration['source'],
            ];
        }

        $pricing = is_array( $model ) && is_array( $model['pricing'] ?? null ) ? $model['pricing'] : [];
        $is_free = is_array( $model ) && ( 'free' === (string) ( $model['cost_tier'] ?? '' ) || 'Free' === (string) ( $model['cost_symbol'] ?? '' ) );

        if ( $is_free )
        {
            $kind  = 'openrouter_free';
            $amount_usd = 0.0;
        }
        else
        {
            $pricing_is_known = [] !== $pricing && (float) ( $pricing['prompt'] ?? -1 ) >= 0 && (float) ( $pricing['completion'] ?? -1 ) >= 0;
            if ( ! $pricing_is_known )
            {
                $pricing = $this->fallback_catalog_pricing();
                $kind    = 'openrouter_variable';
            }
            else
            {
                $kind = 'openrouter_currency';
            }

            $amount_usd = $this->estimate_openrouter_amount_usd( $pricing, $input_tokens, $output_tokens, $reasoning_tokens );
        }

        $calibration = $this->calibrate_usd_estimate( $model_id, $pricing, $amount_usd );
        $amount_usd  = (float) $calibration['estimate'];
        $label       = 'openrouter_free' === $kind
            ? __( 'OR est. $0.00', 'sentient-forms' )
            : sprintf(
                /* translators: %s: OpenRouter provider dollar estimate. */
                __( 'OR est. %s', 'sentient-forms' ),
                $this->format_usd_estimate( $amount_usd )
            );

        if ( 'openrouter_variable' === $kind && $calibration['sample_count'] <= 0 )
        {
            $label = sprintf(
                /* translators: %s: OpenRouter provider dollar estimate. */
                __( 'OR est. %s baseline', 'sentient-forms' ),
                $this->format_usd_estimate( $amount_usd )
            );
        }

        return [
            'action_id'                 => $action_id,
            'resolved_model_id'         => $model_id,
            'route'                     => 'openrouter',
            'kind'                      => $kind,
            'label'                     => $label,
            'amount_usd'                => $amount_usd,
            'estimate_range'            => [
                'low'      => $calibration['low'],
                'high'     => $calibration['high'],
                'currency' => 'USD',
                'unit'     => 'usd',
            ],
            'estimated_input_tokens'    => $input_tokens,
            'estimated_output_tokens'   => $output_tokens,
            'estimated_reasoning_tokens' => $reasoning_tokens,
            'sample_count'              => $calibration['sample_count'],
            'confidence'                => $calibration['confidence'],
            'calibration_source'        => $calibration['source'],
            'provider_pricing'          => $this->sanitize_pricing_map( $pricing ),
            'base_floor_credits'        => $base_credits,
            'normalized_actual_credits' => 0,
            'estimated_debit_credits'   => 0,
            'pricing_policy_version'    => self::PRICING_POLICY_VERSION,
            'estimate_source'           => $calibration['source'],
        ];
    }

    private function action_cost_profile( string $action_id ): array
    {
        $action_id = sanitize_key( $action_id );
        $profiles  = [
            'spam_detection_v1'          => [ 'input_tokens' => 1800, 'output_tokens' => 90, 'reasoning_tokens' => 700, 'base_credits' => 1, 'credit_weight' => 1 ],
            'spam_analysis'              => [ 'input_tokens' => 1800, 'output_tokens' => 90, 'reasoning_tokens' => 700, 'base_credits' => 1, 'credit_weight' => 1 ],
            'content_validation_v1'      => [ 'input_tokens' => 2200, 'output_tokens' => 260, 'reasoning_tokens' => 900, 'base_credits' => 2, 'credit_weight' => 2 ],
            'entry_summary_v1'           => [ 'input_tokens' => 1900, 'output_tokens' => 320, 'reasoning_tokens' => 500, 'base_credits' => 1, 'credit_weight' => 1 ],
            'entry_summary'              => [ 'input_tokens' => 1900, 'output_tokens' => 320, 'reasoning_tokens' => 500, 'base_credits' => 1, 'credit_weight' => 1 ],
            'clarification_assistant_v1' => [ 'input_tokens' => 1700, 'output_tokens' => 420, 'reasoning_tokens' => 500, 'base_credits' => 1, 'credit_weight' => 1 ],
        ];

        return $profiles[ $action_id ] ?? [ 'input_tokens' => 2000, 'output_tokens' => 300, 'reasoning_tokens' => 600, 'base_credits' => 1, 'credit_weight' => 1 ];
    }

    private function effective_model_selection( array $selection_payload ): array
    {
        foreach ( [ 'mapping_selection', 'form_selection', 'action_selection', 'global_selection' ] as $key )
        {
            $selection = $selection_payload[ $key ] ?? null;
            if ( is_array( $selection ) && isset( $selection['primary'] ) )
            {
                return $selection;
            }
        }

        return [];
    }

    private function selected_reasoning_effort( array $selection ): string
    {
        $effort = isset( $selection['reasoning'] ) && is_scalar( $selection['reasoning'] )
            ? sanitize_key( (string) $selection['reasoning'] )
            : 'default';

        return in_array( $effort, [ 'none', 'minimal', 'low', 'medium', 'high', 'xhigh' ], true )
            ? $effort
            : 'default';
    }

    private function estimated_reasoning_tokens( int $basis, string $effort ): int
    {
        $ratio = match ( $effort ) {
            'none'    => 0.0,
            'minimal' => 0.10,
            'low'     => 0.20,
            'medium'  => 0.50,
            'high'    => 0.80,
            'xhigh'   => 0.95,
            default   => 0.0,
        };

        return (int) ceil( max( 0, $basis ) * $ratio );
    }

    private function managed_credit_baseline( string $model_id, int $estimated_tokens, int $action_weight ): int
    {
        $token_blocks = max( 1, (int) ceil( max( 1, $estimated_tokens ) / 1600 ) );
        $model_rate   = $this->managed_model_credit_rate( $model_id );

        return max( 1, $token_blocks * $model_rate * max( 1, $action_weight ) );
    }

    private function managed_model_credit_rate( string $model_id ): int
    {
        $model_id = strtolower( $model_id );
        if ( str_contains( $model_id, 'opus' ) || str_contains( $model_id, 'pro' ) )
        {
            return 3;
        }

        if ( str_contains( $model_id, 'sonnet' ) || str_contains( $model_id, 'gpt-5.5' ) )
        {
            return 2;
        }

        return 1;
    }

    private function estimate_openrouter_amount_usd( array $pricing, int $input_tokens, int $output_tokens, int $reasoning_tokens ): float
    {
        $prompt_price     = max( 0.0, (float) ( $pricing['prompt'] ?? 0 ) );
        $completion_price = max( 0.0, (float) ( $pricing['completion'] ?? 0 ) );
        $request_price    = max( 0.0, (float) ( $pricing['request'] ?? 0 ) );
        $reasoning_price  = isset( $pricing['internal_reasoning'] )
            ? max( 0.0, (float) $pricing['internal_reasoning'] )
            : $completion_price;

        return round(
            ( max( 0, $input_tokens ) * $prompt_price )
            + ( max( 0, $output_tokens ) * $completion_price )
            + ( max( 0, $reasoning_tokens ) * $reasoning_price )
            + $request_price,
            8
        );
    }

    private function fallback_catalog_pricing(): array
    {
        $prompt_prices     = [];
        $completion_prices = [];

        foreach ( $this->list_local_openrouter_models() as $model )
        {
            $pricing = is_array( $model['pricing'] ?? null ) ? $model['pricing'] : [];
            $prompt  = (float) ( $pricing['prompt'] ?? -1 );
            $output  = (float) ( $pricing['completion'] ?? -1 );
            if ( $prompt > 0 && $output > 0 )
            {
                $prompt_prices[]     = $prompt;
                $completion_prices[] = $output;
            }
        }

        return [
            'prompt'     => (string) ( $this->median_float( $prompt_prices ) ?? 0.000001 ),
            'completion' => (string) ( $this->median_float( $completion_prices ) ?? 0.000003 ),
            'request'    => '0',
        ];
    }

    private function median_float( array $values ): ?float
    {
        $values = array_values(
            array_filter(
                array_map( 'floatval', $values ),
                static fn ( float $value ): bool => $value >= 0
            )
        );
        if ( [] === $values )
        {
            return null;
        }

        sort( $values, SORT_NUMERIC );
        $count  = count( $values );
        $middle = intdiv( $count, 2 );

        return 0 === $count % 2
            ? ( $values[ $middle - 1 ] + $values[ $middle ] ) / 2
            : $values[ $middle ];
    }

    private function calibrate_usd_estimate( string $model_id, array $pricing, float $baseline ): array
    {
        if ( $baseline <= 0 )
        {
            return $this->estimate_calibration_payload( 0.0, 0.0, 0.0, 0, 'baseline', 'baseline_profile' );
        }

        $ratios = [];
        foreach ( array_reverse( $this->execution_events->list_recent_for_action_log( 500 ) ) as $event )
        {
            if ( ! $this->event_matches_estimate_route( $event, 'openrouter', $model_id ) )
            {
                continue;
            }

            $actual = $this->extract_event_usd_cost( $event );
            if ( null === $actual )
            {
                continue;
            }

            $event_tokens   = is_array( $event['token_usage_json'] ?? null ) ? $event['token_usage_json'] : [];
            $event_baseline = $this->estimate_openrouter_amount_usd(
                $pricing,
                $this->token_count_from_usage( $event_tokens, [ 'input_tokens', 'prompt_tokens', 'tokens_prompt', 'native_tokens_prompt' ] ),
                $this->token_count_from_usage( $event_tokens, [ 'output_tokens', 'completion_tokens', 'tokens_completion', 'native_tokens_completion' ] ),
                $this->reasoning_token_count_from_usage( $event_tokens )
            );
            $denominator = $event_baseline > 0 ? $event_baseline : $baseline;
            if ( $denominator <= 0 )
            {
                continue;
            }

            $ratios[] = max( 0.25, min( 4.0, $actual / $denominator ) );
        }

        return $this->calibrate_with_ratios( $baseline, $ratios, 'action_log_openrouter_cost' );
    }

    private function calibrate_credit_estimate( string $model_id, int $baseline ): array
    {
        $ratios = [];
        foreach ( array_reverse( $this->execution_events->list_recent_for_action_log( 500 ) ) as $event )
        {
            if ( ! $this->event_matches_estimate_route( $event, self::MANAGED_PROVIDER, $model_id ) )
            {
                continue;
            }

            $actual = $this->extract_event_credit_cost( $event );
            if ( null === $actual || $actual <= 0 )
            {
                continue;
            }

            $ratios[] = max( 0.25, min( 4.0, $actual / max( 1, $baseline ) ) );
        }

        return $this->calibrate_with_ratios( (float) max( 1, $baseline ), $ratios, 'action_log_managed_credits' );
    }

    private function calibrate_with_ratios( float $baseline, array $ratios, string $source ): array
    {
        $count = count( $ratios );
        if ( 0 === $count )
        {
            $spread = max( 0.01, $baseline * 0.35 );
            return $this->estimate_calibration_payload( $baseline, max( 0.0, $baseline - $spread ), $baseline + $spread, 0, 'baseline', 'baseline_profile' );
        }

        $ewma = 1.0;
        foreach ( $ratios as $ratio )
        {
            $ewma = ( 0.35 * (float) $ratio ) + ( 0.65 * $ewma );
        }

        $shrinkage = $count / ( $count + 6 );
        $factor    = 1 + ( $shrinkage * ( $ewma - 1 ) );
        $estimate  = max( 0.0, $baseline * $factor );
        $spread    = max( 0.01, $estimate * ( $count >= 8 ? 0.18 : 0.28 ) );

        return $this->estimate_calibration_payload(
            $estimate,
            max( 0.0, $estimate - $spread ),
            $estimate + $spread,
            $count,
            $this->confidence_for_sample_count( $count ),
            $source
        );
    }

    private function estimate_calibration_payload( float $estimate, float $low, float $high, int $sample_count, string $confidence, string $source ): array
    {
        return [
            'estimate'     => round( $estimate, 8 ),
            'low'          => round( $low, 8 ),
            'high'         => round( $high, 8 ),
            'sample_count' => $sample_count,
            'confidence'   => $confidence,
            'source'       => $source,
        ];
    }

    private function confidence_for_sample_count( int $sample_count ): string
    {
        if ( $sample_count >= 20 )
        {
            return 'high';
        }

        if ( $sample_count >= 8 )
        {
            return 'medium';
        }

        return 'low';
    }

    private function event_matches_estimate_route( array $event, string $provider, string $model_id ): bool
    {
        $status = sanitize_key( (string) ( $event['status'] ?? '' ) );
        if ( ! in_array( $status, [ 'succeeded', 'success', 'completed' ], true ) )
        {
            return false;
        }

        if ( sanitize_key( (string) ( $event['provider'] ?? '' ) ) !== $provider )
        {
            return false;
        }

        $event_model = isset( $event['model'] ) && is_scalar( $event['model'] ) ? (string) $event['model'] : '';
        return '' === $event_model || 'openrouter/auto' === $model_id || $event_model === $model_id;
    }

    private function extract_event_usd_cost( array $event ): ?float
    {
        $cost = is_array( $event['cost_json'] ?? null ) ? $event['cost_json'] : [];
        if ( isset( $cost['amount_usd'] ) && is_numeric( $cost['amount_usd'] ) )
        {
            return max( 0.0, (float) $cost['amount_usd'] );
        }

        return null;
    }

    private function extract_event_credit_cost( array $event ): ?int
    {
        $cost = is_array( $event['cost_json'] ?? null ) ? $event['cost_json'] : [];
        if ( isset( $cost['debited_credits'] ) && is_numeric( $cost['debited_credits'] ) )
        {
            return absint( $cost['debited_credits'] );
        }

        $result = is_array( $event['result_json'] ?? null ) ? $event['result_json'] : [];
        if ( isset( $result['metering']['debited_credits'] ) && is_numeric( $result['metering']['debited_credits'] ) )
        {
            return absint( $result['metering']['debited_credits'] );
        }

        return null;
    }

    private function token_count_from_usage( array $usage, array $keys ): int
    {
        foreach ( $keys as $key )
        {
            if ( isset( $usage[ $key ] ) && is_numeric( $usage[ $key ] ) )
            {
                return max( 0, (int) $usage[ $key ] );
            }
        }

        return 0;
    }

    private function reasoning_token_count_from_usage( array $usage ): int
    {
        if ( isset( $usage['native_tokens_reasoning'] ) && is_numeric( $usage['native_tokens_reasoning'] ) )
        {
            return max( 0, (int) $usage['native_tokens_reasoning'] );
        }

        foreach ( [ 'output_tokens_details', 'completion_tokens_details' ] as $details_key )
        {
            $details = is_array( $usage[ $details_key ] ?? null ) ? $usage[ $details_key ] : [];
            if ( isset( $details['reasoning_tokens'] ) && is_numeric( $details['reasoning_tokens'] ) )
            {
                return max( 0, (int) $details['reasoning_tokens'] );
            }
        }

        return 0;
    }

    private function format_usd_estimate( float $amount ): string
    {
        if ( $amount <= 0 )
        {
            return '$0.00';
        }

        if ( $amount < 0.01 )
        {
            return '$' . rtrim( rtrim( number_format_i18n( $amount, 6 ), '0' ), '.' );
        }

        return '$' . rtrim( rtrim( number_format_i18n( $amount, 4 ), '0' ), '.' );
    }

    private function format_usd_amount( float $amount ): string
    {
        if ( $amount >= 1 )
        {
            return number_format_i18n( $amount, 2 );
        }

        return rtrim( rtrim( number_format_i18n( $amount, 4 ), '0' ), '.' );
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
                    'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + MONTH_IN_SECONDS ),
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

    private function format_openrouter_model_info( array $row ): array
    {
        $metadata             = is_array( $row['metadata_json'] ?? null ) ? $row['metadata_json'] : [];
        $model_id             = sanitize_text_field( (string) ( $row['model_id'] ?? $metadata['id'] ?? '' ) );
        $name                 = isset( $metadata['name'] ) ? sanitize_text_field( (string) $metadata['name'] ) : $model_id;
        $pricing              = isset( $metadata['pricing'] ) && is_array( $metadata['pricing'] ) ? $metadata['pricing'] : [];
        $architecture         = isset( $metadata['architecture'] ) && is_array( $metadata['architecture'] ) ? $metadata['architecture'] : [];
        $input_modalities     = $this->sanitize_string_list( $metadata['input_modalities'] ?? $architecture['input_modalities'] ?? [] );
        $output_modalities    = $this->sanitize_string_list( $metadata['output_modalities'] ?? $architecture['output_modalities'] ?? [] );
        $supported_parameters = $this->sanitize_string_list( $metadata['supported_parameters'] ?? [] );
        $context_window       = isset( $metadata['context_length'] ) ? absint( $metadata['context_length'] ) : 0;
        $is_free              = ! empty( $metadata['free'] );
        $is_preview           = str_contains( strtolower( $model_id . ' ' . $name ), 'preview' );
        $is_stale             = isset( $row['expires_at'] ) && (string) $row['expires_at'] < current_time( 'mysql', true );
        $provider_family      = str_contains( $model_id, '/' ) ? sanitize_key( strtok( $model_id, '/' ) ) : '';
        $server_tools         = $this->openrouter_server_tool_capabilities( $metadata, $supported_parameters, $pricing, $model_id );
        $zdr                  = $this->format_zdr_eligibility( $metadata );

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
            'server_tools' => $server_tools,
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
                    true === $zdr['eligible'] ? 'zdr' : null,
                ]
            )
        );

        return [
            'id'              => $model_id,
            'display_name'    => '' !== $name ? $name : $model_id,
            'provider'        => 'openrouter',
            'provider_family' => $provider_family,
            'developer'       => $this->developer_label_for_provider( $provider_family ),
            'description'     => isset( $metadata['description'] ) && is_scalar( $metadata['description'] )
                ? sanitize_textarea_field( (string) $metadata['description'] )
                : '',
            'speed_tier'      => $this->infer_speed_tier( $model_id, $name ),
            'cost_tier'       => $is_free ? 'free' : $this->infer_cost_tier( $pricing ),
            'cost_symbol'     => $is_free ? 'Free' : $this->cost_symbol_for_pricing( $pricing ),
            'capabilities'    => $capabilities,
            'context_window'  => $context_window,
            'is_preview'      => $is_preview,
            'tags'            => $tags,
            'zdr_eligible'    => $zdr['eligible'],
            'zdr_source'      => $zdr['source'],
            'zdr_checked_at'  => $zdr['checked_at'],
            'supported_parameters' => $supported_parameters,
            'input_modalities' => $input_modalities,
            'output_modalities' => $output_modalities,
            'pricing'         => $this->sanitize_pricing_map( $pricing ),
            'recommended_for' => $this->recommended_for( $is_free, $capabilities, $supported_parameters, $metadata ),
            'recommendation_categories' => $this->sanitize_string_list( $metadata['recommendation_categories'] ?? [] ),
            'category_rankings' => $this->sanitize_category_rankings( $metadata['category_rankings'] ?? [] ),
            'ranking_snapshot' => $this->sanitize_ranking_snapshot( $metadata['ranking_snapshot'] ?? [] ),
            'benchmark_notes' => $this->sanitize_string_label_list( $metadata['benchmark_notes'] ?? [] ),
            'source_urls'     => $this->sanitize_url_list( $metadata['source_urls'] ?? [] ),
            'created'         => isset( $metadata['created'] ) && is_scalar( $metadata['created'] ) ? sanitize_text_field( (string) $metadata['created'] ) : null,
            'knowledge_cutoff' => isset( $metadata['knowledge_cutoff'] ) && is_scalar( $metadata['knowledge_cutoff'] ) ? sanitize_text_field( (string) $metadata['knowledge_cutoff'] ) : null,
            'canonical_source_model_id' => isset( $metadata['canonical_source_model_id'] ) && is_scalar( $metadata['canonical_source_model_id'] )
                ? sanitize_text_field( (string) $metadata['canonical_source_model_id'] )
                : null,
        ];
    }

    private function build_presets( array $models ): array
    {
        $preset_evidence   = $this->model_preset_evidence();
        $recommended_model = $this->pick_evidence_model_id(
            $models,
            'sf_default',
            [ 'openai/gpt-5.5', 'anthropic/claude-sonnet-4.6', 'google/gemini-3-flash-preview', 'openai/gpt-5.4' ]
        ) ?: $this->pick_default_model_id( $models );
        $general_model     = $this->pick_evidence_model_id(
            $models,
            'sf_general',
            [ 'openai/gpt-5.5', 'google/gemini-3-flash-preview', 'anthropic/claude-sonnet-4.6', 'openai/gpt-5.4' ]
        ) ?: $recommended_model;
        $quality_model     = $this->pick_evidence_model_id(
            $models,
            'sf_quality',
            [ 'openai/gpt-5.5-pro', 'anthropic/claude-opus-4.7', 'anthropic/claude-sonnet-4.6', 'openai/gpt-5.5' ]
        ) ?: $recommended_model;
        $free_model        = $this->pick_evidence_model_id( $models, 'sf_free', [ 'openrouter/free' ] )
            ?: ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => 'free' === $model['cost_tier'] ) ?: $recommended_model );
        $structured_model  = $this->pick_evidence_model_id(
            $models,
            'sf_structured',
            [ 'openai/gpt-5.5', 'google/gemini-3-flash-preview', 'nvidia/nemotron-3-super-120b-a12b:free', 'openrouter/free' ]
        )
            ?: ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => in_array( 'structured-output', $model['tags'], true ) ) ?: $recommended_model );
        $fast_model        = $this->pick_evidence_model_id(
            $models,
            'sf_fast',
            [ 'google/gemini-3-flash-preview', 'google/gemini-3.1-flash-lite-preview', 'openai/gpt-5.4', 'openai/gpt-5.4-mini' ]
        )
            ?: ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => in_array( (string) ( $model['speed_tier'] ?? '' ), [ 'fastest', 'fast' ], true ) ) ?: $recommended_model );
        $low_cost_model    = $this->pick_evidence_model_id(
            $models,
            'sf_low_cost',
            [ 'deepseek/deepseek-v4-flash', 'google/gemini-3.1-flash-lite-preview', 'deepseek/deepseek-v4-pro', 'openai/gpt-5.4-mini' ]
        )
            ?: ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => 'free' !== (string) ( $model['cost_tier'] ?? '' ) && in_array( (string) ( $model['cost_tier'] ?? '' ), [ 'low', 'medium' ], true ) ) ?: $free_model );
        $long_model        = $this->pick_evidence_model_id(
            $models,
            'sf_long_context',
            [ 'openai/gpt-5.5', 'google/gemini-3.1-pro-preview', 'anthropic/claude-opus-4.7', 'moonshotai/kimi-k2.6' ]
        )
            ?: ( $this->pick_long_context_model_id( $models ) ?: $recommended_model );
        $reasoning_model   = $this->pick_evidence_model_id(
            $models,
            'sf_reasoning',
            [ 'openai/gpt-5.5-pro', 'openai/gpt-5.5', 'anthropic/claude-opus-4.7', 'z-ai/glm-5.1', 'google/gemini-3.1-pro-preview' ]
        )
            ?: ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => ! empty( $model['capabilities']['reasoning'] ) ) ?: $recommended_model );
        $code_model        = $this->pick_evidence_model_id(
            $models,
            'sf_code',
            [ 'moonshotai/kimi-k2.6', 'anthropic/claude-opus-4.7', 'anthropic/claude-sonnet-4.6', 'qwen/qwen3.6-max-preview', 'openai/gpt-5.5' ]
        )
            ?: ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => ! empty( $model['capabilities']['code'] ) ) ?: $recommended_model );
        $legal_model       = $this->pick_evidence_model_id(
            $models,
            'sf_legal',
            [ 'google/gemini-3.1-pro-preview', 'anthropic/claude-sonnet-4.6', 'openai/gpt-5.5', 'anthropic/claude-opus-4.7' ]
        ) ?: $recommended_model;
        $financial_model   = $this->pick_evidence_model_id(
            $models,
            'sf_financial',
            [ 'anthropic/claude-sonnet-4.6', 'anthropic/claude-opus-4.7', 'openai/gpt-5.5' ]
        ) ?: $recommended_model;
        $privacy_model     = $this->pick_evidence_model_id(
            $models,
            'sf_privacy',
            [ 'openai/gpt-5.5', 'anthropic/claude-sonnet-4.6', 'google/gemini-3.1-pro-preview' ]
        ) ?: $recommended_model;
        $realtime_model    = $this->pick_evidence_model_id(
            $models,
            'sf_realtime',
            [ 'google/gemini-3-flash-preview', 'google/gemini-3.1-flash-lite-preview', 'deepseek/deepseek-v4-flash' ]
        ) ?: $fast_model;
        $multimodal_model  = $this->pick_evidence_model_id(
            $models,
            'sf_multimodal',
            [ 'google/gemini-3.1-pro-preview', 'google/gemini-3-flash-preview', 'openai/gpt-5.5' ]
        ) ?: $recommended_model;
        $research_model    = $this->pick_evidence_model_id(
            $models,
            'sf_research',
            [ 'openai/gpt-5.5', 'anthropic/claude-opus-4.7', 'google/gemini-3.1-pro-preview' ]
        ) ?: $recommended_model;
        $agentic_model     = $this->pick_evidence_model_id(
            $models,
            'sf_agentic',
            [ 'google/gemini-3.1-pro-preview', 'openai/gpt-5.5', 'anthropic/claude-opus-4.7' ]
        ) ?: $recommended_model;

        $preset_definitions = [
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
                'resolved_model_id' => $general_model,
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
                'description'       => __( 'Prefers models with benchmark-backed long-document reasoning, not just the largest advertised context window.', 'sentient-forms' ),
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
                'description'       => __( 'Prefers current OpenRouter-available leaders for coding and software-engineering tasks.', 'sentient-forms' ),
                'category'          => 'local',
                'resolved_model_id' => $code_model,
                'auto_upgrade'      => true,
            ],
            [
                'code'              => 'sf_legal',
                'display_name'      => __( 'Legal', 'sentient-forms' ),
                'description'       => __( 'Prefers models with current legal-reasoning benchmark strength. Always require human review for legal advice.', 'sentient-forms' ),
                'category'          => 'domain',
                'resolved_model_id' => $legal_model,
                'auto_upgrade'      => true,
            ],
            [
                'code'              => 'sf_financial',
                'display_name'      => __( 'Financial', 'sentient-forms' ),
                'description'       => __( 'Prefers models with current finance and accounting benchmark strength. Always require human review for financial decisions.', 'sentient-forms' ),
                'category'          => 'domain',
                'resolved_model_id' => $financial_model,
                'auto_upgrade'      => true,
            ],
            [
                'code'              => 'sf_privacy',
                'display_name'      => __( 'Privacy-sensitive', 'sentient-forms' ),
                'description'       => __( 'Prefers a strong model, but privacy depends on the selected route and upstream provider retention policy.', 'sentient-forms' ),
                'category'          => 'domain',
                'resolved_model_id' => $privacy_model,
                'auto_upgrade'      => true,
            ],
            [
                'code'              => 'sf_realtime',
                'display_name'      => __( 'Realtime', 'sentient-forms' ),
                'description'       => __( 'Prefers low-latency models for visitor-facing form feedback.', 'sentient-forms' ),
                'category'          => 'local',
                'resolved_model_id' => $realtime_model,
                'auto_upgrade'      => true,
            ],
            [
                'code'              => 'sf_multimodal',
                'display_name'      => __( 'Vision and files', 'sentient-forms' ),
                'description'       => __( 'Prefers models with strong multimodal metadata for uploads, screenshots, and rich form context.', 'sentient-forms' ),
                'category'          => 'capability',
                'resolved_model_id' => $multimodal_model,
                'auto_upgrade'      => true,
            ],
            [
                'code'              => 'sf_research',
                'display_name'      => __( 'Search and research', 'sentient-forms' ),
                'description'       => __( 'Prefers models suited to research-style tasks and optional web-search routes when enabled.', 'sentient-forms' ),
                'category'          => 'capability',
                'resolved_model_id' => $research_model,
                'auto_upgrade'      => true,
            ],
            [
                'code'              => 'sf_agentic',
                'display_name'      => __( 'Tool calling', 'sentient-forms' ),
                'description'       => __( 'Prefers models with tool-calling and structured-parameter strength for agentic workflows.', 'sentient-forms' ),
                'category'          => 'capability',
                'resolved_model_id' => $agentic_model,
                'auto_upgrade'      => true,
            ],
        ];

        return array_map(
            fn ( array $preset ): array => $this->build_model_preset( $preset, $preset_evidence ),
            $preset_definitions
        );
    }

    private function build_model_preset( array $preset, array $preset_evidence ): array
    {
        $code     = (string) ( $preset['code'] ?? '' );
        $evidence = isset( $preset_evidence[ $code ] ) && is_array( $preset_evidence[ $code ] )
            ? $preset_evidence[ $code ]
            : [];

        if ( [] === $evidence )
        {
            return $preset;
        }

        if ( isset( $evidence['rationale'] ) && is_scalar( $evidence['rationale'] ) )
        {
            $preset['rationale'] = sanitize_textarea_field( (string) $evidence['rationale'] );
        }

        if ( isset( $evidence['score'] ) )
        {
            $preset['score'] = min( 100, absint( $evidence['score'] ) );
        }

        if ( isset( $evidence['evidence_confidence'] ) && is_scalar( $evidence['evidence_confidence'] ) )
        {
            $preset['evidence_confidence'] = sanitize_key( (string) $evidence['evidence_confidence'] );
        }

        if ( isset( $evidence['evaluated_at'] ) && is_scalar( $evidence['evaluated_at'] ) )
        {
            $preset['evaluated_at'] = sanitize_text_field( (string) $evidence['evaluated_at'] );
        }

        $preset['score_breakdown'] = $this->sanitize_score_breakdown( $evidence['score_breakdown'] ?? [] );
        $preset['top_candidates']  = $this->sanitize_evidence_candidates( $evidence['top_candidates'] ?? [] );
        $preset['source_urls']     = $this->sanitize_url_list( $evidence['source_urls'] ?? [] );

        return $preset;
    }

    private function pick_evidence_model_id( array $models, string $preset_code, array $fallback_model_ids ): ?string
    {
        $evidence     = $this->model_preset_evidence();
        $preferred_ids = $fallback_model_ids;

        if ( isset( $evidence[ $preset_code ]['preferred_model_ids'] ) && is_array( $evidence[ $preset_code ]['preferred_model_ids'] ) )
        {
            $preferred_ids = $this->sanitize_model_id_list( $evidence[ $preset_code ]['preferred_model_ids'] );
        }

        if ( [] === $preferred_ids )
        {
            return null;
        }

        return $this->pick_preferred_model_id( $models, $preferred_ids );
    }

    private function model_preset_evidence(): array
    {
        static $preset_evidence = null;

        if ( null !== $preset_evidence )
        {
            return $preset_evidence;
        }

        $evidence_file = __DIR__ . '/../../data/model-selector-preset-evidence.php';
        if ( ! file_exists( $evidence_file ) )
        {
            $preset_evidence = [];
            return [];
        }

        $evidence = require $evidence_file;
        $preset_evidence = is_array( $evidence ) ? $evidence : [];

        return $preset_evidence;
    }

    private function resolve_local_model( array $payload ): array
    {
        $models      = $this->list_local_openrouter_models();
        $presets     = $this->build_presets( $models );
        $zdr_presets = $this->build_zdr_presets( $models );
        $chain       = [];

        $template_hint = isset( $payload['template_model_hint'] ) ? sanitize_text_field( (string) $payload['template_model_hint'] ) : '';
        $chain[] = $this->build_resolution_step( 'template', $template_hint, false, $presets, $zdr_presets );

        foreach ( [ 'global', 'action', 'form', 'mapping' ] as $level )
        {
            $selection = $payload[ $level . '_selection' ] ?? null;
            $chain[]   = $this->build_resolution_step( $level, $selection, true, $presets, $zdr_presets );
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

        $requires_zdr_resolution = $this->override_chain_requires_managed_zdr( $chain );
        if ( null === $applied_index )
        {
            $fallback_models   = $requires_zdr_resolution ? $this->zdr_eligible_models( $models ) : $models;
            $fallback_model_id = [] === $fallback_models ? '' : $this->pick_default_model_id( $fallback_models );
            $chain[] = [
                'level'           => 'fallback',
                'selection'       => 'sf_default',
                'model_id'        => $fallback_model_id,
                'backup_model_id' => null,
                'requires_zdr'    => $requires_zdr_resolution,
                'applied'         => true,
                'reason'          => $requires_zdr_resolution
                    ? ( '' !== $fallback_model_id
                        ? __( 'No selected ZDR-safe managed preset was available, so the ZDR-safe local default was used.', 'sentient-forms' )
                        : __( 'No ZDR-safe local model is available for the managed service requirement.', 'sentient-forms' ) )
                    : __( 'No override was configured, so the local default preset was used.', 'sentient-forms' ),
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

    private function build_resolution_step( string $level, mixed $selection, bool $allow_presets, array $presets, array $zdr_presets ): array
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
                $requires_zdr   = ! empty( $selection['require_zdr'] );
                $policy_presets = $requires_zdr ? $zdr_presets : $presets;
                $model_id       = $is_preset && $allow_presets ? $this->resolve_preset_model_id( $primary, $policy_presets ) : $primary;

                return [
                    'level'           => $level,
                    'selection'       => $selection_key,
                    'model_id'        => $model_id,
                    'backup_model_id' => $backup,
                    'requires_zdr'    => $requires_zdr,
                    'applied'         => false,
                    'reason'          => '' !== $model_id
                        ? ( $requires_zdr
                            ? __( 'Selection resolves through the Sentient Forms managed service route using the ZDR-safe local model policy.', 'sentient-forms' )
                            : __( 'Selection resolves through the Sentient Forms managed service route.', 'sentient-forms' ) )
                        : ( $requires_zdr
                            ? __( 'The selected managed preset is not available in the ZDR-safe local model policy.', 'sentient-forms' )
                            : __( 'The selected managed preset is not available in the local model policy.', 'sentient-forms' ) ),
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
            'requires_zdr'    => false,
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
            'requires_zdr'    => false,
            'applied'         => false,
            'reason'          => '' !== $model_id ? $reason : __( 'The selected preset is not available in the local model policy.', 'sentient-forms' ),
        ];
    }

    private function override_chain_requires_managed_zdr( array $chain ): bool
    {
        foreach ( $chain as $step )
        {
            if ( ! empty( $step['requires_zdr'] ) )
            {
                return true;
            }
        }

        return false;
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

    private function build_zdr_presets( array $models ): array
    {
        $zdr_models = $this->zdr_eligible_models( $models );
        if ( [] === $zdr_models )
        {
            return [];
        }

        return $this->build_presets( $zdr_models );
    }

    private function zdr_eligible_models( array $models ): array
    {
        return array_filter(
            $models,
            static fn ( array $model ): bool => true === ( $model['zdr_eligible'] ?? null )
        );
    }

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

    /**
     * @param array<string, array<string, mixed>> $models
     * @param array<int, string>                  $preferred_model_ids
     */
    private function pick_preferred_model_id( array $models, array $preferred_model_ids ): ?string
    {
        foreach ( $preferred_model_ids as $model_id )
        {
            $latest_alias = $this->latest_alias_for_model_id( $model_id );
            if ( null !== $latest_alias && isset( $models[ $latest_alias ] ) )
            {
                return $latest_alias;
            }

            if ( isset( $models[ $model_id ] ) )
            {
                return $model_id;
            }
        }

        return null;
    }

    private function latest_alias_for_model_id( string $model_id ): ?string
    {
        return match ( $model_id ) {
            'openai/gpt-5.5', 'openai/gpt-5.4' => '~openai/gpt-latest',
            'openai/gpt-5.4-mini'             => '~openai/gpt-mini-latest',
            'google/gemini-3.1-pro-preview'   => '~google/gemini-pro-latest',
            'google/gemini-3-flash-preview'   => '~google/gemini-flash-latest',
            'anthropic/claude-opus-4.6',
            'anthropic/claude-opus-4.7'        => '~anthropic/claude-opus-latest',
            'anthropic/claude-sonnet-4.6'      => '~anthropic/claude-sonnet-latest',
            'anthropic/claude-haiku-4.5'       => '~anthropic/claude-haiku-latest',
            default                            => null,
        };
    }

    private function format_zdr_eligibility( array $metadata ): array
    {
        $eligible = array_key_exists( 'zdr_eligible', $metadata )
            ? rest_sanitize_boolean( $metadata['zdr_eligible'] )
            : null;
        $source = isset( $metadata['zdr_source'] ) && is_scalar( $metadata['zdr_source'] )
            ? sanitize_key( (string) $metadata['zdr_source'] )
            : null;
        $checked_at = isset( $metadata['zdr_checked_at'] ) && is_scalar( $metadata['zdr_checked_at'] )
            ? sanitize_text_field( (string) $metadata['zdr_checked_at'] )
            : null;

        return [
            'eligible'   => $eligible,
            'source'     => '' !== (string) $source ? $source : null,
            'checked_at' => '' !== (string) $checked_at ? $checked_at : null,
        ];
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
            'cost_symbol'          => 'Varies',
            'capabilities'         => [
                'reasoning'    => false,
                'code'         => false,
                'vision'       => false,
                'tools'        => false,
                'structured'   => false,
                'web_search'   => false,
                'server_tools' => [
                    'web_search' => false,
                    'web_fetch'  => false,
                    'datetime'   => false,
                ],
                'long_context' => false,
            ],
            'context_window'       => 0,
            'is_preview'           => false,
            'tags'                 => [ 'fallback' ],
            'supported_parameters' => [],
            'pricing'              => [],
            'recommended_for'      => [ __( 'Fallback until the OpenRouter catalog is refreshed', 'sentient-forms' ) ],
            'category_rankings'    => [],
            'ranking_snapshot'     => [],
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

    private function openrouter_server_tool_capabilities( array $metadata, array $supported_parameters, array $pricing, string $model_id ): array
    {
        $declared       = is_array( $metadata['openrouter_server_tools'] ?? null ) ? $metadata['openrouter_server_tools'] : [];
        $supports_tools = in_array( 'tools', $supported_parameters, true );

        $server_tools = [
            'web_search' => $this->openrouter_server_tool_support_value(
                $declared,
                'web_search',
                array_key_exists( 'web_search', $pricing ) || in_array( 'web_search_options', $supported_parameters, true )
            ),
            'web_fetch'  => $supports_tools && $this->openrouter_server_tool_support_value( $declared, 'web_fetch', false ),
            'datetime'   => $supports_tools && $this->openrouter_server_tool_support_value( $declared, 'datetime', false ),
        ];

        return $this->apply_openrouter_server_tool_compatibility_overrides( $model_id, $server_tools );
    }

    /**
     * @param array<string, bool> $server_tools
     *
     * @return array<string, bool>
     */
    private function apply_openrouter_server_tool_compatibility_overrides( string $model_id, array $server_tools ): array
    {
        $overrides = self::OPENROUTER_SERVER_TOOL_COMPATIBILITY_OVERRIDES[ $model_id ] ?? null;
        if ( ! is_array( $overrides ) )
        {
            return $server_tools;
        }

        foreach ( $overrides as $tool => $supported )
        {
            $server_tools[ $tool ] = (bool) $supported;
        }

        return $server_tools;
    }

    private function openrouter_server_tool_support_value( array $declared, string $key, bool $default ): bool
    {
        if ( array_key_exists( $key, $declared ) )
        {
            return rest_sanitize_boolean( $declared[ $key ] );
        }

        return $default;
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

    private function sanitize_url_list( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $urls = [];
        foreach ( $value as $item )
        {
            if ( is_scalar( $item ) )
            {
                $url = esc_url_raw( (string) $item );
                if ( '' !== $url )
                {
                    $urls[] = $url;
                }
            }
        }

        return array_values( array_unique( $urls ) );
    }

    private function developer_label_for_provider( string $provider_family ): string
    {
        return match ( $provider_family ) {
            'openai'     => 'OpenAI',
            'anthropic'  => 'Anthropic',
            'google'     => 'Google',
            'moonshotai' => 'Moonshot AI',
            'qwen'       => 'Qwen',
            'deepseek'   => 'DeepSeek',
            'z-ai'       => 'Z.ai',
            'x-ai'       => 'xAI',
            'minimax'    => 'MiniMax',
            'stepfun'    => 'StepFun',
            'tencent'    => 'Tencent',
            'meta-llama' => 'Meta',
            'inclusionai' => 'Inclusion AI',
            'xiaomi'     => 'Xiaomi',
            'poolside'   => 'Poolside',
            'nvidia'     => 'NVIDIA',
            default      => $provider_family,
        };
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

    private function sanitize_score_breakdown( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $scores = [];
        foreach ( $value as $criterion => $score )
        {
            $criterion_key = sanitize_key( (string) $criterion );
            if ( '' === $criterion_key )
            {
                continue;
            }

            $scores[ $criterion_key ] = min( 100, absint( $score ) );
        }

        return $scores;
    }

    private function sanitize_evidence_candidates( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $candidates = [];
        foreach ( $value as $item )
        {
            if ( ! is_array( $item ) )
            {
                continue;
            }

            $model_id = isset( $item['model_id'] ) && is_scalar( $item['model_id'] )
                ? sanitize_text_field( (string) $item['model_id'] )
                : '';
            if ( '' === $model_id )
            {
                continue;
            }

            $candidate = [
                'model_id' => $model_id,
                'score'    => min( 100, absint( $item['score'] ?? 0 ) ),
            ];

            if ( isset( $item['notes'] ) && is_scalar( $item['notes'] ) )
            {
                $candidate['notes'] = sanitize_textarea_field( (string) $item['notes'] );
            }

            $candidates[] = $candidate;
        }

        return $candidates;
    }

    private function sanitize_ranking_snapshot( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $snapshot = [];
        foreach ( $value as $key => $item )
        {
            $sanitized_key = sanitize_key( (string) $key );
            if ( '' === $sanitized_key )
            {
                continue;
            }

            if ( is_scalar( $item ) )
            {
                $snapshot[ $sanitized_key ] = sanitize_text_field( (string) $item );
            }
            elseif ( is_array( $item ) )
            {
                $snapshot[ $sanitized_key ] = $this->sanitize_string_label_map( $item );
            }
        }

        return $snapshot;
    }

    private function sanitize_string_label_map( array $value ): array
    {
        $map = [];
        foreach ( $value as $key => $item )
        {
            if ( is_scalar( $item ) )
            {
                $sanitized_key = sanitize_key( (string) $key );
                if ( '' !== $sanitized_key )
                {
                    $map[ $sanitized_key ] = sanitize_text_field( (string) $item );
                }
            }
        }

        return $map;
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
                            'code'                => [ 'type' => 'string' ],
                            'display_name'        => [ 'type' => 'string' ],
                            'description'         => [ 'type' => 'string' ],
                            'resolved_model_id'   => [ 'type' => 'string' ],
                            'rationale'           => [ 'type' => 'string' ],
                            'score'               => [ 'type' => 'integer' ],
                            'evidence_confidence' => [ 'type' => 'string' ],
                            'evaluated_at'        => [ 'type' => 'string' ],
                            'score_breakdown'     => [
                                'type'                 => 'object',
                                'additionalProperties' => [ 'type' => 'integer' ],
                            ],
                            'top_candidates'      => [
                                'type'  => 'array',
                                'items' => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'model_id' => [ 'type' => 'string' ],
                                        'score'    => [ 'type' => 'integer' ],
                                        'notes'    => [ 'type' => 'string' ],
                                    ],
                                ],
                            ],
                            'source_urls'         => [
                                'type'  => 'array',
                                'items' => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return $this->schema;
    }
}
