<?php
/**
 * REST API Form Action Config Controller for the Sentient Forms plugin.
 * Handles routes for form-level action configuration (hierarchical spam examples).
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
 * Class Sentient_Forms_Form_Action_Config_Controller
 * Manages REST API endpoints for form-level action configuration (Phase 1: Hierarchical Examples).
 * 
 * This controller stores configuration at the form level, allowing settings to persist
 * even when action mappings are deleted. Settings here act as defaults for all mappings
 * of a given action within a specific form.
 */
class Sentient_Forms_Form_Action_Config_Controller extends Abstract_Sentient_Forms_Base_Controller
{
    use Trait_Sentient_Forms_Permission_Utils;

    /**
     * The option prefix for form action configs.
     *
     * @var string
     */
    private const OPTION_PREFIX = 'sentient_forms_form_config_';

    /**
     * Maximum number of action defaults accepted by the batch route.
     *
     * @var int
     */
    public const ACTION_DEFAULTS_BATCH_LIMIT = 100;

    /**
     * The base of this controller's routes.
     *
     * @var string
     * @since 0.1.0
     */
    protected string $rest_base = 'forms/(?P<form_source>[a-z0-9_-]+)/(?P<form_id>[\d]+)/action-config';

    /**
     * Registers the routes for the form action config controller.
     *
     * @since 0.1.0
     */
    public function register_routes(): void
    {
        // GET /forms/{source}/{id}/action-config - List all action configs for a form
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_form_configs' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'form_source' => [
                            'description'       => __( 'Form source slug (e.g., gravity-forms).', 'sentient-forms' ),
                            'type'              => 'string',
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_key',
                        ],
                        'form_id' => [
                            'description'       => __( 'Form ID.', 'sentient-forms' ),
                            'type'              => 'integer',
                            'required'          => true,
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ],
        );

        // GET/POST/DELETE /forms/{source}/{id}/action-config/{action_id}
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<action_id>[a-z0-9_-]+)',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_action_config' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_action_config_args(),
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'update_action_config' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => array_merge(
                        $this->get_action_config_args(),
                        $this->get_update_args()
                    ),
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'delete_action_config' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_action_config_args(),
                ],
            ],
        );

        // GET /actions/defaults?ids=a,b - Batched global action defaults for admin first paint.
        register_rest_route(
            $this->namespace,
            '/actions/defaults',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_action_defaults_batch' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'ids' => [
                            'description' => __( 'Comma-separated action IDs to load.', 'sentient-forms' ),
                            'required'    => false,
                        ],
                    ],
                ],
            ],
        );

        // GET/POST /actions/{action_id}/defaults - Global action defaults (top of hierarchy)
        register_rest_route(
            $this->namespace,
            '/actions/(?P<action_id>[a-z0-9_-]+)/defaults',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_action_defaults' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'action_id' => [
                            'description'       => __( 'Action ID (e.g., spam_detection_v1).', 'sentient-forms' ),
                            'type'              => 'string',
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_key',
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'update_action_defaults' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => array_merge(
                        [
                            'action_id' => [
                                'description'       => __( 'Action ID (e.g., spam_detection_v1).', 'sentient-forms' ),
                                'type'              => 'string',
                                'required'          => true,
                                'sanitize_callback' => 'sanitize_key',
                            ],
                        ],
                        $this->get_update_args()
                    ),
                ],
            ],
        );
    }

    /**
     * Gets route arguments for action config endpoints.
     *
     * @return array
     */
    private function get_action_config_args(): array
    {
        return [
            'form_source' => [
                'description'       => __( 'Form source slug (e.g., gravity-forms).', 'sentient-forms' ),
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_key',
            ],
            'form_id' => [
                'description'       => __( 'Form ID.', 'sentient-forms' ),
                'type'              => 'integer',
                'required'          => true,
                'sanitize_callback' => 'absint',
            ],
            'action_id' => [
                'description'       => __( 'Action ID (e.g., spam_detection_v1).', 'sentient-forms' ),
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_key',
            ],
        ];
    }

    /**
     * Gets update-specific route arguments.
     *
     * @return array
     */
    private function get_update_args(): array
    {
        return [
            'spam_positive_examples' => [
                'description'       => __( 'Examples of legitimate submissions with webmaster rationale.', 'sentient-forms' ),
                'type'              => 'array',
                'items'             => $this->get_spam_guidance_example_schema(),
                'sanitize_callback' => [ $this, 'sanitize_spam_guidance_examples' ],
            ],
            'spam_negative_examples' => [
                'description'       => __( 'Examples of spam submissions with webmaster rationale.', 'sentient-forms' ),
                'type'              => 'array',
                'items'             => $this->get_spam_guidance_example_schema(),
                'sanitize_callback' => [ $this, 'sanitize_spam_guidance_examples' ],
            ],
            'action_customization' => [
                'description'       => __( 'Optional action customization instructions sent to the AI.', 'sentient-forms' ),
                'type'              => 'string',
                'sanitize_callback' => [ $this, 'sanitize_action_customization' ],
            ],
            'include_site_context' => [
                'description'       => __( 'Site context inclusion setting.', 'sentient-forms' ),
                'type'              => 'string',
                'enum'              => [ 'global', 'always', 'never' ],
                'sanitize_callback' => 'sanitize_key',
            ],
            'model_override' => [
                'description'       => __( 'Model override for this action on this form.', 'sentient-forms' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'model_selection' => [
                'description'       => __( 'Structured model selection for this action scope.', 'sentient-forms' ),
                'type'              => 'object',
                'sanitize_callback' => [ $this, 'sanitize_model_selection' ],
            ],
            'realtime_settings' => [
                'description'       => __( 'Realtime Clarification Assistant defaults for this action scope.', 'sentient-forms' ),
                'type'              => 'object',
                'sanitize_callback' => [ $this, 'sanitize_realtime_settings' ],
            ],
            'suppress_notifications_on_spam' => [
                'description'       => __( 'Whether blocking spam classifications should suppress form-submission notifications.', 'sentient-forms' ),
                'type'              => 'boolean',
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
            'suppress_webhooks_on_spam' => [
                'description'       => __( 'Whether blocking spam classifications should suppress Gravity Forms Webhooks feeds.', 'sentient-forms' ),
                'type'              => 'boolean',
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
            'skip_downstream_on_spam' => [
                'description'       => __( 'Whether downstream mappings should skip when this spam action classifies spam.', 'sentient-forms' ),
                'type'              => 'boolean',
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
            'spam_result_display_mode' => [
                'description'       => __( 'Whether spam analysis notes should be stored for none, spam-only, or all results.', 'sentient-forms' ),
                'type'              => 'string',
                'enum'              => [ 'none', 'spam_only', 'all_results' ],
                'sanitize_callback' => [ $this, 'sanitize_spam_result_display_mode' ],
            ],
            'spam_indicators_display' => [
                'description'       => __( 'How much spam-indicator detail to include in entry notes.', 'sentient-forms' ),
                'type'              => 'string',
                'enum'              => [ 'simple', 'detailed' ],
                'sanitize_callback' => [ $this, 'sanitize_spam_indicators_display' ],
            ],
        ];
    }

    /**
     * Sanitizes an array of strings.
     *
     * @param mixed $value Value to sanitize.
     * @return array
     */
    public function sanitize_string_array( $value ): array
    {
        if ( !is_array( $value ) )
        {
            return [];
        }

        return array_map( 'sanitize_text_field', array_filter( $value, 'is_string' ) );
    }

    /**
     * Gets the strict spam guidance example schema.
     *
     * @return array<string, mixed>
     */
    private function get_spam_guidance_example_schema(): array
    {
        return [
            'type'                 => 'object',
            'required'             => [ 'text', 'rationale' ],
            'additionalProperties' => false,
            'properties'           => [
                'text'      => [
                    'type'      => 'string',
                    'minLength' => 1,
                    'maxLength' => 800,
                ],
                'rationale' => [
                    'type'      => 'string',
                    'minLength' => 1,
                    'maxLength' => 800,
                ],
            ],
        ];
    }

    /**
     * Sanitizes strict spam guidance examples.
     *
     * @param mixed $value Value to sanitize.
     * @return array<int, array{text: string, rationale: string}>
     */
    public function sanitize_spam_guidance_examples( $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $sanitized = [];
        foreach ( $value as $example )
        {
            if ( ! is_array( $example ) )
            {
                continue;
            }

            $text      = isset( $example['text'] ) && is_scalar( $example['text'] )
                ? trim( sanitize_textarea_field( (string) $example['text'] ) )
                : '';
            $rationale = isset( $example['rationale'] ) && is_scalar( $example['rationale'] )
                ? trim( sanitize_textarea_field( (string) $example['rationale'] ) )
                : '';

            if ( '' === $text || '' === $rationale )
            {
                continue;
            }

            $sanitized[] = [
                'text'      => mb_substr( $text, 0, 800 ),
                'rationale' => mb_substr( $rationale, 0, 800 ),
            ];

            if ( count( $sanitized ) >= 10 )
            {
                break;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitizes action customization instructions.
     *
     * @param mixed $value Value to sanitize.
     */
    public function sanitize_action_customization( $value ): string
    {
        if ( ! is_scalar( $value ) )
        {
            return '';
        }

        return mb_substr( trim( sanitize_textarea_field( (string) $value ) ), 0, 2000 );
    }

    /**
     * Sanitizes a structured model selection payload.
     *
     * @param mixed $value Value to sanitize.
     * @return array|null
     */
    public function sanitize_model_selection( $value ): ?array
    {
        if ( !is_array( $value ) )
        {
            return null;
        }

        $primary = isset( $value['primary'] ) ? sanitize_text_field( (string) $value['primary'] ) : '';
        if ( $primary === '' )
        {
            return null;
        }

        $backup = null;
        if ( isset( $value['backup'] ) && is_string( $value['backup'] ) )
        {
            $sanitized_backup = sanitize_text_field( $value['backup'] );
            $backup           = $sanitized_backup !== '' ? $sanitized_backup : null;
        }

        $selection = [
            'primary'   => $primary,
            'backup'    => $backup,
            'is_preset' => isset( $value['is_preset'] ) ? (bool) $value['is_preset'] : false,
        ];

        if ( isset( $value['provider'] ) && is_scalar( $value['provider'] ) )
        {
            $provider = sanitize_key( (string) $value['provider'] );
            if ( in_array( $provider, [ 'openrouter', 'sentient_managed' ], true ) )
            {
                $selection['provider'] = $provider;
            }
        }

        if ( isset( $value['credential_id'] ) && is_scalar( $value['credential_id'] ) )
        {
            $credential_id = absint( $value['credential_id'] );
            if ( $credential_id > 0 )
            {
                $selection['credential_id'] = $credential_id;
            }
        }

        $reasoning = isset( $value['reasoning'] ) ? sanitize_key( (string) $value['reasoning'] ) : '';
        if ( in_array( $reasoning, [ 'none', 'minimal', 'low', 'medium', 'high', 'xhigh' ], true ) )
        {
            $selection['reasoning'] = $reasoning;
        }

        $tools = $this->sanitize_model_tool_settings( $value['tools'] ?? null );
        if ( [] !== $tools )
        {
            $selection['tools'] = $tools;
        }

        return $selection;
    }

    /**
     * Sanitizes optional OpenRouter server-tool controls.
     *
     * @param mixed $value Tool settings.
     * @return array<string, mixed>
     */
    private function sanitize_model_tool_settings( $value ): array
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

    /**
     * Sanitizes realtime assistant defaults for action and form scopes.
     *
     * @param mixed $value Raw realtime settings.
     * @return array<string, mixed>
     */
    public function sanitize_realtime_settings( $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $auto_refresh_enabled       = array_key_exists( 'auto_refresh_enabled', $value )
            ? rest_sanitize_boolean( $value['auto_refresh_enabled'] )
            : $this->derive_legacy_auto_refresh_enabled( $value );
        $field_checkpoints_enabled  = array_key_exists( 'field_checkpoints_enabled', $value )
            ? rest_sanitize_boolean( $value['field_checkpoints_enabled'] )
            : $this->derive_legacy_field_checkpoints_enabled( $value );
        $page_checkpoints_enabled   = array_key_exists( 'page_checkpoints_enabled', $value )
            ? rest_sanitize_boolean( $value['page_checkpoints_enabled'] )
            : false;
        $manual_refresh_enabled     = array_key_exists( 'manual_refresh_enabled', $value )
            ? rest_sanitize_boolean( $value['manual_refresh_enabled'] )
            : true;
        $pre_submit_run_enabled     = array_key_exists( 'pre_submit_run_enabled', $value )
            ? rest_sanitize_boolean( $value['pre_submit_run_enabled'] )
            : false;
        $page_checkpoint_mode       = sanitize_key( (string) ( $value['page_checkpoint_mode'] ?? 'all_pages' ) );
        if ( ! in_array( $page_checkpoint_mode, [ 'all_pages', 'include_pages', 'exclude_pages' ], true ) )
        {
            $page_checkpoint_mode = 'all_pages';
        }

        $blocking_mode = 'require_answers' === sanitize_key( (string) ( $value['blocking_mode'] ?? '' ) )
            ? 'require_answers'
            : 'advisory';
        $initial_panel_state = sanitize_key( (string) ( $value['initial_panel_state'] ?? 'minimized' ) );
        if ( ! in_array( $initial_panel_state, [ 'open', 'minimized', 'hidden_until_interaction' ], true ) )
        {
            $initial_panel_state = 'minimized';
        }
        $hidden_field_exposure_mode = sanitize_key( (string) ( $value['hidden_field_exposure_mode'] ?? 'label_hidden' ) );
        if ( ! in_array( $hidden_field_exposure_mode, [ 'omit_hidden', 'label_hidden', 'label_hidden_value', 'label_value' ], true ) )
        {
            $hidden_field_exposure_mode = 'label_hidden';
        }

        $refresh_mode = $auto_refresh_enabled
            ? 'auto'
            : ( $field_checkpoints_enabled || $page_checkpoints_enabled ? 'checkpoint' : 'manual' );

        return [
            'auto_refresh_enabled'        => $auto_refresh_enabled,
            'field_checkpoints_enabled'   => $field_checkpoints_enabled,
            'checkpoint_field_ids'        => $this->sanitize_string_array( $value['checkpoint_field_ids'] ?? [] ),
            'page_checkpoints_enabled'    => $page_checkpoints_enabled,
            'page_checkpoint_mode'        => $page_checkpoint_mode,
            'page_checkpoint_pages'       => $this->sanitize_positive_int_array( $value['page_checkpoint_pages'] ?? [] ),
            'page_checkpoint_timeout_ms'  => $this->normalize_millis( $value['page_checkpoint_timeout_ms'] ?? 2500, 500, 10000, 2500 ),
            'storage_target_field_id'     => isset( $value['storage_target_field_id'] ) && is_scalar( $value['storage_target_field_id'] )
                ? sanitize_text_field( (string) $value['storage_target_field_id'] )
                : '',
            'debounce_ms'                 => $this->normalize_millis( $value['debounce_ms'] ?? 900, 250, 5000, 900 ),
            'cooldown_ms'                 => $this->normalize_millis( $value['cooldown_ms'] ?? 8000, 0, 60000, 8000 ),
            'manual_refresh_enabled'      => $manual_refresh_enabled,
            'blocking_mode'               => $blocking_mode,
            'refresh_mode'                => $refresh_mode,
            'initial_panel_state'         => $initial_panel_state,
            'hidden_field_exposure_mode'  => $hidden_field_exposure_mode,
            'pre_submit_run_enabled'      => $pre_submit_run_enabled,
            'pre_submit_timeout_ms'       => $this->normalize_millis( $value['pre_submit_timeout_ms'] ?? 2500, 500, 10000, 2500 ),
        ];
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function sanitize_positive_int_array( $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $items = [];
        foreach ( $value as $item )
        {
            if ( ! is_scalar( $item ) )
            {
                continue;
            }

            $number = absint( $item );
            if ( $number > 0 )
            {
                $items[] = min( 200, $number );
            }
        }

        $items = array_values( array_unique( $items ) );
        sort( $items, SORT_NUMERIC );

        return $items;
    }

    /**
     * @param mixed $value
     */
    private function normalize_millis( $value, int $min, int $max, int $fallback ): int
    {
        if ( ! is_scalar( $value ) )
        {
            return $fallback;
        }

        $parsed = (int) $value;
        if ( $parsed < $min )
        {
            return $min;
        }
        if ( $parsed > $max )
        {
            return $max;
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function derive_legacy_auto_refresh_enabled( array $value ): bool
    {
        $refresh_mode = sanitize_key( (string) ( $value['refresh_mode'] ?? 'auto' ) );
        return 'auto' === $refresh_mode;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function derive_legacy_field_checkpoints_enabled( array $value ): bool
    {
        $refresh_mode = sanitize_key( (string) ( $value['refresh_mode'] ?? '' ) );
        return 'checkpoint' === $refresh_mode;
    }

    /**
     * @param mixed $value
     */
    public function sanitize_spam_result_display_mode( $value ): string
    {
        $value = sanitize_key( (string) $value );

        return match ( $value ) {
            'none',
            'spam_only',
            'all_results' => $value,
            default      => 'all_results',
        };
    }

    /**
     * @param mixed $value
     */
    public function sanitize_spam_indicators_display( $value ): string
    {
        return 'detailed' === sanitize_key( (string) $value ) ? 'detailed' : 'simple';
    }

    /**
     * Normalizes a config payload for API responses and legacy option reads.
     *
     * @param mixed $config Raw config value.
     * @return array
     */
    private function normalize_action_config( $config ): array
    {
        if ( !is_array( $config ) )
        {
            return [];
        }

        if ( empty( $config['model_selection'] ) && !empty( $config['model_override'] ) && is_string( $config['model_override'] ) )
        {
            $config['model_selection'] = [
                'primary'   => sanitize_text_field( $config['model_override'] ),
                'backup'    => null,
                'is_preset' => str_starts_with( (string) $config['model_override'], 'sf_' ),
            ];
        }

        if ( isset( $config['model_selection'] ) )
        {
            $config['model_selection'] = $this->sanitize_model_selection( $config['model_selection'] );
            if ( $config['model_selection'] === null )
            {
                unset( $config['model_selection'] );
            }
        }

        foreach ( [ 'suppress_notifications_on_spam', 'suppress_webhooks_on_spam', 'skip_downstream_on_spam' ] as $field )
        {
            if ( array_key_exists( $field, $config ) )
            {
                $config[ $field ] = rest_sanitize_boolean( $config[ $field ] );
            }
        }

        foreach ( [ 'spam_positive_examples', 'spam_negative_examples' ] as $field )
        {
            if ( array_key_exists( $field, $config ) )
            {
                $config[ $field ] = $this->sanitize_spam_guidance_examples( $config[ $field ] );
            }
        }

        if ( array_key_exists( 'action_customization', $config ) )
        {
            $config['action_customization'] = $this->sanitize_action_customization( $config['action_customization'] );
        }

        if ( array_key_exists( 'spam_result_display_mode', $config ) )
        {
            $config['spam_result_display_mode'] = $this->sanitize_spam_result_display_mode( $config['spam_result_display_mode'] );
        }

        if ( array_key_exists( 'spam_indicators_display', $config ) )
        {
            $config['spam_indicators_display'] = $this->sanitize_spam_indicators_display( $config['spam_indicators_display'] );
        }

        if ( array_key_exists( 'realtime_settings', $config ) )
        {
            $config['realtime_settings'] = $this->sanitize_realtime_settings( $config['realtime_settings'] );
        }

        return $config;
    }

    /**
     * Generates the option key for a form's action configs.
     *
     * @param string $form_source Form source slug.
     * @param int    $form_id     Form ID.
     * @return string
     */
    private function get_option_key( string $form_source, int $form_id ): string
    {
        return self::OPTION_PREFIX . $form_source . '_' . $form_id;
    }

    /**
     * Gets all action configs for a form.
     *
     * @param string $form_source Form source slug.
     * @param int    $form_id     Form ID.
     * @return array<string, array>
     */
    private function get_all_configs( string $form_source, int $form_id ): array
    {
        $option_key = $this->get_option_key( $form_source, $form_id );
        $configs    = get_option( $option_key, [] );

        return is_array( $configs ) ? $configs : [];
    }

    /**
     * Saves all action configs for a form.
     *
     * @param string              $form_source Form source slug.
     * @param int                 $form_id     Form ID.
     * @param array<string,array> $configs     The configs to save.
     * @return bool
     */
    private function save_all_configs( string $form_source, int $form_id, array $configs ): bool
    {
        $option_key = $this->get_option_key( $form_source, $form_id );

        // If empty, delete the option
        if ( empty( $configs ) )
        {
            return delete_option( $option_key );
        }

        return update_option( $option_key, $configs, false );
    }

    /**
     * Gets all action configs for a form.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_form_configs( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $form_source = $request->get_param( 'form_source' );
        $form_id     = (int) $request->get_param( 'form_id' );

        $configs = array_map( [ $this, 'normalize_action_config' ], $this->get_all_configs( $form_source, $form_id ) );

        return $this->prepare_item_for_response( [
            'form_source' => $form_source,
            'form_id'     => $form_id,
            'configs'     => $configs,
        ] );
    }

    /**
     * Gets a specific action config for a form.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_action_config( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $form_source = $request->get_param( 'form_source' );
        $form_id     = (int) $request->get_param( 'form_id' );
        $action_id   = $request->get_param( 'action_id' );

        $configs       = $this->get_all_configs( $form_source, $form_id );
        $action_config = $this->normalize_action_config( $configs[ $action_id ] ?? [] );

        return $this->prepare_item_for_response( [
            'form_source' => $form_source,
            'form_id'     => $form_id,
            'action_id'   => $action_id,
            'config'      => $action_config,
        ] );
    }

    /**
     * Updates a specific action config for a form.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function update_action_config( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $form_source = $request->get_param( 'form_source' );
        $form_id     = (int) $request->get_param( 'form_id' );
        $action_id   = $request->get_param( 'action_id' );

        $validation = $this->validate_action_config_write_request( $request );
        if ( is_wp_error( $validation ) )
        {
            return $validation;
        }

        $configs       = $this->get_all_configs( $form_source, $form_id );
        $action_config = $this->normalize_action_config( $configs[ $action_id ] ?? [] );

        // Update only provided fields
        $updateable_fields = [
            'spam_positive_examples',
            'spam_negative_examples',
            'action_customization',
            'include_site_context',
            'model_override',
            'model_selection',
            'realtime_settings',
            'suppress_notifications_on_spam',
            'suppress_webhooks_on_spam',
            'skip_downstream_on_spam',
            'spam_result_display_mode',
            'spam_indicators_display',
        ];

        foreach ( $updateable_fields as $field )
        {
            if ( $request->has_param( $field ) )
            {
                $value = $request->get_param( $field );
                
                // If value is null or empty array and field exists, remove it
                if ( $value === null || ( is_array( $value ) && empty( $value ) ) )
                {
                    unset( $action_config[ $field ] );
                }
                else
                {
                    $action_config[ $field ] = $value;
                    if ( $field === 'model_selection' )
                    {
                        unset( $action_config['model_override'] );
                    }
                }
            }
        }

        $action_config = $this->normalize_action_config( $action_config );

        // If the action config is empty, remove it entirely
        if ( empty( $action_config ) )
        {
            unset( $configs[ $action_id ] );
        }
        else
        {
            $action_config['updated_at']   = current_time( 'mysql' );
            $configs[ $action_id ]         = $action_config;
        }

        $saved = $this->save_all_configs( $form_source, $form_id, $configs );

        if ( !$saved && !empty( $configs ) )
        {
            return $this->prepare_error_response(
                'save_failed',
                __( 'Failed to save form action config.', 'sentient-forms' ),
                500,
            );
        }

        return $this->prepare_item_for_response( [
            'form_source' => $form_source,
            'form_id'     => $form_id,
            'action_id'   => $action_id,
            'config'      => $this->normalize_action_config( $action_config ),
        ] );
    }

    /**
     * Deletes a specific action config for a form.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function delete_action_config( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $form_source = $request->get_param( 'form_source' );
        $form_id     = (int) $request->get_param( 'form_id' );
        $action_id   = $request->get_param( 'action_id' );

        $configs = $this->get_all_configs( $form_source, $form_id );

        if ( !isset( $configs[ $action_id ] ) )
        {
            return $this->prepare_error_response(
                'not_found',
                __( 'Action config not found.', 'sentient-forms' ),
                404,
            );
        }

        unset( $configs[ $action_id ] );
        $this->save_all_configs( $form_source, $form_id, $configs );

        return $this->prepare_item_for_response( [
            'deleted'     => true,
            'form_source' => $form_source,
            'form_id'     => $form_id,
            'action_id'   => $action_id,
        ] );
    }

    /**
     * Option prefix for global action defaults.
     */
    private const ACTION_DEFAULTS_PREFIX = 'sentient_forms_action_defaults_';

    /**
     * Gets the option key for global action defaults.
     *
     * @param string $action_id Action ID.
     * @return string
     */
    private function get_action_defaults_key( string $action_id ): string
    {
        return self::ACTION_DEFAULTS_PREFIX . $action_id;
    }

    /**
     * Gets global defaults for an action (top of hierarchy).
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_action_defaults( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $action_id = $request->get_param( 'action_id' );
        $option_key = $this->get_action_defaults_key( $action_id );
        $config = $this->normalize_action_config( get_option( $option_key, [] ) );

        return $this->prepare_item_for_response( [
            'action_id' => $action_id,
            'config'    => is_array( $config ) ? $config : (object) [],
        ] );
    }

    /**
     * Gets global defaults for multiple actions in one request.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_action_defaults_batch( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $action_ids = $this->normalize_action_defaults_ids( $request->get_param( 'ids' ) );
        $defaults   = [];

        if ( function_exists( 'wp_prime_option_caches' ) )
        {
            wp_prime_option_caches( array_map( [ $this, 'get_action_defaults_key' ], $action_ids ) );
        }

        foreach ( $action_ids as $action_id )
        {
            $config = $this->normalize_action_config(
                get_option( $this->get_action_defaults_key( $action_id ), [] )
            );
            $defaults[ $action_id ] = is_array( $config ) ? $config : (object) [];
        }

        return $this->prepare_item_for_response( [
            'defaults'     => $defaults,
            'generated_at' => gmdate( 'c' ),
        ] );
    }

    /**
     * Normalizes a comma-delimited or array action ID list.
     *
     * @param mixed $raw_ids Raw request parameter.
     *
     * @return array<int, string>
     */
    private function normalize_action_defaults_ids( mixed $raw_ids ): array
    {
        if ( is_array( $raw_ids ) )
        {
            $candidates = $raw_ids;
        }
        elseif ( is_scalar( $raw_ids ) )
        {
            $candidates = explode( ',', (string) $raw_ids );
        }
        else
        {
            $candidates = [];
        }

        $ids = [];
        foreach ( $candidates as $candidate )
        {
            if ( !is_scalar( $candidate ) )
            {
                continue;
            }

            $id = sanitize_key( wp_unslash( (string) $candidate ) );
            if ( $id !== '' )
            {
                $ids[ $id ] = true;
            }
        }

        return array_slice( array_keys( $ids ), 0, self::ACTION_DEFAULTS_BATCH_LIMIT );
    }

    /**
     * Updates global defaults for an action.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function update_action_defaults( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $action_id = $request->get_param( 'action_id' );
        $option_key = $this->get_action_defaults_key( $action_id );
        $config = get_option( $option_key, [] );

        $validation = $this->validate_action_config_write_request( $request );
        if ( is_wp_error( $validation ) )
        {
            return $validation;
        }

        if ( !is_array( $config ) )
        {
            $config = [];
        }

        // Update only provided fields
        $updateable_fields = [
            'spam_positive_examples',
            'spam_negative_examples',
            'action_customization',
            'include_site_context',
            'model_override',
            'model_selection',
            'realtime_settings',
            'suppress_notifications_on_spam',
            'suppress_webhooks_on_spam',
            'skip_downstream_on_spam',
            'spam_result_display_mode',
            'spam_indicators_display',
        ];

        foreach ( $updateable_fields as $field )
        {
            if ( $request->has_param( $field ) )
            {
                $value = $request->get_param( $field );
                
                // If value is null or empty array, remove the field
                if ( $value === null || ( is_array( $value ) && empty( $value ) ) )
                {
                    unset( $config[ $field ] );
                }
                else
                {
                    $config[ $field ] = $value;
                    if ( $field === 'model_selection' )
                    {
                        unset( $config['model_override'] );
                    }
                }
            }
        }

        $config = $this->normalize_action_config( $config );

        // If the config is empty, delete the option
        if ( empty( $config ) )
        {
            delete_option( $option_key );
        }
        else
        {
            $config['updated_at'] = current_time( 'mysql' );
            update_option( $option_key, $config, false );
        }

        return $this->prepare_item_for_response( [
            'action_id' => $action_id,
            'config'    => $this->normalize_action_config( $config ),
        ] );
    }

    /**
     * Validates strict write payloads while keeping read normalization tolerant.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_Error|null
     */
    private function validate_action_config_write_request( WP_REST_Request $request ): ?WP_Error
    {
        foreach ( [ 'spam_positive_examples', 'spam_negative_examples' ] as $field )
        {
            if ( ! $request->has_param( $field ) )
            {
                continue;
            }

            $value = $request->get_param( $field );
            if ( null === $value || ( is_array( $value ) && [] === $value ) )
            {
                continue;
            }

            $validation = $this->validate_spam_guidance_examples_for_write( $field, $value );
            if ( is_wp_error( $validation ) )
            {
                return $validation;
            }
        }

        foreach ( [ 'suppress_notifications_on_spam', 'suppress_webhooks_on_spam', 'skip_downstream_on_spam' ] as $field )
        {
            if ( ! $request->has_param( $field ) )
            {
                continue;
            }

            $value = $request->get_param( $field );
            if ( null === $value )
            {
                continue;
            }

            if ( ! is_bool( $value ) )
            {
                return $this->invalid_action_config_write_error(
                    $field,
                    __( 'Spam policy controls must be JSON booleans.', 'sentient-forms' )
                );
            }
        }

        if ( $request->has_param( 'action_customization' ) )
        {
            $value = $request->get_param( 'action_customization' );
            if ( null !== $value )
            {
                if ( ! is_string( $value ) )
                {
                    return $this->invalid_action_config_write_error(
                        'action_customization',
                        __( 'Action customization must be a string.', 'sentient-forms' )
                    );
                }

                if ( mb_strlen( trim( $value ) ) > 2000 )
                {
                    return $this->invalid_action_config_write_error(
                        'action_customization',
                        __( 'Action customization must be 2000 characters or fewer.', 'sentient-forms' )
                    );
                }
            }
        }

        $enum_fields = [
            'include_site_context'       => [ 'global', 'always', 'never' ],
            'spam_result_display_mode'   => [ 'none', 'spam_only', 'all_results' ],
            'spam_indicators_display'    => [ 'simple', 'detailed' ],
        ];
        foreach ( $enum_fields as $field => $allowed )
        {
            if ( ! $request->has_param( $field ) )
            {
                continue;
            }

            $value = $request->get_param( $field );
            if ( null === $value )
            {
                continue;
            }

            if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) )
            {
                return $this->invalid_action_config_write_error(
                    $field,
                    __( 'Action configuration contains an invalid option value.', 'sentient-forms' )
                );
            }
        }

        return null;
    }

    /**
     * @param string $field Field being validated.
     * @param mixed  $value Raw field value.
     * @return WP_Error|null
     */
    private function validate_spam_guidance_examples_for_write( string $field, mixed $value ): ?WP_Error
    {
        if ( ! is_array( $value ) )
        {
            return $this->invalid_action_config_write_error(
                $field,
                __( 'Spam guidance examples must be an array of objects.', 'sentient-forms' )
            );
        }

        if ( count( $value ) > 10 )
        {
            return $this->invalid_action_config_write_error(
                $field,
                __( 'Spam guidance examples are limited to 10 examples per list.', 'sentient-forms' )
            );
        }

        foreach ( $value as $example )
        {
            if ( ! is_array( $example ) )
            {
                return $this->invalid_action_config_write_error(
                    $field,
                    __( 'Each spam guidance example must be an object.', 'sentient-forms' )
                );
            }

            $extra_keys = array_diff( array_keys( $example ), [ 'text', 'rationale' ] );
            if ( [] !== $extra_keys )
            {
                return $this->invalid_action_config_write_error(
                    $field,
                    __( 'Spam guidance examples may only include text and rationale.', 'sentient-forms' )
                );
            }

            foreach ( [ 'text', 'rationale' ] as $example_field )
            {
                if ( ! array_key_exists( $example_field, $example ) || ! is_string( $example[ $example_field ] ) )
                {
                    return $this->invalid_action_config_write_error(
                        $field,
                        __( 'Each spam guidance example must include string text and rationale fields.', 'sentient-forms' )
                    );
                }

                $trimmed = trim( $example[ $example_field ] );
                if ( '' === $trimmed || mb_strlen( $trimmed ) > 800 )
                {
                    return $this->invalid_action_config_write_error(
                        $field,
                        __( 'Spam guidance example text and rationale must be between 1 and 800 characters.', 'sentient-forms' )
                    );
                }
            }
        }

        return null;
    }

    private function invalid_action_config_write_error( string $field, string $message ): WP_Error
    {
        return $this->prepare_error_response(
            'rest_invalid_action_config',
            $message,
            400,
            [ 'field' => $field ],
        );
    }

    /**
     * Retrieves the schema for the form action config response.

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
            'title'      => 'form-action-config',
            'type'       => 'object',
            'properties' => [
                'form_source' => [
                    'description' => __( 'Form source slug.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'form_id' => [
                    'description' => __( 'Form ID.', 'sentient-forms' ),
                    'type'        => 'integer',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'action_id' => [
                    'description' => __( 'Action ID.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'config' => [
                    'description' => __( 'Action configuration.', 'sentient-forms' ),
                    'type'        => 'object',
                    'context'     => [ 'view', 'edit' ],
                    'properties'  => [
                        'spam_positive_examples' => [
                            'type'  => 'array',
                            'items' => $this->get_spam_guidance_example_schema(),
                        ],
                        'spam_negative_examples' => [
                            'type'  => 'array',
                            'items' => $this->get_spam_guidance_example_schema(),
                        ],
                        'action_customization' => [
                            'type' => 'string',
                        ],
                        'include_site_context' => [
                            'type' => 'string',
                            'enum' => [ 'global', 'always', 'never' ],
                        ],
                        'model_override' => [
                            'type' => 'string',
                        ],
                        'model_selection' => [
                            'type'       => 'object',
                            'properties' => [
                                'primary' => [
                                    'type' => 'string',
                                ],
                                'backup' => [
                                    'type' => [ 'string', 'null' ],
                                ],
                                'is_preset' => [
                                    'type' => 'boolean',
                                ],
                            ],
                        ],
                        'suppress_notifications_on_spam' => [
                            'type' => 'boolean',
                        ],
                        'suppress_webhooks_on_spam' => [
                            'type' => 'boolean',
                        ],
                        'skip_downstream_on_spam' => [
                            'type' => 'boolean',
                        ],
                        'spam_result_display_mode' => [
                            'type' => 'string',
                            'enum' => [ 'none', 'spam_only', 'all_results' ],
                        ],
                        'spam_indicators_display' => [
                            'type' => 'string',
                            'enum' => [ 'simple', 'detailed' ],
                        ],
                        'updated_at' => [
                            'type'     => 'string',
                            'format'   => 'date-time',
                            'readonly' => true,
                        ],
                    ],
                ],
            ],
        ];

        return $this->schema;
    }
}
